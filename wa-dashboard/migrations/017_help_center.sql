-- Help centre: FAQ entries and support requests, both managed from Admin.
--
-- Clients had nowhere to look things up and no way to reach support without leaving the
-- product, so every question became a WhatsApp message to the operator.
CREATE TABLE IF NOT EXISTS faq_items (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    sort       INT          NOT NULL DEFAULT 0,   -- operator-controlled order, not by id
    question   VARCHAR(255) NOT NULL,
    answer     MEDIUMTEXT   NOT NULL,
    status     VARCHAR(10)  NOT NULL DEFAULT 'active',   -- active | hidden
    created_at DATETIME     NOT NULL,
    updated_at DATETIME     NULL,
    KEY idx_faq_live (status, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Support requests. Kept in the database rather than only emailed: mail silently fails often
-- enough that a request must never depend on it to survive.
CREATE TABLE IF NOT EXISTS support_tickets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    client_id  INT          NULL,
    user_id    INT          NULL,
    name       VARCHAR(120) NULL,
    email      VARCHAR(190) NULL,
    subject    VARCHAR(200) NOT NULL,
    message    MEDIUMTEXT   NOT NULL,
    status     VARCHAR(12)  NOT NULL DEFAULT 'open',     -- open | closed
    created_at DATETIME     NOT NULL,
    KEY idx_ticket_status (status, id),
    CONSTRAINT fk_ticket_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
