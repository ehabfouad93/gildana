-- Brute-force protection for the login form.
CREATE TABLE IF NOT EXISTS login_attempts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ip            VARCHAR(45)  NOT NULL,
    email         VARCHAR(190) NOT NULL,
    attempts      INT          NOT NULL DEFAULT 0,
    locked_until  DATETIME     NULL,
    updated_at    DATETIME     NOT NULL,
    UNIQUE KEY uq_ip_email (ip, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
