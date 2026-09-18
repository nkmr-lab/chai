<?php
/**
 * C:\Dropbox\Programs\Claude\chai\handlers\bookmarks.php
 *   -> /var/www/chai/handlers/bookmarks.php
 *
 * あとで見る。
 * どの action がどれを呼ぶかは api.php の表を見る。
 * どれも最後は json_out() で返して終わる (json_out は exit する)。
 */
declare(strict_types=1);

// ブックマーク（あとで見る）一覧＝会話idの配列
function act_bookmarks(array $me, string $email): void {
        $st = db()->prepare("SELECT conversation_id FROM bookmarks WHERE email = ? ORDER BY created_at DESC");
        $st->execute([$email]);
        json_out(['ids' => array_map('intval', array_column($st->fetchAll(), 'conversation_id'))]);
}

// ブックマーク追加（アクセスできる会話のみ）
function act_bookmark_add(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $id = (int)($b['conversation_id'] ?? 0);
        if (!conv_access($id, $me)) json_out(['error' => 'forbidden'], 403);
        db()->prepare("INSERT IGNORE INTO bookmarks (email, conversation_id) VALUES (?, ?)")->execute([$email, $id]);
        json_out(['ok' => true]);
}

// ブックマーク削除
function act_bookmark_remove(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        db()->prepare("DELETE FROM bookmarks WHERE email = ? AND conversation_id = ?")->execute([$email, (int)($b['conversation_id'] ?? 0)]);
        json_out(['ok' => true]);
}
