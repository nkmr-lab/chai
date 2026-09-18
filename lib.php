<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib.php
 *   -> /var/www/chai/lib.php
 *
 * 共通ヘルパの読み込み口。 中身は lib/ に役割ごとに分けてある。
 * どの入口 (index.php / api.php / chat.php / media.php など) もこれ 1 本を require すれば良い。
 *
 *   lib/http.php          JSON で返す / ログインを確かめる / 本文を読む
 *   lib/tier.php          契約とティア (真実の源は LabPay)
 *   lib/usage.php         使った量の記録
 *   lib/openai_files.php  OpenAI のファイル置き場
 *   lib/images.php        画像の生成と保存
 *   lib/conv.php          会話の見て良い判定と分類
 */
declare(strict_types=1);

/** chai の置き場所 (docroot)。 lib/ や handlers/ の中から media/ を指すのに使う。 */
if (!defined('CHAI_ROOT')) define('CHAI_ROOT', __DIR__);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/nkmrauth.php';

foreach (['http', 'tier', 'usage', 'openai_files', 'images', 'conv'] as $__m) {
    require_once __DIR__ . "/lib/{$__m}.php";
}
