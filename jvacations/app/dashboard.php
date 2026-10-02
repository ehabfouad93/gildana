<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/** Landing page: what is waiting on *this* person, plus the numbers their role cares about. */
$role = $ME['role'];
$me   = $ME['id'];
$all  = $role === 'admin';

$tiles = [];
$tile  = function (string $label, $value, string $cls = '', string $href = '', string $sub = '') use (&$tiles) {
    $tiles[] = compact('label', 'value', 'cls', 'href', 'sub');
};
$count = fn(string $sql, array $p = []) => (int) db_val($sql, $p);

if (can('clients.add') && !$all) {
    $tile(t('dash.my_clients'), $count("SELECT COUNT(*) FROM clients WHERE created_by = ?", [$me]), '', 'clients.php');
    $tile(t('dash.added_month'), $count("SELECT COUNT(*) FROM clients WHERE created_by = ? AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", [$me]), 'accent');
    $tile(t('tab.waiting_book'), $count("SELECT COUNT(*) FROM clients WHERE created_by = ? AND stage = 'new'", [$me]), '', 'clients.php?tab=waiting');
    $tile(t('tab.won'), $count("SELECT COUNT(*) FROM clients WHERE created_by = ? AND stage = 'contracted'", [$me]), 'good', 'clients.php?tab=won');
}
if (can('clients.book')) {
    $tile(t('tab.to_book'), $count("SELECT COUNT(*) FROM clients WHERE stage = 'new'"), 'accent', 'clients.php?tab=' . ($all ? 'new' : 'to_book'));
}
if (can('clients.book') && !$all) {
    $tile(t('dash.booked_week'), $count("SELECT COUNT(*) FROM clients WHERE booker_id = ? AND booked_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)", [$me]));
}
if (can('clients.confirm')) {
    $tile(t('tab.to_confirm'), $count("SELECT COUNT(*) FROM clients WHERE stage = 'booked'"), 'accent', 'clients.php?tab=' . ($all ? 'booked' : 'to_confirm'));
    $tile(t('dash.meetings_today'), $count("SELECT COUNT(*) FROM clients WHERE stage IN ('booked','confirmed') AND meeting_date = CURDATE()"));
}
if (can('clients.arrive')) {
    $tile(t('tab.expected_today'), $count("SELECT COUNT(*) FROM clients WHERE stage IN ('booked','confirmed') AND meeting_date = CURDATE()"), 'accent', 'clients.php?tab=' . ($all ? 'confirmed' : 'today'));
    $tile(t('tab.at_office'), $count("SELECT COUNT(*) FROM clients WHERE stage = 'arrived'"), 'purple', 'clients.php?tab=' . ($all ? 'arrived' : 'at_office'));
}
if (can('clients.close') && !$all) {
    $tile(t('tab.my_meetings'), $count("SELECT COUNT(*) FROM clients WHERE sales_id = ? AND stage = 'arrived'", [$me]), 'accent', 'clients.php?tab=meetings');
    $tile(t('dash.won_month'), $count("SELECT COUNT(*) FROM clients WHERE sales_id = ? AND stage = 'contracted' AND closed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", [$me]), 'good', 'clients.php?tab=won');
    $won  = $count("SELECT COUNT(*) FROM clients WHERE sales_id = ? AND stage = 'contracted'", [$me]);
    $lost = $count("SELECT COUNT(*) FROM clients WHERE sales_id = ? AND stage = 'lost'", [$me]);
    $tile(t('dash.close_rate'), ($won + $lost) ? round($won / ($won + $lost) * 100) . '%' : '—', '', '', t('dash.won_lost', ['w' => (string) $won, 'l' => (string) $lost]));
    $sold = (float) db_val("SELECT COALESCE(SUM(ct.total_amount),0) FROM contracts ct JOIN clients c ON c.id = ct.client_id WHERE c.sales_id = ? AND ct.contract_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", [$me]);
    $tile(t('dash.sold_month'), money($sold));
}
if (can_any('contracts.view', 'payments.record')) {
    $fin = db_row("SELECT COALESCE(SUM(amount),0) AS total, COALESCE(SUM(paid_amount),0) AS paid,
                          COALESCE(SUM(CASE WHEN status <> 'paid' AND due_date < CURDATE() THEN amount - paid_amount ELSE 0 END),0) AS overdue,
                          COALESCE(SUM(CASE WHEN status <> 'paid' AND due_date BETWEEN CURDATE() AND LAST_DAY(CURDATE()) THEN amount - paid_amount ELSE 0 END),0) AS month_due,
                          COALESCE(SUM(CASE WHEN paid_on >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN paid_amount ELSE 0 END),0) AS month_paid
                     FROM instalments");
    $tile(t('res.count'), $count("SELECT COUNT(*) FROM contracts"), '', 'reservations.php');
    $tile(t('dash.collected_month'), money($fin['month_paid']), 'good', 'instalments.php?tab=paid');
    $tile(t('dash.due_month'), money($fin['month_due']), 'accent', 'instalments.php?tab=month');
    $tile(t('res.overdue'), money($fin['overdue']), 'danger', 'instalments.php?tab=overdue');
    $tile(t('res.remaining'), money((float) $fin['total'] - (float) $fin['paid']));
}
if (can('stays.manage')) {
    $tile(t('dash.stays_30'), $count("SELECT COUNT(*) FROM stays WHERE status = 'confirmed' AND check_in BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"), '', 'stays.php?tab=upcoming');
}

/* Pipeline funnel for admin. */
$funnel = [];
if ($all) {
    foreach (db_all("SELECT stage, COUNT(*) AS n FROM clients GROUP BY stage") as $r) $funnel[$r['stage']] = (int) $r['n'];
}

/* Meetings list relevant to this role. */
$meetings = [];
$mWhere = null; $mParams = [];
if ($all || can_any('clients.confirm', 'clients.arrive')) { $mWhere = "c.stage IN ('booked','confirmed')"; }
elseif (can('clients.book'))  { $mWhere = "c.stage IN ('booked','confirmed') AND c.booker_id = ?"; $mParams = [$me]; }
elseif (can('clients.close')) { $mWhere = "c.stage IN ('booked','confirmed','arrived') AND c.sales_id = ?"; $mParams = [$me]; }
if ($mWhere) {
    $meetings = db_all("SELECT c.id, c.full_name, c.phone, c.stage, c.meeting_date, c.meeting_time, c.meeting_place, s.name AS sales_name
                          FROM clients c LEFT JOIN users s ON s.id = c.sales_id
                         WHERE $mWhere AND c.meeting_date >= CURDATE()
                         ORDER BY c.meeting_date, c.meeting_time LIMIT 15", $mParams);
}

/* Recent activity for the back office. */
$recent = [];
if ($all) {
    $recent = db_all("SELECT e.*, c.full_name, u.name AS user_name FROM client_events e JOIN clients c ON c.id = e.client_id
                       LEFT JOIN users u ON u.id = e.user_id ORDER BY e.id DESC LIMIT 12");
}

layout_header(t('nav.dashboard'), 'dashboard');
page_head(t('dash.hello', ['name' => $ME['name']]), t('dash.sub.' . $role));
?>
<div class="stats-row">
  <?php foreach ($tiles as $tl): ?>
    <<?= $tl['href'] ? 'a href="' . e($tl['href']) . '"' : 'div' ?> class="stat-tile<?= $tl['href'] ? ' link' : '' ?>">
      <span class="lbl"><?= e($tl['label']) ?></span>
      <span class="val <?= e($tl['cls']) ?>"><?= e((string) $tl['value']) ?></span>
      <?php if ($tl['sub']): ?><span class="sub"><?= e($tl['sub']) ?></span><?php endif; ?>
    </<?= $tl['href'] ? 'a' : 'div' ?>>
  <?php endforeach; ?>
</div>

<?php if ($all): ?>
<div class="card">
  <h2><?= e(t('dash.pipeline')) ?></h2>
  <div class="funnel">
    <?php foreach (STAGES as $s): ?>
      <a class="funnel-step st-<?= e($s) ?>" href="clients.php?tab=<?= e($s) ?>">
        <span class="fn-n"><?= (int) ($funnel[$s] ?? 0) ?></span><span class="fn-l"><?= e(t('stage.' . $s)) ?></span></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (can('clients.add') && !$all): ?>
  <div class="card cta-card"><div><h2><?= e(t('dash.advisor_cta')) ?></h2><p class="text-muted"><?= e(t('client.new_sub')) ?></p></div>
    <a class="btn btn-primary" href="client_new.php">+ <?= e(t('nav.client_new')) ?></a></div>
<?php endif; ?>

<div class="grid2">
<?php if ($mWhere): ?>
  <div class="card card-flush">
    <div class="card-head"><h2><?= e(t('dash.upcoming_meetings')) ?></h2></div>
    <?php if (!$meetings): ?><div class="empty"><?= e(t('dash.no_meetings')) ?></div><?php else: ?>
    <table class="data"><tbody>
      <?php foreach ($meetings as $m): ?>
        <tr>
          <td class="nowrap"><strong><?= e(fmt_date($m['meeting_date'])) ?></strong><div class="small text-muted"><?= e(fmt_time($m['meeting_time'])) ?></div></td>
          <td><a href="client.php?id=<?= (int) $m['id'] ?>"><?= e($m['full_name']) ?></a><div class="small text-muted"><?= e($m['meeting_place'] ?? '') ?></div></td>
          <td><?= stage_pill($m['stage']) ?><div class="small text-muted"><?= e($m['sales_name'] ?? '') ?></div></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($recent): ?>
  <div class="card card-flush">
    <div class="card-head"><h2><?= e(t('dash.activity')) ?></h2></div>
    <table class="data"><tbody>
      <?php foreach ($recent as $ev): ?>
        <tr><td><a href="client.php?id=<?= (int) $ev['client_id'] ?>"><?= e($ev['full_name']) ?></a>
          <div class="small text-muted"><?= e(t('event.' . $ev['action'])) ?> · <?= e($ev['user_name'] ?? '—') ?></div></td>
          <td class="small text-muted nowrap"><?= e(fmt_dt($ev['created_at'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>
<?php endif; ?>
</div>
<?php layout_footer();
