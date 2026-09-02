<?php
/**
 * api.php — JSON API（会話CRUD・状態取得・契約再照会）。ストリーミングは chat.php。
 * サブスク契約は LabPay 側で管理（chai は照合のみ）。
 * ルート: /api.php?action=<name>
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$me     = require_login_json();
$email  = $me['email'];
$action = $_GET['action'] ?? '';

switch ($action) {

    // 現在のユーザ + ティア/サブスク状態
    case 'state': {
        json_out([
            'user'     => ['email' => $email, 'name' => $me['name'] ?? '', 'user' => $me['user'] ?? ''],
            'tier'     => tier_state($email),
            'is_admin' => is_admin($me),
        ]);
    }

    // 契約状況をキャッシュ無視で再照会（LabPayで契約直後などに使う）
    case 'recheck': {
        db()->prepare("DELETE FROM sub_cache WHERE email = ?")->execute([$email]);
        json_out(['tier' => tier_state($email)]);
    }

    // 会話一覧（所有＋共有された会話）
    case 'conversations': {
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
    case 'classify': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $id = (int)($b['id'] ?? 0);
        if (!conv_owned($id, $email)) json_out(['error' => 'not_found'], 404);
        $cat = classify_conversation($id, $email);
        json_out(['id' => $id, 'category' => $cat]);
    }

    // カテゴリを手動変更
    case 'conversation_recategorize': {
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
    case 'conversation_create': {
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
    case 'conversation': {
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
    case 'conversation_rename': {
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

    // メッセージ削除（誤った発話/アップロードを履歴・ファイルごと消す）
    case 'message_delete': {
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

    // 会話削除
    case 'conversation_delete': {
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

    // 要望・不具合を送信
    case 'feedback_submit': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $text = trim((string)($b['text'] ?? ''));
        if ($text === '') json_out(['error' => 'empty'], 400);
        $kind = in_array($b['kind'] ?? '', ['request', 'bug', 'other'], true) ? $b['kind'] : 'request';
        $status = is_admin($me) ? 'planned' : 'new';   // 中村の指示はそのまま受理（planned）
        db()->prepare("INSERT INTO feedback (email, name, kind, text, status) VALUES (?, ?, ?, ?, ?)")
           ->execute([$email, (string)($me['name'] ?? ''), $kind, mb_substr($text, 0, 20000), $status]);
        json_out(['ok' => true]);
    }

    // 要望一覧（管理者=全件 / 一般=自分の分）
    case 'feedback_list': {
        if (is_admin($me)) {
            $st = db()->query("SELECT id, email, name, kind, text, status, admin_note, created_at FROM feedback ORDER BY (status='new') DESC, updated_at DESC LIMIT 500");
        } else {
            $st = db()->prepare("SELECT id, kind, text, status, admin_note, created_at FROM feedback WHERE email = ? ORDER BY created_at DESC LIMIT 200");
            $st->execute([$email]);
        }
        json_out(['is_admin' => is_admin($me), 'feedback' => $st->fetchAll()]);
    }

    // 要望のステータス更新（管理者のみ）
    case 'feedback_update': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        if (!is_admin($me)) json_out(['error' => 'forbidden'], 403);
        $b = read_json_body();
        $status = in_array($b['status'] ?? '', ['new', 'planned', 'doing', 'done', 'declined'], true) ? $b['status'] : 'new';
        db()->prepare("UPDATE feedback SET status = ?, admin_note = ? WHERE id = ?")
           ->execute([$status, mb_substr(trim((string)($b['admin_note'] ?? '')), 0, 500), (int)($b['id'] ?? 0)]);
        json_out(['ok' => true]);
    }

    // 共有一覧（所有者のみ）
    case 'shares_list': {
        $id = (int)($_GET['id'] ?? 0);
        if (!conv_owned($id, $email)) json_out(['error' => 'not_found'], 404);
        $st = db()->prepare("SELECT id, grantee, permission FROM shares WHERE conversation_id = ? ORDER BY grantee");
        $st->execute([$id]);
        json_out(['shares' => $st->fetchAll()]);
    }

    // 共有を追加/更新（所有者のみ）
    case 'share_add': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $id = (int)($b['conversation_id'] ?? 0);
        if (!conv_owned($id, $email)) json_out(['error' => 'not_found'], 404);
        $raw = trim((string)($b['grantee'] ?? ''));
        $perm = ($b['permission'] ?? 'read') === 'write' ? 'write' : 'read';
        if (!empty($b['everyone']) || $raw === '*' || $raw === '全員' || strtolower($raw) === 'everyone') $gr = '*';
        else $gr = strtolower($raw);
        if ($gr === '') json_out(['error' => 'empty_grantee'], 400);
        db()->prepare(
            "INSERT INTO shares (conversation_id, grantee, permission) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE permission = VALUES(permission)"
        )->execute([$id, $gr, $perm]);
        json_out(['ok' => true]);
    }

    // 共有を解除（所有者のみ）
    case 'share_remove': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $id = (int)($b['conversation_id'] ?? 0);
        if (!conv_owned($id, $email)) json_out(['error' => 'not_found'], 404);
        db()->prepare("DELETE FROM shares WHERE id = ? AND conversation_id = ?")->execute([(int)($b['id'] ?? 0), $id]);
        json_out(['ok' => true]);
    }

    // ストック一覧
    case 'stocks': {
        $st = db()->prepare("SELECT id, conversation_id, text, created_at FROM stocks WHERE email = ? ORDER BY created_at DESC LIMIT 500");
        $st->execute([$email]);
        json_out(['stocks' => $st->fetchAll()]);
    }

    // ストック保存（返信まるごと or 選択部分）
    case 'stock_save': {
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
    case 'stock_delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        db()->prepare("DELETE FROM stocks WHERE id = ? AND email = ?")->execute([(int)($b['id'] ?? 0), $email]);
        json_out(['ok' => true]);
    }

    // ストック全消し
    case 'stock_clear': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        db()->prepare("DELETE FROM stocks WHERE email = ?")->execute([$email]);
        json_out(['ok' => true]);
    }

    // ブックマーク（あとで見る）一覧＝会話idの配列
    case 'bookmarks': {
        $st = db()->prepare("SELECT conversation_id FROM bookmarks WHERE email = ? ORDER BY created_at DESC");
        $st->execute([$email]);
        json_out(['ids' => array_map('intval', array_column($st->fetchAll(), 'conversation_id'))]);
    }

    // ブックマーク追加（アクセスできる会話のみ）
    case 'bookmark_add': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $id = (int)($b['conversation_id'] ?? 0);
        if (!conv_access($id, $me)) json_out(['error' => 'forbidden'], 403);
        db()->prepare("INSERT IGNORE INTO bookmarks (email, conversation_id) VALUES (?, ?)")->execute([$email, $id]);
        json_out(['ok' => true]);
    }

    // ブックマーク削除
    case 'bookmark_remove': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        db()->prepare("DELETE FROM bookmarks WHERE email = ? AND conversation_id = ?")->execute([$email, (int)($b['conversation_id'] ?? 0)]);
        json_out(['ok' => true]);
    }

    // TODO 一覧（未完 todos ＋ 完了 done）＝各 [{id, due}]
    case 'todos': {
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
    case 'todo_set': {
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
    case 'todo_remove': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        db()->prepare("DELETE FROM todos WHERE email = ? AND conversation_id = ?")->execute([$email, (int)($b['conversation_id'] ?? 0)]);
        json_out(['ok' => true]);
    }

    // ピンセット一覧
    case 'pinsets': {
        $st = db()->prepare("SELECT id, name, chat_ids FROM pin_sets WHERE email = ? ORDER BY sort_order ASC, id ASC");
        $st->execute([$email]);
        $out = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'chat_ids' => json_decode($r['chat_ids'], true) ?: []], $st->fetchAll());
        json_out(['pinsets' => $out]);
    }

    // ピンセット保存（同名は上書き。新規は末尾に並べる）
    case 'pinset_save': {
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
    case 'pinset_reorder': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $ids = array_values(array_filter(array_map('intval', (array)($b['ids'] ?? [])), fn($x) => $x > 0));
        $up = db()->prepare("UPDATE pin_sets SET sort_order = ? WHERE id = ? AND email = ?");
        foreach ($ids as $i => $id) $up->execute([$i, $id, $email]);
        json_out(['ok' => true]);
    }

    // ピンセット削除
    case 'pinset_delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        db()->prepare("DELETE FROM pin_sets WHERE id = ? AND email = ?")->execute([(int)($b['id'] ?? 0), $email]);
        json_out(['ok' => true]);
    }

    default:
        json_out(['error' => 'unknown_action', 'action' => $action], 400);
}
