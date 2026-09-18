<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\openai_files.php
 *   -> /var/www/chai/lib/openai_files.php
 *
 * OpenAI のファイル置き場とのやりとり (上げる / 取る / 消す)。
 * まとめて読み込むのは lib.php。 個別に require しない。
 */
declare(strict_types=1);

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
