<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * The department client lists. ?view= picks the department tab from the menu
 * (Marketing: invitation, booking, attendance · Sales: attendance, deals ·
 * Owners). Inside a view, the tabs are the queues that department works from.
 */
require_cap('clients.add', 'clients.book', 'clients.confirm', 'clients.arrive', 'clients.assign_sales',
            'clients.close', 'deals.all', 'clients.view_all');

$me    = $ME['id'];
$admin = $ME['role'] === 'admin';
/** "Mine" for staff, everyone's for the admin. */
$own = fn(string $col) => $admin ? ['1=1', []] : ["$col = ?", [$me]];

/** view => [needs any of caps, label key, sub key, tab builder] */
$defs = [
    'invitation' => [['clients.add'], 'nav.invitation', 'clients.sub.invitation', function () use ($own) {
        [$w, $p] = $own('c.created_by');
        return [
            'mine'      => [t('tab.mine'),         $w, $p, 'c.id DESC'],
            'waiting'   => [t('tab.waiting_book'), "$w AND c.stage = 'new'", $p, 'c.id DESC'],
            'added_won' => [t('tab.won'),          "$w AND c.stage = 'contracted'", $p, 'c.closed_at DESC'],
        ];
    }],
    'booking' => [['clients.book', 'clients.confirm'], 'nav.booking', 'clients.sub.booking', function () use ($own, $me) {
        $v = [];
        if (can('clients.book')) {
            [$w, $p] = $own('c.booker_id');
            $v['to_book'] = [t('tab.to_book'),      "c.stage = 'new'", [], 'c.id ASC'];
            $v['booked']  = [t('tab.booked_by_me'), "$w AND c.stage <> 'new'", $p, 'c.meeting_date DESC, c.meeting_time DESC'];
        }
        if (can('clients.confirm')) {
            [$w, $p] = $own('c.communicator_id');
            $v['to_confirm'] = [t('tab.to_confirm'), "c.stage = 'booked'", [], 'c.meeting_date ASC, c.meeting_time ASC'];
            $v['confirmed']  = [t('tab.confirmed'),  "c.stage = 'confirmed'", [], 'c.meeting_date ASC, c.meeting_time ASC'];
            $v['handled']    = [t('tab.handled'),    $w, $p, 'c.confirmed_at DESC'];
        }
        return $v;
    }],
    'attendance' => [['clients.arrive'], 'nav.attendance_mkt', 'clients.sub.attendance', function () {
        return [
            'today'         => [t('tab.expected_today'), "c.stage IN ('booked','confirmed') AND c.meeting_date = CURDATE()", [], 'c.meeting_time ASC'],
            'expected'      => [t('tab.expected'),       "c.stage IN ('booked','confirmed')", [], 'c.meeting_date ASC, c.meeting_time ASC'],
            'arrived_today' => [t('tab.arrived_today'),  "c.arrived_at >= CURDATE()", [], 'c.arrived_at DESC'],
        ];
    }],
    'sales_attendance' => [['clients.assign_sales'], 'nav.attendance_sales', 'clients.sub.sales_attendance', function () use ($me) {
        return [
            'unassigned' => [t('tab.no_rep'),       "c.stage = 'arrived' AND c.sales_id IS NULL AND c.sales_rep_name IS NULL", [], 'c.arrived_at ASC'],
            'for_me'     => [t('tab.sent_to_me'),   "c.stage = 'arrived' AND c.manager_id = ?", [$me], 'c.arrived_at ASC'],
            'at_office'  => [t('tab.at_office'),    "c.stage = 'arrived'", [], 'c.arrived_at DESC'],
        ];
    }],
    'deals' => [['clients.close', 'deals.all'], 'nav.deals', 'clients.sub.deals', function () use ($me) {
        if (can('deals.all')) {
            return [
                'ready' => [t('tab.ready_to_close'), "c.stage = 'arrived'", [], 'c.arrived_at ASC'],
                'won'   => [t('tab.won'),  "c.stage = 'contracted'", [], 'c.closed_at DESC'],
                'lost'  => [t('tab.lost'), "c.stage = 'lost'", [], 'c.closed_at DESC'],
            ];
        }
        return [
            'meetings' => [t('tab.my_meetings'),  "c.sales_id = ? AND c.stage = 'arrived'", [$me], 'c.arrived_at ASC'],
            'upcoming' => [t('tab.pending_conf'), "c.sales_id = ? AND c.stage IN ('booked','confirmed')", [$me], 'c.meeting_date ASC, c.meeting_time ASC'],
            'won'      => [t('tab.won'),          "c.sales_id = ? AND c.stage = 'contracted'", [$me], 'c.closed_at DESC'],
            'lost'     => [t('tab.lost'),         "c.sales_id = ? AND c.stage = 'lost'", [$me], 'c.closed_at DESC'],
        ];
    }],
    'all' => [['clients.view_all'], 'nav.all_clients', 'clients.sub.all', function () use ($admin) {
        $v = ['owners' => [t('tab.owners'), "c.stage = 'contracted'", [], 'c.closed_at DESC'],
              'every'  => [t('tab.all'), "1=1", [], 'c.id DESC']];
        if ($admin) foreach (STAGES as $st) $v[$st] = [t('stage.' . $st), "c.stage = ?", [$st], 'c.id DESC'];
        return $v;
    }],
];

$allowed = array_filter($defs, fn($d) => can_any(...$d[0]));
$view = (string) ($_GET['view'] ?? '');
if (!isset($allowed[$view])) $view = (string) array_key_first($allowed);
[, $viewLabel, $viewSub, $build] = $allowed[$view];
$views = $build();

$tab = (string) ($_GET['tab'] ?? '');
if (!isset($views[$tab])) $tab = (string) array_key_first($views);
[$label, $where, $params, $order] = $views[$tab];

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    [$sw, $sp] = client_search_sql($q);
    $where  .= " AND $sw";
    $params  = array_merge($params, $sp);
}

$rows = db_all(
    "SELECT c.*, a.name AS advisor_name, COALESCE(s.name, c.sales_rep_name) AS sales_name, ct.id AS contract_id, ct.contract_no
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

if (($_GET['export'] ?? '') === 'csv' && can('export.csv')) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clients-' . $view . '-' . $tab . '-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map('csv_cell', ['ID', t('client.full_name'), t('client.phone'), t('client.city'), t('client.stage'), t('client.meeting'), t('role.advisor'), t('role.sales'), t('contract.no')])) . "\n";
    foreach ($rows as $r) {
        echo implode(',', array_map('csv_cell', [(string) $r['id'], $r['full_name'], $r['phone'], $r['city'], t('stage.' . $r['stage']),
            $r['meeting_date'] ? $r['meeting_date'] . ' ' . substr((string) $r['meeting_time'], 0, 5) : '', $r['advisor_name'], $r['sales_name'], $r['contract_no']])) . "\n";
    }
    exit;
}

$actions = '';
if ($view === 'invitation') $actions .= '<a class="btn btn-primary" href="client_new.php">+ ' . e(t('nav.client_new')) . '</a>';
if (can('export.csv')) $actions .= '<a class="btn" href="?' . e(http_build_query(['view' => $view, 'tab' => $tab, 'q' => $q, 'export' => 'csv'])) . '">' . e(t('ui.export_csv')) . '</a>';

layout_header(t($viewLabel), 'clients:' . $view);
page_head(t($viewLabel), t($viewSub), $actions);
echo tabs($tabData, $tab, 'tab', array_merge(['view' => $view], $q !== '' ? ['q' => $q] : []));
?>
<div class="card card-flush">
  <div class="card-head">
    <form method="get" class="filter-row">
      <input type="hidden" name="view" value="<?= e($view) ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>">
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
      <?php if ($view !== 'invitation'): ?><th><?= e(t('role.advisor')) ?></th><?php endif; ?>
      <th><?= e(t('role.sales')) ?></th>
      <th><?= e(t('client.added')) ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono"><?= e(client_code((int) $r['id'])) ?></td>
        <td><a class="strong" href="client.php?id=<?= (int) $r['id'] ?>"><?= e($r['full_name']) ?></a>
          <?php if ($r['contract_no']): ?><div class="text-muted small mono"><?= e($r['contract_no']) ?></div><?php endif; ?></td>
        <td dir="ltr" class="nowrap"><?= e($r['phone']) ?></td>
        <td><?= stage_pill($r['stage']) ?></td>
        <td class="nowrap"><?= $r['meeting_date'] ? e(fmt_date($r['meeting_date'])) . ' · ' . e(fmt_time($r['meeting_time'])) : '<span class="text-muted">—</span>' ?></td>
        <?php if ($view !== 'invitation'): ?><td><?= e($r['advisor_name'] ?? '—') ?></td><?php endif; ?>
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
