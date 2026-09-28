<?php
declare(strict_types=1);

/**
 * Meta Lead Ads — Facebook and Instagram instant forms, straight into the CRM.
 *
 * The flow, end to end:
 *   1. A client presses Connect Facebook and approves the platform's Meta app for their Pages
 *      (Facebook Login; the OAuth shape copies includes/google.php).
 *   2. We keep each Page's token, encrypted, and subscribe the app to that Page's `leadgen` field.
 *   3. When someone submits a form, Meta calls webhook_leads.php with only a lead id. We fetch the
 *      answers with the Page token and turn them into an assigned CRM lead.
 *   4. A poller re-reads every enabled form on a timer. Webhooks are delivered at-least-once in
 *      theory and occasionally not at all in practice; this is what makes "never lose a lead" true.
 *
 * One Meta app serves every client — the operator registers it once in Admin → Settings. Using it
 * on other businesses' Pages needs Meta App Review for `leads_retrieval` and
 * `pages_manage_metadata`; before that it works only on Pages the app's own admins manage.
 */

require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/whatsapp.php';

// pages_manage_ads is what lets us LIST a Page's forms and their questions; leads_retrieval reads the leads.
const META_SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_metadata,pages_manage_ads,leads_retrieval,business_management';

/** Where a form's answer can go. */
function meta_targets(): array
{
    return ['phone' => 'Phone (required)', 'name' => 'Full name', 'first_name' => 'First name', 'last_name' => 'Last name',
            'email' => 'Email', 'attr' => 'Keep as a detail on the lead', 'ignore' => 'Don\'t import'];
}

function meta_setting(string $k): string
{
    try { return trim((string) db_val("SELECT v FROM app_settings WHERE k=?", [$k])); }
    catch (Throwable $e) { return ''; }
}

function meta_cfg(): array
{
    return [
        'app_id'     => meta_setting('meta_app_id'),
        'app_secret' => (($s = meta_setting('meta_app_secret')) !== '' ? decrypt_secret($s) : ''),
        // Facebook Login for Business: a saved configuration in the app decides the permissions.
        'config_id'  => meta_setting('meta_config_id'),
    ];
}

function meta_configured(): bool
{
    $c = meta_cfg();
    return $c['app_id'] !== '' && $c['app_secret'] !== '';
}

function meta_redirect_uri(): string
{
    return rtrim(app_base_url(), '/') . '/meta_oauth.php';
}

/** Where the login dialog lives. Overridable only so tests can point it at a stand-in. */
function meta_dialog_base(): string
{
    return rtrim((string) config('fb_dialog_host', 'https://www.facebook.com'), '/') . '/' . config('graph_version', 'v21.0');
}

/** A Graph call. Returns ['ok', 'http', 'json', 'error']. */
function meta_http(string $method, string $path, array $params = []): array
{
    $url = wa_graph_base() . '/' . ltrim($path, '/');
    $ch  = curl_init();
    if ($method === 'GET' || $method === 'DELETE') {
        $url .= ($params ? (str_contains($url, '?') ? '&' : '?') . http_build_query($params) : '');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    } else {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params)]);
    }
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $json = json_decode((string) $body, true);
    if ($body === false || $http === 0) return ['ok' => false, 'http' => 0, 'json' => null, 'error' => 'Could not reach Facebook: ' . $cerr];
    $ok = $http >= 200 && $http < 300 && is_array($json) && !isset($json['error']);
    return ['ok' => $ok, 'http' => $http, 'json' => $json,
            'error' => $ok ? '' : (string) ($json['error']['message'] ?? ('HTTP ' . $http))];
}

/** The Connect button's destination. State is single-use and expires, like the Google flow. */
function meta_auth_url(int $clientId, ?int $userId): string
{
    $state = bin2hex(random_bytes(24));
    db_run("INSERT INTO meta_oauth_state (state,client_id,user_id,created_at) VALUES (?,?,?,NOW())", [$state, $clientId, $userId]);
    try { db_run("DELETE FROM meta_oauth_state WHERE created_at < NOW() - INTERVAL 1 HOUR"); } catch (Throwable $e) {}
    $cfg = meta_cfg();
    // Business-type apps reject a scope list and want the id of a Login configuration instead.
    $ask = $cfg['config_id'] !== '' ? ['config_id' => $cfg['config_id'], 'override_default_response_type' => 'true']
                                    : ['scope' => META_SCOPES];
    return meta_dialog_base() . '/dialog/oauth?' . http_build_query([
        'client_id'     => $cfg['app_id'],
        'redirect_uri'  => meta_redirect_uri(),
        'state'         => $state,
        'response_type' => 'code',
    ] + $ask);
}

function meta_take_state(string $state): ?array
{
    if ($state === '') return null;
    $row = db_row("SELECT * FROM meta_oauth_state WHERE state=? AND created_at > NOW() - INTERVAL 1 HOUR", [$state]);
    if ($row) db_run("DELETE FROM meta_oauth_state WHERE state=?", [$state]);
    return $row ?: null;
}

/**
 * Finish the login: code → user token → long-lived user token → every Page with its own token.
 *
 * The long-lived step matters. A Page token taken from a SHORT-lived user token dies with it
 * after about an hour, and leads would stop arriving silently the same afternoon. Taken from a
 * long-lived user token, the Page token does not expire on a timer at all.
 *
 * @return array{ok:bool, error?:string, pages?:int}
 */
function meta_finish_connect(int $clientId, string $code): array
{
    $c = meta_cfg();
    $short = meta_http('GET', 'oauth/access_token', ['client_id' => $c['app_id'], 'client_secret' => $c['app_secret'],
                                                      'redirect_uri' => meta_redirect_uri(), 'code' => $code]);
    if (!$short['ok'] || empty($short['json']['access_token'])) return ['ok' => false, 'error' => $short['error'] ?: 'Facebook did not return a token.'];

    $long = meta_http('GET', 'oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $c['app_id'],
                                                     'client_secret' => $c['app_secret'], 'fb_exchange_token' => $short['json']['access_token']]);
    $userToken = (string) ($long['json']['access_token'] ?? $short['json']['access_token']);

    $pages = meta_http('GET', 'me/accounts', ['fields' => 'id,name,access_token', 'limit' => 100, 'access_token' => $userToken]);
    if (!$pages['ok']) return ['ok' => false, 'error' => $pages['error']];
    $list = (array) ($pages['json']['data'] ?? []);
    if (!$list) {
        return ['ok' => false, 'error' => 'No Facebook Pages came back. On the Facebook screen, make sure you tick the Pages '
                                        . 'your lead forms run on — and that you are an admin of them.'];
    }
    foreach ($list as $p) {
        if (empty($p['id']) || empty($p['access_token'])) continue;
        db_run("INSERT INTO meta_pages (client_id,page_id,name,token_enc,connected_at) VALUES (?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE name=VALUES(name), token_enc=VALUES(token_enc), last_error=NULL",
               [$clientId, (string) $p['id'], mb_substr((string) ($p['name'] ?? ''), 0, 190), encrypt_secret((string) $p['access_token'])]);
    }
    return ['ok' => true, 'pages' => count($list)];
}

function meta_page_token(array $page): string
{
    return $page['token_enc'] ? decrypt_secret((string) $page['token_enc']) : '';
}

/** Start or stop leads arriving from a Page. */
function meta_page_subscribe(array $page, bool $on): array
{
    $r = meta_http($on ? 'POST' : 'DELETE', $page['page_id'] . '/subscribed_apps',
                   ['subscribed_fields' => 'leadgen', 'access_token' => meta_page_token($page)]);
    db_run("UPDATE meta_pages SET subscribed=?, last_error=? WHERE id=?",
           [$r['ok'] ? ($on ? 1 : 0) : (int) $page['subscribed'], $r['ok'] ? null : mb_substr($r['error'], 0, 255), (int) $page['id']]);
    if ($r['ok'] && $on) meta_sync_forms($page);
    return $r;
}

/** Read the Page's forms, so the client can choose which ones feed the CRM. */
function meta_sync_forms(array $page): array
{
    $r = meta_http('GET', $page['page_id'] . '/leadgen_forms',
                   ['fields' => 'id,name,status', 'limit' => 100, 'access_token' => meta_page_token($page)]);
    if (!$r['ok']) return $r;
    foreach ((array) ($r['json']['data'] ?? []) as $f) {
        if (empty($f['id'])) continue;
        db_run("INSERT INTO meta_forms (client_id,page_id,form_id,name,enabled) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE name=VALUES(name), page_id=VALUES(page_id)",
               [(int) $page['client_id'], (string) $page['page_id'], (string) $f['id'],
                mb_substr((string) ($f['name'] ?? ''), 0, 190), (int) $page['all_forms']]);
    }
    return $r;
}

/**
 * Turn a form's answers into contact fields.
 *
 * Meta names the standard questions (full_name, phone_number, email) but custom questions arrive
 * under whatever the advertiser typed, in any language. So match the standard names first, then
 * anything that looks like a phone; everything else is kept as a detail on the lead, since a
 * question someone bothered to ask on the form is information the salesperson wants.
 */
function meta_map_fields(array $fieldData, ?array $mapping = null): array
{
    $out = ['phone' => '', 'name' => '', 'email' => '', 'attrs' => []];
    $first = $last = '';
    $mapping = $mapping ? array_change_key_case($mapping, CASE_LOWER) : null;
    foreach ($fieldData as $f) {
        $key = mb_strtolower(trim((string) ($f['name'] ?? '')));
        $val = trim((string) (is_array($f['values'] ?? null) ? implode(', ', $f['values']) : ($f['values'] ?? '')));
        if ($key === '' || $val === '') continue;
        // The client's own mapping for this form wins; a question it doesn't mention falls through to recognition.
        if ($mapping && isset($mapping[$key])) {
            switch ($mapping[$key]) {
                case 'ignore':     continue 2;
                case 'phone':      if ($out['phone'] === '') $out['phone'] = $val; continue 2;
                case 'name':       $out['name'] = $val; continue 2;
                case 'first_name': $first = $val; continue 2;
                case 'last_name':  $last = $val; continue 2;
                case 'email':      $out['email'] = $val; continue 2;
                case 'attr':       $out['attrs'][$key] = $val; continue 2;
            }
        }
        if ($key === 'phone_number' || $key === 'phone') { $out['phone'] = $val; continue; }
        if ($key === 'full_name')                         { $out['name']  = $val; continue; }
        if ($key === 'first_name')                        { $first = $val; continue; }
        if ($key === 'last_name')                         { $last  = $val; continue; }
        if ($key === 'email')                             { $out['email'] = $val; continue; }
        if ($out['phone'] === '' && preg_match('/phone|mobile|whatsapp|موبايل|رقم|هاتف/u', $key)) { $out['phone'] = $val; continue; }
        $out['attrs'][$key] = $val;
    }
    if ($out['name'] === '') $out['name'] = trim($first . ' ' . $last);
    return $out;
}

/** Record what happened to a lead. The row was claimed first, so this only ever updates it. */
function meta_log(int $clientId, string $leadgenId, string $outcome, string $detail = '', ?int $contactId = null): void
{
    db_run("UPDATE meta_lead_log SET outcome=?, detail=?, contact_id=? WHERE client_id=? AND leadgen_id=?",
           [$outcome, $detail !== '' ? mb_substr($detail, 0, 255) : null, $contactId, $clientId, $leadgenId]);
}

/**
 * Import one lead. Safe to call from the webhook and the poller at the same instant: the lead is
 * claimed with an INSERT against a unique key before any work, and whoever loses the race stops.
 *
 * @param array|null $lead the lead's data when the caller already has it (the poller does)
 * @return string outcome: imported | duplicate | skipped | error
 */
function meta_process_lead(array $page, string $leadgenId, string $via, ?array $lead = null, string $formId = ''): string
{
    $cid = (int) $page['client_id'];
    $leadgenId = preg_replace('/[^0-9]/', '', $leadgenId);
    if ($leadgenId === '') return 'skipped';

    try {
        $claimed = db_run("INSERT IGNORE INTO meta_lead_log (client_id,page_id,form_id,leadgen_id,via,outcome,created_at)
                           VALUES (?,?,?,?,?, 'processing', NOW())",
                          [$cid, (string) $page['page_id'], $formId !== '' ? $formId : null, $leadgenId, $via]);
    } catch (Throwable $e) {
        return 'error';
    }
    if (!$claimed) return 'duplicate';

    $client = db_row("SELECT * FROM clients WHERE id=?", [$cid]);
    if (!$client || !crm_enabled($client)) { meta_log($cid, $leadgenId, 'skipped', 'The CRM is not switched on for this account.'); return 'skipped'; }

    if ($lead === null) {
        $r = meta_http('GET', $leadgenId, ['fields' => 'id,created_time,field_data,form_id,ad_id,ad_name,campaign_name',
                                           'access_token' => meta_page_token($page)]);
        if (!$r['ok']) {
            // Release the claim so the poller can try again, rather than marking it lost forever.
            db_run("DELETE FROM meta_lead_log WHERE client_id=? AND leadgen_id=?", [$cid, $leadgenId]);
            db_run("UPDATE meta_pages SET last_error=? WHERE id=?", [mb_substr('Could not read a lead: ' . $r['error'], 0, 255), (int) $page['id']]);
            return 'error';
        }
        $lead = $r['json'];
    }
    $formId = (string) ($lead['form_id'] ?? $formId);
    db_run("UPDATE meta_lead_log SET form_id=? WHERE client_id=? AND leadgen_id=?", [$formId ?: null, $cid, $leadgenId]);

    $form = $formId !== '' ? db_row("SELECT * FROM meta_forms WHERE client_id=? AND form_id=?", [$cid, $formId]) : null;
    if ($form && !(int) $form['enabled']) { meta_log($cid, $leadgenId, 'skipped', 'That form is switched off.'); return 'skipped'; }
    if (!$form && !(int) $page['all_forms']) { meta_log($cid, $leadgenId, 'skipped', 'A form not chosen for import.'); return 'skipped'; }

    $map = json_decode((string) ($form['mapping'] ?? ''), true);
    $m = meta_map_fields((array) ($lead['field_data'] ?? []), is_array($map) ? $map : null);
    $phone = normalize_phone($m['phone'], (string) ($client['default_country'] ?? ''));
    if ($phone === '') {
        meta_log($cid, $leadgenId, 'skipped', $m['phone'] === '' ? 'The form has no phone number.' : 'Not a usable phone number: ' . $m['phone']);
        return 'skipped';
    }

    $existing = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $phone]);
    $attrs = $m['attrs'] + array_filter(['lead form' => (string) ($form['name'] ?? ''), 'meta campaign' => (string) ($lead['campaign_name'] ?? ''),
                                         'meta ad' => (string) ($lead['ad_name'] ?? '')]);
    if ($existing) {
        $merged = array_merge(json_decode((string) ($existing['attributes'] ?? ''), true) ?: [], $attrs);
        db_run("UPDATE contacts SET name=COALESCE(NULLIF(?,''),name), email=COALESCE(NULLIF(?,''),email), attributes=? WHERE id=?",
               [$m['name'], $m['email'], json_encode($merged, JSON_UNESCAPED_UNICODE), (int) $existing['id']]);
        $contactId = (int) $existing['id'];
    } else {
        $contactId = db_insert("INSERT INTO contacts (client_id,phone_e164,name,email,attributes,opt_in_status,source,created_at)
                                VALUES (?,?,?,?,?, 'in','meta_form',NOW())",
                               [$cid, $phone, $m['name'], $m['email'] !== '' ? $m['email'] : null, json_encode($attrs, JSON_UNESCAPED_UNICODE)]);
    }

    // The form's own rule, else the Page's. A number that is already someone's lead keeps its owner.
    $rule  = (string) (($form['owner_rule'] ?? null) ?: $page['owner_rule'] ?: 'auto');
    $owner = ctype_digit($rule) ? (int) $rule : $rule;
    $stage = (int) (($form['stage_id'] ?? null) ?: $page['stage_id'] ?: 0) ?: null;
    $isNew = crm_add_lead($client, $contactId, 'meta_form', $owner, $stage);

    $answers = [];
    foreach ($m['attrs'] as $k => $v) $answers[] = ucfirst($k) . ': ' . $v;
    crm_add_note($client, $contactId, 'Submitted the Facebook/Instagram form "' . ($form['name'] ?? $formId) . '"'
        . ($answers ? ".\n" . implode("\n", $answers) : '.'));

    meta_log($cid, $leadgenId, 'imported', $isNew ? '' : 'Already a lead — kept its owner, added the new answers.', $contactId);
    return 'imported';
}

/**
 * The safety net: re-read each enabled form for leads since it was last checked.
 *
 * Throttled per form so a busy cron does not hammer Meta. Dedupe is the same claim the webhook
 * uses, so a lead the webhook already imported is simply skipped here.
 *
 * @return array{forms:int, imported:int}
 */
function meta_poll(int $everyMinutes = 5, int $maxForms = 50): array
{
    $sum = ['forms' => 0, 'imported' => 0];
    try {
        $forms = db_all("SELECT f.*, p.id AS pid FROM meta_forms f
                           JOIN meta_pages p ON p.client_id = f.client_id AND p.page_id = f.page_id
                          WHERE f.enabled = 1 AND p.subscribed = 1
                            AND (f.last_polled_at IS NULL OR f.last_polled_at < NOW() - INTERVAL ? MINUTE)
                          ORDER BY f.last_polled_at IS NULL DESC, f.last_polled_at ASC LIMIT " . (int) $maxForms, [$everyMinutes]);
    } catch (Throwable $e) {
        return $sum;                                     // migration 035 not applied yet
    }
    foreach ($forms as $f) {
        $page = db_row("SELECT * FROM meta_pages WHERE id=?", [(int) $f['pid']]);
        if (!$page) continue;
        // A little overlap with the previous window, so a lead created at the boundary is not missed.
        $since = $f['last_polled_at'] ? strtotime((string) $f['last_polled_at']) - 300 : time() - 3 * 86400;
        $r = meta_http('GET', $f['form_id'] . '/leads', [
            'fields'       => 'id,created_time,field_data,form_id,ad_id,ad_name,campaign_name',
            'filtering'    => json_encode([['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $since]]),
            'limit'        => 100,
            'access_token' => meta_page_token($page),
        ]);
        db_run("UPDATE meta_forms SET last_polled_at=NOW() WHERE id=?", [(int) $f['id']]);
        $sum['forms']++;
        if (!$r['ok']) { db_run("UPDATE meta_pages SET last_error=? WHERE id=?", [mb_substr($r['error'], 0, 255), (int) $page['id']]); continue; }
        foreach ((array) ($r['json']['data'] ?? []) as $lead) {
            if (meta_process_lead($page, (string) ($lead['id'] ?? ''), 'poll', $lead, (string) $f['form_id']) === 'imported') $sum['imported']++;
        }
    }
    return $sum;
}

/**
 * A Page's forms, read live from Facebook, with their questions and lead counts — what the
 * "choose a form" dropdown and the mapping step are built from.
 *
 * @return array{ok:bool, error:string, forms:array}
 */
function meta_page_forms(array $page): array
{
    $r = meta_http('GET', $page['page_id'] . '/leadgen_forms', [
        'fields' => 'id,name,status,leads_count,created_time,questions{key,label,type}',
        'limit' => 200, 'access_token' => meta_page_token($page)]);
    if (!$r['ok']) return ['ok' => false, 'error' => meta_explain_error($r['error']), 'forms' => []];
    $forms = [];
    foreach ((array) ($r['json']['data'] ?? []) as $f) {
        if (empty($f['id'])) continue;
        $qs = [];
        foreach ((array) ($f['questions'] ?? []) as $q) {
            $key = (string) ($q['key'] ?? '');
            if ($key === '') continue;
            $qs[] = ['key' => $key, 'label' => (string) ($q['label'] ?? '') ?: ucfirst(str_replace('_', ' ', $key)),
                     'type' => (string) ($q['type'] ?? ''), 'guess' => meta_guess_target($key, (string) ($q['type'] ?? ''))];
        }
        $forms[] = ['id' => (string) $f['id'], 'name' => (string) ($f['name'] ?? $f['id']), 'status' => (string) ($f['status'] ?? ''),
                    'leads_count' => isset($f['leads_count']) ? (int) $f['leads_count'] : null, 'questions' => $qs];
    }
    return ['ok' => true, 'error' => '', 'forms' => $forms];
}

/** The sensible default for a question, so most forms need no changes at all. */
function meta_guess_target(string $key, string $type): string
{
    $t = strtoupper($type); $k = mb_strtolower($key);
    if ($t === 'PHONE' || in_array($k, ['phone_number', 'phone'], true)) return 'phone';
    if ($t === 'FULL_NAME' || $k === 'full_name') return 'name';
    if ($t === 'FIRST_NAME' || $k === 'first_name') return 'first_name';
    if ($t === 'LAST_NAME' || $k === 'last_name') return 'last_name';
    if ($t === 'EMAIL' || $k === 'email') return 'email';
    if (preg_match('/phone|mobile|whatsapp|موبايل|رقم|هاتف/u', $k)) return 'phone';
    return 'attr';
}

/** Facebook's permission errors, said in terms of what to do about them. */
function meta_explain_error(string $err): string
{
    if (preg_match('/pages_manage_ads|leads_retrieval|permission|\(#200\)|\(#10\)/i', $err)) {
        return 'Facebook would not share this Page\'s forms: ' . $err . ' — press "Reconnect Facebook" and allow every permission it asks for. '
             . 'You also need to be an admin of the Page (or have Leads access in its settings).';
    }
    return $err;
}

/**
 * Pull every lead Meta still holds for one form (it keeps 90 days), page by page.
 * Dedupe is the same claim the webhook uses, so running it twice imports nothing twice.
 *
 * @return array{ok:bool, error:string, imported:int, already:int, skipped:int, more:bool}
 */
function meta_sync_form(array $form, int $max = 1000): array
{
    $sum = ['ok' => true, 'error' => '', 'imported' => 0, 'already' => 0, 'skipped' => 0, 'more' => false];
    $page = db_row("SELECT * FROM meta_pages WHERE client_id=? AND page_id=?", [(int) $form['client_id'], (string) $form['page_id']]);
    if (!$page) return ['ok' => false, 'error' => 'The Page for this form is no longer connected.'] + $sum;
    $params = ['fields' => 'id,created_time,field_data,form_id,ad_id,ad_name,campaign_name', 'limit' => 100,
               'access_token' => meta_page_token($page)];
    $seen = 0;
    while (true) {
        $r = meta_http('GET', $form['form_id'] . '/leads', $params);
        if (!$r['ok']) { $sum['ok'] = false; $sum['error'] = meta_explain_error($r['error']); break; }
        foreach ((array) ($r['json']['data'] ?? []) as $lead) {
            $out = meta_process_lead($page, (string) ($lead['id'] ?? ''), 'poll', $lead, (string) $form['form_id']);
            $sum[$out === 'imported' ? 'imported' : ($out === 'duplicate' ? 'already' : 'skipped')]++;
            $seen++;
        }
        $after = (string) ($r['json']['paging']['cursors']['after'] ?? '');
        if ($after === '' || empty($r['json']['paging']['next'])) break;
        if ($seen >= $max) { $sum['more'] = true; break; }
        $params['after'] = $after;
    }
    // Reading the form worked, so whatever Facebook complained about before is over.
    if ($sum['ok']) db_run("UPDATE meta_pages SET last_error=NULL WHERE id=?", [(int) $page['id']]);
    // Fresh count while we are here.
    $c = meta_http('GET', (string) $form['form_id'], ['fields' => 'leads_count', 'access_token' => meta_page_token($page)]);
    $cnt = $c['ok'] && isset($c['json']['leads_count']) ? (int) $c['json']['leads_count'] : null;
    db_run("UPDATE meta_forms SET last_synced_at=NOW(), last_polled_at=NOW(), leads_count=COALESCE(?, leads_count) WHERE id=?",
           [$cnt, (int) $form['id']]);
    return $sum;
}
