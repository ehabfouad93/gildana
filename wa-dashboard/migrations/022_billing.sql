-- Subscription plans, dual WABA modes, and the meters behind them.
--
-- Two facts shape this. First, a client on their OWN WhatsApp Business Account is billed by
-- Meta directly — there is no cost for us to pass through, so their credits are simply a fee
-- for using the platform. Second, a client onboarded under OUR account is a cost we carry and
-- rebill, so their messages must be priced from a real rate table. Both live side by side.

CREATE TABLE IF NOT EXISTS plans (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(32)  NOT NULL,
    name                VARCHAR(80)  NOT NULL,
    price_month         DECIMAL(10,2) NOT NULL DEFAULT 0,
    currency            CHAR(3)      NOT NULL DEFAULT 'USD',
    included_credits    INT          NOT NULL DEFAULT 0,
    overage_per_1k      DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_numbers         INT          NOT NULL DEFAULT 1,    -- connected personal numbers
    max_seats           INT          NOT NULL DEFAULT 1,
    max_contacts        INT          NOT NULL DEFAULT 0,    -- 0 = unlimited
    max_flows           INT          NOT NULL DEFAULT 0,
    -- byo: the client brings their own AI key and we meter nothing.
    -- included: they use the platform key and their usage is metered against an allowance.
    ai_mode             ENUM('byo','included') NOT NULL DEFAULT 'byo',
    included_ai_credits INT          NOT NULL DEFAULT 0,
    features            JSON         NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    sort                INT          NOT NULL DEFAULT 0,
    created_at          DATETIME     NOT NULL,
    UNIQUE KEY uq_plan_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE clients
    -- byo      : their own Meta app and number; Meta bills them. This is every existing client.
    -- platform : onboarded under our WABA; we pay Meta and rebill with a markup.
    ADD COLUMN waba_mode       ENUM('byo','platform') NOT NULL DEFAULT 'byo' AFTER require_signed_webhook,
    ADD COLUMN plan_id         INT      NULL DEFAULT NULL AFTER waba_mode,
    ADD COLUMN plan_started_at DATETIME NULL DEFAULT NULL AFTER plan_id,
    ADD COLUMN plan_renews_at  DATETIME NULL DEFAULT NULL AFTER plan_started_at,
    -- Off by default: a client cannot run up a bill they never agreed to.
    ADD COLUMN overage_allowed TINYINT(1) NOT NULL DEFAULT 0 AFTER plan_renews_at,
    ADD CONSTRAINT fk_clients_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL;

-- What Meta charges US per message, by destination country and message category.
-- Admin-maintained on purpose: these rates change and differ sharply by country, so hardcoding
-- them would guarantee wrong invoices. Country '*' is the fallback for anywhere unlisted.
CREATE TABLE IF NOT EXISTS wa_rates (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    country_code   VARCHAR(8)  NOT NULL,          -- E.164 prefix, e.g. '20', or '*'
    category       VARCHAR(20) NOT NULL,          -- marketing|utility|authentication|service
    cost           DECIMAL(10,6) NOT NULL DEFAULT 0,
    currency       CHAR(3)     NOT NULL DEFAULT 'USD',
    effective_from DATE        NOT NULL,
    UNIQUE KEY uq_rate (country_code, category, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What the AI providers charge us, per million tokens, per model.
CREATE TABLE IF NOT EXISTS ai_rates (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    provider        VARCHAR(12) NOT NULL,          -- claude|openai
    model           VARCHAR(64) NOT NULL,
    input_per_mtok  DECIMAL(10,4) NOT NULL DEFAULT 0,
    output_per_mtok DECIMAL(10,4) NOT NULL DEFAULT 0,
    currency        CHAR(3)     NOT NULL DEFAULT 'USD',
    UNIQUE KEY uq_ai_rate (provider, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every metered AI call. Only written when the PLATFORM key was used — a client on their own
-- key costs us nothing and is never recorded here.
CREATE TABLE IF NOT EXISTS ai_usage (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id     INT         NOT NULL,
    period_start  DATE        NOT NULL,
    provider      VARCHAR(12) NOT NULL,
    model         VARCHAR(64) NOT NULL,
    input_tokens  INT         NOT NULL DEFAULT 0,
    output_tokens INT         NOT NULL DEFAULT 0,
    cost          DECIMAL(12,6) NOT NULL DEFAULT 0,   -- what it cost US
    credits       INT         NOT NULL DEFAULT 0,     -- what we charged THEM
    source        VARCHAR(24) NULL,                   -- flow|inbox|qualifier|agent
    created_at    DATETIME    NOT NULL,
    KEY idx_client_period (client_id, period_start),
    CONSTRAINT fk_aiu_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per client per billing month, so a period can be closed and invoiced without
-- re-scanning the whole ledger.
CREATE TABLE IF NOT EXISTS usage_periods (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id      INT      NOT NULL,
    period_start   DATE     NOT NULL,
    period_end     DATE     NOT NULL,
    credits_used   INT      NOT NULL DEFAULT 0,
    messages_sent  INT      NOT NULL DEFAULT 0,
    ai_credits     INT      NOT NULL DEFAULT 0,
    platform_cost  DECIMAL(12,4) NOT NULL DEFAULT 0,  -- our real Meta + AI spend for them
    closed_at      DATETIME NULL,
    UNIQUE KEY uq_client_period (client_id, period_start),
    CONSTRAINT fk_up_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every message we charged for, with what it actually cost. This is what makes an invoice
-- defensible: "you sent 412 marketing messages to Egypt" rather than "you used 412 credits".
CREATE TABLE IF NOT EXISTS message_charges (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id    INT         NOT NULL,
    period_start DATE        NOT NULL,
    country_code VARCHAR(8)  NOT NULL,
    category     VARCHAR(20) NOT NULL,
    waba_mode    VARCHAR(10) NOT NULL,
    qty          INT         NOT NULL DEFAULT 0,
    cost         DECIMAL(12,6) NOT NULL DEFAULT 0,
    credits      INT         NOT NULL DEFAULT 0,
    UNIQUE KEY uq_charge (client_id, period_start, country_code, category, waba_mode),
    CONSTRAINT fk_mc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
