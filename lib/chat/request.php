<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\chat\request.php
 *   -> /var/www/chai/lib/chat/request.php
 *
 * OpenAI に渡すもの (これまでの話・使える道具・言い添え) を組み立てる。
 * 呼ぶのは chat.php。 手順はそちらを見ると 1 画面で分かる。
 */
declare(strict_types=1);

/** これまでのやりとりを、 添付つきで組み立てる。 画像は「その発言に付いていた分」だけを添える。 */
function chat_build_input(int $convId, array $tc, array $files, string $content, bool $regen): array {
    $imgByMsg = $files['imgByMsg']; $docFileIds = $files['docIds'];
    $docFileNames = $files['docNames']; $dataFileNames = $files['dataNames'];
    $hist = (int)($tc['history_messages'] ?? 8);
    $st = db()->prepare(
        "SELECT id, role, content FROM messages WHERE conversation_id = ? AND role IN ('user','assistant')
         ORDER BY id DESC LIMIT ?"
    );
    $st->bindValue(1, $convId, PDO::PARAM_INT);
    $st->bindValue(2, $hist, PDO::PARAM_INT);
    $st->execute();
    $recent = array_reverse($st->fetchAll());

    $input = [];
    foreach ($recent as $i => $m) {
        $mid = (int)($m['id'] ?? 0);
        $isLastUser = ($i === count($recent) - 1) && $m['role'] === 'user';
        if ($m['role'] === 'user') {
            // その発言に紐づく画像だけを添付（古い画像の誤説明を防ぐ）。PDF等は最後の発言にまとめて添付
            $myImgs = $imgByMsg[$mid] ?? [];
            $useDocs = $isLastUser ? $docFileIds : [];
            $names = $isLastUser ? array_merge($docFileNames, $dataFileNames) : [];
            $base  = ($isLastUser && !$regen) ? $content : (string)$m['content'];
            $text  = $base . (($isLastUser && !$regen && $names) ? "\n\n[添付ファイル: " . implode(', ', $names) . "]" : '');
            if ($myImgs || $useDocs) {
                $parts = [['type' => 'input_text', 'text' => $text !== '' ? $text : '添付ファイルを確認してください。']];
                foreach ($myImgs as $fid) $parts[] = ['type' => 'input_image', 'file_id' => $fid];
                foreach ($useDocs as $fid) $parts[] = ['type' => 'input_file', 'file_id' => $fid];
                $input[] = ['role' => 'user', 'content' => $parts];
            } else {
                $input[] = ['role' => 'user', 'content' => $text];
            }
        } else {
            $input[] = ['role' => 'assistant', 'content' => (string)$m['content']];
        }
    }
    return $input;
}

/**
 * 使える道具を決める (Web 検索 / データ分析 / 画像生成・編集)。
 * 返り値: tools と、 使えない時にモデルへ言い添える文 (genNote / webNote)、 画像生成の枠。
 */
function chat_build_tools(array $tc, string $email, int $win, array $files): array {
    $dataFileIds = $files['dataIds']; $imgFileIds = $files['imgIds'];
    $tools = [];
    if (!empty($tc['allow_web_search'])) $tools[] = ['type' => 'web_search'];
    // データ分析: アップロード済みファイルがあれば code_interpreter を付与
    if ($dataFileIds) $tools[] = ['type' => 'code_interpreter', 'container' => ['type' => 'auto', 'file_ids' => $dataFileIds]];
    // 画像生成: pro=無制限 / free=1日お試し枠
    $genUnlimited = !empty($tc['allow_image_gen']);
    $genLimit     = (int)($tc['image_gen_limit'] ?? 0);
    $canGen       = $genUnlimited || (usage_window_count($email, 'imggen', $win) < $genLimit);
    $genNote      = (!$genUnlimited && !$canGen && $genLimit > 0)
        ? '（注意: 画像生成はお試し上限に達しています。求められても生成せず、メンバー契約で無制限に使える旨を丁寧に案内してください。）' : '';
    // Web検索が無いティア向けの案内文（不案内にならないように）
    $webNote = empty($tc['allow_web_search'])
        ? '（Web検索は使えません。ユーザーが最新情報の取得やWeb検索を必要とする場合は「現状、この環境からWebを直接検索する機能はありません。Webを検索する機能を付与するには、LabPayでAIサブスクを契約してください」と案内してください。）' : '';
    if ($canGen) {
        $tools[] = [
            'type' => 'function',
            'name' => 'generate_image',
            'description' => 'ユーザーが画像・イラスト・ロゴ・図などの生成/作成/描画を求めたときに呼ぶ。文章の説明だけを求めている場合は呼ばない。既存の画像を加工する場合は edit_image を使う。',
            'parameters' => [
                'type' => 'object',
                'properties' => ['prompt' => ['type' => 'string', 'description' => '生成する画像の詳細な説明（英語推奨、なければ日本語）']],
                'required' => ['prompt'],
            ],
        ];
        // アップロード済み/生成済みの画像があるときだけ「編集」ツールを出す
        if ($imgFileIds) {
            $tools[] = [
                'type' => 'function',
                'name' => 'edit_image',
                'description' => 'アップロード済み（または直前に生成した）画像を編集・加工・修正するときに呼ぶ。背景変更、要素の追加/削除、色やスタイルの変更など。まったく新規に一から作る場合は generate_image を使う。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['prompt' => ['type' => 'string', 'description' => '画像にどんな変更を加えるかの具体的な指示（英語推奨）']],
                    'required' => ['prompt'],
                ],
            ];
        }
    }
    return compact('tools', 'genUnlimited', 'canGen', 'genNote', 'webNote');
}

/** OpenAI へ送る中身。 言い添えはここで 1 本にまとめる。 */
function chat_build_payload(string $model, array $input, array $tc, array $toolset, string $dataInstr): array {
    $tools = $toolset['tools']; $genNote = $toolset['genNote']; $webNote = $toolset['webNote'];
    // 言語混線対策: たまに「だけ」がヘブライ文字(בלבד)になる等の他言語混入を防ぐ
    $langNote = '（回答は自然な日本語で統一し、意図せず他言語の文字（ヘブライ文字・アラビア文字・ハングル等）の単語を混ぜないこと。日本語にすべき語は必ず日本語で書く。ユーザーが特定言語での回答を求めた場合のみその言語を使う。）';

    
    $payload = [
        'model'             => $model,
        'input'             => $input,
        'instructions'      => $tc['system_prompt'] . $langNote . $genNote . $webNote . $dataInstr,
        'stream'            => true,
        'store'             => false,
        'max_output_tokens' => (int)($tc['max_tokens'] ?? 2000),
    ];
    if ($tools) { $payload['tools'] = $tools; $payload['tool_choice'] = 'auto'; }
    return $payload;
}
