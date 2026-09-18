<?php
/**
 * C:\Dropbox\Programs\Claude\chai\tests\run_tests.php
 *   → /var/www/chai/tests/run_tests.php
 *
 * chai の API を HTTP 越しに一通り叩くテスト。 tests/e2e.sh から呼ばれる
 * (先に php -S で chai 本体と偽 OpenAI を立てておく必要がある)。
 *
 * 触るのは chai_test DB と偽 OpenAI だけ。 本番 DB と本物の OpenAI には繋がない。
 * 走らせるたびに chai_test の中身は消える。
 *
 * 「いまこう動いている」を写し取るのが目的。 中身を作り替えた後も、 これが同じように
 * 通ることで、 振る舞いを変えていないと言える。
 */
declare(strict_types=1);

$BASE = getenv('CHAI_TEST_BASE') ?: 'http://127.0.0.1:8897';

$T = ['ok' => 0, 'ng' => 0, 'fails' => []];
function ok(bool $c, string $what, string $detail = ''): void {
    global $T;
    if ($c) { $T['ok']++; echo "  ok   $what\n"; }
    else { $T['ng']++; $T['fails'][] = $what; echo "  FAIL $what" . ($detail ? "  <- $detail" : '') . "\n"; }
}
function eq($expect, $actual, string $what): void {
    ok($expect === $actual, $what, 'expected=' . json_encode($expect, JSON_UNESCAPED_UNICODE)
        . ' actual=' . json_encode($actual, JSON_UNESCAPED_UNICODE));
}
function section(string $s): void { echo "\n[$s]\n"; }

function who(string $email, string $name): string {
    return json_encode(['email' => $email, 'name' => $name, 'user' => explode('@', $email)[0]], JSON_UNESCAPED_UNICODE);
}
$ALICE = who('alice@test.local', '有栖川あゆみ');
$BOB   = who('bob@test.local',   'ボブ田太郎');
$ADMIN = who('nakamura.satoshi@gmail.com', '中村聡史');

/** API を 1 回叩く。 $body があれば POST。 */
function call(string $action, ?array $body = null, ?string $as = null, array $query = []): array {
    global $BASE, $ALICE;
    $as = $as ?? $ALICE;
    $url = $BASE . '/api.php?action=' . rawurlencode($action);
    foreach ($query as $k => $v) $url .= '&' . rawurlencode($k) . '=' . rawurlencode((string)$v);
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-Chai-Test-Identity: ' . $as, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ];
    if ($body !== null) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    curl_setopt_array($ch, $opt);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'json' => json_decode((string)$res, true), 'raw' => (string)$res];
}

// ─── 下ごしらえ ───────────────────────────────────────
$CFG = require __DIR__ . '/config.test.php';
$pdo = new PDO($CFG['db']['dsn'], $CFG['db']['user'], $CFG['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET NAMES utf8mb4');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['conv_files','file_blobs','messages','shares','stocks','bookmarks','todos','pin_sets',
          'feedback','sub_cache','usage_daily','usage_feature','usage_events','conversations'] as $t) {
    $pdo->exec("TRUNCATE TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// ─── 身元 ─────────────────────────────────────────────
section('身元と状態');
$r = call('state', null, '');
eq(401, $r['status'], '身元なしは 401');

$r = call('state');
eq(200, $r['status'], 'ログインすれば 200');
eq('alice@test.local', $r['json']['user']['email'], '自分のメールが返る');
eq(false, $r['json']['is_admin'], '普通の人は管理者ではない');
ok(isset($r['json']['tier']), 'ティアの状態が付いてくる');

$r = call('state', null, $ADMIN);
eq(true, $r['json']['is_admin'], 'config の admins は管理者');

$r = call('recheck', []);
eq(200, $r['status'], '契約の再照会が通る');

$r = call('nosuchaction');
eq(400, $r['status'], '知らない action は 400');
eq('unknown_action', $r['json']['error'], 'その旨が返る');

// ─── 会話 ─────────────────────────────────────────────
section('会話');
$r = call('conversations');
eq([], $r['json']['conversations'], '最初は空');

$r = call('conversation_create', ['title' => '最初の会話']);
eq(200, $r['status'], '会話を作れる');
$c1 = (int)$r['json']['id'];
ok($c1 > 0, 'id が返る');

$r = call('conversation_create', ['title' => '']);
eq('新しいチャット', $r['json']['title'], '題名が空なら既定の名前');
$c2 = (int)$r['json']['id'];

$r = call('conversations');
eq(2, count($r['json']['conversations']), '2 件見える');
eq('owner', $r['json']['conversations'][0]['perm'], '自分のものは owner');

$r = call('conversation', null, null, ['id' => $c1]);
eq(200, $r['status'], '中身を開ける');
eq([], $r['json']['messages'], 'まだ発言は無い');
eq('owner', $r['json']['perm'], '権限は owner');

$r = call('conversation', null, $BOB, ['id' => $c1]);
eq(404, $r['status'], '他人の会話は開けない');

$r = call('conversation_rename', ['id' => $c1, 'title' => '名前を変えた']);
eq(200, $r['status'], '名前を変えられる');
$r = call('conversation_rename', ['id' => $c1, 'title' => '']);
eq(400, $r['status'], '空の名前は 400');
$r = call('conversation_rename', ['id' => $c1, 'title' => 'よこどり'], $BOB);
eq(404, $r['status'], '他人の会話は変えられない');

$r = call('conversation_recategorize', ['id' => $c1, 'category' => '研究']);
eq(200, $r['status'], 'カテゴリを変えられる');
$r = call('conversations');
$one = array_values(array_filter($r['json']['conversations'], fn($c) => (int)$c['id'] === $c1))[0];
eq('研究', $one['category'], 'カテゴリが入っている');
eq('名前を変えた', $one['title'], '名前が変わっている');

// ─── 共有 ─────────────────────────────────────────────
section('共有');
$r = call('shares_list', null, null, ['id' => $c1]);
eq([], $r['json']['shares'], '最初は共有なし');

$r = call('share_add', ['conversation_id' => $c1, 'grantee' => 'BOB@test.local', 'permission' => 'read']);
eq(200, $r['status'], '共有を足せる');
$r = call('shares_list', null, null, ['id' => $c1]);
eq('bob@test.local', $r['json']['shares'][0]['grantee'], '宛先は小文字にそろう');
eq('read', $r['json']['shares'][0]['permission'], '権限は read');
$shareId = (int)$r['json']['shares'][0]['id'];

$r = call('conversation', null, $BOB, ['id' => $c1]);
eq(200, $r['status'], '共有された人は開ける');
eq('read', $r['json']['perm'], '権限は read');

$r = call('share_add', ['conversation_id' => $c1, 'grantee' => 'bob@test.local', 'permission' => 'write']);
$r = call('conversation', null, $BOB, ['id' => $c1]);
eq('write', $r['json']['perm'], '上書きすると write になる');

$r = call('conversations', null, $BOB);
eq(1, count($r['json']['conversations']), '共有された会話は一覧に出る');
eq(0, (int)$r['json']['conversations'][0]['owned'], '自分のものではない印');

$r = call('share_add', ['conversation_id' => $c1, 'grantee' => ''], $BOB);
eq(404, $r['status'], '所有者でなければ共有を足せない');
$r = call('share_add', ['conversation_id' => $c1, 'grantee' => '']);
eq(400, $r['status'], '宛先が空なら 400');

$r = call('share_remove', ['conversation_id' => $c1, 'id' => $shareId]);
eq(200, $r['status'], '共有を外せる');
$r = call('conversation', null, $BOB, ['id' => $c1]);
eq(404, $r['status'], '外したら開けない');

// 全員に共有
$r = call('share_add', ['conversation_id' => $c2, 'everyone' => true, 'permission' => 'read']);
$r = call('conversation', null, $BOB, ['id' => $c2]);
eq(200, $r['status'], '全員共有なら誰でも開ける');

// ─── 発言と分類 (偽 OpenAI) ───────────────────────────
section('発言と分類');
$pdo->prepare("INSERT INTO messages (conversation_id, role, content, author_email, author_name)
               VALUES (?,?,?,?,?)")->execute([$c1, 'user', 'これはテストの発言', 'alice@test.local', '有栖川あゆみ']);
$mid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?,?,?)")
    ->execute([$c1, 'assistant', 'これは返事']);

$r = call('conversation', null, null, ['id' => $c1]);
eq(2, count($r['json']['messages']), '発言が読める');
eq([], $r['json']['messages'][0]['images'], '添付は空の配列で付いてくる');

$r = call('classify', ['id' => $c1]);
eq(200, $r['status'], '分類が通る (偽 OpenAI)');
eq('雑談', $r['json']['category'], '偽 OpenAI が返した分類が入る');

$r = call('message_delete', ['id' => $mid], $BOB);
eq(403, $r['status'], '他人の発言は消せない');
$r = call('message_delete', ['id' => $mid]);
eq(200, $r['status'], '自分の発言は消せる');
$r = call('conversation', null, null, ['id' => $c1]);
eq(1, count($r['json']['messages']), '消えている');
$r = call('message_delete', ['id' => 999999]);
eq(404, $r['status'], '無い発言は 404');

// ─── 要望 ─────────────────────────────────────────────
section('要望・不具合');
$r = call('feedback_submit', ['text' => '', 'kind' => 'bug']);
eq(400, $r['status'], '空の要望は 400');
$r = call('feedback_submit', ['text' => 'ここが使いにくい', 'kind' => 'bug']);
eq(200, $r['status'], '要望を出せる');

$r = call('feedback_list');
eq(1, count($r['json']['feedback']), '自分の分が見える');
eq(false, $r['json']['is_admin'], '一般の人には管理者の印は立たない');

$r = call('feedback_list', null, $ADMIN);
eq(true, $r['json']['is_admin'], '管理者には印が立つ');
$fid = (int)$r['json']['feedback'][0]['id'];
eq('new', $r['json']['feedback'][0]['status'], '一般の人からの要望は new');

$r = call('feedback_update', ['id' => $fid, 'status' => 'doing', 'admin_note' => '対応中'], $ADMIN);
eq(200, $r['status'], '管理者は状態を変えられる');
$r = call('feedback_update', ['id' => $fid, 'status' => 'done']);
eq(403, $r['status'], '一般の人は変えられない');
$r = call('feedback_list', null, $ADMIN);
eq('doing', $r['json']['feedback'][0]['status'], '状態が変わっている');

$r = call('feedback_submit', ['text' => '中村からの指示'], $ADMIN);
$r = call('feedback_list', null, $ADMIN);
$mine = array_values(array_filter($r['json']['feedback'], fn($f) => $f['text'] === '中村からの指示'))[0];
eq('planned', $mine['status'], '管理者の要望はそのまま planned');

// ─── ストック ─────────────────────────────────────────
section('ストック');
$r = call('stocks');
eq([], $r['json']['stocks'], '最初は空');
$r = call('stock_save', ['text' => '', 'conversation_id' => $c1]);
eq(400, $r['status'], '空は 400');
$r = call('stock_save', ['text' => '覚えておきたい返事', 'conversation_id' => $c1]);
eq(200, $r['status'], '保存できる');
$sid = (int)$r['json']['id'];
$r = call('stocks');
eq(1, count($r['json']['stocks']), '一覧に出る');
$r = call('stocks', null, $BOB);
eq([], $r['json']['stocks'], '他人のストックは見えない');
$r = call('stock_delete', ['id' => $sid]);
eq(200, $r['status'], '消せる');
call('stock_save', ['text' => 'あ']); call('stock_save', ['text' => 'い']);
$r = call('stock_clear', []);
eq(200, $r['status'], '全部消せる');
$r = call('stocks');
eq([], $r['json']['stocks'], '空になった');

// ─── ブックマークと TODO ──────────────────────────────
section('ブックマークと TODO');
$r = call('bookmarks');
eq([], $r['json']['ids'], '最初は空');
$r = call('bookmark_add', ['conversation_id' => $c1]);
eq(200, $r['status'], '足せる');
$r = call('bookmarks');
eq([$c1], $r['json']['ids'], '入っている');
$r = call('bookmark_add', ['conversation_id' => $c1]);
eq(200, $r['status'], '二重に足しても平気');
$r = call('bookmark_add', ['conversation_id' => 999999], $BOB);
eq(403, $r['status'], '入れない会話は足せない');
$r = call('bookmark_remove', ['conversation_id' => $c1]);
$r = call('bookmarks');
eq([], $r['json']['ids'], '外せる');

$r = call('todos');
eq([], $r['json']['todos'], 'TODO も最初は空');
$r = call('todo_set', ['conversation_id' => $c1, 'due' => '2026-10-01']);
eq(200, $r['status'], '期日を付けられる');
$r = call('todos');
eq([['id' => $c1, 'due' => '2026-10-01']], $r['json']['todos'], '期日つきで出る');
$r = call('todo_set', ['conversation_id' => $c1, 'due' => 'でたらめ']);
$r = call('todos');
eq(null, $r['json']['todos'][0]['due'], '日付として読めない指定は空になる');
$r = call('todo_set', ['conversation_id' => $c1, 'done' => true]);
$r = call('todos');
eq([], $r['json']['todos'], '済ませると未完から消え');
eq(1, count($r['json']['done']), '完了の方に移る');
$r = call('todo_remove', ['conversation_id' => $c1]);
$r = call('todos');
eq(0, count($r['json']['done']), '消せる');

// ─── ピンセット ───────────────────────────────────────
section('ピンセット');
$r = call('pinsets');
eq([], $r['json']['pinsets'], '最初は空');
$r = call('pinset_save', ['name' => '', 'chat_ids' => [$c1]]);
eq(400, $r['status'], '名前が空なら 400');
$r = call('pinset_save', ['name' => '朝', 'chat_ids' => []]);
eq(400, $r['status'], '中身が空なら 400');
$r = call('pinset_save', ['name' => '朝', 'chat_ids' => [$c1, $c2, $c1]]);
eq(200, $r['status'], '作れる');
$r = call('pinsets');
eq(1, count($r['json']['pinsets']), '一覧に出る');
eq([$c1, $c2], $r['json']['pinsets'][0]['chat_ids'], '重複は取り除かれる');
$psid = (int)$r['json']['pinsets'][0]['id'];

$r = call('pinset_save', ['name' => '朝', 'chat_ids' => [$c2]]);
$r = call('pinsets');
eq(1, count($r['json']['pinsets']), '同じ名前は増えずに');
eq([$c2], $r['json']['pinsets'][0]['chat_ids'], '中身が上書きされる');

call('pinset_save', ['name' => '夜', 'chat_ids' => [$c1]]);
$r = call('pinsets');
$ids = array_column($r['json']['pinsets'], 'id');
eq(['朝', '夜'], array_column($r['json']['pinsets'], 'name'), '並びは作った順');
$r = call('pinset_reorder', ['ids' => array_reverse($ids)]);
$r = call('pinsets');
eq(['夜', '朝'], array_column($r['json']['pinsets'], 'name'), '並べ替えられる');

$r = call('pinsets', null, $BOB);
eq([], $r['json']['pinsets'], '他人のピンセットは見えない');
$r = call('pinset_delete', ['id' => $psid], $BOB);
$r = call('pinsets');
eq(2, count($r['json']['pinsets']), '他人には消せない');
$r = call('pinset_delete', ['id' => $psid]);
$r = call('pinsets');
eq(1, count($r['json']['pinsets']), '自分のは消せる');

// ─── 会話の削除 ───────────────────────────────────────
section('会話の削除');
$r = call('conversation_delete', ['id' => $c1], $BOB);
eq(404, $r['status'], '他人の会話は消せない');
$r = call('conversation_delete', ['id' => $c1]);
eq(200, $r['status'], '自分の会話は消せる');
$r = call('conversation', null, null, ['id' => $c1]);
eq(404, $r['status'], '消えている');
$left = (int)$pdo->query("SELECT COUNT(1) FROM messages WHERE conversation_id = $c1")->fetchColumn();
eq(0, $left, '中の発言も残らない');

// ─── 結果 ─────────────────────────────────────────────
echo "\n" . str_repeat('-', 50) . "\n";
echo "ok {$T['ok']} / ng {$T['ng']}\n";
if ($T['ng'] > 0) {
    echo "落ちたもの:\n  - " . implode("\n  - ", $T['fails']) . "\n";
    exit(1);
}
echo "ぜんぶ通りました\n";
exit(0);
