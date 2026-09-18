# chai.nkmr.io

中村研向けの ChatGPT / Claude 風 AI チャット。バックエンドは OpenAI API。
体験（ストリーミング・会話履歴・Markdown/コード表示）は本家に寄せる。
利用は **LabPay の週額パス（500pt / 7日）** を購入したメンバーがフル機能、
未購入・失効中は「おためし」ティア（軽量モデル・1日上限・短い文脈）。

## 構成（素の PHP + Vanilla JS + MariaDB、ビルド不要）
| ファイル | 役割 |
|---|---|
| `index.php` | SPA シェル。nkmrauth で SSO 必須ゲート、初期状態を注入 |
| `api.php` | JSON API：会話CRUD・状態取得 |
| `chat.php` | OpenAI へのストリーミング中継（SSE）。ティアでモデル/上限を制御 |
| `billing.php` | 週額パスの intent→confirm。サブスク延長・自動更新ON/OFF |
| `lib.php` | 認証・JSON・サブスク/ティア判定・利用量カウント |
| `db.php` | 設定ロード + PDO（単一ソース） |
| `nkmrauth.php` | nkmr 共通認証(SSO)ドロップイン |
| `config.sample.php` | 設定テンプレ（→ `config.local.php` にコピーして本番値） |
| `schema.sql` | DB スキーマ |
| `assets/app.js`, `assets/styles.css` | フロント本体 / スタイル |

## ティア（すべて config.local.php で調整可能）
- **メンバー(pro)**: 上位モデル・無制限・長い文脈・画像OK
- **おためし(free)**: 軽量モデル・1日N通・短い文脈・画像なし

## 課金の仕組み（重要な設計上の制約）
LabPay は「ログイン中の本人ブラウザからの送金」しかできず、サーバが他人の残高を
勝手に引く手段がない。そこで:
1. 本人ブラウザが `pay.nkmr.io/api/transfers` で 500pt を chai 口座へ送金
2. chai が入金を（可能なら）照合し、サブスクを +7日 延長
3. 期限切れ後、次回利用時に自動更新を試行。残高不足なら失効し「おためし」へ

→ 完全無人の毎週自動引き落としは LabPay 現状では不可。将来やるなら LabPay に
継続課金(マンデート)API を新設する（別プロジェクト）。

## デプロイ
`DEPLOY.md` 参照（nkmr-dev / `/var/www/html/chai/`）。

## 置きかた (2026-09-18 の整理後)

```
/var/www/chai/
  index.php            画面の外枠 (ログインを通して骨組みを出す)
  api.php              JSON API の入口。action => 関数 の表だけ
  chat.php             返事を作って流すところ。9 段の手順書
  media.php / media/   画像などの配布と置き場
  handlers/            api.php の action ごとの中身 (state / conversations / messages /
                       feedback / shares / stocks / bookmarks / todos / pinsets)
  lib.php              共通ヘルパの読み込み口 (中身は lib/)
  lib/
    http.php           JSON で返す / ログインを確かめる / 本文を読む
    tier.php           契約とティア (真実の源は LabPay)
    usage.php          使った量の記録
    openai_files.php   OpenAI のファイル置き場
    images.php         画像の生成と保存
    conv.php           会話の見て良い判定と分類
    sse.php            少しずつ流すための下ごしらえ
    chat/              chat.php の手順ごとの中身
      limits.php       ティアとモデルを決める
      uploads.php      添付を上げる
      conversation.php 会話を用意して発言を残す
      request.php      渡すもの (話・道具・言い添え) を組み立てる
      stream.php       受けながら流す
      finish.php       画像や図表を足して、保存して締める
  assets/styles.css
  assets/js/           画面。ES モジュール。index.php は app.js 1 本だけ読む
                       util / embed / api / md / state / dom / pane / panes / convs /
                       bookmarks / todos / pinsets / tier / stocks / share / feedback / app
  tests/               テスト一式 (下記)
```

**モジュールの読み込み**: 入口の `app.js` には `?v=<版>` が付くが、その中の `import` には
付かない。古い部品が混ざらないよう、vhost で `/assets/js/` に `Cache-Control: no-cache`。

## テスト

本番 DB と本物の OpenAI には繋がない。触るのは `chai_test` DB と偽 OpenAI だけ。

```
sudo -u apache bash /var/www/chai/tests/e2e.sh     # API 95 + 画面/SSE 23
NODE_PATH=<jsdomを入れた場所>/node_modules node tests/ui_test.js   # 画面 18 (手元で)
```

- `tests/run_tests.php` … API 30 アクションを HTTP 越しに
- `tests/stub_openai.php` … 偽 OpenAI。本物と同じそっけなさで返す
- `tests/ui_test.js` … jsdom。本番と同じモジュールを読み、骨組みは index.php から取る
- `tests/config.test.php` … DB を chai_test に、OpenAI を偽サーバに向けるだけ

`auth.test_identity_header` は**本番の config.local.php には無い**(= 抜け道は死んでいる)。
e2e が毎回それを確かめる。
