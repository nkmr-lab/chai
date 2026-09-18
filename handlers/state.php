<?php
/**
 * C:\Dropbox\Programs\Claude\chai\handlers\state.php
 *   -> /var/www/chai/handlers/state.php
 *
 * 自分の状態と契約の再照会。
 * どの action がどれを呼ぶかは api.php の表を見る。
 * どれも最後は json_out() で返して終わる (json_out は exit する)。
 */
declare(strict_types=1);

// 現在のユーザ + ティア/サブスク状態
function act_state(array $me, string $email): void {
        json_out([
            'user'     => ['email' => $email, 'name' => $me['name'] ?? '', 'user' => $me['user'] ?? ''],
            'tier'     => tier_state($email),
            'is_admin' => is_admin($me),
        ]);
}

// 契約状況をキャッシュ無視で再照会（LabPayで契約直後などに使う）
function act_recheck(array $me, string $email): void {
        db()->prepare("DELETE FROM sub_cache WHERE email = ?")->execute([$email]);
        json_out(['tier' => tier_state($email)]);
}
