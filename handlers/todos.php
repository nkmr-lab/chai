<?php
/**
 * C:\Dropbox\Programs\Claude\chai\handlers\todos.php
 *   -> /var/www/chai/handlers/todos.php
 *
 * TODO。
 * どの action がどれを呼ぶかは api.php の表を見る。
 * どれも最後は json_out() で返して終わる (json_out は exit する)。
 */
declare(strict_types=1);

// TODO 一覧（未完 todos ＋ 完了 done）＝各 [{id, due}]
function act_todos(array $me, string $email): void {
        $st = db()->prepare("SELECT conversation_id, due, done FROM todos WHERE email = ? ORDER BY (due IS NULL), due ASC, created_at DESC");
        $st->execute([$email]);
        $active = []; $done = [];
        foreach ($st->fetchAll() as $r) {
            $row = ['id' => (int)$r['conversation_id'], 'due' => $r['due']];
            if ((int)$r['done']) $done[] = $row; else $active[] = $row;
        }
        json_out(['todos' => $active, 'done' => $done]);
}

// TODO 追加/更新（due・done を渡された分だけ更新。アクセス可の会話のみ）
function act_todo_set(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $id = (int)($b['conversation_id'] ?? 0);
        if (!conv_access($id, $me)) json_out(['error' => 'forbidden'], 403);
        db()->prepare("INSERT IGNORE INTO todos (email, conversation_id) VALUES (?, ?)")->execute([$email, $id]);
        if (array_key_exists('due', $b)) {
            $due = trim((string)($b['due'] ?? ''));
            $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;   // 妥当な日付のみ、他はクリア
            db()->prepare("UPDATE todos SET due = ? WHERE email = ? AND conversation_id = ?")->execute([$due, $email, $id]);
        }
        if (array_key_exists('done', $b)) {
            db()->prepare("UPDATE todos SET done = ? WHERE email = ? AND conversation_id = ?")->execute([(int)!!$b['done'], $email, $id]);
        }
        json_out(['ok' => true]);
}

// TODO 削除
function act_todo_remove(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        db()->prepare("DELETE FROM todos WHERE email = ? AND conversation_id = ?")->execute([$email, (int)($b['conversation_id'] ?? 0)]);
        json_out(['ok' => true]);
}
