<?php
/**
 * C:\Dropbox\Programs\Claude\chai\chat.php
 *   -> /var/www/chai/chat.php
 *
 * 返事を作って、少しずつ画面へ流すところ (OpenAI Responses API への中継、SSE)。
 * ネイティブ Web検索 ＋ 画像生成/編集 ＋ 画像/PDF/データ入力に対応。
 *
 * POST JSON { conversation_id?, content, model?, images?[], pdfs?[], datafiles?[], regenerate? }
 * 画面へ流すもの: meta / delta / reasoning / status / code / image / done / error
 *   (event: 行は使わず、data: の JSON の type で見分ける)
 *
 * このファイルは手順書。ひとつひとつの中身は lib/chat/ にある:
 *   limits.php       ティアとモデルを決める
 *   uploads.php      添付を OpenAI のファイル置き場へ上げる
 *   conversation.php 会話を用意して発言を残す
 *   request.php      これまでの話・使える道具・言い添えを組み立てる
 *   stream.php       受けながら流す
 *   finish.php       画像や図表を足して、保存して締める
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/sse.php';
foreach (['limits', 'uploads', 'conversation', 'request', 'stream', 'finish'] as $__m) {
    require_once __DIR__ . "/lib/chat/{$__m}.php";
}

// ── 受け取る ─────────────────────────────────────────────
$me    = require_login_json();
$email = $me['email'];
$b     = read_json_body();

$content   = trim((string)($b['content'] ?? ''));
$images    = is_array($b['images'] ?? null) ? $b['images'] : [];
$pdfs      = is_array($b['pdfs'] ?? null) ? $b['pdfs'] : [];
$datafiles = is_array($b['datafiles'] ?? null) ? $b['datafiles'] : [];   // CSV/xlsx（code_interpreter用）
$convId    = (int)($b['conversation_id'] ?? 0);
$reqModel  = (string)($b['model'] ?? '');
$regen     = !empty($b['regenerate']);

sse_begin();
if (!$regen && $content === '' && !$images && !$pdfs && !$datafiles) sse_error('メッセージが空です');

// ── 1. ティアとモデルを決める（上限に当たったら代わりのモデルに落とす）──
$T = chat_prepare_tier($email, $reqModel, $images, $pdfs);
$tier = $T['tier']; $tc = $T['tc']; $win = $T['win']; $model = $T['model'];
$images = $T['images']; $pdfs = $T['pdfs'];

// ── 2. 添付を上げる ──────────────────────────────────────
$U   = $GLOBALS['CFG']['upload'] ?? [];
$img  = chat_upload_images($images, $T['visionOK'], $U);
$images = $img['images'];
$doc  = chat_upload_pdfs($pdfs, $T['allowFiles'], $U);
$data = chat_upload_datafiles($datafiles, $tc, $U);
if (($images || $doc['ids']) && !$T['filesUnlimited']) usage_event($email, 'file');   // お試し枠を1消費

// ── 3. 会話を用意して、今回の発言を残す ──────────────────
$C = chat_open_conversation($me, $email, $convId, $regen, $model);
$convId = $C['id']; $conv = $C['conv']; $firstExchange = $C['firstExchange'];

$noteParts = [];
if (!$regen) {
    if ($images) $noteParts[] = count($images) . '枚の画像';
    if ($doc['ids']) $noteParts[] = 'PDF';
    if ($datafiles && !empty($tc['allow_data_analysis'])) $noteParts[] = 'データ';
}
$userMsgId = chat_store_user_message($convId, $email, $me, $content, $regen, $noteParts);

sse(['type' => 'meta', 'conversation_id' => $convId, 'title' => $conv['title'],
     'tier' => $tier, 'model' => $model, 'user_msg_id' => $userMsgId]);

// ── 4. 添付を会話に紐づけ、会話ぶんをまとめて集め直す ────
chat_persist_files($convId, $userMsgId, $img, $doc, $data);
$files = chat_collect_files($convId, $tc);

// ── 5. OpenAI に渡すものを組み立てる ─────────────────────
$input   = chat_build_input($convId, $tc, $files, $content, $regen);
$toolset = chat_build_tools($tc, $email, $win, $files);
$payload = chat_build_payload($model, $input, $tc, $toolset, $files['instr']);

// ── 6. 受けながら流す ────────────────────────────────────
$st = chat_stream($GLOBALS['CFG']['openai'], $payload);
$assistant = $st['assistant'];

// ── 7. 画像や図表を足す ──────────────────────────────────
[$assistant, $producedMedia] = chat_run_image_tools(
    $st + ['assistant' => $assistant], $files, $convId, $userMsgId, $email, $toolset['genUnlimited']);
[$assistant, $producedMedia] = chat_attach_ci_files($st['ciFiles'], $assistant, $producedMedia);

// ── 8. 何も出せなかった時の断り ──────────────────────────
if ($assistant === '' && ($st['code'] >= 400 || $st['err'] !== '' || $st['apiErr'] !== '')) {
    $m = $st['apiErr'] !== '' ? $st['apiErr'] : "応答の生成に失敗しました（{$st['code']}）";
    sse_error(mb_substr($m, 0, 120) . '。モデルを変えて再度お試しください。');
}
// 回答テキストが出ないまま終了したときの注記。ただし画像/グラフ等を出したなら不要。
if (!$st['hadText'] && $st['incompleteFlag']) {   // トークン上限で途中終了（成果物の有無に関わらず警告）
    $note = ($assistant !== '' ? "\n\n" : '') . '⚠️ 回答が長くなりトークン上限に達したため、結論まで出力できませんでした。「続けて」と送るか、上位モデル（GPT-5.6 Sol）に切り替えてお試しください。';
    $assistant .= $note;
    sse(['type' => 'delta', 'text' => $note]);
} elseif (!$st['hadText'] && !$producedMedia) {   // 本当に何も出せなかった時だけ
    $note = ($assistant !== '' ? "\n\n" : '') . '（回答テキストが生成されませんでした。もう一度お試しください。）';
    $assistant .= $note;
    sse(['type' => 'delta', 'text' => $note]);
}

// ── 9. 残して締める ──────────────────────────────────────
chat_finish($convId, $conv, $model, $assistant, $email, $firstExchange, $content, $noteParts);
