<?php
declare(strict_types=1);

/**
 * Zoho: CRM (leads, contacts, deals both ways, conversations logged as notes), Books and
 * Inventory (invoices, payments, sales orders → customer messages and automation triggers).
 *
 * Connecting, either way:
 *   - Connect button: the platform's Zoho client (Admin → Settings → Zoho client), Zoho's OAuth
 *     in the client's data centre, offline access → a refresh token we keep encrypted.
 *   - Keys: a Self Client the client makes in their own Zoho API console — its client ID, secret
 *     and a grant code (or a refresh token).
 *
 * Revenect → Zoho CRM: crm_hook() queues a push for every lead change (crm_zoho_queue in
 * crm_integrations.php); the worker sends them. Zoho → Revenect: Zoho's Notifications API calls
 * webhook_zoho.php, and a 5-minute poll catches anything missed. Loops are stopped two ways:
 * changes made because of Zoho carry origin 'zoho_crm' (never pushed back), and each link keeps a
 * fingerprint of what was last sent or received, so Zoho echoing our own change is ignored.
 */

/** Zoho's data centres: where the account lives decides both the sign-in and the API address. */
function zoho_dcs(): array
{
    return [
        'com'    => ['label' => 'Global / US — zoho.com',   'accounts' => 'https://accounts.zoho.com',    'api' => 'https://www.zohoapis.com'],
        'eu'     => ['label' => 'Europe — zoho.eu',         'accounts' => 'https://accounts.zoho.eu',     'api' => 'https://www.zohoapis.eu'],
        'in'     => ['label' => 'India — zoho.in',          'accounts' => 'https://accounts.zoho.in',     'api' => 'https://www.zohoapis.in'],
        'com.au' => ['label' => 'Australia — zoho.com.au',  'accounts' => 'https://accounts.zoho.com.au', 'api' => 'https://www.zohoapis.com.au'],
        'jp'     => ['label' => 'Japan — zoho.jp',          'accounts' => 'https://accounts.zoho.jp',     'api' => 'https://www.zohoapis.jp'],
        'ca'     => ['label' => 'Canada — zohocloud.ca',    'accounts' => 'https://accounts.zohocloud.ca','api' => 'https://www.zohoapis.ca'],
        'sa'     => ['label' => 'Saudi Arabia — zoho.sa',   'accounts' => 'https://accounts.zoho.sa',     'api' => 'https://www.zohoapis.sa'],
    ];
}

function zoho_dc(string $dc): string { return isset(zoho_dcs()[$dc]) ? $dc : 'com'; }

/** Hosts for a data centre. Tests point both at a mock with config('zoho_base'). */
function zoho_hosts(string $dc): array
{
    $o = rtrim((string) config('zoho_base', ''), '/');
    if ($o !== '') return ['accounts' => $o . '/accounts', 'api' => $o . '/api'];
    $d = zoho_dcs()[zoho_dc($dc)];
    return ['accounts' => $d['accounts'], 'api' => $d['api']];
}

/** The platform's Zoho client (Admin → Settings). */
function zoho_cfg(): array
{
    return ['client_id' => trim((string) setting_get('zoho_client_id', '')), 'client_secret' => decrypt_secret((string) setting_get('zoho_client_secret', ''))];
}

function zoho_configured(): bool
{
    $c = zoho_cfg();
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

function zoho_redirect_uri(): string { return integration_url('zoho_oauth.php'); }

function zoho_crm_v(): string
{
    $v = (string) config('zoho_crm_version', 'v7');
    return preg_match('/^v\d+$/', $v) ? $v : 'v7';
}

function zoho_scopes(string $provider): string
{
    return match ($provider) {
        'zoho_crm' => 'ZohoCRM.modules.ALL,ZohoCRM.settings.fields.READ,ZohoCRM.settings.modules.READ,ZohoCRM.users.READ,ZohoCRM.org.READ,ZohoCRM.notifications.ALL',
        'zoho_books' => 'ZohoBooks.invoices.READ,ZohoBooks.customerpayments.READ,ZohoBooks.salesorders.READ,ZohoBooks.contacts.READ,ZohoBooks.settings.READ',
        'zoho_inventory' => 'ZohoInventory.salesorders.READ,ZohoInventory.contacts.READ,ZohoInventory.packages.READ,ZohoInventory.settings.READ',
        default => '',
    };
}

/* ───────────────────────── connecting ───────────────────────── */

function zoho_auth_url(int $clientId, ?int $userId, string $provider, string $dc): string
{
    $state = integration_state_make($clientId, $userId, 'zoho', $provider . ':' . zoho_dc($dc));
    return zoho_hosts($dc)['accounts'] . '/oauth/v2/auth?' . http_build_query([
        'scope' => zoho_scopes($provider), 'client_id' => zoho_cfg()['client_id'], 'response_type' => 'code', 'access_type' => 'offline',
        'prompt' => 'consent', 'redirect_uri' => zoho_redirect_uri(), 'state' => $state,
    ]);
}

/** Zoho names the accounts server that issued the code; only one of its own is believed. */
function zoho_dc_from_server(string $server, string $fallback): string
{
    foreach (zoho_dcs() as $k => $d) if (rtrim($server, '/') === $d['accounts']) return $k;
    return $fallback;
}

function zoho_err(array $r): string
{
    $j = $r['json'] ?? null;
    if (is_array($j)) {
        $e = $j['message'] ?? $j['error_description'] ?? $j['error'] ?? ($j['data'][0]['message'] ?? null);
        if (is_array($e)) $e = json_encode($e);
        if ($e) return mb_substr((string) $e, 0, 200) . (!empty($j['code']) && is_string($j['code']) ? ' [' . $j['code'] . ']' : '') . ' (' . $r['http'] . ')';
    }
    return $r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['http'];
}

/** Swap a code for tokens. $own: a Self Client's id + secret (keys), else the platform's client. */
function zoho_exchange(string $dc, string $code, ?array $own, bool $redirect): array
{
    $c = $own ?? zoho_cfg();
    $form = ['grant_type' => 'authorization_code', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'code' => $code];
    if ($redirect) $form['redirect_uri'] = zoho_redirect_uri();
    $r = integration_http('POST', zoho_hosts($dc)['accounts'] . '/oauth/v2/token', ['Accept: application/json'], $form);
    if (!$r['ok'] || empty($r['json']['access_token']) || !empty($r['json']['error'])) return ['ok' => false, 'error' => 'Zoho refused: ' . zoho_err($r)];
    if (empty($r['json']['refresh_token'])) return ['ok' => false, 'error' => 'Zoho gave no refresh token. Remove Revenect from your Zoho account\'s connected apps and connect again.'];
    $t = ['access' => (string) $r['json']['access_token'], 'refresh' => (string) $r['json']['refresh_token'], 'expires_at' => time() + (int) ($r['json']['expires_in'] ?? 3600)];
    if ($own) $t += ['cid' => $own['client_id'], 'csec' => $own['client_secret']];
    return ['ok' => true, 'tokens' => $t];
}

/** The callback, after the client approves. */
function zoho_finish_oauth(array $q): array
{
    $st = integration_state_take((string) ($q['state'] ?? ''), 'zoho');
    if (!$st) return ['ok' => false, 'error' => 'This sign-in link was already used or is over an hour old. Start again from Connected apps.'];
    [$provider, $dc] = array_pad(explode(':', (string) $st['extra'], 2), 2, 'com');
    if (!isset(integrations_catalog()[$provider]) || $provider === 'shopify') return ['ok' => false, 'error' => 'Unknown app.'];
    if (!empty($q['error'])) return ['ok' => false, 'error' => $q['error'] === 'access_denied' ? 'You cancelled at Zoho — nothing was changed.' : 'Zoho reported: ' . $q['error'], 'client_id' => (int) $st['client_id']];
    $dc = zoho_dc_from_server((string) ($q['accounts-server'] ?? ''), zoho_dc($dc));
    $x = zoho_exchange($dc, (string) ($q['code'] ?? ''), null, true);
    if (!$x['ok']) return $x + ['client_id' => (int) $st['client_id']];
    return zoho_connected((int) $st['client_id'], $st['user_id'] !== null ? (int) $st['user_id'] : null, $provider, $dc, 'oauth', $x['tokens']);
}

/** Keys: a Self Client's id + secret, and a grant code generated with the scopes (or a refresh token). */
function zoho_connect_keys(int $clientId, ?int $userId, string $provider, string $dc, string $id, string $secret, string $code, string $refresh): array
{
    if ($id === '' || $secret === '') return ['ok' => false, 'error' => 'Paste the Self Client\'s client ID and client secret.'];
    $own = ['client_id' => $id, 'client_secret' => $secret];
    if ($refresh !== '') {
        $t = ['access' => '', 'refresh' => $refresh, 'expires_at' => 0, 'cid' => $id, 'csec' => $secret];
    } elseif ($code !== '') {
        $x = zoho_exchange($dc, $code, $own, false);
        if (!$x['ok']) return $x;
        $t = $x['tokens'];
    } else return ['ok' => false, 'error' => 'Paste the grant code you generated in the Self Client (or a refresh token).'];
    return zoho_connected($clientId, $userId, $provider, $dc, 'key', $t);
}

/** Find the org the tokens belong to, store the connection, start the first sync. */
function zoho_connected(int $clientId, ?int $userId, string $provider, string $dc, string $mode, array $tokens): array
{
    $probe = ['id' => 0, 'client_id' => $clientId, 'provider' => $provider, 'account' => '', 'data_center' => $dc, 'tokens' => $tokens, 'settings_arr' => []];
    if ($provider === 'zoho_crm') {
        $r = zoho_api($probe, 'GET', '/crm/' . zoho_crm_v() . '/org');
        $org = $r['json']['org'][0] ?? null;
        if (!$r['ok'] || !$org) return ['ok' => false, 'error' => 'Could not read your Zoho CRM organisation: ' . zoho_err($r), 'client_id' => $clientId];
        $account = (string) $org['id']; $name = (string) ($org['company_name'] ?? 'Zoho CRM');
    } else {
        $path = $provider === 'zoho_books' ? '/books/v3/organizations' : '/inventory/v1/organizations';
        $r = zoho_api($probe, 'GET', $path);
        $orgs = (array) ($r['json']['organizations'] ?? []);
        if (!$r['ok'] || !$orgs) return ['ok' => false, 'error' => 'Could not read your Zoho organisation: ' . zoho_err($r), 'client_id' => $clientId];
        $org = $orgs[0];
        foreach ($orgs as $o) if (!empty($o['is_default_org'])) $org = $o;
        $account = (string) $org['organization_id']; $name = (string) ($org['name'] ?? 'Zoho');
    }
    $id = integration_save($clientId, $provider, $account, $probe['tokens'], [
        'auth_mode' => $mode, 'data_center' => $dc, 'display_name' => mb_substr($name, 0, 190), 'connected_by' => $userId,
        'hook_token' => bin2hex(random_bytes(24)),
    ]);
    $it = integration_get($id);
    integration_log($it, 'out', 'connected', 'Connected ' . $name . ' (' . zoho_dcs()[zoho_dc($dc)]['label'] . ')' . ($mode === 'key' ? ' with keys' : ''));
    if ($provider === 'zoho_crm') { integration_job($it, 'watch', 0); integration_job($it, 'users', 0); integration_job($it, 'fields', 0); }
    // Start the polls from now: what already exists in Zoho is history, not news.
    integration_cursor_save($it, ['since' => date('c'), 'first' => 1]);
    return ['ok' => true, 'id' => $id, 'client_id' => $clientId, 'name' => $name];
}

function zoho_disconnect(array $it): void
{
    if ($it['provider'] === 'zoho_crm' && !empty($it['tokens'])) {
        zoho_api($it, 'DELETE', '/crm/' . zoho_crm_v() . '/actions/watch', ['channel_ids' => zoho_channel_id($it)]);
    }
    // Revoke the refresh token so it is worthless even if it ever leaked.
    if (!empty($it['tokens']['refresh'])) integration_http('POST', zoho_hosts((string) $it['data_center'])['accounts'] . '/oauth/v2/token/revoke', [], ['token' => $it['tokens']['refresh']]);
    db_run("UPDATE integrations SET status='disconnected', tokens_enc=NULL, hook_token=NULL, updated_at=NOW() WHERE id=?", [(int) $it['id']]);
    unset($GLOBALS['__crm_zoho']);
    db_run("UPDATE integration_jobs SET status='failed', last_error='Disconnected' WHERE integration_id=? AND status='queued'", [(int) $it['id']]);
    integration_log($it, 'out', 'disconnected', 'Disconnected');
}

/* ───────────────────────── calling Zoho ───────────────────────── */

/** A working access token, refreshed when it is about to lapse. */
function zoho_token(array &$it, bool $force = false): string
{
    $t = $it['tokens'];
    if (!$force && !empty($t['access']) && (int) ($t['expires_at'] ?? 0) > time() + 120) return (string) $t['access'];
    $c = !empty($t['cid']) ? ['client_id' => $t['cid'], 'client_secret' => $t['csec']] : zoho_cfg();
    $r = integration_http('POST', zoho_hosts((string) $it['data_center'])['accounts'] . '/oauth/v2/token', ['Accept: application/json'],
                          ['grant_type' => 'refresh_token', 'refresh_token' => (string) ($t['refresh'] ?? ''), 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret']]);
    if (!$r['ok'] || empty($r['json']['access_token'])) {
        if (!empty($it['id']) && in_array((string) ($r['json']['error'] ?? ''), ['invalid_code', 'invalid_client', 'access_denied'], true)) {
            integration_error($it, 'Zoho no longer accepts our access (' . $r['json']['error'] . ') — connect again.');
        }
        return (string) ($t['access'] ?? '');
    }
    $t['access'] = (string) $r['json']['access_token'];
    $t['expires_at'] = time() + (int) ($r['json']['expires_in'] ?? 3600);
    $it['tokens'] = $t;
    if (!empty($it['id'])) integration_set_tokens($it, $t);
    return $t['access'];
}

/** An API call: zoho_api($it, 'GET', '/crm/v7/Leads/123'). Books and Inventory get the organisation added. */
function zoho_api(array &$it, string $method, string $path, array $query = [], ?array $body = null, array $headers = []): array
{
    if (in_array($it['provider'], ['zoho_books', 'zoho_inventory'], true) && $it['account'] !== '') $query['organization_id'] = $it['account'];
    $url = zoho_hosts((string) $it['data_center'])['api'] . $path . ($query ? '?' . http_build_query($query) : '');
    $r = ['http' => 0];
    for ($try = 0; $try < 2; $try++) {
        $hdr = array_merge(['Authorization: Zoho-oauthtoken ' . zoho_token($it, $try > 0), 'Accept: application/json'], $body !== null ? ['Content-Type: application/json'] : [], $headers);
        $r = integration_http($method, $url, $hdr, $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
        if ($r['http'] !== 401) break;                       // an expired token: refresh once and try again
    }
    return $r;
}

/* ───────────────────────── Zoho CRM: mapping ───────────────────────── */

/** Revenect lead fields that can be sent to Zoho, as key => label. */
function zoho_revenect_fields(int $clientId): array
{
    $f = ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'source' => 'Source', 'project' => 'Project', 'unit_type' => 'Unit type', 'budget' => 'Budget',
          'deal_value' => 'Deal value', 'campaign' => 'Campaign', 'platform' => 'Platform', 'ad_name' => 'Ad', 'code' => 'Lead code', 'url' => 'Link to the lead in Revenect'];
    if (function_exists('crm_fields')) foreach (crm_fields($clientId) as $cf) $f['custom:' . $cf['fkey']] = (string) $cf['label'];
    return $f;
}

/** The field map in use: what the client set, over the defaults. */
function zoho_field_map(array $s): array
{
    $def = ['name' => 'Last_Name', 'phone' => 'Phone', 'email' => 'Email', 'source' => 'Lead_Source'];
    $m = (array) ($s['field_map'] ?? []);
    return $m ? array_filter($m + ['name' => 'Last_Name'], 'strlen') : $def;
}

/** What identifies a record's content: equal fingerprints = nothing changed (or our own echo). */
function zoho_fingerprint(string $name, string $phone, string $email, string $status): string
{
    return sha1(mb_strtolower(trim($name)) . '|' . preg_replace('/\D/', '', $phone) . '|' . mb_strtolower(trim($email)) . '|' . mb_strtolower(trim($status)));
}

/** A Revenect lead as a Zoho record, for the chosen module. */
function zoho_record(array $it, array $lead): array
{
    $s = integration_settings($it);
    $module = ($s['module'] ?? 'Leads') === 'Contacts' ? 'Contacts' : 'Leads';
    $rec = [];
    foreach (zoho_field_map($s) as $ours => $theirs) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', (string) $theirs)) continue;
        if ($ours === 'name') {
            $parts = preg_split('/\s+/', trim((string) $lead['name'])) ?: [];
            $last = $parts ? array_pop($parts) : '';
            if ($theirs === 'Last_Name' && $parts) $rec['First_Name'] = implode(' ', $parts);
            $rec[$theirs] = $theirs === 'Last_Name' ? ($last !== '' ? $last : ('+' . ltrim((string) $lead['phone'], '+'))) : (string) $lead['name'];
            continue;
        }
        $v = str_starts_with($ours, 'custom:') ? ($lead['custom_fields'][substr($ours, 7)] ?? null) : ($lead[$ours] ?? null);
        if ($v === null || $v === '' || is_array($v) || is_object($v)) continue;
        $rec[$theirs] = is_float($v) || is_int($v) ? $v : mb_substr((string) $v, 0, 250);
    }
    if ($module === 'Contacts') unset($rec['Lead_Source'], $rec['Lead_Status'], $rec['Company']);
    $status = '';
    if ($module === 'Leads' && $lead['stage_id'] !== null && ($st = (string) ($s['stage_map'][(string) $lead['stage_id']] ?? '')) !== '') $status = $rec['Lead_Status'] = $st;
    // The salesperson, when they have a Zoho user with the same email.
    $owner = strtolower((string) ($lead['owner']['email'] ?? ''));
    if ($owner !== '' && ($zid = $it['cursor']['users'][$owner] ?? null)) $rec['Owner'] = ['id' => (string) $zid];
    $fp = zoho_fingerprint((string) $lead['name'], (string) $lead['phone'], (string) ($lead['email'] ?? ''), $status);
    return [$module, $rec, $fp];
}

/** Send one record; drop a field Zoho refuses (a picklist value it does not have) and try again. */
function zoho_write(array &$it, string $method, string $path, array $rec, array $extra = []): array
{
    $dropped = [];
    for ($i = 0; $i < 3; $i++) {
        $r = zoho_api($it, $method, $path, [], ['data' => [$rec]] + $extra);
        $d = $r['json']['data'][0] ?? null;
        if ($r['ok'] && ($d['code'] ?? '') === 'SUCCESS') return ['ok' => true, 'id' => (string) ($d['details']['id'] ?? ''), 'dropped' => $dropped, 'raw' => $d];
        $bad = (string) ($d['details']['api_name'] ?? '');
        if (($d['code'] ?? '') === 'INVALID_DATA' && $bad !== '' && isset($rec[$bad]) && !in_array($bad, ['Last_Name', 'Deal_Name'], true)) { unset($rec[$bad]); $dropped[] = $bad; continue; }
        return ['ok' => false, 'error' => ($d['message'] ?? '') !== '' ? $d['message'] . ' [' . ($d['code'] ?? '') . ']' . ($bad ? ' — field ' . $bad : '') : zoho_err($r),
                'retry' => $r['http'] === 0 || $r['http'] >= 500 || $r['http'] === 429, 'code' => (string) ($d['code'] ?? '')];
    }
    return ['ok' => false, 'error' => 'Zoho refused the record.', 'retry' => false];
}

/** Revenect → Zoho: create or update the lead / contact, then the deal when the lead is won, lost or at a deal stage. */
function zoho_push_lead(array $it, int $contactId): array
{
    $s = integration_settings($it);
    if (empty($s['push_leads'])) return ['ok' => true];
    $lead = crm_int_lead($contactId);
    if (!$lead || (int) db_val("SELECT client_id FROM contacts WHERE id=?", [$contactId]) !== (int) $it['client_id']) return ['ok' => true];
    if ($lead['stage_id'] === null) return ['ok' => true];      // not in the CRM pipeline
    [$module, $rec, $fp] = zoho_record($it, $lead);
    $kind = strtolower(rtrim($module, 's'));
    $v = '/crm/' . zoho_crm_v();
    // Once converted, the person lives on as a Zoho Contact.
    $ext = integration_link_ext($it, 'contact', $contactId);
    if ($ext !== null) { $module = 'Contacts'; $kind = 'contact'; unset($rec['Lead_Source'], $rec['Lead_Status'], $rec['Company']); }
    else $ext = integration_link_ext($it, $kind, $contactId);
    $link = $ext !== null ? db_row("SELECT * FROM integration_links WHERE integration_id=? AND kind=? AND ext_id=?", [(int) $it['id'], $kind, $ext]) : null;
    if (!$link || $link['sync_hash'] !== $fp || ($GLOBALS['__zoho_force'] ?? false) || in_array($lead['stage_type'], ['won', 'lost'], true)) {
        $w = $ext !== null ? zoho_write($it, 'PUT', "$v/$module/$ext", $rec)
                           : zoho_write($it, 'POST', "$v/$module/upsert", $rec, ['duplicate_check_fields' => array_values(array_intersect(['Phone', 'Email', 'Mobile'], array_keys($rec))) ?: ['Last_Name']]);
        if (!$w['ok'] && $ext !== null && in_array($w['code'] ?? '', ['INVALID_DATA', 'ENTITY_ID_INVALID', 'RECORD_NOT_FOUND'], true) && stripos((string) $w['error'], 'id') !== false) {
            // The record was deleted in Zoho: make it again.
            db_run("DELETE FROM integration_links WHERE integration_id=? AND kind=? AND ext_id=?", [(int) $it['id'], $kind, $ext]);
            $ext = null;
            $w = zoho_write($it, 'POST', "$v/$module/upsert", $rec, ['duplicate_check_fields' => ['Phone', 'Email']]);
        }
        if (!$w['ok']) return $w;
        $ext = $ext ?? $w['id'];
        integration_link_set($it, $kind, $ext, $contactId, $fp);
        integration_log($it, 'out', strtolower($module), ($link ? 'Updated ' : 'Sent ') . $lead['name'] . ' to Zoho ' . $module . ($w['dropped'] ? ' (Zoho refused: ' . implode(', ', $w['dropped']) . ')' : ''));
    }
    if (!empty($s['deals'])) return zoho_push_deal($it, $lead, $module, (string) $ext, $s);
    return ['ok' => true];
}

/** The deal: Won / Lost mapped to Zoho's closing stages, or a stage chosen to open one. */
function zoho_push_deal(array $it, array $lead, string $module, string $ext, array $s): array
{
    $type = (string) $lead['stage_type'];
    $dealStage = $type === 'won' ? (string) $s['won_stage'] : ($type === 'lost' ? (string) $s['lost_stage'] : (string) ($s['deal_stages'][(string) $lead['stage_id']] ?? ''));
    $dealId = integration_link_ext($it, 'deal', (int) $lead['id']);
    if ($dealStage === '' && $dealId === null) return ['ok' => true];
    $v = '/crm/' . zoho_crm_v();
    $deal = ['Deal_Name' => mb_substr($lead['name'] . ($lead['project'] ? ' — ' . $lead['project'] : ''), 0, 120) ?: 'Revenect lead', 'Closing_Date' => date('Y-m-d')];
    if ($dealStage !== '') $deal['Stage'] = $dealStage;
    if ($lead['deal_value'] !== null) $deal['Amount'] = (float) $lead['deal_value'];
    if ($dealId === null && $module === 'Leads' && $type === 'won') {
        // Won while still a Zoho Lead: convert it into a Contact with this Deal, as a salesperson would in Zoho.
        $r = zoho_api($it, 'POST', "$v/Leads/$ext/actions/convert", [], ['data' => [['overwrite' => true, 'notify_lead_owner' => false, 'Deals' => $deal]]]);
        $d = $r['json']['data'][0] ?? [];
        $det = (array) ($d['details'] ?? $d);
        $contact = is_array($det['Contacts'] ?? null) ? ($det['Contacts']['id'] ?? '') : ($det['Contacts'] ?? '');
        $dealNew = is_array($det['Deals'] ?? null) ? ($det['Deals']['id'] ?? '') : ($det['Deals'] ?? '');
        if (!$r['ok'] || !$contact) return ['ok' => false, 'error' => 'Could not convert the Zoho lead: ' . zoho_err($r), 'retry' => $r['http'] >= 500 || $r['http'] === 0];
        integration_link_set($it, 'contact', (string) $contact, (int) $lead['id']);
        if ($dealNew) integration_link_set($it, 'deal', (string) $dealNew, (int) $lead['id'], sha1($dealStage));
        integration_log($it, 'out', 'convert', $lead['name'] . ' won — converted to a Zoho contact' . ($dealNew ? ' with a deal' : ''));
        return ['ok' => true];
    }
    if ($dealId === null && $module === 'Leads') return ['ok' => true];   // a deal needs a contact; lost leads just carry their status
    $hash = sha1($dealStage . '|' . ($deal['Amount'] ?? ''));
    if ($dealId !== null && db_val("SELECT sync_hash FROM integration_links WHERE integration_id=? AND kind='deal' AND ext_id=?", [(int) $it['id'], $dealId]) === $hash) return ['ok' => true];
    if ($dealId === null) $deal['Contact_Name'] = ['id' => $ext];
    $w = $dealId !== null ? zoho_write($it, 'PUT', "$v/Deals/$dealId", $deal) : zoho_write($it, 'POST', "$v/Deals", $deal);
    if (!$w['ok']) return $w;
    integration_link_set($it, 'deal', $dealId ?? $w['id'], (int) $lead['id'], $hash);
    integration_log($it, 'out', 'deal', ($dealId ? 'Updated' : 'Opened') . ' the deal for ' . $lead['name'] . ($dealStage !== '' ? ' — ' . $dealStage : ''));
    return ['ok' => true];
}

/** Zoho → Revenect: one Lead, Contact or Deal as Zoho has it now. */
function zoho_apply(array $it, string $module, array $rec): string
{
    $s = integration_settings($it);
    $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $it['client_id']]);
    if (!$client || !function_exists('crm_enabled') || !crm_enabled($client)) return 'CRM is off';
    $id = (string) ($rec['id'] ?? '');
    if ($module === 'Deals') {
        $cid = integration_link_contact($it, 'deal', $id);
        if (!$cid) return 'deal not linked to a Revenect lead';
        $stage = (string) ($rec['Stage'] ?? '');
        $hash = sha1($stage . '|' . ($rec['Amount'] ?? ''));
        $link = db_row("SELECT sync_hash FROM integration_links WHERE integration_id=? AND kind='deal' AND ext_id=?", [(int) $it['id'], $id]);
        if ($link && in_array($link['sync_hash'], [$hash, sha1($stage)], true)) return 'no change';
        $kind = $stage === (string) $s['won_stage'] ? 'won' : ($stage === (string) $s['lost_stage'] ? 'lost' : '');
        $prev = crm_int_origin(); crm_int_origin('zoho_crm');
        try {
            if (isset($rec['Amount']) && is_numeric($rec['Amount'])) db_run("UPDATE contacts SET deal_value=? WHERE id=?", [(float) $rec['Amount'], $cid]);
            if ($kind !== '' && ($sid = db_val("SELECT id FROM crm_stages WHERE client_id=? AND kind=? ORDER BY sort LIMIT 1", [(int) $client['id'], $kind]))) {
                $cur = db_row("SELECT c.stage_id, s.kind FROM contacts c LEFT JOIN crm_stages s ON s.id=c.stage_id WHERE c.id=?", [$cid]);
                if (($cur['kind'] ?? '') !== $kind) crm_set_stage($client, $cid, (int) $sid, null, $kind === 'lost' ? 'Closed lost in Zoho' : null);
            }
        } finally { crm_int_origin($prev); }
        integration_link_set($it, 'deal', $id, $cid, $hash);
        return 'deal ' . ($rec['Deal_Name'] ?? $id) . ' → ' . $stage;
    }
    if (empty($s['pull_leads'])) return 'pulling leads is off';
    $kind = $module === 'Contacts' ? 'contact' : 'lead';
    $map = zoho_field_map($s);
    $name = trim((string) ($rec['Full_Name'] ?? '')) ?: trim(($rec['First_Name'] ?? '') . ' ' . ($rec['Last_Name'] ?? ''));
    $phone = (string) (($rec[$map['phone'] ?? 'Phone'] ?? '') ?: ($rec['Mobile'] ?? '') ?: ($rec['Phone'] ?? ''));
    $email = (string) ($rec[$map['email'] ?? 'Email'] ?? $rec['Email'] ?? '');
    $status = $module === 'Leads' ? (string) ($rec['Lead_Status'] ?? '') : '';
    $linked = integration_link_contact($it, $kind, $id);
    $fp = zoho_fingerprint($name, $phone, $email, $status && in_array($status, (array) $s['stage_map'], true) ? $status : '');
    if ($linked && db_val("SELECT sync_hash FROM integration_links WHERE integration_id=? AND kind=? AND ext_id=?", [(int) $it['id'], $kind, $id]) === $fp) return 'no change';
    if ($phone === '' && !$linked) return ($name ?: 'record ' . $id) . ' has no phone — not added';
    $d = ['name' => $name, 'phone' => $phone, 'email' => $email, 'source' => 'zoho'];
    $sid = $status !== '' ? array_search($status, (array) $s['stage_map'], true) : false;
    if ($sid !== false) $d['stage'] = (string) $sid;
    $owner = (string) ($rec['Owner']['email'] ?? '');
    if ($owner !== '') $d['owner_email'] = $owner;
    foreach ($map as $ours => $theirs) {
        if (in_array($ours, ['name', 'phone', 'email', 'source', 'url', 'code'], true) || !isset($rec[$theirs]) || !is_scalar($rec[$theirs])) continue;
        if (str_starts_with($ours, 'custom:')) $d['custom'][substr($ours, 7)] = (string) $rec[$theirs];
        else $d[$ours] = (string) $rec[$theirs];
    }
    $r = crm_int_upsert($client, $d, ['system' => 'zoho_crm', 'origin' => 'zoho_crm', 'source' => 'zoho', 'id' => $linked]);
    if (empty($r['ok'])) return 'not added: ' . ($r['error'] ?? '');
    integration_link_set($it, $kind, $id, (int) $r['id'], $fp);
    if (!empty($r['created'])) {
        if (!function_exists('automation_handle_crm_event')) require_once __DIR__ . '/automation.php';
        automation_handle_crm_event($client, (int) $r['id'], 'zoho_lead', ['fields' => ['zoho_id' => $id, 'zoho_module' => $module]]);
    }
    return (!empty($r['created']) ? 'added ' : 'updated ') . ($name ?: $phone) . ' from Zoho ' . $module;
}

/** Revenect's conversations, notes, calls and visits since the last note, as one Zoho note. */
function zoho_push_notes(array $it, int $contactId): array
{
    $s = integration_settings($it);
    if (empty($s['notes'])) return ['ok' => true];
    $kind = integration_link_ext($it, 'contact', $contactId) !== null ? 'contact' : 'lead';
    $ext = integration_link_ext($it, $kind, $contactId);
    if ($ext === null) return ['ok' => true];
    $link = db_row("SELECT * FROM integration_links WHERE integration_id=? AND kind=? AND ext_id=?", [(int) $it['id'], $kind, $ext]);
    $data = json_decode((string) ($link['data'] ?? ''), true) ?: [];
    $since = (string) ($data['notes_to'] ?? date('Y-m-d H:i:s', time() - 86400));   // the first note covers the last day
    $until = date('Y-m-d H:i:s');
    $lines = [];
    $ch = ['whatsapp' => 'WhatsApp', 'messenger' => 'Messenger', 'instagram' => 'Instagram', 'sms' => 'SMS'];
    foreach (db_all("SELECT direction, channel, type, body, created_at FROM messages WHERE contact_id=? AND client_id=? AND created_at > ? AND created_at <= ? ORDER BY id LIMIT 300",
                    [$contactId, (int) $it['client_id'], $since, $until]) as $m) {
        $body = trim((string) $m['body']) !== '' ? (string) $m['body'] : '[' . $m['type'] . ']';
        $lines[] = substr((string) $m['created_at'], 0, 16) . ' ' . ($m['direction'] === 'in' ? '←' : '→') . ' ' . ($ch[$m['channel'] ?? 'whatsapp'] ?? ucfirst((string) $m['channel'])) . ': ' . mb_substr($body, 0, 1000);
    }
    foreach (db_all("SELECT n.kind, n.outcome, n.body, n.created_at, COALESCE(NULLIF(u.name,''),u.email) who FROM crm_notes n LEFT JOIN users u ON u.id=n.user_id
                      WHERE n.contact_id=? AND n.client_id=? AND n.created_at > ? AND n.created_at <= ? AND n.body NOT LIKE '[zoho_crm]%' ORDER BY n.id LIMIT 100",
                    [$contactId, (int) $it['client_id'], $since, $until]) as $n) {
        $lines[] = substr((string) $n['created_at'], 0, 16) . ' • ' . ucfirst((string) ($n['kind'] ?? 'note')) . ($n['outcome'] ? ' (' . $n['outcome'] . ')' : '') . ($n['who'] ? ' by ' . $n['who'] : '') . ': ' . mb_substr((string) $n['body'], 0, 2000);
    }
    usort($lines, fn($a, $b) => strcmp(substr($a, 0, 16), substr($b, 0, 16)));
    if ($lines) {
        $module = $kind === 'contact' ? 'Contacts' : 'Leads';
        $r = zoho_api($it, 'POST', '/crm/' . zoho_crm_v() . "/$module/$ext/Notes", [], ['data' => [[
            'Note_Title' => 'Revenect — ' . count($lines) . ' update' . (count($lines) === 1 ? '' : 's') . ' ' . date('j M H:i'),
            'Note_Content' => mb_substr(implode("\n", $lines), 0, 30000)]]]);
        if (!$r['ok'] || ($r['json']['data'][0]['code'] ?? '') !== 'SUCCESS') return ['ok' => false, 'error' => 'Could not add the note: ' . zoho_err($r), 'retry' => $r['http'] !== 400];
        integration_log($it, 'out', 'note', count($lines) . ' conversation line(s) and notes logged in Zoho');
    }
    $data['notes_to'] = $until;
    db_run("UPDATE integration_links SET data=? WHERE id=?", [json_encode($data), (int) $link['id']]);
    return ['ok' => true];
}

/** Zoho's channel id for this connection's notifications. */
function zoho_channel_id(array $it): string { return (string) (1700000000 + (int) $it['id']); }

function zoho_webhook_url(array $it): string { return integration_url('webhook_zoho.php?t=' . $it['hook_token']); }

/* ───────────────────────── Books & Inventory ───────────────────────── */

/** The person on an invoice / sales order: the Zoho customer, read once and remembered. */
function zoho_customer(array $it, array $client, string $custId, array $doc): ?int
{
    if ($custId === '') return null;
    $known = integration_link_contact($it, 'customer', $custId);
    if ($known) return $known;
    $path = ($it['provider'] === 'zoho_books' ? '/books/v3/contacts/' : '/inventory/v1/contacts/') . rawurlencode($custId);
    $r = zoho_api($it, 'GET', $path);
    $c = (array) ($r['json']['contact'] ?? []);
    $person = $c['contact_persons'][0] ?? [];
    $p = ['name' => (string) ($c['contact_name'] ?? $doc['customer_name'] ?? ''), 'first_name' => (string) ($person['first_name'] ?? ''),
          'phone' => (string) (($c['mobile'] ?? '') ?: ($person['mobile'] ?? '') ?: ($c['phone'] ?? '') ?: ($person['phone'] ?? '') ?: ($doc['phone'] ?? '')),
          'email' => (string) (($c['email'] ?? '') ?: ($person['email'] ?? '') ?: ($doc['email'] ?? ''))];
    return integration_person($client, $it, 'customer', $custId, $p, false) ?: null;
}

/** Read invoices and sales orders changed since the last look; message and trigger on what moved. */
function zoho_poll_orders(array $it): int
{
    $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $it['client_id']]);
    if (!$client) return 0;
    $cur = $it['cursor'];
    $since = strtotime((string) ($cur['since'] ?? 'now')) ?: time();
    $quiet = !empty($cur['first']);                          // the first look: history, nobody messaged
    $books = $it['provider'] === 'zoho_books';
    $feeds = $books ? ['invoice' => '/books/v3/invoices', 'salesorder' => '/books/v3/salesorders'] : ['salesorder' => '/inventory/v1/salesorders'];
    $newest = $since; $n = 0;
    foreach ($feeds as $kind => $path) {
        $r = zoho_api($it, 'GET', $path, ['sort_column' => 'last_modified_time', 'sort_order' => 'D', 'per_page' => 100]);
        if (!$r['ok']) { integration_error($it, 'Could not read ' . $kind . 's: ' . zoho_err($r)); continue; }
        foreach ((array) ($r['json'][$kind . 's'] ?? []) as $d) {
            $mod = strtotime((string) ($d['last_modified_time'] ?? '')) ?: 0;
            if ($mod && $mod <= $since - 60) break;             // sorted newest first: the rest is older
            $newest = max($newest, $mod);
            $cid = zoho_customer($it, $client, (string) ($d['customer_id'] ?? ''), $d);
            $zs = strtolower((string) ($d['status'] ?? ''));
            $ship = strtolower((string) ($d['shipped_status'] ?? ''));
            $status = $kind === 'invoice'
                ? match (true) { $zs === 'paid' => 'paid', in_array($zs, ['sent', 'overdue', 'partially_paid', 'viewed'], true) => 'sent', $zs === 'void' => 'void', default => $zs }
                : match (true) { in_array($ship, ['shipped', 'fulfilled', 'delivered', 'partially_shipped'], true) => 'shipped', in_array($zs, ['open', 'confirmed', 'invoiced'], true) => 'placed', $zs === 'void' => 'void', default => $zs };
            [$row] = integration_order_upsert($it, $kind, (string) ($d[$kind . '_id'] ?? ''), [
                'contact_id' => $cid, 'number' => (string) ($d[$kind . '_number'] ?? ''), 'status' => $status, 'financial' => $zs, 'fulfillment' => $ship ?: null,
                'total' => isset($d['total']) ? (float) $d['total'] : null, 'currency' => $d['currency_code'] ?? null, 'name' => $d['customer_name'] ?? null,
                'email' => $d['email'] ?? null, 'link_url' => isset($d['invoice_url']) && preg_match('#^https://#', (string) $d['invoice_url']) ? $d['invoice_url'] : null,
                'placed_at' => !empty($d['date']) ? date('Y-m-d 00:00:00', strtotime((string) $d['date'])) : null,
            ]);
            if (!$row) continue;
            $evs = $kind === 'invoice'
                ? ['sent' => [['invoice_sent', 'zoho_invoice']], 'paid' => [['invoice_sent', null], ['payment_received', 'zoho_payment']]]
                : ['placed' => [['order_confirmed', 'zoho_salesorder']], 'shipped' => [['order_confirmed', null], ['order_shipped', 'zoho_shipped']]];
            foreach ($evs[$status] ?? [] as [$ev, $trig]) {
                // An invoice that is already paid when first seen: one "payment received", not "here is your invoice" too.
                if ($quiet || ($trig === null)) { integration_mark($row, $ev); if ($trig) integration_mark($row, 't:' . $trig); continue; }
                if (integration_notify($it, $row, $ev)) $n++;
                integration_trigger($it, $trig, $row);
            }
            if ($quiet) foreach (['invoice_sent', 'payment_received', 'order_confirmed', 'order_shipped', 't:zoho_invoice', 't:zoho_payment', 't:zoho_salesorder', 't:zoho_shipped'] as $ev) integration_mark($row, $ev);
        }
    }
    $cur['since'] = date('c', $newest);
    unset($cur['first']);
    $cur['poll_at'] = time();
    integration_cursor_save($it, $cur);
    db_run("UPDATE integrations SET last_sync_at=NOW() WHERE id=?", [(int) $it['id']]);
    return $n;
}

/* ───────────────────────── the worker ───────────────────────── */

function zoho_job(array $it, array $job, array $payload): array
{
    $v = '/crm/' . zoho_crm_v();
    switch ((string) $job['kind']) {
        case 'push_lead':
            return zoho_push_lead($it, (int) $job['ref_id']);
        case 'push_notes':
            return zoho_push_notes($it, (int) $job['ref_id']);
        case 'pull':
            $module = in_array($payload['module'] ?? '', ['Leads', 'Contacts', 'Deals'], true) ? $payload['module'] : 'Leads';
            if (($payload['operation'] ?? '') === 'delete') { integration_log($it, 'in', strtolower($module), 'Deleted in Zoho (kept in Revenect)'); return ['ok' => true]; }
            $r = zoho_api($it, 'GET', "$v/$module/" . (int) $job['ref_id']);
            if ($r['http'] === 204 || $r['http'] === 404) return ['ok' => true];
            $rec = $r['json']['data'][0] ?? null;
            if (!$r['ok'] || !$rec) return ['ok' => false, 'error' => 'Could not read the Zoho record: ' . zoho_err($r), 'retry' => $r['http'] !== 400];
            $res = zoho_apply($it, $module, $rec);
            if ($res !== 'no change') integration_log($it, 'in', strtolower($module), $res);
            return ['ok' => true];
        case 'watch':
            $r = zoho_api($it, 'POST', "$v/actions/watch", [], ['watch' => [[
                'channel_id' => zoho_channel_id($it), 'events' => ['Leads.all', 'Contacts.all', 'Deals.edit'], 'notify_url' => zoho_webhook_url($it),
                'token' => substr((string) $it['hook_token'], 0, 50), 'channel_expiry' => date('c', time() + 6 * 86400), 'return_affected_field_values' => false]]]);
            $ok = $r['ok'] && ($r['json']['watch'][0]['code'] ?? '') === 'SUCCESS';
            $cur = $it['cursor']; $cur['watch_until'] = $ok ? time() + 6 * 86400 : time() + 3600;
            integration_cursor_save($it, $cur);
            integration_log($it, 'out', 'watch', $ok ? 'Zoho will tell us about lead, contact and deal changes' : 'Zoho did not accept live updates (' . zoho_err($r) . ') — checking every 5 minutes instead', $ok);
            return ['ok' => true];
        case 'fields':
            // Zoho's fields and picklists, for the mapping screen.
            $cur = $it['cursor']; $cur['fields'] = [];
            foreach (['Leads', 'Contacts', 'Deals'] as $module) {
                $r = zoho_api($it, 'GET', "$v/settings/fields", ['module' => $module]);
                if (!$r['ok']) return ['ok' => false, 'error' => 'Could not read Zoho\'s fields: ' . zoho_err($r), 'retry' => $r['http'] >= 500];
                foreach ((array) ($r['json']['fields'] ?? []) as $f) {
                    if (!empty($f['read_only']) || in_array($f['data_type'] ?? '', ['lookup', 'ownerlookup', 'multiselectlookup', 'subform', 'fileupload', 'imageupload', 'formula', 'autonumber'], true)) continue;
                    $pick = array_values(array_filter(array_map(fn($o) => (string) ($o['actual_value'] ?? $o['display_value'] ?? ''), (array) ($f['pick_list_values'] ?? [])), fn($x) => $x !== '' && $x !== '-None-'));
                    $cur['fields'][$module][(string) $f['api_name']] = ['label' => (string) ($f['field_label'] ?? $f['api_name']), 'pick' => array_slice($pick, 0, 100)];
                }
            }
            $cur['fields_at'] = time();
            integration_cursor_save($it, $cur);
            return ['ok' => true];
        case 'users':
            $r = zoho_api($it, 'GET', "$v/users", ['type' => 'ActiveUsers', 'per_page' => 200]);
            if (!$r['ok']) return ['ok' => false, 'error' => 'Could not list Zoho users: ' . zoho_err($r), 'retry' => $r['http'] >= 500];
            $cur = $it['cursor']; $cur['users'] = [];
            foreach ((array) ($r['json']['users'] ?? []) as $u) if (!empty($u['email'])) $cur['users'][strtolower((string) $u['email'])] = (string) $u['id'];
            integration_cursor_save($it, $cur);
            return ['ok' => true];
    }
    return ['ok' => false, 'error' => 'Unknown job ' . $job['kind'], 'retry' => false];
}

/** The 5-minute poll and the 15-minute note batch for Zoho CRM; the 10-minute poll for Books / Inventory. */
function zoho_tick(array $it): int
{
    $cur = $it['cursor'];
    if ($it['provider'] !== 'zoho_crm') return (int) ($cur['poll_at'] ?? 0) < time() - 600 ? zoho_poll_orders($it) : 0;
    $s = integration_settings($it);
    $v = '/crm/' . zoho_crm_v();
    $n = 0;
    if ((int) ($cur['watch_until'] ?? 0) < time() + 86400 && !db_val("SELECT 1 FROM integration_jobs WHERE integration_id=? AND kind='watch' AND status='queued'", [(int) $it['id']])) {
        integration_job($it, 'watch', 0);
    }
    if (!empty($s['pull_leads']) && (int) ($cur['poll_at'] ?? 0) < time() - 300) {
        $since = (string) ($cur['since'] ?? date('c', time() - 3600));
        $newest = strtotime($since) ?: time();
        foreach (['Leads', 'Contacts', 'Deals'] as $module) {
            $fields = $module === 'Deals' ? 'Deal_Name,Stage,Amount,Modified_Time' : 'First_Name,Last_Name,Full_Name,Phone,Mobile,Email,Owner,Modified_Time' . ($module === 'Leads' ? ',Lead_Status' : '');
            foreach (zoho_field_map($s) as $theirs) if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', (string) $theirs) && $module !== 'Deals') $fields .= ',' . $theirs;
            $r = zoho_api($it, 'GET', "$v/$module", ['fields' => implode(',', array_unique(explode(',', $fields))), 'per_page' => 100, 'sort_by' => 'Modified_Time', 'sort_order' => 'desc'],
                          null, ['If-Modified-Since: ' . date('c', (strtotime($since) ?: time()) - 60)]);
            if ($r['http'] === 304 || $r['http'] === 204) continue;
            if (!$r['ok']) continue;
            foreach ((array) ($r['json']['data'] ?? []) as $rec) {
                $newest = max($newest, strtotime((string) ($rec['Modified_Time'] ?? '')) ?: 0);
                if ($module === 'Deals' && !integration_link_contact($it, 'deal', (string) $rec['id'])) continue;
                $res = zoho_apply($it, $module, $rec);
                if ($res !== 'no change') { integration_log($it, 'in', strtolower($module), $res); $n++; }
            }
        }
        $cur['since'] = date('c', $newest);
        $cur['poll_at'] = time();
        db_run("UPDATE integrations SET last_sync_at=NOW() WHERE id=?", [(int) $it['id']]);
    }
    if (!empty($s['notes']) && (int) ($cur['notes_at'] ?? 0) < time() - 900) {
        $from = date('Y-m-d H:i:s', (int) ($cur['notes_at'] ?? 0) ?: time() - 900);
        foreach (db_all("SELECT DISTINCT l.contact_id FROM integration_links l
                          WHERE l.integration_id=? AND l.kind IN ('lead','contact') AND l.contact_id IS NOT NULL
                            AND (EXISTS (SELECT 1 FROM messages m WHERE m.contact_id=l.contact_id AND m.created_at > ?)
                              OR EXISTS (SELECT 1 FROM crm_notes n WHERE n.contact_id=l.contact_id AND n.created_at > ?)) LIMIT 500", [(int) $it['id'], $from, $from]) as $row) {
            integration_job($it, 'push_notes', (int) $row['contact_id']);
        }
        $cur['notes_at'] = time();
    }
    integration_cursor_save($it, $cur);
    return $n;
}
