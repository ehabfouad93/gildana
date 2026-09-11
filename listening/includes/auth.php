<?php
declare(strict_types=1);

/**
 * Authentication + tenancy guards. Two roles:
 *   admin  — the agency: sees every client, manages accounts, may enter a workspace
 *   client — one brand: sees only its own mentions
 *
 * An admin "enters" a client's workspace by setting $_SESSION['impersonate_client_id']
 * (admin/open_workspace.php); require_client() then resolves that client.
 */

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
    try { db_run("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$user['id']]); } catch (Throwable $e) {}

    session_regenerate_id(true);
    $_SESSION['uid']       = (int) $user['id'];
    $_SESSION['role']      = (string) $user['role'];
    $_SESSION['email']     = (string) $user['email'];
    $_SESSION['name']      = (string) $user['name'];
    $_SESSION['client_id'] = $user['client_id'] === null ? null : (int) $user['client_id'];
    $_SESSION['locale']    = (string) ($user['locale'] ?: 'en');
    unset($_SESSION['impersonate_client_id']);
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

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    return [
        'id'        => (int) $_SESSION['uid'],
        'role'      => (string) ($_SESSION['role'] ?? ''),
        'email'     => (string) ($_SESSION['email'] ?? ''),
        'name'      => (string) ($_SESSION['name'] ?? ''),
        'client_id' => isset($_SESSION['client_id']) ? ($_SESSION['client_id'] === null ? null : (int) $_SESSION['client_id']) : null,
    ];
}

function is_admin(): bool  { return (current_user()['role'] ?? '') === 'admin'; }
function is_client(): bool { return (current_user()['role'] ?? '') === 'client'; }

/** True when an admin is currently working inside a client's workspace. */
function is_impersonating(): bool
{
    return is_admin() && !empty($_SESSION['impersonate_client_id']);
}

/** Guard: must be logged in (any role). Returns the user. */
function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('../index.php');
    return $u;
}

/** Guard: must be an admin. */
function require_admin(): array
{
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') redirect('../index.php');
    return $u;
}

/**
 * Guard for every /client page. Returns [$user, $clientRow].
 * Admins resolve through the impersonated workspace; clients through their own row.
 */
function require_client(): array
{
    $u = current_user();
    if (!$u) redirect('../index.php');

    if ($u['role'] === 'admin') {
        $cid = (int) ($_SESSION['impersonate_client_id'] ?? 0);
        if (!$cid) redirect('../admin/index.php');
        $client = db_row("SELECT * FROM clients WHERE id = ?", [$cid]);
        if (!$client) {
            unset($_SESSION['impersonate_client_id']);
            redirect('../admin/index.php');
        }
        return [$u, $client]; // admins may manage disabled clients too
    }

    if ($u['role'] !== 'client' || !$u['client_id']) redirect('../index.php');
    $client = db_row("SELECT * FROM clients WHERE id = ?", [(int) $u['client_id']]);
    if (!$client || $client['status'] !== 'active') {
        logout();
        redirect('../index.php?disabled=1');
    }
    return [$u, $client];
}

/* ── login brute-force throttle ── */
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW       = 900;
const LOGIN_LOCK         = 900;

function login_lock_seconds(string $ip, string $email): int
{
    try {
        $row = db_row("SELECT locked_until FROM login_attempts WHERE ip=? AND email=?", [$ip, $email]);
    } catch (Throwable $e) { return 0; }
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
    } catch (Throwable $e) {}
}

function login_clear(string $ip, string $email): void
{
    try { db_run("DELETE FROM login_attempts WHERE ip=? AND email=?", [$ip, $email]); } catch (Throwable $e) {}
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
