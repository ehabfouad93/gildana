<?php
declare(strict_types=1);

/**
 * What a notice says, and getting it to the person.
 *
 * crm_notice() (crm_manager.php) records that something happened for someone: a lead given to
 * them, a follow-up due, a lead waiting too long. This file turns those records into words:
 *
 *   in the app   the bell lists them, newest first, until read — names and all, since the
 *                person is signed in and the lead is theirs
 *   WhatsApp     the same words to the salesperson's own number, when the account turns that on:
 *                through the company's linked phone as plain text, or through the Business API
 *                with an approved template (the API cannot open a chat with free text)
 *
 * The phone push (push_status.php) stays counts-only; it goes through a third party.
 */

require_once __DIR__ . '/channel.php';   // WhatsApp sends, company phone or Business API

/** Kinds that can go to WhatsApp, in words an admin recognises. */
function crm_staff_wa_kinds(): array
{
    return ['assigned' => 'A new lead is given to them', 'sla' => 'A lead of theirs is waiting too long',
            'followup' => 'A follow-up is due', 'reclaimed' => 'A lead is taken from them',
            'visit' => 'A site visit is coming up', 'stage' => 'A lead of theirs reaches a stage chosen below',
            'daily' => 'The daily report, every evening (managers)'];
}

/**
 * The words for one notice, and where tapping it goes.
 *
 * @return array{text:string, url:string, when:string}
 */
function crm_notice_text(array $n): array
{
    $lead = null;
    if (!empty($n['contact_id'])) {
        $lead = db_row("SELECT id, name, phone_e164, project_id FROM contacts WHERE id=?", [(int) $n['contact_id']]);
    }
    $who  = $lead ? ((string) ($lead['name'] ?: '+' . $lead['phone_e164'])) : 'A lead';
    $proj = '';
    if ($lead && !empty($lead['project_id'])) {
        $proj = (string) db_val("SELECT name FROM crm_projects WHERE id=?", [(int) $lead['project_id']]);
    }
    $d = json_decode((string) ($n['data'] ?? ''), true) ?: [];
    $url = $lead ? 'crm_lead.php?id=' . (int) $lead['id'] : 'crm.php';
    $text = match ((string) $n['kind']) {
        'assigned'  => 'New lead for you: ' . $who . ($proj !== '' ? ' — ' . $proj : ''),
        'followup'  => 'Follow-up due now: ' . $who,
        'sla'       => $who . ' is waiting — not contacted yet',
        'sla_team'  => (int) ($d['n'] ?? 1) . ' lead' . ((int) ($d['n'] ?? 1) === 1 ? ' was' : 's were') . ' not contacted in time',
        'reclaimed' => $who . ' was passed to a colleague — not contacted in time',
        'resubmit'  => $who . ' came in again — a good moment to call',
        'stale'     => (int) ($d['n'] ?? 1) . ' lead' . ((int) ($d['n'] ?? 1) === 1 ? ' has' : 's have') . ' had no activity for ' . (int) ($d['days'] ?? 0) . ' days',
        'digest'    => crm_digest_words($d),
        'daily'     => (string) ($d['text'] ?? 'The daily report is ready'),
        'request'   => ($d['by'] ?? 'Someone') . ' asks to ' . (($d['what'] ?? '') === 'create' ? 'add a new lead' . (!empty($d['name']) ? ': ' . $d['name'] : '') : 'delete ' . $who),
        'request_done' => 'A manager ' . (!empty($d['ok']) ? 'approved' : 'refused') . ' your request to ' . (($d['what'] ?? '') === 'create' ? 'add ' . ($d['name'] ?? 'a lead') : 'delete ' . $who)
                          . (!empty($d['answer']) ? ' — “' . $d['answer'] . '”' : ''),
        'stage'     => $who . ' moved to ' . ((string) db_val("SELECT name FROM crm_stages WHERE id=?", [(int) ($d['stage'] ?? 0)]) ?: 'a new stage')
                       . ($proj !== '' ? ' — ' . $proj : ''),
        'visit'     => 'Site visit ' . (!empty($d['at']) ? date('D j M, H:i', strtotime((string) $d['at'])) : 'soon') . ': ' . $who . ($proj !== '' ? ' — ' . $proj : ''),
        default     => 'Update on ' . $who,
    };
    if ($n['kind'] === 'sla_team') $url = 'crm_team.php';
    if ($n['kind'] === 'request') $url = 'crm_manage.php#requests';
    if ($n['kind'] === 'daily') $url = 'crm_reports.php?r=daily&period=custom&from=' . rawurlencode((string) ($d['day'] ?? '')) . '&to=' . rawurlencode((string) ($d['day'] ?? ''));
    if (in_array($n['kind'], ['stale', 'digest'], true)) $url = 'crm.php';
    if ($n['kind'] === 'visit' && !empty($d['visit'])) $url = 'crm_calendar.php?visit=' . (int) $d['visit'];
    return ['text' => $text, 'url' => $url, 'when' => (string) $n['created_at']];
}

function crm_digest_words(array $d): string
{
    $parts = [];
    if (!empty($d['due_today'])) $parts[] = $d['due_today'] . ' follow-up' . ($d['due_today'] == 1 ? '' : 's') . ' today';
    if (!empty($d['overdue']))   $parts[] = $d['overdue'] . ' overdue';
    if (!empty($d['new']))       $parts[] = $d['new'] . ' new lead' . ($d['new'] == 1 ? '' : 's');
    if (!empty($d['late']))      $parts[] = $d['late'] . ' not contacted in time';
    if (!empty($d['team']) && !empty($d['unassigned'])) $parts[] = $d['unassigned'] . ' unassigned';
    return ($d['team'] ?? 0 ? 'Your team today: ' : 'Your day: ') . ($parts ? implode(', ', $parts) : 'nothing waiting');
}

/** This person's recent notices, for the bell. */
function crm_notices_for(int $userId, int $limit = 15): array
{
    try {
        $rows = db_all("SELECT * FROM crm_notices WHERE user_id=? ORDER BY id DESC LIMIT " . (int) $limit, [$userId]);
    } catch (Throwable $e) { return []; }
    return array_map(fn($n) => crm_notice_text($n) + ['id' => (int) $n['id'], 'read' => $n['read_at'] !== null, 'kind' => $n['kind']], $rows);
}

function crm_notices_unread(int $userId): int
{
    try { return (int) db_val("SELECT COUNT(*) FROM crm_notices WHERE user_id=? AND read_at IS NULL AND created_at > NOW() - INTERVAL 14 DAY", [$userId]); }
    catch (Throwable $e) { return 0; }
}

function crm_notices_mark_read(int $userId, ?int $id = null): void
{
    try {
        if ($id) db_run("UPDATE crm_notices SET read_at=NOW() WHERE id=? AND user_id=? AND read_at IS NULL", [$id, $userId]);
        else     db_run("UPDATE crm_notices SET read_at=NOW() WHERE user_id=? AND read_at IS NULL", [$userId]);
    } catch (Throwable $e) {}
}

/* ───────────────────────── WhatsApp to the salesperson ───────────────────────── */

/** What can fill an alert's {{n}} or its wording: the alert itself, the person it goes to, and everything about the lead. */
function crm_staff_tokens(int $clientId = 0): array
{
    $lead = function_exists('crm_tpl_tokens') ? crm_tpl_tokens($clientId) : ['name' => 'Lead: name', 'text' => 'Fixed text…'];
    $text = $lead['text'] ?? 'Fixed text…'; unset($lead['text']);
    return ['alert' => 'Alert: what happened', 'link' => 'Alert: link to open the lead', 'staff_name' => 'Alert: name of who gets it']
         + $lead + ['text' => $text];
}

/** Each alert's own settings: [kind => [template => id, vars => [tokens], text => wording]]. */
function crm_staff_custom(array $s): array
{
    return json_decode((string) ($s['staff_wa_custom'] ?? ''), true) ?: [];
}

/** The default wording when the company phone sends an alert, with its placeholders. */
function crm_staff_default_text(): string
{
    return "{{alert}}\n{{link}}";
}

/** The value of one alert token for this notice. */
function crm_staff_value(string $tok, array $n, array $t, string $url, string $staffName): string
{
    if ($tok === 'alert') return $t['text'];
    if ($tok === 'link') return $url;
    if ($tok === 'staff_name') return $staffName !== '' ? $staffName : '-';
    $c = !empty($n['contact_id']) ? (db_row("SELECT * FROM contacts WHERE id=?", [(int) $n['contact_id']]) ?: []) : [];
    if (!$c && !str_starts_with($tok, 'text:')) return '-';
    $d = json_decode((string) ($n['data'] ?? ''), true) ?: [];
    $ctx = [];
    if (!empty($d['visit'])) {
        $v = db_row("SELECT * FROM crm_visits WHERE id=?", [(int) $d['visit']]) ?: [];
        $ctx = ['starts_at' => $v['starts_at'] ?? ($d['at'] ?? null), 'place' => $v['place'] ?? '', 'meet_url' => $v['meet_url'] ?? '', 'project_id' => $v['project_id'] ?? null];
    }
    return function_exists('crm_tpl_value') ? crm_tpl_value($tok, $c ?: ['client_id' => $n['client_id']], $ctx) : '-';
}

/** Wording with {{token}} placeholders filled in. */
function crm_staff_render(string $text, array $n, array $t, string $url, string $staffName): string
{
    return (string) preg_replace_callback('/\{\{\s*([a-z0-9_:]+)\s*\}\}/i',
        fn($m) => crm_staff_value(strtolower($m[1]), $n, $t, $url, $staffName), $text);
}

/**
 * Send one alert to a salesperson's own WhatsApp.
 *
 * The company's linked phone sends plain text, like a colleague would — the alert's own wording
 * when one is set. Without one, the Business API needs an approved template: the alert's own
 * template and fields when set, else the account's alert template with {{1}} the alert, {{2}} the
 * link and any others a dash.
 *
 * @param array|null $notice the crm_notices row, so the alert's own wording and lead fields can be used
 * @return array{ok:bool, error:string, how:string}
 */
function crm_staff_send(array $client, string $phone, string $text, string $url, ?array $notice = null, string $staffName = ''): array
{
    $s = crm_settings((int) $client['id']);
    $kind = $notice ? ((string) $notice['kind'] === 'sla_team' ? 'sla' : (string) $notice['kind']) : '';
    $own = $kind !== '' ? (crm_staff_custom($s)[$kind] ?? []) : [];
    $t = ['text' => $text, 'url' => $url];
    if (($client['personal_status'] ?? '') === 'connected' && function_exists('pw_send_text')) {
        $body = $notice && trim((string) ($own['text'] ?? '')) !== '' ? crm_staff_render((string) $own['text'], $notice, $t, $url, $staffName) : $text . "\n" . $url;
        $r = pw_send_text(array_merge($client, ['channel' => 'personal']), $phone, $body);
        return ['ok' => !empty($r['ok']), 'error' => (string) ($r['error_title'] ?? ''), 'how' => 'company_phone'];
    }
    $tplId = (int) ($own['template'] ?? 0) ?: (int) ($s['staff_wa_template'] ?? 0);
    if (!$tplId || trim((string) ($client['phone_number_id'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'Choose an approved template for alerts, or link the company phone.', 'how' => 'none'];
    }
    $tpl = db_row("SELECT * FROM templates WHERE id=? AND client_id=?", [$tplId, (int) $client['id']]);
    if (!$tpl || strtolower((string) $tpl['status']) !== 'approved') {
        return ['ok' => false, 'error' => 'The alert template is missing or not approved.', 'how' => 'template'];
    }
    $spec = wa_template_spec(json_decode((string) $tpl['components'], true) ?: []);
    $tokens = !empty($own['template']) && (int) $own['template'] === $tplId ? (array) ($own['vars'] ?? []) : ['alert', 'link'];
    $vars = [];
    for ($i = 1; $i <= max(1, (int) ($spec['body_vars'] ?? 0)); $i++) {
        $tok = (string) ($tokens[$i - 1] ?? '');
        $val = $tok === '' ? '-' : ($notice ? crm_staff_value($tok, $notice, $t, $url, $staffName)
                                            : ($tok === 'alert' ? $text : ($tok === 'link' ? $url : (str_starts_with($tok, 'text:') ? substr($tok, 5) : '-'))));
        $vars[(string) $i] = ['source' => 'static', 'value' => $val !== '' ? $val : '-'];
    }
    $r = channel_send_template(array_merge($client, ['channel' => 'cloud']), $phone, $tpl, ['vars' => $vars, 'header_vars' => []],
                               ['phone_e164' => $phone, 'name' => '', 'attributes' => null]);
    return ['ok' => !empty($r['ok']), 'error' => (string) ($r['error_title'] ?? ''), 'how' => 'template'];
}

/**
 * A lead reached a stage: when the account sends the "reaches a stage" alert for this stage, tell
 * the lead's salesperson (in the bell, and on WhatsApp by the usual pass).
 */
function crm_staff_stage_alert(int $clientId, int $contactId, int $stageId): void
{
    $s = crm_settings($clientId);
    if (!in_array('stage', explode(',', (string) $s['staff_wa_kinds']), true)) return;
    if (!in_array((string) $stageId, explode(',', (string) ($s['staff_wa_stages'] ?? '')), true)) return;
    $owner = (int) db_val("SELECT owner_user_id FROM contacts WHERE id=? AND client_id=?", [$contactId, $clientId]);
    if ($owner && function_exists('crm_notice')) crm_notice($clientId, $owner, 'stage', $contactId, ['stage' => $stageId]);
}

/**
 * The worker's pass: every fresh notice of a kind the account sends, to people with a number who
 * want alerts. Each notice is tried once, and marked so it is never sent twice.
 */
function crm_staff_wa_tick(int $limit = 100): int
{
    if (!db_has_column('crm_notices', 'wa_status')) return 0;
    $rows = db_all("SELECT n.*, u.phone, u.wa_alerts, u.status AS ustatus, COALESCE(NULLIF(u.name,''), u.email) AS uname FROM crm_notices n JOIN users u ON u.id = n.user_id
                     WHERE n.wa_status IS NULL AND n.created_at > NOW() - INTERVAL 2 HOUR
                     ORDER BY n.id LIMIT " . (int) $limit);
    $sent = 0; $clients = [];
    foreach ($rows as $n) {
        $cid = (int) $n['client_id'];
        $clients[$cid] ??= db_row("SELECT * FROM clients WHERE id=?", [$cid]);
        $client = $clients[$cid];
        $s = crm_settings($cid);
        $kinds = array_filter(explode(',', (string) $s['staff_wa_kinds']));
        $skip = null;
        if (!(int) $s['staff_wa_on'])                          $skip = 'off';
        elseif (!in_array($n['kind'] === 'sla_team' ? 'sla' : $n['kind'], $kinds, true)) $skip = 'kind';
        elseif (!(int) $n['wa_alerts'] || $n['ustatus'] !== 'active') $skip = 'user';
        elseif (trim((string) $n['phone']) === '')            $skip = 'no number';
        if ($skip) { db_run("UPDATE crm_notices SET wa_status='skipped', wa_error=? WHERE id=?", [$skip, (int) $n['id']]); continue; }

        $t = crm_notice_text($n);
        $url = rtrim(app_base_url(), '/') . '/client/' . $t['url'];
        // Claim it first, so a slow send and the next pass can never both send it.
        if (!db_run("UPDATE crm_notices SET wa_status='sending' WHERE id=? AND wa_status IS NULL", [(int) $n['id']])) continue;
        $r = crm_staff_send($client, (string) $n['phone'], $t['text'], $url, $n, (string) ($n['uname'] ?? ''));
        db_run("UPDATE crm_notices SET wa_status=?, wa_error=? WHERE id=?",
               [$r['ok'] ? 'sent' : 'failed', $r['ok'] ? null : mb_substr($r['error'], 0, 255), (int) $n['id']]);
        if ($r['ok']) $sent++;
    }
    return $sent;
}
