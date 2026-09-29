<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm_reports.php';
require_once __DIR__ . '/../includes/ads.php';

/**
 * CRM reports: the sales team, and the leads.
 *
 * Sales users see their own row and their own leads only — the scope is applied inside the
 * report functions, so the CSV export cannot show more than the screen does.
 */
$cid = (int) $CLIENT['id'];
$f = [
    'from'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-29 days')),
    'to'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? ''))   ? $_GET['to']   : date('Y-m-d'),
    'owner'  => is_sales() ? '' : (string) ($_GET['owner'] ?? ''),
    'source' => (string) ($_GET['source'] ?? ''),
    'project'=> (int) ($_GET['project'] ?? 0) ?: '',
];
if ($f['from'] > $f['to']) [$f['from'], $f['to']] = [$f['to'], $f['from']];

$sales   = crm_report_sales($cid, $f);
$funnel  = crm_report_funnel($cid, $f);
$sources = crm_report_sources($cid, $f);
$ads     = crm_report_ads($cid, $f);
$tis     = crm_report_time_in_stage($cid, $f);
$weekly  = crm_report_weekly($cid, $f);
$byProject = crm_report_projects($cid, $f);
$lostWhy   = crm_report_lost($cid, $f);
$projList  = crm_projects($cid);
$visitsByUser = crm_report_visits($cid, $f['from'], $f['to'], 'user');
$visitsByProj = $projList ? crm_report_visits($cid, $f['from'], $f['to'], 'project') : [];

/* ── CSV export: the same numbers as the screen, never more ── */
if (($x = (string) ($_GET['export'] ?? '')) !== '') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="crm-' . $x . '-' . $f['from'] . '-to-' . $f['to'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                        // so Excel opens Arabic names correctly
    if ($x === 'sources') {
        fputcsv($out, ['Source', 'Leads', 'Won', 'Won value', 'Conversion %']);
        foreach ($sources as $r) fputcsv($out, [$r['label'], $r['leads'], $r['won'], $r['won_value'], $r['rate']]);
    } else {
        fputcsv($out, ['Salesperson', 'Assigned', 'Contacted', 'Median first response (minutes)', 'Worked', 'Activities logged', 'Won',
                       'Won value', 'Conversion %', 'Overdue follow-ups']);
        foreach ($sales as $r) fputcsv($out, [$r['name'], $r['assigned'], $r['contacted'],
            $r['median_response'] !== null ? round($r['median_response'] / 60) : '', $r['worked'], $r['activities'] ?? 0, $r['won'],
            $r['won_value'], $r['conversion'], $r['overdue']]);
    }
    fclose($out);
    exit;
}

$totLeads = $funnel['total'];
$totWon   = array_sum(array_column($sources, 'won'));
$allResp  = [];
foreach ($sales as $r) if ($r['median_response'] !== null) $allResp[] = $r['median_response'];
$people   = crm_assignable_users($cid);
$srcAll   = array_column(db_all("SELECT DISTINCT source FROM contacts WHERE client_id=? AND crm_added_at IS NOT NULL AND source IS NOT NULL", [$cid]), 'source');
$q        = fn(array $extra = []) => e(http_build_query(array_merge($f, $extra)));

client_header('CRM reports', 'crm', $CLIENT);
page_head('CRM reports', '<a class="btn btn-ghost btn-sm" href="crm.php">&larr; CRM</a>');
?>
<style>
  /* Chart roles. One validated set (categorical slots 1–2, checked against this white surface:
     CVD ΔE 24.7, normal-vision 33.6, both ≥3:1). Won and Lost are deliberately NOT green and
     red — that pair is the one colour-blind readers most often cannot tell apart. */
  .viz { --bar: #7C3AED; --won: #2a78d6; --lost: #eb6834; --track: rgba(13,19,33,.06);
         --ink: #0d1321; --ink-2: rgba(13,19,33,.62); }
  .viz-bars { display: flex; flex-direction: column; gap: 2px; }
  .viz-row { display: grid; grid-template-columns: 130px minmax(0, 1fr) 120px; gap: 10px; align-items: center;
             padding: 5px 0; font-size: 13px; cursor: default; }
  .viz-row:hover { background: rgba(13,19,33,.03); }
  .viz-lbl { color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .viz-track { height: 14px; background: var(--track); border-radius: 4px; overflow: hidden; }
  /* display:block — these are spans, and an inline element ignores width and height, which
     left every bar an empty grey track while the numbers beside it were right. */
  .viz-track, .viz-fill { display: block; }
  /* No minimum width: a zero or a missing value draws nothing, rather than a stub that reads as "a little". */
  .viz-fill { height: 100%; background: var(--bar); border-radius: 0 4px 4px 0; }
  .viz-val { color: var(--ink-2); font-variant-numeric: tabular-nums; text-align: right; }
  .viz-val strong { color: var(--ink); }
  .viz-weeks { display: flex; gap: 10px; align-items: flex-end; height: 150px; padding: 20px 0 0;
               border-bottom: 1px solid var(--line); overflow-x: auto; }
  .viz-week { display: flex; flex-direction: column; align-items: center; gap: 4px; min-width: 44px; height: 100%; }
  .viz-pair { flex: 1; display: flex; gap: 2px; align-items: flex-end; width: 100%; justify-content: center; }
  .viz-col { width: 14px; border-radius: 4px 4px 0 0; position: relative; display: block; }
  /* Direct labels: two series, so the count sits on each bar rather than only in the legend. */
  .viz-col b { position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); margin-bottom: 2px;
               font-size: 10.5px; font-weight: 600; color: var(--ink-2); font-variant-numeric: tabular-nums; }
  .viz-col.won { background: var(--won); } .viz-col.lost { background: var(--lost); }
  .viz-wk { font-size: 11px; color: var(--ink-2); white-space: nowrap; }
  .viz-legend { display: flex; gap: 14px; font-size: 12px; color: var(--ink-2); margin-top: 8px; }
  .viz-key { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; }
  .viz-tip { position: fixed; pointer-events: none; background: #0d1321; color: #fff; font-size: 12px; line-height: 1.4;
             padding: 6px 9px; border-radius: 7px; z-index: 50; max-width: 260px; display: none; }
  @media (max-width: 640px) { .viz-row { grid-template-columns: 90px minmax(0,1fr) 88px; } }
</style>

<form class="crm-bar" method="get">
  <label style="font-size:12.5px">From <input type="date" name="from" value="<?= e($f['from']) ?>" style="width:auto"></label>
  <label style="font-size:12.5px">to <input type="date" name="to" value="<?= e($f['to']) ?>" style="width:auto"></label>
  <?php if (!is_sales()): ?>
  <select name="owner"><option value="">Everyone</option><option value="none" <?= $f['owner'] === 'none' ? 'selected' : '' ?>>Unassigned</option>
    <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $f['owner'] === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <select name="source"><option value="">Every source</option>
    <?php foreach ($srcAll as $s): ?><option value="<?= e($s) ?>" <?= $f['source'] === $s ? 'selected' : '' ?>><?= e(crm_source_label($s)) ?></option><?php endforeach; ?></select>
  <?php if ($projList): ?>
  <select name="project"><option value="">Every project</option>
    <?php foreach ($projList as $pj): ?><option value="<?= (int) $pj['id'] ?>" <?= (int) $f['project'] === (int) $pj['id'] ? 'selected' : '' ?>><?= e($pj['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <button class="btn btn-ghost btn-sm">Apply</button>
</form>

<div class="stats-row">
  <div class="stat-tile"><span class="lbl">New leads</span><span class="val"><?= number_format($totLeads) ?></span>
    <span class="sub"><?= e(date('j M', strtotime($f['from']))) ?> – <?= e(date('j M', strtotime($f['to']))) ?></span></div>
  <div class="stat-tile"><span class="lbl">Won</span><span class="val accent"><?= number_format($totWon) ?></span>
    <span class="sub"><?= number_format(array_sum(array_column($sources, 'won_value'))) ?> in value</span></div>
  <div class="stat-tile"><span class="lbl">Conversion</span><span class="val"><?= $totLeads ? round(100 * $totWon / $totLeads, 1) . '%' : '—' ?></span>
    <span class="sub">won ÷ new leads</span></div>
  <div class="stat-tile"><span class="lbl">First response</span><span class="val"><?= e(crm_duration(crm_median($allResp))) ?></span>
    <span class="sub">median, by a person</span></div>
</div>

<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <h2 style="border:0;padding:0;margin:0"><?= is_sales() ? 'Your performance' : 'Sales performance' ?></h2>
    <a class="btn btn-ghost btn-sm" href="?<?= $q(['export' => 'sales']) ?>">Export CSV</a>
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Salesperson</th><th class="num">Assigned</th><th class="num">Contacted</th><th class="num">First response</th>
      <th class="num">Worked</th><th class="num" title="Calls, meetings, visits, WhatsApps and emails logged">Activities</th><th class="num">Won</th><th class="num">Won value</th><th class="num">Conversion</th><th class="num">Overdue now</th></tr></thead>
    <tbody>
    <?php if (!$sales): ?><tr><td colspan="10"><div class="empty">No leads were handed out in this period.</div></td></tr><?php endif; ?>
    <?php foreach ($sales as $r): ?>
      <tr><td><strong><?= e($r['name']) ?></strong></td>
        <td class="num"><?= $r['assigned'] ?></td>
        <td class="num"><?= $r['contacted'] ?></td>
        <td class="num"><?= e(crm_duration($r['median_response'])) ?></td>
        <td class="num"><?= $r['worked'] ?></td>
        <td class="num"><?= (int) ($r['activities'] ?? 0) ?></td>
        <td class="num"><strong><?= $r['won'] ?></strong></td>
        <td class="num"><?= number_format($r['won_value']) ?></td>
        <td class="num"><?= $r['conversion'] !== null ? $r['conversion'] . '%' : '—' ?></td>
        <td class="num <?= $r['overdue'] ? 'crm-late' : '' ?>"><?= $r['overdue'] ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <p class="text-muted" style="font-size:12px;padding:0 18px 14px;margin:0">
    <strong>Contacted</strong> and <strong>first response</strong> count only replies from a person — a bot's instant
    auto-reply is left out, or everyone would look immediate. <strong>Worked</strong> = leads they moved to another stage.
    <strong>Conversion</strong> = won ÷ assigned.</p>
</div>

<div class="viz lead-grid" style="margin-top:16px">
  <div class="card">
    <h2>How far leads got</h2>
    <p class="text-muted" style="font-size:12.5px;margin-top:-6px">Of the <?= number_format($totLeads) ?> leads added in this period,
      how many reached each stage at any point. The drop between rows is where leads are being lost.</p>
    <div class="viz-bars" role="list">
      <?php foreach ($funnel['stages'] as $i => $s):
        $pct  = $totLeads ? 100 * $s['reached'] / $totLeads : 0;
        $prev = $i > 0 ? $funnel['stages'][$i - 1]['reached'] : null;
        $tip  = $s['name'] . ': ' . $s['reached'] . ' of ' . $totLeads . ' leads (' . round($pct) . '%)'
              . ($prev !== null && $s['kind'] === 'open' && $prev > $s['reached'] ? ' · ' . ($prev - $s['reached']) . ' fewer than ' . $funnel['stages'][$i - 1]['name'] : ''); ?>
        <div class="viz-row" role="listitem" data-tip="<?= e($tip) ?>">
          <span class="viz-lbl"><?= e($s['name']) ?></span>
          <span class="viz-track"><?php if ($s['reached'] > 0): ?><span class="viz-fill" style="width:<?= max(1, round($pct, 1)) ?>%"></span><?php endif; ?></span>
          <span class="viz-val"><strong><?= $s['reached'] ?></strong> · <?= round($pct) ?>%</span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h2>Where deals wait</h2>
    <p class="text-muted" style="font-size:12.5px;margin-top:-6px">Median time in each stage before moving on. Leads still
      sitting in a stage are not counted yet — that would make the stage where deals stall look fastest.</p>
    <?php $maxT = max(array_map(fn($t) => (float) $t['median'], $tis) ?: [0]); ?>
    <div class="viz-bars" role="list">
      <?php foreach ($tis as $t): ?>
        <div class="viz-row" role="listitem" data-tip="<?= e($t['name'] . ': median ' . crm_duration($t['median']) . ' across ' . $t['n'] . ' moves') ?>">
          <span class="viz-lbl"><?= e($t['name']) ?></span>
          <span class="viz-track"><?php if ($maxT > 0 && $t['median'] !== null && $t['median'] > 0): ?><span class="viz-fill" style="width:<?= max(1, round(100 * $t['median'] / $maxT, 1)) ?>%"></span><?php endif; ?></span>
          <span class="viz-val"><strong><?= e(crm_duration($t['median'])) ?></strong><?= $t['n'] ? ' · ' . $t['n'] : '' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="viz card" style="margin-top:16px">
  <h2>Won and lost, by week</h2>
  <?php if (!$weekly): ?>
    <p class="text-muted">No leads were won or lost in this period.</p>
  <?php else: $maxW = max(array_map(fn($w) => max($w['won'], $w['lost']), $weekly)) ?: 1; ?>
    <div class="viz-weeks">
      <?php foreach ($weekly as $w): ?>
        <div class="viz-week" data-tip="<?= e('Week of ' . $w['week'] . ': ' . $w['won'] . ' won, ' . $w['lost'] . ' lost') ?>">
          <div class="viz-pair">
            <span class="viz-col won"  style="height:<?= round(100 * $w['won'] / $maxW) ?>%"><?= $w['won'] ? '<b>' . $w['won'] . '</b>' : '' ?></span>
            <span class="viz-col lost" style="height:<?= round(100 * $w['lost'] / $maxW) ?>%"><?= $w['lost'] ? '<b>' . $w['lost'] . '</b>' : '' ?></span>
          </div>
          <span class="viz-wk"><?= e($w['week']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="viz-legend"><span><span class="viz-key" style="background:var(--won)"></span>Won</span>
      <span><span class="viz-key" style="background:var(--lost)"></span>Lost</span></div>
  <?php endif; ?>
</div>

<div class="card card-flush" style="margin-top:16px">
  <div style="padding:14px 18px" class="row-between">
    <h2 style="border:0;padding:0;margin:0">Where leads come from</h2>
    <a class="btn btn-ghost btn-sm" href="?<?= $q(['export' => 'sources']) ?>">Export CSV</a>
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Source</th><th class="num">Leads</th><th class="num">Won</th><th class="num">Won value</th><th class="num">Conversion</th></tr></thead>
    <tbody>
    <?php if (!$sources): ?><tr><td colspan="5"><div class="empty">No leads in this period.</div></td></tr><?php endif; ?>
    <?php foreach ($sources as $r): ?>
      <tr><td><?= e($r['label']) ?></td><td class="num"><?= (int) $r['leads'] ?></td><td class="num"><?= (int) $r['won'] ?></td>
        <td class="num"><?= number_format((float) $r['won_value']) ?></td><td class="num"><?= $r['rate'] !== null ? $r['rate'] . '%' : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<?php if ($projList): ?>
<div class="card card-flush" style="margin-top:16px" id="by-project">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Projects</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Leads that arrived in this period, by the project they are for.</p></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Project</th><th class="num">Leads</th><th class="num">Hot now</th><th class="num">Won</th><th class="num">Lost</th><th class="num">Won value</th><th class="num">Conversion</th></tr></thead>
    <tbody>
    <?php if (!$byProject): ?><tr><td colspan="7"><div class="empty">No leads in this period.</div></td></tr><?php endif; ?>
    <?php foreach ($byProject as $r): ?>
      <tr><td><?= e($r['label']) ?></td><td class="num"><?= (int) $r['leads'] ?></td><td class="num"><?= (int) $r['hot'] ?></td>
        <td class="num"><?= (int) $r['won'] ?></td><td class="num"><?= (int) $r['lost'] ?></td>
        <td class="num"><?= number_format((float) $r['won_value']) ?></td><td class="num"><?= $r['rate'] !== null ? $r['rate'] . '%' : '—' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>

<?php if ($visitsByUser): ?>
<div class="card card-flush" style="margin-top:16px" id="visits-report">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Site visits</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Visits in this period. <strong>Came</strong> is out of the visits marked either way;
      <strong>then bought</strong> is out of the people who came.</p></div>
  <?php foreach (array_filter(['Salesperson' => $visitsByUser, 'Project' => $visitsByProj]) as $head => $rows): ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th><?= $head ?></th><th class="num">Booked</th><th class="num">Came</th><th class="num">Didn't come</th><th class="num">Show rate</th><th class="num">Then bought</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['label']) ?></td><td class="num"><?= (int) $r['booked'] ?></td><td class="num"><?= (int) $r['came'] ?></td><td class="num"><?= (int) $r['no_show'] ?></td>
        <td class="num"><?= $r['show_rate'] !== null ? $r['show_rate'] . '%' : '—' ?></td>
        <td class="num"><?= (int) $r['bought'] ?><?= $r['sale_rate'] !== null ? ' · ' . $r['sale_rate'] . '%' : '' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card viz" style="margin-top:16px" id="lost-why">
  <h2>Why deals were lost</h2>
  <?php if (!$lostWhy): ?><p class="text-muted">No leads were lost in this period.</p>
  <?php else: $maxN = max(array_column($lostWhy, 'n')); ?>
    <div class="viz-bars" role="list">
    <?php foreach ($lostWhy as $r): $top = array_slice($r['projects'], 0, 2, true); ?>
      <div class="viz-row" role="listitem" data-tip="<?= e($r['reason'] . ': ' . $r['n'] . ' (' . $r['share'] . '%)' . ($top ? ' — most in ' . implode(', ', array_map(fn($k, $v) => "$k ($v)", array_keys($top), $top)) : '')) ?>">
        <span class="viz-lbl"><?= e($r['reason']) ?></span>
        <span class="viz-track"><span class="viz-fill" style="background:var(--lost);width:<?= round(100 * $r['n'] / $maxN, 1) ?>%"></span></span>
        <span class="viz-val"><strong><?= (int) $r['n'] ?></strong> · <?= $r['share'] ?>%</span>
      </div>
    <?php endforeach; ?>
    </div>
    <p class="text-muted" style="font-size:12px;margin:10px 0 0">Hover a reason to see which projects it happens in most.</p>
  <?php endif; ?>
</div>

<?php if ($ads): ?>
<div class="card card-flush" style="margin-top:16px">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Click-to-WhatsApp ads, by sales</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">What each ad produced in deals, not just in messages.</p></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Ad</th><th class="num">Leads</th><th class="num">Won</th><th class="num">Won value</th></tr></thead>
    <tbody><?php foreach ($ads as $a): ?>
      <tr><td><?= e(ads_label($a['headline'] ?? null, (string) $a['source_id'])) ?></td><td class="num"><?= (int) $a['leads'] ?></td>
        <td class="num"><?= (int) $a['won'] ?></td><td class="num"><?= number_format((float) $a['won_value']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>

<div class="viz-tip" id="viz-tip" role="tooltip"></div>
<script>
/* One tooltip for every chart. The hit target is the whole row or week column — larger than
   the bar itself, so a short bar is as easy to point at as a long one. */
(() => {
  const tip = document.getElementById('viz-tip');
  document.querySelectorAll('[data-tip]').forEach(el => {
    el.addEventListener('mousemove', e => {
      tip.textContent = el.dataset.tip; tip.style.display = 'block';
      tip.style.left = Math.min(e.clientX + 12, innerWidth - tip.offsetWidth - 8) + 'px';
      tip.style.top  = (e.clientY + 14) + 'px';
    });
    el.addEventListener('mouseleave', () => tip.style.display = 'none');
  });
})();
</script>
<?php layout_footer();
