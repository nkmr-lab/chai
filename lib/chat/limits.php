<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\chat\limits.php
 *   -> /var/www/chai/lib/chat/limits.php
 *
 * ティアとモデルを決める。 上限に当たったら知らせて、 代わりのモデルに落とす。
 * 呼ぶのは chat.php。 手順はそちらを見ると 1 画面で分かる。
 */
declare(strict_types=1);

/**
 * 返り値: tier / tc(ティア設定) / win(窓の時間) / model / filesUnlimited / allowFiles / visionOK
 *         と、 許されなかった分を取り除いた images / pdfs。
 */
function chat_prepare_tier(string $email, string $reqModel, array $images, array $pdfs): array {
    $sub  = labpay_sub_status($email);
    $tier = tier_name($sub);
    $tc   = tier_config($tier);
    $win  = (int)($tc['window_hours'] ?? 8);
    $model = model_allowed($reqModel, $tier) ? $reqModel : default_model_for($tier);

    // プレミアムモデル(Astra等)は会員でも週次上限。超過したら安価な代替に自動フォールバック
    $prem = $GLOBALS['CFG']['premium'] ?? [];
    $premModels = (array)($prem['models'] ?? []);
    $premUsed = false;
    if ($premModels && in_array($model, $premModels, true)) {
        $pcap = (int)($prem['cap'] ?? 30);
        $pwin = (int)($prem['window_hours'] ?? 168);
        if ($pcap > 0 && usage_window_count($email, 'premium', $pwin) >= $pcap) {
            $fb = (string)($prem['fallback'] ?? 'gpt-5.6-sol');
            $days = (int)round($pwin / 24);
            sse(['type' => 'status', 'text' => "⭐ {$model} の利用上限（{$days}日で{$pcap}回）に達したため、今回は " . $fb . " で回答します"]);
            $model = model_allowed($fb, $tier) ? $fb : default_model_for($tier);
        } else {
            $premUsed = true;   // 上限内 → このターンをカウント（後で記録）
        }
    }

    $cap = (int)($tc['message_cap'] ?? 0);
    if ($cap > 0 && usage_window_count($email, 'msg', $win) >= $cap) {
        sse_error("おためしは{$win}時間あたり{$cap}通までです。メンバーになると無制限で使えます。");
    }
    if ($premUsed) usage_event($email, 'premium');   // Astra等プレミアムの週次カウント
    // ファイル(画像/PDF)読み込み: pro=無制限 / free=1日お試し枠
    $filesUnlimited = !empty($tc['allow_images']);
    $fileLimit      = (int)($tc['file_limit'] ?? 0);
    $hadFiles       = ($images || $pdfs);
    $allowFiles     = $filesUnlimited || (usage_window_count($email, 'file', $win) < $fileLimit);
    if (!$allowFiles) {
        $images = []; $pdfs = [];
        if ($hadFiles) sse(['type' => 'status', 'text' => "📎 ファイル読み込みは、おためしでは{$win}時間に1回までです（メンバーは無制限）"]);
    }
    $visionOK = $allowFiles && model_has_vision($model);
    if ($images && !$visionOK) $images = [];

    return compact('sub', 'tier', 'tc', 'win', 'model', 'filesUnlimited', 'allowFiles', 'visionOK', 'images', 'pdfs');
}
