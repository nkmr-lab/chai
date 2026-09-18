<?php
/**
 * C:\Dropbox\Programs\Claude\chai\handlers\messages.php
 *   -> /var/www/chai/handlers/messages.php
 *
 * 発言の削除。
 * どの action がどれを呼ぶかは api.php の表を見る。
 * どれも最後は json_out() で返して終わる (json_out は exit する)。
 */
declare(strict_types=1);

// メッセージ削除（誤った発話/アップロードを履歴・ファイルごと消す）
function act_message_delete(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $mid = (int)($b['id'] ?? 0);
        $st = db()->prepare(
            "SELECT m.author_email, c.email AS owner FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE m.id = ?"
        );
        $st->execute([$mid]);
        $row = $st->fetch();
        if (!$row) json_out(['error' => 'not_found'], 404);
        $isOwner  = strtolower((string)$row['owner']) === strtolower($email);
        $isAuthor = !empty($row['author_email']) && strtolower((string)$row['author_email']) === strtolower($email);
        if (!$isOwner && !$isAuthor) json_out(['error' => 'forbidden'], 403);
        // 紐づくファイルを切り離す。ただし他の会話で同一ファイルを参照していれば実削除しない（重複排除）
        $fs = db()->prepare("SELECT file_id FROM conv_files WHERE message_id = ?");
        $fs->execute([$mid]);
        $fids = array_map(fn($f) => (string)$f['file_id'], $fs->fetchAll());
        db()->prepare("DELETE FROM conv_files WHERE message_id = ?")->execute([$mid]);
        release_file_refs($fids);   // 参照が0になったものだけ OpenAI/file_blobs から削除
        db()->prepare("DELETE FROM messages WHERE id = ?")->execute([$mid]);
        json_out(['ok' => true]);
}
