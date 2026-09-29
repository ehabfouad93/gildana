<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';

/**
 * The sales team at a glance, for managers: who holds what, who is behind, who is away — and
 * moving leads between people when someone leaves, goes on holiday, or is simply overloaded.
 */
$cid = (int) $CLIENT['id'];
$me  = (int) ($PERM_USER['id'] ?? 0);
if (!is_client_admin()) {
    http_response_code(403);
    client_header('Team & transfer', 'crm', $CLIENT);
    echo '<div class="card" style="max-width:560px"><h2 style="margin-top:0">For managers</h2><p class="text-muted">Only an Admin can see the team and move leads between people.</p></div>';
    layout_footer(); exit;
}
$stages = crm_stages($cid);
$people = crm_assignable_users($cid);
$names  = array_column($people, 'name', 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'avail') {
        $uid = (int) ($_POST['user'] ?? 0);
        if (isset($names[$uid])) {
            $cap = trim((string) ($_POST['capacity'] ?? ''));
            db_run("UPDATE users SET crm_available=?, crm_capacity=? WHERE id=? AND client_id=?",
                   [!empty($_POST['available']) ? 1 : 0, $cap !== '' && (int) $cap > 0 ? (int) $cap : null, $uid, $cid]);
            flash('Saved for ' . $names[$uid] . '.');
        }
        redirect('crm_team.php');
    }
    /* Teams: a leader, and the people under them. One team per person. */
    if ($a === 'team_save' || $a === 'team_add') {
        $tid  = (int) ($_POST['team'] ?? 0);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
        $lead = (int) ($_POST['leader'] ?? 0);
        $lead = isset($names[$lead]) ? $lead : null;
        if ($name === '') { flash('Give the team a name.', 'error'); redirect('crm_team.php#teams'); }
        if ($a === 'team_add') {
            $tid = db_insert("INSERT INTO crm_teams (client_id,name,leader_user_id,sort,created_at) VALUES (?,?,?,?,NOW())",
                             [$cid, $name, $lead, (int) db_val("SELECT COALESCE(MAX(sort),0)+10 FROM crm_teams WHERE client_id=?", [$cid])]);
        } elseif (db_val("SELECT COUNT(*) FROM crm_teams WHERE id=? AND client_id=?", [$tid, $cid])) {
            db_run("UPDATE crm_teams SET name=?, leader_user_id=? WHERE id=? AND client_id=?", [$name, $lead, $tid, $cid]);
        } else { redirect('crm_team.php#teams'); }
        $members = array_values(array_filter(array_map('intval', (array) ($_POST['members'] ?? [])), fn($u) => isset($names[$u])));
        db_run("UPDATE users SET team_id=NULL WHERE client_id=? AND team_id=?", [$cid, $tid]);
        if ($lead && !in_array($lead, $members, true)) $members[] = $lead;
        if ($members) db_run("UPDATE users SET team_id=? WHERE client_id=? AND id IN (" . implode(',', array_fill(0, count($members), '?')) . ")",
                             array_merge([$tid, $cid], $members));
        flash('Saved ' . $name . '.');
        redirect('crm_team.php#teams');
    }
    if ($a === 'team_delete') {
        $tid = (int) ($_POST['team'] ?? 0);
        db_run("UPDATE users SET team_id=NULL WHERE client_id=? AND team_id=?", [$cid, $tid]);
        db_run("DELETE FROM crm_teams WHERE id=? AND client_id=?", [$tid, $cid]);
        flash('Team removed. Its people keep their leads.');
        redirect('crm_team.php#teams');
    }
    if ($a === 'transfer') {
        $from = (string) ($_POST['from'] ?? '');
        $to   = (string) ($_POST['to'] ?? '');
        $fromId = $from === 'none' ? null : (int) $from;
        if ($from === '' || ($fromId !== null && !isset($names[$fromId]))) { flash('Choose whose leads to move.', 'error'); redirect('crm_team.php#transfer'); }
        if ($to === '' || (!in_array($to, ['rules', 'none'], true) && !isset($names[(int) $to]))) { flash('Choose where they go.', 'error'); redirect('crm_team.php#transfer'); }
        if ((string) $fromId === $to) { flash('Those are the same person.', 'error'); redirect('crm_team.php#transfer'); }
        $n = crm_transfer($CLIENT, $fromId, $to, ['scope' => (string) ($_POST['scope'] ?? 'open'), 'stage' => (int) ($_POST['stage'] ?? 0)], $me);
        $whereTo = $to === 'rules' ? 'shared out by your assignment rules' : ($to === 'none' ? 'left unassigned' : 'moved to ' . $names[(int) $to]);
        flash($n . ' lead' . ($n === 1 ? '' : 's') . ' ' . $whereTo . '.');
        redirect('crm_team.php#transfer');
    }
}

/* One row per person: what they hold and how they are doing with it. */
$s = crm_settings($cid);
$late = (int) ($s['first_contact_minutes'] ?? 0);
$rows = [];
$hasTeams = db_has_column('users', 'team_id');
foreach (db_all("SELECT id, COALESCE(NULLIF(name,''), email) name, client_role, crm_available, crm_capacity" . ($hasTeams ? ", team_id" : "") . " FROM users
                  WHERE client_id=? AND role='client' AND status='active' ORDER BY client_role='sales' DESC, name", [$cid]) as $u) {
    $rows[(int) $u['id']] = $u + ['open' => 0, 'new' => 0, 'late' => 0, 'overdue' => 0, 'hot' => 0, 'won30' => 0];
}
$q = fn(string $extra, array $p = []) => db_all("SELECT c.owner_user_id uid, COUNT(*) n FROM contacts c JOIN crm_stages s ON s.id=c.stage_id
                                                  WHERE c.client_id=? AND c.owner_user_id IS NOT NULL $extra GROUP BY c.owner_user_id", array_merge([$cid], $p));
foreach ([['open', "AND s.kind='open'", []],
          ['new', "AND s.kind='open' AND c.first_response_at IS NULL", []],
          ['overdue', "AND s.kind='open' AND c.next_followup_at < NOW()", []],
          ['hot', "AND s.kind='open' AND c.score >= 70", []],
          ['won30', "AND s.kind='won' AND c.id IN (SELECT e.contact_id FROM crm_events e WHERE e.kind='stage' AND e.created_at > NOW() - INTERVAL 30 DAY)", []]] as [$k, $sql, $p]) {
    foreach ($q($sql, $p) as $r) if (isset($rows[(int) $r['uid']])) $rows[(int) $r['uid']][$k] = (int) $r['n'];
}
if ($late) foreach ($q("AND s.kind='open' AND c.first_response_at IS NULL AND c.assigned_at < NOW() - INTERVAL ? MINUTE", [$late]) as $r)
    if (isset($rows[(int) $r['uid']])) $rows[(int) $r['uid']]['late'] = (int) $r['n'];
$unassigned = (int) db_val("SELECT COUNT(*) FROM contacts c JOIN crm_stages s ON s.id=c.stage_id WHERE c.client_id=? AND s.kind='open' AND c.owner_user_id IS NULL", [$cid]);
$hasSales = (bool) array_filter($rows, fn($r) => $r['client_role'] === 'sales');
$teams = crm_teams($cid);
$teamNames = array_column($teams, 'name', 'id');

client_header('Team & transfer', 'crm', $CLIENT);
page_head('Team & transfer', '<a class="btn btn-ghost btn-sm" href="crm_rules.php">Assignment rules</a>');
?>
<?php if (!$hasSales): ?>
  <div class="alert warn" style="font-size:13px">Nobody on this account has the <strong>Sales</strong> role, so new leads are not shared out
    and stay unassigned. Add salespeople on the <a href="team.php">Team</a> page with the Sales role.</div>
<?php endif; ?>
<?php if ($unassigned): ?>
  <div class="alert info" style="font-size:13px"><strong><?= $unassigned ?></strong> open lead<?= $unassigned === 1 ? ' has' : 's have' ?> no owner.
    <a href="crm.php?view=table&owner=none">See them</a> or share them out below.</div>
<?php endif; ?>

<div class="card card-flush">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Workload</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Someone switched to <strong>Away</strong> gets no new leads, and nobody gets more than their
      <strong>limit</strong> of open leads — the next person does. Their current leads stay with them until you move them.</p></div>
  <div class="table-wrap"><table class="data" id="workload">
    <thead><tr><th>Person</th><th class="num">Open</th><th class="num" title="Assigned and not contacted yet">Not contacted</th>
      <?php if ($late): ?><th class="num" title="Not contacted within <?= $late ?> minutes">Late</th><?php endif; ?>
      <th class="num">Overdue follow-ups</th><th class="num">Hot</th><th class="num">Won (30 days)</th><th>Taking leads</th></tr></thead><tbody>
    <?php foreach ($rows as $uid => $r): ?>
      <tr><td><a href="crm.php?view=table&owner=<?= $uid ?>"><strong><?= e((string) $r['name']) ?></strong></a>
          <span class="text-muted" style="display:block;font-size:11.5px"><?= e(ucfirst((string) $r['client_role'])) ?><?= !empty($r['team_id']) && isset($teamNames[(int) $r['team_id']]) ? ' · ' . e($teamNames[(int) $r['team_id']]) : '' ?></span></td>
        <td class="num"><?= $r['open'] ?></td>
        <td class="num"><?= $r['new'] ?></td>
        <?php if ($late): ?><td class="num <?= $r['late'] ? 'crm-late' : '' ?>"><?= $r['late'] ?></td><?php endif; ?>
        <td class="num <?= $r['overdue'] ? 'crm-late' : '' ?>"><?= $r['overdue'] ?></td>
        <td class="num"><?= $r['hot'] ?></td>
        <td class="num"><?= $r['won30'] ?></td>
        <td><form method="post" class="avail-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="avail"><input type="hidden" name="user" value="<?= $uid ?>">
            <label class="mod-all" style="margin:0"><input type="checkbox" name="available" value="1" <?= (int) $r['crm_available'] ? 'checked' : '' ?>
              onchange="this.form.submit()"> <?= (int) $r['crm_available'] ? 'Available' : 'Away' ?></label>
            <input type="number" name="capacity" min="1" placeholder="No limit" value="<?= $r['crm_capacity'] !== null ? (int) $r['crm_capacity'] : '' ?>"
                   aria-label="Most open leads at once" title="Most open leads at once" style="width:96px" onchange="this.form.submit()">
          </form></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>

<?php if ($hasTeams): ?>
<div class="card" id="teams">
  <h2>Teams</h2>
  <p class="text-muted" style="font-size:12.5px;margin-top:-4px">Group salespeople under a team leader. A leader sees their team's leads as well as
    their own, and can move leads between the people on their team. Reports and assignment rules can then work by team.</p>
  <?php foreach ($teams as $t): $tid = (int) $t['id']; ?>
    <form method="post" class="team-box">
      <?= csrf_field() ?><input type="hidden" name="action" value="team_save"><input type="hidden" name="team" value="<?= $tid ?>">
      <div class="grid2">
        <div class="field"><span class="lbl">Team name</span><input name="name" value="<?= e((string) $t['name']) ?>" maxlength="80" required></div>
        <div class="field"><span class="lbl">Team leader</span><select name="leader"><option value="0">—</option>
          <?php foreach ($rows as $uid => $r): ?><option value="<?= $uid ?>" <?= (int) $t['leader_user_id'] === $uid ? 'selected' : '' ?>><?= e((string) $r['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <span class="lbl">People on the team</span>
      <div class="team-members">
        <?php foreach ($rows as $uid => $r): $inOther = !empty($r['team_id']) && (int) $r['team_id'] !== $tid; ?>
          <label class="mod-all"><input type="checkbox" name="members[]" value="<?= $uid ?>" <?= (int) ($r['team_id'] ?? 0) === $tid ? 'checked' : '' ?>>
            <?= e((string) $r['name']) ?><?= $inOther ? ' <span class="text-muted">(' . e($teamNames[(int) $r['team_id']] ?? '') . ')</span>' : '' ?></label>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:10px;align-items:center" class="mt10">
        <button class="btn btn-primary btn-sm">Save team</button>
        <button class="btn-link" style="color:var(--danger)" name="action" value="team_delete" onclick="return confirm('Remove this team? Its people keep their leads.')">Remove team</button>
      </div>
    </form>
  <?php endforeach; ?>
  <details <?= $teams ? '' : 'open' ?>><summary class="btn btn-ghost btn-sm">+ New team</summary>
    <form method="post" class="team-box mt10">
      <?= csrf_field() ?><input type="hidden" name="action" value="team_add">
      <div class="grid2">
        <div class="field"><span class="lbl">Team name</span><input name="name" maxlength="80" placeholder="New Cairo team" required></div>
        <div class="field"><span class="lbl">Team leader</span><select name="leader"><option value="0">—</option>
          <?php foreach ($rows as $uid => $r): ?><option value="<?= $uid ?>"><?= e((string) $r['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <span class="lbl">People on the team</span>
      <div class="team-members">
        <?php foreach ($rows as $uid => $r): ?><label class="mod-all"><input type="checkbox" name="members[]" value="<?= $uid ?>"> <?= e((string) $r['name']) ?><?= !empty($r['team_id']) ? ' <span class="text-muted">(' . e($teamNames[(int) $r['team_id']] ?? '') . ')</span>' : '' ?></label><?php endforeach; ?>
      </div>
      <button class="btn btn-primary btn-sm mt10">Create team</button>
    </form></details>
</div>
<?php endif; ?>

<div class="card" id="transfer">
  <h2>Move leads</h2>
  <p class="text-muted" style="font-size:12.5px;margin-top:-4px">For when someone leaves, goes on holiday, or has too many. Each lead's history
    shows who moved it, and the new owner is notified.</p>
  <form method="post" class="grid2" onsubmit="return confirm('Move these leads now?')">
    <?= csrf_field() ?><input type="hidden" name="action" value="transfer">
    <div class="field"><span class="lbl">From</span><select name="from" required>
      <option value="">Choose…</option>
      <option value="none">Unassigned (<?= $unassigned ?>)</option>
      <?php foreach ($rows as $uid => $r): ?><option value="<?= $uid ?>"><?= e((string) $r['name']) ?> (<?= $r['open'] ?> open)</option><?php endforeach; ?></select></div>
    <div class="field"><span class="lbl">To</span><select name="to" required>
      <option value="">Choose…</option>
      <option value="rules">Share out by the assignment rules</option>
      <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e((string) $u['name']) ?></option><?php endforeach; ?>
      <option value="none">Nobody (unassign)</option></select></div>
    <div class="field"><span class="lbl">Which leads</span><select name="scope">
      <option value="open">All open leads</option>
      <option value="not_contacted">Only ones not contacted yet</option>
      <option value="all">Everything, including won and lost</option></select></div>
    <div class="field"><span class="lbl">Only in stage</span><select name="stage"><option value="0">Any stage</option>
      <?php foreach ($stages as $st): ?><option value="<?= (int) $st['id'] ?>"><?= e($st['name']) ?></option><?php endforeach; ?></select></div>
    <div><button class="btn btn-primary">Move leads</button></div>
  </form>
</div>
<?php layout_footer();
