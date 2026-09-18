<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\tier.php
 *   -> /var/www/chai/lib/tier.php
 *
 * ティア判定。 契約の真実の源は LabPay で、 chai は照合してキャッシュするだけ。
 * まとめて読み込むのは lib.php。 個別に require しない。
 */
declare(strict_types=1);

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
