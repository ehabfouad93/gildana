-- Click-to-WhatsApp ads: remember which ad brought each lead, and let a flow trigger on it.
--
-- Meta sends a `referral` object on the FIRST inbound message of a conversation that started
-- from a Facebook or Instagram ad — the ad's id, its own headline and body copy, the link,
-- and a click id. webhook.php parsed the message and threw that object away, so the app could
-- not tell an ad conversation from any other first message, let alone which ad.
--
-- Two things are stored, deliberately not three:
--
--   wa_ads   — one row per ad, upserted on every click. This is what the automation editor's
--              ad picker lists and what the Reports page counts against. The headline lives
--              here once rather than being copied onto every contact.
--
--   contacts — three columns holding FIRST touch. Which ad first brought this person is the
--              question a client actually asks; it is written once and never overwritten, so
--              someone who clicks a second ad months later stays credited to the first.
--
-- Per-click history (multi-touch) would need an events table. Nobody has asked for it, and
-- adding it later changes nothing here.

CREATE TABLE IF NOT EXISTS wa_ads (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    client_id     INT          NOT NULL,
    -- Meta's ad id, as it arrives in referral.source_id. A string, not an int: it is an
    -- opaque identifier that has grown in length before and is not arithmetic.
    source_id     VARCHAR(64)  NOT NULL,
    source_type   VARCHAR(16)  NOT NULL DEFAULT 'ad',   -- 'ad' or 'post'
    headline      VARCHAR(255) NULL,
    body          TEXT         NULL,
    source_url    VARCHAR(512) NULL,
    first_seen_at DATETIME     NOT NULL,
    -- Moves on every click, which is what separates a live ad from a spent one in the picker.
    last_seen_at  DATETIME     NOT NULL,
    UNIQUE KEY uq_client_ad (client_id, source_id),
    KEY idx_client_seen (client_id, last_seen_at),
    CONSTRAINT fk_wa_ads_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- First touch on the contact. The id only — the headline is in wa_ads, so an ad whose copy
-- is edited does not leave half its leads quoting the old wording.
ALTER TABLE contacts ADD COLUMN ad_source_id VARCHAR(64) NULL AFTER source;
ALTER TABLE contacts ADD COLUMN ad_clid      VARCHAR(255) NULL AFTER ad_source_id;
ALTER TABLE contacts ADD COLUMN referred_at  DATETIME     NULL AFTER ad_clid;

-- The lead count per ad is COUNT(*) over this index, so it stays exact without a counter
-- column that could drift.
ALTER TABLE contacts ADD INDEX idx_ad_source (client_id, ad_source_id);
