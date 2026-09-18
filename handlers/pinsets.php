<?php
/**
 * C:\Dropbox\Programs\Claude\chai\handlers\pinsets.php
 *   -> /var/www/chai/handlers/pinsets.php
 *
 * ピンセット (横に並べる会話の組)。
 * どの action がどれを呼ぶかは api.php の表を見る。
 * どれも最後は json_out() で返して終わる (json_out は exit する)。
 */
declare(strict_types=1);

// ピンセット一覧
function act_pinsets(array $me, string $email): void {
        $st = db()->prepare("SELECT id, name, chat_ids FROM pin_sets WHERE email = ? ORDER BY sort_order ASC, id ASC");
        $st->execute([$email]);
        $out = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'chat_ids' => json_decode($r['chat_ids'], true) ?: []], $st->fetchAll());
        json_out(['pinsets' => $out]);
}

// ピンセット保存（同名は上書き。新規は末尾に並べる）
function act_pinset_save(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 120);
        $ids  = array_values(array_unique(array_filter(array_map('intval', (array)($b['chat_ids'] ?? [])), fn($x) => $x > 0)));
        if ($name === '') json_out(['error' => 'empty_name'], 400);
        if (!$ids) json_out(['error' => 'empty_set'], 400);
        $next = (int)db()->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM pin_sets WHERE email=" . db()->quote($email))->fetchColumn();
        db()->prepare(
            "INSERT INTO pin_sets (email, name, chat_ids, sort_order) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE chat_ids = VALUES(chat_ids), updated_at = NOW()"
        )->execute([$email, $name, json_encode($ids), $next]);
        json_out(['ok' => true]);
}

// ピンセット並び替え（idの配列順に sort_order を振り直す）
function act_pinset_reorder(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $ids = array_values(array_filter(array_map('intval', (array)($b['ids'] ?? [])), fn($x) => $x > 0));
        $up = db()->prepare("UPDATE pin_sets SET sort_order = ? WHERE id = ? AND email = ?");
        foreach ($ids as $i => $id) $up->execute([$i, $id, $email]);
        json_out(['ok' => true]);
}

// ピンセット削除
function act_pinset_delete(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        db()->prepare("DELETE FROM pin_sets WHERE id = ? AND email = ?")->execute([(int)($b['id'] ?? 0), $email]);
        json_out(['ok' => true]);
}
