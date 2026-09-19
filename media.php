<?php
/**
 * C:\Dropbox\Programs\Claude\chai\media.php
 *   -> /var/www/chai/media.php
 *
 * media/ の中身 (生成画像、 データ分析が書き出した図表や CSV) を**ログインした人にだけ**渡す。
 *
 * 以前は Apache が素のまま配っていたので、 URL さえ知っていれば誰でも取れた。
 * 名前は当てにくい乱数だが、 分析の出力には研究データが入りうるので、 当てにくさに頼らない。
 *
 * vhost 側で /media/<名前> をここに回している。 実体は readfile で渡す。
 * (X-Sendfile の方が速いが、 設定を 1 行間違えると「中身が空の 200」になって
 *  気づきにくい。 ここで扱うのは数百 KB の画像や CSV なので素直に読んで渡す。)
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

if (!chai_identity()) {                      // 未ログインは auth.nkmr.io へ
    // 戻り先は Host ヘッダから作らない (偽の Host を送られると別所へ誘導する踏み台になる)
    $self = 'https://chai.nkmr.io' . ($_SERVER['REQUEST_URI'] ?? '/');
    if (!headers_sent()) {
        header('Location: ' . NKMRAUTH_URL . '/?action=sso&return=' . urlencode($self));
    }
    exit;
}

$name = (string)($_GET['f'] ?? '');
// 名前は「英数と . _ -」だけ。 / や .. は受けない (ディレクトリを遡られないように)
if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name) || str_contains($name, '..')) {
    http_response_code(400);
    exit('bad name');
}

$dir  = __DIR__ . '/media';
$path = $dir . '/' . $name;
$real = realpath($path);
if ($real === false || strncmp($real, realpath($dir) . DIRECTORY_SEPARATOR, strlen(realpath($dir)) + 1) !== 0 || !is_file($real)) {
    http_response_code(404);
    exit('not found');
}

$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$types = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
    'csv' => 'text/csv; charset=utf-8', 'txt' => 'text/plain; charset=utf-8',
    'json' => 'application/json', 'pdf' => 'application/pdf',
];
$type = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $type);
header('Cache-Control: private, max-age=86400');
// 画像以外は「開く」より「保存」に寄せる (svg を開かせて中の script を動かさない)
if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
    header('Content-Disposition: attachment; filename="' . $name . '"');
}
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($real));
readfile($real);
