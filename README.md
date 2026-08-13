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
