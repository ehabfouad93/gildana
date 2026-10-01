<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * One client list, scoped per role. Each department gets its own queue
 * (what is waiting on them) plus the clients it has already handled.
 */
require_role('advisor', 'booker', 'communicator', 'sales', 'owner_services');

$me = $ME['id'];

/** tab key => [label, WHERE sql, params, order] */
$views = [];
switch ($ME['role']) {
    case 'advisor':
        $views['mine']    = [t('tab.mine'),        "c.created_by = ?", [$me], 'c.id DESC'];
        $views['waiting'] = [t('tab.waiting_book'), "c.created_by = ? AND c.stage = 'new'", [$me], 'c.id DESC'];
        $views['won']     = [t('tab.won'),         "c.created_by = ? AND c.stage = 'contracted'", [$me], 'c.closed_at DESC'];
        break;
    case 'booker':
        $views['to_book'] = [t('tab.to_book'),     "c.stage = 'new'", [], 'c.id ASC'];
        $views['booked']  = [t('tab.booked_by_me'), "c.booker_id = ? AND c.stage <> 'new'", [$me], 'c.meeting_date DESC, c.meeting_time DESC'];
        break;
    case 'communicator':
        $views['to_confirm'] = [t('tab.to_confirm'), "c.stage = 'booked'", [], 'c.meeting_date ASC, c.meeting_time ASC'];
        $views['confirmed']  = [t('tab.confirmed'),  "c.stage = 'confirmed'", [], 'c.meeting_date ASC, c.meeting_time ASC'];
        $views['handled']    = [t('tab.handled'),    "c.communicator_id = ?", [$me], 'c.confirmed_at DESC'];
        break;
    case 'sales':
        $views['meetings'] = [t('tab.my_meetings'), "c.sales_id = ? AND c.stage = 'confirmed'", [$me], 'c.meeting_date ASC, c.meeting_time ASC'];
        $views['upcoming'] = [t('tab.pending_conf'), "c.sales_id = ? AND c.stage = 'booked'", [$me], 'c.meeting_date ASC, c.meeting_time ASC'];
        $views['won']      = [t('tab.won'),         "c.sales_id = ? AND c.stage = 'contracted'", [$me], 'c.closed_at DESC'];
        $views['lost']     = [t('tab.lost'),        "c.sales_id = ? AND c.stage = 'lost'", [$me], 'c.closed_at DESC'];
        break;
    case 'owner_services':
        $views['owners'] = [t('tab.owners'), "c.stage = 'contracted'", [], 'c.closed_at DESC'];
        break;
    default: // admin
        $views['all'] = [t('tab.all'), "1=1", [], 'c.id DESC'];
        foreach (STAGES as $s) {
            $views[$s] = [t('stage.' . $s), "c.stage = ?", [$s], 'c.id DESC'];
        }
}

$tab = (string) ($_GET['tab'] ?? '');
if (!isset($views[$tab])) $tab = (string) array_key_first($views);
[$label, $where, $params, $order] = $views[$tab];

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $where  .= " AND (c.full_name LIKE ? OR c.phone LIKE ? OR c.phone2 LIKE ? OR ct.contract_no LIKE ?)";
    $like    = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$rows = db_all(
    "SELECT c.*, a.name AS advisor_name, s.name AS sales_name, ct.id AS contract_id, ct.contract_no
       FROM clients c
       LEFT JOIN users a ON a.id = c.created_by
       LEFT JOIN users s ON s.id = c.sales_id
       LEFT JOIN contracts ct ON ct.client_id = c.id
      WHERE $where
      ORDER BY $order
      LIMIT 500", $params);

$tabData = [];
foreach ($views as $k => $v) {
    $tabData[$k] = ['label' => $v[0], 'count' => (int) db_val("SELECT COUNT(*) FROM clients c WHERE {$v[1]}", $v[2])];
}

if (($_GET['export'] ?? '') === 'csv' && has_role('owner_services')) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clients-' . $tab . '-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map('csv_cell', ['ID', t('client.full_name'), t('client.phone'), t('client.city'), t('client.stage'), t('client.meeting'), t('role.advisor'), t('role.sales'), t('contract.no')])) . "\n";
    foreach ($rows as $r) {
        echo implode(',', array_map('csv_cell', [(string) $r['id'], $r['full_name'], $r['phone'], $r['city'], t('stage.' . $r['stage']),
            $r['meeting_date'] ? $r['meeting_date'] . ' ' . substr((string) $r['meeting_time'], 0, 5) : '', $r['advisor_name'], $r['sales_name'], $r['contract_no']])) . "\n";
    }
    exit;
}

$actions = '';
if (has_role('advisor')) $actions .= '<a class="btn btn-primary" href="client_new.php">+ ' . e(t('nav.client_new')) . '</a>';
if (has_role('owner_services')) $actions .= '<a class="btn" href="?' . e(http_build_query(['tab' => $tab, 'q' => $q, 'export' => 'csv'])) . '">' . e(t('ui.export_csv')) . '</a>';

layout_header(t('nav.clients.' . $ME['role']), 'clients');
page_head(t('nav.clients.' . $ME['role']), t('clients.sub.' . $ME['role']), $actions);
echo tabs($tabData, $tab, 'tab', $q !== '' ? ['q' => $q] : []);
?>
<div class="card card-flush">
  <div class="card-head">
    <form method="get" class="filter-row">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('clients.search')) ?>">
      <button class="btn btn-sm" type="submit"><?= e(t('ui.search')) ?></button>
    </form>
    <span class="text-muted"><?= e(t('ui.n_results', ['n' => (string) count($rows)])) ?></span>
  </div>
  <?php if (!$rows): ?>
    <div class="empty"><?= e(t('clients.empty')) ?></div>
  <?php else: ?>
  <div class="table-wrap">
  <table class="data">
    <thead><tr>
      <th>#</th>
      <th><?= e(t('client.full_name')) ?></th>
      <th><?= e(t('client.phone')) ?></th>
      <th><?= e(t('client.stage')) ?></th>
      <th><?= e(t('client.meeting')) ?></th>
      <?php if ($ME['role'] !== 'advisor'): ?><th><?= e(t('role.advisor')) ?></th><?php endif; ?>
      <th><?= e(t('role.sales')) ?></th>
      <th><?= e(t('client.added')) ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="text-muted"><?= (int) $r['id'] ?></td>
        <td><a class="strong" href="client.php?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a>
          <?php if ($r['contract_no']): ?><div class="text-muted small mono"><?= e($r['contract_no']) ?></div><?php endif; ?></td>
        <td dir="ltr" class="nowrap"><?= e($r['phone']) ?></td>
        <td><?= stage_pill($r['stage']) ?></td>
        <td class="nowrap"><?= $r['meeting_date'] ? e(fmt_date($r['meeting_date'])) . ' · ' . e(fmt_time($r['meeting_time'])) : '<span class="text-muted">—</span>' ?></td>
        <?php if ($ME['role'] !== 'advisor'): ?><td><?= e($r['advisor_name'] ?? '—') ?></td><?php endif; ?>
        <td><?= e($r['sales_name'] ?? '—') ?></td>
        <td class="nowrap text-muted"><?= e(fmt_date($r['created_at'])) ?></td>
        <td class="nowrap"><a class="btn btn-sm" href="client.php?id=<?= (int) $r['id'] ?>"><?= e(t('ui.open')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php layout_footer();
