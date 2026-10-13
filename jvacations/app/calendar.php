<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Owner Services → Usage calendar. A month view of every confirmed stay, per
 * project, so the team sees occupancy at a glance before booking more weeks.
 */
require_cap('stays.manage');

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

$projects = [];
foreach (db_all("SELECT id, name FROM projects ORDER BY name") as $p) $projects[$p['id']] = $p['name'];

// Egypt's week starts on Saturday.
$startDow = 6;
$lead = ((int) date('w', strtotime($first)) - $startDow + 7) % 7;
$days = (int) date('t', strtotime($first));
$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));
$today = date('Y-m-d');
$q = fn(array $x) => '?' . http_build_query(array_merge(['m' => $m, 'project' => $proj ?: null], $x));

layout_header(t('nav.calendar'), 'calendar');
page_head(t('nav.calendar'), t('cal.sub'), '<a class="btn btn-primary" href="stays.php">' . e(t('cal.book')) . '</a>');
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
