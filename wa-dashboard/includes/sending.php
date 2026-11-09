<?php
declare(strict_types=1);

/**
 * Which WhatsApp a person's message goes out through.
 *
 * An account has up to two numbers of its own — the Business API number and a linked personal
 * number — and now each salesperson may link their own. The account's Admin chooses, per person:
 *
 *   (not set)         the account's usual channel — how everyone behaved before this existed
 *   api               the Business API number
 *   company_personal  the company's linked personal number
 *   own               their own linked number
 *   none              they can read conversations but not send
 *
 * sender_for() turns that choice into the client row a send should use. Every send function
 * picks its channel from $client['channel'] and its session from $client['personal_*'], so
 * returning an adjusted row is all it takes — no send function needs to know about people.
 */

require_once __DIR__ . '/channel.php';
require_once __DIR__ . '/personal_wa.php';
require_once __DIR__ . '/numbers.php';

function send_via_labels(?array $client = null): array
{
    $o = [
        ''                 => 'Any of the account\'s numbers (chooses when sending)',
        'api'              => 'Only the main Business API number',
        'company_personal' => 'Only the company\'s main phone',
    ];
    // The account's extra numbers (Settings → WhatsApp numbers): one person, one number.
    if ($client && function_exists('wa_numbers_for')) {
        $have = wa_lines($client);
        if (!isset($have['api'])) unset($o['api']);
        if (!isset($have['phone'])) unset($o['company_personal']);
        foreach (wa_lines($client) as $k => $l) if ($k[0] === 'n') $o[$k] = 'Only ' . $l['label'] . ($l['phone'] !== '' ? ' (' . $l['phone'] . ')' : '');
    }
    return $o + ['own' => 'Their own phone', 'none' => 'Cannot send'];
}

/** This person's linked number, if they have started linking one. */
function user_channel(int $userId): ?array
{
    try { return db_row("SELECT * FROM user_channels WHERE user_id=?", [$userId]) ?: null; }
    catch (Throwable $e) { return null; }
}

/** Their row, created on first use, with a gateway instance name that cannot clash with anyone's. */
function user_channel_ensure(int $clientId, int $userId): array
{
    $uc = user_channel($userId);
    if ($uc) return $uc;
    db_insert("INSERT INTO user_channels (user_id,client_id,instance,status,created_at) VALUES (?,?,?, 'disconnected', NOW())",
              [$userId, $clientId, 'client' . $clientId . 'u' . $userId]);
    return user_channel($userId);
}

/**
 * The client row with this person's own session in place of the company's.
 *
 * __user_channel tells pw_store() where session changes belong, so linking or checking a
 * salesperson's phone never touches the company's link.
 */
function user_channel_client(array $client, array $uc): array
{
    return array_merge($client, [
        'channel'                   => 'personal',
        'personal_instance'         => (string) ($uc['instance'] ?: 'client' . (int) $client['id'] . 'u' . (int) $uc['user_id']),
        'personal_status'           => (string) $uc['status'],
        'personal_msisdn'           => $uc['msisdn'],
        'personal_hook_secret'      => $uc['hook_secret'],
        'personal_hook_secret_prev' => $uc['hook_secret_prev'],
        'personal_connected_at'     => $uc['connected_at'],
        '__user_channel'            => (int) $uc['id'],
        '__user_id'                 => (int) $uc['user_id'],
    ]);
}

/**
 * The client row a send by this person should use, or why they cannot send.
 *
 * Which numbers a person may use is the Admin's choice on the Team page (users.send_via):
 * any of the account's numbers, one number, their own phone, or none. $line asks for one number
 * (an Inbox conversation's, a "Send from" picker's); otherwise the contact's last number, then
 * the account's usual one. See includes/numbers.php.
 *
 * @param array|null $user the signed-in user's row; NULL (the system — bots, campaigns) may use
 *                         every business number
 * @return array{ok:bool, client?:array, via:string, line?:string, error?:string}
 */
function sender_for(array $client, ?array $user, ?string $line = null, ?array $contact = null): array
{
    // A client row already set to a line (an automation replying on the number it was written to) keeps it.
    if ($line === null && isset($client['__line'])) $line = (string) $client['__line'];
    if (!wa_lines_ready()) {
        $via = channel_is_personal($client) ? 'company_personal' : 'api';
        return ['ok' => true, 'client' => $client, 'via' => $via, 'line' => wa_line_default($client)];
    }
    $r = wa_line_pick($client, $user && ($user['role'] ?? '') !== 'admin' ? $user : null, $line, $contact);
    return $r + ['via' => 'none'];
}

/** The signed-in user's full row, for sender_for(), when there is one. */
function sending_user(): ?array
{
    if (!function_exists('perm_context')) return null;
    [$u] = perm_context();
    return !empty($u['id']) ? $u : null;
}
