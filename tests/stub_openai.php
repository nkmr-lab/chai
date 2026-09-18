<?php
/**
 * C:\Dropbox\Programs\Claude\chai\tests\stub_openai.php
 *   → /var/www/chai/tests/stub_openai.php
 *
 * テスト用の偽 OpenAI。 php -S 127.0.0.1:8898 tests/stub_openai.php で立てる。
 * 本物には 1 回も繋がずに、 chat.php の SSE 中継や分類を通しで試すためのもの。
 *
 * 大事なのは「本物と同じそっけなさ」で返すこと。 親切すぎる偽物を作ると、
 * 本物なら落ちる書き方が素通りしてしまう。
 */
declare(strict_types=1);

$path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?: [];

function j(array $x, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($x, JSON_UNESCAPED_UNICODE);
}

// ── Responses API (ストリーミング) ───────────────────────
if ($path === '/v1/responses') {
    // 本物と同じく、 stream=true の時だけ SSE。 そうでなければ JSON。
    if (empty($body['stream'])) {
        j(['id' => 'resp_stub', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'ok']]]]]);
        exit;
    }
    header('Content-Type: text/event-stream');
    $send = function (string $event, array $data) {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    };
    $send('response.created', ['type' => 'response.created', 'response' => ['id' => 'resp_stub']]);

    // 「画像」と頼まれた時は、 本物と同じように道具呼び出し (function_call) を返す。
    // これで chat.php の 画像生成 → media/ 保存 → markdown 追記 の道が通る。
    $asked = json_encode($body['input'] ?? [], JSON_UNESCAPED_UNICODE);
    if (strpos($asked, '画像') !== false) {
        $send('response.output_item.added', ['type' => 'response.output_item.added',
            'item' => ['type' => 'function_call', 'name' => 'generate_image']]);
        $send('response.function_call_arguments.done', ['type' => 'response.function_call_arguments.done',
            'arguments' => json_encode(['prompt' => 'a small test image'])]);
        $send('response.completed', ['type' => 'response.completed', 'response' => ['id' => 'resp_stub']]);
        echo "data: [DONE]" . PHP_EOL . PHP_EOL;
        exit;
    }
    foreach (['こんにちは', '。テスト', 'の返事です。'] as $piece) {
        $send('response.output_text.delta', ['type' => 'response.output_text.delta', 'delta' => $piece]);
    }
    $send('response.completed', ['type' => 'response.completed', 'response' => [
        'id' => 'resp_stub',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'こんにちは。テストの返事です。']]]],
    ]]);
    echo "data: [DONE]\n\n";
    exit;
}

// ── 分類などで使う chat/completions ──────────────────────
if ($path === '/v1/chat/completions') {
    j(['choices' => [['message' => ['content' => '雑談']]]]);
    exit;
}

// ── ファイル ─────────────────────────────────────────────
if ($path === '/v1/files' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    j(['id' => 'file-stub' . substr(md5((string)microtime(true)), 0, 8), 'bytes' => 1, 'filename' => 'x']);
    exit;
}
if (preg_match('#^/v1/files/([^/]+)/content$#', $path)) { echo 'stub file content'; exit; }
if (preg_match('#^/v1/files/([^/]+)$#', $path))         { j(['deleted' => true]); exit; }
if (preg_match('#^/v1/containers/[^/]+/files/[^/]+/content$#', $path)) { echo 'stub container file'; exit; }

// ── 画像 ─────────────────────────────────────────────────
if ($path === '/v1/images/generations' || $path === '/v1/images/edits') {
    // 1x1 の PNG
    j(['data' => [['b64_json' => base64_encode(base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ))]]]);
    exit;
}

j(['error' => ['message' => '偽サーバに ' . $path . ' は無い']], 404);
