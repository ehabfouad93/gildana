-- AI Studio — initial schema

CREATE TABLE IF NOT EXISTS users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(120)  NOT NULL DEFAULT '',
    email          VARCHAR(190)  NOT NULL UNIQUE,
    password_hash  VARCHAR(255)  NOT NULL,
    role           ENUM('admin','member') NOT NULL DEFAULT 'member',
    status         ENUM('active','disabled') NOT NULL DEFAULT 'active',
    last_login_at  DATETIME NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(64)  NOT NULL,
    email        VARCHAR(190) NOT NULL,
    attempts     INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ip_email (ip, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Encrypted provider credentials, one row per vendor group.
CREATE TABLE IF NOT EXISTS provider_keys (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vendor          VARCHAR(40) NOT NULL UNIQUE,   -- openai|anthropic|stability|kling|meta
    credentials_enc MEDIUMTEXT NOT NULL,           -- AES-GCM( json )
    updated_by      INT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A campaign holds the brief and the user's chosen module per stage.
CREATE TABLE IF NOT EXISTS campaigns (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    name          VARCHAR(190) NOT NULL,
    product       VARCHAR(255) NOT NULL DEFAULT '',
    audience      VARCHAR(255) NOT NULL DEFAULT '',
    goal          VARCHAR(255) NOT NULL DEFAULT '',
    key_message   TEXT NULL,
    tone          VARCHAR(160) NOT NULL DEFAULT '',
    cta           VARCHAR(160) NOT NULL DEFAULT '',
    language      VARCHAR(60)  NOT NULL DEFAULT 'English',
    picks_json    TEXT NULL,          -- {stage: {module_id, model, note}}
    status        ENUM('draft','generating','ready','archived') NOT NULL DEFAULT 'draft',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every generated asset (copy text, image, design markup, video).
CREATE TABLE IF NOT EXISTS assets (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id   INT NOT NULL,
    stage         VARCHAR(40)  NOT NULL,   -- copy|design|video
    module_id     VARCHAR(60)  NOT NULL,
    vendor        VARCHAR(40)  NOT NULL,
    kind          ENUM('text','image','video','design') NOT NULL,
    status        ENUM('ready','pending','failed') NOT NULL DEFAULT 'ready',
    title         VARCHAR(190) NOT NULL DEFAULT '',
    text_content  LONGTEXT NULL,
    rel_path      VARCHAR(255) NULL,       -- path under storage/
    url           VARCHAR(500) NULL,       -- public URL
    mime          VARCHAR(100) NULL,
    bytes         INT NOT NULL DEFAULT 0,
    error         VARCHAR(500) NOT NULL DEFAULT '',
    meta_json     TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_campaign (campaign_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Async generation jobs (currently Kling video). The cron worker finalizes them.
CREATE TABLE IF NOT EXISTS jobs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id  INT NOT NULL,
    asset_id     INT NOT NULL,
    vendor       VARCHAR(40) NOT NULL,
    task_id      VARCHAR(190) NOT NULL,
    model        VARCHAR(80) NOT NULL DEFAULT '',
    status       ENUM('processing','succeeded','failed') NOT NULL DEFAULT 'processing',
    attempts     INT NOT NULL DEFAULT 0,
    error        VARCHAR(500) NOT NULL DEFAULT '',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_status (status),
    KEY idx_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Publish log: which asset went to which channel.
CREATE TABLE IF NOT EXISTS publications (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    asset_id     INT NOT NULL,
    campaign_id  INT NOT NULL,
    module_id    VARCHAR(60) NOT NULL,   -- meta_facebook | meta_instagram
    user_id      INT NOT NULL,
    caption      TEXT NULL,
    status       ENUM('published','failed') NOT NULL DEFAULT 'published',
    post_id      VARCHAR(190) NOT NULL DEFAULT '',
    permalink    VARCHAR(500) NOT NULL DEFAULT '',
    error        VARCHAR(500) NOT NULL DEFAULT '',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_asset (asset_id),
    KEY idx_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
