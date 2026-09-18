<?php
/**
 * C:\Dropbox\Programs\Claude\chai\handlers\feedback.php
 *   -> /var/www/chai/handlers/feedback.php
 *
 * 要望・不具合。
 * どの action がどれを呼ぶかは api.php の表を見る。
 * どれも最後は json_out() で返して終わる (json_out は exit する)。
 */
declare(strict_types=1);

// 要望・不具合を送信
function act_feedback_submit(array $me, string $email): void {
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
function act_feedback_list(array $me, string $email): void {
        if (is_admin($me)) {
            $st = db()->query("SELECT id, email, name, kind, text, status, admin_note, created_at FROM feedback ORDER BY (status='new') DESC, updated_at DESC LIMIT 500");
        } else {
            $st = db()->prepare("SELECT id, kind, text, status, admin_note, created_at FROM feedback WHERE email = ? ORDER BY created_at DESC LIMIT 200");
            $st->execute([$email]);
        }
        json_out(['is_admin' => is_admin($me), 'feedback' => $st->fetchAll()]);
}

// 要望のステータス更新（管理者のみ）
function act_feedback_update(array $me, string $email): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        if (!is_admin($me)) json_out(['error' => 'forbidden'], 403);
        $b = read_json_body();
        $status = in_array($b['status'] ?? '', ['new', 'planned', 'doing', 'done', 'declined'], true) ? $b['status'] : 'new';
        db()->prepare("UPDATE feedback SET status = ?, admin_note = ? WHERE id = ?")
           ->execute([$status, mb_substr(trim((string)($b['admin_note'] ?? '')), 0, 500), (int)($b['id'] ?? 0)]);
        json_out(['ok' => true]);
}
