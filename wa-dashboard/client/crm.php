<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';

/**
 * The pipeline: every lead as a board of stages or as a table, the same leads either way.
 *
 * Sales see only their own leads (crm_leads() applies the scope). Admins see everyone's and can
 * reassign. Viewers can look; the gate in _init.php refuses their writes, and the page hides the
 * controls so it does not offer them buttons that will say no.
 */
$cid     = (int) $CLIENT['id'];
$me      = (int) ($PERM_USER['id'] ?? 0);
$canEdit = can_write();
$isAdmin = is_client_admin();
$stages  = crm_stages($cid);
$stageMap = crm_stage_map($cid);
$people  = crm_assignable_users($cid);

/* ── AJAX ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');

    /** The lead ids in this request that the user may touch. Anything else is dropped silently. */
    $mine = function (array $ids) use ($cid): array {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        [$scope, $sp] = crm_scope('');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return array_map('intval', array_column(db_all(
            "SELECT id FROM contacts WHERE client_id=? AND stage_id IS NOT NULL AND id IN ($ph){$scope}",
            array_merge([$cid], $ids, $sp)), 'id'));
    };

    if ($a === 'stage') {
        $ids = $mine((array) ($_POST['ids'] ?? [$_POST['id'] ?? 0]));
        $sid = (int) ($_POST['stage_id'] ?? 0);
        if (!$ids || !isset($stageMap[$sid])) json_out(['ok' => false, 'error' => 'Nothing to move.']);
        $reason = trim((string) ($_POST['lost_reason'] ?? ''));
        if (crm_needs_lost_reason($cid, $sid) && $reason === '') json_out(['ok' => false, 'need_reason' => true]);
        foreach ($ids as $id) crm_set_stage($CLIENT, $id, $sid, $me, $reason !== '' ? $reason : null, (string) ($_POST['lost_note'] ?? ''));
        json_out(['ok' => true, 'n' => count($ids)]);
    }

    if ($a === 'assign') {
        // Reassigning is an Admin's call: a salesperson handing leads to a colleague, or taking
        // them, would bypass the fairness round-robin exists for.
        if (!$isAdmin) json_out(['ok' => false, 'error' => 'Only an Admin can reassign leads.']);
        $ids = $mine((array) ($_POST['ids'] ?? [$_POST['id'] ?? 0]));
        $to  = (string) ($_POST['user_id'] ?? '');
        foreach ($ids as $id) {
            // "Share out" goes through the assignment rules, as a new lead would.
            if ($to === 'auto') crm_assign($CLIENT, $id, crm_assign_next($CLIENT, db_row("SELECT * FROM contacts WHERE id=?", [$id])), $me);
            else crm_assign($CLIENT, $id, $to === '' || $to === 'none' ? null : (int) $to, $me);
        }
        json_out(['ok' => true, 'n' => count($ids)]);
    }

    if ($a === 'add') {
        $country = (string) ($CLIENT['default_country'] ?? '');
        $phone = normalize_phone((string) ($_POST['phone'] ?? ''), $country);
        if ($phone === '') json_out(['ok' => false, 'error' => 'Enter a valid phone number, with the country code or a local number.']);
        $name  = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'That email address does not look right.']);

        $existing = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $phone]);
        if ($existing && $existing['stage_id'] !== null) {
            // Already a lead. Say whose, so nobody creates a second one or quietly takes it over —
            // unless it is someone else's and this is a salesperson, who is told only that it exists.
            $who = crm_can_see($existing) ? ' (' . crm_user_name($existing['owner_user_id'] !== null ? (int) $existing['owner_user_id'] : null) . ')' : '';
            json_out(['ok' => false, 'error' => 'That number is already a lead' . $who . '.',
                      'id' => crm_can_see($existing) ? (int) $existing['id'] : 0]);
        }
        if ($existing) {
            db_run("UPDATE contacts SET name=COALESCE(NULLIF(?,''),name), email=COALESCE(NULLIF(?,''),email) WHERE id=?",
                   [$name, $email, (int) $existing['id']]);
            $contactId = (int) $existing['id'];
        } else {
            $contactId = db_insert("INSERT INTO contacts (client_id,phone_e164,name,email,opt_in_status,source,created_at)
                                    VALUES (?,?,?,?, 'in','manual',NOW())", [$cid, $phone, $name, $email !== '' ? $email : null]);
        }
        if (db_has_column('contacts', 'project_id')) {
            $pj = (int) ($_POST['project_id'] ?? 0);
            db_run("UPDATE contacts SET project_id=?, unit_type=NULLIF(?,''), budget=NULLIF(?,'') WHERE id=?",
                   [isset(crm_project_names($cid)[$pj]) ? $pj : null, mb_substr(trim((string) ($_POST['unit_type'] ?? '')), 0, 80),
                    mb_substr(trim((string) ($_POST['budget'] ?? '')), 0, 80), $contactId]);
        }
        $owner = is_sales() ? $me : (string) ($_POST['owner'] ?? 'auto');
        crm_add_lead($CLIENT, $contactId, 'manual', $owner === '' ? 'auto' : $owner, (int) ($_POST['stage_id'] ?? 0) ?: null, $me);

        $value = (string) ($_POST['deal_value'] ?? $_POST['value'] ?? '');
        $fu    = trim((string) ($_POST['followup'] ?? ''));
        db_run("UPDATE contacts SET deal_value=?, next_followup_at=? WHERE id=?",
               [crm_deal_value($value, $phone),
                $fu !== '' ? date('Y-m-d H:i:s', strtotime($fu)) : null, $contactId]);
        crm_add_note($CLIENT, $contactId, (string) ($_POST['note'] ?? ''), $me);
        json_out(['ok' => true, 'id' => $contactId, 'reused' => (bool) $existing]);
    }

    if ($a === 'stages') {
        if (!$isAdmin) json_out(['ok' => false, 'error' => 'Only an Admin can change the stages.']);
        $rows = json_decode((string) ($_POST['stages'] ?? '[]'), true) ?: [];
        $keep = [];
        foreach ($rows as $i => $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $kind = in_array($r['kind'] ?? '', ['open', 'won', 'lost'], true) ? $r['kind'] : 'open';
            if ($name === '') continue;
            $id = (int) ($r['id'] ?? 0);
            if ($id && isset($stageMap[$id])) {
                db_run("UPDATE crm_stages SET name=?, kind=?, sort=? WHERE id=? AND client_id=?", [mb_substr($name, 0, 80), $kind, ($i + 1) * 10, $id, $cid]);
            } else {
                $id = db_insert("INSERT INTO crm_stages (client_id,name,sort,kind,created_at) VALUES (?,?,?,?,NOW())", [$cid, mb_substr($name, 0, 80), ($i + 1) * 10, $kind]);
            }
            $keep[] = $id;
        }
        if (!array_filter($rows, fn($r) => ($r['kind'] ?? 'open') === 'open' && trim((string) ($r['name'] ?? '')) !== '')) {
            json_out(['ok' => false, 'error' => 'Keep at least one open stage — new leads need somewhere to land.']);
        }
        // A removed stage's leads move to the first open one rather than vanishing from the board.
        $gone = array_diff(array_keys($stageMap), $keep);
        if ($gone) {
            $first = crm_first_stage($cid);
            foreach ($gone as $g) {
                if ((int) $g === $first) continue;
                foreach (db_all("SELECT id FROM contacts WHERE client_id=? AND stage_id=?", [$cid, (int) $g]) as $l) {
                    crm_set_stage($CLIENT, (int) $l['id'], $first, $me);
                }
                db_run("DELETE FROM crm_stages WHERE id=? AND client_id=?", [(int) $g, $cid]);
            }
        }
        json_out(['ok' => true]);
    }
    json_out(['ok' => false]);
}

$f = [
    'q'      => trim((string) ($_GET['q'] ?? '')),
    'stage'  => (int) ($_GET['stage'] ?? 0) ?: '',
    'owner'  => (string) ($_GET['owner'] ?? ''),
    'source' => (string) ($_GET['source'] ?? ''),
    'due'    => (string) ($_GET['due'] ?? ''),
    'project'=> (int) ($_GET['project'] ?? 0) ?: '',
    'heat'   => (string) ($_GET['heat'] ?? ''),
    'status' => (string) ($_GET['status'] ?? ''),
    'sort'   => (string) ($_GET['sort'] ?? ''),
];
$projects = crm_projects($cid);
$pnames   = array_column($projects, 'name', 'id');
$alerts   = crm_alert_counts($CLIENT, is_sales() ? $me : null);
$lostIds  = array_map('intval', array_keys(array_filter($stageMap, fn($st) => $st['kind'] === 'lost')));
$askLost  = (int) crm_settings($cid)['require_lost_reason'] === 1;
if (is_sales()) $f['owner'] = '';                       // scoped already; the filter would only confuse
$leads = crm_leads($cid, $f);
$byStage = [];
foreach ($leads as $l) $byStage[(int) $l['stage_id']][] = $l;
$view = ($_GET['view'] ?? '') === 'table' ? 'table' : 'board';
$sources = array_column(db_all("SELECT DISTINCT source FROM contacts WHERE client_id=? AND stage_id IS NOT NULL AND source IS NOT NULL", [$cid]), 'source');
$overdue = count(array_filter($leads, fn($l) => $l['next_followup_at'] && strtotime((string) $l['next_followup_at']) < time() && $l['stage_kind'] === 'open'));

$actions = '';
if ($canEdit) $actions .= '<button class="btn btn-primary btn-sm" onclick="crmAdd()">+ Add lead</button>';
if ($canEdit && can_crm('import')) $actions .= '<a class="btn btn-ghost btn-sm" href="crm_import.php">Import</a>';
if (can_crm('reports')) $actions .= '<a class="btn btn-ghost btn-sm" href="crm_reports.php">Reports</a>';
if ($isAdmin && can_crm('forms')) $actions .= '<a class="btn btn-ghost btn-sm" href="meta_leads.php">Lead forms</a>';
if ($isAdmin) $actions .= '<button class="btn btn-ghost btn-sm" onclick="crmStages()">Stages</button>';

client_header('CRM', 'crm', $CLIENT);
page_head('CRM', $actions);

function crm_initials(string $n): string {
    $p = preg_split('/\s+/u', trim($n)) ?: [];
    return mb_strtoupper(mb_substr($p[0] ?? '?', 0, 1) . mb_substr($p[1] ?? '', 0, 1));
}
function crm_money($v): string { return $v !== null && $v !== '' ? number_format((float) $v) : ''; }
function crm_when(?string $d): string {
    if (!$d) return '';
    $t = strtotime($d); $days = (int) floor((strtotime(date('Y-m-d', $t)) - strtotime(date('Y-m-d'))) / 86400);
    return $days === 0 ? 'Today' : ($days === 1 ? 'Tomorrow' : ($days === -1 ? 'Yesterday' : date('j M', $t)));
}
?>
<form class="crm-bar" method="get">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search name, phone or email">
  <select name="stage"><option value="">All stages</option>
    <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $f['stage'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
  <?php if (!is_sales()): ?>
  <select name="owner"><option value="">Everyone</option><option value="none" <?= $f['owner'] === 'none' ? 'selected' : '' ?>>Unassigned</option>
    <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $f['owner'] === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <select name="source"><option value="">Any source</option>
    <?php foreach ($sources as $src): ?><option value="<?= e($src) ?>" <?= $f['source'] === $src ? 'selected' : '' ?>><?= e(crm_source_label($src)) ?></option><?php endforeach; ?></select>
  <select name="due"><option value="">Any follow-up</option>
    <option value="today" <?= $f['due'] === 'today' ? 'selected' : '' ?>>Due today</option>
    <option value="overdue" <?= $f['due'] === 'overdue' ? 'selected' : '' ?>>Overdue</option></select>
  <?php if ($projects): ?>
  <select name="project"><option value="">Any project</option>
    <?php foreach ($projects as $pj): ?><option value="<?= (int) $pj['id'] ?>" <?= (int) $f['project'] === (int) $pj['id'] ? 'selected' : '' ?>><?= e($pj['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <select name="heat"><option value="">Hot, warm and cold</option>
    <?php foreach (['hot' => 'Hot only', 'warm' => 'Warm only', 'cold' => 'Cold only'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['heat'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <select name="status"><option value="">Any status</option>
    <?php foreach (['not_contacted' => 'Not contacted yet', 'no_followup' => 'No follow-up planned', 'again' => 'Came in more than once'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <select name="sort" aria-label="Order"><option value="">Next follow-up first</option>
    <?php foreach (['hot' => 'Hottest first', 'newest' => 'Newest first', 'value' => 'Biggest value first'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['sort'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <button class="btn btn-ghost btn-sm">Filter</button>
  <span class="crm-view">
    <a href="?<?= e(http_build_query(['view' => 'board'] + $f)) ?>" class="<?= $view === 'board' ? 'on' : '' ?>">Board</a>
    <a href="?<?= e(http_build_query(['view' => 'table'] + $f)) ?>" class="<?= $view === 'table' ? 'on' : '' ?>">Table</a>
  </span>
</form>

<?php if ($alerts['late'] && $f['status'] !== 'not_contacted'): ?>
  <div class="alert error" style="font-size:12.5px" id="crm-late"><strong><?= $alerts['late'] ?></strong> lead<?= $alerts['late'] === 1 ? ' has' : 's have' ?> not been contacted in time.
    <a href="?<?= e(http_build_query(['view' => 'table', 'status' => 'not_contacted', 'sort' => 'newest'])) ?>">Show them</a></div>
<?php endif; ?>
<?php if (!is_sales() && $alerts['unassigned'] && $f['owner'] !== 'none'): ?>
  <div class="alert info" style="font-size:12.5px"><?= $alerts['unassigned'] ?> open lead<?= $alerts['unassigned'] === 1 ? ' has' : 's have' ?> no owner.
    <a href="?<?= e(http_build_query(['view' => 'table', 'owner' => 'none'])) ?>">Show them</a><?= $isAdmin ? ' · <a href="crm_team.php#transfer">Share them out</a>' : '' ?></div>
<?php endif; ?>
<?php if ($overdue && $f['due'] !== 'overdue'): ?>
  <div class="alert warn" style="font-size:12.5px"><?= $overdue ?> follow-up<?= $overdue === 1 ? ' is' : 's are' ?> overdue.
    <a href="?<?= e(http_build_query(['view' => $view, 'due' => 'overdue'])) ?>">Show them</a></div>
<?php endif; ?>

<?php if (!$leads): ?>
  <div class="card empty-state">
    <h2><?= array_filter($f) ? 'No leads match those filters' : (is_sales() ? 'No leads assigned to you yet' : 'No leads yet') ?></h2>
    <p class="text-muted"><?= is_sales()
        ? 'New conversations are shared out between the sales team automatically. Yours will appear here.'
        : 'Leads arrive here by themselves when someone messages you. You can also add them by hand or import a spreadsheet.' ?></p>
  </div>
<?php elseif ($view === 'board'): ?>
  <div class="crm-board" id="crm-board">
    <?php foreach ($stages as $s): $col = $byStage[(int) $s['id']] ?? [];
      $sum = array_sum(array_map(fn($l) => (float) $l['deal_value'], $col)); ?>
      <section class="crm-col crm-<?= e($s['kind']) ?>" data-stage="<?= (int) $s['id'] ?>">
        <header><strong><?= e($s['name']) ?></strong> <span class="crm-n"><?= count($col) ?></span>
          <?php if ($sum > 0): ?><span class="crm-sum"><?= crm_money($sum) ?></span><?php endif; ?></header>
        <div class="crm-cards">
          <?php foreach ($col as $l):
            $due  = $l['next_followup_at'] && $l['stage_kind'] === 'open' ? strtotime((string) $l['next_followup_at']) : 0;
            $late = $due && $due < time(); ?>
            <article class="crm-card" draggable="<?= $canEdit ? 'true' : 'false' ?>" data-id="<?= (int) $l['id'] ?>">
              <a class="crm-card-name" href="crm_lead.php?id=<?= (int) $l['id'] ?>"><?= e((string) ($l['name'] ?: '+' . $l['phone_e164'])) ?></a>
              <?php if ($l['name']): ?><div class="crm-card-phone">+<?= e((string) $l['phone_e164']) ?></div><?php endif; ?>
              <?php [$hc, $hw] = crm_heat(isset($l['score']) && $l['score'] !== null ? (int) $l['score'] : null);
                    $notYet = $l['stage_kind'] === 'open' && $l['owner_user_id'] !== null && empty($l['first_response_at']);
                    $proj = !empty($l['project_id']) ? ($pnames[(int) $l['project_id']] ?? '') : ''; ?>
              <?php if ($hc || $proj || $notYet || (int) ($l['submissions'] ?? 1) > 1): ?>
              <div class="crm-card-tags">
                <?php if ($hc && $l['stage_kind'] === 'open'): ?><span class="heat <?= $hc ?>"><?= $hw ?></span><?php endif; ?>
                <?php if ($proj): ?><span class="crm-flag"><?= e($proj) ?></span><?php endif; ?>
                <?php if ((int) ($l['submissions'] ?? 1) > 1): ?><span class="crm-flag">×<?= (int) $l['submissions'] ?></span><?php endif; ?>
                <?php if ($notYet): ?><span class="crm-flag late">Not contacted</span><?php endif; ?>
              </div>
              <?php endif; ?>
              <?php if ($l['last_body']): ?><div class="crm-card-last"><?= e(mb_substr((string) $l['last_body'], 0, 70)) ?></div><?php endif; ?>
              <div class="crm-card-foot">
                <?php if ($l['deal_value'] !== null): ?><span class="crm-val"><?= crm_money($l['deal_value']) ?></span><?php endif; ?>
                <?php if ($due): ?><span class="crm-due <?= $late ? 'late' : '' ?>" title="Next follow-up"><?= e(crm_when((string) $l['next_followup_at'])) ?></span><?php endif; ?>
                <span class="crm-owner" title="<?= e((string) ($l['owner_name'] ?? 'Unassigned')) ?>"><?= $l['owner_name'] ? e(crm_initials((string) $l['owner_name'])) : '—' ?></span>
              </div>
              <?php if ($canEdit): ?>
                <select class="crm-move" aria-label="Move to stage" onchange="crmMove([<?= (int) $l['id'] ?>], this.value)">
                  <?php foreach ($stages as $s2): ?><option value="<?= (int) $s2['id'] ?>" <?= (int) $s2['id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s2['name']) ?></option><?php endforeach; ?>
                </select>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <?php if ($canEdit): ?>
  <div class="crm-bulk" id="crm-bulk" hidden>
    <span id="crm-bulk-n"></span>
    <select id="crm-bulk-stage"><option value="">Move to stage…</option>
      <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
    <?php if ($isAdmin): ?>
    <select id="crm-bulk-owner"><option value="">Assign to…</option><option value="auto">Share out by the assignment rules</option><option value="none">Nobody</option>
      <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="card card-flush"><div class="table-wrap">
    <table class="data">
      <thead><tr>
        <?php if ($canEdit): ?><th style="width:28px"><input type="checkbox" id="crm-all" aria-label="Select all"></th><?php endif; ?>
        <th>Lead</th><th>Stage</th><th>Heat</th><?php if ($projects): ?><th>Project</th><?php endif; ?><?php if (!is_sales()): ?><th>Owner</th><?php endif; ?><th>Value</th><th>Follow-up</th><th>Source</th><th>Last activity</th></tr></thead>
      <tbody>
      <?php foreach ($leads as $l):
        $late = $l['next_followup_at'] && strtotime((string) $l['next_followup_at']) < time() && $l['stage_kind'] === 'open'; ?>
        <tr>
          <?php if ($canEdit): ?><td><input type="checkbox" class="crm-pick" value="<?= (int) $l['id'] ?>"></td><?php endif; ?>
          <td><a href="crm_lead.php?id=<?= (int) $l['id'] ?>"><strong><?= e((string) ($l['name'] ?: '+' . $l['phone_e164'])) ?></strong></a>
            <span class="text-muted d-block">+<?= e((string) $l['phone_e164']) ?></span></td>
          <td><span class="pill <?= $l['stage_kind'] === 'won' ? 'green' : ($l['stage_kind'] === 'lost' ? 'red' : 'gray') ?>"><?= e((string) $l['stage_name']) ?></span>
            <?php if ($l['stage_kind'] === 'lost' && !empty($l['lost_reason'])): ?><span class="text-muted" style="display:block;font-size:11.5px"><?= e((string) $l['lost_reason']) ?></span><?php endif; ?></td>
          <td><?php [$hc, $hw] = crm_heat(isset($l['score']) && $l['score'] !== null ? (int) $l['score'] : null);
                if ($hc && $l['stage_kind'] === 'open'): ?><span class="heat <?= $hc ?>"><?= $hw ?> · <?= (int) $l['score'] ?></span><?php endif; ?></td>
          <?php if ($projects): ?><td><?= e((string) ($pnames[(int) ($l['project_id'] ?? 0)] ?? '')) ?><?= !empty($l['unit_type']) ? '<span class="text-muted" style="display:block;font-size:11.5px">' . e((string) $l['unit_type']) . '</span>' : '' ?></td><?php endif; ?>
          <?php if (!is_sales()): ?><td><?= $l['owner_name'] ? e((string) $l['owner_name']) : '<span class="text-muted">Unassigned</span>' ?></td><?php endif; ?>
          <td class="num"><?= crm_money($l['deal_value']) ?></td>
          <td class="<?= $late ? 'crm-late' : '' ?>"><?= e(crm_when($l['next_followup_at'])) ?></td>
          <td class="text-muted"><?= e(crm_source_label($l['source'])) ?></td>
          <td class="text-muted"><?= $l['last_at'] ? e(date('j M, H:i', strtotime((string) $l['last_at']))) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="modal-back" id="m-add">
  <form class="modal" id="add-form" onsubmit="return crmAddSave(event)">
    <h2>Add a lead</h2>
    <div class="grid2">
      <div class="field"><span class="lbl">Name</span><input name="name" placeholder="Ahmed Mahmoud"></div>
      <div class="field"><span class="lbl">Phone *</span><input name="phone" type="tel" autocomplete="tel" required placeholder="01001234567 or +201001234567"></div>
      <div class="field"><span class="lbl">Email</span><input name="email" type="email"></div>
      <div class="field"><span class="lbl">Deal value</span><input name="deal_value" inputmode="decimal" autocomplete="off" placeholder="5,000,000"></div>
      <div class="field"><span class="lbl">Stage</span><select name="stage_id">
        <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <?php if (!is_sales()): ?>
      <div class="field"><span class="lbl">Owner</span><select name="owner">
        <option value="auto">Share out (next in rotation)</option><option value="none">Leave unassigned</option>
        <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="field"><span class="lbl">Next follow-up</span><input name="followup" type="datetime-local"></div>
      <?php if ($projects): ?>
      <div class="field"><span class="lbl">Project</span><select name="project_id"><option value="0">—</option>
        <?php foreach ($projects as $pj): if (!(int) $pj['active']) continue; ?><option value="<?= (int) $pj['id'] ?>"><?= e($pj['name']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="field"><span class="lbl">Unit type</span><select name="unit_type"><option value="">—</option>
        <?php foreach (crm_options($cid, 'unit_type') as $u): ?><option><?= e($u) ?></option><?php endforeach; ?></select></div>
      <div class="field"><span class="lbl">Budget</span><input name="budget" maxlength="80" placeholder="5–7M"></div>
    </div>
    <div class="field"><span class="lbl">Note</span><textarea name="note" rows="2" placeholder="What they asked about, budget, timing…"></textarea></div>
    <div class="alert error" id="add-err" hidden></div>
    <div class="row-between mt10"><span></span><span style="display:flex;gap:8px">
      <button type="button" class="btn btn-ghost" onclick="$m('m-add').classList.remove('open')">Cancel</button>
      <button class="btn btn-primary">Add lead</button></span></div>
  </form>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="modal-back" id="m-stages">
  <div class="modal">
    <h2>Stages</h2>
    <p class="text-muted" style="font-size:12.5px;margin-top:-6px">Drag to reorder. <strong>Won</strong> and
      <strong>Lost</strong> close a lead and drive the conversion figures in Reports, whatever you name them.
      Removing a stage moves its leads to the first open stage.</p>
    <div id="st-list"></div>
    <button type="button" class="btn btn-ghost btn-sm" onclick="stAdd()">+ Add stage</button>
    <div class="alert error" id="st-err" hidden></div>
    <div class="row-between mt10"><span></span><span style="display:flex;gap:8px">
      <button type="button" class="btn btn-ghost" onclick="$m('m-stages').classList.remove('open')">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="stSave()">Save stages</button></span></div>
  </div>
</div>
<?php endif; ?>

<?php if ($canEdit && $askLost && $lostIds): ?>
<dialog id="lost-dlg" class="lost-dlg" aria-labelledby="lost-title">
  <form onsubmit="return lostSave(event)">
    <h2 id="lost-title" style="margin-top:0">Why <span id="lost-what">was this lead</span> lost?</h2>
    <div class="lost-reasons">
      <?php foreach (crm_options($cid, 'lost_reason') as $r): ?><label class="act-kind"><input type="radio" name="lost_reason" value="<?= e($r) ?>" required><span><?= e($r) ?></span></label><?php endforeach; ?>
    </div>
    <div class="field"><span class="lbl">Anything to add (optional)</span><input name="lost_note" maxlength="255"></div>
    <div style="display:flex;gap:8px"><button class="btn btn-primary">Mark as lost</button>
      <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close(); location.reload()">Cancel</button></div>
  </form>
</dialog>
<?php endif; ?>
<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const STAGES = <?= json_encode(array_map(fn($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'kind' => $s['kind']], $stages)) ?>;
const $m = id => document.getElementById(id);

async function crmPost(data){
  const fd = new FormData(); fd.append('ajax','1'); fd.append('csrf_token', CSRF);
  for (const [k,v] of Object.entries(data)) (Array.isArray(v) ? v : [v]).forEach(x => fd.append(Array.isArray(v) ? k+'[]' : k, x));
  const r = await fetch('crm.php', {method:'POST', body:fd});
  return r.json().catch(() => ({ok:false, error:'Something went wrong.'}));
}
async function crmMove(ids, stageId, extra){
  const d = await crmPost(Object.assign({action:'stage', ids, stage_id:stageId}, extra || {}));
  if (d.need_reason && $m('lost-dlg')) {
    // Moving to Lost asks why first; the move happens when the reason is given.
    LOST_PENDING = {ids, stageId};
    $m('lost-what').textContent = ids.length === 1 ? 'was this lead' : 'were these ' + ids.length + ' leads';
    $m('lost-dlg').querySelector('form').reset(); $m('lost-dlg').showModal(); return;
  }
  if (d.ok) location.reload(); else alert(d.error || 'Could not move it.');
}
let LOST_PENDING = null;
function lostSave(e){
  e.preventDefault();
  const fd = new FormData(e.target);
  crmMove(LOST_PENDING.ids, LOST_PENDING.stageId, {lost_reason: fd.get('lost_reason'), lost_note: fd.get('lost_note') || ''});
  return false;
}

/* Board drag and drop. Cards also carry a stage <select>, because touch screens do not drag. */
document.querySelectorAll('.crm-card[draggable="true"]').forEach(c => {
  c.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', c.dataset.id); c.classList.add('dragging'); });
  c.addEventListener('dragend', () => c.classList.remove('dragging'));
});
document.querySelectorAll('.crm-col').forEach(col => {
  col.addEventListener('dragover', e => { e.preventDefault(); col.classList.add('over'); });
  col.addEventListener('dragleave', () => col.classList.remove('over'));
  col.addEventListener('drop', e => {
    e.preventDefault(); col.classList.remove('over');
    const id = e.dataTransfer.getData('text/plain');
    if (id) crmMove([id], col.dataset.stage);
  });
});

/* Table bulk actions. */
const picks = () => [...document.querySelectorAll('.crm-pick:checked')].map(c => c.value);
function bulkShow(){ const n = picks().length; const b = $m('crm-bulk'); if (!b) return;
  b.hidden = !n; $m('crm-bulk-n').textContent = n + ' selected'; }
document.querySelectorAll('.crm-pick').forEach(c => c.addEventListener('change', bulkShow));
$m('crm-all')?.addEventListener('change', e => { document.querySelectorAll('.crm-pick').forEach(c => c.checked = e.target.checked); bulkShow(); });
$m('crm-bulk-stage')?.addEventListener('change', e => { if (e.target.value) crmMove(picks(), e.target.value); });
$m('crm-bulk-owner')?.addEventListener('change', async e => {
  if (!e.target.value) return;
  const d = await crmPost({action:'assign', ids:picks(), user_id:e.target.value});
  if (d.ok) location.reload(); else alert(d.error || 'Could not reassign.');
});

function crmAdd(){ $m('add-form').reset(); $m('add-err').hidden = true; $m('m-add').classList.add('open'); }
async function crmAddSave(e){
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target)); data.action = 'add';
  const d = await crmPost(data);
  if (d.ok) { location.href = 'crm_lead.php?id=' + d.id; return false; }
  $m('add-err').hidden = false;
  $m('add-err').innerHTML = (d.error || 'Could not add the lead.') + (d.id ? ` <a href="crm_lead.php?id=${d.id}">Open it</a>` : '');
  return false;
}

/* Stage editor. */
function stRow(s){
  const div = document.createElement('div'); div.className = 'st-row'; div.draggable = true; div.dataset.id = s.id || '';
  div.innerHTML = `<span class="st-grip" aria-hidden="true">≡</span>
    <input class="st-name" value="${(s.name||'').replace(/"/g,'&quot;')}" placeholder="Stage name">
    <select class="st-kind"><option value="open">Open</option><option value="won">Won</option><option value="lost">Lost</option></select>
    <button type="button" class="icon-btn" title="Remove" onclick="this.parentNode.remove()">✕</button>`;
  div.querySelector('.st-kind').value = s.kind || 'open';
  div.addEventListener('dragstart', () => div.classList.add('dragging'));
  div.addEventListener('dragend', () => div.classList.remove('dragging'));
  return div;
}
function crmStages(){
  const list = $m('st-list'); list.innerHTML = '';
  STAGES.forEach(s => list.appendChild(stRow(s)));
  list.ondragover = e => { e.preventDefault(); const drag = list.querySelector('.dragging');
    const after = [...list.querySelectorAll('.st-row:not(.dragging)')].find(r => e.clientY < r.getBoundingClientRect().top + r.offsetHeight/2);
    after ? list.insertBefore(drag, after) : list.appendChild(drag); };
  $m('st-err').hidden = true; $m('m-stages').classList.add('open');
}
function stAdd(){ $m('st-list').appendChild(stRow({name:'', kind:'open'})); }
async function stSave(){
  const stages = [...document.querySelectorAll('#st-list .st-row')].map(r => ({
    id: r.dataset.id, name: r.querySelector('.st-name').value, kind: r.querySelector('.st-kind').value }));
  const d = await crmPost({action:'stages', stages: JSON.stringify(stages)});
  if (d.ok) location.href = 'crm.php'; else { $m('st-err').hidden = false; $m('st-err').textContent = d.error || 'Could not save.'; }
}
<?php if ($isAdmin && !empty($_GET['stages'])): ?>crmStages();   // opened from the CRM menu → Stages<?php endif; ?>
</script>
<?php layout_footer();
