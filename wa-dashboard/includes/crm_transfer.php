<?php
declare(strict_types=1);

/**
 * Transferring leads, one or many at once.
 *
 *   to people     one or more salespeople, each with an optional number of leads ("quota").
 *                 People without a number share what is left equally. A lead never goes back to
 *                 the person who has it now; with smart rotation, people who have never had the lead
 *                 are preferred over people who had it before.
 *   to teams      the members of the chosen teams share the leads equally, same rules.
 *   options       fresh / cold afterwards; keep or clear the follow-up; who may still see the history
 *                 from before the transfer; start the lead again as New; show its last status.
 *
 * An Admin may transfer to anyone; a team leader only inside their own team(s).
 */

require_once __DIR__ . '/crm.php';

function crm_transfer_policies(): array
{
    return ['' => 'Show all history', 'sales' => 'Hide earlier history from the salesperson',
            'leaders' => 'Hide earlier history from the salesperson and team leader'];
}

/** Who this person may transfer to: everyone (Admin), their team (leader), nobody (anyone else). */
function crm_transfer_targets(int $clientId): array
{
    $people = crm_assignable_users($clientId);
    if (is_client_admin()) return $people;
    if (!crm_is_team_leader()) return [];
    $vis = crm_visible_owner_ids() ?? [];
    return array_values(array_filter($people, fn($u) => in_array((int) $u['id'], $vis, true)));
}

/**
 * Plan who gets which lead. Pure, so it can be tested and previewed.
 *
 * @param array<int,array{id:int,owner:?int,past:int[]}> $leads
 * @param array<int,?int> $quota  user id => number of leads, or null for "an equal share of the rest"
 * @return array{plan:array<int,int>, skipped:int[], error?:string}
 */
function crm_transfer_plan(array $leads, array $quota, bool $smart): array
{
    $n = count($leads);
    $fixed = array_sum(array_map('intval', array_filter($quota, fn($q) => $q !== null)));
    if ($fixed > $n) return ['plan' => [], 'skipped' => [], 'error' => "The numbers add up to $fixed, but only $n leads are selected."];
    // People with a number get exactly that many at most; people without one share the rest,
    // kept as even as the rules allow (a lead never goes back to its owner, smart rotation).
    $open = array_keys(array_filter($quota, fn($q) => $q === null));
    $pool = $n - $fixed;
    $target = []; $got = array_fill_keys(array_keys($quota), 0);
    foreach ($quota as $u => $q) $target[$u] = $q !== null ? (float) $q : ($open ? $pool / count($open) : 0.0);
    $plan = []; $skipped = [];
    // Leads whose owner is one of the receivers have fewer choices: place them first.
    usort($leads, fn($a, $b) => (int) isset($quota[$b['owner']]) <=> (int) isset($quota[$a['owner']]));
    foreach ($leads as $l) {
        $cands = array_values(array_filter(array_keys($quota), fn($u) => $u !== $l['owner'] && $target[$u] > 0
            && ($quota[$u] !== null ? $got[$u] < $quota[$u] : $pool > 0)));
        if ($smart) {
            $fresh = array_values(array_filter($cands, fn($u) => !in_array($u, $l['past'], true)));
            if ($fresh) $cands = $fresh;
        }
        if (!$cands) { $skipped[] = $l['id']; continue; }
        usort($cands, fn($a, $b) => ($got[$a] / $target[$a]) <=> ($got[$b] / $target[$b]) ?: $a <=> $b);   // the emptiest first
        $u = $cands[0];
        $plan[$l['id']] = $u; $got[$u]++;
        if ($quota[$u] === null) $pool--;
    }
    return ['plan' => $plan, 'skipped' => $skipped];
}

/**
 * Transfer leads.
 *
 * @param array{mode?:string, users?:array, teams?:array, smart?:bool, data_type?:string, reason?:string, notes?:string,
 *              keep_followup?:bool, history?:string, fresh_start?:bool, show_status?:bool} $o
 * @return array{ok:bool, error?:string, moved?:int, skipped?:int, by_user?:array}
 */
function crm_bulk_transfer(array $client, array $contactIds, array $o, ?int $by): array
{
    $cid = (int) $client['id'];
    $allowed = array_map(fn($u) => (int) $u['id'], crm_transfer_targets($cid));
    if (!$allowed) return ['ok' => false, 'error' => 'Only an Admin or a team leader can transfer leads.'];

    // Who receives, and how many each.
    $quota = [];
    if (($o['mode'] ?? 'users') === 'teams') {
        foreach ((array) ($o['teams'] ?? []) as $t) foreach (crm_team_member_ids((int) $t) as $u) if (in_array((int) $u, $allowed, true)) $quota[(int) $u] = null;
        if (!$quota) return ['ok' => false, 'error' => 'Choose a team with people in it.'];
    } else {
        foreach ((array) ($o['users'] ?? []) as $row) {
            $u = (int) ($row['id'] ?? 0);
            if (!$u) continue;
            if (!in_array($u, $allowed, true)) return ['ok' => false, 'error' => 'You can transfer only to people on your team.'];
            $q = trim((string) ($row['count'] ?? ''));
            $quota[$u] = $q === '' ? null : max(0, (int) $q);
        }
        if (!$quota) return ['ok' => false, 'error' => 'Choose who to transfer to.'];
    }
    $history = (string) ($o['history'] ?? '');
    if (!isset(crm_transfer_policies()[$history])) $history = '';
    if (!empty($o['fresh_start']) && $history === '') return ['ok' => false, 'error' => 'Starting a lead again as New needs its earlier history hidden.'];

    // The leads, as this person may see them, with everyone who has had each one.
    $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
    if (!$ids) return ['ok' => false, 'error' => 'Choose some leads first.'];
    $leads = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        [$scope, $sp] = crm_scope('c');
        foreach (db_all("SELECT c.id, c.owner_user_id, c.stage_id, c.substatus,
                                (SELECT GROUP_CONCAT(DISTINCT e.to_val) FROM crm_events e WHERE e.contact_id=c.id AND e.kind IN ('assigned','reclaimed')) past
                           FROM contacts c WHERE c.client_id=? AND c.stage_id IS NOT NULL AND c.id IN ($ph)$scope", array_merge([$cid], $chunk, $sp)) as $r) {
            $leads[] = ['id' => (int) $r['id'], 'owner' => $r['owner_user_id'] !== null ? (int) $r['owner_user_id'] : null,
                        'past' => array_map('intval', array_filter(explode(',', (string) $r['past']))), 'row' => $r];
        }
    }
    $plan = crm_transfer_plan($leads, $quota, !empty($o['smart']));
    if (!empty($plan['error'])) return ['ok' => false, 'error' => $plan['error']];

    $byId = array_column($leads, null, 'id');
    $stages = crm_stage_map($cid);
    $first = crm_first_stage($cid);
    $batch = date('ymdHis') . bin2hex(random_bytes(2));
    $reason = mb_substr(trim((string) ($o['reason'] ?? '')), 0, 255);
    $notes  = mb_substr(trim((string) ($o['notes'] ?? '')), 0, 1000);
    $dtype = (string) ($o['data_type'] ?? '');
    $opts = implode(',', array_filter([$history !== '' ? 'hide:' . $history : '', !empty($o['fresh_start']) ? 'fresh_start' : '',
                                       !empty($o['keep_followup']) ? 'kept_followup' : '', $dtype !== '' ? 'type:' . $dtype : '', !empty($o['smart']) ? 'smart' : '']));
    $count = [];
    foreach ($plan['plan'] as $leadId => $to) {
        $r = $byId[$leadId]['row'];
        $status = trim(($stages[(int) $r['stage_id']]['name'] ?? '') . (!empty($r['substatus']) ? ' · ' . $r['substatus'] : ''));
        crm_assign($client, $leadId, $to, $by);
        $set = []; $p = [];
        if ($dtype !== '' && isset(crm_data_types()[$dtype])) { $set[] = 'data_type=?'; $p[] = $dtype; }
        if (empty($o['keep_followup'])) $set[] = 'next_followup_at=NULL, followup_note=NULL';
        if (db_has_column('contacts', 'history_hide')) {
            if ($history !== '') { $set[] = 'history_hide=?, history_from=NOW()'; $p[] = $history; }
            else $set[] = 'history_hide=NULL, history_from=NULL';
            $set[] = 'prev_status=?'; $p[] = !empty($o['show_status']) && $history !== '' ? mb_substr($status, 0, 255) : null;
        }
        if ($set) db_run("UPDATE contacts SET " . implode(', ', $set) . " WHERE id=? AND client_id=?", array_merge($p, [$leadId, $cid]));
        if (!empty($o['fresh_start']) && $first && (int) $r['stage_id'] !== $first) crm_set_stage($client, $leadId, $first, $by);
        if (!empty($o['fresh_start'])) db_run("UPDATE contacts SET substatus=NULL WHERE id=?", [$leadId]);
        if (db_has_column('contacts', 'history_hide')) {
            db_insert("INSERT INTO crm_transfers (client_id,batch,contact_id,from_user_id,to_user_id,reason,notes,options,by_user_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())",
                      [$cid, $batch, $leadId, $r['owner_user_id'], $to, $reason ?: null, $notes ?: null, $opts ?: null, $by]);
        }
        if ($reason !== '' || $notes !== '') crm_log($cid, $leadId, 'transfer_note', null, mb_substr(trim($reason . ($reason !== '' && $notes !== '' ? ' — ' : '') . $notes), 0, 250), $by);
        $count[$to] = ($count[$to] ?? 0) + 1;
    }
    return ['ok' => true, 'moved' => count($plan['plan']), 'skipped' => count($plan['skipped']) + (count($ids) - count($leads)), 'by_user' => $count, 'batch' => $batch];
}

/** Hide the history from before the last transfer from this viewer? Admins always see everything. */
function crm_history_hidden_for(array $lead): ?string
{
    $h = (string) ($lead['history_hide'] ?? '');
    if ($h === '' || empty($lead['history_from']) || !function_exists('is_sales') || !is_sales()) return null;
    if ($h === 'leaders') return (string) $lead['history_from'];
    // 'sales': the salesperson who has it now; a team leader looking at a team member's lead still sees it.
    return (int) ($lead['owner_user_id'] ?? 0) === (int) crm_actor_id() ? (string) $lead['history_from'] : null;
}
