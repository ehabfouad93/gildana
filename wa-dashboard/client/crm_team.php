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
foreach (db_all("SELECT id, COALESCE(NULLIF(name,''), email) name, client_role, crm_available, crm_capacity FROM users
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
          <span class="text-muted" style="display:block;font-size:11.5px"><?= e(ucfirst((string) $r['client_role'])) ?></span></td>
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
