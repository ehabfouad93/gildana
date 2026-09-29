-- Online meetings (a visit held on a video call), sales events with invitations and attendance.

-- A visit is either at the site or online. An online one carries its meeting link, and the lead
-- gets a second reminder shortly before it starts.
ALTER TABLE crm_visits ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'site' AFTER user_id;          -- site | online
ALTER TABLE crm_visits ADD COLUMN meet_url VARCHAR(500) NULL AFTER place;
ALTER TABLE crm_visits ADD COLUMN soon_reminded TINYINT(1) NOT NULL DEFAULT 0 AFTER staff_reminded;
ALTER TABLE crm_visits ADD COLUMN gcal_event_id VARCHAR(120) NULL AFTER meet_url;

-- A salesperson's own meeting room (a fixed Zoom / Meet / Teams link), used when none is typed.
ALTER TABLE users ADD COLUMN meet_url VARCHAR(500) NULL AFTER nav_favs;

-- The "starts soon" message for online meetings, and how long before.
ALTER TABLE crm_settings ADD COLUMN meet_soon_tpl INT NULL AFTER visit_done_stage;
ALTER TABLE crm_settings ADD COLUMN meet_soon_vars TEXT NULL AFTER meet_soon_tpl;
ALTER TABLE crm_settings ADD COLUMN meet_soon_minutes INT NOT NULL DEFAULT 30 AFTER meet_soon_vars;

-- Sales events: an open day, a launch, a webinar. Leads are invited on WhatsApp with a template,
-- and the list shows who said yes, who came and who bought afterwards.
CREATE TABLE IF NOT EXISTS sales_events (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    client_id       INT          NOT NULL,
    name            VARCHAR(150) NOT NULL,
    starts_at       DATETIME     NOT NULL,
    duration_min    INT          NOT NULL DEFAULT 120,
    place           VARCHAR(500) NULL,                   -- an address, or a link for an online event
    project_id      INT          NULL,
    notes           VARCHAR(1000) NULL,
    invite_tpl      INT          NULL,
    invite_vars     TEXT         NULL,
    remind_tpl      INT          NULL,                   -- the day before, to everyone not declined
    remind_vars     TEXT         NULL,
    reminded        TINYINT(1)   NOT NULL DEFAULT 0,
    status          VARCHAR(10)  NOT NULL DEFAULT 'active',   -- active | cancelled
    created_by      INT          NULL,
    created_at      DATETIME     NOT NULL,
    KEY idx_client_time (client_id, starts_at),
    CONSTRAINT fk_sales_events_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_event_guests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    event_id        INT          NOT NULL,
    client_id       INT          NOT NULL,
    contact_id      INT          NOT NULL,
    status          VARCHAR(10)  NOT NULL DEFAULT 'added',    -- added | invited | confirmed | declined | attended | no_show
    added_by        INT          NULL,
    added_at        DATETIME     NOT NULL,
    invited_at      DATETIME     NULL,
    answered_at     DATETIME     NULL,
    UNIQUE KEY uq_event_contact (event_id, contact_id),
    KEY idx_contact (contact_id),
    CONSTRAINT fk_seg_event FOREIGN KEY (event_id) REFERENCES sales_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_seg_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
