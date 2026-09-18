<?php
/**
 * C:\Dropbox\Programs\Claude\chai\tests\config.test.php
 *   → /var/www/chai/tests/config.test.php
 *
 * テスト用の設定。 本番の config.local.php を土台にして、
 *   - DB を chai_test に差し替え
 *   - 身元を HTTP ヘッダで名乗れるようにする (テストだけの抜け道)
 *   - OpenAI を手元の偽サーバに向ける (本物には 1 回も繋がない)
 * だけを変える。 これを本番の設定として読ませてはいけない。
 */
$base = require __DIR__ . '/../config.local.php';

$base['db']['dsn'] = preg_replace('/dbname=[^;]+/', 'dbname=chai_test', $base['db']['dsn']);
$base['auth']['test_identity_header'] = 'X-Chai-Test-Identity';
$base['openai']['base_url'] = 'http://127.0.0.1:8898/v1';
$base['openai']['api_key']  = 'sk-test';
// LabPay は NKMRID cookie が無ければ通信せず「未契約」を返すので、 そのままで良い。

return $base;
