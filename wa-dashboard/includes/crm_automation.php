<?php
declare(strict_types=1);

/**
 * WhatsApp the CRM sends by itself.
 *
 *   stage messages  a lead reaches "Viewing" → the location and a visit confirmation go out
 *   sequences       a lead stops answering → "we tried to reach you", then an offer 3 days later
 *   visits          confirmations and reminders (crm_visits.php queues them here too)
 *
 * Everything goes through one queue (crm_msg_queue), sent by the background worker. Right before
 * each send the queue checks: still opted in? inside working hours? for a sequence — did they
 * reply, or was the lead closed? So a message decided on Monday is still right when it leaves on
 * Tuesday, and a lead who wrote back is never chased.
 */

require_once __DIR__ . '/inbox.php';          // inbox_send_template(): credits, channel, logging
require_once __DIR__ . '/crm_fields.php';     // custom fields, money format — for the template variables

/**
 * What can fill a template's {{n}}, in words an admin picks from: the lead, the salesperson, the
 * visit or event, the company — and every custom field of the account (cf:<key>).
 */
function crm_tpl_tokens(int $clientId = 0): array
{
    $t = [
        'name'         => 'Lead: name',
        'first_name'   => 'Lead: first name',
        'phone'        => 'Lead: phone',
        'email'        => 'Lead: email',
        'code'         => 'Lead: lead code',
        'project'      => 'Lead: project',
        'unit_type'    => 'Lead: unit type',
        'budget'       => 'Lead: budget',
        'payment'      => 'Lead: payment (cash / instalments)',
        'deal_value'   => 'Lead: deal value',
        'stage'        => 'Lead: stage',
        'substatus'    => 'Lead: sub-status',
        'source'       => 'Lead: source',
        'campaign'     => 'Lead: campaign',
        'platform'     => 'Lead: platform',
        'ad_name'      => 'Lead: ad name',
        'created_date' => 'Lead: date added',
        'followup_date' => 'Lead: next follow-up date',
        'followup_time' => 'Lead: next follow-up time',
        'owner_name'   => 'Salesperson: name',
        'owner_first_name' => 'Salesperson: first name',
        'owner_phone'  => 'Salesperson: phone',
        'owner_email'  => 'Salesperson: email',
        'visit_date'   => 'Visit: date',
        'visit_time'   => 'Visit: time',
        'visit_place'  => 'Visit: place',
        'meeting_link' => 'Visit: online meeting link',
        'event_name'   => 'Event: name',
        'event_date'   => 'Event: date',
        'event_time'   => 'Event: time',
        'event_place'  => 'Event: place or link',
        'company'      => 'Company name',
        'today'        => 'Today\'s date',
    ];
    $clientId = $clientId ?: (int) ($GLOBALS['CLIENT']['id'] ?? 0);
    if ($clientId && function_exists('crm_fields')) {
        foreach (crm_fields($clientId) as $f) $t['cf:' . $f['fkey']] = 'Custom field: ' . $f['label'];
    }
    return $t + ['text' => 'Fixed text…'];
}

/**
 * The value for one {{n}}. A token is 'name', 'cf:nationality', or 'text:Our office' for fixed
 * text. A value that comes out empty becomes a dash rather than failing the send — Meta refuses a
 * template with an empty parameter, and "Hello -" is better than no message at all.
 */
function crm_tpl_value(string $token, array $c, array $ctx = []): string
{
    if (str_starts_with($token, 'text:')) return trim(substr($token, 5)) ?: '-';
    static $owners = [], $projects = [];
    $v = '';
    $uid = (int) ($c['owner_user_id'] ?? 0);
    if ($uid && str_starts_with($token, 'owner_')) $owners[$uid] ??= db_row("SELECT name, email, phone FROM users WHERE id=?", [$uid]) ?: [];
    $o = $owners[$uid] ?? [];
    $date = fn($x) => $x ? date('l j F', strtotime((string) $x)) : '';
    if (str_starts_with($token, 'cf:')) {
        $key = substr($token, 3);
        $raw = (function_exists('crm_custom_get') ? crm_custom_get($c) : [])[$key] ?? '';
        $f = null;
        foreach (function_exists('crm_fields') ? crm_fields((int) ($c['client_id'] ?? 0), false) : [] as $ff) if ($ff['fkey'] === $key) $f = $ff;
        $v = $f ? crm_custom_show($f, $raw) : (string) $raw;
        $v = trim($v);
        return $v !== '' ? $v : '-';
    }
    switch ($token) {
        case 'name':       $v = (string) ($c['name'] ?? ''); break;
        case 'first_name': $v = (string) (preg_split('/\s+/u', trim((string) ($c['name'] ?? ''))) ?: [''])[0]; break;
        case 'phone':      $v = '+' . $c['phone_e164']; break;
        case 'email':      $v = (string) ($c['email'] ?? ''); break;
        case 'code':       $v = (string) ($c['code'] ?? ''); break;
        case 'unit_type':  $v = (string) ($c['unit_type'] ?? ''); break;
        case 'budget':     $v = (string) ($c['budget'] ?? ''); break;
        case 'payment':    $v = (string) ($c['payment_pref'] ?? ''); break;
        case 'deal_value': $v = (float) ($c['deal_value'] ?? 0) > 0 && function_exists('crm_money_fmt') ? crm_money_fmt($c['deal_value'], (int) $c['client_id']) : ''; break;
        case 'substatus':  $v = (string) ($c['substatus'] ?? ''); break;
        case 'source':     $v = (string) ($c['source'] ?? ''); break;
        case 'campaign':   $v = (string) ($c['campaign'] ?? ''); break;
        case 'platform':   $v = (string) ($c['platform'] ?? ''); break;
        case 'ad_name':    $v = (string) ($c['ad_name'] ?? ''); break;
        case 'created_date': $v = $date($c['crm_added_at'] ?? $c['created_at'] ?? ''); break;
        case 'followup_date': $v = $date($c['next_followup_at'] ?? ''); break;
        case 'followup_time': $v = !empty($c['next_followup_at']) ? date('g:i A', strtotime((string) $c['next_followup_at'])) : ''; break;
        case 'today':      $v = date('l j F'); break;
        case 'project':
            $pid = (int) ($ctx['project_id'] ?? $c['project_id'] ?? 0);
            if ($pid) $v = $projects[$pid] ??= (string) db_val("SELECT name FROM crm_projects WHERE id=?", [$pid]);
            break;
        case 'owner_name':       $v = (string) ($o['name'] ?? ''); break;
        case 'owner_first_name': $v = (string) (preg_split('/\s+/u', trim((string) ($o['name'] ?? ''))) ?: [''])[0]; break;
        case 'owner_phone':      $v = !empty($o['phone']) ? '+' . $o['phone'] : ''; break;
        case 'owner_email':      $v = (string) ($o['email'] ?? ''); break;
        case 'stage':
            if (!empty($c['stage_id'])) $v = (string) db_val("SELECT name FROM crm_stages WHERE id=?", [(int) $c['stage_id']]);
            break;
        case 'visit_date':  $v = $date($ctx['starts_at'] ?? ''); break;
        case 'visit_time':  $v = !empty($ctx['starts_at']) ? date('g:i A', strtotime((string) $ctx['starts_at'])) : ''; break;
        case 'visit_place': $v = (string) ($ctx['place'] ?? ''); break;
        case 'meeting_link': $v = (string) ($ctx['meet_url'] ?? ''); break;
        case 'event_name':  $v = (string) ($ctx['event_name'] ?? ''); break;
        case 'event_date':  $v = $date($ctx['event_at'] ?? ''); break;
        case 'event_time':  $v = !empty($ctx['event_at']) ? date('g:i A', strtotime((string) $ctx['event_at'])) : ''; break;
        case 'event_place': $v = (string) ($ctx['event_place'] ?? ''); break;
        case 'company':     $v = (string) db_val("SELECT name FROM clients WHERE id=?", [(int) $c['client_id']]); break;
    }
    $v = trim($v);
    return $v !== '' ? $v : '-';
}

/** A template's fields, for the pickers: how many {{n}} in the body, and whether it needs a picture. */
function crm_tpl_shape(array $tpl): array
{
    $spec = wa_template_spec(json_decode((string) $tpl['components'], true) ?: []);
    return ['body' => (int) $spec['body_vars'], 'header_vars' => (int) ($spec['header']['text_vars'] ?? 0),
            'media' => in_array(strtoupper((string) ($spec['header']['format'] ?? '')), ['IMAGE', 'VIDEO', 'DOCUMENT'], true)
                       ? strtolower((string) $spec['header']['format']) : ''];
}

/** Approved templates, with their shape, for the setup pages. */
function crm_tpl_choices(int $clientId): array
{
    $out = [];
    foreach (db_all("SELECT * FROM templates WHERE client_id=? AND LOWER(status)='approved' ORDER BY wa_name", [$clientId]) as $t) {
        $out[(int) $t['id']] = ['id' => (int) $t['id'], 'name' => $t['wa_name'], 'lang' => $t['language'],
                                'category' => strtolower((string) $t['category']), 'text' => (string) $t['body_text']] + crm_tpl_shape($t);
    }
    return $out;
}

/** Tokens from a posted form (vars[1]=name, vars_text[3]=Our office…), in order. */
function crm_tokens_from_post(string $prefix, array $post, ?array $allowed = null): array
{
    $allowed ??= crm_tpl_tokens();
    $vars = (array) ($post[$prefix] ?? []); $txt = (array) ($post[$prefix . '_text'] ?? []);
    ksort($vars);
    $out = [];
    foreach ($vars as $i => $tok) {
        $tok = (string) $tok;
        if ($tok === 'text') $tok = 'text:' . mb_substr(trim((string) ($txt[$i] ?? '')), 0, 200);
        elseif (!isset($allowed[$tok]) && !preg_match('/^cf:[a-z0-9_]{1,40}$/', $tok)) $tok = 'name';
        $out[] = $tok;
    }
    return $out;
}

/* ───────────────────────── the queue ───────────────────────── */

/** Automatic messages go from the Business API number only. Is it connected? */
function crm_auto_api_ready(array $client): bool
{
    return wa_phone_id($client) !== '' && wa_token($client) !== '';
}

function crm_queue(array $client, int $contactId, int $templateId, array $tokens, string $headerMedia, string $reason,
                   ?string $dueAt = null, array $context = [], string $sendBy = 'whatsapp', ?string $smsText = null): int
{
    // "Send by": whatsapp (the template), sms (its own text), or wa_then_sms (the template, and the text by SMS if WhatsApp fails).
    if (!db_has_column('crm_msg_queue', 'send_by')) $sendBy = 'whatsapp';
    if ($sendBy === 'whatsapp' || !db_has_column('crm_msg_queue', 'send_by')) {
        return db_insert("INSERT INTO crm_msg_queue (client_id,contact_id,template_id,vars,header_media,context,reason,due_at,created_at)
                          VALUES (?,?,?,?,?,?,?,?,NOW())",
            [(int) $client['id'], $contactId, $templateId, json_encode(array_values($tokens), JSON_UNESCAPED_UNICODE),
             $headerMedia !== '' ? $headerMedia : null, $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
             mb_substr($reason, 0, 40), $dueAt ?? date('Y-m-d H:i:s')]);
    }
    return db_insert("INSERT INTO crm_msg_queue (client_id,contact_id,template_id,send_by,sms_text,vars,header_media,context,reason,due_at,created_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,NOW())",
        [(int) $client['id'], $contactId, $templateId ?: null, $sendBy, $smsText, json_encode(array_values($tokens), JSON_UNESCAPED_UNICODE),
         $headerMedia !== '' ? $headerMedia : null, $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
         mb_substr($reason, 0, 40), $dueAt ?? date('Y-m-d H:i:s')]);
}

/** Send a queued message's SMS text to the lead. Returns ['ok', 'error', 'id']. */
function crm_queue_sms(array $client, array $c, array $q, array $ctx): array
{
    if (!function_exists('crm_sms_available') || !crm_sms_available($client)) return ['ok' => false, 'error' => 'SMS is not available on this account.', 'id' => null];
    if (empty($c['phone_e164'])) return ['ok' => false, 'error' => 'The lead has no phone number.', 'id' => null];
    require_once __DIR__ . '/sms.php';
    $text = sms_render((string) $q['sms_text'], $c, $ctx);
    return sms_send_now($client, (string) $c['phone_e164'], $text, ['source' => 'crm', 'render' => false, 'ctx' => $ctx], (int) $c['id']);
}

/** The next moment inside working hours, if the account keeps them. */
function crm_next_working_time(array $s, int $t): int
{
    if ($s['work_start'] === null || $s['work_end'] === null) return $t;
    $h = (int) date('G', $t);
    if ($h >= (int) $s['work_start'] && $h < (int) $s['work_end']) return $t;
    $day = $h >= (int) $s['work_end'] ? strtotime('+1 day', $t) : $t;
    return strtotime(date('Y-m-d', $day) . sprintf(' %02d:00:00', (int) $s['work_start']));
}

/** Send what is due. Each row is claimed before sending, so two workers can never both send it. */
function crm_queue_tick(int $limit = 50): int
{
    try {
        $rows = db_all("SELECT * FROM crm_msg_queue WHERE status='queued' AND due_at <= NOW() ORDER BY due_at, id LIMIT " . (int) $limit);
    } catch (Throwable $e) { return 0; }
    $sent = 0; $clients = [];
    foreach ($rows as $q) {
        if (!db_run("UPDATE crm_msg_queue SET status='sending' WHERE id=? AND status='queued'", [(int) $q['id']])) continue;
        $cid = (int) $q['client_id'];
        $clients[$cid] ??= db_row("SELECT * FROM clients WHERE id=?", [$cid]);
        $client = $clients[$cid];
        $done = function (string $status, string $err = '', ?int $msgId = null) use ($q) {
            db_run("UPDATE crm_msg_queue SET status=?, error=?, message_id=?, sent_at=IF(?='sent',NOW(),sent_at) WHERE id=?",
                   [$status, $err !== '' ? mb_substr($err, 0, 255) : null, $msgId, $status, (int) $q['id']]);
        };
        $c = db_row("SELECT * FROM contacts WHERE id=?", [(int) $q['contact_id']]);
        if (!$client || ($client['status'] ?? '') !== 'active') { $done('skipped', 'The account is not active.'); continue; }
        if (!$c) { $done('skipped', 'The lead no longer exists.'); continue; }
        if (($c['opt_in_status'] ?? '') === 'out') { $done('skipped', 'They opted out of messages.'); continue; }

        // Outside working hours: wait for the morning rather than message someone at midnight.
        $s = crm_settings($cid);
        $next = crm_next_working_time($s, time());
        if ($next > time() + 60) {
            db_run("UPDATE crm_msg_queue SET status='queued', due_at=? WHERE id=?", [date('Y-m-d H:i:s', $next), (int) $q['id']]);
            continue;
        }
        // A sequence step whose run was stopped (they replied, the lead closed) is dropped.
        if (preg_match('/^seq:(\d+):/', (string) $q['reason'], $m)) {
            $st = db_val("SELECT status FROM crm_seq_runs WHERE sequence_id=? AND contact_id=?", [(int) $m[1], (int) $c['id']]);
            if ($st === 'stopped') { $done('cancelled', 'The sequence was stopped.'); continue; }
        }

        $ctx = json_decode((string) ($q['context'] ?? ''), true) ?: [];
        $by = (string) ($q['send_by'] ?? 'whatsapp');
        if ($by === 'sms') {
            if (!empty($c['sms_opt_out_at'])) { $done('skipped', 'They opted out of SMS.'); continue; }
            $r = crm_queue_sms($client, $c, $q, $ctx);
            $done($r['ok'] ? 'sent' : 'failed', $r['ok'] ? '' : 'SMS: ' . $r['error']);
            if ($r['ok']) $sent++;
            continue;
        }
        // "WhatsApp, SMS if it fails" — used wherever WhatsApp cannot go out.
        $fallback = function (string $why) use ($by, $q, $c, $client, $ctx, $done, &$sent): bool {
            if ($by !== 'wa_then_sms' || trim((string) ($q['sms_text'] ?? '')) === '' || !empty($c['sms_opt_out_at'])) return false;
            $s2 = crm_queue_sms($client, $c, $q, $ctx);
            if (!$s2['ok']) { $done('failed', mb_substr($why, 0, 120) . ' · SMS: ' . $s2['error']); return true; }
            $done('sent', 'WhatsApp could not go (' . mb_substr($why, 0, 120) . '); sent by SMS.'); $sent++;
            return true;
        };
        $tpl = db_row("SELECT * FROM templates WHERE id=? AND client_id=?", [(int) $q['template_id'], $cid]);
        if (!$tpl) { if (!$fallback('The template was deleted.')) $done('failed', 'The template was deleted.'); continue; }
        $shape = crm_tpl_shape($tpl);
        $tokens = json_decode((string) $q['vars'], true) ?: [];
        $vals = [];
        for ($i = 0; $i < $shape['body']; $i++) $vals[] = crm_tpl_value((string) ($tokens[$i] ?? 'name'), $c, $ctx);
        $hvals = [];
        for ($i = 0; $i < $shape['header_vars']; $i++) $hvals[] = crm_tpl_value((string) ($tokens[$shape['body'] + $i] ?? 'name'), $c, $ctx);

        /* Always from the WhatsApp Business API number — a managerial message on the company's
           official number, never from a salesperson's phone or the linked company phone, whatever
           the account's usual channel is. */
        if (!crm_auto_api_ready($client)) {
            $why = 'The WhatsApp Business API number is not connected (Settings → WhatsApp API Credentials).';
            if (!$fallback($why)) $done('failed', $why);
            continue;
        }
        $r = inbox_send_template(array_merge($client, ['channel' => 'cloud']), (int) $c['id'], (int) $tpl['id'], $vals, $hvals, (string) ($q['header_media'] ?? ''),
                                 ['source' => 'crm_auto', 'takeover' => false]);
        // "WhatsApp, SMS if it fails": the WhatsApp attempt failed for good, so the SMS text goes instead.
        if (empty($r['ok']) && $fallback((string) ($r['error'] ?? 'WhatsApp failed'))) continue;
        $done(!empty($r['ok']) ? 'sent' : 'failed', !empty($r['ok']) ? '' : (string) ($r['error'] ?? 'Not sent.'), isset($r['id']) ? (int) $r['id'] : null);
        if (!empty($r['ok'])) $sent++;
    }
    return $sent;
}

/** Start automations waiting for a CRM event, loading the engine only when some flow waits for it. */
function social_flow_event(array $client, int $contactId, string $kind, array $data): void
{
    try {
        if (!db_has_column('flow_runs', 'channel') || !db_val("SELECT 1 FROM flow_triggers WHERE client_id=? AND kind=? AND active=1 LIMIT 1", [(int) $client['id'], $kind])) return;
        require_once __DIR__ . '/automation.php';
        automation_handle_crm_event($client, $contactId, $kind, $data);
    } catch (Throwable $e) { error_log('flow event ' . $kind . ': ' . $e->getMessage()); }
}

/* ───────────────────────── stage messages ───────────────────────── */

/**
 * A lead reached a stage. Queue that stage's message, start any sequence that begins here, and
 * stop sequences a closed lead should no longer be in.
 *
 * @param bool $arrival true when the lead was just added straight into this stage
 */
function crm_auto_on_stage(array $client, int $contactId, int $stageId, bool $arrival = false): void
{
    if (!db_has_column('crm_msg_queue', 'context')) return;              // migration 041 not applied yet
    $cid = (int) $client['id'];
    if (function_exists('crm_capi_on_stage')) crm_capi_on_stage($client, $contactId, $stageId);   // tell Meta, for form leads
    if (function_exists('crm_staff_stage_alert')) crm_staff_stage_alert($cid, $contactId, $stageId);   // tell the salesperson, when chosen
    if (function_exists('social_flow_event')) social_flow_event($client, $contactId, 'crm_stage', ['stage_id' => $stageId]);   // automations waiting for this stage
    try {
        $m = db_row("SELECT * FROM crm_stage_msgs WHERE client_id=? AND stage_id=? AND active=1", [$cid, $stageId]);
        if ($m && (!$arrival || (int) $m['on_arrival'])) {
            crm_queue($client, $contactId, (int) $m['template_id'], json_decode((string) $m['vars'], true) ?: [],
                      (string) ($m['header_media'] ?? ''), 'stage:' . $stageId,
                      date('Y-m-d H:i:s', time() + 60 * (int) $m['delay_minutes']), [], (string) ($m['send_by'] ?? 'whatsapp'), $m['sms_text'] ?? null);
        }
        $kind = crm_stage_kind($cid, $stageId);
        if ($kind === 'won' || $kind === 'lost') crm_seq_stop_all($contactId, 'The lead was ' . ($kind === 'won' ? 'won' : 'lost') . '.');
        // Leaving a stage ends the sequences that were about that stage.
        foreach (db_all("SELECT r.sequence_id FROM crm_seq_runs r JOIN crm_sequences s ON s.id=r.sequence_id
                          WHERE r.contact_id=? AND r.status='active' AND s.trigger_kind='stage' AND s.trigger_n<>?", [$contactId, $stageId]) as $r) {
            crm_seq_stop((int) $r['sequence_id'], $contactId, 'It moved to another stage.');
        }
        foreach (db_all("SELECT * FROM crm_sequences WHERE client_id=? AND active=1 AND trigger_kind='stage' AND trigger_n=?", [$cid, $stageId]) as $seq) {
            crm_seq_enroll($client, $seq, $contactId);
        }
        if ($arrival) {
            foreach (db_all("SELECT * FROM crm_sequences WHERE client_id=? AND active=1 AND trigger_kind='new_lead'", [$cid]) as $seq) {
                crm_seq_enroll($client, $seq, $contactId);
            }
        }
    } catch (Throwable $e) {
        error_log('crm_auto_on_stage: ' . $e->getMessage());
    }
}

/* ───────────────────────── sequences ───────────────────────── */

function crm_seq_triggers(): array
{
    return ['no_answer' => 'After this many "No answer" calls', 'stale' => 'After this many days with no activity',
            'stage' => 'When a lead reaches the stage', 'new_lead' => 'When a new lead arrives'];
}

/** Put a lead into a sequence. Once only: a lead who finished or was stopped does not start again. */
function crm_seq_enroll(array $client, array $seq, int $contactId): bool
{
    $first = db_row("SELECT * FROM crm_seq_steps WHERE sequence_id=? ORDER BY sort, id LIMIT 1", [(int) $seq['id']]);
    if (!$first) return false;
    $c = db_row("SELECT opt_in_status, stage_id FROM contacts WHERE id=?", [$contactId]);
    if (!$c || ($c['opt_in_status'] ?? '') === 'out') return false;
    if (in_array(crm_stage_kind((int) $client['id'], $c['stage_id'] !== null ? (int) $c['stage_id'] : null), ['won', 'lost'], true)) return false;
    $n = db_run("INSERT IGNORE INTO crm_seq_runs (client_id,sequence_id,contact_id,step_idx,next_at,status,started_at)
                 VALUES (?,?,?,0,?, 'active', NOW())",
                [(int) $client['id'], (int) $seq['id'], $contactId, date('Y-m-d H:i:s', time() + 3600 * (int) $first['delay_hours'])]);
    if ($n) crm_log((int) $client['id'], $contactId, 'seq_start', null, (string) $seq['id'], null);
    return $n > 0;
}

function crm_seq_stop(int $sequenceId, int $contactId, string $reason): void
{
    if (db_run("UPDATE crm_seq_runs SET status='stopped', stop_reason=?, next_at=NULL WHERE sequence_id=? AND contact_id=? AND status='active'",
               [mb_substr($reason, 0, 60), $sequenceId, $contactId])) {
        db_run("UPDATE crm_msg_queue SET status='cancelled', error=? WHERE contact_id=? AND reason LIKE ? AND status='queued'",
               [mb_substr($reason, 0, 255), $contactId, 'seq:' . $sequenceId . ':%']);
        $cid = (int) db_val("SELECT client_id FROM contacts WHERE id=?", [$contactId]);
        if ($cid) crm_log($cid, $contactId, 'seq_stop', (string) $sequenceId, mb_substr($reason, 0, 64), null);
    }
}

function crm_seq_stop_all(int $contactId, string $reason): void
{
    try {
        foreach (db_all("SELECT sequence_id FROM crm_seq_runs WHERE contact_id=? AND status='active'", [$contactId]) as $r) {
            crm_seq_stop((int) $r['sequence_id'], $contactId, $reason);
        }
    } catch (Throwable $e) {}
}

/** A call was logged. Enough "No answer"s start the sequences waiting for exactly that. */
function crm_auto_on_activity(array $client, int $contactId, ?string $outcome): void
{
    if ($outcome !== 'no_answer' || !db_has_column('crm_msg_queue', 'context')) return;
    try {
        $n = (int) db_val("SELECT COUNT(*) FROM crm_notes WHERE contact_id=? AND outcome='no_answer'", [$contactId]);
        foreach (db_all("SELECT * FROM crm_sequences WHERE client_id=? AND active=1 AND trigger_kind='no_answer' AND trigger_n<=?",
                        [(int) $client['id'], $n]) as $seq) {
            crm_seq_enroll($client, $seq, $contactId);
        }
    } catch (Throwable $e) { error_log('crm_auto_on_activity: ' . $e->getMessage()); }
}

/**
 * The worker's pass over sequences: stop the ones whose lead replied, start the "quiet for N days"
 * ones, and queue each step as it comes due.
 */
function crm_seq_tick(): int
{
    if (!db_has_column('crm_msg_queue', 'context')) return 0;
    // They wrote back since joining: a person should answer, not the next step.
    foreach (db_all("SELECT r.sequence_id, r.contact_id FROM crm_seq_runs r
                      WHERE r.status='active' AND EXISTS (SELECT 1 FROM messages m WHERE m.contact_id=r.contact_id
                            AND m.direction='in' AND m.created_at > r.started_at) LIMIT 500") as $r) {
        crm_seq_stop((int) $r['sequence_id'], (int) $r['contact_id'], 'They replied.');
    }
    // Quiet for N days.
    foreach (db_all("SELECT q.*, c.name AS client_name FROM crm_sequences q JOIN clients c ON c.id=q.client_id
                      WHERE q.active=1 AND q.trigger_kind='stale' AND q.trigger_n > 0 AND c.status='active'") as $seq) {
        $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $seq['client_id']]);
        foreach (db_all("SELECT c.id FROM contacts c JOIN crm_stages s ON s.id=c.stage_id
                          WHERE c.client_id=? AND s.kind='open' AND c.opt_in_status<>'out'
                            AND COALESCE(c.last_touch_at, c.crm_added_at, c.created_at) < NOW() - INTERVAL ? DAY
                            AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.client_id=c.client_id AND m.contact_id=c.id AND m.direction='in' AND m.created_at > NOW() - INTERVAL ? DAY)
                            AND NOT EXISTS (SELECT 1 FROM crm_seq_runs r WHERE r.sequence_id=? AND r.contact_id=c.id)
                          LIMIT 200", [(int) $seq['client_id'], (int) $seq['trigger_n'], (int) $seq['trigger_n'], (int) $seq['id']]) as $c) {
            crm_seq_enroll($client, $seq, (int) $c['id']);
        }
    }
    // Steps that are due go to the queue; the run moves on to the next one.
    $queued = 0;
    foreach (db_all("SELECT r.*, q.active AS seq_active FROM crm_seq_runs r JOIN crm_sequences q ON q.id=r.sequence_id
                      WHERE r.status='active' AND r.next_at <= NOW() ORDER BY r.next_at LIMIT 300") as $r) {
        if (!(int) $r['seq_active']) continue;                              // paused sequence: runs wait
        $steps = db_all("SELECT * FROM crm_seq_steps WHERE sequence_id=? ORDER BY sort, id", [(int) $r['sequence_id']]);
        $step = $steps[(int) $r['step_idx']] ?? null;
        if (!$step) { db_run("UPDATE crm_seq_runs SET status='done', next_at=NULL WHERE id=?", [(int) $r['id']]); continue; }
        $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $r['client_id']]);
        crm_queue($client, (int) $r['contact_id'], (int) $step['template_id'], json_decode((string) $step['vars'], true) ?: [],
                  (string) ($step['header_media'] ?? ''), 'seq:' . $r['sequence_id'] . ':' . ((int) $r['step_idx'] + 1), null, [],
                  (string) ($step['send_by'] ?? 'whatsapp'), $step['sms_text'] ?? null);
        $queued++;
        $nextStep = $steps[(int) $r['step_idx'] + 1] ?? null;
        db_run("UPDATE crm_seq_runs SET step_idx=step_idx+1, next_at=?, status=? WHERE id=?",
               [$nextStep ? date('Y-m-d H:i:s', time() + 3600 * (int) $nextStep['delay_hours']) : null, $nextStep ? 'active' : 'done', (int) $r['id']]);
    }
    return $queued;
}
