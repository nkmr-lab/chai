<?php
/**
 * C:\Dropbox\Programs\Claude\chai\lib\conv.php
 *   -> /var/www/chai/lib/conv.php
 *
 * 会話そのもの。 見て良いかの判定と、 内容からの分類。
 * まとめて読み込むのは lib.php。 個別に require しない。
 */
declare(strict_types=1);

/** 会話内容を短いカテゴリ名に自動分類して保存。既存カテゴリを優先再利用。返り値=カテゴリ。 */
function classify_conversation(int $convId, string $email): ?string {
    global $CFG;
    $conv = conv_owned($convId, $email);
    if (!$conv) return null;

    $st = db()->prepare("SELECT role, content FROM messages WHERE conversation_id = ? AND role IN ('user','assistant') ORDER BY id ASC LIMIT 4");
    $st->execute([$convId]);
    $rows = $st->fetchAll();
    if (!$rows) return null;
    $snippet = '';
    foreach ($rows as $r) $snippet .= ($r['role'] === 'user' ? 'U: ' : 'A: ') . mb_substr(preg_replace('/\s+/u', ' ', (string)$r['content']), 0, 300) . "\n";

    $cs = db()->prepare("SELECT DISTINCT category FROM conversations WHERE email = ? AND category IS NOT NULL AND category <> '' ORDER BY updated_at DESC LIMIT 30");
    $cs->execute([$email]);
    $existing = array_values(array_filter(array_column($cs->fetchAll(), 'category')));

    $sys = 'あなたは会話を分類する係です。会話内容を表す簡潔なカテゴリ名を日本語で1つだけ返します。'
         . '規則: 6〜10文字程度の名詞句 / できるだけ既存カテゴリを再利用 / 記号や説明を付けずカテゴリ名だけ出力。';
    $user = ($existing ? '既存カテゴリ: ' . implode(' / ', $existing) . "\n\n" : '')
          . "会話:\n" . $snippet . "\nカテゴリ名:";

    $oa = $CFG['openai'];
    $payload = ['model' => 'gpt-4o-mini', 'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]], 'max_completion_tokens' => 20];
    $ch = curl_init(rtrim($oa['base_url'], '/') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $oa['api_key']],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$res, true);
    $cat = trim((string)($j['choices'][0]['message']['content'] ?? ''));
    $cat = preg_replace('/[\r\n].*$/s', '', $cat);
    // 前後の空白・引用符・記号を除去。trim() はバイト単位で全角文字を壊すため、Unicode対応の正規表現で行う
    $cat = preg_replace('/^[\s"\'。.：:・\-　]+|[\s"\'。.：:・\-　]+$/u', '', (string)$cat);
    $cat = mb_substr($cat, 0, 24);
    if ($cat === '') $cat = 'その他';
    db()->prepare("UPDATE conversations SET category = ? WHERE id = ? AND email = ?")->execute([$cat, $convId, $email]);
    return $cat;
}

/** 会話の所有者チェック付き取得。 */
function conv_owned(int $id, string $email): ?array {
    $st = db()->prepare("SELECT * FROM conversations WHERE id = ? AND email = ?");
    $st->execute([$id, $email]);
    return $st->fetch() ?: null;
}

/** 共有を含むアクセス判定。返り値 ['conv'=>row,'perm'=>'owner'|'write'|'read'] or null。 */
function conv_access(int $id, array $me): ?array {
    $st = db()->prepare("SELECT * FROM conversations WHERE id = ?");
    $st->execute([$id]);
    $conv = $st->fetch();
    if (!$conv) return null;
    if (strtolower((string)$conv['email']) === strtolower((string)$me['email'])) return ['conv' => $conv, 'perm' => 'owner'];
    $g = grantee_keys($me);
    $ph = implode(',', array_fill(0, count($g), '?'));
    $s = db()->prepare("SELECT permission FROM shares WHERE conversation_id = ? AND grantee IN ($ph) ORDER BY (permission='write') DESC LIMIT 1");
    $s->execute(array_merge([$id], $g));
    $perm = $s->fetchColumn();
    return $perm ? ['conv' => $conv, 'perm' => (string)$perm] : null;
}

/** 自分にマッチしうる grantee キー（email / nkmr username / 全員）。 */
function grantee_keys(array $me): array {
    return array_values(array_unique(array_filter([
        strtolower((string)($me['email'] ?? '')),
        strtolower((string)($me['user'] ?? '')),
        '*',
    ])));
}
