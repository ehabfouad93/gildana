<?php
declare(strict_types=1);

/**
 * Site visits.
 *
 *   book        on the lead page: when, where, who meets them. The lead gets a WhatsApp
 *               confirmation, and can be moved to a stage (e.g. Viewing) in the same step.
 *   remind      the day before, the lead gets a reminder; an hour before (configurable), the
 *               salesperson gets one — the bell, their phone, their WhatsApp.
 *   outcome     afterwards: came, didn't come, or cancelled. "Came" can move the lead on.
 *
 * The messages go through the CRM's queue (crm_automation.php), so they respect working hours
 * and opt-outs like everything else sent automatically.
 *
 * A visit can also be an online meeting: the same booking, confirmation and reminders, with a
 * meeting link in place of an address — and one more reminder to the lead shortly before it starts.
 * The link is the one typed in, else a Google Meet room (when switched on and Google is connected),
 * else the salesperson's own room from their profile, else a fresh Jitsi room, which needs no account.
 */

function crm_visit_kinds(): array
{
    return ['site' => 'Site visit', 'online' => 'Online meeting'];
}

/** A link somebody typed: only web addresses, nothing that would run in the browser. */
function crm_meet_url_clean(string $u): string
{
    $u = trim($u);
    if ($u === '') return '';
    if (!preg_match('~^https?://~i', $u)) $u = 'https://' . $u;
    return filter_var($u, FILTER_VALIDATE_URL) && preg_match('~^https?://[^\s<>"]+$~i', $u) ? mb_substr($u, 0, 500) : '';
}

/**
 * The link for an online meeting.
 * @return array{url:string, gcal?:string, via:string, error?:string}
 */
function crm_meet_link(array $client, array $lead, ?int $hostId, int $start, int $minutes, string $typed = ''): array
{
    if (($u = crm_meet_url_clean($typed)) !== '') return ['url' => $u, 'via' => 'typed'];
    require_once __DIR__ . '/google.php';
    $googleErr = '';
    if (google_meet_enabled() && google_connected($client)) {
        $title = 'Meeting with ' . (trim((string) ($lead['name'] ?? '')) ?: '+' . $lead['phone_e164']);
        $g = google_meet_create($client, $title, $start, $minutes, (string) ($lead['email'] ?? ''));
        if ($g['ok']) return ['url' => $g['url'], 'gcal' => $g['event_id'], 'via' => 'google'];
        $googleErr = (string) $g['error'];
    }
    if ($hostId && db_has_column('users', 'meet_url')) {
        $own = crm_meet_url_clean((string) db_val("SELECT meet_url FROM users WHERE id=?", [$hostId]));
        if ($own !== '') return ['url' => $own, 'via' => 'own', 'error' => $googleErr];
    }
    $slug = preg_replace('/[^A-Za-z0-9]+/', '', (string) ($client['name'] ?? 'Meeting')) ?: 'Meeting';
    return ['url' => 'https://meet.jit.si/' . substr($slug, 0, 24) . '-' . bin2hex(random_bytes(6)), 'via' => 'jitsi', 'error' => $googleErr];
}

function crm_visit_statuses(): array
{
    return ['scheduled' => 'Booked', 'done' => 'Came', 'no_show' => 'Didn\'t come', 'cancelled' => 'Cancelled'];
}

/** The visit's details, as the template tokens (visit_date / visit_time / visit_place / project) need them. */
function crm_visit_context(array $v): array
{
    return ['visit' => (int) $v['id'], 'starts_at' => (string) $v['starts_at'], 'place' => (string) ($v['place'] ?? ''),
            'project_id' => $v['project_id'] !== null ? (int) $v['project_id'] : null,
            'meet_url' => (string) ($v['meet_url'] ?? ''), 'kind' => (string) ($v['kind'] ?? 'site')];
}

/**
 * Book a visit.
 *
 * @param array{starts_at:string, place?:string, project_id?:?int, user_id?:?int, notes?:string, duration?:int, confirm?:bool,
 *              kind?:string, meet_url?:string} $d
 * @return array{ok:bool, error?:string, id?:int}
 */
function crm_visit_book(array $client, int $contactId, array $d, ?int $by): array
{
    $cid = (int) $client['id'];
    $c = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$contactId, $cid]);
    if (!$c) return ['ok' => false, 'error' => 'Lead not found.'];
    $t = strtotime((string) ($d['starts_at'] ?? ''));
    if (!$t) return ['ok' => false, 'error' => 'Choose the date and time of the visit.'];
    if ($t < time() - 3600) return ['ok' => false, 'error' => 'That time has already passed.'];

    $project = (int) ($d['project_id'] ?? 0) ?: ((int) ($c['project_id'] ?? 0) ?: null);
    $place = trim((string) ($d['place'] ?? ''));
    if ($place === '' && $project) $place = (string) db_val("SELECT COALESCE(address,'') FROM crm_projects WHERE id=?", [$project]);
    $host = (int) ($d['user_id'] ?? 0) ?: ($c['owner_user_id'] !== null ? (int) $c['owner_user_id'] : $by);

    $mins = max(15, min(480, (int) ($d['duration'] ?? 60)));
    $online = ($d['kind'] ?? 'site') === 'online' && db_has_column('crm_visits', 'kind');
    $meet = ['url' => '', 'gcal' => null];
    if ($online) {
        $meet = crm_meet_link($client, $c, $host, $t, $mins, (string) ($d['meet_url'] ?? '')) + ['gcal' => null];
        $place = $meet['url'];                          // what {visit_place} shows in the messages
    }
    $row = ['client_id' => $cid, 'contact_id' => $contactId, 'user_id' => $host, 'project_id' => $project,
            'starts_at' => date('Y-m-d H:i:s', $t), 'duration_min' => $mins, 'place' => $place !== '' ? mb_substr($place, 0, 255) : null,
            'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null, 'status' => 'scheduled',
            'created_by' => $by, 'created_at' => date('Y-m-d H:i:s')];
    if ($online) $row += ['kind' => 'online', 'meet_url' => $meet['url'], 'gcal_event_id' => $meet['gcal'] ?: null];
    $id = db_insert("INSERT INTO crm_visits (" . implode(',', array_keys($row)) . ") VALUES (" . rtrim(str_repeat('?,', count($row)), ',') . ")", array_values($row));
    if ($project && empty($c['project_id'])) db_run("UPDATE contacts SET project_id=? WHERE id=?", [$project, $contactId]);

    crm_log($cid, $contactId, $online ? 'meeting' : 'visit', null, date('Y-m-d H:i', $t), $by);
    crm_log_activity($client, $contactId, 'visit', 'booked', $online
        ? 'Online meeting booked for ' . date('D j M, H:i', $t) . '. Link: ' . $meet['url']
        : 'Site visit booked for ' . date('D j M, H:i', $t) . ($place !== '' ? ' at ' . $place : '') . '.', $by);
    $v = db_row("SELECT * FROM crm_visits WHERE id=?", [$id]);

    $s = crm_settings($cid);
    if (!empty($d['confirm']) && (int) $s['visit_confirm_tpl']) {
        crm_queue($client, $contactId, (int) $s['visit_confirm_tpl'], json_decode((string) $s['visit_confirm_vars'], true) ?: [],
                  '', 'visit_confirm:' . $id, null, crm_visit_context($v));
    }
    if ((int) $s['visit_booked_stage'] && $c['stage_id'] !== null) crm_set_stage($client, $contactId, (int) $s['visit_booked_stage'], $by);
    // Booked by someone else for them: the host hears about it now, not only an hour before.
    if ($host && $host !== $by) crm_notice($cid, $host, 'visit', $contactId, ['visit' => $id, 'at' => $v['starts_at']]);
    return ['ok' => true, 'id' => $id, 'meet_url' => $meet['url'], 'meet_via' => $meet['via'] ?? '', 'warning' => $meet['error'] ?? ''];
}

/** Move a visit to another time. The lead gets the confirmation again, with the new time. */
function crm_visit_reschedule(array $client, int $visitId, string $when, ?int $by): array
{
    $v = db_row("SELECT * FROM crm_visits WHERE id=? AND client_id=?", [$visitId, (int) $client['id']]);
    if (!$v || $v['status'] !== 'scheduled') return ['ok' => false, 'error' => 'That visit cannot be moved.'];
    $t = strtotime($when);
    if (!$t || $t < time() - 3600) return ['ok' => false, 'error' => 'Choose a time that has not passed.'];
    db_run("UPDATE crm_visits SET starts_at=?, lead_reminded=0, staff_reminded=0" . (db_has_column('crm_visits', 'soon_reminded') ? ", soon_reminded=0" : '') . " WHERE id=?",
           [date('Y-m-d H:i:s', $t), $visitId]);
    if (!empty($v['gcal_event_id'])) { require_once __DIR__ . '/google.php'; google_meet_update($client, (string) $v['gcal_event_id'], $t, (int) $v['duration_min']); }
    crm_visit_cancel_messages($visitId, 'The visit was moved.');
    crm_log((int) $client['id'], (int) $v['contact_id'], 'visit_moved', (string) $v['starts_at'], date('Y-m-d H:i', $t), $by);
    $s = crm_settings((int) $client['id']);
    if ((int) $s['visit_confirm_tpl']) {
        $v = db_row("SELECT * FROM crm_visits WHERE id=?", [$visitId]);
        crm_queue($client, (int) $v['contact_id'], (int) $s['visit_confirm_tpl'], json_decode((string) $s['visit_confirm_vars'], true) ?: [],
                  '', 'visit_confirm:' . $visitId, null, crm_visit_context($v));
    }
    return ['ok' => true];
}

function crm_visit_cancel_messages(int $visitId, string $why): void
{
    db_run("UPDATE crm_msg_queue SET status='cancelled', error=? WHERE status='queued' AND (reason=? OR reason=?)",
           [$why, 'visit_confirm:' . $visitId, 'visit_remind:' . $visitId]);
    db_run("UPDATE crm_msg_queue SET status='cancelled', error=? WHERE status='queued' AND reason=?", [$why, 'meet_soon:' . $visitId]);
}

/** Came, didn't come, or cancelled. */
function crm_visit_outcome(array $client, int $visitId, string $status, ?int $by): bool
{
    if (!in_array($status, ['done', 'no_show', 'cancelled'], true)) return false;
    $v = db_row("SELECT * FROM crm_visits WHERE id=? AND client_id=?", [$visitId, (int) $client['id']]);
    if (!$v) return false;
    db_run("UPDATE crm_visits SET status=?, outcome_at=NOW() WHERE id=?", [$status, $visitId]);
    if ($status === 'cancelled' && !empty($v['gcal_event_id'])) { require_once __DIR__ . '/google.php'; google_meet_update($client, (string) $v['gcal_event_id'], null); }
    $what = ($v['kind'] ?? 'site') === 'online' ? 'online meeting' : 'site visit';
    if ($status !== 'done') crm_visit_cancel_messages($visitId, $status === 'cancelled' ? 'The visit was cancelled.' : 'They did not come.');
    $cId = (int) $v['contact_id'];
    $when = date('D j M', strtotime((string) $v['starts_at']));
    crm_log_activity($client, $cId, 'visit', $status === 'done' ? 'visited' : ($status === 'no_show' ? 'no_show' : null),
        ['done' => 'Came to the ' . $what . ' on ' . $when . '.', 'no_show' => 'Did not come to the ' . $what . ' on ' . $when . '.',
         'cancelled' => ucfirst($what) . ' on ' . $when . ' cancelled.'][$status], $by);
    $s = crm_settings((int) $client['id']);
    if ($status === 'done' && (int) $s['visit_done_stage']) crm_set_stage($client, $cId, (int) $s['visit_done_stage'], $by);
    if ($status === 'done' && !empty($s['capi_visit_event']) && function_exists('crm_capi_queue')) crm_capi_queue($client, $cId, (string) $s['capi_visit_event']);
    return true;
}

/**
 * The worker's pass: tomorrow's visitors get their reminder (from the chosen hour today), and
 * each host gets theirs shortly before. Visits nobody marked stay "Booked" and show as needing an
 * answer on the calendar.
 */
function crm_visits_tick(): int
{
    if (!db_has_column('crm_visits', 'lead_reminded')) return 0;
    $n = 0; $clients = [];
    // The lead: the day before, from the account's reminder hour — or at once if booked after it.
    foreach (db_all("SELECT v.* FROM crm_visits v WHERE v.status='scheduled' AND v.lead_reminded=0
                      AND DATE(v.starts_at) = CURDATE() + INTERVAL 1 DAY LIMIT 300") as $v) {
        $cid = (int) $v['client_id'];
        $s = crm_settings($cid);
        if ((int) date('G') < (int) $s['visit_remind_hour']) continue;
        db_run("UPDATE crm_visits SET lead_reminded=1 WHERE id=?", [(int) $v['id']]);
        if (!(int) $s['visit_remind_tpl']) continue;
        // A visit booked only this evening already had its confirmation; no reminder on top of it.
        if (strtotime((string) $v['created_at']) > time() - 6 * 3600) continue;
        $clients[$cid] ??= db_row("SELECT * FROM clients WHERE id=?", [$cid]);
        crm_queue($clients[$cid], (int) $v['contact_id'], (int) $s['visit_remind_tpl'], json_decode((string) $s['visit_remind_vars'], true) ?: [],
                  '', 'visit_remind:' . $v['id'], null, crm_visit_context($v));
        $n++;
    }
    // An online meeting: the lead again, shortly before, with the link.
    if (db_has_column('crm_visits', 'soon_reminded') && db_has_column('crm_settings', 'meet_soon_tpl')) {
        foreach (db_all("SELECT v.* FROM crm_visits v JOIN crm_settings cs ON cs.client_id=v.client_id
                          WHERE v.status='scheduled' AND v.kind='online' AND v.soon_reminded=0 AND cs.meet_soon_tpl IS NOT NULL
                            AND v.starts_at > NOW() AND v.starts_at <= NOW() + INTERVAL cs.meet_soon_minutes MINUTE LIMIT 300") as $v) {
            db_run("UPDATE crm_visits SET soon_reminded=1 WHERE id=?", [(int) $v['id']]);
            $cid = (int) $v['client_id']; $s = crm_settings($cid);
            $clients[$cid] ??= db_row("SELECT * FROM clients WHERE id=?", [$cid]);
            crm_queue($clients[$cid], (int) $v['contact_id'], (int) $s['meet_soon_tpl'], json_decode((string) $s['meet_soon_vars'], true) ?: [],
                      '', 'meet_soon:' . $v['id'], null, crm_visit_context($v));
            $n++;
        }
    }
    // The host: shortly before.
    foreach (db_all("SELECT v.*, COALESCE(cs.visit_staff_minutes, 60) mins FROM crm_visits v LEFT JOIN crm_settings cs ON cs.client_id=v.client_id
                      WHERE v.status='scheduled' AND v.staff_reminded=0 AND v.user_id IS NOT NULL
                        AND v.starts_at > NOW() AND v.starts_at <= NOW() + INTERVAL COALESCE(cs.visit_staff_minutes, 60) MINUTE LIMIT 300") as $v) {
        db_run("UPDATE crm_visits SET staff_reminded=1 WHERE id=?", [(int) $v['id']]);
        crm_notice((int) $v['client_id'], (int) $v['user_id'], 'visit', (int) $v['contact_id'], ['visit' => (int) $v['id'], 'at' => $v['starts_at']]);
        $n++;
    }
    return $n;
}

/** Visits a person may see: a salesperson their own (hosting, or their lead's); others all. */
function crm_visits_between(int $clientId, string $from, string $to, array $f = []): array
{
    $sql = "SELECT v.*, c.name AS lead_name, c.phone_e164, c.owner_user_id, p.name AS project_name,
                   COALESCE(NULLIF(u.name,''), u.email) AS host_name
              FROM crm_visits v JOIN contacts c ON c.id=v.contact_id
              LEFT JOIN crm_projects p ON p.id=v.project_id LEFT JOIN users u ON u.id=v.user_id
             WHERE v.client_id=? AND v.starts_at >= ? AND v.starts_at < ?";
    $p = [$clientId, $from, $to];
    if (function_exists('is_sales') && is_sales()) { $sql .= " AND (v.user_id=? OR c.owner_user_id=?)"; $me = (int) crm_actor_id(); array_push($p, $me, $me); }
    if (!empty($f['user']))    { $sql .= " AND v.user_id=?"; $p[] = (int) $f['user']; }
    if (!empty($f['project'])) { $sql .= " AND v.project_id=?"; $p[] = (int) $f['project']; }
    if (empty($f['cancelled'])) $sql .= " AND v.status<>'cancelled'";
    return db_all($sql . " ORDER BY v.starts_at", $p);
}

/**
 * Visits and what came of them, per salesperson and per project: booked, came, didn't come,
 * show rate, and how many of those who came went on to buy.
 */
function crm_report_visits(int $clientId, string $from, string $to, string $by = 'user'): array
{
    if (!db_has_column('crm_visits', 'lead_reminded')) return [];
    require_once __DIR__ . '/crm_reports.php';                 // crm_stage_kinds()
    $col = $by === 'project' ? 'v.project_id' : 'v.user_id';
    $won = crm_stage_kinds($clientId)['won'] ?: [0];
    $ph = implode(',', array_fill(0, count($won), '?'));
    $sql = "SELECT $col AS k, COUNT(*) booked, SUM(v.status='done') came, SUM(v.status='no_show') no_show,
                   COUNT(DISTINCT CASE WHEN v.status='done' AND c.stage_id IN ($ph) THEN c.id END) AS bought,
                   COUNT(DISTINCT CASE WHEN v.status='done' THEN c.id END) AS visitors
              FROM crm_visits v JOIN contacts c ON c.id=v.contact_id
             WHERE v.client_id=? AND v.status<>'cancelled' AND v.starts_at BETWEEN ? AND ?";
    $p = array_merge($won, [$clientId, $from . ' 00:00:00', $to . ' 23:59:59']);
    [$scope, $sp] = crm_scope('c');
    $rows = db_all($sql . $scope . " GROUP BY $col ORDER BY booked DESC", array_merge($p, $sp));
    $names = $by === 'project' ? crm_project_names($clientId) : [];
    foreach ($rows as &$r) {
        $r['label'] = $by === 'project' ? ($r['k'] !== null ? ($names[(int) $r['k']] ?? 'A removed project') : 'No project')
                                        : crm_user_name($r['k'] !== null ? (int) $r['k'] : null);
        $decided = (int) $r['came'] + (int) $r['no_show'];
        $r['show_rate'] = $decided ? round(100 * $r['came'] / $decided) : null;
        $r['sale_rate'] = (int) $r['visitors'] ? round(100 * $r['bought'] / $r['visitors']) : null;
    }
    unset($r);
    return $rows;
}
