<?php
declare(strict_types=1);

/**
 * SMS: gateways, message parts and cost, queueing, sending, delivery reports and opt-outs.
 *
 *   gateways   A platform gateway (client_id NULL, set up by the platform admin) or a client's own.
 *              A client uses its own when the admin allows it (sms_mode = own) and it has an active
 *              one; otherwise its chosen platform gateway, otherwise the platform default.
 *   cost       credits = parts × the client's sms_rate. A part is 160 GSM characters (153 when the
 *              message is split) or 70 Arabic/unicode characters (67 when split).
 *   queue      Every SMS is a row in sms_messages. sms_dispatch() (cron/dispatch.php) sends what is
 *              due: charges credits, calls the provider, refunds on failure, retries temporary
 *              problems three times, logs it in the person's conversation, and tells the client's
 *              system (callback_url / outgoing webhook "sms.status").
 */

require_once __DIR__ . '/sms_providers.php';
require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/push.php';         // setting_get / setting_set
require_once __DIR__ . '/permissions.php';  // client_modules
require_once __DIR__ . '/campaign.php';     // campaign_render_text, campaign_refresh_counts
require_once __DIR__ . '/inbox.php';        // msg_log

/* ───────────────────────── parts and cost ───────────────────────── */

/** GSM 03.38 basic set and the extension characters that take two places. */
function sms_gsm_sets(): array
{
    static $s = null;
    if ($s === null) {
        $basic = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $s = [array_flip(mb_str_split($basic)), array_flip(mb_str_split("^{}\\[~]|€\f"))];
    }
    return $s;
}

/** ['encoding' => gsm|ucs2, 'chars' => counted length, 'parts' => n, 'per_part' => size, 'left' => room in the last part]. */
function sms_parts(string $text): array
{
    [$basic, $ext] = sms_gsm_sets();
    $chars = mb_str_split($text);
    $gsm = true; $len = 0;
    foreach ($chars as $ch) {
        if (isset($basic[$ch])) $len++;
        elseif (isset($ext[$ch])) $len += 2;
        else { $gsm = false; break; }
    }
    if (!$gsm) {
        // UCS-2: characters outside the basic plane (most emoji) take two places.
        $len = 0;
        foreach ($chars as $ch) $len += strlen(mb_convert_encoding($ch, 'UTF-16BE', 'UTF-8')) > 2 ? 2 : 1;
    }
    [$single, $multi] = $gsm ? [160, 153] : [70, 67];
    $parts = $len === 0 ? 1 : ($len <= $single ? 1 : (int) ceil($len / $multi));
    $per = $parts === 1 ? $single : $multi;
    return ['encoding' => $gsm ? 'gsm' : 'ucs2', 'chars' => $len, 'parts' => $parts, 'per_part' => $per, 'left' => $parts * $per - $len];
}

function sms_rate(array $client): int
{
    return max(0, (int) ($client['sms_rate'] ?? 1));
}

/** Fill {{name}}, {{first_name}}, {{owner_name}}, {{cf:field}}… — the CRM's variables — and contact attributes. */
function sms_render(string $text, array $contact, array $ctx = []): string
{
    if (!$contact) return $text;
    $tokens = function_exists('crm_tpl_tokens') ? crm_tpl_tokens((int) ($contact['client_id'] ?? 0)) : [];
    $text = (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+(?::[a-z0-9_]+)?)\s*\}\}/i', function ($m) use ($tokens, $contact, $ctx) {
        $k = strtolower($m[1]);
        if (isset($tokens[$k]) && $k !== 'text' && function_exists('crm_tpl_value')) {
            $v = crm_tpl_value($k, $contact, $ctx);
            return $v === '-' ? '' : $v;
        }
        return $m[0];
    }, $text);
    return function_exists('campaign_render_text') ? campaign_render_text($text, $contact) : $text;
}

/* ───────────────────────── gateways ───────────────────────── */

function sms_ready(): bool
{
    static $r = null;
    return $r ??= db_has_column('clients', 'sms_rate');
}

/** A gateway row with its settings decrypted into 'config'. */
function sms_gateway_load(?array $row): ?array
{
    if (!$row) return null;
    $row['config'] = $row['config_enc'] ? (json_decode((string) decrypt_secret((string) $row['config_enc']), true) ?: []) : [];
    return $row;
}

function sms_gateway(int $id): ?array
{
    return sms_gateway_load(db_row("SELECT * FROM sms_gateways WHERE id=?", [$id]) ?: null);
}

/** The gateway this client sends through, or null when none is set up. */
function sms_client_gateway(array $client): ?array
{
    $cid = (int) $client['id'];
    if (($client['sms_mode'] ?? 'platform') === 'own') {
        $own = db_row("SELECT * FROM sms_gateways WHERE client_id=? AND active=1 ORDER BY is_default DESC, id LIMIT 1", [$cid]);
        if ($own) return sms_gateway_load($own);
    }
    if (!empty($client['sms_gateway_id'])) {
        $g = db_row("SELECT * FROM sms_gateways WHERE id=? AND client_id IS NULL AND active=1", [(int) $client['sms_gateway_id']]);
        if ($g) return sms_gateway_load($g);
    }
    return sms_gateway_load(db_row("SELECT * FROM sms_gateways WHERE client_id IS NULL AND active=1 ORDER BY is_default DESC, id LIMIT 1") ?: null);
}

/** Sender names this client may use: their own gateway's, or what the admin approved for them. */
function sms_client_senders(array $client): array
{
    $gw = sms_client_gateway($client);
    $list = fn($s) => array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $s)), 'strlen')));
    if ($gw && $gw['client_id'] !== null) return $list($gw['senders'] . ',' . $gw['default_sender']);
    $approved = $list($client['sms_senders'] ?? '');
    if ($approved) return $approved;
    return $gw ? $list((string) $gw['default_sender']) : [];
}

/** Save a gateway (new or existing). Blank secret fields keep what was stored. */
function sms_gateway_save(?int $clientId, array $in, ?int $id = null): int
{
    $provider = isset(sms_provider_catalog()[$in['provider'] ?? '']) ? (string) $in['provider'] : 'http';
    $old = $id ? sms_gateway($id) : null;
    $cfg = [];
    foreach (sms_provider_catalog()[$provider]['fields'] as $k => [$label, $type]) {
        $v = trim((string) ($in['cfg'][$k] ?? ''));
        if ($type === 'secret' && $v === '' && $old && ($old['provider'] === $provider)) $v = (string) ($old['config'][$k] ?? '');
        if ($v !== '') $cfg[$k] = $type === 'textarea' ? mb_substr($v, 0, 2000) : mb_substr($v, 0, 500);
    }
    foreach (['dlr_id', 'dlr_status'] as $k) if (trim((string) ($in['cfg'][$k] ?? '')) !== '') $cfg[$k] = mb_substr(trim((string) $in['cfg'][$k]), 0, 60);
    $vals = [
        'provider' => $provider,
        'name' => mb_substr(trim((string) ($in['name'] ?? '')) ?: sms_provider_label($provider), 0, 80),
        'config_enc' => encrypt_secret(json_encode($cfg, JSON_UNESCAPED_UNICODE)),
        'default_sender' => mb_substr(trim((string) ($in['default_sender'] ?? '')), 0, 40) ?: null,
        'senders' => mb_substr(implode(',', array_filter(array_map('trim', explode(',', (string) ($in['senders'] ?? ''))))), 0, 500) ?: null,
        'active' => !empty($in['active']) ? 1 : 0,
        'rate_per_sec' => max(1, min(200, (int) ($in['rate_per_sec'] ?? 10))),
    ];
    if ($id) {
        db_run("UPDATE sms_gateways SET provider=?, name=?, config_enc=?, default_sender=?, senders=?, active=?, rate_per_sec=?, updated_at=NOW() WHERE id=?",
               array_merge(array_values($vals), [$id]));
    } else {
        $id = db_insert("INSERT INTO sms_gateways (provider,name,config_enc,default_sender,senders,active,rate_per_sec,client_id,dlr_token,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())",
                        array_merge(array_values($vals), [$clientId, bin2hex(random_bytes(16))]));
    }
    if (!empty($in['is_default'])) {
        db_run("UPDATE sms_gateways SET is_default=(id=?) WHERE " . ($clientId === null ? 'client_id IS NULL' : 'client_id=' . (int) $clientId), [$id]);
    }
    return (int) $id;
}

/** Where providers report delivery for this gateway. */
function sms_dlr_url(array $gw): string
{
    $base = function_exists('app_base_url') ? rtrim(app_base_url(), '/') : '';
    if ($base === '') return '';
    return $base . '/webhook_sms.php?g=' . (int) $gw['id'] . '&t=' . $gw['dlr_token'];
}

/* ───────────────────────── queueing ───────────────────────── */

/**
 * Queue SMS. $recipients: list of ['phone' => raw, 'contact_id' => ?int, 'campaign_message_id' => ?int].
 * $opts: sender, source, campaign_id, user_id, api_key_id, reference, callback_url, scheduled_at, render (bool), ctx.
 * Returns ['ok', 'queued' => [[id, to, parts, credits]…], 'skipped' => [[to, reason]…], 'credits', 'error'].
 */
function sms_queue(array $client, array $recipients, string $text, array $opts = []): array
{
    $cid = (int) $client['id'];
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'error' => 'Write the message first.', 'queued' => [], 'skipped' => [], 'credits' => 0];
    if (!sms_client_gateway($client)) return ['ok' => false, 'error' => 'No SMS provider is connected for this account yet.', 'queued' => [], 'skipped' => [], 'credits' => 0];
    $senders = sms_client_senders($client);
    $sender = trim((string) ($opts['sender'] ?? ''));
    if ($sender === '' && $senders) $sender = $senders[0];
    if ($senders && !in_array($sender, $senders, true)) return ['ok' => false, 'error' => 'The sender "' . $sender . '" is not approved for this account.', 'queued' => [], 'skipped' => [], 'credits' => 0];
    $rate = sms_rate($client);
    $country = (string) ($client['default_country'] ?? '');
    $seen = []; $queued = []; $skipped = []; $total = 0;
    $render = $opts['render'] ?? true;
    $contactsById = [];
    $ids = array_values(array_filter(array_map(fn($r) => (int) ($r['contact_id'] ?? 0), $recipients)));
    foreach (array_chunk($ids, 500) as $chunk) {
        foreach (db_all("SELECT * FROM contacts WHERE client_id=? AND id IN (" . implode(',', $chunk) . ")", [$cid]) as $c) $contactsById[(int) $c['id']] = $c;
    }
    $optedOut = [];
    foreach (db_all("SELECT phone_e164 FROM contacts WHERE client_id=? AND sms_opt_out_at IS NOT NULL AND phone_e164 IS NOT NULL", [$cid]) as $r) $optedOut[$r['phone_e164']] = true;

    foreach ($recipients as $r) {
        $c = $contactsById[(int) ($r['contact_id'] ?? 0)] ?? null;
        $raw = (string) ($r['phone'] ?? ($c['phone_e164'] ?? ''));
        $to = normalize_phone($raw, $country);
        if ($to === '') { $skipped[] = [$raw, 'Not a valid phone number']; continue; }
        if (isset($seen[$to])) { $skipped[] = [$to, 'Duplicate number']; continue; }
        $seen[$to] = true;
        if (isset($optedOut[$to]) || ($c && !empty($c['sms_opt_out_at']))) { $skipped[] = [$to, 'Opted out of SMS']; continue; }
        if (!$c && empty($opts['no_contact'])) $c = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $to]) ?: null;
        $body = $render && $c ? sms_render($text, $c, (array) ($opts['ctx'] ?? [])) : $text;
        // A variable nobody could fill (a pasted number with no contact) is left out, not sent as "{{name}}".
        if ($render) $body = trim((string) preg_replace(['/\{\{\s*[a-z0-9_:]+\s*\}\}/i', '/ {2,}/'], ['', ' '], $body));
        $p = sms_parts($body);
        $credits = $p['parts'] * $rate;
        $id = db_insert("INSERT INTO sms_messages (client_id,contact_id,to_e164,sender,body,parts,encoding,credits,source,campaign_id,campaign_message_id,api_key_id,user_id,
                                                   reference,callback_url,scheduled_at,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'queued',NOW())",
                        [$cid, $c ? (int) $c['id'] : null, $to, $sender !== '' ? $sender : null, $body, $p['parts'], $p['encoding'], $credits,
                         (string) ($opts['source'] ?? 'manual'), $opts['campaign_id'] ?? null, $r['campaign_message_id'] ?? null, $opts['api_key_id'] ?? null,
                         $opts['user_id'] ?? null, isset($opts['reference']) && $opts['reference'] !== '' ? mb_substr((string) $opts['reference'], 0, 120) : null,
                         !empty($opts['callback_url']) ? mb_substr((string) $opts['callback_url'], 0, 500) : null, $opts['scheduled_at'] ?? null]);
        $queued[] = ['id' => (int) $id, 'to' => $to, 'parts' => $p['parts'], 'credits' => $credits, 'contact_id' => $c ? (int) $c['id'] : null];
        $total += $credits;
    }
    return ['ok' => true, 'queued' => $queued, 'skipped' => $skipped, 'credits' => $total, 'error' => '', 'sender' => $sender];
}

/** Queue one and send it straight away (CRM, alerts, tests, automations). Same answer shape as sms_send_row(). */
function sms_send_now(array $client, string $phone, string $text, array $opts = [], ?int $contactId = null): array
{
    $q = sms_queue($client, [['phone' => $phone, 'contact_id' => $contactId]], $text, $opts);
    if (!$q['ok']) return ['ok' => false, 'error' => $q['error'], 'id' => null];
    if (!$q['queued']) return ['ok' => false, 'error' => $q['skipped'][0][1] ?? 'Not sent.', 'id' => null];
    $row = db_row("SELECT * FROM sms_messages WHERE id=?", [$q['queued'][0]['id']]);
    return sms_send_row($client, $row);
}

/* ───────────────────────── sending ───────────────────────── */

/** Send one queued row now. Returns ['ok', 'error', 'id' (our id), 'retry']. */
function sms_send_row(array $client, array $m): array
{
    $id = (int) $m['id'];
    $cid = (int) $client['id'];
    $fin = function (string $status, string $err = '', string $code = '') use ($id) {
        db_run("UPDATE sms_messages SET status=?, error_title=?, error_code=?, claimed_by=NULL, updated_at=NOW() WHERE id=?",
               [$status, $err !== '' ? mb_substr($err, 0, 255) : null, $code !== '' ? mb_substr($code, 0, 40) : null, $id]);
    };
    if (($client['status'] ?? '') !== 'active') { $fin('failed', 'The account is not active.', 'inactive'); return ['ok' => false, 'error' => 'The account is not active.', 'id' => $id]; }
    if (function_exists('client_modules') && !in_array('sms', client_modules($client), true)) { $fin('failed', 'SMS is not part of this plan.', 'module_off'); return ['ok' => false, 'error' => 'SMS is not part of this plan.', 'id' => $id]; }
    $gw = sms_client_gateway($client);
    if (!$gw) { $fin('failed', 'No SMS provider is connected.', 'no_gateway'); return ['ok' => false, 'error' => 'No SMS provider is connected.', 'id' => $id]; }
    $credits = (int) $m['credits'];
    if ($credits > 0 && credits_adjust($cid, -$credits, 'sms', $m['campaign_id'] ? (int) $m['campaign_id'] : null) === null) {
        $fin('failed', 'Not enough credits.', 'no_credits');
        sms_after($client, db_row("SELECT * FROM sms_messages WHERE id=?", [$id]));
        return ['ok' => false, 'error' => 'Not enough credits.', 'id' => $id];
    }
    db_run("UPDATE sms_messages SET status='sending', gateway_id=?, attempts=attempts+1, updated_at=NOW() WHERE id=?", [(int) $gw['id'], $id]);
    $r = sms_provider_send($gw, (string) $m['to_e164'], (string) $m['body'], (string) ($m['sender'] ?? ''), 'rv' . $id, $m['encoding'] === 'ucs2', sms_dlr_url($gw));
    if ($r['ok']) {
        db_run("UPDATE sms_messages SET status='sent', provider_msg_id=?, sent_at=NOW(), error_title=NULL, error_code=NULL, claimed_by=NULL, updated_at=NOW() WHERE id=?",
               [$r['id'] !== null && $r['id'] !== '' ? mb_substr((string) $r['id'], 0, 120) : null, $id]);
    } else {
        if ($credits > 0) credits_adjust($cid, $credits, 'refund_failed', $m['campaign_id'] ? (int) $m['campaign_id'] : null);
        $attempts = (int) $m['attempts'] + 1;
        if ($r['retry'] && $attempts < 3) {
            db_run("UPDATE sms_messages SET status='queued', next_attempt_at=NOW() + INTERVAL ? MINUTE, error_title=?, error_code=?, claimed_by=NULL, updated_at=NOW() WHERE id=?",
                   [[1, 5, 25][$attempts - 1] ?? 25, mb_substr((string) $r['error'], 0, 255), mb_substr((string) $r['code'], 0, 40), $id]);
            return ['ok' => false, 'error' => (string) $r['error'], 'id' => $id, 'retry' => true];
        }
        $fin('failed', (string) $r['error'], (string) $r['code']);
    }
    sms_after($client, db_row("SELECT * FROM sms_messages WHERE id=?", [$id]));
    return ['ok' => $r['ok'], 'error' => $r['ok'] ? '' : (string) $r['error'], 'id' => $id];
}

/** After a final status: the conversation, the campaign, and the client's system. */
function sms_after(array $client, ?array $m): void
{
    if (!$m) return;
    $cid = (int) $client['id'];
    if (in_array($m['status'], ['sent', 'failed'], true) && $m['contact_id'] && function_exists('msg_log') && empty($m['_logged'])) {
        try {
            msg_log($cid, (int) $m['contact_id'], 'out', (string) $m['body'], ['source' => ['campaign' => 'campaign', 'automation' => 'automation', 'api' => 'api'][$m['source']] ?? 'manual',
                    'status' => $m['status'] === 'sent' ? 'sent' : 'failed', 'error' => $m['error_title'], 'channel' => 'sms', 'ext' => 'sms:' . $m['id'],
                    'sent_by' => $m['user_id'] ? (int) $m['user_id'] : null]);
        } catch (Throwable $e) { error_log('sms msg_log: ' . $e->getMessage()); }
    }
    if ($m['campaign_message_id']) {
        $st = ['sent' => 'sent', 'delivered' => 'delivered', 'failed' => 'failed', 'undelivered' => 'failed', 'skipped' => 'failed'][$m['status']] ?? null;
        if ($st) {
            db_run("UPDATE campaign_messages SET status=?, wa_message_id=?, error_code=?, error_title=?, sent_at=COALESCE(sent_at, ?), delivered_at=IF(?='delivered', NOW(), delivered_at), claimed_by=NULL, updated_at=NOW() WHERE id=?",
                   [$st, 'sms:' . $m['id'], $m['error_code'], $m['error_title'], $m['sent_at'], $st, (int) $m['campaign_message_id']]);
            if (function_exists('campaign_refresh_counts') && $m['campaign_id']) campaign_refresh_counts((int) $m['campaign_id']);
        }
    }
    sms_notify_client($client, $m);
}

/** The secret that signs this account's SMS callbacks; made on first use, kept encrypted. */
function sms_callback_secret(int $clientId, bool $renew = false): string
{
    $k = 'sms_cb_secret_' . $clientId;
    $enc = $renew ? null : setting_get($k);
    if ($enc) return (string) decrypt_secret($enc);
    $s = bin2hex(random_bytes(24));
    setting_set($k, encrypt_secret($s));
    return $s;
}

/** The status as the API shows it. */
function sms_public(array $m): array
{
    return ['id' => (int) $m['id'], 'to' => '+' . $m['to_e164'], 'sender' => $m['sender'], 'status' => $m['status'], 'parts' => (int) $m['parts'],
            'encoding' => $m['encoding'], 'credits' => (int) $m['credits'], 'reference' => $m['reference'], 'error' => $m['error_title'],
            'created_at' => $m['created_at'] ? date('c', strtotime((string) $m['created_at'])) : null,
            'sent_at' => $m['sent_at'] ? date('c', strtotime((string) $m['sent_at'])) : null,
            'delivered_at' => $m['delivered_at'] ? date('c', strtotime((string) $m['delivered_at'])) : null];
}

/** Tell the client's system: the message's callback_url, and the account's webhooks subscribed to sms.status. */
function sms_notify_client(array $client, array $m): void
{
    $body = json_encode(['event' => 'sms.status', 'data' => sms_public($m), 'sent_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!empty($m['callback_url'])) {
        // Signed with the account's SMS callback secret (shown on SMS → API), so the receiver can check it came from us.
        $ts = (string) time();
        $hdr = ['Content-Type: application/json', 'X-Revenect-Event: sms.status', 'X-Revenect-Timestamp: ' . $ts,
                'X-Revenect-Signature: sha256=' . hash_hmac('sha256', $ts . '.' . $body, sms_callback_secret((int) $client['id']))];
        if (function_exists('safe_http_request')) safe_http_request('POST', (string) $m['callback_url'], $body, $hdr);
    }
    // The account's outgoing webhooks (CRM → Integrations) that listen for "sms.status".
    if ($m['contact_id'] && function_exists('crm_hook')) {
        try { crm_hook('sms.status', (int) $client['id'], (int) $m['contact_id'], sms_public($m)); } catch (Throwable $e) {}
    }
}

/**
 * Send what is due (cron/dispatch.php): queued rows whose time has come, a batch per client,
 * keeping each gateway under its messages-per-second limit. Returns [sent, failed].
 */
function sms_dispatch(string $workerId, int $cap = 500): array
{
    if (!sms_ready()) return [0, 0];
    $sent = 0; $failed = 0;
    $rows = db_all("SELECT id FROM sms_messages WHERE status='queued' AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                     AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()) ORDER BY id LIMIT " . (int) $cap);
    if (!$rows) return [0, 0];
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    db_run("UPDATE sms_messages SET status='sending', claimed_by=?, updated_at=NOW() WHERE status='queued' AND id IN (" . implode(',', $ids) . ")", [$workerId]);
    $mine = db_all("SELECT * FROM sms_messages WHERE claimed_by=? AND status='sending' ORDER BY id", [$workerId]);
    $clients = []; $paceAt = [];
    foreach ($mine as $m) {
        $cid = (int) $m['client_id'];
        $clients[$cid] ??= db_row("SELECT * FROM clients WHERE id=?", [$cid]) ?: [];
        if (!$clients[$cid]) continue;
        // A campaign that was paused or cancelled meanwhile.
        if ($m['campaign_id']) {
            $cs = (string) db_val("SELECT status FROM campaigns WHERE id=?", [(int) $m['campaign_id']]);
            if ($cs === 'paused' || $cs === 'scheduled') { db_run("UPDATE sms_messages SET status='queued', claimed_by=NULL WHERE id=?", [(int) $m['id']]); continue; }
            if ($cs === 'canceled') { db_run("UPDATE sms_messages SET status='skipped', error_title='Campaign canceled', claimed_by=NULL WHERE id=?", [(int) $m['id']]); continue; }
        }
        $gw = sms_client_gateway($clients[$cid]);
        if ($gw) {
            $gap = 1 / max(1, (int) $gw['rate_per_sec']);
            $last = $paceAt[(int) $gw['id']] ?? 0;
            $wait = $last + $gap - microtime(true);
            if ($wait > 0) usleep((int) ($wait * 1e6));
            $paceAt[(int) $gw['id']] = microtime(true);
        }
        $m['status'] = 'queued';
        $r = sms_send_row($clients[$cid], $m);
        if ($r['ok']) $sent++; elseif (empty($r['retry'])) $failed++;
    }
    return [$sent, $failed];
}

/** A delivery report arrived: update the message, its campaign row and the client's system. */
function sms_apply_dlr(array $gw, string $providerId, string $status, string $err = ''): bool
{
    $m = db_row("SELECT * FROM sms_messages WHERE provider_msg_id=? AND gateway_id=? ORDER BY id DESC LIMIT 1", [$providerId, (int) $gw['id']]);
    if (!$m && preg_match('/^rv(\d+)$/', $providerId, $x)) $m = db_row("SELECT * FROM sms_messages WHERE id=? AND gateway_id=?", [(int) $x[1], (int) $gw['id']]);
    if (!$m) return false;
    if ($status === 'sent' || $m['status'] === $status) return true;
    db_run("UPDATE sms_messages SET status=?, delivered_at=IF(?='delivered', NOW(), delivered_at), error_title=IF(?<>'', ?, error_title), updated_at=NOW() WHERE id=?",
           [$status, $status, $err, mb_substr($err, 0, 255), (int) $m['id']]);
    $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $m['client_id']]);
    $m = db_row("SELECT * FROM sms_messages WHERE id=?", [(int) $m['id']]);
    if ($m['contact_id']) db_run("UPDATE messages SET status=? WHERE ext_id=?", [$status === 'delivered' ? 'delivered' : 'failed', 'sms:' . $m['id']]);
    if ($client) { $m['_logged'] = true; sms_after($client, $m); }
    return true;
}

/* ───────────────────────── campaigns ───────────────────────── */

/**
 * An SMS campaign: one campaigns row (channel sms) and a campaign_messages row + sms_messages row per
 * recipient. $recipients as for sms_queue(). Returns [campaign id, result of sms_queue].
 */
function sms_campaign_create(array $client, string $name, string $text, array $recipients, array $opts = []): array
{
    $cid = (int) $client['id'];
    $sched = $opts['scheduled_at'] ?? null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $k = db_insert("INSERT INTO campaigns (client_id,name,channel,list_id,body_text,variable_map,status,scheduled_at,created_at,started_at) VALUES (?,?,?,?,?,?,?,?,NOW(),?)",
                       [$cid, mb_substr($name, 0, 190), 'sms', ($opts['list_id'] ?? null) ?: null, $text, json_encode(['sender' => $opts['sender'] ?? ''], JSON_UNESCAPED_UNICODE),
                        $sched ? 'scheduled' : 'sending', $sched, $sched ? null : date('Y-m-d H:i:s')]);
        $q = sms_queue($client, $recipients, $text, $opts + ['source' => 'campaign', 'campaign_id' => $k]);
        if (!$q['ok'] || !$q['queued']) { $pdo->rollBack(); return [0, $q['ok'] ? ['ok' => false, 'error' => 'Nobody to send to: ' . ($q['skipped'] ? count($q['skipped']) . ' skipped (invalid, duplicate or opted out).' : 'the audience is empty.')] + $q : $q]; }
        if ($q['credits'] > (int) db_val("SELECT credits_balance FROM clients WHERE id=?", [$cid])) {
            $pdo->rollBack();
            return [0, ['ok' => false, 'error' => 'This needs ' . number_format($q['credits']) . ' credits; the account has ' . number_format((int) $client['credits_balance']) . '.'] + $q];
        }
        foreach ($q['queued'] as $qm) {
            $cm = db_insert("INSERT INTO campaign_messages (campaign_id,client_id,contact_id,phone_e164,recipient_ext,status,updated_at) VALUES (?,?,?,?,?,'queued',NOW())",
                            [$k, $cid, $qm['contact_id'], $qm['to'], 'sms:' . $qm['id']]);
            db_run("UPDATE sms_messages SET campaign_message_id=? WHERE id=?", [$cm, $qm['id']]);
        }
        db_run("UPDATE campaigns SET total_count=? WHERE id=?", [count($q['queued']), $k]);
        $pdo->commit();
        return [$k, $q];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('sms_campaign_create: ' . $e->getMessage());
        return [0, ['ok' => false, 'error' => 'Could not create the campaign. Please try again.', 'queued' => [], 'skipped' => [], 'credits' => 0]];
    }
}

/** Scheduled SMS campaigns whose time came: their messages carry the same time, so only the status changes. */
function sms_campaigns_promote(): void
{
    db_run("UPDATE campaigns SET status='sending', started_at=COALESCE(started_at,NOW()) WHERE channel='sms' AND status='scheduled' AND scheduled_at <= NOW()");
}

/* ───────────────────────── opt-out ───────────────────────── */

function sms_opt_out(int $clientId, string $phone, bool $out = true): bool
{
    $c = db_row("SELECT id FROM contacts WHERE client_id=? AND phone_e164=?", [$clientId, $phone]);
    if (!$c) {
        if (!$out) return false;
        db_insert("INSERT INTO contacts (client_id,phone_e164,opt_in_status,source,created_at,sms_opt_out_at) VALUES (?,?,'in','sms_optout',NOW(),NOW())", [$clientId, $phone]);
        return true;
    }
    db_run("UPDATE contacts SET sms_opt_out_at=" . ($out ? 'NOW()' : 'NULL') . " WHERE id=?", [(int) $c['id']]);
    return true;
}
