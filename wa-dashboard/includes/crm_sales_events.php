<?php
declare(strict_types=1);

/**
 * Sales events — an open day, a launch, a webinar.
 *
 *   create    a manager names it: when, where (an address or a link), which project.
 *   guests    leads are added from the leads list (tick them, or everything a filter matches) or on
 *             the event's page by project / stage / heat. A salesperson only ever adds and sees
 *             their own leads.
 *   invite    one WhatsApp template to every guest not yet invited, from the Business API number,
 *             through the CRM queue (working hours, opt-outs). The day before, a reminder goes to
 *             everyone who has not said no.
 *   attendance  said yes / said no, then came / didn't come. The report shows each event's
 *             invited → confirmed → came → bought.
 */

require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/crm_automation.php';

function sev_statuses(): array
{
    return ['added' => 'Not invited yet', 'invited' => 'Invited', 'confirmed' => 'Said yes', 'declined' => 'Said no',
            'attended' => 'Came', 'no_show' => 'Didn\'t come'];
}

function sev_ready(): bool
{
    return db_has_column('crm_visits', 'kind') && (bool) db_val("SHOW TABLES LIKE 'sales_events'");
}

function sev_get(int $clientId, int $eventId): ?array
{
    return db_row("SELECT * FROM sales_events WHERE id=? AND client_id=?", [$eventId, $clientId]) ?: null;
}

/** What the template tokens need (event_name / event_date / event_time / event_place, and project). */
function sev_context(array $ev): array
{
    return ['sales_event' => (int) $ev['id'], 'event_name' => (string) $ev['name'], 'event_at' => (string) $ev['starts_at'],
            'event_place' => (string) ($ev['place'] ?? ''), 'starts_at' => (string) $ev['starts_at'], 'place' => (string) ($ev['place'] ?? ''),
            'project_id' => $ev['project_id'] !== null ? (int) $ev['project_id'] : null];
}

/**
 * Create or change an event.
 * @return array{ok:bool, error?:string, id?:int}
 */
function sev_save(array $client, array $d, ?int $by, int $id = 0): array
{
    $cid = (int) $client['id'];
    $name = mb_substr(trim((string) ($d['name'] ?? '')), 0, 150);
    if ($name === '') return ['ok' => false, 'error' => 'Give the event a name.'];
    $t = strtotime(trim((string) ($d['date'] ?? '')) . ' ' . trim((string) ($d['time'] ?? '')));
    if (!$t) return ['ok' => false, 'error' => 'Choose the date and time.'];
    $tpls = crm_tpl_choices($cid);
    $it = (int) ($d['invite_tpl'] ?? 0); $rt = (int) ($d['remind_tpl'] ?? 0);
    $pid = (int) ($d['project_id'] ?? 0);
    $row = [
        'name' => $name, 'starts_at' => date('Y-m-d H:i:s', $t),
        'duration_min' => max(15, min(1440, (int) ($d['duration'] ?? 120))),
        'place' => mb_substr(trim((string) ($d['place'] ?? '')), 0, 500) ?: null,
        'project_id' => isset(crm_project_names($cid)[$pid]) ? $pid : null,
        'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 1000) ?: null,
        'invite_tpl' => isset($tpls[$it]) ? $it : null, 'invite_vars' => json_encode($d['invite_vars'] ?? [], JSON_UNESCAPED_UNICODE),
        'remind_tpl' => isset($tpls[$rt]) ? $rt : null, 'remind_vars' => json_encode($d['remind_vars'] ?? [], JSON_UNESCAPED_UNICODE),
    ];
    if ($id) {
        $old = sev_get($cid, $id);
        if (!$old) return ['ok' => false, 'error' => 'Event not found.'];
        if ($old['starts_at'] !== $row['starts_at']) $row['reminded'] = 0;   // a new date earns a new reminder
        db_run("UPDATE sales_events SET " . implode(',', array_map(fn($k) => "$k=?", array_keys($row))) . " WHERE id=? AND client_id=?",
               array_merge(array_values($row), [$id, $cid]));
        return ['ok' => true, 'id' => $id];
    }
    if ($t < time() - 3600) return ['ok' => false, 'error' => 'That time has already passed.'];
    $row += ['client_id' => $cid, 'status' => 'active', 'created_by' => $by, 'created_at' => date('Y-m-d H:i:s')];
    $id = db_insert("INSERT INTO sales_events (" . implode(',', array_keys($row)) . ") VALUES (" . rtrim(str_repeat('?,', count($row)), ',') . ")", array_values($row));
    return ['ok' => true, 'id' => $id];
}

/** Cancel: nothing more is sent for it. */
function sev_cancel(array $client, int $eventId): void
{
    db_run("UPDATE sales_events SET status='cancelled' WHERE id=? AND client_id=?", [$eventId, (int) $client['id']]);
    db_run("UPDATE crm_msg_queue SET status='cancelled', error='The event was cancelled.' WHERE status='queued' AND client_id=? AND (reason=? OR reason=?)",
           [(int) $client['id'], 'event_invite:' . $eventId, 'event_remind:' . $eventId]);
}

/** Add leads as guests. Only leads this person can see; ones already on the list are skipped. */
function sev_add_guests(array $client, int $eventId, array $contactIds, ?int $by): int
{
    $cid = (int) $client['id'];
    $ev = sev_get($cid, $eventId);
    if (!$ev || $ev['status'] !== 'active') return 0;
    $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
    if (!$ids) return 0;
    $n = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        [$scope, $sp] = crm_scope('c');
        $ok = array_column(db_all("SELECT c.id FROM contacts c WHERE c.client_id=? AND c.stage_id IS NOT NULL AND c.id IN ($ph)$scope",
                                  array_merge([$cid], $chunk, $sp)), 'id');
        foreach ($ok as $contactId) {
            $n += db_run("INSERT IGNORE INTO sales_event_guests (event_id,client_id,contact_id,status,added_by,added_at) VALUES (?,?,?,'added',?,NOW())",
                         [$eventId, $cid, (int) $contactId, $by]) ? 1 : 0;
        }
    }
    return $n;
}

/**
 * Send the invitation to every guest not yet invited.
 * @return array{ok:bool, error?:string, n?:int}
 */
function sev_invite(array $client, int $eventId, ?int $by): array
{
    $cid = (int) $client['id'];
    $ev = sev_get($cid, $eventId);
    if (!$ev || $ev['status'] !== 'active') return ['ok' => false, 'error' => 'Event not found.'];
    if (!(int) $ev['invite_tpl']) return ['ok' => false, 'error' => 'Choose the invitation template on the event first.'];
    if (!crm_auto_api_ready($client)) return ['ok' => false, 'error' => 'Connect the WhatsApp Business API number in Settings first.'];
    if (strtotime((string) $ev['starts_at']) < time()) return ['ok' => false, 'error' => 'The event has already started.'];
    $tokens = json_decode((string) $ev['invite_vars'], true) ?: [];
    $n = 0;
    [$scope, $sp] = crm_scope('c');
    foreach (db_all("SELECT g.id, g.contact_id FROM sales_event_guests g JOIN contacts c ON c.id=g.contact_id
                      WHERE g.event_id=? AND g.status='added'$scope", array_merge([$eventId], $sp)) as $g) {
        crm_queue($client, (int) $g['contact_id'], (int) $ev['invite_tpl'], $tokens, '', 'event_invite:' . $eventId, null, sev_context($ev) + ['by' => $by]);
        db_run("UPDATE sales_event_guests SET status='invited', invited_at=NOW() WHERE id=?", [(int) $g['id']]);
        crm_log($cid, (int) $g['contact_id'], 'event', null, mb_substr('Invited to ' . $ev['name'] . ' on ' . date('D j M, H:i', strtotime((string) $ev['starts_at'])), 0, 250), $by);
        $n++;
    }
    return ['ok' => true, 'n' => $n];
}

/** Said yes / said no / came / didn't come — for guests this person can see. */
function sev_mark(array $client, int $eventId, array $guestIds, string $status, ?int $by): int
{
    if (!isset(sev_statuses()[$status]) || $status === 'added') return 0;
    $cid = (int) $client['id'];
    $ev = sev_get($cid, $eventId);
    if (!$ev) return 0;
    $ids = array_values(array_filter(array_map('intval', $guestIds)));
    if (!$ids) return 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    [$scope, $sp] = crm_scope('c');
    $n = 0;
    $words = ['confirmed' => 'Said yes to', 'declined' => 'Said no to', 'attended' => 'Came to', 'no_show' => 'Did not come to', 'invited' => 'Invited again to'];
    foreach (db_all("SELECT g.* FROM sales_event_guests g JOIN contacts c ON c.id=g.contact_id WHERE g.event_id=? AND g.id IN ($ph)$scope",
                    array_merge([$eventId], $ids, $sp)) as $g) {
        if ($g['status'] === $status) continue;
        db_run("UPDATE sales_event_guests SET status=?, answered_at=NOW() WHERE id=?", [$status, (int) $g['id']]);
        crm_log($cid, (int) $g['contact_id'], 'event', null, mb_substr($words[$status] . ' ' . $ev['name'], 0, 250), $by);
        $n++;
    }
    return $n;
}

/** The day before, from the account's reminder hour: everyone invited who has not said no. */
function sev_tick(): int
{
    if (!sev_ready()) return 0;
    $n = 0;
    foreach (db_all("SELECT * FROM sales_events WHERE status='active' AND reminded=0 AND remind_tpl IS NOT NULL
                      AND DATE(starts_at) = CURDATE() + INTERVAL 1 DAY LIMIT 100") as $ev) {
        $cid = (int) $ev['client_id'];
        $s = crm_settings($cid);
        if ((int) date('G') < (int) ($s['visit_remind_hour'] ?? 18)) continue;
        db_run("UPDATE sales_events SET reminded=1 WHERE id=?", [(int) $ev['id']]);
        $client = db_row("SELECT * FROM clients WHERE id=?", [$cid]);
        if (!$client) continue;
        $tokens = json_decode((string) $ev['remind_vars'], true) ?: [];
        foreach (db_all("SELECT contact_id FROM sales_event_guests WHERE event_id=? AND status IN ('invited','confirmed')", [(int) $ev['id']]) as $g) {
            crm_queue($client, (int) $g['contact_id'], (int) $ev['remind_tpl'], $tokens, '', 'event_remind:' . $ev['id'], null, sev_context($ev));
            $n++;
        }
    }
    return $n;
}

/** Events with their counts, newest first. Counts only this person's leads for a salesperson. */
function sev_list(int $clientId, bool $past = false): array
{
    [$scope, $sp] = crm_scope('c');
    $won = crm_won_ids($clientId);
    $wph = implode(',', array_fill(0, count($won), '?'));
    return db_all("SELECT e.*, p.name AS project_name,
                          COUNT(c.id) AS guests,
                          SUM(g.status IN ('invited','confirmed','declined','attended','no_show')) AS invited,
                          SUM(g.status IN ('confirmed','attended','no_show')) AS said_yes,
                          SUM(g.status='declined') AS said_no,
                          SUM(g.status='attended') AS came,
                          SUM(g.status='no_show') AS no_show,
                          COUNT(DISTINCT CASE WHEN g.status='attended' AND c.stage_id IN ($wph) THEN c.id END) AS bought
                     FROM sales_events e
                     LEFT JOIN sales_event_guests g ON g.event_id=e.id
                     LEFT JOIN contacts c ON c.id=g.contact_id AND c.deleted_at IS NULL$scope
                     LEFT JOIN crm_projects p ON p.id=e.project_id
                    WHERE e.client_id=? AND e.starts_at " . ($past ? '<' : '>=') . " NOW() - INTERVAL 1 DAY
                    GROUP BY e.id ORDER BY e.starts_at " . ($past ? 'DESC' : 'ASC') . " LIMIT 200",
                  array_merge($won, $sp, [$clientId]));
}

/** The won stage ids, never empty (so IN () stays valid). */
function crm_won_ids(int $clientId): array
{
    $ids = array_map('intval', array_column(db_all("SELECT id FROM crm_stages WHERE client_id=? AND kind='won'", [$clientId]), 'id'));
    return $ids ?: [0];
}

function sev_guests(int $clientId, int $eventId, string $status = ''): array
{
    [$scope, $sp] = crm_scope('c');
    $sql = "SELECT g.*, c.name, c.phone_e164, c.owner_user_id, s.name AS stage, s.kind AS stage_kind,
                   COALESCE(NULLIF(u.name,''), u.email) AS owner_name
              FROM sales_event_guests g JOIN contacts c ON c.id=g.contact_id AND c.deleted_at IS NULL
              LEFT JOIN crm_stages s ON s.id=c.stage_id LEFT JOIN users u ON u.id=c.owner_user_id
             WHERE g.event_id=? AND g.client_id=?$scope";
    $p = array_merge([$eventId, $clientId], $sp);
    if ($status !== '' && isset(sev_statuses()[$status])) { $sql .= " AND g.status=?"; $p[] = $status; }
    return db_all($sql . " ORDER BY FIELD(g.status,'attended','confirmed','invited','added','no_show','declined'), c.name LIMIT 3000", $p);
}
