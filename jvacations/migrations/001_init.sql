-- J Vacations — initial schema. MySQL 5.7+ / MariaDB 10.3+.
-- Money is DECIMAL(14,2); every date is stored as entered (local business dates).

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  phone         VARCHAR(40)  NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','advisor','booker','communicator','sales','accountant','owner_services') NOT NULL,
  locale        VARCHAR(5)   NOT NULL DEFAULT 'en',
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  KEY idx_role (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip           VARCHAR(64)  NOT NULL,
  email        VARCHAR(190) NOT NULL,
  attempts     INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  updated_at   DATETIME NOT NULL,
  UNIQUE KEY uq_ip_email (ip, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL,
  location   VARCHAR(190) NULL,
  notes      TEXT NULL,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per lead. `stage` is the pipeline position; each hand-off stamps
-- who did it so every department sees where the client came from.
CREATE TABLE IF NOT EXISTS clients (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name       VARCHAR(150) NOT NULL,
  phone           VARCHAR(40)  NOT NULL,
  phone2          VARCHAR(40)  NULL,
  email           VARCHAR(190) NULL,
  city            VARCHAR(100) NULL,
  job             VARCHAR(120) NULL,
  marital_status  VARCHAR(30)  NULL,
  source          VARCHAR(60)  NULL,
  notes           TEXT NULL,
  stage           ENUM('new','booked','confirmed','contracted','lost','cancelled') NOT NULL DEFAULT 'new',
  created_by      INT UNSIGNED NOT NULL,
  booker_id       INT UNSIGNED NULL,
  booked_at       DATETIME NULL,
  meeting_date    DATE NULL,
  meeting_time    TIME NULL,
  meeting_place   VARCHAR(150) NULL,
  communicator_id INT UNSIGNED NULL,
  confirmed_at    DATETIME NULL,
  sales_id        INT UNSIGNED NULL,
  closed_at       DATETIME NULL,
  lost_reason     VARCHAR(255) NULL,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  KEY idx_stage (stage),
  KEY idx_created_by (created_by),
  KEY idx_sales (sales_id, stage),
  KEY idx_booker (booker_id),
  CONSTRAINT fk_clients_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_events (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(40) NOT NULL,
  note       TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_client (client_id, id),
  CONSTRAINT fk_events_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The reservation: the formal data sales enters once the deal succeeds.
CREATE TABLE IF NOT EXISTS contracts (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id       INT UNSIGNED NOT NULL UNIQUE,
  contract_no     VARCHAR(40)  NOT NULL UNIQUE,
  contract_date   DATE NOT NULL,
  official_name   VARCHAR(190) NOT NULL,
  national_id     VARCHAR(40)  NOT NULL,
  nationality     VARCHAR(60)  NULL,
  birth_date      DATE NULL,
  address         VARCHAR(255) NULL,
  phone           VARCHAR(40)  NULL,
  email           VARCHAR(190) NULL,
  second_party    VARCHAR(190) NULL,
  project_id      INT UNSIGNED NOT NULL,
  unit_type       VARCHAR(80)  NULL,
  season          VARCHAR(40)  NULL,
  weeks_per_year  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  duration_years  TINYINT UNSIGNED NOT NULL DEFAULT 10,
  start_year      SMALLINT UNSIGNED NOT NULL,
  currency        VARCHAR(8)   NOT NULL DEFAULT 'EGP',
  total_amount    DECIMAL(14,2) NOT NULL,
  down_pct        DECIMAL(5,2)  NOT NULL,
  down_amount     DECIMAL(14,2) NOT NULL,
  months          SMALLINT UNSIGNED NOT NULL,
  monthly_amount  DECIMAL(14,2) NOT NULL,
  first_due_date  DATE NOT NULL,
  notes           TEXT NULL,
  created_by      INT UNSIGNED NOT NULL,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  CONSTRAINT fk_contract_client  FOREIGN KEY (client_id)  REFERENCES clients(id),
  CONSTRAINT fk_contract_project FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- seq 0 is the down payment, 1..months the monthly instalments.
CREATE TABLE IF NOT EXISTS instalments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id INT UNSIGNED NOT NULL,
  seq         SMALLINT UNSIGNED NOT NULL,
  due_date    DATE NOT NULL,
  amount      DECIMAL(14,2) NOT NULL,
  status      ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid_on     DATE NULL,
  receipt_no  VARCHAR(60) NULL,
  method      VARCHAR(30) NULL,
  note        VARCHAR(255) NULL,
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME NULL,
  UNIQUE KEY uq_contract_seq (contract_id, seq),
  KEY idx_due (status, due_date),
  CONSTRAINT fk_inst_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A holiday booked against the owner's yearly week allowance.
CREATE TABLE IF NOT EXISTS stays (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contract_id   INT UNSIGNED NOT NULL,
  project_id    INT UNSIGNED NOT NULL,
  check_in      DATE NOT NULL,
  check_in_time TIME NULL,
  weeks         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  check_out     DATE NOT NULL,
  unit_type     VARCHAR(80) NULL,
  guests        TINYINT UNSIGNED NULL,
  status        ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  notes         VARCHAR(255) NULL,
  created_by    INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL,
  KEY idx_contract (contract_id, status),
  KEY idx_checkin (check_in),
  CONSTRAINT fk_stay_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  CONSTRAINT fk_stay_project  FOREIGN KEY (project_id)  REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
