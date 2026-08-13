<?php
/**
 * db.php — 設定ロード + PDO 接続（単一ソース）。
 * どのエンドポイントも最初に require し、$CFG と db() を使う。
 */
declare(strict_types=1);

$__cfgPath = __DIR__ . '/config.local.php';
if (!is_file($__cfgPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'config_missing', 'hint' => 'config.local.php を作成してください'], JSON_UNESCAPED_UNICODE);
    exit;
}
/** @var array $CFG */
$CFG = require $__cfgPath;

/** PDO を一度だけ生成して返す。 */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    global $CFG;
    $d = $CFG['db'];
    $pdo = new PDO($d['dsn'], $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET time_zone = '+09:00'");
    return $pdo;
}
