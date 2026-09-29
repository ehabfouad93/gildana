<?php
declare(strict_types=1);

/**
 * The CRM's clock, run by the background worker every minute:
 *
 *   follow-ups   the owner's phone buzzes when a follow-up comes due
 *   digest       each morning: today's follow-ups, overdue, new leads — and for managers, the team
 *   response     a new lead nobody has contacted in time → alert the owner and the managers,
 *                and after longer, give it to someone else by the assignment rules
 *   stale        an open lead with no activity for days → alert, and optionally reassign
 *   scores       keep lead scores fresh as time passes ("nothing for 30 days" cools a lead)
 *
 * Every step is cheap when there is nothing to do, and marks what it announced, so a lead is
 * announced once — not every minute.
 */

require_once __DIR__ . '/crm.php';

function crm_auto_tick(): array
{
    $sum = ['followups' => 0, 'digests' => 0, 'alerts' => 0, 'reclaimed' => 0, 'stale' => 0, 'rescored' => 0];
    if (!db_has_column('contacts', 'reclaims')) return $sum;          // migration 039 not applied yet
    foreach (db_all("SELECT * FROM clients WHERE status='active'") as $client) {
        if (!crm_enabled($client)) continue;
        try {
            $s = crm_settings((int) $client['id']);
            $sum['followups'] += crm_auto_followups($client, $s);
            $sum['digests']   += crm_auto_digest($client, $s);
            [$a, $r] = crm_auto_response($client, $s);
            $sum['alerts'] += $a; $sum['reclaimed'] += $r;
            $sum['stale']     += crm_auto_stale($client, $s);
        } catch (Throwable $e) {
            error_log('crm_auto_tick client ' . $client['id'] . ': ' . $e->getMessage());
        }
    }
    $sum['rescored'] = crm_auto_rescore();
    // Sequences decide what is due, then the queue sends it (stage messages, sequence steps, visits).
    $sum['seq_queued'] = crm_seq_tick();
    $sum['auto_sent']  = crm_queue_tick();
    // Last, so this pass's alerts go out in this pass.
    $sum['staff_wa'] = crm_staff_wa_tick();
    return $sum;
}

/** Inside working hours? No hours set = always. */
function crm_in_hours(array $s, ?int $now = null): bool
{
    if ($s['work_start'] === null || $s['work_end'] === null) return true;
    $h = (int) date('G', $now ?? time());
    return $h >= (int) $s['work_start'] && $h < (int) $s['work_end'];
}

/**
 * Minutes a lead has been waiting, counting only working time from when it was assigned: a lead
 * that arrived at 11 pm has been waiting since 9 am, not all night.
 */
function crm_waited_minutes(array $s, string $since, ?int $now = null): int
{
    $now = $now ?? time();
    $from = strtotime($since);
    if ($s['work_start'] !== null && $s['work_end'] !== null) {
        $todayStart = strtotime(date('Y-m-d', $now) . sprintf(' %02d:00:00', (int) $s['work_start']));
        if ($from < $todayStart) $from = $todayStart;
    }
    return max(0, (int) floor(($now - $from) / 60));
}

function crm_auto_followups(array $client, array $s): int
{
    if (!(int) $s['followup_reminders']) return 0;
    $cid = (int) $client['id'];
    $rows = db_all("SELECT c.id, c.owner_user_id, c.next_followup_at FROM contacts c JOIN crm_stages st ON st.id=c.stage_id
                     WHERE c.client_id=? AND st.kind='open' AND c.owner_user_id IS NOT NULL
                       AND c.next_followup_at <= NOW()
                       AND (c.followup_notified_for IS NULL OR c.followup_notified_for <> c.next_followup_at)
                     LIMIT 500", [$cid]);
    $n = 0;
    foreach ($rows as $r) {
        db_run("UPDATE contacts SET followup_notified_for=next_followup_at WHERE id=?", [(int) $r['id']]);
        // A follow-up already a day overdue when this first runs is marked, not announced — no
        // flood of old reminders the day this is switched on. The morning summary counts those.
        if (strtotime((string) $r['next_followup_at']) < time() - 86400) continue;
        crm_notice($cid, (int) $r['owner_user_id'], 'followup', (int) $r['id']);
        $n++;
    }
    return $n;
}

function crm_auto_digest(array $client, array $s): int
{
    if ($s['digest_hour'] === null || (int) date('G') < (int) $s['digest_hour']) return 0;
    $cid = (int) $client['id'];
    $today = date('Y-m-d');
    $n = 0;
    $users = db_all("SELECT * FROM users WHERE client_id=? AND role='client' AND status='active'
                      AND (crm_digest_on IS NULL OR crm_digest_on < ?)", [$cid, $today]);
    foreach ($users as $u) {
        db_run("UPDATE users SET crm_digest_on=? WHERE id=?", [$today, (int) $u['id']]);
        $isMgr = user_client_role($u) === 'admin';
        if (!$isMgr && user_client_role($u) !== 'sales') continue;
        if (!in_array('crm', user_modules($u, $client), true)) continue;
        $a = crm_alert_counts($client, $isMgr ? null : (int) $u['id']);
        $a['new'] = (int) db_val("SELECT COUNT(*) FROM contacts WHERE client_id=? AND crm_added_at >= CURDATE() - INTERVAL 1 DAY"
                                 . ($isMgr ? '' : ' AND owner_user_id=' . (int) $u['id']), [$cid]);
        if (!array_sum($a)) continue;                             // nothing to say is not worth a buzz
        crm_notice($cid, (int) $u['id'], 'digest', null, $a + ['team' => $isMgr ? 1 : 0]);
        $n++;
    }
    return $n;
}

/** @return array{0:int,1:int} [alerts sent, leads taken back] */
function crm_auto_response(array $client, array $s): array
{
    $alertAt = (int) ($s['first_contact_minutes'] ?? 0);
    $takeAt  = (int) ($s['reclaim_minutes'] ?? 0);
    if (!$alertAt && !$takeAt) return [0, 0];
    if (!crm_in_hours($s)) return [0, 0];
    $cid = (int) $client['id'];
    $first = min(array_filter([$alertAt, $takeAt]));
    $rows = db_all("SELECT c.* FROM contacts c JOIN crm_stages st ON st.id=c.stage_id
                     WHERE c.client_id=? AND st.kind='open' AND c.owner_user_id IS NOT NULL
                       AND c.first_response_at IS NULL AND c.assigned_at IS NOT NULL
                       AND c.assigned_at < NOW() - INTERVAL ? MINUTE
                     ORDER BY c.assigned_at LIMIT 300", [$cid, $first]);
    $alerts = 0; $taken = 0; $mgrLate = 0;
    foreach ($rows as $c) {
        $waited = crm_waited_minutes($s, (string) $c['assigned_at']);
        $owner  = (int) $c['owner_user_id'];

        if ($takeAt && $waited >= $takeAt && (int) $c['reclaims'] < (int) $s['reclaim_max']) {
            $newOwner = crm_assign_next($client, $c, array_unique(array_merge([$owner], crm_past_owners((int) $c['id']))));
            if ($newOwner !== null) {
                crm_assign($client, (int) $c['id'], $newOwner, null);
                db_run("UPDATE contacts SET reclaims=reclaims+1 WHERE id=?", [(int) $c['id']]);
                crm_log($cid, (int) $c['id'], 'reclaimed', (string) $owner, (string) $newOwner, null);
                crm_notice($cid, $owner, 'reclaimed', (int) $c['id']);
                $taken++;
                continue;
            }
        }
        if ($alertAt && $waited >= $alertAt && $c['sla_alerted_at'] === null) {
            db_run("UPDATE contacts SET sla_alerted_at=NOW() WHERE id=?", [(int) $c['id']]);
            crm_log($cid, (int) $c['id'], 'sla', null, (string) $waited, null);
            crm_notice($cid, $owner, 'sla', (int) $c['id']);
            $alerts++; $mgrLate++;
        }
    }
    // One summary buzz for the managers per pass, not one per lead.
    if ($mgrLate) foreach (crm_admin_ids($cid) as $m) crm_notice($cid, $m, 'sla_team', null, ['n' => $mgrLate]);
    return [$alerts, $taken];
}

function crm_auto_stale(array $client, array $s): int
{
    $days = (int) ($s['stale_days'] ?? 0);
    if (!$days) return 0;
    $cid = (int) $client['id'];
    $rows = db_all("SELECT c.* FROM contacts c JOIN crm_stages st ON st.id=c.stage_id
                     WHERE c.client_id=? AND st.kind='open' AND c.owner_user_id IS NOT NULL AND c.stale_alerted_at IS NULL
                       AND COALESCE(c.last_touch_at, c.assigned_at, c.crm_added_at, c.created_at) < NOW() - INTERVAL ? DAY
                     LIMIT 300", [$cid, $days]);
    $per = [];
    foreach ($rows as $c) {
        db_run("UPDATE contacts SET stale_alerted_at=NOW() WHERE id=?", [(int) $c['id']]);
        $owner = (int) $c['owner_user_id'];
        if ((int) $s['stale_reassign']) {
            $new = crm_assign_next($client, $c, [$owner]);
            if ($new !== null) {
                crm_assign($client, (int) $c['id'], $new, null);
                crm_log($cid, (int) $c['id'], 'reclaimed', (string) $owner, (string) $new, null);
                crm_notice($cid, $owner, 'reclaimed', (int) $c['id']);
                continue;
            }
        }
        $per[$owner] = ($per[$owner] ?? 0) + 1;
    }
    foreach ($per as $uid => $n) crm_notice($cid, $uid, 'stale', null, ['n' => $n, 'days' => $days]);
    return count($rows);
}

/** Scores drift with time (a lead that went quiet cools down), so refresh the oldest few each pass. */
function crm_auto_rescore(int $limit = 200): int
{
    $ids = array_column(db_all("SELECT c.id FROM contacts c JOIN crm_stages st ON st.id=c.stage_id
                                 WHERE st.kind='open' AND (c.score_at IS NULL OR c.score_at < NOW() - INTERVAL 12 HOUR)
                                 ORDER BY c.score_at IS NOT NULL, c.score_at LIMIT " . (int) $limit), 'id');
    foreach ($ids as $id) crm_rescore((int) $id);
    return count($ids);
}

/**
 * What a person's phone should say, for push_status.php: the most important unseen notice.
 * Counts and kinds only — no names, numbers or message text leave through a push.
 */
function crm_notice_summary(int $userId): ?array
{
    try {
        $rows = db_all("SELECT kind, COUNT(*) n, MAX(id) last, MAX(contact_id) cid FROM crm_notices
                         WHERE user_id=? AND seen_at IS NULL AND created_at > NOW() - INTERVAL 1 DAY GROUP BY kind", [$userId]);
    } catch (Throwable $e) { return null; }
    if (!$rows) return null;
    $by = array_column($rows, null, 'kind');
    db_run("UPDATE crm_notices SET seen_at=NOW() WHERE user_id=? AND seen_at IS NULL", [$userId]);
    foreach (['visit', 'reclaimed', 'sla', 'assigned', 'sla_team', 'followup', 'resubmit', 'stale', 'digest'] as $k) {
        if (!isset($by[$k])) continue;
        $out = ['kind' => $k, 'n' => (int) $by[$k]['n']];
        if ((int) $by[$k]['n'] === 1 && $by[$k]['cid']) $out['lead'] = (int) $by[$k]['cid'];   // an id, to open the right page
        if (in_array($k, ['digest', 'sla_team', 'stale'], true)) {
            $d = json_decode((string) db_val("SELECT data FROM crm_notices WHERE id=?", [(int) $by[$k]['last']]), true) ?: [];
            $out['data'] = array_map('intval', array_intersect_key($d, array_flip(['due_today', 'overdue', 'late', 'unassigned', 'new', 'team', 'n', 'days'])));
        }
        return $out;
    }
    return null;
}
