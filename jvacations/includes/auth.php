<?php
declare(strict_types=1);

/**
 * Authentication + role guards. Every account has exactly one role; each page
 * declares which roles may open it with require_role(), and client-level access
 * is decided by can_view_client() in domain.php.
 */

const ROLES = ['admin', 'advisor', 'booker', 'communicator', 'sales', 'accountant', 'owner_services'];

function attempt_login(string $email, string $password): bool
{
    $email = strtolower(trim($email));
    $user  = db_row("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_run("UPDATE users SET password_hash = ? WHERE id = ?",
            [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    db_run("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$user['id']]);

    session_regenerate_id(true);
    $_SESSION['uid']    = (int) $user['id'];
    $_SESSION['locale'] = (string) ($user['locale'] ?: 'en');
    csrf_token();
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * The signed-in user, re-read from the database once per request so a role
 * change or a disabled account takes effect immediately, not at next login.
 */
function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    if (empty($_SESSION['uid'])) return null;
    $row = db_row("SELECT id, name, email, role, locale, status FROM users WHERE id = ?", [(int) $_SESSION['uid']]);
    if (!$row || $row['status'] !== 'active') {
        logout();
        return null;
    }
    $row['id'] = (int) $row['id'];
    $cache = $row;
    return $cache;
}

function has_role(string ...$roles): bool
{
    $u = current_user();
    return $u !== null && ($u['role'] === 'admin' || in_array($u['role'], $roles, true));
}

/** Guard: logged in with one of $roles (admin always passes). Returns the user. */
function require_role(string ...$roles): array
{
    $u = current_user();
    if (!$u) redirect(app_url('index.php'));
    if ($roles && !has_role(...$roles)) {
        http_response_code(403);
        exit(t('err.forbidden'));
    }
    return $u;
}

/** Path to an app file from whichever folder the current page lives in. */
function app_url(string $path): string
{
    $inApp = basename(dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) === 'app';
    return ($inApp ? '../' : '') . $path;
}

/* ── login brute-force throttle ── */
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW       = 900;
const LOGIN_LOCK         = 900;

function login_lock_seconds(string $ip, string $email): int
{
    $row = db_row("SELECT locked_until FROM login_attempts WHERE ip=? AND email=?", [$ip, $email]);
    if ($row && $row['locked_until'] && strtotime((string) $row['locked_until']) > time()) {
        return strtotime((string) $row['locked_until']) - time();
    }
    return 0;
}

function login_record_fail(string $ip, string $email): void
{
    $row = db_row("SELECT * FROM login_attempts WHERE ip=? AND email=?", [$ip, $email]);
    if (!$row) {
        db_run("INSERT INTO login_attempts (ip,email,attempts,updated_at) VALUES (?,?,1,NOW())", [$ip, $email]);
        return;
    }
    $attempts = strtotime((string) $row['updated_at']) < time() - LOGIN_WINDOW ? 1 : (int) $row['attempts'] + 1;
    $locked   = $attempts >= LOGIN_MAX_ATTEMPTS ? date('Y-m-d H:i:s', time() + LOGIN_LOCK) : null;
    db_run("UPDATE login_attempts SET attempts=?, locked_until=?, updated_at=NOW() WHERE id=?",
        [$attempts, $locked, $row['id']]);
}

function login_clear(string $ip, string $email): void
{
    db_run("DELETE FROM login_attempts WHERE ip=? AND email=?", [$ip, $email]);
}

/** True if any admin exists (gates first-run setup). */
function admin_exists(): bool
{
    try {
        return (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'admin'") > 0;
    } catch (PDOException $ex) {
        return false;
    }
}
