<?php
declare(strict_types=1);

/**
 * Sending sales results back to Meta — the Conversions API for CRM.
 *
 * When a lead from a Facebook/Instagram form moves along — contacted, came to a visit, bought —
 * Meta is told, with the lead's own Meta id. Its ads can then optimise for people who buy, not
 * only for people who fill in forms, which usually brings the cost per real buyer down.
 *
 * Only leads that came from a Meta lead form are sent (Meta matches them by their lead id), and
 * each event goes once per lead. The phone and email go too, hashed with SHA-256 as Meta
 * requires — never in the clear.
 */

require_once __DIR__ . '/meta_leads.php';      // meta_http()

/** The events this account sends, per stage id. */
function crm_capi_stage_events(array $s): array
{
    $e = json_decode((string) ($s['capi_events'] ?? ''), true);
    return is_array($e) ? array_filter(array_map(fn($v) => trim((string) $v), $e), fn($v) => $v !== '') : [];
}

/** The lead's Meta lead id, when it came from a lead form. */
function crm_capi_lead_id(int $contactId): ?string
{
    try {
        $id = db_val("SELECT leadgen_id FROM meta_lead_log WHERE contact_id=? AND outcome='imported' ORDER BY id LIMIT 1", [$contactId]);
        return $id ? (string) $id : null;
    } catch (Throwable $e) { return null; }
}

/** Queue an event for a lead — once. Leads that did not come from a Meta form are left out. */
function crm_capi_queue(array $client, int $contactId, string $eventName): bool
{
    if (!db_has_column('crm_settings', 'capi_on')) return false;
    $s = crm_settings((int) $client['id']);
    if (!(int) $s['capi_on'] || trim($eventName) === '') return false;
    if (crm_capi_lead_id($contactId) === null) return false;
    return db_run("INSERT IGNORE INTO crm_capi_events (client_id,contact_id,event_name,event_time,status,created_at) VALUES (?,?,?,?, 'queued', NOW())",
                  [(int) $client['id'], $contactId, mb_substr(trim($eventName), 0, 60), time()]) > 0;
}

/** A lead reached a stage: queue that stage's event, if it has one. */
function crm_capi_on_stage(array $client, int $contactId, int $stageId): void
{
    try {
        $ev = crm_capi_stage_events(crm_settings((int) $client['id']))[(string) $stageId] ?? '';
        if ($ev !== '') crm_capi_queue($client, $contactId, $ev);
    } catch (Throwable $e) { error_log('crm_capi_on_stage: ' . $e->getMessage()); }
}

/** One event as Meta wants it. */
function crm_capi_payload(array $row, array $contact, string $leadId): array
{
    $user = ['lead_id' => (int) $leadId];
    $ph = preg_replace('/\D+/', '', (string) $contact['phone_e164']);
    if ($ph !== '') $user['ph'] = [hash('sha256', $ph)];
    $em = mb_strtolower(trim((string) ($contact['email'] ?? '')));
    if ($em !== '') $user['em'] = [hash('sha256', $em)];
    return [
        'event_name'    => (string) $row['event_name'],
        'event_time'    => (int) $row['event_time'],
        'action_source' => 'system_generated',
        'user_data'     => $user,
        'custom_data'   => ['lead_event_source' => function_exists('brand_name') ? brand_name() : 'CRM', 'event_source' => 'crm'],
    ];
}

/**
 * Send one account's events. Batched (Meta takes up to 1,000 per request; we send 100), and a
 * whole batch is marked by what Meta answered, so a bad token shows on every event it held.
 *
 * @return array{sent:int, failed:int, error:string}
 */
function crm_capi_send(array $client, array $rows, ?string $testCode = null): array
{
    $s = crm_settings((int) $client['id']);
    $dataset = trim((string) ($s['capi_dataset'] ?? ''));
    $token = !empty($s['capi_token_enc']) ? decrypt_secret((string) $s['capi_token_enc']) : '';
    $out = ['sent' => 0, 'failed' => 0, 'error' => ''];
    if ($dataset === '' || $token === '') {
        $out['error'] = 'Add the dataset ID and access token first.';
        foreach ($rows as $r) if (!empty($r['id'])) db_run("UPDATE crm_capi_events SET status='failed', error=? WHERE id=?", [$out['error'], (int) $r['id']]);
        $out['failed'] = count($rows);
        return $out;
    }
    $data = []; $ids = [];
    foreach ($rows as $r) {
        $c = db_row("SELECT * FROM contacts WHERE id=?", [(int) $r['contact_id']]);
        $lead = $c ? crm_capi_lead_id((int) $c['id']) : null;
        if (!$c || !$lead) {
            if (!empty($r['id'])) db_run("UPDATE crm_capi_events SET status='skipped', error='Not a Meta form lead.' WHERE id=?", [(int) $r['id']]);
            continue;
        }
        $data[] = crm_capi_payload($r, $c, $lead);
        if (!empty($r['id'])) $ids[] = (int) $r['id'];
    }
    if (!$data) return $out;
    $params = ['data' => json_encode($data), 'access_token' => $token];
    $code = $testCode ?? trim((string) ($s['capi_test_code'] ?? ''));
    if ($code !== '') $params['test_event_code'] = $code;
    $r = meta_http('POST', rawurlencode($dataset) . '/events', $params);
    $ok = $r['ok'] && (int) ($r['json']['events_received'] ?? 0) > 0;
    $err = $ok ? '' : mb_substr((string) ($r['error'] ?: 'Meta did not accept the events.'), 0, 255);
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        db_run("UPDATE crm_capi_events SET status=?, error=?, sent_at=IF(?='sent',NOW(),sent_at) WHERE id IN ($ph)",
               array_merge([$ok ? 'sent' : 'failed', $ok ? null : $err, $ok ? 'sent' : 'failed'], $ids));
    }
    $out[$ok ? 'sent' : 'failed'] = count($data);
    $out['error'] = $err;
    return $out;
}

/** The worker's pass: every account's waiting events, a batch at a time. */
function crm_capi_tick(): int
{
    if (!db_has_column('crm_settings', 'capi_on')) return 0;
    $sent = 0;
    foreach (db_all("SELECT DISTINCT e.client_id FROM crm_capi_events e WHERE e.status='queued' LIMIT 50") as $a) {
        $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $a['client_id']]);
        if (!$client) continue;
        $rows = db_all("SELECT * FROM crm_capi_events WHERE client_id=? AND status='queued' ORDER BY id LIMIT 100", [(int) $a['client_id']]);
        $sent += crm_capi_send($client, $rows)['sent'];
    }
    return $sent;
}
