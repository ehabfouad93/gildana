-- ─────────────────────────────────────────────────────────────
-- Gildana Listening — initial schema
-- Tenancy: `clients` IS the tenant (one brand account per client).
-- Every child table carries client_id with ON DELETE CASCADE.
-- ─────────────────────────────────────────────────────────────

-- One brand account. All third-party credentials live here, encrypted.
CREATE TABLE IF NOT EXISTS clients (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(160) NOT NULL,
    brand_name          VARCHAR(160) NOT NULL DEFAULT '',
    website             VARCHAR(255) NOT NULL DEFAULT '',
    default_lang        VARCHAR(8)   NOT NULL DEFAULT 'ar',   -- ar|en|both: search hint for connectors
    default_country     VARCHAR(8)   NOT NULL DEFAULT 'EG',
    locale              VARCHAR(5)   NOT NULL DEFAULT 'en',   -- default UI language for this client's users

    -- AI sentiment escalation. Column names match wa-dashboard so includes/ai.php drops in unchanged.
    ai_provider         VARCHAR(12)  NOT NULL DEFAULT '',     -- ''|claude|openai
    ai_model            VARCHAR(64)  NOT NULL DEFAULT '',
    ai_api_key_enc      TEXT         NULL,
    ai_daily_cap        INT          NOT NULL DEFAULT 500,    -- max AI classifications per day
    ai_calls_today      INT          NOT NULL DEFAULT 0,
    ai_calls_day        DATE         NULL,                    -- the day ai_calls_today counts

    -- Optional paid aggregator (SerpApi-style), per client so cost is per client.
    aggregator_vendor   VARCHAR(24)  NOT NULL DEFAULT '',     -- ''|serpapi
    aggregator_key_enc  TEXT         NULL,

    -- YouTube Data API v3 server key, per client so the daily quota is theirs.
    youtube_key_enc     TEXT         NULL,

    -- Official Meta Graph — only pages/accounts the client OWNS.
    meta_page_id        VARCHAR(64)  NOT NULL DEFAULT '',
    meta_ig_user_id     VARCHAR(64)  NOT NULL DEFAULT '',
    meta_token_enc      TEXT         NULL,
    meta_token_updated_at DATETIME   NULL,

    alert_email         VARCHAR(190) NOT NULL DEFAULT '',
    digest_freq         ENUM('off','daily','weekly') NOT NULL DEFAULT 'off',
    last_digest_at      DATETIME     NULL,

    status              VARCHAR(16)  NOT NULL DEFAULT 'active',  -- active|disabled
    created_at          DATETIME     NOT NULL,
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Logins. role 'admin' has client_id NULL and sees every client.
CREATE TABLE IF NOT EXISTS users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT          NULL,
    name           VARCHAR(120) NOT NULL DEFAULT '',
    email          VARCHAR(190) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    role           VARCHAR(16)  NOT NULL DEFAULT 'client',   -- admin|client
    locale         VARCHAR(5)   NOT NULL DEFAULT 'en',
    status         VARCHAR(16)  NOT NULL DEFAULT 'active',
    last_login_at  DATETIME     NULL,
    created_at     DATETIME     NOT NULL,
    UNIQUE KEY uq_email (email),
    KEY idx_client (client_id),
    CONSTRAINT fk_users_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Login brute-force throttle (ip + email).
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(64)  NOT NULL,
    email        VARCHAR(190) NOT NULL,
    attempts     INT          NOT NULL DEFAULT 0,
    locked_until DATETIME     NULL,
    updated_at   DATETIME     NOT NULL,
    UNIQUE KEY uq_ip_email (ip, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What we listen for.
CREATE TABLE IF NOT EXISTS keywords (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    client_id     INT NOT NULL,
    term          VARCHAR(190) NOT NULL,
    kind          ENUM('brand','competitor','keyword','hashtag') NOT NULL DEFAULT 'keyword',
    match_mode    ENUM('phrase','all_words','exact') NOT NULL DEFAULT 'phrase',
    required_json TEXT NULL,   -- ["Egypt"]  → at least one must appear
    excluded_json TEXT NULL,   -- ["recipe"] → any hit drops the mention
    lang          VARCHAR(8) NOT NULL DEFAULT '',
    country       VARCHAR(8) NOT NULL DEFAULT '',
    status        ENUM('active','paused') NOT NULL DEFAULT 'active',
    created_at    DATETIME NOT NULL,
    UNIQUE KEY uq_client_term (client_id, term),
    KEY idx_client_active (client_id, status),
    CONSTRAINT fk_kw_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One enabled connector instance. next_fetch_at makes this table the fetch queue,
-- so there is no separate jobs table to leak rows.
CREATE TABLE IF NOT EXISTS sources (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    client_id            INT NOT NULL,
    connector            VARCHAR(32) NOT NULL,   -- google_news|bing_news|reddit|youtube|rss|serpapi|meta_page|meta_ig
    label                VARCHAR(160) NOT NULL DEFAULT '',
    keyword_id           INT NULL,               -- NULL = run once per active keyword
    config_json          TEXT NULL,              -- {"hl":"ar","gl":"EG","feed_url":"…","subreddit":"…"}
    fetch_interval_min   INT NOT NULL DEFAULT 30,
    next_fetch_at        DATETIME NULL,          -- authoritative scheduler column
    last_fetched_at      DATETIME NULL,
    last_ok_at           DATETIME NULL,
    last_status          ENUM('never','ok','empty','error','throttled') NOT NULL DEFAULT 'never',
    last_error           VARCHAR(500) NOT NULL DEFAULT '',
    last_item_count      INT NOT NULL DEFAULT 0,
    consecutive_failures INT NOT NULL DEFAULT 0,
    etag                 VARCHAR(190) NOT NULL DEFAULT '',   -- conditional GET
    last_modified        VARCHAR(190) NOT NULL DEFAULT '',
    status               ENUM('active','paused','error') NOT NULL DEFAULT 'active',
    created_at           DATETIME NOT NULL,
    KEY idx_due (status, next_fetch_at),
    KEY idx_client (client_id, connector),
    CONSTRAINT fk_src_client  FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE CASCADE,
    CONSTRAINT fk_src_keyword FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The core table. One row per unique piece of content mentioning a tracked term.
CREATE TABLE IF NOT EXISTS mentions (
    id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id            INT NOT NULL,
    source_id            INT NULL,
    keyword_id           INT NULL,
    connector            VARCHAR(32) NOT NULL,
    platform             VARCHAR(24) NOT NULL DEFAULT 'web',  -- news|reddit|youtube|facebook|instagram|blog|web
    external_id          VARCHAR(190) NOT NULL DEFAULT '',
    url                  VARCHAR(1000) NOT NULL DEFAULT '',
    url_hash             CHAR(40) NOT NULL,                   -- sha1(canonical url) or sha1(connector:external_id)
    domain               VARCHAR(190) NOT NULL DEFAULT '',
    title                VARCHAR(500) NOT NULL DEFAULT '',
    content              MEDIUMTEXT NULL,
    snippet              VARCHAR(600) NOT NULL DEFAULT '',
    image_url            VARCHAR(1000) NOT NULL DEFAULT '',
    author_name          VARCHAR(190) NOT NULL DEFAULT '',
    author_handle        VARCHAR(190) NOT NULL DEFAULT '',
    author_url           VARCHAR(500) NOT NULL DEFAULT '',
    author_followers     INT NOT NULL DEFAULT 0,
    lang                 VARCHAR(8) NOT NULL DEFAULT '',
    country              VARCHAR(8) NOT NULL DEFAULT '',
    published_at         DATETIME NULL,                       -- UTC
    fetched_at           DATETIME NOT NULL,
    reach                INT NOT NULL DEFAULT 0,
    likes                INT NOT NULL DEFAULT 0,
    comments_count       INT NOT NULL DEFAULT 0,
    shares               INT NOT NULL DEFAULT 0,
    views                INT NOT NULL DEFAULT 0,
    engagement           INT NOT NULL DEFAULT 0,              -- likes+comments+shares, denormalized for ORDER BY

    sentiment            ENUM('positive','negative','neutral','unknown') NOT NULL DEFAULT 'unknown',
    sentiment_score      DECIMAL(4,3) NOT NULL DEFAULT 0.000, -- -1.000 .. 1.000
    sentiment_confidence DECIMAL(4,3) NOT NULL DEFAULT 0.000, --  0.000 .. 1.000
    sentiment_method     ENUM('none','lexicon','ai','manual') NOT NULL DEFAULT 'none',
    sentiment_reason     VARCHAR(300) NOT NULL DEFAULT '',
    sentiment_at         DATETIME NULL,
    is_manual            TINYINT(1) NOT NULL DEFAULT 0,       -- 1 = the engine must never overwrite this row
    overridden_by        INT NULL,
    classify_state       ENUM('pending','needs_ai','done','failed') NOT NULL DEFAULT 'pending',
    ai_attempts          TINYINT NOT NULL DEFAULT 0,
    topics_json          TEXT NULL,

    is_read              TINYINT(1) NOT NULL DEFAULT 0,
    is_starred           TINYINT(1) NOT NULL DEFAULT 0,
    is_hidden            TINYINT(1) NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL,

    UNIQUE KEY uq_dedupe (client_id, connector, url_hash),
    KEY idx_feed      (client_id, is_hidden, published_at),
    KEY idx_sentiment (client_id, sentiment, published_at),
    KEY idx_connector (client_id, connector, published_at),
    KEY idx_keyword   (client_id, keyword_id, published_at),
    KEY idx_starred   (client_id, is_starred, published_at),
    KEY idx_pipeline  (classify_state, id),
    CONSTRAINT fk_mn_client  FOREIGN KEY (client_id)     REFERENCES clients(id)  ON DELETE CASCADE,
    CONSTRAINT fk_mn_source  FOREIGN KEY (source_id)     REFERENCES sources(id)  ON DELETE SET NULL,
    CONSTRAINT fk_mn_keyword FOREIGN KEY (keyword_id)    REFERENCES keywords(id) ON DELETE SET NULL,
    CONSTRAINT fk_mn_user    FOREIGN KEY (overridden_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per source poll: the source-health screen and the API-spend audit trail.
CREATE TABLE IF NOT EXISTS fetch_runs (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT NOT NULL,
    source_id      INT NULL,
    connector      VARCHAR(32) NOT NULL,
    keyword_id     INT NULL,
    status         ENUM('ok','empty','error','throttled','skipped') NOT NULL DEFAULT 'ok',
    http_code      SMALLINT NOT NULL DEFAULT 0,
    items_seen     INT NOT NULL DEFAULT 0,
    items_new      INT NOT NULL DEFAULT 0,
    items_filtered INT NOT NULL DEFAULT 0,
    billable       INT NOT NULL DEFAULT 0,   -- API units consumed (serpapi 1, youtube 100+n, free 0)
    duration_ms    INT NOT NULL DEFAULT 0,
    request_url    VARCHAR(1000) NOT NULL DEFAULT '',  -- credentials stripped before storing
    error          VARCHAR(500) NOT NULL DEFAULT '',
    started_at     DATETIME NOT NULL,
    finished_at    DATETIME NULL,
    KEY idx_source_time (source_id, id),
    KEY idx_client_time (client_id, started_at),
    CONSTRAINT fk_fr_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_fr_source FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alert_rules (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT NOT NULL,
    name           VARCHAR(160) NOT NULL,
    type           ENUM('any_negative','negative_spike','volume_spike','high_reach_negative','keyword_match')
                        NOT NULL DEFAULT 'any_negative',
    keyword_id     INT NULL,                  -- NULL = any keyword
    connector      VARCHAR(32) NOT NULL DEFAULT '',   -- '' = any connector
    threshold      INT NOT NULL DEFAULT 1,    -- count, or a reach/follower floor
    window_minutes INT NOT NULL DEFAULT 60,
    min_confidence DECIMAL(4,3) NOT NULL DEFAULT 0.500,
    cooldown_min   INT NOT NULL DEFAULT 120,
    recipients     VARCHAR(500) NOT NULL DEFAULT '',
    last_fired_at  DATETIME NULL,
    status         ENUM('active','paused') NOT NULL DEFAULT 'active',
    created_at     DATETIME NOT NULL,
    KEY idx_client_active (client_id, status),
    CONSTRAINT fk_ar_client  FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE CASCADE,
    CONSTRAINT fk_ar_keyword FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alerts (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id    INT NOT NULL,
    rule_id      INT NULL,
    mention_id   BIGINT NULL,
    level        ENUM('info','warn','critical') NOT NULL DEFAULT 'info',
    title        VARCHAR(200) NOT NULL,
    body         TEXT NULL,
    payload_json TEXT NULL,     -- {"mention_ids":[…],"count":7,"window":60}
    email_status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    email_error  VARCHAR(300) NOT NULL DEFAULT '',
    is_read      TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL,
    KEY idx_client_time (client_id, id),
    KEY idx_unread (client_id, is_read, id),
    KEY idx_email (email_status, id),
    CONSTRAINT fk_al_client  FOREIGN KEY (client_id)  REFERENCES clients(id)     ON DELETE CASCADE,
    CONSTRAINT fk_al_rule    FOREIGN KEY (rule_id)    REFERENCES alert_rules(id) ON DELETE SET NULL,
    CONSTRAINT fk_al_mention FOREIGN KEY (mention_id) REFERENCES mentions(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
