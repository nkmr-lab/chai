-- chai.nkmr.io スキーマ（MariaDB / InnoDB / utf8mb4）
-- 適用: mysql -u root chai < schema.sql   （DB/ユーザは事前に作成）
--
--   CREATE DATABASE chai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   CREATE USER 'chai'@'127.0.0.1' IDENTIFIED BY '****';
--   GRANT ALL PRIVILEGES ON chai.* TO 'chai'@'127.0.0.1';

SET NAMES utf8mb4;

-- 会話（スレッド） -------------------------------------------------
CREATE TABLE IF NOT EXISTS conversations (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(255)    NOT NULL,           -- SSO の所有者メール
  title       VARCHAR(255)    NOT NULL DEFAULT '新しいチャット',
  category    VARCHAR(64)     NULL,               -- 内容から自動分類したカテゴリ
  model       VARCHAR(64)     NULL,               -- 作成時のモデル（参考）
  archived    TINYINT(1)      NOT NULL DEFAULT 0,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_updated (email, archived, updated_at),
  KEY idx_email_cat (email, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- メッセージ -------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id  BIGINT UNSIGNED NOT NULL,
  role             ENUM('user','assistant','system') NOT NULL,
  content          MEDIUMTEXT      NOT NULL,
  author_email     VARCHAR(255)    NULL,          -- 書き込んだ人（共有チャット用。NULL=所有者）
  author_name      VARCHAR(255)    NULL,
  model            VARCHAR(64)     NULL,
  prompt_tokens    INT             NULL,
  completion_tokens INT            NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_conv (conversation_id, id),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id)
      REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- サブスク照会キャッシュ（真実の源は LabPay。ここは短期キャッシュのみ） --
CREATE TABLE IF NOT EXISTS sub_cache (
  email       VARCHAR(255)    NOT NULL,
  active      TINYINT(1)      NOT NULL DEFAULT 0,
  status      VARCHAR(16)     NOT NULL DEFAULT 'never', -- active|graceful|expired|never|unauth|unknown
  expires_at  DATETIME        NULL,
  days_left   INT             NULL,
  checked_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1日の利用カウント（無料ティアの上限用） ---------------------------
CREATE TABLE IF NOT EXISTS usage_daily (
  email       VARCHAR(255)    NOT NULL,
  day         DATE            NOT NULL,
  message_count INT           NOT NULL DEFAULT 0,
  PRIMARY KEY (email, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 機能別の1日カウント（おためしのお試し枠: file / imggen など） --------
CREATE TABLE IF NOT EXISTS usage_feature (
  email    VARCHAR(255) NOT NULL,
  day      DATE         NOT NULL,
  feature  VARCHAR(24)  NOT NULL,
  cnt      INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (email, day, feature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 要望・不具合（システム更新要望の受付。管理者=中村が管理） --------------
CREATE TABLE IF NOT EXISTS feedback (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(255)    NOT NULL,
  name        VARCHAR(255)    NULL,
  kind        ENUM('request','bug','other') NOT NULL DEFAULT 'request',
  text        MEDIUMTEXT      NOT NULL,
  status      ENUM('new','planned','doing','done','declined') NOT NULL DEFAULT 'new',
  admin_note  VARCHAR(500)    NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_status (status, created_at),
  KEY idx_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 共有（会話を他ユーザーに閲覧/書き込み許可） ----------------------------
CREATE TABLE IF NOT EXISTS shares (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  grantee         VARCHAR(255)    NOT NULL,        -- 相手のemail or nkmr username、'*'=全員
  permission      ENUM('read','write') NOT NULL DEFAULT 'read',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conv_grantee (conversation_id, grantee),
  KEY idx_grantee (grantee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ストック（返信/選択部分の切り抜き保存） --------------------------------
CREATE TABLE IF NOT EXISTS stocks (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(255)    NOT NULL,
  conversation_id BIGINT UNSIGNED NULL,
  source_msg_id   BIGINT UNSIGNED NULL,
  text            MEDIUMTEXT      NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ピンセット（名前付きのピン集合＝まとめて開くワークスペース） -----------
CREATE TABLE IF NOT EXISTS pin_sets (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(255)    NOT NULL,
  name       VARCHAR(120)    NOT NULL,
  chat_ids   TEXT            NOT NULL,           -- JSON配列の会話id
  sort_order INT             NOT NULL DEFAULT 0, -- 並び順（小さいほど上）
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_name (email, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ブックマーク（「あとで見る」。ピン=横並び表示とは別の、単なる保存リスト） -----
CREATE TABLE IF NOT EXISTS bookmarks (
  email           VARCHAR(255)    NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (email, conversation_id),
  KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TODO（チャットごとの〆切＋完了チェック。ブックマークとは別の「やること」リスト） -----
CREATE TABLE IF NOT EXISTS todos (
  email           VARCHAR(255)    NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  due             DATE            NULL,          -- 〆切（任意）
  done            TINYINT         NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (email, conversation_id),
  KEY idx_email_done (email, done)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 会話に紐づくデータ分析ファイル（OpenAI file_id を保持し毎ターン再マウント） ---
CREATE TABLE IF NOT EXISTS conv_files (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  message_id      BIGINT UNSIGNED NULL,          -- どのユーザ発話が上げたか（削除連動用）
  file_id         VARCHAR(80)     NOT NULL,
  name            VARCHAR(255)    NULL,
  kind            VARCHAR(16)     NOT NULL DEFAULT 'data',  -- data=CSV/xlsx, doc=PDF
  hash            CHAR(64)        NULL,          -- 内容ハッシュ（重複排除の参照キー）
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_conv (conversation_id),
  KEY idx_msg (message_id),
  KEY idx_fileid (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 同一内容ファイルの実体は1つだけ保持（hash→OpenAI file_id）。conv_files が参照する。
CREATE TABLE IF NOT EXISTS file_blobs (
  hash        CHAR(64)        NOT NULL,
  file_id     VARCHAR(80)     NOT NULL,
  size        BIGINT UNSIGNED NULL,
  mime        VARCHAR(120)    NULL,
  name        VARCHAR(255)    NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (hash),
  KEY idx_fileid (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 利用イベント（ローリング時間窓の集計用: msg / file / imggen） ---------
CREATE TABLE IF NOT EXISTS usage_events (
  id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email    VARCHAR(255)    NOT NULL,
  feature  VARCHAR(24)     NOT NULL,
  at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ewf (email, feature, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
