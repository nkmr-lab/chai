<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\http.php
 *   -> /var/www/chai/lib/http.php
 *
 * 入出力と身元。 JSON で返す / ログインを確かめる / 本文を読む。
 * まとめて読み込むのは lib.php。 個別に require しない。
 */
declare(strict_types=1);

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
