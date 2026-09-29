<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';
require_once __DIR__ . '/../includes/crm_dashboard.php';

/**
 * The sales dashboard.
 *
 *   Today    what each person has done so far today, and how fast they answer new leads
 *   Targets  this month against each person's targets, and the leaderboard
 *   Reports  leads against sales over time, where leads stand, and which sources, projects and
 *            people turn leads into deals
 *
 * A salesperson sees their own numbers (and the leaderboard); managers see the team.
 */
$cid     = (int) $CLIENT['id'];
$isAdmin = is_client_admin();
$tab     = in_array($_GET['tab'] ?? '', ['today', 'targets', 'reports'], true) ? $_GET['tab'] : 'today';
$month   = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'targets') {
    verify_csrf();
    if (!$isAdmin) { flash('Only an Admin can set targets.', 'error'); redirect('crm_dashboard.php?tab=targets'); }
    $m = preg_match('/^\d{4}-\d{2}$/', (string) ($_POST['month'] ?? '')) ? (string) $_POST['month'] : date('Y-m');
    $ok = array_map('intval', array_column(crm_dash_people($cid), 'id'));
    foreach ((array) ($_POST['t'] ?? []) as $uid => $v) if (in_array((int) $uid, $ok, true)) crm_target_save($cid, (int) $uid, $m, (array) $v);
    if (!empty($_POST['copy_next'])) {
        $next = date('Y-m', strtotime($m . '-01 +1 month'));
        foreach (crm_targets($cid, $m) as $uid => $t) crm_target_save($cid, (int) $uid, $next, $t);
    }
    flash('Targets saved for ' . date('F Y', strtotime($m . '-01')) . '.');
    redirect('crm_dashboard.php?tab=targets&month=' . $m);
}

$f = [
    'from'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-89 days')),
    'to'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d'),
    'owner'   => is_sales() ? '' : (string) ($_GET['owner'] ?? ''),
    'source'  => (string) ($_GET['source'] ?? ''),
    'project' => (int) ($_GET['project'] ?? 0) ?: '',
];
if ($f['from'] > $f['to']) [$f['from'], $f['to']] = [$f['to'], $f['from']];
$bucket = ($_GET['by'] ?? '') === 'month' ? 'month' : 'week';

client_header('Sales dashboard', 'crm', $CLIENT);
page_head('Sales dashboard');

/** Horizontal bars, several series per row, each with its number written beside it. */
function dv_bars(array $rows, array $series, string $labelKey = 'label'): string
{
    $max = 1;
    foreach ($rows as $r) foreach ($series as [$k]) $max = max($max, (float) $r[$k]);
    $h = '<div class="dv-bars" role="list">';
    foreach ($rows as $r) {
        $tip = $r[$labelKey] . ': ' . implode(', ', array_map(fn($s) => number_format((float) $r[$s[0]]) . ' ' . strtolower($s[1]), $series));
        $h .= '<div class="dv-row" role="listitem" data-tip="' . e($tip) . '"><span class="dv-lbl">' . e((string) $r[$labelKey]) . '</span><span class="dv-group">';
        foreach ($series as [$k, $l, $cls]) {
            $v = (float) $r[$k];
            $h .= '<span class="dv-line"><span class="dv-track"><span class="dv-fill ' . $cls . '" style="width:' . round(100 * $v / $max, 1) . '%"></span></span>'
                . '<span class="dv-num">' . number_format($v) . '</span></span>';
        }
        $h .= '</span></div>';
    }
    return $h . '</div>';
}
function dv_legend(array $series): string
{
    return '<div class="dv-legend">' . implode('', array_map(fn($s) => '<span><i class="dv-key ' . $s[2] . '"></i>' . e($s[1]) . '</span>', $series)) . '</div>';
}
?>
<nav class="dv-tabs" aria-label="Dashboard sections">
  <?php foreach (['today' => 'Today', 'targets' => 'Targets & leaderboard', 'reports' => 'Reports'] as $k => $l): ?>
    <a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= $l ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'today'): $today = crm_dash_today($cid);
  $sum = fn($k) => array_sum(array_column($today, $k)); ?>
  <div class="dv-kpis">
    <?php foreach ([['new', 'New leads given out'], ['calls', 'Calls logged'], ['whatsapp', 'WhatsApps sent'], ['contacted', 'Leads contacted'],
                    ['booked', 'Visits booked'], ['visits', 'Visits today'], ['won', 'Deals won']] as [$k, $l]): ?>
      <div class="dv-kpi"><span class="dv-kpi-n"><?= number_format($sum($k)) ?></span><span class="dv-kpi-l"><?= $l ?></span></div>
    <?php endforeach; ?>
  </div>
  <div class="card card-flush" id="today">
    <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0"><?= is_sales() ? 'Your day' : 'Each person today' ?></h2>
      <p class="text-muted" style="font-size:12.5px;margin:4px 0 0"><?= e(date('l j F')) ?>, so far. First response is the median over the last 7 days,
        from being given a lead to first contacting it.</p></div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Person</th><th class="num">New leads</th><th class="num">Calls</th><th class="num">WhatsApps</th><th class="num">Contacted</th>
        <th class="num">Visits booked</th><th class="num">Visits today</th><th class="num">Won</th><th class="num">First response</th></tr></thead>
      <tbody>
      <?php if (!$today): ?><tr><td colspan="9"><div class="empty">Nobody on the sales team yet — add people with the Sales role on the Team page.</div></td></tr><?php endif; ?>
      <?php foreach ($today as $r): ?>
        <tr><td><strong><?= e((string) $r['name']) ?></strong></td>
          <?php foreach (['new', 'calls', 'whatsapp', 'contacted', 'booked', 'visits', 'won'] as $k): ?><td class="num <?= $r[$k] ? '' : 'text-muted' ?>"><?= $r[$k] ?></td><?php endforeach; ?>
          <td class="num"><?= e(crm_duration($r['response'])) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
  </div>

<?php elseif ($tab === 'targets'): $board = crm_leaderboard($cid, $month); $tg = crm_targets($cid, $month);
  $prev = date('Y-m', strtotime($month . '-01 -1 month')); $next = date('Y-m', strtotime($month . '-01 +1 month'));
  $daysIn = (int) date('t', strtotime($month . '-01'));
  $elapsed = $month < date('Y-m') ? 1.0 : ($month > date('Y-m') ? 0.0 : (int) date('j') / $daysIn); ?>
  <div class="row-between" style="margin-bottom:12px;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0"><?= e(date('F Y', strtotime($month . '-01'))) ?></h2>
    <span style="display:flex;gap:6px">
      <a class="btn btn-ghost btn-sm" href="?tab=targets&month=<?= $prev ?>" aria-label="Previous month">←</a>
      <a class="btn btn-ghost btn-sm" href="?tab=targets">This month</a>
      <a class="btn btn-ghost btn-sm" href="?tab=targets&month=<?= $next ?>" aria-label="Next month">→</a></span>
  </div>
  <?php if ($month === date('Y-m')): ?><p class="text-muted" style="font-size:12.5px;margin-top:-4px">
    <?= round($elapsed * 100) ?>% of the month has passed — the thin mark on each bar shows where someone on pace would be.</p><?php endif; ?>

  <div class="card card-flush" id="leaderboard">
    <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Leaderboard</h2>
      <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Ranked by deals won, then their value.</p></div>
    <div class="lb">
    <?php if (!$board): ?><div class="empty" style="padding:16px">Nobody on the sales team yet.</div><?php endif; ?>
    <?php foreach ($board as $i => $r): ?>
      <div class="lb-row">
        <span class="lb-rank"><?= $i + 1 ?></span>
        <div class="lb-who"><strong><?= e((string) $r['name']) ?></strong>
          <span class="text-muted"><?= $r['sales'] ?> won<?= $r['sales_value'] ? ' · ' . number_format($r['sales_value']) : '' ?> · <?= $r['visits'] ?> visits · <?= $r['contacted'] ?> contacted</span></div>
        <div class="lb-goals">
          <?php foreach (['sales' => 'Deals', 'sales_value' => 'Value', 'visits' => 'Visits', 'contacted' => 'Contacted'] as $k => $l):
            $goal = $r['target'][$k] ?? null; if ($goal === null || (float) $goal <= 0) continue; $pct = (int) $r['pct'][$k]; ?>
            <div class="goal" title="<?= e($l . ': ' . number_format((float) $r[$k]) . ' of ' . number_format((float) $goal)) ?>">
              <span class="goal-lbl"><?= $l ?></span>
              <span class="goal-track"><span class="goal-fill <?= $pct >= 100 ? 'met' : ($pct < round($elapsed * 100) - 15 ? 'behind' : '') ?>" style="width:<?= min(100, $pct) ?>%"></span>
                <?php if ($elapsed > 0 && $elapsed < 1): ?><span class="goal-pace" style="left:<?= round($elapsed * 100) ?>%"></span><?php endif; ?></span>
              <span class="goal-n"><?= number_format((float) $r[$k]) ?>/<?= number_format((float) $goal) ?></span>
            </div>
          <?php endforeach; ?>
          <?php if (!array_filter((array) ($r['target'] ?? []), fn($v, $k) => in_array($k, ['sales', 'sales_value', 'visits', 'contacted'], true) && $v !== null && (float) $v > 0, ARRAY_FILTER_USE_BOTH)): ?>
            <span class="text-muted" style="font-size:12px">No targets set<?= $isAdmin ? ' — set them below' : '' ?></span><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>

  <?php if ($isAdmin): ?>
  <div class="card" id="targets">
    <h2>Targets for <?= e(date('F Y', strtotime($month . '-01'))) ?></h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="targets"><input type="hidden" name="month" value="<?= e($month) ?>">
      <div class="table-wrap"><table class="data targets-table">
        <thead><tr><th>Person</th><th>Leads contacted</th><th>Visits (came)</th><th>Deals won</th><th>Value won</th></tr></thead>
        <tbody><?php foreach (crm_dash_people($cid) as $u): $t = $tg[(int) $u['id']] ?? []; ?>
          <tr><td><strong><?= e((string) $u['name']) ?></strong></td>
            <?php foreach (['contacted', 'visits', 'sales', 'sales_value'] as $k): ?>
              <td><input type="number" min="0" step="<?= $k === 'sales_value' ? 'any' : '1' ?>" name="t[<?= (int) $u['id'] ?>][<?= $k ?>]"
                   value="<?= isset($t[$k]) && $t[$k] !== null ? e((string) ($k === 'sales_value' ? (float) $t[$k] : (int) $t[$k])) : '' ?>" aria-label="<?= e($u['name'] . ' ' . $k) ?>"></td>
            <?php endforeach; ?></tr>
        <?php endforeach; ?></tbody></table></div>
      <label class="mod-all mt10"><input type="checkbox" name="copy_next" value="1"> Use the same targets for <?= e(date('F', strtotime($month . '-01 +1 month'))) ?></label>
      <button class="btn btn-primary mt10">Save targets</button>
    </form>
  </div>
  <?php endif; ?>

<?php else:
  $series = crm_series($cid, $f, $bucket);
  $status = crm_status_split($cid, $f);
  $bySrc  = crm_split($cid, $f, 'source');
  $projects = crm_projects($cid);
  $byProj = $projects ? crm_split($cid, $f, 'project') : [];
  $byOwn  = is_sales() ? [] : crm_split($cid, $f, 'owner');
  $acts   = crm_activity_matrix($cid, $f);
  $people = crm_assignable_users($cid);
  $srcAll = array_column(db_all("SELECT DISTINCT source FROM contacts WHERE client_id=? AND stage_id IS NOT NULL AND source IS NOT NULL", [$cid]), 'source');
  $tot = ['leads' => array_sum(array_column($series, 'leads')), 'won' => array_sum(array_column($series, 'won')), 'lost' => array_sum(array_column($series, 'lost'))];
  $three = [['leads', 'Leads', 's-leads'], ['won', 'Won', 's-won'], ['lost', 'Lost', 's-lost']];
  $two   = [['leads', 'Leads', 's-leads'], ['won', 'Won', 's-won']]; ?>
  <form class="crm-bar" method="get">
    <input type="hidden" name="tab" value="reports">
    <label class="text-muted" style="font-size:12.5px">From <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
    <label class="text-muted" style="font-size:12.5px">to <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
    <select name="by"><option value="week">By week</option><option value="month" <?= $bucket === 'month' ? 'selected' : '' ?>>By month</option></select>
    <?php if (!is_sales()): ?><select name="owner"><option value="">Everyone</option>
      <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $f['owner'] === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <select name="source"><option value="">Every source</option>
      <?php foreach ($srcAll as $s): ?><option value="<?= e($s) ?>" <?= $f['source'] === $s ? 'selected' : '' ?>><?= e(crm_source_label($s)) ?></option><?php endforeach; ?></select>
    <?php if ($projects): ?><select name="project"><option value="">Every project</option>
      <?php foreach ($projects as $pj): ?><option value="<?= (int) $pj['id'] ?>" <?= (int) $f['project'] === (int) $pj['id'] ? 'selected' : '' ?>><?= e($pj['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <button class="btn btn-ghost btn-sm">Apply</button>
  </form>

  <div class="dv-kpis">
    <div class="dv-kpi"><span class="dv-kpi-n"><?= number_format($tot['leads']) ?></span><span class="dv-kpi-l">Leads</span></div>
    <div class="dv-kpi"><span class="dv-kpi-n"><?= number_format($tot['won']) ?></span><span class="dv-kpi-l">Won</span></div>
    <div class="dv-kpi"><span class="dv-kpi-n"><?= number_format($tot['lost']) ?></span><span class="dv-kpi-l">Lost</span></div>
    <div class="dv-kpi"><span class="dv-kpi-n"><?= $tot['leads'] ? round(100 * $tot['won'] / $tot['leads'], 1) . '%' : '—' ?></span><span class="dv-kpi-l">Won ÷ leads</span></div>
  </div>

  <div class="card dv" id="leads-vs-sales">
    <h2>Leads vs sales, by <?= $bucket ?></h2>
    <p class="text-muted" style="font-size:12.5px;margin-top:-6px">Leads that arrived, and deals won and lost — each counted in the <?= $bucket ?> it happened.</p>
    <?php $maxC = max(1, ...array_map(fn($b) => max($b['leads'], $b['won'], $b['lost']), $series ?: [['leads' => 0, 'won' => 0, 'lost' => 0]])); ?>
    <div class="dv-cols" role="list">
      <?php foreach ($series as $b): ?>
        <div class="dv-col" role="listitem" data-tip="<?= e($b['label'] . ': ' . $b['leads'] . ' leads, ' . $b['won'] . ' won, ' . $b['lost'] . ' lost') ?>">
          <div class="dv-trio">
            <?php foreach ($three as [$k, , $cls]): ?>
              <span class="dv-bar <?= $cls ?>" style="height:<?= round(100 * $b[$k] / $maxC, 1) ?>%"><?php if ($b[$k]): ?><b><?= $b[$k] ?></b><?php endif; ?></span>
            <?php endforeach; ?>
          </div>
          <span class="dv-x"><?= e($b['label']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?= dv_legend($three) ?>
  </div>

  <div class="dv-grid">
    <div class="card dv" id="by-status">
      <h2>Leads by status</h2>
      <p class="text-muted" style="font-size:12.5px;margin-top:-6px">Where the leads from this period stand now.</p>
      <?php $maxS = max(1, ...array_column($status, 'n')); $allS = max(1, array_sum(array_column($status, 'n'))); ?>
      <div class="dv-bars" role="list">
        <?php foreach ($status as $s): ?>
          <div class="dv-row" role="listitem" data-tip="<?= e($s['label'] . ': ' . $s['n'] . ' (' . round(100 * $s['n'] / $allS) . '%)') ?>">
            <span class="dv-lbl"><?= e($s['label']) ?></span>
            <span class="dv-group"><span class="dv-line"><span class="dv-track"><span class="dv-fill <?= $s['kind'] === 'won' ? 's-won' : ($s['kind'] === 'lost' ? 's-lost' : 's-leads') ?>" style="width:<?= round(100 * $s['n'] / $maxS, 1) ?>%"></span></span>
              <span class="dv-num"><?= $s['n'] ?> · <?= round(100 * $s['n'] / $allS) ?>%</span></span></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card dv" id="by-source">
      <h2>Leads vs sales, by source</h2>
      <?= $bySrc ? dv_bars($bySrc, $two) . dv_legend($two) : '<p class="text-muted">No leads in this period.</p>' ?>
    </div>
    <?php if ($projects): ?>
    <div class="card dv" id="by-project">
      <h2>Leads vs sales, by project</h2>
      <?= $byProj ? dv_bars($byProj, $two) . dv_legend($two) : '<p class="text-muted">No leads in this period.</p>' ?>
    </div>
    <?php endif; ?>
    <?php if ($byOwn): ?>
    <div class="card dv" id="by-person">
      <h2>Leads vs sales, by salesperson</h2>
      <?= dv_bars($byOwn, $two) . dv_legend($two) ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="card card-flush" id="conversion" style="margin-top:16px">
    <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Conversion, side by side</h2></div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>By</th><th></th><th class="num">Leads</th><th class="num">Won</th><th class="num">Lost</th><th class="num">Won value</th><th class="num">Won ÷ leads</th></tr></thead>
      <tbody><?php foreach (['Source' => $bySrc, 'Project' => $byProj, 'Salesperson' => $byOwn] as $by => $rows): foreach ($rows as $i => $r): ?>
        <tr><td class="text-muted"><?= $i === 0 ? $by : '' ?></td><td><?= e((string) $r['label']) ?></td><td class="num"><?= (int) $r['leads'] ?></td><td class="num"><?= (int) $r['won'] ?></td>
          <td class="num"><?= (int) $r['lost'] ?></td><td class="num"><?= number_format((float) $r['won_value']) ?></td><td class="num"><?= $r['rate'] !== null ? $r['rate'] . '%' : '—' ?></td></tr>
      <?php endforeach; endforeach; ?></tbody></table></div>
  </div>

  <?php if ($acts): $kinds = crm_activity_kinds(); ?>
  <div class="card card-flush" id="activity-matrix" style="margin-top:16px">
    <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">What each person logged</h2></div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Person</th><?php foreach ($kinds as $l): ?><th class="num"><?= e($l) ?></th><?php endforeach; ?><th class="num">Total</th></tr></thead>
      <tbody><?php foreach ($acts as $uid => $row): ?>
        <tr><td><?= e(crm_user_name((int) $uid)) ?></td><?php foreach (array_keys($kinds) as $k): ?><td class="num"><?= (int) ($row[$k] ?? 0) ?></td><?php endforeach; ?>
          <td class="num"><strong><?= array_sum($row) ?></strong></td></tr>
      <?php endforeach; ?></tbody></table></div>
  </div>
  <?php endif; ?>
  <p class="text-muted" style="font-size:12.5px;margin-top:12px">More in <a href="crm_reports.php">CRM reports</a>: response times, the funnel, time in each stage, lost reasons and site visits.</p>

  <div class="viz-tip" id="dv-tip" role="tooltip"></div>
  <script>
  (() => {
    const tip = document.getElementById('dv-tip');
    document.querySelectorAll('[data-tip]').forEach(el => {
      el.addEventListener('mousemove', e => { tip.textContent = el.dataset.tip; tip.style.display = 'block';
        tip.style.left = Math.min(e.clientX + 14, innerWidth - tip.offsetWidth - 8) + 'px'; tip.style.top = (e.clientY + 14) + 'px'; });
      el.addEventListener('mouseleave', () => { tip.style.display = 'none'; });
    });
  })();
  </script>
<?php endif; ?>
<?php layout_footer();
