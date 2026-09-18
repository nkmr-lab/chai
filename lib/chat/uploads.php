<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\chat\uploads.php
 *   -> /var/www/chai/lib/chat/uploads.php
 *
 * 添付 (画像 / PDF / データ) を OpenAI のファイル置き場へ上げる。
 * 呼ぶのは chat.php。 手順はそちらを見ると 1 画面で分かる。
 */
declare(strict_types=1);

/** 画像を上げる。 表示用は media/ に、 モデル用は OpenAI Files(vision) に置く。 */
function chat_upload_images(array $images, bool $visionOK, array $U): array {
    $U = $GLOBALS['CFG']['upload'] ?? [];
    $maxImg = (int)($U['max_image_mb'] ?? 8) * 1024 * 1024;
    $images = array_values(array_filter($images, fn($u) => is_string($u) && $u !== '' && strlen($u) <= $maxImg * 1.4));

    // 画像 → OpenAI Files(purpose=vision) へアップロードして file_id で永続化
    $imgFileIds = [];
    $imgHashes = [];
    $imgUrls = [];   // 表示用（/media）URL（アップロード画像をリロード後も表示するため）
    if ($images && $visionOK) {
        foreach (array_slice($images, 0, 4) as $durl) {
            $mime = 'image/png'; $raw = $durl;
            if (preg_match('#^data:([a-z0-9.+/-]+);base64,#i', $durl, $mm)) $mime = strtolower($mm[1]);
            if (($pp = strpos($durl, 'base64,')) !== false) $raw = substr($durl, $pp + 7);
            $bin = base64_decode($raw, true);
            if ($bin === false || $bin === '' || strlen($bin) > $maxImg) continue;
            $ext = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/webp' ? 'webp' : ($mime === 'image/gif' ? 'gif' : 'png'));
            // 表示用に /media へ保存
            $mfn = 'up_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $mdir = __DIR__ . '/media'; if (!is_dir($mdir)) @mkdir($mdir, 0775, true);
            file_put_contents($mdir . '/' . $mfn, $bin);
            // モデル用に OpenAI Files(vision) へ
            $tmp = tempnam(sys_get_temp_dir(), 'chaiimg_') . '.' . $ext; file_put_contents($tmp, $bin);
            $up = openai_upload_dedup($tmp, 'image.' . $ext, $mime, 'vision');
            @unlink($tmp);
            if ($up) { $imgFileIds[] = $up['file_id']; $imgHashes[] = $up['hash']; $imgUrls[] = '/media/' . $mfn; }
        }
    }
    return ['ids' => $imgFileIds, 'hashes' => $imgHashes, 'urls' => $imgUrls, 'images' => $images];
}

/** PDF を上げる。 大きいものはページで分ける。 全部失敗したら、 黙って続けずその場で終える。 */
function chat_upload_pdfs(array $pdfs, bool $allowFiles, array $U): array {
    $docFileIds = [];
    $docFileNames = [];
    $docHashes = [];
    $pdfFailed = 0;
    if ($pdfs && $allowFiles) {
        sse(['type' => 'status', 'text' => '📄 PDFを読み込んでいます…']);
        $maxPdf = (int)($U['max_pdf_mb'] ?? 15) * 1024 * 1024;
        foreach (array_slice($pdfs, 0, 4) as $pf) {
            $name = basename((string)($pf['name'] ?? 'document.pdf'));
            $raw  = (string)($pf['data'] ?? '');
            if (($pp = strpos($raw, 'base64,')) !== false) $raw = substr($raw, $pp + 7);
            $bin = base64_decode($raw, true);
            if ($bin === false || $bin === '' || strlen($bin) > $maxPdf) { $pdfFailed++; continue; }
            $tmp = tempnam(sys_get_temp_dir(), 'chaipdf_') . '.pdf';
            file_put_contents($tmp, $bin);
            // OpenAIの読取上限(約32MB/100ページ)を超える大きいPDFはページ範囲で分割
            $parts = pdf_split_parts($tmp);
            $np = count($parts);
            if ($np > 1) sse(['type' => 'status', 'text' => "📄 大きいPDFを{$np}分割して読み込んでいます…"]);
            $okThis = 0;
            foreach ($parts as $pi => $ppath) {
                $pname = $np > 1 ? ($name . ' (' . ($pi + 1) . '/' . $np . ')') : $name;
                $up = openai_upload_dedup($ppath, $pname, 'application/pdf');   // 同一内容は再利用
                if ($ppath !== $tmp) @unlink($ppath);
                if ($up) { $docFileIds[] = $up['file_id']; $docFileNames[] = $pname; $docHashes[] = $up['hash']; $okThis++; }
            }
            @unlink($tmp);
            if (!$okThis) $pdfFailed++;
        }
        // 全部失敗＝本文が届かないので、モデルに丸投げせず明確に知らせて終了
        if (!$docFileIds && $pdfFailed) sse_error('PDFの読み込みに失敗しました（サイズが大きすぎるか、破損している可能性があります）。もう一度アップロードしてください。');
    }
    return ['ids' => $docFileIds, 'names' => $docFileNames, 'hashes' => $docHashes];
}

/** CSV/Excel を上げる (メンバー限定)。 分析は code_interpreter が読む。 */
function chat_upload_datafiles(array $datafiles, array $tc, array $U): array {
    $dataFileIds = [];
    $dataFileNames = [];
    $dataHashes = [];
    if ($datafiles && empty($tc['allow_data_analysis'])) {
        sse(['type' => 'status', 'text' => '📊 CSV/Excel のデータ分析はメンバー限定です（LabPayで契約すると使えます）']);
    } elseif ($datafiles) {
        sse(['type' => 'status', 'text' => '📊 データを読み込んでいます…']);
        $maxData = (int)($U['max_data_mb'] ?? 20) * 1024 * 1024;
        foreach (array_slice($datafiles, 0, 4) as $df) {
            $name = basename((string)($df['name'] ?? 'data.csv'));
            $raw  = (string)($df['data'] ?? '');
            if (($pp = strpos($raw, 'base64,')) !== false) $raw = substr($raw, $pp + 7);
            $bin = base64_decode($raw, true);
            if ($bin === false || $bin === '' || strlen($bin) > $maxData) continue;
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'csv');
            $mime = $ext === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                  : ($ext === 'xls' ? 'application/vnd.ms-excel' : 'text/csv');
            $tmp = tempnam(sys_get_temp_dir(), 'chaidata_') . '.' . $ext;
            file_put_contents($tmp, $bin);
            $up = openai_upload_dedup($tmp, $name, $mime);   // 同一内容は再利用
            @unlink($tmp);
            if ($up) { $dataFileIds[] = $up['file_id']; $dataFileNames[] = $name; $dataHashes[] = $up['hash']; }
        }
    }
    if ($datafiles) error_log('[chai] datafiles=' . count($datafiles) . ' uploaded=' . count($dataFileIds));
    return ['ids' => $dataFileIds, 'names' => $dataFileNames, 'hashes' => $dataHashes];
}
