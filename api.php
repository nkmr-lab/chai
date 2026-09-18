<?php
/**
 * C:\Dropbox\Programs\Claude\chai\api.php
 *   -> /var/www/chai/api.php
 *
 * JSON API の入口。 ルート: /api.php?action=<name>
 * ここには「どの action が何を呼ぶか」の表だけを置く。 中身は handlers/ にある。
 * 返事の生成 (ストリーミング) だけは別で、 chat.php が受ける。
 *
 * サブスク契約は LabPay 側が真実の源 (chai は照合のみ)。
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

foreach (['state', 'conversations', 'messages', 'feedback', 'shares',
          'stocks', 'bookmarks', 'todos', 'pinsets'] as $__h) {
    require_once __DIR__ . "/handlers/{$__h}.php";
}

/** action => 呼ぶ関数。 関数はすべて handlers/ にあり、 (array $me, string $email) で呼ばれる。 */
const CHAI_ACTIONS = [
    // 自分の状態
    'state'   => 'act_state',
    'recheck' => 'act_recheck',

    // 会話
    'conversations'             => 'act_conversations',
    'conversation'              => 'act_conversation',
    'conversation_create'       => 'act_conversation_create',
    'conversation_rename'       => 'act_conversation_rename',
    'conversation_recategorize' => 'act_conversation_recategorize',
    'conversation_delete'       => 'act_conversation_delete',
    'classify'                  => 'act_classify',

    // 発言
    'message_delete' => 'act_message_delete',

    // 要望・不具合
    'feedback_submit' => 'act_feedback_submit',
    'feedback_list'   => 'act_feedback_list',
    'feedback_update' => 'act_feedback_update',

    // 共有
    'shares_list'  => 'act_shares_list',
    'share_add'    => 'act_share_add',
    'share_remove' => 'act_share_remove',

    // ストック
    'stocks'       => 'act_stocks',
    'stock_save'   => 'act_stock_save',
    'stock_delete' => 'act_stock_delete',
    'stock_clear'  => 'act_stock_clear',

    // あとで見る
    'bookmarks'       => 'act_bookmarks',
    'bookmark_add'    => 'act_bookmark_add',
    'bookmark_remove' => 'act_bookmark_remove',

    // TODO
    'todos'       => 'act_todos',
    'todo_set'    => 'act_todo_set',
    'todo_remove' => 'act_todo_remove',

    // ピンセット
    'pinsets'        => 'act_pinsets',
    'pinset_save'    => 'act_pinset_save',
    'pinset_reorder' => 'act_pinset_reorder',
    'pinset_delete'  => 'act_pinset_delete',
];

$me     = require_login_json();
$email  = $me['email'];
$action = (string)($_GET['action'] ?? '');

$fn = CHAI_ACTIONS[$action] ?? null;
if ($fn === null) json_out(['error' => 'unknown_action', 'action' => $action], 400);

$fn($me, $email);
json_out(['error' => 'no_response', 'action' => $action], 500);   // ここには来ない (各 act_ が返して終わる)
