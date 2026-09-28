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

function send_via_labels(): array
{
    return [
        ''                 => 'The account\'s usual number',
        'api'              => 'The WhatsApp Business API number',
        'company_personal' => 'The company\'s linked phone',
        'own'              => 'Their own phone',
        'none'             => 'Cannot send',
    ];
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
 * @param array|null $user the signed-in user's row; NULL (the system — bots, campaigns) keeps the
 *                         account's own channel, as before
 * @return array{ok:bool, client?:array, via:string, error?:string}
 */
function sender_for(array $client, ?array $user): array
{
    $accountVia = channel_is_personal($client) ? 'company_personal' : 'api';
    // The system, and a platform operator working inside an account, send as the account does.
    if (!$user || ($user['role'] ?? '') === 'admin') return ['ok' => true, 'client' => $client, 'via' => $accountVia];

    $choice = (string) ($user['send_via'] ?? '');
    switch ($choice) {
        case '':
            return ['ok' => true, 'client' => $client, 'via' => $accountVia];

        case 'none':
            return ['ok' => false, 'via' => 'none',
                    'error' => 'Your account admin has not set you up to send messages. Ask them to choose a number for you on the Team page.'];

        case 'api':
            if (trim((string) ($client['phone_number_id'] ?? '')) === '') {
                return ['ok' => false, 'via' => 'api', 'error' => 'This account has no WhatsApp Business API number connected, '
                                                                 . 'so there is nothing to send from. Ask your admin.'];
            }
            return ['ok' => true, 'client' => array_merge($client, ['channel' => 'cloud']), 'via' => 'api'];

        case 'company_personal':
            if (($client['personal_status'] ?? '') !== 'connected') {
                return ['ok' => false, 'via' => 'company_personal', 'error' => 'The company\'s phone is not linked right now, '
                                                                            . 'so messages cannot go out from it. Ask your admin to reconnect it in Settings.'];
            }
            return ['ok' => true, 'client' => array_merge($client, ['channel' => 'personal']), 'via' => 'company_personal'];

        case 'own':
            $uc = user_channel((int) $user['id']);
            if (!$uc || $uc['status'] !== 'connected') {
                return ['ok' => false, 'via' => 'own', 'error' => 'Your own WhatsApp is not linked yet. Open My WhatsApp and scan the code with your phone.'];
            }
            return ['ok' => true, 'client' => user_channel_client($client, $uc), 'via' => 'own'];
    }
    return ['ok' => false, 'via' => $choice, 'error' => 'Unknown sending setting.'];
}

/** The signed-in user's full row, for sender_for(), when there is one. */
function sending_user(): ?array
{
    if (!function_exists('perm_context')) return null;
    [$u] = perm_context();
    return !empty($u['id']) ? $u : null;
}
