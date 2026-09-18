<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\chat\finish.php
 *   -> /var/www/chai/lib/chat/finish.php
 *
 * 返事を受け取った後の後始末 (画像の生成・編集、 図表の取り込み、 保存と締め)。
 * 呼ぶのは chat.php。 手順はそちらを見ると 1 画面で分かる。
 */
declare(strict_types=1);

/**
 * 画像の生成 / 編集の道具が呼ばれていたら、 ここで実際に作って本文に足す。
 * 返り値: [本文, 何か目に見えるものを出したか]
 */
function chat_run_image_tools(array $st, array $files, int $convId, ?int $userMsgId,
                              string $email, bool $genUnlimited): array {
    $assistant = $st['assistant']; $toolName = $st['toolName']; $toolArgs = $st['toolArgs'];
    $hadText = $st['hadText']; $imgFileIds = $files['imgIds'];
    $producedMedia = false;
    // 画像生成ツールが呼ばれていれば生成して追記
    if ($toolName === 'generate_image' && trim($toolArgs) !== '') {
        $args = json_decode($toolArgs, true);
        $p = is_array($args) ? trim((string)($args['prompt'] ?? '')) : '';
        if ($p !== '') {
            sse(['type' => 'status', 'text' => '🎨 画像を生成しています…']);
            $g = chai_generate_image($p);
            if ($g['ok']) {
                if (!$genUnlimited) usage_event($email, 'imggen');  // お試し枠を1消費
                if (!empty($g['bin'])) persist_image_bin($convId, $userMsgId, $g['bin']);   // 生成画像も保存＝続けて編集できる
                $alt = mb_substr(str_replace([']', "\n"], ' ', $p), 0, 60);
                // 全文プロンプトをマーカーで埋め込み、フロントは既定では小さな「🎨 プロンプト」トグルだけ表示
                $cap = $hadText ? '' : '<<<IMGPROMPT>>>' . str_replace(["\n", "\r"], ' ', $p) . '<<<ENDIMGPROMPT>>>' . "\n\n";
                $imgmd = ($assistant !== '' ? "\n\n" : '') . $cap . '![' . $alt . '](' . $g['url'] . ')';
                $assistant .= $imgmd;
                sse(['type' => 'image', 'markdown' => $imgmd]);
                $producedMedia = true;
            } else {
                $emsg = ($assistant !== '' ? "\n\n" : '') . '（画像生成に失敗しました：' . $g['error'] . '）';
                $assistant .= $emsg;
                sse(['type' => 'image', 'markdown' => $emsg]);
                $producedMedia = true;   // 失敗メッセージは出したので無言注記は不要
            }
        }
    }

    // 画像編集ツール: アップロード済み/生成済みの最新画像を /images/edits で加工
    if ($toolName === 'edit_image' && trim($toolArgs) !== '' && $imgFileIds) {
        $args = json_decode($toolArgs, true);
        $p = is_array($args) ? trim((string)($args['prompt'] ?? '')) : '';
        $targetFid = (string)end($imgFileIds);   // 直近の画像を対象
        if ($p !== '' && $targetFid !== '') {
            sse(['type' => 'status', 'text' => '🎨 画像を編集しています…']);
            $srcBin = openai_fetch_file_content($targetFid);
            $g = $srcBin ? chai_edit_image($srcBin, 'image/png', $p) : ['ok' => false, 'error' => '元画像を取得できませんでした'];
            if ($g['ok']) {
                if (!$genUnlimited) usage_event($email, 'imggen');
                if (!empty($g['bin'])) persist_image_bin($convId, $userMsgId, $g['bin']);   // 編集結果も保存＝さらに編集できる
                $alt = mb_substr(str_replace([']', "\n"], ' ', $p), 0, 60);
                $cap = $hadText ? '' : '<<<IMGPROMPT>>>' . str_replace(["\n", "\r"], ' ', $p) . '<<<ENDIMGPROMPT>>>' . "\n\n";
                $imgmd = ($assistant !== '' ? "\n\n" : '') . $cap . '![' . $alt . '](' . $g['url'] . ')';
                $assistant .= $imgmd;
                sse(['type' => 'image', 'markdown' => $imgmd]);
                $producedMedia = true;
            } else {
                $emsg = ($assistant !== '' ? "\n\n" : '') . '（画像編集に失敗しました：' . $g['error'] . '）';
                $assistant .= $emsg;
                sse(['type' => 'image', 'markdown' => $emsg]);
                $producedMedia = true;
            }
        }
    }
    return [$assistant, $producedMedia];
}

/** code_interpreter が作った図表を取り込んで本文に足す。 返り値は上と同じ形。 */
function chat_attach_ci_files(array $ciFiles, string $assistant, bool $producedMedia): array {
    // code_interpreter が生成したファイル（グラフ等）を取得して表示。
    // 無名の自動表示画像(filename==file_id)は savefig の名前付きと重複するので、名前付きを優先。
    $named = []; $unnamed = [];
    foreach ($ciFiles as $f) {
        $fn = (string)($f['filename'] ?? '');
        if ($fn === '' || strncmp($fn, (string)$f['file_id'], strlen((string)$f['file_id'])) === 0 || strncmp($fn, 'cfile_', 6) === 0) $unnamed[] = $f;
        else $named[] = $f;
    }
    foreach (($named ?: $unnamed) as $f) {
        if (empty($f['container_id'])) continue;
        $bin = openai_fetch_container_file($f['container_id'], $f['file_id']);
        if (!$bin) continue;
        $ext   = strtolower(preg_replace('/[^a-z0-9]/i', '', pathinfo($f['filename'], PATHINFO_EXTENSION) ?: 'png')) ?: 'bin';
        $fname = 'ci_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dir   = __DIR__ . '/media'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        file_put_contents($dir . '/' . $fname, $bin);
        $url   = '/media/' . $fname;
        $label = str_replace([']', "\n"], ' ', $f['filename']);
        $md    = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)
            ? "\n\n![" . $label . "](" . $url . ")"
            : "\n\n[📄 " . $label . "](" . $url . ")";
        $assistant .= $md;
        sse(['type' => 'image', 'markdown' => $md]);
        $producedMedia = true;
    }
    return [$assistant, $producedMedia];
}

/** 返事を残し、 題名を決めて、 締めの合図を流す。 */
function chat_finish(int $convId, array $conv, string $model, string $assistant, string $email,
                     bool $firstExchange, string $content, array $noteParts): void {
    db()->prepare("INSERT INTO messages (conversation_id, role, content, model) VALUES (?, 'assistant', ?, ?)")
       ->execute([$convId, $assistant, $model]);
    $asstId = (int)db()->lastInsertId();

    usage_event($email, 'msg');
    db()->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")->execute([$convId]);

    $title = $conv['title'];
    if ($firstExchange) {
        $seed = $content !== '' ? $content : (!empty($noteParts) ? implode('・', $noteParts) . 'について' : '新しいチャット');
        $title = mb_substr(preg_replace('/\s+/u', ' ', $seed), 0, 40);
        if ($title === '') $title = '新しいチャット';
        db()->prepare("UPDATE conversations SET title = ? WHERE id = ?")->execute([$title, $convId]);
    }

    sse(['type' => 'done', 'conversation_id' => $convId, 'title' => $title, 'model' => $model, 'msg_id' => $asstId]);
}
