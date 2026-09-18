<?php
/**
 * C:\Dropbox\Programs\Claude\chai\handlers\stocks.php
 *   -> /var/www/chai/handlers/stocks.php
 *
 * ストック (切り抜き保存)。
 * どの action がどれを呼ぶかは api.php の表を見る。
 * どれも最後は json_out() で返して終わる (json_out は exit する)。
 */
declare(strict_types=1);

// ストック一覧
function act_stocks(array $me, string $email): void {
        $st = db()->prepare("SELECT id, conversation_id, text, created_at FROM stocks WHERE email = ? ORDER BY created_at DESC LIMIT 500");
        $st->execute([$email]);
        json_out(['stocks' => $st->fetchAll()]);
}

// ストック保存（返信まるごと or 選択部分）
function act_stock_save(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $text = trim((string)($b['text'] ?? ''));
        if ($text === '') json_out(['error' => 'empty'], 400);
        $text = mb_substr($text, 0, 100000);
        $cid = isset($b['conversation_id']) && $b['conversation_id'] ? (int)$b['conversation_id'] : null;
        $mid = isset($b['source_msg_id']) && $b['source_msg_id'] ? (int)$b['source_msg_id'] : null;
        db()->prepare("INSERT INTO stocks (email, conversation_id, source_msg_id, text) VALUES (?, ?, ?, ?)")
           ->execute([$email, $cid, $mid, $text]);
        json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
}

// ストック削除
function act_stock_delete(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        db()->prepare("DELETE FROM stocks WHERE id = ? AND email = ?")->execute([(int)($b['id'] ?? 0), $email]);
        json_out(['ok' => true]);
}

// ストック全消し
function act_stock_clear(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        db()->prepare("DELETE FROM stocks WHERE email = ?")->execute([$email]);
        json_out(['ok' => true]);
}
