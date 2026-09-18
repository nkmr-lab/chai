<?php
/**
 * lib.php — 共通ヘルパ（認証・JSON・ティア判定・利用量）。
 * サブスク契約の有無は LabPay(pay.nkmr.io) が真実の源。chai は照合するだけ。
 * db.php を前提に require される。
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/nkmrauth.php';

/** JSON を返して終了。 */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * いまのブラウザの身元。 ふだんは nkmrauth の署名クッキーだけを見る。
 * auth.test_identity_header に名前が入っている時だけ、 そのヘッダの JSON を身元として受ける。
 * 本番の config.local.php にはこの鍵が無いので、 抜け道は本番では死んでいる。
 */
function chai_identity(): ?array {
    global $CFG;
    $hdr = trim((string)($CFG['auth']['test_identity_header'] ?? ''));
    if ($hdr !== '') {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $hdr));
        $raw = (string)($_SERVER[$key] ?? '');
        if ($raw !== '') {
            $p = json_decode($raw, true);
            if (is_array($p) && trim((string)($p['email'] ?? '')) !== '') return $p;
        }
    }
    return nkmrauth_identity();
}

/** JSON API 用のログイン必須ゲート。未ログインは 401（リダイレクトしない）。 */
function require_login_json(): array {
    $id = chai_identity();
    if (!$id) json_out(['error' => 'auth_required'], 401);
    return $id;
}

/** POST の JSON ボディを配列で取得。 */
function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

/**
 * LabPay に AI サブスク契約状況を照会（サーバ側・権威判定）。
 * 受信した SSO cookie をそのまま /api/ai-sub/check に転送する
 * （NKMRID は .nkmr.io 共有なので chai も受け取っている）。
 * DB(sub_cache)に cache_ttl 秒キャッシュして LabPay を叩きすぎない。
 * 返り値: ['active'=>bool,'status'=>str,'expires_at'=>?str,'days_left'=>int,'user_id'=>?int]
 */
function labpay_sub_status(string $email): array {
    global $CFG;
    $lp  = $CFG['labpay'];
    $ttl = (int)($lp['cache_ttl'] ?? 300);

    // 鮮度は SQL で算出（保存も NOW() も同じ +09:00。PHP strtotime との TZ 差で誤判定しない）
    $st = db()->prepare("SELECT *, TIMESTAMPDIFF(SECOND, checked_at, NOW()) AS age FROM sub_cache WHERE email = ?");
    $st->execute([$email]);
    $cache = $st->fetch();
    $age = $cache ? (int)$cache['age'] : PHP_INT_MAX;
    if ($cache && $age >= 0 && $age < $ttl) {
        return _sub_from_cache($cache);
    }

    $res = _labpay_fetch_check($lp);
    if ($res === null) {
        // 通信失敗: 直近(1時間以内)のキャッシュのみ流用。古い/無ければ未契約扱い（=解約が滞留しない）
        if ($cache && $age >= 0 && $age < 3600) return _sub_from_cache($cache);
        return ['active' => false, 'status' => 'unknown', 'expires_at' => null, 'days_left' => 0, 'user_id' => null];
    }
    db()->prepare(
        "INSERT INTO sub_cache (email, active, status, expires_at, days_left, checked_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE active=VALUES(active), status=VALUES(status),
           expires_at=VALUES(expires_at), days_left=VALUES(days_left), checked_at=NOW()"
    )->execute([$email, (int)$res['active'], $res['status'], $res['expires_at'], $res['days_left']]);
    return $res;
}

function _sub_from_cache(array $c): array {
    return [
        'active'     => (bool)$c['active'],
        'status'     => (string)$c['status'],
        'expires_at' => $c['expires_at'],
        'days_left'  => (int)$c['days_left'],
        'user_id'    => null,
    ];
}

/**
 * LabPay /api/ai-sub/check を叩く。null=通信失敗。
 * chai には NKMRID しか届かない（labpay_sid は pay.nkmr.io 限定）ので、
 * まず NKMRID で /api/auth/sso-login して labpay_sid を発行させ、その cookie で照会する（2段）。
 */
function _labpay_fetch_check(array $lp): ?array {
    $nkmrid = $_COOKIE['NKMRID'] ?? '';
    if ($nkmrid === '') return ['active' => false, 'status' => 'never', 'expires_at' => null, 'days_left' => 0, 'user_id' => null];

    $base = rtrim($lp['base_url'], '/');
    [$code, $body] = _labpay_bridge_get($base, $nkmrid, $lp['check_path'] ?? '/api/ai-sub/check');

    if ($code === 401 || $code === 403) return ['active' => false, 'status' => 'unauth', 'expires_at' => null, 'days_left' => 0, 'user_id' => null];
    if ($code !== 200 || !$body) return null;
    $j = json_decode($body, true);
    if (!is_array($j)) return null;
    return [
        'active'     => !empty($j['active']),
        'status'     => (string)($j['status'] ?? 'unknown'),
        'expires_at' => $j['expires_at'] ?? null,
        'days_left'  => (int)($j['days_left'] ?? 0),
        'user_id'    => isset($j['user_id']) ? (int)$j['user_id'] : null,
    ];
}

/**
 * NKMRID → sso-login(labpay_sid発行) → 指定パスをGET、を1つの curl ハンドル
 * （メモリ内 cookie エンジン）で連続実行。返り値 [http_code, body, ssologin_code]。
 */
function _labpay_bridge_get(string $base, string $nkmrid, string $path): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_COOKIEFILE, '');            // cookie エンジン有効化（メモリ）
    curl_setopt($ch, CURLOPT_COOKIE, 'NKMRID=' . $nkmrid); // NKMRID を毎リクエスト送る
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);

    // 1) labpay_sid を発行（NKMRID から。CSRF は X-Requested-With で満たす）
    curl_setopt($ch, CURLOPT_URL, $base . '/api/auth/sso-login');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: labpay', 'Accept: application/json']);
    curl_exec($ch);
    $ssologin = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // 2) 目的のパスを GET（ハンドルが labpay_sid を自動付与）
    curl_setopt($ch, CURLOPT_POST, false);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_URL, $base . $path);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, $body, $ssologin];
}

/** 管理者か（要望の全件管理）。config.admins に email か nkmr username が一致。 */
function is_admin(array $me): bool {
    global $CFG;
    $adm = array_map('strtolower', (array)($CFG['admins'] ?? []));
    return in_array(strtolower((string)($me['email'] ?? '')), $adm, true)
        || in_array(strtolower((string)($me['user'] ?? '')), $adm, true);
}

/** ティア設定を返す。 */
function tier_config(string $tier): array {
    global $CFG;
    return $CFG['tiers'][$tier] ?? $CFG['tiers']['free'];
}

/** ティアで選べるモデル一覧 [{id,label,vision}]。 */
function models_for_tier(string $tier): array {
    global $CFG;
    $out = [];
    foreach (($CFG['models'] ?? []) as $id => $m) {
        if ($tier === 'pro' || ($m['tier'] ?? 'pro') === 'free') {
            $out[] = ['id' => $id, 'label' => $m['label'] ?? $id, 'vision' => !empty($m['vision'])];
        }
    }
    return $out;
}

/** そのティアでこのモデルを使ってよいか。 */
function model_allowed(string $model, string $tier): bool {
    global $CFG;
    $m = $CFG['models'][$model] ?? null;
    if (!$m) return false;
    return $tier === 'pro' || ($m['tier'] ?? 'pro') === 'free';
}

/** ティアの既定モデル。 */
function default_model_for(string $tier): string {
    global $CFG;
    return $CFG['default_model'][$tier] ?? ($tier === 'pro' ? 'gpt-4o' : 'gpt-4o-mini');
}

/** モデルが vision（画像入力）対応か。 */
function model_has_vision(string $model): bool {
    global $CFG;
    return !empty($CFG['models'][$model]['vision']);
}

/** gpt-image-1 で画像生成 → media/ に保存。返り値 ['ok'=>bool,'url'=>?str,'error'=>?str]。 */
/** image_gen で使うモデル候補（第一候補→フォールバック）。未認証403等で次を試す。 */
function chai_image_models(): array {
    $ig = $GLOBALS['CFG']['image_gen'];
    return array_values(array_filter([$ig['model'] ?? 'gpt-image-1.5', $ig['fallback'] ?? null]));
}
function chai_generate_image(string $prompt, ?string $size = null): array {
    global $CFG;
    $oa = $CFG['openai']; $ig = $CFG['image_gen'];
    $err = '生成失敗';
    foreach (chai_image_models() as $model) {
        $payload = ['model' => $model, 'prompt' => $prompt, 'size' => $size ?: ($ig['size'] ?? '1024x1024'), 'n' => 1];
        if (!empty($ig['quality']) && strncmp($model, 'gpt-image', 9) === 0) $payload['quality'] = $ig['quality']; // qualityはgpt-image系のみ
        $ch = curl_init(rtrim($oa['base_url'], '/') . '/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $oa['api_key']],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 280,
        ]);
        $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = json_decode((string)$res, true);
        if ($code === 200 && !empty($j['data'][0])) {
            $row = $j['data'][0];
            $bin = !empty($row['b64_json']) ? base64_decode($row['b64_json']) : (!empty($row['url']) ? @file_get_contents($row['url']) : null);
            if ($bin) {
                $fname = 'gen_' . bin2hex(random_bytes(8)) . '.png';
                $dir = __DIR__ . '/media'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
                file_put_contents($dir . '/' . $fname, $bin);
                return ['ok' => true, 'url' => '/media/' . $fname, 'bin' => $bin, 'model' => $model];
            }
        }
        $err = $j['error']['message'] ?? "生成失敗（{$code}）";
        error_log('[chai-img] gen model=' . $model . ' http=' . $code . ' -> fallback? err=' . mb_substr($err, 0, 80));
    }
    return ['ok' => false, 'error' => $err];
}

/** 生成/編集した画像を vision ファイルとして保存し conv_files(kind=image) に記録（続けて編集できるように）。 */
function persist_image_bin(int $convId, ?int $msgId, string $bin): void {
    $tmp = tempnam(sys_get_temp_dir(), 'pgen') . '.png'; file_put_contents($tmp, $bin);
    $up = openai_upload_dedup($tmp, 'image.png', 'image/png', 'vision'); @unlink($tmp);
    if ($up) db()->prepare("INSERT INTO conv_files (conversation_id, message_id, file_id, name, kind, hash) VALUES (?, ?, ?, 'image', 'image', ?)")
        ->execute([$convId, $msgId, $up['file_id'], $up['hash']]);
}

/** サブスク状況からティア名を返す（'pro' | 'free'）。 */
function tier_name(array $sub): string {
    return !empty($sub['active']) ? 'pro' : 'free';
}

/** サブスク+ティアをまとめてフロントに渡す形で返す。 */
function tier_state(string $email): array {
    global $CFG;
    $sub  = labpay_sub_status($email);
    $name = tier_name($sub);
    $tc   = tier_config($name);
    $win = (int)($tc['window_hours'] ?? 8);
    $cap = (int)($tc['message_cap'] ?? 0);
    $usedWin = $cap > 0 ? usage_window_count($email, 'msg', $win) : 0;
    return [
        'tier'            => $name,
        'label'           => $tc['label'] ?? $name,
        'models'          => models_for_tier($name),
        'default_model'   => default_model_for($name),
        'model'           => default_model_for($name),      // 後方互換（バッジ表示用）
        'allow_images'    => (bool)($tc['allow_images'] ?? false),
        'allow_image_gen' => (bool)($tc['allow_image_gen'] ?? false),
        'can_attach'      => !empty($tc['allow_images']) || (int)($tc['file_limit'] ?? 0) > 0,
        'can_image_gen'   => !empty($tc['allow_image_gen']) || (int)($tc['image_gen_limit'] ?? 0) > 0,
        'active'          => (bool)$sub['active'],
        'sub_status'      => $sub['status'],          // active|graceful|expired|never|unauth|unknown
        'expires_at'      => $sub['expires_at'],
        'days_left'       => $sub['days_left'],
        'window_hours'    => $win,
        'message_cap'     => $cap,
        'used_window'     => $usedWin,
        'remaining_window'=> $cap > 0 ? max(0, $cap - $usedWin) : null,
        'price_points'    => (int)($CFG['subscription']['price_points'] ?? 500),
        'period_days'     => (int)($CFG['subscription']['period_days'] ?? 7),
        'subscribe_url'   => $CFG['labpay']['subscribe_url'] ?? 'https://pay.nkmr.io/#/ai-sub',
    ];
}

/** 本日の送信回数。 */
function usage_today(string $email): int {
    $st = db()->prepare("SELECT message_count FROM usage_daily WHERE email = ? AND day = CURDATE()");
    $st->execute([$email]);
    return (int)($st->fetchColumn() ?: 0);
}

/** 本日の送信回数を +1。 */
function usage_bump(string $email): void {
    $st = db()->prepare(
        "INSERT INTO usage_daily (email, day, message_count) VALUES (?, CURDATE(), 1)
         ON DUPLICATE KEY UPDATE message_count = message_count + 1"
    );
    $st->execute([$email]);
}

/** 直近 $hours 時間の利用回数（msg / file / imggen）。 */
function usage_window_count(string $email, string $feature, int $hours): int {
    $h = max(1, $hours);
    $st = db()->prepare("SELECT COUNT(*) FROM usage_events WHERE email = ? AND feature = ? AND at > (NOW() - INTERVAL {$h} HOUR)");
    $st->execute([$email, $feature]);
    return (int)$st->fetchColumn();
}

/** 利用イベントを1件記録。 */
function usage_event(string $email, string $feature): void {
    db()->prepare("INSERT INTO usage_events (email, feature) VALUES (?, ?)")->execute([$email, $feature]);
}

/** ファイルを OpenAI Files API にアップロード。返り値=file_id or null。purpose: user_data(既定) / vision(画像)。 */
function openai_upload_file(string $tmpPath, string $filename, string $mime, string $purpose = 'user_data'): ?string {
    global $CFG; $oa = $CFG['openai'];
    $ch = curl_init(rtrim($oa['base_url'], '/') . '/files');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $oa['api_key']],
        CURLOPT_POSTFIELDS => ['purpose' => $purpose, 'file' => new CURLFile($tmpPath, $mime, $filename)],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    ]);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$r, true);
    return ($code === 200 && !empty($j['id'])) ? $j['id'] : null;
}

/**
 * 大きいPDFを OpenAI の読取上限（1ファイル約32MB/約100ページ）に収まるよう
 * ページ範囲で複数パートに分割する。返り値=一時PDFパスの配列（分割不要なら [元パス]）。
 * qpdf(ページ抽出) と pdfinfo(ページ数) を使用。
 */
function pdf_split_parts(string $path, int $maxBytes = 31457280, int $maxPages = 95): array {
    $size = (int)@filesize($path);
    $pages = 0;
    $info = @shell_exec('pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null');
    if ($info && preg_match('/^Pages:\s+(\d+)/m', $info, $m)) $pages = (int)$m[1];
    if ($size <= $maxBytes && ($pages === 0 || $pages <= $maxPages)) return [$path];   // 分割不要
    if ($pages < 2 || !trim((string)@shell_exec('command -v qpdf 2>/dev/null'))) return [$path];   // 分割不能はそのまま
    $n = max((int)ceil($size / $maxBytes), (int)ceil($pages / $maxPages), 1);
    $n = min($n, 12);   // パート数の上限
    $per = (int)ceil($pages / $n);
    $parts = [];
    for ($i = 0; $i < $n; $i++) {
        $a = $i * $per + 1; if ($a > $pages) break;
        $b = min(($i + 1) * $per, $pages);
        $out = tempnam(sys_get_temp_dir(), 'pdfpart_') . '.pdf';
        @shell_exec('qpdf ' . escapeshellarg($path) . ' --pages ' . escapeshellarg($path) . ' ' . $a . '-' . $b . ' -- ' . escapeshellarg($out) . ' 2>/dev/null');
        if (is_file($out) && filesize($out) > 0) $parts[] = $out; else @unlink($out);
    }
    return $parts ?: [$path];
}

/** OpenAI Files の内容(バイナリ)を取得。purpose=vision のファイルは取得可（画像編集の入力に使う）。 */
function openai_fetch_file_content(string $fid): ?string {
    global $CFG; $oa = $CFG['openai'];
    if ($fid === '') return null;
    $ch = curl_init(rtrim($oa['base_url'], '/') . '/files/' . rawurlencode($fid) . '/content');
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $oa['api_key']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code === 200 ? (string)$r : null;
}

/** アップロード済み画像(バイナリ)を /images/edits で編集。返り値 ['ok'=>bool,'url'=>,'bin'=>] 。 */
function chai_edit_image(string $bin, string $mime, string $prompt, ?string $size = null): array {
    global $CFG; $oa = $CFG['openai']; $ig = $CFG['image_gen'];
    $ext = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/webp' ? 'webp' : 'png');
    $tmp = tempnam(sys_get_temp_dir(), 'edit') . '.' . $ext; file_put_contents($tmp, $bin);
    $err = '編集失敗';
    foreach (chai_image_models() as $model) {
        $post = ['model' => $model, 'prompt' => $prompt, 'size' => $size ?: ($ig['size'] ?? '1024x1024'),
                 'image' => new CURLFile($tmp, $mime, 'image.' . $ext)];
        $ch = curl_init(rtrim($oa['base_url'], '/') . '/images/edits');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $oa['api_key']],
            CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 280]);
        $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = json_decode((string)$res, true);
        if ($code === 200 && !empty($j['data'][0])) {
            $row = $j['data'][0];
            $out = !empty($row['b64_json']) ? base64_decode($row['b64_json']) : (!empty($row['url']) ? @file_get_contents($row['url']) : null);
            if ($out) {
                @unlink($tmp);
                $fname = 'edit_' . bin2hex(random_bytes(8)) . '.png';
                $dir = __DIR__ . '/media'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
                file_put_contents($dir . '/' . $fname, $out);
                return ['ok' => true, 'url' => '/media/' . $fname, 'bin' => $out, 'model' => $model];
            }
        }
        $err = $j['error']['message'] ?? "編集失敗（{$code}）";
        error_log('[chai-img] edit model=' . $model . ' http=' . $code . ' -> fallback? err=' . mb_substr($err, 0, 80));
    }
    @unlink($tmp);
    return ['ok' => false, 'error' => $err];
}

/**
 * 内容ハッシュ(sha256)で重複排除しつつ OpenAI Files へアップロード。
 * 同一内容が過去に上がっていれば再アップロードせず既存 file_id を再利用する。
 * 返り値: ['file_id'=>..., 'hash'=>...] または null。
 */
function openai_upload_dedup(string $tmpPath, string $filename, string $mime, string $purpose = 'user_data'): ?array {
    $hash = hash_file('sha256', $tmpPath);
    if ($hash === false) return null;
    $st = db()->prepare("SELECT file_id FROM file_blobs WHERE hash = ?");
    $st->execute([$hash]);
    $fid = $st->fetchColumn();
    if ($fid) return ['file_id' => (string)$fid, 'hash' => $hash];   // 既存を再利用（アップロードしない）
    $fid = openai_upload_file($tmpPath, $filename, $mime, $purpose);
    if (!$fid) return null;
    db()->prepare(
        "INSERT INTO file_blobs (hash, file_id, size, mime, name) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE file_id = VALUES(file_id)"
    )->execute([$hash, $fid, (int)@filesize($tmpPath), mb_substr($mime, 0, 120), mb_substr($filename, 0, 255)]);
    return ['file_id' => $fid, 'hash' => $hash];
}

/**
 * 指定 file_id 群のうち、conv_files からの参照が無くなったものだけを
 * OpenAI Files と file_blobs から実削除する（参照カウント方式）。
 */
function release_file_refs(array $fileIds): void {
    foreach (array_unique(array_filter($fileIds)) as $fid) {
        $c = db()->prepare("SELECT COUNT(*) FROM conv_files WHERE file_id = ?");
        $c->execute([$fid]);
        if ((int)$c->fetchColumn() === 0) {
            openai_delete_file((string)$fid);
            db()->prepare("DELETE FROM file_blobs WHERE file_id = ?")->execute([$fid]);
        }
    }
}

/** OpenAI Files のファイルを削除（メッセージ削除時のクリーンアップ）。 */
function openai_delete_file(string $fileId): void {
    global $CFG; $oa = $CFG['openai'];
    if ($fileId === '') return;
    $ch = curl_init(rtrim($oa['base_url'], '/') . '/files/' . rawurlencode($fileId));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $oa['api_key']],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    ]);
    @curl_exec($ch); curl_close($ch);
}

/** code_interpreter コンテナ内のファイル内容(バイナリ)を取得。 */
function openai_fetch_container_file(string $containerId, string $fileId): ?string {
    global $CFG; $oa = $CFG['openai'];
    $ch = curl_init(rtrim($oa['base_url'], '/') . "/containers/{$containerId}/files/{$fileId}/content");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $oa['api_key']],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    ]);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($code === 200 && $r !== false && $r !== '') ? $r : null;
}

/** 会話内容を短いカテゴリ名に自動分類して保存。既存カテゴリを優先再利用。返り値=カテゴリ。 */
function classify_conversation(int $convId, string $email): ?string {
    global $CFG;
    $conv = conv_owned($convId, $email);
    if (!$conv) return null;

    $st = db()->prepare("SELECT role, content FROM messages WHERE conversation_id = ? AND role IN ('user','assistant') ORDER BY id ASC LIMIT 4");
    $st->execute([$convId]);
    $rows = $st->fetchAll();
    if (!$rows) return null;
    $snippet = '';
    foreach ($rows as $r) $snippet .= ($r['role'] === 'user' ? 'U: ' : 'A: ') . mb_substr(preg_replace('/\s+/u', ' ', (string)$r['content']), 0, 300) . "\n";

    $cs = db()->prepare("SELECT DISTINCT category FROM conversations WHERE email = ? AND category IS NOT NULL AND category <> '' ORDER BY updated_at DESC LIMIT 30");
    $cs->execute([$email]);
    $existing = array_values(array_filter(array_column($cs->fetchAll(), 'category')));

    $sys = 'あなたは会話を分類する係です。会話内容を表す簡潔なカテゴリ名を日本語で1つだけ返します。'
         . '規則: 6〜10文字程度の名詞句 / できるだけ既存カテゴリを再利用 / 記号や説明を付けずカテゴリ名だけ出力。';
    $user = ($existing ? '既存カテゴリ: ' . implode(' / ', $existing) . "\n\n" : '')
          . "会話:\n" . $snippet . "\nカテゴリ名:";

    $oa = $CFG['openai'];
    $payload = ['model' => 'gpt-4o-mini', 'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]], 'max_completion_tokens' => 20];
    $ch = curl_init(rtrim($oa['base_url'], '/') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $oa['api_key']],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$res, true);
    $cat = trim((string)($j['choices'][0]['message']['content'] ?? ''));
    $cat = preg_replace('/[\r\n].*$/s', '', $cat);
    // 前後の空白・引用符・記号を除去。trim() はバイト単位で全角文字を壊すため、Unicode対応の正規表現で行う
    $cat = preg_replace('/^[\s"\'。.：:・\-　]+|[\s"\'。.：:・\-　]+$/u', '', (string)$cat);
    $cat = mb_substr($cat, 0, 24);
    if ($cat === '') $cat = 'その他';
    db()->prepare("UPDATE conversations SET category = ? WHERE id = ? AND email = ?")->execute([$cat, $convId, $email]);
    return $cat;
}

/** 会話の所有者チェック付き取得。 */
function conv_owned(int $id, string $email): ?array {
    $st = db()->prepare("SELECT * FROM conversations WHERE id = ? AND email = ?");
    $st->execute([$id, $email]);
    return $st->fetch() ?: null;
}

/** 共有を含むアクセス判定。返り値 ['conv'=>row,'perm'=>'owner'|'write'|'read'] or null。 */
function conv_access(int $id, array $me): ?array {
    $st = db()->prepare("SELECT * FROM conversations WHERE id = ?");
    $st->execute([$id]);
    $conv = $st->fetch();
    if (!$conv) return null;
    if (strtolower((string)$conv['email']) === strtolower((string)$me['email'])) return ['conv' => $conv, 'perm' => 'owner'];
    $g = grantee_keys($me);
    $ph = implode(',', array_fill(0, count($g), '?'));
    $s = db()->prepare("SELECT permission FROM shares WHERE conversation_id = ? AND grantee IN ($ph) ORDER BY (permission='write') DESC LIMIT 1");
    $s->execute(array_merge([$id], $g));
    $perm = $s->fetchColumn();
    return $perm ? ['conv' => $conv, 'perm' => (string)$perm] : null;
}

/** 自分にマッチしうる grantee キー（email / nkmr username / 全員）。 */
function grantee_keys(array $me): array {
    return array_values(array_unique(array_filter([
        strtolower((string)($me['email'] ?? '')),
        strtolower((string)($me['user'] ?? '')),
        '*',
    ])));
}
