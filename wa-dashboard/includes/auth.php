<?php
declare(strict_types=1);

/**
 * Authentication + role guards. Self-contained; not linked to the Gildana CMS.
 */

function attempt_login(string $email, string $password): bool
{
    $email = strtolower(trim($email));
    $user  = db_row("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    // rehash if needed
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_run("UPDATE users SET password_hash = ? WHERE id = ?",
            [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    // Best-effort last-login stamp (column added in migration 002).
    try { db_run("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$user['id']]); } catch (Throwable $e) {}

    session_regenerate_id(true);
    $_SESSION['uid']       = (int) $user['id'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['client_id'] = $user['client_id'] !== null ? (int) $user['client_id'] : null;
    $_SESSION['email']     = $user['email'];
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
 * The signed-in user's full row, not just what the session carries.
 *
 * current_user() is deliberately session-only — cheap, and enough for auth checks. But the
 * top bar needs the name and picture, which live in the database and change while the session
 * is open, so those have to be read. Cached per request: this runs on every page render.
 */
function current_user_full(): ?array
{
    static $row = false;
    if ($row !== false) return $row;
    $u = current_user();
    if (!$u) return $row = null;
    try {
        $row = db_row("SELECT * FROM users WHERE id=?", [(int) $u['id']]) ?: $u;
    } catch (Throwable $e) {
        $row = $u;                       // never let the chrome break the page
    }
    return $row;
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    return [
        'id'        => (int) $_SESSION['uid'],
        'role'      => (string) ($_SESSION['role'] ?? ''),
        'client_id' => $_SESSION['client_id'] ?? null,
        'email'     => (string) ($_SESSION['email'] ?? ''),
    ];
}

function is_admin(): bool  { return (current_user()['role'] ?? '') === 'admin'; }
function is_client(): bool { return (current_user()['role'] ?? '') === 'client'; }

/** Guard: must be logged in as admin, else redirect to login. */
function require_admin(): array
{
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') redirect('../index.php');
    return $u;
}

/** True when an admin is currently acting inside a client's workspace. */
function is_impersonating(): bool
{
    return is_admin() && !empty($_SESSION['impersonate_client_id']);
}

/**
 * Guard: must be logged in as a client — OR an admin impersonating a client.
 * Returns [user, client row].
 */
function require_client(): array
{
    $u = current_user();
    if (!$u) redirect('../index.php');

    // Admin acting inside a client's workspace.
    if ($u['role'] === 'admin') {
        $cid = (int) ($_SESSION['impersonate_client_id'] ?? 0);
        if (!$cid) redirect('../admin/index.php');
        $client = db_row("SELECT * FROM clients WHERE id = ?", [$cid]);
        if (!$client) { unset($_SESSION['impersonate_client_id']); redirect('../admin/index.php'); }
        return [$u, $client]; // admins may manage disabled clients too
    }

    if ($u['role'] !== 'client' || !$u['client_id']) redirect('../index.php');
    $client = db_row("SELECT * FROM clients WHERE id = ?", [$u['client_id']]);
    if (!$client || $client['status'] !== 'active') {
        logout();
        redirect('../index.php?disabled=1');
    }
    return [$u, $client];
}

/* ── login brute-force throttle ──
   After 5 failed attempts from the same IP+email within 15 minutes, lock for 15 min. */
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW       = 900; // seconds
const LOGIN_LOCK         = 900; // seconds

function login_lock_seconds(string $ip, string $email): int
{
    try {
        $row = db_row("SELECT locked_until FROM login_attempts WHERE ip=? AND email=?", [$ip, $email]);
    } catch (Throwable $e) { return 0; } // table not migrated yet → don't block
    if ($row && $row['locked_until'] && strtotime((string) $row['locked_until']) > time()) {
        return strtotime((string) $row['locked_until']) - time();
    }
    return 0;
}

function login_record_fail(string $ip, string $email): void
{
    try {
        $row = db_row("SELECT * FROM login_attempts WHERE ip=? AND email=?", [$ip, $email]);
        if (!$row) {
            db_run("INSERT INTO login_attempts (ip,email,attempts,updated_at) VALUES (?,?,1,NOW())", [$ip, $email]);
            return;
        }
        $attempts = strtotime((string) $row['updated_at']) < time() - LOGIN_WINDOW ? 1 : (int) $row['attempts'] + 1;
        $locked   = $attempts >= LOGIN_MAX_ATTEMPTS ? date('Y-m-d H:i:s', time() + LOGIN_LOCK) : null;
        db_run("UPDATE login_attempts SET attempts=?, locked_until=?, updated_at=NOW() WHERE id=?",
            [$attempts, $locked, $row['id']]);
    } catch (Throwable $e) { /* non-fatal */ }
}

function login_clear(string $ip, string $email): void
{
    try { db_run("DELETE FROM login_attempts WHERE ip=? AND email=?", [$ip, $email]); } catch (Throwable $e) {}
}

/** True if any admin user exists (used to gate first-run setup).
    Tolerant of a not-yet-migrated database (returns false → go to setup). */
function admin_exists(): bool
{
    try {
        return (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'admin'") > 0;
    } catch (PDOException $ex) {
        return false;
    }
}
