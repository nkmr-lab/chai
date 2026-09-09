<?php
/**
 * chat.php — OpenAI Responses API へのストリーミング中継（SSE）。
 * ネイティブ Web検索（web_search）＋ 画像生成（function tool）＋ 画像/PDF入力に対応。
 * POST JSON { conversation_id?, content, model?, images?[], pdfs?[], regenerate? }
 * クライアントへのイベント: meta / delta / image / status / done / error（従来どおり）
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$me    = require_login_json();
$email = $me['email'];
$b     = read_json_body();

$content  = trim((string)($b['content'] ?? ''));
$images   = is_array($b['images'] ?? null) ? $b['images'] : [];
$pdfs     = is_array($b['pdfs'] ?? null) ? $b['pdfs'] : [];
$datafiles = is_array($b['datafiles'] ?? null) ? $b['datafiles'] : [];   // CSV/xlsx（code_interpreter用）
$convId   = (int)($b['conversation_id'] ?? 0);
$reqModel = (string)($b['model'] ?? '');
$regen    = !empty($b['regenerate']);

// ── SSE 準備 ─────────────────────────────────────────────
while (ob_get_level() > 0) { ob_end_flush(); }
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
@ini_set('zlib.output_compression', '0');
ob_implicit_flush(true);

function sse(array $data): void {
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush(); @flush();
}
function sse_error(string $msg): void { sse(['type' => 'error', 'message' => $msg]); exit; }

if (!$regen && $content === '' && !$images && !$pdfs && !$datafiles) sse_error('メッセージが空です');

// ── ティア判定 & モデル ──────────────────────────────────
$sub  = labpay_sub_status($email);
$tier = tier_name($sub);
$tc   = tier_config($tier);
$win  = (int)($tc['window_hours'] ?? 8);
$model = model_allowed($reqModel, $tier) ? $reqModel : default_model_for($tier);

// プレミアムモデル(Astra等)は会員でも週次上限。超過したら安価な代替に自動フォールバック
$prem = $GLOBALS['CFG']['premium'] ?? [];
$premModels = (array)($prem['models'] ?? []);
$premUsed = false;
if ($premModels && in_array($model, $premModels, true)) {
    $pcap = (int)($prem['cap'] ?? 30);
    $pwin = (int)($prem['window_hours'] ?? 168);
    if ($pcap > 0 && usage_window_count($email, 'premium', $pwin) >= $pcap) {
        $fb = (string)($prem['fallback'] ?? 'gpt-5.6-sol');
        $days = (int)round($pwin / 24);
        sse(['type' => 'status', 'text' => "⭐ {$model} の利用上限（{$days}日で{$pcap}回）に達したため、今回は " . $fb . " で回答します"]);
        $model = model_allowed($fb, $tier) ? $fb : default_model_for($tier);
    } else {
        $premUsed = true;   // 上限内 → このターンをカウント（後で記録）
    }
}

$cap = (int)($tc['message_cap'] ?? 0);
if ($cap > 0 && usage_window_count($email, 'msg', $win) >= $cap) {
    sse_error("おためしは{$win}時間あたり{$cap}通までです。メンバーになると無制限で使えます。");
}
if ($premUsed) usage_event($email, 'premium');   // Astra等プレミアムの週次カウント
// ファイル(画像/PDF)読み込み: pro=無制限 / free=1日お試し枠
$filesUnlimited = !empty($tc['allow_images']);
$fileLimit      = (int)($tc['file_limit'] ?? 0);
$hadFiles       = ($images || $pdfs);
$allowFiles     = $filesUnlimited || (usage_window_count($email, 'file', $win) < $fileLimit);
if (!$allowFiles) {
    $images = []; $pdfs = [];
    if ($hadFiles) sse(['type' => 'status', 'text' => "📎 ファイル読み込みは、おためしでは{$win}時間に1回までです（メンバーは無制限）"]);
}
$visionOK = $allowFiles && model_has_vision($model);
if ($images && !$visionOK) $images = [];

$U = $GLOBALS['CFG']['upload'] ?? [];
$maxImg = (int)($U['max_image_mb'] ?? 8) * 1024 * 1024;
$images = array_values(array_filter($images, fn($u) => is_string($u) && $u !== '' && strlen($u) <= $maxImg * 1.4));

// 画像 → OpenAI Files(purpose=vision) へアップロードして file_id で永続化
// （毎ターン再添付＝やり直し・追撃質問でも参照可能／編集の入力にも使える）
$imgFileIds = [];
$imgHashes = [];
$imgUrls = [];   // 表示用（/media）URL（アップロード画像をリロード後も表示するため）
if ($images && $visionOK) {
    foreach (array_slice($images, 0, 4) as $durl) {
        $mime = 'image/png'; $raw = $durl;
        if (preg_match('#^data:([a-z0-9.+/-]+);base64,#i', $durl, $mm)) $mime = strtolower($mm[1]);
        if (($pp = strpos($durl, 'base64,')) !== false) $raw = substr($durl, $pp + 7);
        $bin = base64_decode($raw, true);
        if ($bin === false || $bin === '' || strlen($bin) > $maxImg) continue;
        $ext = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/webp' ? 'webp' : ($mime === 'image/gif' ? 'gif' : 'png'));
        // 表示用に /media へ保存
        $mfn = 'up_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $mdir = __DIR__ . '/media'; if (!is_dir($mdir)) @mkdir($mdir, 0775, true);
        file_put_contents($mdir . '/' . $mfn, $bin);
        // モデル用に OpenAI Files(vision) へ
        $tmp = tempnam(sys_get_temp_dir(), 'chaiimg_') . '.' . $ext; file_put_contents($tmp, $bin);
        $up = openai_upload_dedup($tmp, 'image.' . $ext, $mime, 'vision');
        @unlink($tmp);
        if ($up) { $imgFileIds[] = $up['file_id']; $imgHashes[] = $up['hash']; $imgUrls[] = '/media/' . $mfn; }
    }
}

// PDF → OpenAI Files へアップロード（input_file でネイティブ読み込み。会話に紐付けて毎ターン再添付）
$docFileIds = [];
$docFileNames = [];
$docHashes = [];
$pdfFailed = 0;
if ($pdfs && $allowFiles) {
    sse(['type' => 'status', 'text' => '📄 PDFを読み込んでいます…']);
    $maxPdf = (int)($U['max_pdf_mb'] ?? 15) * 1024 * 1024;
    foreach (array_slice($pdfs, 0, 4) as $pf) {
        $name = basename((string)($pf['name'] ?? 'document.pdf'));
        $raw  = (string)($pf['data'] ?? '');
        if (($pp = strpos($raw, 'base64,')) !== false) $raw = substr($raw, $pp + 7);
        $bin = base64_decode($raw, true);
        if ($bin === false || $bin === '' || strlen($bin) > $maxPdf) { $pdfFailed++; continue; }
        $tmp = tempnam(sys_get_temp_dir(), 'chaipdf_') . '.pdf';
        file_put_contents($tmp, $bin);
        // OpenAIの読取上限(約32MB/100ページ)を超える大きいPDFはページ範囲で分割
        $parts = pdf_split_parts($tmp);
        $np = count($parts);
        if ($np > 1) sse(['type' => 'status', 'text' => "📄 大きいPDFを{$np}分割して読み込んでいます…"]);
        $okThis = 0;
        foreach ($parts as $pi => $ppath) {
            $pname = $np > 1 ? ($name . ' (' . ($pi + 1) . '/' . $np . ')') : $name;
            $up = openai_upload_dedup($ppath, $pname, 'application/pdf');   // 同一内容は再利用
            if ($ppath !== $tmp) @unlink($ppath);
            if ($up) { $docFileIds[] = $up['file_id']; $docFileNames[] = $pname; $docHashes[] = $up['hash']; $okThis++; }
        }
        @unlink($tmp);
        if (!$okThis) $pdfFailed++;
    }
    // 全部失敗＝本文が届かないので、モデルに丸投げせず明確に知らせて終了
    if (!$docFileIds && $pdfFailed) sse_error('PDFの読み込みに失敗しました（サイズが大きすぎるか、破損している可能性があります）。もう一度アップロードしてください。');
}
if (($images || $docFileIds) && !$filesUnlimited) usage_event($email, 'file');  // お試し枠を1消費

// ── データ分析ファイル(CSV/xlsx) → OpenAI Files へアップロード（メンバー限定） ──
$dataFileIds = [];
$dataFileNames = [];
$dataHashes = [];
if ($datafiles && empty($tc['allow_data_analysis'])) {
    sse(['type' => 'status', 'text' => '📊 CSV/Excel のデータ分析はメンバー限定です（LabPayで契約すると使えます）']);
} elseif ($datafiles) {
    sse(['type' => 'status', 'text' => '📊 データを読み込んでいます…']);
    $maxData = (int)($U['max_data_mb'] ?? 20) * 1024 * 1024;
    foreach (array_slice($datafiles, 0, 4) as $df) {
        $name = basename((string)($df['name'] ?? 'data.csv'));
        $raw  = (string)($df['data'] ?? '');
        if (($pp = strpos($raw, 'base64,')) !== false) $raw = substr($raw, $pp + 7);
        $bin = base64_decode($raw, true);
        if ($bin === false || $bin === '' || strlen($bin) > $maxData) continue;
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'csv');
        $mime = $ext === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
              : ($ext === 'xls' ? 'application/vnd.ms-excel' : 'text/csv');
        $tmp = tempnam(sys_get_temp_dir(), 'chaidata_') . '.' . $ext;
        file_put_contents($tmp, $bin);
        $up = openai_upload_dedup($tmp, $name, $mime);   // 同一内容は再利用
        @unlink($tmp);
        if ($up) { $dataFileIds[] = $up['file_id']; $dataFileNames[] = $name; $dataHashes[] = $up['hash']; }
    }
}
if ($datafiles) error_log('[chai] datafiles=' . count($datafiles) . ' uploaded=' . count($dataFileIds));
// ($dataInstr / 会話への永続化は、会話id確定後にまとめて行う)

function extract_pdfs(array $pdfs, int $maxChars, int $maxBytes): string {
    $chunks = [];
    foreach ($pdfs as $pf) {
        $name = basename((string)($pf['name'] ?? 'document.pdf'));
        $raw  = (string)($pf['data'] ?? '');
        if (($p = strpos($raw, 'base64,')) !== false) $raw = substr($raw, $p + 7);
        $data = base64_decode($raw, true);
        if ($data === false || $data === '' || strlen($data) > $maxBytes) continue;
        $tmp = tempnam(sys_get_temp_dir(), 'chaipdf_');
        file_put_contents($tmp, $data);
        $txt = @shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
        @unlink($tmp);
        $txt = trim((string)$txt);
        if ($txt !== '') $chunks[] = "【添付PDF: {$name}】\n" . $txt;
    }
    $all = implode("\n\n", $chunks);
    if (mb_strlen($all) > $maxChars) $all = mb_substr($all, 0, $maxChars) . "\n…(以下省略)";
    return $all;
}

// ── 会話の取得/作成 ──────────────────────────────────────
if ($convId > 0) {
    $acc = conv_access($convId, $me);
    if (!$acc) sse_error('会話が見つかりません');
    if ($acc['perm'] === 'read') sse_error('このチャットは閲覧のみです（書き込み権限がありません）。');
    $conv = $acc['conv'];
} elseif ($regen) {
    sse_error('やり直す会話がありません');
} else {
    $st = db()->prepare("INSERT INTO conversations (email, title, model) VALUES (?, '新しいチャット', ?)");
    $st->execute([$email, $model]);
    $convId = (int)db()->lastInsertId();
    $conv = conv_owned($convId, $email);
}

if ($regen) {
    $luid = (int)db()->query("SELECT COALESCE(MAX(id),0) FROM messages WHERE conversation_id = " . (int)$convId . " AND role='user'")->fetchColumn();
    if (!$luid) sse_error('やり直す発話がありません');
    db()->prepare("DELETE FROM messages WHERE conversation_id = ? AND role='assistant' AND id > ?")->execute([$convId, $luid]);
    $firstExchange = false;
} else {
    $firstExchange = ((int)db()->query("SELECT COUNT(*) FROM messages WHERE conversation_id = " . (int)$convId . " AND role='user'")->fetchColumn()) === 0;
}

$userMsgId = null;
if (!$regen) {
    $noteParts = [];
    if ($images) $noteParts[] = count($images) . '枚の画像';
    if ($docFileIds) $noteParts[] = 'PDF';
    if ($datafiles && !empty($tc['allow_data_analysis'])) $noteParts[] = 'データ';
    $stored = $content . ($noteParts ? "\n\n📎 " . implode('・', $noteParts) : '');
    db()->prepare("INSERT INTO messages (conversation_id, role, content, author_email, author_name) VALUES (?, 'user', ?, ?, ?)")
       ->execute([$convId, $stored, $email, (string)($me['name'] ?? '')]);
    $userMsgId = (int)db()->lastInsertId();
}

sse(['type' => 'meta', 'conversation_id' => $convId, 'title' => $conv['title'], 'tier' => $tier, 'model' => $model, 'user_msg_id' => $userMsgId]);

// ── 会話に紐づくファイルを永続化（毎ターン data=code_interpreter / doc=input_file で再添付） ──
foreach ($dataFileIds as $k => $fid) {
    db()->prepare("INSERT INTO conv_files (conversation_id, message_id, file_id, name, kind, hash) VALUES (?, ?, ?, ?, 'data', ?)")
       ->execute([$convId, $userMsgId, $fid, $dataFileNames[$k] ?? 'data', $dataHashes[$k] ?? null]);
}
foreach ($docFileIds as $k => $fid) {
    db()->prepare("INSERT INTO conv_files (conversation_id, message_id, file_id, name, kind, hash) VALUES (?, ?, ?, ?, 'doc', ?)")
       ->execute([$convId, $userMsgId, $fid, $docFileNames[$k] ?? 'document.pdf', $docHashes[$k] ?? null]);
}
foreach ($imgFileIds as $k => $fid) {
    db()->prepare("INSERT INTO conv_files (conversation_id, message_id, file_id, name, kind, hash) VALUES (?, ?, ?, ?, 'image', ?)")
       ->execute([$convId, $userMsgId, $fid, $imgUrls[$k] ?? 'image', $imgHashes[$k] ?? null]);
}
$cf = db()->prepare("SELECT file_id, name, kind, message_id FROM conv_files WHERE conversation_id = ? ORDER BY id ASC LIMIT 80");
$cf->execute([$convId]);
$dataFileIds = $dataFileNames = $docFileIds = $docFileNames = $imgFileIds = [];
$imgByMsg = [];   // message_id => [file_id]（発言ごとに正しく画像を添付するため）
foreach ($cf->fetchAll() as $r) {
    $k = $r['kind'] ?? 'data';
    if ($k === 'doc') { $docFileIds[] = $r['file_id']; $docFileNames[] = $r['name']; }
    elseif ($k === 'image') { $imgFileIds[] = $r['file_id']; if ($r['message_id']) $imgByMsg[(int)$r['message_id']][] = $r['file_id']; }
    else { $dataFileIds[] = $r['file_id']; $dataFileNames[] = $r['name']; }
}
$dataFileIds = array_values(array_unique($dataFileIds));
$docFileIds  = array_values(array_unique($docFileIds));
$imgFileIds  = array_values(array_unique($imgFileIds));   // 編集ツールの対象（最新画像）判定に使用
if (empty($tc['allow_data_analysis'])) { $dataFileIds = []; $dataFileNames = []; }   // CSV/xlsx分析は会員のみ
$dataInstr = '';
if ($dataFileIds) {
    $dataInstr = '（この会話にはデータファイル（' . implode(', ', $dataFileNames) . '）が code_interpreter にアップ済みです。分析要求時は必ず code_interpreter で読み込み、読めない場合は encoding(utf-8/cp932/shift_jis)や区切り文字を変えて再試行。グラフは一意名で1回だけ savefig（plt.show や二重保存はしない）。「再アップロードして」とは言わないこと。）';
}
if ($docFileIds) {
    $dataInstr .= '（この会話には添付PDF（' . implode(', ', $docFileNames) . '）が input_file として渡されています。要約・査読・引用・図表や実験条件の確認は、必ずそのPDF本文を直接参照して回答すること。以前の要約だけに頼らず原文を読むこと。「本文を参照できない」とは言わないこと。）';
}

// ── Responses API の input 構築 ──────────────────────────
$hist = (int)($tc['history_messages'] ?? 8);
$st = db()->prepare(
    "SELECT id, role, content FROM messages WHERE conversation_id = ? AND role IN ('user','assistant')
     ORDER BY id DESC LIMIT ?"
);
$st->bindValue(1, $convId, PDO::PARAM_INT);
$st->bindValue(2, $hist, PDO::PARAM_INT);
$st->execute();
$recent = array_reverse($st->fetchAll());

$input = [];
foreach ($recent as $i => $m) {
    $mid = (int)($m['id'] ?? 0);
    $isLastUser = ($i === count($recent) - 1) && $m['role'] === 'user';
    if ($m['role'] === 'user') {
        // その発言に紐づく画像だけを添付（古い画像の誤説明を防ぐ）。PDF等は最後の発言にまとめて添付
        $myImgs = $imgByMsg[$mid] ?? [];
        $useDocs = $isLastUser ? $docFileIds : [];
        $names = $isLastUser ? array_merge($docFileNames, $dataFileNames) : [];
        $base  = ($isLastUser && !$regen) ? $content : (string)$m['content'];
        $text  = $base . (($isLastUser && !$regen && $names) ? "\n\n[添付ファイル: " . implode(', ', $names) . "]" : '');
        if ($myImgs || $useDocs) {
            $parts = [['type' => 'input_text', 'text' => $text !== '' ? $text : '添付ファイルを確認してください。']];
            foreach ($myImgs as $fid) $parts[] = ['type' => 'input_image', 'file_id' => $fid];
            foreach ($useDocs as $fid) $parts[] = ['type' => 'input_file', 'file_id' => $fid];
            $input[] = ['role' => 'user', 'content' => $parts];
        } else {
            $input[] = ['role' => 'user', 'content' => $text];
        }
    } else {
        $input[] = ['role' => 'assistant', 'content' => (string)$m['content']];
    }
}

// ── tools（Web検索・画像生成） ───────────────────────────
$tools = [];
if (!empty($tc['allow_web_search'])) $tools[] = ['type' => 'web_search'];
// データ分析: アップロード済みファイルがあれば code_interpreter を付与
if ($dataFileIds) $tools[] = ['type' => 'code_interpreter', 'container' => ['type' => 'auto', 'file_ids' => $dataFileIds]];
// 画像生成: pro=無制限 / free=1日お試し枠
$genUnlimited = !empty($tc['allow_image_gen']);
$genLimit     = (int)($tc['image_gen_limit'] ?? 0);
$canGen       = $genUnlimited || (usage_window_count($email, 'imggen', $win) < $genLimit);
$genNote      = (!$genUnlimited && !$canGen && $genLimit > 0)
    ? '（注意: 画像生成はお試し上限に達しています。求められても生成せず、メンバー契約で無制限に使える旨を丁寧に案内してください。）' : '';
// Web検索が無いティア向けの案内文（不案内にならないように）
$webNote = empty($tc['allow_web_search'])
    ? '（Web検索は使えません。ユーザーが最新情報の取得やWeb検索を必要とする場合は「現状、この環境からWebを直接検索する機能はありません。Webを検索する機能を付与するには、LabPayでAIサブスクを契約してください」と案内してください。）' : '';
if ($canGen) {
    $tools[] = [
        'type' => 'function',
        'name' => 'generate_image',
        'description' => 'ユーザーが画像・イラスト・ロゴ・図などの生成/作成/描画を求めたときに呼ぶ。文章の説明だけを求めている場合は呼ばない。既存の画像を加工する場合は edit_image を使う。',
        'parameters' => [
            'type' => 'object',
            'properties' => ['prompt' => ['type' => 'string', 'description' => '生成する画像の詳細な説明（英語推奨、なければ日本語）']],
            'required' => ['prompt'],
        ],
    ];
    // アップロード済み/生成済みの画像があるときだけ「編集」ツールを出す
    if ($imgFileIds) {
        $tools[] = [
            'type' => 'function',
            'name' => 'edit_image',
            'description' => 'アップロード済み（または直前に生成した）画像を編集・加工・修正するときに呼ぶ。背景変更、要素の追加/削除、色やスタイルの変更など。まったく新規に一から作る場合は generate_image を使う。',
            'parameters' => [
                'type' => 'object',
                'properties' => ['prompt' => ['type' => 'string', 'description' => '画像にどんな変更を加えるかの具体的な指示（英語推奨）']],
                'required' => ['prompt'],
            ],
        ];
    }
}

// 言語混線対策: たまに「だけ」がヘブライ文字(בלבד)になる等の他言語混入を防ぐ
$langNote = '（回答は自然な日本語で統一し、意図せず他言語の文字（ヘブライ文字・アラビア文字・ハングル等）の単語を混ぜないこと。日本語にすべき語は必ず日本語で書く。ユーザーが特定言語での回答を求めた場合のみその言語を使う。）';

$oa = $CFG['openai'];
$payload = [
    'model'             => $model,
    'input'             => $input,
    'instructions'      => $tc['system_prompt'] . $langNote . $genNote . $webNote . $dataInstr,
    'stream'            => true,
    'store'             => false,
    'max_output_tokens' => (int)($tc['max_tokens'] ?? 2000),
];
if ($tools) { $payload['tools'] = $tools; $payload['tool_choice'] = 'auto'; }

// ── ストリーミング呼び出し（Responses SSE をパース） ─────
$assistant = '';
$buf = '';
$toolName = '';
$toolArgs = '';
$searchNotified = false;
$ciNotified = false;
$ciFiles = [];
$ciCodeBuf = '';
$hadText = false;
$producedMedia = false;   // 画像生成/グラフ等の視覚的成果物を出したか（無言注記の抑止用）
$incompleteFlag = false;
$apiErr = '';

$ch = curl_init(rtrim($oa['base_url'], '/') . '/responses');
$headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $oa['api_key']];
if (!empty($oa['org']))     $headers[] = 'OpenAI-Organization: ' . $oa['org'];
if (!empty($oa['project'])) $headers[] = 'OpenAI-Project: ' . $oa['project'];

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$assistant, &$buf, &$toolName, &$toolArgs, &$searchNotified, &$ciNotified, &$ciFiles, &$ciCodeBuf, &$hadText, &$incompleteFlag, &$apiErr) {
        if (connection_aborted()) return 0;
        $buf .= $chunk;
        while (($pos = strpos($buf, "\n\n")) !== false) {
            $frame = substr($buf, 0, $pos);
            $buf   = substr($buf, $pos + 2);
            foreach (explode("\n", $frame) as $line) {
                $line = trim($line);
                if (strncmp($line, 'data:', 5) !== 0) continue;
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') continue;
                $j = json_decode($data, true);
                if (!is_array($j) || !isset($j['type'])) continue;
                $type = $j['type'];
                if ($type === 'response.output_text.delta') {
                    $d = $j['delta'] ?? '';
                    if ($d !== '') { $assistant .= $d; $hadText = true; sse(['type' => 'delta', 'text' => $d]); }
                } elseif ($type === 'response.incomplete') {
                    $incompleteFlag = true;
                } elseif ($type === 'response.created') {
                    sse(['type' => 'status', 'text' => '🧠 考えています…']);
                } elseif ($type === 'response.reasoning_summary_text.delta') {
                    $rd = $j['delta'] ?? ''; if ($rd !== '') sse(['type' => 'reasoning', 'text' => $rd]);
                } elseif ($type === 'response.output_item.added') {
                    $it = $j['item'] ?? [];
                    $itype = $it['type'] ?? '';
                    if ($itype === 'function_call') $toolName = $it['name'] ?? $toolName;
                    elseif ($itype === 'web_search_call') {
                        $q = $it['action']['query'] ?? ($it['query'] ?? '');
                        $searchNotified = true;
                        sse(['type' => 'status', 'text' => $q !== '' ? ('🔍 「' . mb_substr($q, 0, 40) . '」を検索しています…') : '🔍 ウェブを検索しています…']);
                    }
                } elseif (strpos($type, 'web_search') !== false) {
                    if (!$searchNotified) { $searchNotified = true; sse(['type' => 'status', 'text' => '🔍 ウェブを検索しています…']); }
                } elseif ($type === 'response.code_interpreter_call_code.delta') {
                    $ciCodeBuf .= $j['delta'] ?? '';
                    if (!$ciNotified) { $ciNotified = true; sse(['type' => 'status', 'text' => '🧮 データを分析中（コード実行）…']); }
                } elseif ($type === 'response.code_interpreter_call_code.done') {
                    $codeStr = $j['code'] ?? $ciCodeBuf;
                    if (trim((string)$codeStr) !== '') {
                        $cmd = "\n\n<<<CODE>>>\n" . rtrim((string)$codeStr) . "\n<<<ENDCODE>>>";
                        $assistant .= $cmd;
                        sse(['type' => 'code', 'markdown' => $cmd]);
                    }
                    $ciCodeBuf = '';
                } elseif (strpos($type, 'code_interpreter') !== false) {
                    if (!$ciNotified) { $ciNotified = true; sse(['type' => 'status', 'text' => '🧮 データを分析中（コード実行）…']); }
                } elseif ($type === 'response.output_text.annotation.added') {
                    $an = $j['annotation'] ?? [];
                    if (($an['type'] ?? '') === 'container_file_citation' && !empty($an['file_id'])) {
                        $ciFiles[$an['file_id']] = ['container_id' => $an['container_id'] ?? '', 'file_id' => $an['file_id'], 'filename' => $an['filename'] ?? 'file'];
                    }
                } elseif ($type === 'response.function_call_arguments.delta') {
                    $toolArgs .= $j['delta'] ?? '';
                } elseif ($type === 'response.function_call_arguments.done') {
                    if (!empty($j['arguments'])) $toolArgs = $j['arguments'];
                } elseif ($type === 'response.failed' || $type === 'response.error' || $type === 'error') {
                    $apiErr = $j['response']['error']['message'] ?? ($j['message'] ?? 'error');
                }
            }
        }
        return strlen($chunk);
    },
]);
curl_exec($ch);
$err  = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 画像生成ツールが呼ばれていれば生成して追記
if ($toolName === 'generate_image' && trim($toolArgs) !== '') {
    $args = json_decode($toolArgs, true);
    $p = is_array($args) ? trim((string)($args['prompt'] ?? '')) : '';
    if ($p !== '') {
        sse(['type' => 'status', 'text' => '🎨 画像を生成しています…']);
        $g = chai_generate_image($p);
        if ($g['ok']) {
            if (!$genUnlimited) usage_event($email, 'imggen');  // お試し枠を1消費
            if (!empty($g['bin'])) persist_image_bin($convId, $userMsgId, $g['bin']);   // 生成画像も保存＝続けて編集できる
            $alt = mb_substr(str_replace([']', "\n"], ' ', $p), 0, 60);
            // 全文プロンプトをマーカーで埋め込み、フロントは既定では小さな「🎨 プロンプト」トグルだけ表示
            $cap = $hadText ? '' : '<<<IMGPROMPT>>>' . str_replace(["\n", "\r"], ' ', $p) . '<<<ENDIMGPROMPT>>>' . "\n\n";
            $imgmd = ($assistant !== '' ? "\n\n" : '') . $cap . '![' . $alt . '](' . $g['url'] . ')';
            $assistant .= $imgmd;
            sse(['type' => 'image', 'markdown' => $imgmd]);
            $producedMedia = true;
        } else {
            $emsg = ($assistant !== '' ? "\n\n" : '') . '（画像生成に失敗しました：' . $g['error'] . '）';
            $assistant .= $emsg;
            sse(['type' => 'image', 'markdown' => $emsg]);
            $producedMedia = true;   // 失敗メッセージは出したので無言注記は不要
        }
    }
}

// 画像編集ツール: アップロード済み/生成済みの最新画像を /images/edits で加工
if ($toolName === 'edit_image' && trim($toolArgs) !== '' && $imgFileIds) {
    $args = json_decode($toolArgs, true);
    $p = is_array($args) ? trim((string)($args['prompt'] ?? '')) : '';
    $targetFid = (string)end($imgFileIds);   // 直近の画像を対象
    if ($p !== '' && $targetFid !== '') {
        sse(['type' => 'status', 'text' => '🎨 画像を編集しています…']);
        $srcBin = openai_fetch_file_content($targetFid);
        $g = $srcBin ? chai_edit_image($srcBin, 'image/png', $p) : ['ok' => false, 'error' => '元画像を取得できませんでした'];
        if ($g['ok']) {
            if (!$genUnlimited) usage_event($email, 'imggen');
            if (!empty($g['bin'])) persist_image_bin($convId, $userMsgId, $g['bin']);   // 編集結果も保存＝さらに編集できる
            $alt = mb_substr(str_replace([']', "\n"], ' ', $p), 0, 60);
            $cap = $hadText ? '' : '<<<IMGPROMPT>>>' . str_replace(["\n", "\r"], ' ', $p) . '<<<ENDIMGPROMPT>>>' . "\n\n";
            $imgmd = ($assistant !== '' ? "\n\n" : '') . $cap . '![' . $alt . '](' . $g['url'] . ')';
            $assistant .= $imgmd;
            sse(['type' => 'image', 'markdown' => $imgmd]);
            $producedMedia = true;
        } else {
            $emsg = ($assistant !== '' ? "\n\n" : '') . '（画像編集に失敗しました：' . $g['error'] . '）';
            $assistant .= $emsg;
            sse(['type' => 'image', 'markdown' => $emsg]);
            $producedMedia = true;
        }
    }
}

// code_interpreter が生成したファイル（グラフ等）を取得して表示。
// 無名の自動表示画像(filename==file_id)は savefig の名前付きと重複するので、名前付きを優先。
$named = []; $unnamed = [];
foreach ($ciFiles as $f) {
    $fn = (string)($f['filename'] ?? '');
    if ($fn === '' || strncmp($fn, (string)$f['file_id'], strlen((string)$f['file_id'])) === 0 || strncmp($fn, 'cfile_', 6) === 0) $unnamed[] = $f;
    else $named[] = $f;
}
foreach (($named ?: $unnamed) as $f) {
    if (empty($f['container_id'])) continue;
    $bin = openai_fetch_container_file($f['container_id'], $f['file_id']);
    if (!$bin) continue;
    $ext   = strtolower(preg_replace('/[^a-z0-9]/i', '', pathinfo($f['filename'], PATHINFO_EXTENSION) ?: 'png')) ?: 'bin';
    $fname = 'ci_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dir   = __DIR__ . '/media'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    file_put_contents($dir . '/' . $fname, $bin);
    $url   = '/media/' . $fname;
    $label = str_replace([']', "\n"], ' ', $f['filename']);
    $md    = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)
        ? "\n\n![" . $label . "](" . $url . ")"
        : "\n\n[📄 " . $label . "](" . $url . ")";
    $assistant .= $md;
    sse(['type' => 'image', 'markdown' => $md]);
    $producedMedia = true;
}

if ($assistant === '' && ($code >= 400 || $err !== '' || $apiErr !== '')) {
    $m = $apiErr !== '' ? $apiErr : "応答の生成に失敗しました（{$code}）";
    sse_error(mb_substr($m, 0, 120) . '。モデルを変えて再度お試しください。');
}
// 回答テキストが出ないまま終了したときの注記。ただし画像/グラフ等を出したなら不要。
if (!$hadText && $incompleteFlag) {   // トークン上限で途中終了（成果物の有無に関わらず警告）
    $note = ($assistant !== '' ? "\n\n" : '') . '⚠️ 回答が長くなりトークン上限に達したため、結論まで出力できませんでした。「続けて」と送るか、上位モデル（GPT-5.6 Sol）に切り替えてお試しください。';
    $assistant .= $note;
    sse(['type' => 'delta', 'text' => $note]);
} elseif (!$hadText && !$producedMedia) {   // 本当に何も出せなかった時だけ
    $note = ($assistant !== '' ? "\n\n" : '') . '（回答テキストが生成されませんでした。もう一度お試しください。）';
    $assistant .= $note;
    sse(['type' => 'delta', 'text' => $note]);
}

db()->prepare("INSERT INTO messages (conversation_id, role, content, model) VALUES (?, 'assistant', ?, ?)")
   ->execute([$convId, $assistant, $model]);
$asstId = (int)db()->lastInsertId();

usage_event($email, 'msg');
db()->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")->execute([$convId]);

$title = $conv['title'];
if ($firstExchange) {
    $seed = $content !== '' ? $content : (!empty($noteParts) ? implode('・', $noteParts) . 'について' : '新しいチャット');
    $title = mb_substr(preg_replace('/\s+/u', ' ', $seed), 0, 40);
    if ($title === '') $title = '新しいチャット';
    db()->prepare("UPDATE conversations SET title = ? WHERE id = ?")->execute([$title, $convId]);
}

sse(['type' => 'done', 'conversation_id' => $convId, 'title' => $title, 'model' => $model, 'msg_id' => $asstId]);
