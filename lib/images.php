<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\images.php
 *   -> /var/www/chai/lib/images.php
 *
 * 画像の生成と手入れ、 そして保存。
 * まとめて読み込むのは lib.php。 個別に require しない。
 */
declare(strict_types=1);

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
                $dir = CHAI_ROOT . '/media'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
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
                $dir = CHAI_ROOT . '/media'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
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
