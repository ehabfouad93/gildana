<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Owner Services → Usage calendar. Two views of the same holiday reservations:
 * a month calendar (occupancy at a glance) and a searchable table with a
 * printable confirmation for each stay.
 */
require_cap('stays.manage');

$view = ($_GET['view'] ?? '') === 'table' ? 'table' : 'calendar';
$projects = [];
foreach (db_all("SELECT id, name FROM projects ORDER BY name") as $p) $projects[$p['id']] = $p['name'];

/** Calendar | Table switch. */
$switch = function (string $view): string {
    return '<div class="seg view-switch">'
         . '<a class="seg-link' . ($view === 'calendar' ? ' on' : '') . '" href="calendar.php">📅 ' . e(t('cal.view_calendar')) . '</a>'
         . '<a class="seg-link' . ($view === 'table' ? ' on' : '') . '" href="calendar.php?view=table">☰ ' . e(t('cal.view_table')) . '</a></div>';
};

if ($view === 'table') {
    $q      = trim((string) ($_GET['q'] ?? ''));
    $proj   = (int) ($_GET['project'] ?? 0);
    $when   = in_array($_GET['when'] ?? '', ['upcoming', 'month', 'past', 'all'], true) ? $_GET['when'] : 'upcoming';
    $status = in_array($_GET['status'] ?? '', ['confirmed', 'cancelled', 'all'], true) ? $_GET['status'] : 'confirmed';
    $from   = valid_date($_GET['from'] ?? '');
    $to     = valid_date($_GET['to'] ?? '');

    $where = ['1=1']; $params = [];
    if ($status !== 'all') { $where[] = 's.status = ?'; $params[] = $status; }
    if ($proj) { $where[] = 's.project_id = ?'; $params[] = $proj; }
    if ($from || $to) {
        if ($from) { $where[] = 's.check_out > ?'; $params[] = $from; }
        if ($to)   { $where[] = 's.check_in <= ?'; $params[] = $to; }
    } elseif ($when === 'upcoming') { $where[] = 's.check_out >= CURDATE()'; }
    elseif ($when === 'month')      { $where[] = "s.check_in <= LAST_DAY(CURDATE()) AND s.check_out > DATE_FORMAT(CURDATE(), '%Y-%m-01')"; }
    elseif ($when === 'past')       { $where[] = 's.check_out < CURDATE()'; }
    if ($q !== '') { [$sw, $sp] = client_search_sql($q); $where[] = $sw; $params = array_merge($params, $sp); }
    if ($q !== '' && preg_match('/^HR-?0*(\d+)$/i', $q, $m)) { $where = ['s.id = ?']; $params = [(int) $m[1]]; }

    $rows = db_all(
        "SELECT s.*, p.name AS project_name, ct.official_name, ct.contract_no, ct.client_id, c.phone, u.name AS booked_by
           FROM stays s JOIN projects p ON p.id = s.project_id JOIN contracts ct ON ct.id = s.contract_id
           JOIN clients c ON c.id = ct.client_id LEFT JOIN users u ON u.id = s.created_by
          WHERE " . implode(' AND ', $where) . "
          ORDER BY s.check_in " . ($when === 'past' ? 'DESC' : 'ASC') . ", s.check_in_time LIMIT 1000", $params);

    if (($_GET['export'] ?? '') === 'csv' && can('export.csv')) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="holiday-reservations-' . date('Ymd') . '.csv"');
        echo "\xEF\xBB\xBF";
        echo implode(',', array_map('csv_cell', [t('voucher.no'), t('stay.check_in'), t('stay.time'), t('stay.check_out'), t('contract.project'),
            t('contract.unit_type'), t('stay.weeks'), t('stay.guests'), t('contract.official_name'), t('search.client_no'), t('client.phone'),
            t('contract.no'), t('pay.status')])) . "\n";
        foreach ($rows as $r) {
            echo implode(',', array_map('csv_cell', [stay_code((int) $r['id']), $r['check_in'], substr((string) $r['check_in_time'], 0, 5), $r['check_out'],
                $r['project_name'], $r['unit_type'], (string) $r['weeks'], (string) $r['guests'], $r['official_name'], client_code((int) $r['client_id']),
                $r['phone'], $r['contract_no'], $r['status'] === 'confirmed' ? t('stay.confirmed') : t('stay.cancelled_lbl')])) . "\n";
        }
        exit;
    }

    $qs = ['view' => 'table', 'q' => $q, 'project' => $proj ?: '', 'when' => $when, 'status' => $status, 'from' => $from ?? '', 'to' => $to ?? ''];
    $actions = $switch($view) . '<a class="btn btn-primary" href="stays.php">' . e(t('cal.book')) . '</a>';
    if (can('export.csv')) $actions .= '<a class="btn" href="?' . e(http_build_query($qs + ['export' => 'csv'])) . '">' . e(t('ui.export_csv')) . '</a>';

    layout_header(t('nav.calendar'), 'calendar');
    page_head(t('nav.calendar'), t('cal.table_sub'), $actions);
    ?>
<div class="card card-flush">
  <form method="get" class="card-head stay-filters">
    <input type="hidden" name="view" value="table">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('cal.search')) ?>" class="grow-input">
    <select name="when" data-autosubmit><?= options(['upcoming' => t('cal.when.upcoming'), 'month' => t('cal.when.month'), 'past' => t('cal.when.past'), 'all' => t('cal.when.all')], $when, false) ?></select>
    <select name="project" data-autosubmit><option value=""><?= e(t('res.all_projects')) ?></option><?= options($projects, $proj ?: '', false) ?></select>
    <select name="status" data-autosubmit><?= options(['confirmed' => t('stay.confirmed'), 'cancelled' => t('stay.cancelled_lbl'), 'all' => t('res.f_all')], $status, false) ?></select>
    <label class="mini-date"><span><?= e(t('search.from')) ?></span><input type="date" name="from" value="<?= e($from ?? '') ?>"></label>
    <label class="mini-date"><span><?= e(t('search.to')) ?></span><input type="date" name="to" value="<?= e($to ?? '') ?>"></label>
    <button class="btn btn-sm" type="submit"><?= e(t('ui.search')) ?></button>
    <span class="text-muted small"><?= e(t('ui.n_results', ['n' => (string) count($rows)])) ?></span>
  </form>
  <?php if (!$rows): ?><div class="empty"><?= e(t('stay.empty')) ?></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th><?= e(t('voucher.no')) ?></th><th><?= e(t('stay.check_in')) ?></th><th><?= e(t('stay.check_out')) ?></th>
      <th><?= e(t('contract.project')) ?></th><th><?= e(t('contract.official_name')) ?></th><th><?= e(t('stay.weeks')) ?></th>
      <th><?= e(t('pay.status')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['status'] === 'cancelled' ? 'row-muted' : '' ?>">
        <td class="mono nowrap"><?= e(stay_code((int) $r['id'])) ?></td>
        <td class="nowrap"><strong><?= e(fmt_date($r['check_in'])) ?></strong><div class="small text-muted"><?= e(fmt_time($r['check_in_time'])) ?></div></td>
        <td class="nowrap"><?= e(fmt_date($r['check_out'])) ?></td>
        <td><?= e($r['project_name']) ?><?= $r['unit_type'] ? '<div class="small text-muted">' . e($r['unit_type']) . '</div>' : '' ?></td>
        <td><a class="strong" href="history.php?client=<?= (int) $r['client_id'] ?>"><?= e($r['official_name']) ?></a>
          <div class="small text-muted"><span class="mono"><?= e(client_code((int) $r['client_id'])) ?> · <?= e($r['contract_no']) ?></span> · <span dir="ltr"><?= e($r['phone']) ?></span></div></td>
        <td><?= (int) $r['weeks'] ?><?= $r['guests'] ? '<div class="small text-muted">' . e(t('stay.guests_n', ['n' => (string) $r['guests']])) . '</div>' : '' ?></td>
        <td><?= $r['status'] === 'confirmed' ? '<span class="pill green">' . e(t('stay.confirmed')) . '</span>' : '<span class="pill gray">' . e(t('stay.cancelled_lbl')) . '</span>' ?></td>
        <td class="nowrap"><?= stay_doc_buttons((int) $r['id']) ?> <a class="btn btn-sm btn-ghost" href="stays.php?contract=<?= (int) $r['contract_id'] ?>"><?= e(t('ui.open')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php
    layout_footer();
    exit;
}

$m = (string) ($_GET['m'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $m)) $m = date('Y-m');
$first = $m . '-01';
$last  = date('Y-m-t', strtotime($first));
$proj  = (int) ($_GET['project'] ?? 0);

$params = [$last, $first];
$sql = "SELECT s.*, p.name AS project_name, ct.official_name, ct.contract_no, ct.client_id
          FROM stays s JOIN projects p ON p.id = s.project_id JOIN contracts ct ON ct.id = s.contract_id
         WHERE s.status = 'confirmed' AND s.check_in <= ? AND s.check_out > ?";
if ($proj) { $sql .= " AND s.project_id = ?"; $params[] = $proj; }
$stays = db_all($sql . " ORDER BY s.check_in, s.check_in_time", $params);

// Egypt's week starts on Saturday.
$startDow = 6;
$lead = ((int) date('w', strtotime($first)) - $startDow + 7) % 7;
$days = (int) date('t', strtotime($first));
$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));
$today = date('Y-m-d');
$q = fn(array $x) => '?' . http_build_query(array_merge(['m' => $m, 'project' => $proj ?: null], $x));

layout_header(t('nav.calendar'), 'calendar');
page_head(t('nav.calendar'), t('cal.sub'), $switch($view) . '<a class="btn btn-primary" href="stays.php">' . e(t('cal.book')) . '</a>');
?>
<div class="card card-flush">
  <div class="card-head">
    <div class="cal-nav">
      <a class="btn btn-sm" href="<?= e($q(['m' => $prev])) ?>">‹</a>
      <h2><?= e(t('cal.month.' . (int) substr($m, 5, 2)) . ' ' . substr($m, 0, 4)) ?></h2>
      <a class="btn btn-sm" href="<?= e($q(['m' => $next])) ?>">›</a>
      <a class="btn btn-sm btn-ghost" href="<?= e($q(['m' => date('Y-m')])) ?>"><?= e(t('cal.today')) ?></a>
    </div>
    <form method="get" class="filter-row"><input type="hidden" name="m" value="<?= e($m) ?>">
      <select name="project" data-autosubmit><option value=""><?= e(t('res.all_projects')) ?></option><?= options($projects, $proj ?: '', false) ?></select>
      <span class="text-muted small"><?= e(t('cal.n_stays', ['n' => (string) count($stays)])) ?></span></form>
  </div>
  <div class="cal-grid">
    <?php for ($d = 0; $d < 7; $d++): ?><div class="cal-dow"><?= e(t('cal.dow.' . (($startDow + $d) % 7))) ?></div><?php endfor; ?>
    <?php for ($i = 0; $i < $lead; $i++): ?><div class="cal-day out"></div><?php endfor; ?>
    <?php for ($d = 1; $d <= $days; $d++):
        $date = sprintf('%s-%02d', $m, $d);
        $here = array_filter($stays, fn($s) => $s['check_in'] <= $date && $s['check_out'] > $date); ?>
      <div class="cal-day <?= $date === $today ? 'today' : '' ?>">
        <div class="cal-num"><?= $d ?></div>
        <?php foreach (array_slice($here, 0, 4) as $s): $arr = $s['check_in'] === $date; ?>
          <a class="cal-chip <?= $arr ? 'arrive' : '' ?>" href="stays.php?contract=<?= (int) $s['contract_id'] ?>" title="<?= e($s['official_name'] . ' · ' . $s['project_name'] . ' · ' . fmt_date($s['check_in']) . ' → ' . fmt_date($s['check_out'])) ?>">
            <?= $arr ? '▸ ' . e(fmt_time($s['check_in_time'])) . ' ' : '' ?><?= e(excerpt_name($s['official_name'])) ?><?= $proj ? '' : ' · ' . e($s['project_name']) ?></a>
        <?php endforeach; ?>
        <?php if (count($here) > 4): ?><div class="cal-more">+<?= count($here) - 4 ?></div><?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>
<?php layout_footer();
