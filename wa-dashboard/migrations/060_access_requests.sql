-- Requests from the "Get started" form, kept as their own records so the operator can work them:
-- New → Contacted → Converted (into a client account) or Declined, with notes along the way.
CREATE TABLE IF NOT EXISTS access_requests (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    email        VARCHAR(190) NOT NULL,
    job_title    VARCHAR(120) NULL,
    company      VARCHAR(150) NULL,
    phone        VARCHAR(32)  NULL,
    about        TEXT         NULL,
    status       VARCHAR(12)  NOT NULL DEFAULT 'new',   -- new | contacted | converted | declined
    notes        TEXT         NULL,                     -- the operator's, never shown to the requester
    client_id    INT          NULL,                     -- the account it became
    source       VARCHAR(40)  NULL,                     -- landing | link
    utm_source   VARCHAR(100) NULL,
    utm_medium   VARCHAR(100) NULL,
    utm_campaign VARCHAR(100) NULL,
    referrer     VARCHAR(255) NULL,
    ip           VARCHAR(45)  NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   DATETIME     NOT NULL,
    updated_at   DATETIME     NULL,
    KEY idx_ar_status (status, created_at),
    KEY idx_ar_email (email),
    CONSTRAINT fk_ar_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Requests that came in before this table existed were filed as support tickets; bring them over
-- so the new page starts with the full history. (The tickets stay where they are.)
INSERT INTO access_requests (name, email, about, status, source, created_at)
SELECT COALESCE(NULLIF(name,''), email), COALESCE(email,''), message,
       CASE WHEN status='open' THEN 'new' ELSE 'contacted' END, 'landing', created_at
  FROM support_tickets
 WHERE client_id IS NULL AND subject LIKE 'Access request:%';
