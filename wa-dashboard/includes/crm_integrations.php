<?php
declare(strict_types=1);

/**
 * Connecting the CRM to another CRM (or any system: n8n, Zapier, Make, a developer's script).
 *
 *   OUT  webhooks — when something happens to a lead (added, stage changed, assigned, activity
 *        logged, visit booked…), its full record is POSTed as JSON to the other system's URL,
 *        signed with HMAC-SHA256 so they can check it came from here. Queued; failures retried.
 *   IN   1. the API (api.php): create / update / read leads, add notes — with a key from the
 *           Integrations page, read-only or read & write;
 *        2. incoming webhook URLs: for systems that can only POST their own JSON. Each URL has a
 *           field mapping (their field → our field); with none, common names are recognised.
 *
 * Both sides recognise the same person by external_id (the other system's id) or the phone.
 * A change that arrived through the API or an incoming URL is not echoed back out to a webhook
 * that asks for that (the default), so two systems do not ping-pong forever.
 */

require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/crypto.php';

/* ───────────────────────── events ───────────────────────── */

function crm_int_events(): array
{
    return ['lead.created' => 'A lead is added', 'lead.updated' => 'Lead details change', 'lead.stage_changed' => 'Stage changes',
            'lead.won' => 'A lead is won', 'lead.lost' => 'A lead is lost', 'lead.assigned' => 'Assigned or transferred',
            'activity.logged' => 'A call, meeting or comment is logged', 'visit.booked' => 'A visit or meeting is booked',
            'lead.deleted' => 'A lead is deleted'];
}

function crm_int_ready(): bool
{
    static $r = null;
    return $r ??= db_has_column('contacts', 'external_id');
}

/** Where the change being made came from: '' (a person in the app), or 'api' (API / incoming URL). */
function crm_int_origin(?string $set = null): string
{
    if ($set !== null) $GLOBALS['__crm_origin'] = $set;
    return (string) ($GLOBALS['__crm_origin'] ?? '');
}

/** The lead as another system sees it. Stable field names: this is the contract. */
function crm_int_lead(int $contactId): ?array
{
    $c = db_row("SELECT c.*, s.name AS stage_name, s.kind AS stage_kind, p.name AS project_name,
                        u.email AS owner_email, COALESCE(NULLIF(u.name,''), u.email) AS owner_name
                   FROM contacts c LEFT JOIN crm_stages s ON s.id=c.stage_id LEFT JOIN crm_projects p ON p.id=c.project_id
                   LEFT JOIN users u ON u.id=c.owner_user_id WHERE c.id=?", [$contactId]);
    if (!$c) return null;
    $custom = function_exists('crm_custom_get') ? crm_custom_get($c) : [];
    return [
        'id' => (int) $c['id'], 'code' => (string) ($c['code'] ?? ''), 'external_id' => $c['external_id'] ?? null, 'external_system' => $c['external_system'] ?? null,
        'name' => (string) $c['name'], 'phone' => '+' . $c['phone_e164'], 'email' => $c['email'],
        'stage' => $c['stage_name'], 'stage_id' => $c['stage_id'] !== null ? (int) $c['stage_id'] : null, 'stage_type' => $c['stage_kind'],
        'substatus' => $c['substatus'] ?? null, 'lost_reason' => $c['lost_reason'] ?? null,
        'owner' => $c['owner_user_id'] !== null ? ['id' => (int) $c['owner_user_id'], 'name' => $c['owner_name'], 'email' => $c['owner_email']] : null,
        'source' => $c['source'], 'platform' => $c['platform'] ?? null, 'campaign' => $c['campaign'] ?? null, 'adset' => $c['adset'] ?? null, 'ad_name' => $c['ad_name'] ?? null,
        'from_meta_directly' => function_exists('crm_is_meta_direct') ? crm_is_meta_direct($c['source'] ?? null) : null,
        'project' => $c['project_name'], 'unit_type' => $c['unit_type'] ?? null, 'budget' => $c['budget'] ?? null,
        'deal_value' => $c['deal_value'] !== null ? (float) $c['deal_value'] : null, 'qualification' => $c['qualification'] ?? null,
        'data_type' => $c['data_type'] ?? null, 'score' => isset($c['score']) && $c['score'] !== null ? (int) $c['score'] : null,
        'next_followup_at' => $c['next_followup_at'] ?? null, 'custom_fields' => $custom ?: new stdClass(),
        'created_at' => $c['crm_added_at'] ?: $c['created_at'], 'assigned_at' => $c['assigned_at'] ?? null,
        'url' => rtrim(app_base_url(), '/') . '/client/crm_lead.php?id=' . (int) $c['id'],
    ];
}

/**
 * Something happened to a lead: queue it for every webhook that wants this event.
 * Cheap when nothing listens (one cached query per request).
 */
function crm_hook(string $event, int $clientId, int $contactId, array $extra = []): void
{
    if (!crm_int_ready()) return;
    if (!empty($GLOBALS['__crm_adding'][$contactId]) && $event !== 'lead.created') return;   // folded into "created"
    static $hooks = [];
    try {
        $hooks[$clientId] ??= db_all("SELECT id, events, skip_api FROM crm_webhooks WHERE client_id=? AND active=1", [$clientId]);
    } catch (Throwable $e) { return; }
    $want = array_filter($hooks[$clientId], fn($h) => ($h['events'] === '*' || in_array($event, explode(',', (string) $h['events']), true))
                                                    && !((int) $h['skip_api'] && crm_int_origin() === 'api'));
    if (!$want) return;
    $lead = crm_int_lead($contactId);
    if (!$lead) return;
    $body = json_encode(['event' => $event, 'occurred_at' => date('c'), 'origin' => crm_int_origin() ?: 'app', 'lead' => $lead] + ($extra ? ['data' => $extra] : []),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach ($want as $h) {
        db_insert("INSERT INTO crm_webhook_deliveries (webhook_id,client_id,event,contact_id,payload,status,next_at,created_at) VALUES (?,?,?,?,?,'queued',NOW(),NOW())",
                  [(int) $h['id'], $clientId, $event, $contactId, $body]);
    }
}

/** Forget the cached webhook list (after the Integrations page changes it, and in tests). */
function crm_hook_reset(): void { /* the static cache lives one request; nothing to do across requests */ }

/* ───────────────────────── sending ───────────────────────── */

function crm_hook_sign(string $secret, string $body, int $ts): string
{
    return 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
}

/** POST one body to a webhook. Returns [http code, short response or error]. */
function crm_hook_post(array $hook, string $event, string $body, string $deliveryId): array
{
    // Checked again at every send: a name that pointed somewhere public yesterday may point inside today.
    if (($bad = crm_hook_url_ok((string) $hook['url'])) !== '') return [0, $bad];
    $ts = time();
    $secret = decrypt_secret((string) $hook['secret_enc']);
    $ch = curl_init((string) $hook['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: Revenect-Webhooks/1.0',
                               'X-Revenect-Event: ' . $event, 'X-Revenect-Delivery: ' . $deliveryId, 'X-Revenect-Timestamp: ' . $ts,
                               'X-Revenect-Signature: ' . crm_hook_sign($secret, $body, $ts)],
    ]);
    $out = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $out === false ? ($err ?: 'Could not connect') : mb_substr(trim((string) $out), 0, 300)];
}

/** Send what is due; retry failures after 1 min, 5 min, 30 min, 2 h, 6 h, then give up. */
function crm_hooks_tick(int $limit = 50): int
{
    if (!crm_int_ready()) return 0;
    $delays = [60, 300, 1800, 7200, 21600];
    $n = 0;
    foreach (db_all("SELECT d.*, h.url, h.secret_enc, h.active FROM crm_webhook_deliveries d JOIN crm_webhooks h ON h.id=d.webhook_id
                      WHERE d.status='queued' AND d.next_at <= NOW() ORDER BY d.id LIMIT " . (int) $limit) as $d) {
        if (!db_run("UPDATE crm_webhook_deliveries SET status='sending' WHERE id=? AND status='queued'", [(int) $d['id']])) continue;
        if (!(int) $d['active']) { db_run("UPDATE crm_webhook_deliveries SET status='failed', response='The webhook is switched off.' WHERE id=?", [(int) $d['id']]); continue; }
        [$code, $resp] = crm_hook_post($d, (string) $d['event'], (string) $d['payload'], (string) $d['id']);
        $okSent = $code >= 200 && $code < 300;
        $att = (int) $d['attempts'] + 1;
        if ($okSent) {
            db_run("UPDATE crm_webhook_deliveries SET status='sent', attempts=?, http_code=?, response=?, sent_at=NOW() WHERE id=?", [$att, $code, $resp, (int) $d['id']]);
            db_run("UPDATE crm_webhooks SET fail_streak=0, last_status=?, last_at=NOW() WHERE id=?", ['OK ' . $code, (int) $d['webhook_id']]);
        } else {
            $more = $att <= count($delays);
            db_run("UPDATE crm_webhook_deliveries SET status=?, attempts=?, http_code=?, response=?, next_at=NOW() + INTERVAL ? SECOND WHERE id=?",
                   [$more ? 'queued' : 'failed', $att, $code ?: null, $resp, $delays[min($att, count($delays)) - 1], (int) $d['id']]);
            db_run("UPDATE crm_webhooks SET fail_streak=fail_streak+1, last_status=?, last_at=NOW() WHERE id=?",
                   [mb_substr(($code ? 'HTTP ' . $code : 'No connection') . ($resp !== '' ? ' — ' . $resp : ''), 0, 255), (int) $d['webhook_id']]);
        }
        $n++;
    }
    return $n;
}

/** Only real addresses on the internet: a webhook must not be pointed at this server's own network. */
function crm_hook_url_ok(string $url): string
{
    if (!preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return 'Enter the full address, starting with https://';
    $host = (string) parse_url($url, PHP_URL_HOST);
    if (config('webhook_allow_private', false)) return '';                  // test rigs and on-premise setups
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return 'That address could not be found.';
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return 'That address is on a private network. Use a public one.';
    }
    return '';
}

/* ───────────────────────── API keys ───────────────────────── */

/** Make a key. The whole key is returned once and never stored — only its hash. */
function crm_api_key_create(int $clientId, string $name, string $access, string $system, ?int $by): string
{
    $prefix = 'rvn_' . bin2hex(random_bytes(3));
    $key = $prefix . '_' . bin2hex(random_bytes(20));
    db_insert("INSERT INTO crm_api_keys (client_id,name,prefix,key_hash,access,system,created_by,created_at) VALUES (?,?,?,?,?,?,?,NOW())",
              [$clientId, mb_substr(trim($name) ?: 'API key', 0, 80), $prefix, hash('sha256', $key), $access === 'write' ? 'write' : 'read',
               mb_substr(trim($system), 0, 40) ?: null, $by]);
    return $key;
}

/** The key's row and its account, or an error. Counts the request for the per-minute limit. */
function crm_api_key_auth(string $key): array
{
    if (!preg_match('/^rvn_[0-9a-f]{6}_[0-9a-f]{40}$/', $key)) return ['error' => 'Missing or malformed API key.', 'status' => 401];
    $k = db_row("SELECT * FROM crm_api_keys WHERE key_hash=? AND revoked_at IS NULL", [hash('sha256', $key)]);
    if (!$k) return ['error' => 'Unknown or revoked API key.', 'status' => 401];
    $client = db_row("SELECT * FROM clients WHERE id=? AND status='active'", [(int) $k['client_id']]);
    if (!$client) return ['error' => 'The account is not active.', 'status' => 403];
    $limit = (int) config('api_rate_per_minute', 120);
    db_run("UPDATE crm_api_keys SET last_used_at=NOW(),
                   window_count = IF(window_start IS NULL OR window_start < NOW() - INTERVAL 1 MINUTE, 1, window_count + 1),
                   window_start = IF(window_start IS NULL OR window_start < NOW() - INTERVAL 1 MINUTE, NOW(), window_start) WHERE id=?", [(int) $k['id']]);
    if ((int) db_val("SELECT window_count FROM crm_api_keys WHERE id=?", [(int) $k['id']]) > $limit) return ['error' => "Too many requests — the limit is $limit a minute.", 'status' => 429];
    return ['key' => $k, 'client' => $client];
}

/* ───────────────────────── creating and updating a lead from outside ───────────────────────── */

/** Our fields another system may set, with what each means. */
function crm_int_fields(): array
{
    return ['external_id' => 'Their id for the lead', 'name' => 'Full name', 'first_name' => 'First name', 'last_name' => 'Last name',
            'phone' => 'Phone (required for a new lead)', 'email' => 'Email', 'stage' => 'Stage (name)', 'substatus' => 'Sub-status',
            'lost_reason' => 'Lost reason', 'owner_email' => 'Salesperson (email)', 'source' => 'Source', 'platform' => 'Platform',
            'campaign' => 'Campaign', 'adset' => 'Ad set', 'ad_name' => 'Ad', 'project' => 'Project (name)', 'unit_type' => 'Unit type', 'budget' => 'Budget',
            'deal_value' => 'Deal value', 'qualification' => 'Qualified (qualified / not_qualified)', 'data_type' => 'Fresh or cold (fresh / cold)',
            'followup_at' => 'Next follow-up (date and time)', 'note' => 'A comment to add'];
}

/** Their names for our fields, tried when a URL has no mapping. Lower-case, compared without _ - spaces. */
function crm_int_synonyms(): array
{
    return ['external_id' => ['id', 'external_id', 'lead_id', 'leadid', 'record_id', 'crm_id'],
            'name' => ['name', 'full_name', 'fullname', 'customer_name', 'client_name', 'contact_name', 'lead_name'],
            'first_name' => ['first_name', 'firstname', 'fname', 'given_name'], 'last_name' => ['last_name', 'lastname', 'lname', 'surname', 'family_name'],
            'phone' => ['phone', 'phone_number', 'mobile', 'mobile_number', 'mobile_phone', 'whatsapp', 'whatsapp_number', 'tel', 'telephone', 'contact_number'],
            'email' => ['email', 'email_address', 'mail'], 'stage' => ['stage', 'status', 'lead_status', 'pipeline_stage'],
            'substatus' => ['substatus', 'sub_status'], 'lost_reason' => ['lost_reason'], 'owner_email' => ['owner_email', 'agent_email', 'sales_email', 'assigned_to_email'],
            'source' => ['source', 'lead_source', 'utm_source'], 'platform' => ['platform'], 'campaign' => ['campaign', 'campaign_name', 'utm_campaign'],
            'adset' => ['adset', 'adset_name', 'ad_set', 'ad_set_name'], 'ad_name' => ['ad_name', 'ad', 'utm_content'], 'project' => ['project', 'project_name', 'property', 'compound'],
            'unit_type' => ['unit_type', 'unit', 'property_type'], 'budget' => ['budget'], 'deal_value' => ['deal_value', 'value', 'amount'],
            'qualification' => ['qualification'], 'data_type' => ['data_type', 'lead_type'], 'followup_at' => ['followup_at', 'follow_up', 'next_followup'],
            'note' => ['note', 'notes', 'comment', 'comments', 'message', 'description']];
}

/** Every leaf of a JSON payload as "a.b.0.c" => value (to map from, and to show on the page). */
function crm_int_flatten($data, string $prefix = '', int $depth = 0): array
{
    $out = [];
    if (!is_array($data) || $depth > 6) return $prefix !== '' ? [$prefix => $data] : [];
    foreach ($data as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) $out += crm_int_flatten($v, $key, $depth + 1); else $out[$key] = $v;
        if (count($out) > 300) break;
    }
    return $out;
}

/**
 * An incoming payload → our fields: the mapping where there is one, the common names for the rest.
 * Also understands "field_data" lists ([{name, values}]) as Meta and some CRMs send them.
 */
function crm_int_map(array $payload, array $mapping): array
{
    $flat = crm_int_flatten($payload);
    foreach ((array) ($payload['field_data'] ?? []) as $fd) {
        if (is_array($fd) && isset($fd['name'])) $flat[(string) $fd['name']] = is_array($fd['values'] ?? null) ? (string) ($fd['values'][0] ?? '') : (string) ($fd['value'] ?? '');
    }
    // The mapping first; whatever it does not cover, the common names.
    $out = [];
    foreach ($mapping as $ours => $theirs) {
        if ($theirs === '' || !isset(crm_int_fields()[$ours]) && !str_starts_with((string) $ours, 'cf:')) continue;
        if (array_key_exists($theirs, $flat) && $flat[$theirs] !== null && $flat[$theirs] !== '') $out[$ours] = $flat[$theirs];
    }
    $mapped = array_flip(array_values(array_filter($mapping, 'strlen')));
    $norm = fn($s) => strtolower(str_replace(['_', '-', ' '], '', (string) $s));
    $byLeaf = [];
    foreach ($flat as $path => $v) {
        $leaf = $norm(preg_replace('/^.*\./', '', (string) $path));
        if (!isset($byLeaf[$leaf]) && $v !== null && $v !== '' && !is_bool($v)) $byLeaf[$leaf] = $v;
    }
    foreach ($flat as $path => $_) if (isset($mapped[$path])) unset($byLeaf[$norm(preg_replace('/^.*\./', '', (string) $path))]);   // already used
    foreach (crm_int_synonyms() as $ours => $names) {
        if (isset($out[$ours])) continue;
        foreach ($names as $n) if (isset($byLeaf[$norm($n)])) { $out[$ours] = $byLeaf[$norm($n)]; break; }
    }
    return $out;
}

/**
 * Create or update a lead from another system's data (already in our field names).
 * Finds the lead by external_id, then by phone. Only the fields given are changed.
 *
 * @param array $o ['system' => their name, 'owner' => 'auto'|'none'|user id for new leads, 'stage_id' => for new leads,
 *                  'update' => false to refuse updating an existing lead, 'source' => default source]
 * @return array{ok:bool, error?:string, id?:int, created?:bool, lead?:array}
 */
function crm_int_upsert(array $client, array $d, array $o = []): array
{
    $cid = (int) $client['id'];
    $prev = crm_int_origin(); crm_int_origin('api');
    try {
        $s = fn(string $k, int $len = 255) => mb_substr(trim((string) (is_scalar($d[$k] ?? null) ? $d[$k] : '')), 0, $len);
        $system = mb_substr(trim((string) ($o['system'] ?? '')), 0, 40);
        $ext = $s('external_id', 100);
        $name = $s('name', 150);
        if ($name === '' && ($s('first_name') !== '' || $s('last_name') !== '')) $name = trim($s('first_name', 75) . ' ' . $s('last_name', 75));
        $phone = $s('phone', 40) !== '' ? normalize_phone($s('phone', 40), (string) ($client['default_country'] ?? '')) : '';
        if ($s('phone', 40) !== '' && $phone === '') return ['ok' => false, 'error' => 'The phone number is not valid: ' . $s('phone', 40)];
        $email = $s('email', 190);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

        $c = null;
        if ($ext !== '') $c = db_row("SELECT * FROM contacts WHERE client_id=? AND external_id=?" . ($system !== '' ? " AND (external_system=? OR external_system IS NULL)" : ''),
                                     $system !== '' ? [$cid, $ext, $system] : [$cid, $ext]);
        if (!$c && $phone !== '') $c = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $phone]);
        if (!empty($o['id'])) $c = db_row("SELECT * FROM contacts WHERE client_id=? AND id=?", [$cid, (int) $o['id']]);
        $created = false;
        if ($c && $c['stage_id'] !== null && isset($o['update']) && !$o['update']) return ['ok' => false, 'error' => 'That lead already exists.', 'id' => (int) $c['id']];
        if (!$c) {
            if ($phone === '') return ['ok' => false, 'error' => 'A phone number is needed to add a lead.'];
            $id = db_insert("INSERT INTO contacts (client_id,phone_e164,name,email,opt_in_status,source,created_at) VALUES (?,?,?,?,'in',?,NOW())",
                            [$cid, $phone, $name, $email !== '' ? $email : null, $s('source', 30) ?: ($o['source'] ?? 'api')]);
            $c = db_row("SELECT * FROM contacts WHERE id=?", [$id]);
        }
        $id = (int) $c['id'];
        $before = function_exists('crm_field_snapshot') ? crm_field_snapshot($cid, $c) : [];
        if ($c['stage_id'] === null) {
            // Everything that follows belongs to "lead.created", sent once at the end with the full record.
            $GLOBALS['__crm_adding'][$id] = true; $GLOBALS['__crm_defer'][$id] = true;
            $owner = $o['owner'] ?? 'auto';
            if ($s('owner_email', 190) !== '') {
                $u = db_val("SELECT id FROM users WHERE client_id=? AND email=? AND status='active'", [$cid, $s('owner_email', 190)]);
                if ($u) $owner = (int) $u;
            }
            $stageId = (int) ($o['stage_id'] ?? 0) ?: null;
            if ($s('stage') !== '') $stageId = crm_int_stage_id($cid, $s('stage')) ?: $stageId;
            crm_add_lead($client, $id, $s('source', 30) ?: (string) ($o['source'] ?? 'api'), $owner, $stageId, null);
            $created = true;
        }
        // The plain fields: only those given.
        $set = []; $p = [];
        $put = function (string $col, $val) use (&$set, &$p) { $set[] = "$col=?"; $p[] = $val; };
        if ($name !== '') $put('name', $name);
        if ($email !== '') $put('email', $email);
        if ($ext !== '') { $put('external_id', $ext); if ($system !== '') $put('external_system', $system); }
        if ($s('unit_type') !== '' && db_has_column('contacts', 'unit_type')) $put('unit_type', $s('unit_type', 80));
        if ($s('budget') !== '' && db_has_column('contacts', 'budget')) $put('budget', $s('budget', 80));
        if ($s('deal_value') !== '') $put('deal_value', crm_deal_value($s('deal_value'), (string) $c['phone_e164']));
        if ($s('project') !== '') { $pid = db_val("SELECT id FROM crm_projects WHERE client_id=? AND name=?", [$cid, $s('project')]); if ($pid) $put('project_id', (int) $pid); }
        if (isset(crm_qualifications()[$s('qualification')])) $put('qualification', $s('qualification'));
        if (isset(crm_data_types()[$s('data_type')])) $put('data_type', $s('data_type'));
        if (!$created && $s('source', 30) !== '') $put('source', $s('source', 30));
        if ($set) db_run("UPDATE contacts SET " . implode(',', $set) . " WHERE id=? AND client_id=?", array_merge($p, [$id, $cid]));
        crm_set_origin($id, ['campaign' => $s('campaign', 160), 'platform' => $s('platform', 16), 'adset' => $s('adset', 160), 'ad_name' => $s('ad_name', 160)]);
        if (!empty($d['custom']) && is_array($d['custom']) && function_exists('crm_custom_save')) crm_custom_save($cid, $id, $d['custom']);

        // Moves that have their own rules: stage, sub-status, owner, follow-up, a comment.
        if (!$created) {
            if ($s('stage') !== '' && ($sid = crm_int_stage_id($cid, $s('stage')))) crm_set_stage($client, $id, $sid, null, $s('lost_reason', 80) ?: null);
            if ($s('owner_email', 190) !== '') {
                $u = db_val("SELECT id FROM users WHERE client_id=? AND email=? AND status='active'", [$cid, $s('owner_email', 190)]);
                if ($u) crm_assign($client, $id, (int) $u, null);
            }
        }
        if ($s('substatus') !== '' && function_exists('crm_set_substatus')) crm_set_substatus($client, $id, $s('substatus', 80), null);
        if ($s('followup_at') !== '' && strtotime($s('followup_at'))) crm_set_followup($client, $id, $s('followup_at'), '', null);
        if ($s('note', 5000) !== '') crm_log_activity($client, $id, 'note', null, ($system !== '' ? "[$system] " : '') . $s('note', 5000), null);
        if (!$created && $before && function_exists('crm_log_changes')) crm_log_changes($cid, $id, $before, null);
        unset($GLOBALS['__crm_adding'][$id], $GLOBALS['__crm_defer'][$id]);
        if ($created) { crm_rescore($id); crm_hook('lead.created', $cid, $id); }
        return ['ok' => true, 'id' => $id, 'created' => $created, 'lead' => crm_int_lead($id)];
    } finally {
        unset($GLOBALS['__crm_adding'], $GLOBALS['__crm_defer']);
        crm_int_origin($prev);
    }
}

/** A stage by its name (any case) or its id. */
function crm_int_stage_id(int $clientId, string $v): int
{
    foreach (crm_stage_map($clientId) as $id => $s) {
        if ((string) $id === $v || mb_strtolower((string) $s['name']) === mb_strtolower($v)) return (int) $id;
    }
    return 0;
}

/* ───────────────────────── incoming webhook URLs ───────────────────────── */

function crm_inbound_create(int $clientId, string $name, ?int $by): string
{
    $token = bin2hex(random_bytes(24));
    db_insert("INSERT INTO crm_inbound_hooks (client_id,name,token_hash,token_hint,token_enc,created_by,created_at) VALUES (?,?,?,?,?,?,NOW())",
              [$clientId, mb_substr(trim($name) ?: 'Incoming leads', 0, 80), hash('sha256', $token), substr($token, 0, 6), encrypt_secret($token), $by]);
    return $token;
}

function crm_inbound_url(string $token): string
{
    return rtrim(app_base_url(), '/') . '/crm_hook.php?t=' . $token;
}

/** One POST to an incoming URL: map it, create or update the lead, remember what came. */
function crm_inbound_receive(array $hook, array $payload): array
{
    $client = db_row("SELECT * FROM clients WHERE id=? AND status='active'", [(int) $hook['client_id']]);
    if (!$client) return ['ok' => false, 'error' => 'The account is not active.'];
    $GLOBALS['CLIENT'] = $client;
    $mapping = json_decode((string) ($hook['mapping'] ?? ''), true) ?: [];
    $d = crm_int_map($payload, $mapping);
    // Custom fields mapped as "cf:<key>".
    foreach ($d as $k => $v) if (str_starts_with((string) $k, 'cf:')) { $d['custom'][substr((string) $k, 3)] = $v; unset($d[$k]); }
    $owner = ctype_digit((string) $hook['owner_rule']) ? (int) $hook['owner_rule'] : (string) $hook['owner_rule'];
    $r = crm_int_upsert($client, $d, ['system' => (string) ($hook['system'] ?? ''), 'owner' => $owner, 'stage_id' => $hook['stage_id'] !== null ? (int) $hook['stage_id'] : null,
                                     'update' => (bool) (int) $hook['update_existing'], 'source' => 'api']);
    $result = $r['ok'] ? ($r['created'] ? 'Added lead #' . ($r['lead']['code'] ?? $r['id']) : 'Updated lead #' . ($r['lead']['code'] ?? $r['id'])) : 'Refused: ' . $r['error'];
    db_run("UPDATE crm_inbound_hooks SET received=received+1, last_at=NOW(), last_payload=?, last_result=? WHERE id=?",
           [mb_substr(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 0, 60000), mb_substr($result, 0, 255), (int) $hook['id']]);
    return $r;
}
