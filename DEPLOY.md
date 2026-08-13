# chai.nkmr.io デプロイ手順（nkmr-dev）

素の PHP8 + Vanilla JS + MariaDB。ビルド不要。`/var/www/html/chai/` に置いて vhost を足すだけ。

## 前提（nkmr-dev 側で確認）
- PHP 8.x（sodium 拡張=SSO検証に必須 / curl 拡張=OpenAI・LabPay呼び出しに必須）
- MariaDB（localhost）
- Apache（wildcard `*.nkmr.io` 証明書あり → 新規サブドメインは vhost 追加のみ）

## 1) 事前に決める/用意するもの（★=中村さん判断が必要）
- ★ **OpenAI APIキー** … nkmr-dev の `pen/pdf_audio/config.local.php` に既存キーあり。流用 or 新規。
- ★ **chai 用 LabPay 受取口座** … LabPay(pay.nkmr.io)に chai 専用アカウントを作り、その `users.id` を控える。
      - allowlist にメール登録 → 一度ログインさせて users 行を作る → 管理画面/DBで `users.id` を確認。
- ★ **入金照合用の Bearer セッション（任意・推奨）** … chai 口座でログインして得た `labpay_sid`。
      あると `/api/me/transactions` で入金を照合し不正申告を弾ける。未設定なら暫定信頼で動く。
- DB パスワード（新規）

## 2) DB 作成（nkmr-dev 上）
```sql
CREATE DATABASE chai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'chai'@'127.0.0.1' IDENTIFIED BY '<PASS>';
GRANT ALL PRIVILEGES ON chai.* TO 'chai'@'127.0.0.1';
FLUSH PRIVILEGES;
```
```bash
mysql -u chai -p chai < /var/www/html/chai/schema.sql
```

## 3) ファイル配置（PowerShell から scp。Git Bash は鍵を拾えないので不可）
```powershell
# ローカル C:\Dropbox\Programs\Claude\chai\ → nkmr-dev:/var/www/html/chai/
# （config.local.php は含めない。サーバ上で作る）
scp -r C:\Dropbox\Programs\Claude\chai\* <user>@nkmr-dev:/var/www/html/chai/
```

## 4) config.local.php をサーバ上で作成
```bash
cd /var/www/html/chai
cp config.sample.php config.local.php
# 編集して埋める:
#   db.pass, openai.api_key, labpay.chai_user_id,（推奨）labpay.chai_session_id
# モデル名 tiers.pro.model / tiers.free.model も実在モデルに合わせて調整
```

## 5) Apache vhost（chai.nkmr.io）
`/etc/httpd/conf.d/` に `chai.conf`（wildcard-userdir より前に読まれる名前推奨、例 `00-chai.conf`）:
```apache
<VirtualHost *:80>
  ServerName chai.nkmr.io
  RewriteEngine On
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</VirtualHost>
<VirtualHost *:443>
  ServerName chai.nkmr.io
  DocumentRoot /var/www/html/chai
  DirectoryIndex index.php
  <Directory /var/www/html/chai>
    AllowOverride All
    Require all granted
  </Directory>
  SSLEngine on
  SSLCertificateFile    /etc/letsencrypt/live/wildcard.nkmr.io/fullchain.pem
  SSLCertificateKeyFile /etc/letsencrypt/live/wildcard.nkmr.io/privkey.pem
  Header always set Strict-Transport-Security "max-age=31536000"
  Header always set X-Content-Type-Options nosniff
  # SSE のバッファ無効化（ストリーミング表示のため）
  <Location />
    SetEnv no-gzip 1
  </Location>
</VirtualHost>
```
DNS: `chai.nkmr.io` を nkmr-dev の A レコードへ（wildcard `*.nkmr.io` があれば不要な場合も）。
```bash
apachectl configtest && systemctl reload httpd
```

## 6) 動作確認
1. https://chai.nkmr.io → SSO でログイン → チャット画面
2. 何か送信 → ストリーミング表示されるか
3. 「プラン」→「500pt でメンバーになる」→ LabPay 送金 → pro に切替わるか
4. 無料ティアで1日上限（既定10通）に当たるか

## 注意
- `config.local.php` は **絶対に git に入れない**（.gitignore 済み）。
- LabPay は「本人ブラウザからの送金」しかできない設計のため、真の無人 cron 課金は不可。
  本実装は「期限切れ後、次回利用時に自動更新を試みる」方式（＝残高切れで自動失効）。
  完全無人課金が必要になったら LabPay 側に継続課金(マンデート)API を新設すること。
