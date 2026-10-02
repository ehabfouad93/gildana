<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Advanced search for everyone. Finds a client by number (#12 — the same on
 * every stage, the contract and the statement), contract number, any phone in
 * any format, national ID or name, with optional filters. Results are limited
 * to the clients this person is allowed to open.
 */
$q       = trim((string) ($_GET['q'] ?? ''));
$stage   = (string) ($_GET['stage'] ?? '');
$sales   = (int) ($_GET['sales'] ?? 0);
$project = (int) ($_GET['project'] ?? 0);
$from    = valid_date($_GET['from'] ?? '');
$to      = valid_date($_GET['to'] ?? '');
$dateBy  = ($_GET['date_by'] ?? '') === 'meeting' ? 'meeting' : 'created';
$active  = $q !== '' || $stage !== '' || $sales || $project || $from || $to;

$rows = [];
if ($active) {
    $where = ['1=1']; $params = [];
    if ($q !== '') { [$sw, $sp] = client_search_sql($q); $where[] = $sw; $params = array_merge($params, $sp); }
    if (in_array($stage, STAGES, true)) { $where[] = 'c.stage = ?'; $params[] = $stage; }
    if ($sales)   { $where[] = 'c.sales_id = ?'; $params[] = $sales; }
    if ($project) { $where[] = 'ct.project_id = ?'; $params[] = $project; }
    $col = $dateBy === 'meeting' ? 'c.meeting_date' : 'DATE(c.created_at)';
    if ($from) { $where[] = "$col >= ?"; $params[] = $from; }
    if ($to)   { $where[] = "$col <= ?"; $params[] = $to; }

    $found = db_all(
        "SELECT c.*, a.name AS advisor_name, s.name AS sales_name, ct.id AS contract_id, ct.contract_no, ct.official_name, ct.national_id
           FROM clients c
           LEFT JOIN contracts ct ON ct.client_id = c.id
           LEFT JOIN users a ON a.id = c.created_by
           LEFT JOIN users s ON s.id = c.sales_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY c.id DESC LIMIT 500", $params);
    foreach ($found as $r) {
        if (can_view_client($ME, $r)) $rows[] = $r;
        if (count($rows) >= 200) break;
    }
    // A precise hit (number, contract, phone) goes straight to the profile.
    if (count($rows) === 1 && $q !== '' && !$stage && !$sales && !$project && !$from && !$to && ($_GET['list'] ?? '') !== '1') {
        redirect('client.php?id=' . (int) $rows[0]['id']);
    }
}

$stageOpts = [];
foreach (STAGES as $s) $stageOpts[$s] = t('stage.' . $s);
$salesOpts = [];
foreach (users_by_role('sales') as $u) $salesOpts[$u['id']] = $u['name'];
$projOpts = [];
foreach (db_all("SELECT id, name FROM projects ORDER BY name") as $p) $projOpts[$p['id']] = $p['name'];

layout_header(t('nav.search'), 'search');
page_head(t('nav.search'), t('search.sub'));
?>
<form method="get" class="card search-card">
  <div class="search-main">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('search.placeholder')) ?>" autofocus>
    <button class="btn btn-primary" type="submit"><?= e(t('ui.search')) ?></button>
  </div>
  <p class="hint-line"><?= e(t('search.hint')) ?></p>
  <details class="search-more" <?= ($stage !== '' || $sales || $project || $from || $to) ? 'open' : '' ?>>
    <summary><?= e(t('search.filters')) ?></summary>
    <div class="grid3">
      <div class="field"><span class="lbl"><?= e(t('client.stage')) ?></span><select name="stage"><?= options($stageOpts, $stage) ?></select></div>
      <div class="field"><span class="lbl"><?= e(t('role.sales')) ?></span><select name="sales"><?= options($salesOpts, $sales ?: '') ?></select></div>
      <div class="field"><span class="lbl"><?= e(t('contract.project')) ?></span><select name="project"><?= options($projOpts, $project ?: '') ?></select></div>
      <div class="field"><span class="lbl"><?= e(t('search.date_by')) ?></span>
        <select name="date_by"><?= options(['created' => t('client.added'), 'meeting' => t('meeting.date')], $dateBy, false) ?></select></div>
      <div class="field"><span class="lbl"><?= e(t('search.from')) ?></span><input type="date" name="from" value="<?= e($from ?? '') ?>"></div>
      <div class="field"><span class="lbl"><?= e(t('search.to')) ?></span><input type="date" name="to" value="<?= e($to ?? '') ?>"></div>
    </div>
    <input type="hidden" name="list" value="1">
  </details>
</form>

<?php if ($active): ?>
<div class="card card-flush">
  <div class="card-head"><h2><?= e(t('ui.n_results', ['n' => (string) count($rows)])) ?></h2></div>
  <?php if (!$rows): ?><div class="empty"><?= e(t('search.none')) ?></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th><?= e(t('search.client_no')) ?></th><th><?= e(t('client.full_name')) ?></th><th><?= e(t('client.phone')) ?></th>
      <th><?= e(t('client.stage')) ?></th><th><?= e(t('contract.no')) ?></th><th><?= e(t('role.sales')) ?></th><th><?= e(t('client.added')) ?></th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono strong"><?= e(client_code((int) $r['id'])) ?></td>
        <td><a class="strong" href="client.php?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a>
          <?php if ($r['official_name'] && $r['official_name'] !== $r['full_name']): ?><div class="small text-muted"><?= e($r['official_name']) ?></div><?php endif; ?></td>
        <td dir="ltr" class="nowrap"><?= e($r['phone']) ?><?= $r['phone2'] ? '<div class="small text-muted">' . e($r['phone2']) . '</div>' : '' ?></td>
        <td><?= stage_pill($r['stage']) ?></td>
        <td class="mono nowrap"><?= e($r['contract_no'] ?? '—') ?></td>
        <td><?= e($r['sales_name'] ?? '—') ?></td>
        <td class="nowrap text-muted"><?= e(fmt_date($r['created_at'])) ?></td>
        <td><a class="btn btn-sm" href="client.php?id=<?= (int) $r['id'] ?>"><?= e(t('ui.open')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_footer();
