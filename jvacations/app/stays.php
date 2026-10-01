<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Owner services — weeks & holiday reservations. Each contract grants a number
 * of weeks per year for its term; a stay books one or more of those weeks at a
 * project on a date and time. A year can never be booked beyond its allowance.
 */
require_role('owner_services');

$cid = (int) ($_GET['contract'] ?? 0);
$ct  = $cid ? db_row("SELECT ct.*, c.full_name, c.phone AS client_phone, p.name AS project_name
                        FROM contracts ct JOIN clients c ON c.id = ct.client_id JOIN projects p ON p.id = ct.project_id
                       WHERE ct.id = ?", [$cid]) : null;
if ($cid && !$ct) { http_response_code(404); exit(t('err.not_found')); }

$projects = [];
foreach (db_all("SELECT id, name FROM projects WHERE active = 1 ORDER BY name") as $p) $projects[$p['id']] = $p['name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ct) {
    verify_csrf();
    $back = 'stays.php?contract=' . $cid;

    if (($_POST['action'] ?? '') === 'cancel') {
        $sid = (int) ($_POST['stay_id'] ?? 0);
        $n = db_run("UPDATE stays SET status = 'cancelled' WHERE id = ? AND contract_id = ? AND status = 'confirmed'", [$sid, $cid]);
        if ($n) log_event((int) $ct['client_id'], 'stay_cancelled', '#' . $sid);
        flash(t('stay.cancelled'));
        redirect($back);
    }

    $checkIn = valid_date($_POST['check_in'] ?? '');
    $time    = valid_time($_POST['check_in_time'] ?? '');
    $weeks   = (int) ($_POST['weeks'] ?? 0);
    $proj    = (int) ($_POST['project_id'] ?? 0);
    $guests  = (int) ($_POST['guests'] ?? 0);
    $err     = '';

    if (!$checkIn)                      $err = t('stay.err_date');
    elseif (!$time)                     $err = t('stay.err_time');
    elseif (!isset($projects[$proj]))   $err = t('contract.err.project');
    elseif ($weeks < 1)                 $err = t('stay.err_weeks');
    else {
        $year  = (int) substr($checkIn, 0, 4);
        $allow = week_allowance($ct);
        if (!isset($allow[$year])) {
            $err = t('stay.err_term', ['from' => (string) $ct['start_year'], 'to' => (string) ((int) $ct['start_year'] + (int) $ct['duration_years'] - 1)]);
        } elseif ($allow[$year]['used'] + $weeks > $allow[$year]['allowed']) {
            $err = t('stay.err_allowance', ['year' => (string) $year, 'left' => (string) max(0, $allow[$year]['allowed'] - $allow[$year]['used'])]);
        } elseif ($clash = db_row("SELECT id FROM stays WHERE contract_id = ? AND status = 'confirmed' AND check_in < DATE_ADD(?, INTERVAL ? DAY) AND check_out > ?",
                                  [$cid, $checkIn, $weeks * 7, $checkIn])) {
            $err = t('stay.err_overlap');
        }
    }

    if ($err !== '') {
        flash($err, 'error');
        $_SESSION['stay_old'] = $_POST;
        redirect($back);
    }
    $checkOut = date('Y-m-d', strtotime($checkIn . ' +' . ($weeks * 7) . ' days'));
    db_insert("INSERT INTO stays (contract_id, project_id, check_in, check_in_time, weeks, check_out, unit_type, guests, status, notes, created_by, created_at)
               VALUES (?,?,?,?,?,?,?,?, 'confirmed', ?, ?, NOW())",
        [$cid, $proj, $checkIn, $time . ':00', $weeks, $checkOut, mb_substr(post_str('unit_type'), 0, 80) ?: null,
         $guests ?: null, mb_substr(post_str('notes'), 0, 255) ?: null, $ME['id']]);
    log_event((int) $ct['client_id'], 'stay_booked', $projects[$proj] . ' · ' . fmt_date($checkIn) . ' ' . fmt_time($time) . ' · ' . t('stay.weeks_n', ['n' => (string) $weeks]));
    flash(t('stay.booked'));
    redirect($back);
}

layout_header(t('nav.stays'), 'stays');

/* ───────── one owner ───────── */
if ($ct):
    $allow  = week_allowance($ct);
    $stays  = db_all("SELECT s.*, p.name AS project_name, u.name AS by_name FROM stays s JOIN projects p ON p.id = s.project_id
                       LEFT JOIN users u ON u.id = s.created_by WHERE s.contract_id = ? ORDER BY s.check_in DESC", [$cid]);
    $totals = contract_totals($cid);
    $old    = $_SESSION['stay_old'] ?? [];
    unset($_SESSION['stay_old']);
    $thisYear = (int) date('Y');

    page_head($ct['official_name'], $ct['contract_no'] . ' · ' . $ct['project_name'],
        '<a class="btn" href="client.php?id=' . (int) $ct['client_id'] . '">' . e(t('client.profile')) . '</a>'
        . '<a class="btn" href="reservation.php?id=' . $cid . '">' . e(t('res.statement')) . '</a>'
        . '<a class="btn btn-ghost" href="stays.php">' . e(t('ui.back')) . '</a>');
    if ((float) $totals['overdue'] > 0): ?>
      <div class="alert warn"><?= e(t('stay.overdue_warn', ['amount' => money($totals['overdue'], $ct['currency']), 'n' => (string) (int) $totals['overdue_count']])) ?></div>
    <?php endif; ?>
<div class="grid-main">
<div>
  <div class="card card-flush">
    <div class="card-head"><h2><?= e(t('stay.allowance')) ?></h2>
      <span class="text-muted small"><?= e(t('contract.weeks_line', ['w' => (string) $ct['weeks_per_year'], 'y' => (string) $ct['duration_years'], 'from' => (string) $ct['start_year']])) ?></span></div>
    <div class="week-grid">
      <?php foreach ($allow as $y => $a):
          $left = $a['allowed'] - $a['used']; ?>
        <div class="week-year <?= $y === $thisYear ? 'now' : '' ?> <?= $y < $thisYear ? 'past' : '' ?>">
          <div class="wy-year"><?= $y ?></div>
          <div class="wy-dots"><?php for ($k = 0; $k < $a['allowed']; $k++): ?><i class="<?= $k < $a['used'] ? 'used' : '' ?>"></i><?php endfor; ?></div>
          <div class="wy-left"><?= e(t('stay.left_n', ['n' => (string) $left])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card card-flush">
    <div class="card-head"><h2><?= e(t('stay.list')) ?></h2></div>
    <?php if (!$stays): ?><div class="empty"><?= e(t('stay.empty')) ?></div><?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th><?= e(t('stay.check_in')) ?></th><th><?= e(t('stay.check_out')) ?></th><th><?= e(t('contract.project')) ?></th>
        <th><?= e(t('stay.weeks')) ?></th><th><?= e(t('pay.status')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($stays as $s): ?>
        <tr>
          <td class="nowrap"><?= e(fmt_date($s['check_in'])) ?> · <?= e(fmt_time($s['check_in_time'])) ?></td>
          <td class="nowrap"><?= e(fmt_date($s['check_out'])) ?></td>
          <td><?= e($s['project_name']) ?><?= $s['unit_type'] ? '<div class="small text-muted">' . e($s['unit_type']) . '</div>' : '' ?>
            <?= $s['notes'] ? '<div class="small text-muted" dir="auto">' . e($s['notes']) . '</div>' : '' ?></td>
          <td><?= (int) $s['weeks'] ?><?= $s['guests'] ? ' · ' . e(t('stay.guests_n', ['n' => (string) $s['guests']])) : '' ?></td>
          <td><?= $s['status'] === 'confirmed' ? '<span class="pill green">' . e(t('stay.confirmed')) . '</span>' : '<span class="pill gray">' . e(t('stay.cancelled_lbl')) . '</span>' ?></td>
          <td><?php if ($s['status'] === 'confirmed'): ?>
            <form method="post" data-confirm-form="<?= e(t('stay.cancel_q')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="stay_id" value="<?= (int) $s['id'] ?>">
              <button class="btn btn-sm btn-danger-ghost" type="submit"><?= e(t('ui.cancel')) ?></button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<div>
  <form method="post" class="card action-card">
    <?= csrf_field() ?>
    <h2><?= e(t('stay.new')) ?></h2>
    <div class="field"><span class="lbl"><?= e(t('contract.project')) ?> *</span>
      <select name="project_id" required><?= options($projects, $old['project_id'] ?? $ct['project_id'], false) ?></select></div>
    <div class="grid2">
      <div class="field"><span class="lbl"><?= e(t('stay.check_in')) ?> *</span><input type="date" name="check_in" value="<?= e($old['check_in'] ?? '') ?>" required></div>
      <div class="field"><span class="lbl"><?= e(t('stay.time')) ?> *</span><input type="time" name="check_in_time" value="<?= e($old['check_in_time'] ?? '14:00') ?>" required></div>
      <div class="field"><span class="lbl"><?= e(t('stay.weeks')) ?> *</span><input type="number" name="weeks" min="1" max="<?= (int) $ct['weeks_per_year'] ?>" value="<?= e($old['weeks'] ?? '1') ?>" required></div>
      <div class="field"><span class="lbl"><?= e(t('stay.guests')) ?></span><input type="number" name="guests" min="1" max="30" value="<?= e($old['guests'] ?? '') ?>"></div>
    </div>
    <div class="field"><span class="lbl"><?= e(t('contract.unit_type')) ?></span><input type="text" name="unit_type" value="<?= e($old['unit_type'] ?? ($ct['unit_type'] ?? '')) ?>"></div>
    <div class="field"><span class="lbl"><?= e(t('ui.note')) ?></span><input type="text" name="notes" value="<?= e($old['notes'] ?? '') ?>"></div>
    <button class="btn btn-primary btn-block" type="submit"><?= e(t('stay.book_btn')) ?></button>
  </form>
</div>
</div>
<?php
/* ───────── all owners ───────── */
else:
    $tab  = ($_GET['tab'] ?? '') === 'upcoming' ? 'upcoming' : 'owners';
    $q    = trim((string) ($_GET['q'] ?? ''));
    $year = (int) date('Y');
    page_head(t('nav.stays'), t('stay.sub'));
    echo tabs(['owners' => ['label' => t('stay.tab_owners')], 'upcoming' => ['label' => t('stay.tab_upcoming')]], $tab);

    if ($tab === 'owners'):
        $params = [$year, $year, $year];
        $where  = '';
        if ($q !== '') {
            $where = "WHERE (ct.official_name LIKE ? OR ct.contract_no LIKE ? OR c.phone LIKE ?)";
            $like  = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        $rows = db_all(
            "SELECT ct.id, ct.contract_no, ct.official_name, ct.weeks_per_year, ct.start_year, ct.duration_years, c.phone, p.name AS project_name,
                    (SELECT COALESCE(SUM(weeks),0) FROM stays s WHERE s.contract_id = ct.id AND s.status = 'confirmed' AND YEAR(s.check_in) = ?) AS used,
                    (ct.start_year <= ? AND ct.start_year + ct.duration_years > ?) AS in_term
               FROM contracts ct JOIN clients c ON c.id = ct.client_id JOIN projects p ON p.id = ct.project_id
               $where ORDER BY ct.official_name LIMIT 1000", $params);
        ?>
<div class="card card-flush">
  <div class="card-head">
    <form method="get" class="filter-row"><input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('res.search')) ?>">
      <button class="btn btn-sm" type="submit"><?= e(t('ui.search')) ?></button></form>
    <span class="text-muted small"><?= e(t('stay.year_note', ['year' => (string) $year])) ?></span>
  </div>
  <?php if (!$rows): ?><div class="empty"><?= e(t('res.empty')) ?></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th><?= e(t('contract.official_name')) ?></th><th><?= e(t('contract.no')) ?></th><th><?= e(t('contract.project')) ?></th>
      <th><?= e(t('stay.this_year')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $left = (int) $r['weeks_per_year'] - (int) $r['used']; ?>
      <tr>
        <td><strong><?= e($r['official_name']) ?></strong><div class="small text-muted" dir="ltr"><?= e($r['phone']) ?></div></td>
        <td class="mono nowrap"><?= e($r['contract_no']) ?></td>
        <td><?= e($r['project_name']) ?></td>
        <td><?php if ($r['in_term']): ?>
            <span class="pill <?= $left > 0 ? 'green' : 'gray' ?>"><?= e(t('stay.used_of', ['used' => (string) $r['used'], 'allowed' => (string) $r['weeks_per_year']])) ?></span>
          <?php else: ?><span class="text-muted small"><?= e(t('stay.out_of_term')) ?></span><?php endif; ?></td>
        <td><a class="btn btn-sm <?= $left > 0 && $r['in_term'] ? 'btn-primary' : '' ?>" href="stays.php?contract=<?= (int) $r['id'] ?>"><?= e(t('stay.manage')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php
    else:
        $proj = (int) ($_GET['project'] ?? 0);
        $rows = db_all(
            "SELECT s.*, p.name AS project_name, ct.official_name, ct.contract_no, c.phone
               FROM stays s JOIN projects p ON p.id = s.project_id JOIN contracts ct ON ct.id = s.contract_id JOIN clients c ON c.id = ct.client_id
              WHERE s.status = 'confirmed' AND s.check_out >= CURDATE()" . ($proj ? ' AND s.project_id = ' . $proj : '') . "
              ORDER BY s.check_in ASC, s.check_in_time ASC LIMIT 1000");
        ?>
<div class="card card-flush">
  <div class="card-head">
    <form method="get" class="filter-row"><input type="hidden" name="tab" value="upcoming">
      <select name="project" data-autosubmit><option value=""><?= e(t('res.all_projects')) ?></option><?= options($projects, $proj ?: '', false) ?></select></form>
  </div>
  <?php if (!$rows): ?><div class="empty"><?= e(t('stay.empty')) ?></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th><?= e(t('stay.check_in')) ?></th><th><?= e(t('stay.check_out')) ?></th><th><?= e(t('contract.project')) ?></th>
      <th><?= e(t('contract.official_name')) ?></th><th><?= e(t('stay.weeks')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td class="nowrap"><?= e(fmt_date($s['check_in'])) ?> · <?= e(fmt_time($s['check_in_time'])) ?></td>
        <td class="nowrap"><?= e(fmt_date($s['check_out'])) ?></td>
        <td><?= e($s['project_name']) ?><?= $s['unit_type'] ? '<div class="small text-muted">' . e($s['unit_type']) . '</div>' : '' ?></td>
        <td><?= e($s['official_name']) ?><div class="small text-muted"><span class="mono"><?= e($s['contract_no']) ?></span> · <span dir="ltr"><?= e($s['phone']) ?></span></div></td>
        <td><?= (int) $s['weeks'] ?><?= $s['guests'] ? ' · ' . e(t('stay.guests_n', ['n' => (string) $s['guests']])) : '' ?></td>
        <td><a class="btn btn-sm" href="stays.php?contract=<?= (int) $s['contract_id'] ?>"><?= e(t('ui.open')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php
    endif;
endif;
layout_footer();
