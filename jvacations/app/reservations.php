<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/** Every reservation (contract) with its collection position. Accountant + owner services. */
require_role('accountant', 'owner_services');

$q       = trim((string) ($_GET['q'] ?? ''));
$project = (int) ($_GET['project'] ?? 0);
$filter  = (string) ($_GET['f'] ?? '');

$where  = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = "(ct.contract_no LIKE ? OR ct.official_name LIKE ? OR c.full_name LIKE ? OR c.phone LIKE ? OR ct.national_id LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($project) { $where[] = 'ct.project_id = ?'; $params[] = $project; }
$having = '';
if ($filter === 'overdue') $having = 'HAVING overdue > 0';
if ($filter === 'settled') $having = 'HAVING paid >= total';

$rows = db_all(
    "SELECT ct.id, ct.contract_no, ct.contract_date, ct.official_name, ct.currency, ct.months, ct.total_amount,
            c.id AS client_id, c.phone, p.name AS project_name, u.name AS sales_name,
            SUM(i.amount) AS total, SUM(i.paid_amount) AS paid,
            SUM(CASE WHEN i.status <> 'paid' AND i.due_date < CURDATE() THEN i.amount - i.paid_amount ELSE 0 END) AS overdue,
            MIN(CASE WHEN i.status <> 'paid' THEN i.due_date END) AS next_due
       FROM contracts ct
       JOIN clients c ON c.id = ct.client_id
       JOIN projects p ON p.id = ct.project_id
       LEFT JOIN users u ON u.id = ct.created_by
       LEFT JOIN instalments i ON i.contract_id = ct.id
      WHERE " . implode(' AND ', $where) . "
      GROUP BY ct.id
      $having
      ORDER BY ct.contract_date DESC, ct.id DESC
      LIMIT 1000", $params);

$sum = ['total' => 0.0, 'paid' => 0.0, 'overdue' => 0.0];
foreach ($rows as $r) {
    $sum['total'] += (float) $r['total'];
    $sum['paid'] += (float) $r['paid'];
    $sum['overdue'] += (float) $r['overdue'];
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reservations-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map('csv_cell', [t('contract.no'), t('contract.date'), t('contract.official_name'), t('client.phone'), t('contract.project'), t('contract.total'), t('res.paid'), t('res.remaining'), t('res.overdue'), t('res.next_due')])) . "\n";
    foreach ($rows as $r) {
        echo implode(',', array_map('csv_cell', [$r['contract_no'], $r['contract_date'], $r['official_name'], $r['phone'], $r['project_name'],
            $r['total'], $r['paid'], (string) ((float) $r['total'] - (float) $r['paid']), $r['overdue'], $r['next_due']])) . "\n";
    }
    exit;
}

$projects = [];
foreach (db_all("SELECT id, name FROM projects ORDER BY name") as $p) $projects[$p['id']] = $p['name'];

layout_header(t('nav.reservations'), 'reservations');
page_head(t('nav.reservations'), t('res.sub'),
    '<a class="btn" href="?' . e(http_build_query(['q' => $q, 'project' => $project ?: '', 'f' => $filter, 'export' => 'csv'])) . '">' . e(t('ui.export_csv')) . '</a>');
?>
<div class="stats-row">
  <div class="stat-tile"><span class="lbl"><?= e(t('res.count')) ?></span><span class="val"><?= count($rows) ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('res.total_value')) ?></span><span class="val"><?= e(money($sum['total'])) ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('res.paid')) ?></span><span class="val good"><?= e(money($sum['paid'])) ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('res.remaining')) ?></span><span class="val accent"><?= e(money($sum['total'] - $sum['paid'])) ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('res.overdue')) ?></span><span class="val danger"><?= e(money($sum['overdue'])) ?></span></div>
</div>

<div class="card card-flush">
  <div class="card-head">
    <form method="get" class="filter-row">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('res.search')) ?>">
      <select name="project" data-autosubmit><option value=""><?= e(t('res.all_projects')) ?></option><?= options($projects, $project ?: '', false) ?></select>
      <select name="f" data-autosubmit><?= options(['' => t('res.f_all'), 'overdue' => t('res.f_overdue'), 'settled' => t('res.f_settled')], $filter, false) ?></select>
      <button class="btn btn-sm" type="submit"><?= e(t('ui.search')) ?></button>
    </form>
  </div>
  <?php if (!$rows): ?><div class="empty"><?= e(t('res.empty')) ?></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th><?= e(t('contract.no')) ?></th><th><?= e(t('contract.official_name')) ?></th><th><?= e(t('contract.project')) ?></th>
      <th class="num"><?= e(t('contract.total')) ?></th><th class="num"><?= e(t('res.paid')) ?></th><th class="num"><?= e(t('res.remaining')) ?></th>
      <th><?= e(t('res.progress')) ?></th><th><?= e(t('res.next_due')) ?></th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $pctPaid = (float) $r['total'] > 0 ? min(100, round((float) $r['paid'] / (float) $r['total'] * 100)) : 0; ?>
      <tr>
        <td class="mono nowrap"><?= e($r['contract_no']) ?><div class="text-muted small"><?= e(fmt_date($r['contract_date'])) ?></div></td>
        <td><a class="strong" href="client.php?id=<?= (int) $r['client_id'] ?>"><?= e($r['official_name']) ?></a><div class="text-muted small" dir="ltr"><?= e($r['phone']) ?></div></td>
        <td><?= e($r['project_name']) ?></td>
        <td class="num nowrap"><?= e(money($r['total'], $r['currency'])) ?></td>
        <td class="num nowrap good-text"><?= e(money($r['paid'], $r['currency'])) ?></td>
        <td class="num nowrap"><?= e(money((float) $r['total'] - (float) $r['paid'], $r['currency'])) ?>
          <?php if ((float) $r['overdue'] > 0): ?><div class="small danger-text"><?= e(t('res.overdue')) ?>: <?= e(money($r['overdue'], $r['currency'])) ?></div><?php endif; ?></td>
        <td><div class="progress"><span style="width:<?= $pctPaid ?>%"></span></div><div class="small text-muted"><?= $pctPaid ?>%</div></td>
        <td class="nowrap"><?= e(fmt_date($r['next_due'])) ?></td>
        <td><a class="btn btn-sm" href="reservation.php?id=<?= (int) $r['id'] ?>"><?= e(t('res.statement')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php layout_footer();
