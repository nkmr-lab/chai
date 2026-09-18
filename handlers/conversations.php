<?php
/**
 * C:\Dropbox\Programs\Claude\chai\handlers\conversations.php
 *   -> /var/www/chai/handlers/conversations.php
 *
 * 会話の一覧 / 作成 / 取得 / 名前 / 分類 / 削除。
 * どの action がどれを呼ぶかは api.php の表を見る。
 * どれも最後は json_out() で返して終わる (json_out は exit する)。
 */
declare(strict_types=1);

// 会話一覧（所有＋共有された会話）
function act_conversations(array $me, string $email): void {
        $own = db()->prepare(
            "SELECT id, title, category, model, updated_at, 1 AS owned, NULL AS owner_email, 'owner' AS perm
             FROM conversations WHERE email = ? AND archived = 0"
        );
        $own->execute([$email]);
        $list = $own->fetchAll();
        $g = grantee_keys($me);
        $ph = implode(',', array_fill(0, count($g), '?'));
        $sh = db()->prepare(
            "SELECT c.id, c.title, c.category, c.model, c.updated_at, 0 AS owned, c.email AS owner_email,
                    MAX(s.permission = 'write') AS can_write
             FROM conversations c JOIN shares s ON s.conversation_id = c.id
             WHERE s.grantee IN ($ph) AND c.archived = 0 AND LOWER(c.email) <> ?
             GROUP BY c.id"
        );
        $sh->execute(array_merge($g, [strtolower($email)]));
        foreach ($sh->fetchAll() as $r) {
            $r['perm'] = $r['can_write'] ? 'write' : 'read';
            unset($r['can_write']);
            $list[] = $r;
        }
        usort($list, fn($a, $b) => strcmp((string)$b['updated_at'], (string)$a['updated_at']));
        json_out(['conversations' => $list]);
}

// 内容から自動分類（新規会話の初回やり取り後にフロントが呼ぶ）
function act_classify(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $id = (int)($b['id'] ?? 0);
        if (!conv_owned($id, $email)) json_out(['error' => 'not_found'], 404);
        $cat = classify_conversation($id, $email);
        json_out(['id' => $id, 'category' => $cat]);
}

// カテゴリを手動変更
function act_conversation_recategorize(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $id = (int)($b['id'] ?? 0);
        $cat = mb_substr(trim((string)($b['category'] ?? '')), 0, 24);
        if (!conv_owned($id, $email)) json_out(['error' => 'not_found'], 404);
        db()->prepare("UPDATE conversations SET category = ? WHERE id = ? AND email = ?")
           ->execute([$cat !== '' ? $cat : null, $id, $email]);
        json_out(['ok' => true]);
}

// 会話作成
function act_conversation_create(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $title = trim((string)($b['title'] ?? '新しいチャット'));
        if ($title === '') $title = '新しいチャット';
        $st = db()->prepare("INSERT INTO conversations (email, title) VALUES (?, ?)");
        $st->execute([$email, mb_substr($title, 0, 255)]);
        $id = (int)db()->lastInsertId();
        json_out(['id' => $id, 'title' => $title]);
}

// 1会話のメッセージ取得（共有アクセス可）
function act_conversation(array $me, string $email): void {
        $id = (int)($_GET['id'] ?? 0);
        $acc = conv_access($id, $me);
        if (!$acc) json_out(['error' => 'not_found'], 404);
        $st = db()->prepare(
            "SELECT id, role, content, author_email, author_name, created_at FROM messages
             WHERE conversation_id = ? AND role <> 'system' ORDER BY id ASC"
        );
        $st->execute([$id]);
        $messages = $st->fetchAll();
        // 発言ごとの添付を付与＝リロード後も表示できる（画像は/media URL、PDF/データは名前チップ）
        $fr = db()->prepare("SELECT message_id, kind, name FROM conv_files WHERE conversation_id = ? ORDER BY id ASC");
        $fr->execute([$id]);
        $imgByMsg = []; $fileByMsg = [];
        foreach ($fr->fetchAll() as $r) {
            if (!$r['message_id']) continue;
            $m = (int)$r['message_id'];
            if ($r['kind'] === 'image') { if (strncmp((string)$r['name'], '/media/', 7) === 0) $imgByMsg[$m][] = $r['name']; }
            else { $fileByMsg[$m][] = ['kind' => $r['kind'] === 'doc' ? 'pdf' : 'data', 'name' => $r['name']]; }
        }
        foreach ($messages as &$m) { $m['images'] = $imgByMsg[(int)$m['id']] ?? []; $m['files'] = $fileByMsg[(int)$m['id']] ?? []; }
        unset($m);
        json_out(['conversation' => $acc['conv'], 'perm' => $acc['perm'], 'messages' => $messages]);
}

// 会話リネーム
function act_conversation_rename(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $id = (int)($b['id'] ?? 0);
        $title = trim((string)($b['title'] ?? ''));
        if (!conv_owned($id, $email)) json_out(['error' => 'not_found'], 404);
        if ($title === '') json_out(['error' => 'empty_title'], 400);
        $st = db()->prepare("UPDATE conversations SET title = ? WHERE id = ? AND email = ?");
        $st->execute([mb_substr($title, 0, 255), $id, $email]);
        json_out(['ok' => true]);
}

// 会話削除
function act_conversation_delete(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $id = (int)($b['id'] ?? 0);
        if (!conv_owned($id, $email)) json_out(['error' => 'not_found'], 404);
        // 紐づくファイルを切り離してから会話・履歴を削除（参照0のものだけ実削除）
        $fs = db()->prepare("SELECT file_id FROM conv_files WHERE conversation_id = ?");
        $fs->execute([$id]);
        $fids = array_map(fn($f) => (string)$f['file_id'], $fs->fetchAll());
        db()->prepare("DELETE FROM conv_files WHERE conversation_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM messages WHERE conversation_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM conversations WHERE id = ? AND email = ?")->execute([$id, $email]);
        release_file_refs($fids);
        json_out(['ok' => true]);
}
