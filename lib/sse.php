<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\sse.php
 *   -> /var/www/chai/lib/sse.php
 *
 * 返事を少しずつ流すための SSE (Server-Sent Events)。
 * 画面側は data: の JSON の type で見分ける (event: 行は使っていない)。
 * 呼ぶのは chat.php。 手順はそちらを見ると 1 画面で分かる。
 */
declare(strict_types=1);

/** SSE を始める。 途中で詰まらないよう、 途中の緩衝をすべて外す。 */
function sse_begin(): void {
    while (ob_get_level() > 0) { ob_end_flush(); }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    @ini_set('zlib.output_compression', '0');
    ob_implicit_flush(true);
}

function sse(array $data): void {
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @ob_flush(); @flush();
}
function sse_error(string $msg): void { sse(['type' => 'error', 'message' => $msg]); exit; }
