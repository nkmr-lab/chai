<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\chat\conversation.php
 *   -> /var/www/chai/lib/chat/conversation.php
 *
 * 会話そのものの用意 (取得 / 作成 / 発言の保存 / 添付の紐づけ)。
 * 呼ぶのは chat.php。 手順はそちらを見ると 1 画面で分かる。
 */
declare(strict_types=1);

/** 会話を用意する。 既存なら書ける権利を確かめ、 無ければ作る。 やり直しなら前の返事を消す。 */
function chat_open_conversation(array $me, string $email, int $convId, bool $regen, string $model): array {
    if ($convId > 0) {
        $acc = conv_access($convId, $me);
        if (!$acc) sse_error('会話が見つかりません');
        if ($acc['perm'] === 'read') sse_error('このチャットは閲覧のみです（書き込み権限がありません）。');
        $conv = $acc['conv'];
    } elseif ($regen) {
        sse_error('やり直す会話がありません');
    } else {
        $st = db()->prepare("INSERT INTO conversations (email, title, model) VALUES (?, '新しいチャット', ?)");
        $st->execute([$email, $model]);
        $convId = (int)db()->lastInsertId();
        $conv = conv_owned($convId, $email);
    }

    if ($regen) {
        $luid = (int)db()->query("SELECT COALESCE(MAX(id),0) FROM messages WHERE conversation_id = " . (int)$convId . " AND role='user'")->fetchColumn();
        if (!$luid) sse_error('やり直す発話がありません');
        db()->prepare("DELETE FROM messages WHERE conversation_id = ? AND role='assistant' AND id > ?")->execute([$convId, $luid]);
        $firstExchange = false;
    } else {
        $firstExchange = ((int)db()->query("SELECT COUNT(*) FROM messages WHERE conversation_id = " . (int)$convId . " AND role='user'")->fetchColumn()) === 0;
    }

    return ['id' => $convId, 'conv' => $conv, 'firstExchange' => $firstExchange];
}

/** 今回の発言を残す。 添付があったことも本文の末尾に控える。 やり直しの時は何も足さない。 */
function chat_store_user_message(int $convId, string $email, array $me, string $content,
                                bool $regen, array $noteParts): ?int {
    if ($regen) return null;   // やり直しは、 前の発言をそのまま使うので何も足さない
    $stored = $content . ($noteParts ? "\n\n📎 " . implode('・', $noteParts) : '');
    db()->prepare("INSERT INTO messages (conversation_id, role, content, author_email, author_name) VALUES (?, 'user', ?, ?, ?)")
       ->execute([$convId, $stored, $email, (string)($me['name'] ?? '')]);
    return (int)db()->lastInsertId();
}

/** 上げた添付を会話に紐づける (毎ターン再添付するため)。 */
function chat_persist_files(int $convId, ?int $userMsgId, array $img, array $doc, array $data): void {
    $dataFileIds = $data['ids']; $dataFileNames = $data['names']; $dataHashes = $data['hashes'];
    $docFileIds  = $doc['ids'];  $docFileNames  = $doc['names'];  $docHashes  = $doc['hashes'];
    $imgFileIds  = $img['ids'];  $imgUrls       = $img['urls'];   $imgHashes  = $img['hashes'];
    foreach ($dataFileIds as $k => $fid) {
        db()->prepare("INSERT INTO conv_files (conversation_id, message_id, file_id, name, kind, hash) VALUES (?, ?, ?, ?, 'data', ?)")
           ->execute([$convId, $userMsgId, $fid, $dataFileNames[$k] ?? 'data', $dataHashes[$k] ?? null]);
    }
    foreach ($docFileIds as $k => $fid) {
        db()->prepare("INSERT INTO conv_files (conversation_id, message_id, file_id, name, kind, hash) VALUES (?, ?, ?, ?, 'doc', ?)")
           ->execute([$convId, $userMsgId, $fid, $docFileNames[$k] ?? 'document.pdf', $docHashes[$k] ?? null]);
    }
    foreach ($imgFileIds as $k => $fid) {
        db()->prepare("INSERT INTO conv_files (conversation_id, message_id, file_id, name, kind, hash) VALUES (?, ?, ?, ?, 'image', ?)")
           ->execute([$convId, $userMsgId, $fid, $imgUrls[$k] ?? 'image', $imgHashes[$k] ?? null]);
    }
}

/**
 * 会話に紐づく添付を集め直して、 モデルへの言い添え (dataInstr) を作る。
 * 返り値: dataIds / dataNames / docIds / docNames / imgIds / imgByMsg / instr
 */
function chat_collect_files(int $convId, array $tc): array {
    $cf = db()->prepare("SELECT file_id, name, kind, message_id FROM conv_files WHERE conversation_id = ? ORDER BY id ASC LIMIT 80");
    $cf->execute([$convId]);
    $dataFileIds = $dataFileNames = $docFileIds = $docFileNames = $imgFileIds = [];
    $imgByMsg = [];   // message_id => [file_id]（発言ごとに正しく画像を添付するため）
    foreach ($cf->fetchAll() as $r) {
        $k = $r['kind'] ?? 'data';
        if ($k === 'doc') { $docFileIds[] = $r['file_id']; $docFileNames[] = $r['name']; }
        elseif ($k === 'image') { $imgFileIds[] = $r['file_id']; if ($r['message_id']) $imgByMsg[(int)$r['message_id']][] = $r['file_id']; }
        else { $dataFileIds[] = $r['file_id']; $dataFileNames[] = $r['name']; }
    }
    $dataFileIds = array_values(array_unique($dataFileIds));
    $docFileIds  = array_values(array_unique($docFileIds));
    $imgFileIds  = array_values(array_unique($imgFileIds));   // 編集ツールの対象（最新画像）判定に使用
    if (empty($tc['allow_data_analysis'])) { $dataFileIds = []; $dataFileNames = []; }   // CSV/xlsx分析は会員のみ
    $dataInstr = '';
    if ($dataFileIds) {
        $dataInstr = '（この会話にはデータファイル（' . implode(', ', $dataFileNames) . '）が code_interpreter にアップ済みです。分析要求時は必ず code_interpreter で読み込み、読めない場合は encoding(utf-8/cp932/shift_jis)や区切り文字を変えて再試行。グラフは一意名で1回だけ savefig（plt.show や二重保存はしない）。「再アップロードして」とは言わないこと。）';
    }
    if ($docFileIds) {
        $dataInstr .= '（この会話には添付PDF（' . implode(', ', $docFileNames) . '）が input_file として渡されています。要約・査読・引用・図表や実験条件の確認は、必ずそのPDF本文を直接参照して回答すること。以前の要約だけに頼らず原文を読むこと。「本文を参照できない」とは言わないこと。）';
    }
    return ['dataIds' => $dataFileIds, 'dataNames' => $dataFileNames,
            'docIds' => $docFileIds, 'docNames' => $docFileNames,
            'imgIds' => $imgFileIds, 'imgByMsg' => $imgByMsg, 'instr' => $dataInstr];
}
