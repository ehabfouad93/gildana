<?php
declare(strict_types=1);

/**
 * Several WhatsApp numbers per account.
 *
 * Each number an account can send from is a LINE, named by a short key that is written on every
 * WhatsApp message (messages.line) and remembered per contact (contacts.last_line):
 *
 *   api     the main Business API number (clients.phone_number_id…)
 *   phone   the main linked phone (clients.personal_*)
 *   n<id>   an extra number the account added (wa_numbers: a Business API number or a phone)
 *   u<id>   a salesperson's own phone (user_channels)
 *
 * Every send function takes a client row and reads its channel and credentials from it, so
 * sending from a line is only ever "pass the client row with that line's details in place":
 * wa_line_client(). pw_store() writes a linked phone's session changes back to wherever that
 * phone lives (clients, wa_numbers or user_channels).
 */

require_once __DIR__ . '/channel.php';

function wa_lines_ready(): bool
{
    static $ok = null;
    return $ok ??= db_has_column('messages', 'line');
}

/** The line a send goes through when nothing chose one: the account's usual channel. */
function wa_line_default(array $client): string
{
    return ($client['channel'] ?? 'cloud') === 'personal' ? 'phone' : 'api';
}

/** The line this client row sends through. */
function wa_line_of(array $client): string
{
    return (string) ($client['__line'] ?? wa_line_default($client));
}

function wa_line_valid(string $line): bool
{
    return (bool) preg_match('/^(api|phone|n\d{1,9}|u\d{1,9})$/', $line);
}

/** The account's extra numbers. */
function wa_numbers_for(int $clientId, bool $activeOnly = true): array
{
    try {
        return db_all("SELECT * FROM wa_numbers WHERE client_id=?" . ($activeOnly ? " AND active=1" : '') . " ORDER BY id", [$clientId]);
    } catch (Throwable $e) { return []; }
}

function wa_number(int $id, int $clientId): ?array
{
    try { return db_row("SELECT * FROM wa_numbers WHERE id=? AND client_id=?", [$id, $clientId]) ?: null; }
    catch (Throwable $e) { return null; }
}

/** "+20 100 123 4567"-ish, from digits. */
function wa_pretty_phone(?string $digits): string
{
    $d = preg_replace('/\D/', '', (string) $digits);
    return $d !== '' ? '+' . $d : '';
}

/**
 * Every line the account has, in a stable order: the main API number, the main phone, the extra
 * numbers, then (with $withUsers) salespeople's own phones.
 * Each: key, kind (cloud|personal), label, phone, ready (can send now), waba.
 */
function wa_lines(array $client, bool $withUsers = false): array
{
    $cache = &$GLOBALS['__wa_lines'];                      // per request; wa_lines_reset() after a change
    $cache ??= [];
    $ck = (int) $client['id'] . ($withUsers ? 'u' : '');
    if (isset($cache[$ck])) return $cache[$ck];
    $out = [];
    // The main API number: the account's own, or the platform's when it sends under our account.
    if (wa_phone_id($client) !== '') {
        $out['api'] = ['key' => 'api', 'kind' => 'cloud', 'label' => 'Business API number', 'phone' => wa_pretty_phone($client['api_display_phone'] ?? ''),
                       'ready' => wa_token($client) !== '', 'waba' => (string) ($client['waba_id'] ?? '')];
    }
    if (trim((string) ($client['personal_instance'] ?? '')) !== '' || ($client['personal_status'] ?? '') === 'connected' || ($client['channel'] ?? '') === 'personal') {
        $out['phone'] = ['key' => 'phone', 'kind' => 'personal', 'label' => 'Company phone', 'phone' => wa_pretty_phone($client['personal_msisdn'] ?? ''),
                         'ready' => ($client['personal_status'] ?? '') === 'connected', 'waba' => ''];
    }
    $nApi = isset($out['api']) ? 1 : 0; $nPh = isset($out['phone']) ? 1 : 0;
    foreach (wa_numbers_for((int) $client['id']) as $n) {
        $cloud = $n['kind'] === 'cloud';
        $default = $cloud ? 'API number ' . (++$nApi) : 'Phone ' . (++$nPh);
        $out['n' . $n['id']] = ['key' => 'n' . $n['id'], 'kind' => $cloud ? 'cloud' : 'personal', 'label' => trim((string) $n['label']) ?: $default,
                                'phone' => wa_pretty_phone($cloud ? $n['display_phone'] : $n['msisdn']),
                                'ready' => $cloud ? (!empty($n['access_token_enc']) || wa_token($client) !== '') && $n['phone_number_id'] : $n['status'] === 'connected',
                                'waba' => $cloud ? (string) ($n['waba_id'] ?: ($client['waba_id'] ?? '')) : '', 'id' => (int) $n['id']];
    }
    if ($withUsers) {
        try {
            foreach (db_all("SELECT uc.*, COALESCE(NULLIF(u.name,''), u.email) who FROM user_channels uc JOIN users u ON u.id=uc.user_id
                              WHERE uc.client_id=? ORDER BY u.name", [(int) $client['id']]) as $uc) {
                $out['u' . $uc['user_id']] = ['key' => 'u' . $uc['user_id'], 'kind' => 'personal', 'label' => $uc['who'] . "'s phone",
                                             'phone' => wa_pretty_phone($uc['msisdn']), 'ready' => $uc['status'] === 'connected', 'waba' => ''];
            }
        } catch (Throwable $e) {}
    }
    return $cache[$ck] = $out;
}

/** Forget the cached lines (after numbers are added or changed in the same request). */
function wa_lines_reset(): void
{
    unset($GLOBALS['__wa_lines'], $GLOBALS['__ib_offline']);
}

/** A line's name for people: "Sales (+20 100…)" — or the key when the line is gone. */
function wa_line_label(array $client, ?string $line, bool $withPhone = true): string
{
    if ($line === null || $line === '') return '';
    $l = wa_lines($client, true)[$line] ?? null;
    if (!$l) return match (true) { $line === 'api' => 'Business API number', $line === 'phone' => 'Company phone', default => 'Removed number' };
    return $l['label'] . ($withPhone && $l['phone'] !== '' ? ' (' . $l['phone'] . ')' : '');
}

/**
 * The client row to send through a line: its channel and credentials in place, and __line set
 * so the message is logged on that line. NULL when the account has no such line.
 */
function wa_line_client(array $client, string $line): ?array
{
    $base = $client;
    unset($base['__line'], $base['__wa_number'], $base['__user_channel'], $base['__user_id']);
    if ($line === 'api') return wa_phone_id($client) !== '' ? array_merge($base, ['channel' => 'cloud', '__line' => 'api']) : null;
    if ($line === 'phone') return array_merge($base, ['channel' => 'personal', '__line' => 'phone']);
    if (preg_match('/^n(\d+)$/', $line, $m)) {
        $n = wa_number((int) $m[1], (int) $client['id']);
        if (!$n || !(int) $n['active']) return null;
        if ($n['kind'] === 'cloud') {
            return array_merge($base, [
                'channel' => 'cloud', 'phone_number_id' => (string) $n['phone_number_id'], 'waba_id' => (string) ($n['waba_id'] ?: ($client['waba_id'] ?? '')),
                'access_token_enc' => $n['access_token_enc'] ?: ($client['access_token_enc'] ?? null),
                'app_secret_enc' => $n['app_secret_enc'] ?: ($client['app_secret_enc'] ?? null),
                '__line' => $line, '__wa_number' => (int) $n['id'],
            ]);
        }
        return array_merge($base, [
            'channel' => 'personal', 'personal_instance' => (string) ($n['instance'] ?: 'client' . (int) $client['id'] . 'n' . (int) $n['id']),
            'personal_status' => (string) $n['status'], 'personal_msisdn' => $n['msisdn'], 'personal_hook_secret' => $n['hook_secret'],
            'personal_hook_secret_prev' => $n['hook_secret_prev'], 'personal_connected_at' => $n['connected_at'],
            '__line' => $line, '__wa_number' => (int) $n['id'],
        ]);
    }
    if (preg_match('/^u(\d+)$/', $line, $m)) {
        try { $uc = db_row("SELECT * FROM user_channels WHERE user_id=? AND client_id=?", [(int) $m[1], (int) $client['id']]); } catch (Throwable $e) { $uc = null; }
        if (!$uc || !function_exists('user_channel_client')) return null;
        return array_merge(user_channel_client($base, $uc), ['__line' => $line]);
    }
    return null;
}

/** Why a line cannot send right now ('' when it can). */
function wa_line_blocked(array $client, string $line): string
{
    $l = wa_lines($client, true)[$line] ?? null;
    if (!$l) return 'That WhatsApp number is no longer on this account.';
    if ($l['ready']) return '';
    return $l['kind'] === 'personal' ? $l['label'] . ' is not linked right now — scan its QR code in Settings → WhatsApp numbers.'
                                     : $l['label'] . ' is missing its access token or phone number ID.';
}

/** The WhatsApp Business Account a line's templates come from. */
function wa_line_waba(array $client, string $line): string
{
    return (string) (wa_lines($client)[$line]['waba'] ?? ($client['waba_id'] ?? ''));
}

/** The old per-message "via" word for a line (messages.via, the Team page's words). */
function wa_line_via(array $client, string $line): string
{
    if ($line === 'api') return 'api';
    if ($line === 'phone') return 'company_personal';
    if ($line[0] === 'u') return 'own';
    return (wa_lines($client)[$line]['kind'] ?? 'cloud') === 'cloud' ? 'api' : 'company_personal';
}

/**
 * The lines this person may send from. The Admin decides per person on the Team page
 * (users.send_via): any of the account's numbers, one number, their own phone, or none.
 * The system (automations, campaigns) and the platform operator may use every business line.
 */
function wa_lines_for_user(array $client, ?array $user): array
{
    $all = wa_lines($client, true);
    $business = array_filter($all, fn($l) => $l['key'][0] !== 'u');
    if (!$user || ($user['role'] ?? '') === 'admin') return $business;
    $via = (string) ($user['send_via'] ?? '');
    return match (true) {
        $via === '' || $via === 'any' => $business,
        $via === 'none' => [],
        $via === 'api' => array_intersect_key($all, ['api' => 1]),
        $via === 'company_personal' => array_intersect_key($all, ['phone' => 1]),
        $via === 'own' => array_intersect_key($all, ['u' . (int) $user['id'] => 1]),
        (bool) preg_match('/^n\d+$/', $via) => array_intersect_key($all, [$via => 1]),
        default => [],
    };
}

/**
 * Choose the line for a send and return the client row for it.
 * Order: the line asked for (when allowed), the contact's last line (when allowed), the account's
 * usual line (when allowed), then the first line this person may use.
 * @return array{ok:bool, client?:array, line?:string, via?:string, error?:string}
 */
function wa_line_pick(array $client, ?array $user, ?string $wanted = null, ?array $contact = null): array
{
    $allowed = wa_lines_for_user($client, $user);
    if (!$allowed) {
        return ['ok' => false, 'via' => 'none', 'error' => $user && ($user['send_via'] ?? '') === 'none'
            ? 'Your account admin has not set you up to send messages. Ask them to choose a number for you on the Team page.'
            : 'There is no WhatsApp number to send from. Connect one in Settings → WhatsApp numbers.'];
    }
    $order = array_filter([$wanted, $contact['last_line'] ?? null, wa_line_default($client)]);
    $line = null;
    foreach ($order as $k) if (isset($allowed[$k])) { $line = $k; break; }
    if ($wanted !== null && $wanted !== '' && !isset($allowed[$wanted])) {
        return ['ok' => false, 'via' => 'none', 'error' => 'You are not set up to send from ' . wa_line_label($client, $wanted, false) . '.'];
    }
    $line ??= array_key_first($allowed);
    if (($why = wa_line_blocked($client, $line)) !== '') return ['ok' => false, 'via' => wa_line_via($client, $line), 'line' => $line, 'error' => $why];
    $row = wa_line_client($client, $line);
    if (!$row) return ['ok' => false, 'via' => 'none', 'line' => $line, 'error' => 'That WhatsApp number is no longer on this account.'];
    return ['ok' => true, 'client' => $row, 'line' => $line, 'via' => wa_line_via($client, $line)];
}

/**
 * The account a Business API phone number id belongs to, as the client row for that line — the
 * main number ('api') or an extra one ('n<id>', with its own app secret when it has one).
 */
function wa_client_by_pnid(string $pnid): ?array
{
    if ($pnid === '') return null;
    $c = db_row("SELECT * FROM clients WHERE phone_number_id = ?", [$pnid]);
    if ($c) return array_merge($c, ['__line' => 'api']);
    try { $n = db_row("SELECT * FROM wa_numbers WHERE phone_number_id=? AND kind='cloud' AND active=1", [$pnid]); } catch (Throwable $e) { $n = null; }
    if (!$n) return null;
    $c = db_row("SELECT * FROM clients WHERE id=?", [(int) $n['client_id']]);
    return $c ? wa_line_client($c, 'n' . $n['id']) : null;
}

/** Remember the line a contact last spoke on. */
function wa_line_touch(int $contactId, string $line): void
{
    if (!wa_lines_ready() || !wa_line_valid($line)) return;
    try { db_run("UPDATE contacts SET last_line=? WHERE id=? AND (last_line IS NULL OR last_line<>?)", [$line, $contactId, $line]); } catch (Throwable $e) {}
}

/** How many more numbers the account may add. */
function wa_numbers_room(array $client): int
{
    return max(0, (int) ($client['max_numbers'] ?? 0) - count(wa_numbers_for((int) $client['id'])));
}

/** Ask Meta about a Business API number: its display number and name. ['ok','phone','name','error']. */
function wa_number_check(array $lineClient): array
{
    $token = wa_token($lineClient); $pnid = wa_phone_id($lineClient);
    if ($token === '' || $pnid === '') return ['ok' => false, 'error' => 'Missing access token or phone number ID.'];
    $r = wa_request('GET', wa_graph_base() . '/' . rawurlencode($pnid) . '?fields=display_phone_number,verified_name', $token);
    if ($r['http'] < 200 || $r['http'] >= 300) return ['ok' => false, 'error' => 'Meta said: ' . ($r['error'] ?: 'HTTP ' . $r['http'])];
    return ['ok' => true, 'phone' => preg_replace('/\D/', '', (string) ($r['json']['display_phone_number'] ?? '')), 'name' => (string) ($r['json']['verified_name'] ?? ''), 'error' => ''];
}

/** Options for a "Send from" select: key => label, only lines this person may use. */
function wa_line_options(array $client, ?array $user, ?string $kind = null): array
{
    $o = [];
    foreach (wa_lines_for_user($client, $user) as $k => $l) {
        if ($kind !== null && $l['kind'] !== $kind) continue;
        $o[$k] = $l['label'] . ($l['phone'] !== '' ? ' (' . $l['phone'] . ')' : '') . ($l['ready'] ? '' : ' — not connected');
    }
    return $o;
}
