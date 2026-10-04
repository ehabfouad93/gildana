<?php
declare(strict_types=1);

/**
 * Opening a client account: the business and its first login, together or not at all.
 *
 * Used by Admin → Clients (+ New Client) and by Admin → Requests (Convert to client), so an
 * account made from a request is exactly the same as one made by hand.
 */
require_once __DIR__ . '/credits.php';

/**
 * @param array $f name, email, password, credits, and optionally company, sender_display, category,
 *                 contact_person, contact_phone, contact_email, timezone, default_country, notes,
 *                 low_credit_threshold, login_name
 * @return array{ok:bool, id?:int, error?:string}
 */
function client_account_create(array $f): array
{
    $s       = fn(string $k) => trim((string) ($f[$k] ?? ''));
    $name    = $s('name');
    $email   = strtolower($s('email'));
    $pass    = (string) ($f['password'] ?? '');
    $credits = max(0, (int) ($f['credits'] ?? 0));

    if ($name === '')                                return ['ok' => false, 'error' => 'Client name is required.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  return ['ok' => false, 'error' => 'Enter a valid login email.'];
    if (strlen($pass) < 8)                           return ['ok' => false, 'error' => 'Login password must be at least 8 characters.'];
    if (db_val("SELECT COUNT(*) FROM users WHERE email = ?", [$email]))
        return ['ok' => false, 'error' => 'That login email is already in use.'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $clientId = db_insert(
            "INSERT INTO clients
                (name, sender_display, company, contact_person, contact_phone, contact_email,
                 category, timezone, default_country, notes, credits_balance, low_credit_threshold, status, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,0,?, 'active', NOW())",
            [$name, $s('sender_display') ?: $name, $s('company'), $s('contact_person'), $s('contact_phone'), $s('contact_email'),
             $s('category'), $s('timezone'), $s('default_country'), $s('notes') ?: null,
             max(0, (int) ($f['low_credit_threshold'] ?? 100))]
        );
        db_insert("INSERT INTO users (client_id, email, name, password_hash, role, status, created_at)
                   VALUES (?,?,?,?, 'client', 'active', NOW())",
                  [$clientId, $email, $s('login_name') ?: null, password_hash($pass, PASSWORD_DEFAULT)]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('client create failed: ' . $ex->getMessage());
        return ['ok' => false, 'error' => 'Could not create the client. Please try again.'];
    }
    if ($credits > 0) credits_adjust($clientId, $credits, 'initial_grant');
    return ['ok' => true, 'id' => $clientId];
}

/** A password to hand to a new client: 12 characters, nothing easily misread (no 0/O, 1/l/I). */
function client_account_password(): string
{
    $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 12; $i++) $p .= $abc[random_int(0, strlen($abc) - 1)];
    return $p;
}
