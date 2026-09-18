<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\chat\stream.php
 *   -> /var/www/chai/lib/chat/stream.php
 *
 * OpenAI の返事を受けながら、 そのまま画面へ流す。
 * 受け取りつつ溜めたものを返す (本文 / 道具の呼び出し / 図表のファイル / 打ち切りの有無)。
 * 呼ぶのは chat.php。 手順はそちらを見ると 1 画面で分かる。
 */
declare(strict_types=1);

function chat_stream(array $oa, array $payload): array {
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

    return compact('assistant', 'toolName', 'toolArgs', 'ciFiles', 'hadText',
                       'incompleteFlag', 'apiErr', 'code', 'err');
}
