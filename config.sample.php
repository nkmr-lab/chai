<?php
/**
 * config.sample.php — chai.nkmr.io 設定テンプレート。
 * 本番では config.local.php にコピーして値を埋める（config.local.php は .gitignore 済み）。
 *   cp config.sample.php config.local.php   （サーバ上でのみ）
 *
 * このファイルは「単一の設定ソース」。モデル名・料金・ティア制限は全部ここで調整する。
 */
return [
    // ── データベース（nkmr-dev ローカル MariaDB） ─────────────────
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=chai;charset=utf8mb4',
        'user' => 'chai',
        'pass' => 'CHANGE_ME',
    ],

    // ── OpenAI ────────────────────────────────────────────────
    'openai' => [
        'api_key'  => 'sk-CHANGE_ME',
        'base_url' => 'https://api.openai.com/v1',
        // 組織/プロジェクトを分けている場合のみ（不要なら null）
        'org'      => null,
        'project'  => null,
    ],

    // ── ティア別の体験（自由度）。加入中=pro / 失効・未加入=free ──────
    'tiers' => [
        'pro' => [
            'label'            => 'メンバー',
            'system_prompt'    => 'あなたは中村研究室のメンバーを支援する有能なアシスタント「chai」です。丁寧かつ簡潔に、必要なら具体例を交えて答えてください。',
            'history_messages' => 40,                  // 文脈に載せる直近メッセージ数
            'max_tokens'       => 16000,               // 応答の最大トークン（推論＋コード実行に余裕）
            'daily_message_cap'=> 0,                   // 0 = 無制限
            'allow_images'     => true,                // 画像/PDF 入力
            'allow_image_gen'  => true,                // 画像生成
            'allow_web_search' => true,                // Web検索（必要時にモデルが自動で検索）
            'allow_data_analysis' => true,             // CSV/xlsx をPythonで分析（code_interpreter）
        ],
        'free' => [
            'label'            => 'おためし',
            'system_prompt'    => 'あなたは中村研究室のアシスタント「chai」です。簡潔に答えてください。',
            'history_messages' => 8,                    // 文脈を短く（メンバーは長い）
            'max_tokens'       => 2000,
            'window_hours'     => 8,                    // 制限のリセット時間窓（8時間ごと）
            'message_cap'      => 10,                    // 8時間あたり10通まで
            'allow_images'     => false,                // ファイル無制限は不可（下のお試し枠は別）
            'file_limit'       => 1,                    // ファイル読み込みを8時間に1回だけお試し
            'allow_image_gen'  => false,                // 画像生成 無制限は不可
            'image_gen_limit'  => 1,                    // 画像生成を8時間に1回だけお試し
            'allow_web_search' => false,                // Web検索は不可
        ],
    ],

    // ── 選べるモデル（実在確認済み）。id => [表示名, 使える最低ティア, vision可] ──
    // free ユーザには tier=free のものだけ表示。pro は全部。
    // 5.6 をベースに。非会員も 5.6 を使える（下げない）。o3 だけメンバー特典。
    'models' => [
        'gpt-6-astra'  => ['label' => 'GPT-6 Astra（最新・高精度／メンバー限定）', 'tier' => 'pro', 'vision' => true],
        'gpt-5.6-sol'  => ['label' => 'GPT-5.6 Sol（高精度）',   'tier' => 'free', 'vision' => true],
        'gpt-5.6-terra'=> ['label' => 'GPT-5.6 Terra（バランス）', 'tier' => 'free', 'vision' => true],
        'gpt-5.6-luna' => ['label' => 'GPT-5.6 Luna（高速・安価）', 'tier' => 'free', 'vision' => true],
        'o3'           => ['label' => 'o3（じっくり推論）',       'tier' => 'pro',  'vision' => true],
    ],
    'default_model' => ['pro' => 'gpt-5.6-luna', 'free' => 'gpt-5.6-luna'],   // 既定は安価なLuna。Astra/Solは選択式(Astraは約50倍高い)

    // ── 画像生成 ───────────────────────────────────────────────
    // model が失敗（未認証403等）したら fallback を自動で試す。
    // chatgpt-image-latest(=最新の「Image」系)は要・組織verification。認証すればそのまま最新に切替わる。
    'image_gen' => [
        'model'    => 'chatgpt-image-latest', // 最新。要OpenAI組織認証（未認証なら下のfallbackで動く）
        'fallback' => 'gpt-image-1.5',        // 日本語◎かつ高速（~13秒）
        'size'     => '1024x1024',
        'quality'  => 'medium',               // gpt-image系にのみ適用（chatgpt-image系には送らない）
    ],

    // ── アップロード制限 ─────────────────────────────────────
    'upload' => [
        'max_image_mb'   => 20,     // スマホ写真は大きめなので余裕を持たせる
        'max_pdf_mb'     => 100,    // 大きいPDFは chat.php で ~30MB/95ページ単位に分割して読む（qpdf）
        'max_data_mb'    => 80,      // CSV/xlsx の上限（大きめ）
        'pdf_text_chars' => 40000,   // PDFから抽出するテキストの上限
    ],

    // ── サブスク（週額パス） ────────────────────────────────────
    'subscription' => [
        'price_points'  => 500,   // 1回の課金ポイント
        'period_days'   => 7,     // 有効期間（週額）
        'auto_renew'    => true,  // 期限切れ後、アクティビティ時に自動更新を試みる既定
    ],

    // ── LabPay 連携（pay.nkmr.io）── サブスクの本体は LabPay 側 ──────
    // chai は契約有無を /api/ai-sub/check に照会するだけ（受信SSO cookieを転送）。
    'labpay' => [
        'base_url'      => 'https://pay.nkmr.io',
        'check_path'    => '/api/ai-sub/check',        // 契約照会エンドポイント
        'subscribe_url' => 'https://pay.nkmr.io/#/ai-sub', // 契約導線（LabPayへ誘導）
        'cache_ttl'     => 60,                           // 照会結果のキャッシュ秒数(短め=解約が早く反映)
    ],

    // ── 管理者（要望の全件閲覧・ステータス管理）。email または nkmr username ──
    'admins' => ['nakamura.satoshi@gmail.com'],

    // ── 動作モード ────────────────────────────────────────────
    'debug' => false,
];
