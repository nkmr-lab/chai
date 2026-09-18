<?php
/**
 * C:\Dropbox\Programs\Claude\chai\handlers\shares.php
 *   -> /var/www/chai/handlers/shares.php
 *
 * 会話の共有。
 * どの action がどれを呼ぶかは api.php の表を見る。
 * どれも最後は json_out() で返して終わる (json_out は exit する)。
 */
declare(strict_types=1);

// 共有一覧（所有者のみ）
function act_shares_list(array $me, string $email): void {
        $id = (int)($_GET['id'] ?? 0);
        if (!conv_owned($id, $email)) json_out(['error' => 'not_found'], 404);
        $st = db()->prepare("SELECT id, grantee, permission FROM shares WHERE conversation_id = ? ORDER BY grantee");
        $st->execute([$id]);
        json_out(['shares' => $st->fetchAll()]);
}

// 共有を追加/更新（所有者のみ）
function act_share_add(array $me, string $email): void {
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
function act_share_remove(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $b = read_json_body();
        $id = (int)($b['conversation_id'] ?? 0);
        if (!conv_owned($id, $email)) json_out(['error' => 'not_found'], 404);
        db()->prepare("DELETE FROM shares WHERE id = ? AND conversation_id = ?")->execute([(int)($b['id'] ?? 0), $id]);
        json_out(['ok' => true]);
}
