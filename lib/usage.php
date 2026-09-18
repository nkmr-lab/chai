<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\usage.php
 *   -> /var/www/chai/lib/usage.php
 *
 * 使った量の記録 (1 日の通数、 機能ごとの窓)。
 * まとめて読み込むのは lib.php。 個別に require しない。
 */
declare(strict_types=1);

/** 本日の送信回数。 */
function usage_today(string $email): int {
    $st = db()->prepare("SELECT message_count FROM usage_daily WHERE email = ? AND day = CURDATE()");
    $st->execute([$email]);
    return (int)($st->fetchColumn() ?: 0);
}

/** 本日の送信回数を +1。 */
function usage_bump(string $email): void {
    $st = db()->prepare(
        "INSERT INTO usage_daily (email, day, message_count) VALUES (?, CURDATE(), 1)
         ON DUPLICATE KEY UPDATE message_count = message_count + 1"
    );
    $st->execute([$email]);
}

/** 直近 $hours 時間の利用回数（msg / file / imggen）。 */
function usage_window_count(string $email, string $feature, int $hours): int {
    $h = max(1, $hours);
    $st = db()->prepare("SELECT COUNT(*) FROM usage_events WHERE email = ? AND feature = ? AND at > (NOW() - INTERVAL {$h} HOUR)");
    $st->execute([$email, $feature]);
    return (int)$st->fetchColumn();
}

/** 利用イベントを1件記録。 */
function usage_event(string $email, string $feature): void {
    db()->prepare("INSERT INTO usage_events (email, feature) VALUES (?, ?)")->execute([$email, $feature]);
}
