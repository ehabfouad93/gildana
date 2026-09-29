<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm_library.php';
require_once __DIR__ . '/../includes/ads.php';

/**
 * Reports: a library of report cards, and each report behind the same filter bar.
 *
 * Every number opens the leads behind it; every report exports to Excel, prints to PDF and can be
 * shared on WhatsApp. Sales see their own leads only (a team leader: their team's) — the scope is
 * applied inside the reports, so an export cannot show more than the screen does.
 */
$cid = (int) $CLIENT['id'];
$me  = (int) ($PERM_USER['id'] ?? 0);
$cat = crm_lib_catalog();
// Old links (?export=sales) keep working.
if (!isset($_GET['r']) && isset($_GET['export']) && isset($cat[(string) $_GET['export']])) { $_GET['r'] = $_GET['export']; $_GET['export'] = 'csv'; }
$key = (string) ($_GET['r'] ?? '');
if ($key !== '' && !isset($cat[$key])) $key = '';
// The daily report is about today unless another day is asked for.
if ($key === 'daily' && !isset($_GET['period']) && empty($_GET['from'])) $_GET['period'] = 'today';
$f = crm_lib_filters($_GET);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'daily_settings' && is_client_admin()) {
    verify_csrf();
    $h = (string) ($_POST['hour'] ?? '');
    $s = crm_settings($cid);
    $kinds = array_filter(explode(',', (string) $s['staff_wa_kinds']));
    $kinds = !empty($_POST['whatsapp']) ? array_values(array_unique(array_merge($kinds, ['daily']))) : array_values(array_diff($kinds, ['daily']));
    crm_settings_set($cid, ['daily_hour' => $h === '' ? null : max(0, min(23, (int) $h)), 'staff_wa_kinds' => implode(',', $kinds)]);
    flash($h === '' ? 'The daily report will not be sent.' : 'The daily report goes to managers every day at ' . sprintf('%02d:00', (int) $h) . '.');
    redirect('crm_reports.php?r=daily');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fav') {
    verify_csrf();
    $k = (string) ($_POST['key'] ?? '');
    $favs = crm_lib_favs($PERM_USER);
    if (isset($cat[$k])) $favs = in_array($k, $favs, true) ? array_values(array_diff($favs, [$k])) : array_merge($favs, [$k]);
    if (db_has_column('users', 'report_favs')) db_run("UPDATE users SET report_favs=? WHERE id=?", [$favs ? implode(',', $favs) : null, $me]);
    json_out(['ok' => true, 'on' => in_array($k, $favs, true)]);
}

$rep = $key !== '' ? crm_lib_run($cid, $key, $f) : null;

/* ── Export: exactly the table on screen ── */
if ($rep && ($x = (string) ($_GET['export'] ?? '')) !== '') {
    $head = array_map(fn($c) => $c[0], $rep['cols']);
    $raw = function (array $r) use ($rep, $cid) {
        $o = [];
        foreach ($rep['cols'] as $k => [$l, $t]) {
            $v = $r[$k] ?? null;
            $o[] = $v === null || $v === '' ? '' : (in_array($t, ['num', 'money', 'pct'], true) ? (float) $v + 0 : ($t === 'dur' ? crm_duration((float) $v) : ($t === 'days' ? round((float) $v, 1) : (string) $v)));
        }
        return $o;
    };
    $rows = array_map($raw, $rep['rows']);
    if ($rep['total']) $rows[] = $raw($rep['total']);
    $fname = 'report-' . $key . '-' . $f['from'] . '-to-' . $f['to'];
    if (function_exists('crm_audit')) crm_audit($cid, $me, 'export', null, 'report ' . $key, count($rep['rows']));
    if ($x === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $head); foreach ($rows as $r) fputcsv($o, $r); exit;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '.xlsx"');
    echo crm_xlsx($head, $rows, mb_substr($cat[$key][1], 0, 31));
    exit;
}

$active = crm_lib_active($f);
$qs = fn(array $set = [], array $drop = []) => '?' . http_build_query(array_diff_key(array_filter(array_merge($active, $set), fn($v) => $v !== '' && $v !== null), array_flip($drop)));
$periodWords = $f['period'] === 'custom' || $f['from'] !== $f['to']
    ? date('j M Y', strtotime($f['from'])) . ' – ' . date('j M Y', strtotime($f['to'])) : date('j M Y', strtotime($f['from']));
$people = crm_assignable_users($cid);
$isLeader = function_exists('crm_is_team_leader') && crm_is_team_leader();
if ($isLeader) { $vis = crm_visible_owner_ids(); $people = array_values(array_filter($people, fn($u) => in_array((int) $u['id'], $vis, true))); }
$projects = crm_projects($cid);
$teams = crm_teams($cid);
$sources = array_column(db_all("SELECT DISTINCT source FROM contacts WHERE client_id=? AND crm_added_at IS NOT NULL AND source IS NOT NULL", [$cid]), 'source');
$favs = crm_lib_favs($PERM_USER);

client_header('Reports', 'crm', $CLIENT);

/* The bar every report shares. */
$filterBar = function (string $r) use ($f, $people, $projects, $teams, $sources, $isLeader) { ?>
  <form class="rep-filterbar" method="get" id="rep-filter">
    <?php if ($r !== ''): ?><input type="hidden" name="r" value="<?= e($r) ?>"><?php endif; ?>
    <div class="rep-periods" role="group" aria-label="Period">
      <?php foreach (crm_lib_periods() as $k => $l): ?>
        <label class="rep-chip <?= $f['period'] === $k ? 'on' : '' ?>"><input type="radio" name="period" value="<?= $k ?>" <?= $f['period'] === $k ? 'checked' : '' ?>
          onchange="<?= $k === 'custom' ? "document.getElementById('rep-dates').hidden=false" : 'this.form.submit()' ?>"><?= e($l) ?></label>
      <?php endforeach; ?>
      <span id="rep-dates" <?= $f['period'] === 'custom' ? '' : 'hidden' ?>>
        <input type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From"> – <input type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To"></span>
    </div>
    <div class="rep-periods" role="group" aria-label="Fresh or cold data">
      <?php foreach (['' => 'All', 'fresh' => 'Fresh', 'cold' => 'Cold data'] as $k => $l): ?>
        <label class="rep-chip <?= $f['dtype'] === $k ? 'on' : '' ?>"><input type="radio" name="dtype" value="<?= $k ?>" <?= $f['dtype'] === $k ? 'checked' : '' ?> onchange="this.form.submit()"><?= e($l) ?></label>
      <?php endforeach; ?>
    </div>
    <?php if ($projects): ?><select name="project" aria-label="Project"><option value="">All projects</option>
      <?php foreach ($projects as $pj): ?><option value="<?= (int) $pj['id'] ?>" <?= $f['project'] === (string) $pj['id'] ? 'selected' : '' ?>><?= e($pj['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <select name="source" aria-label="Source"><option value="">All sources</option>
      <?php foreach ($sources as $s): ?><option value="<?= e($s) ?>" <?= $f['source'] === $s ? 'selected' : '' ?>><?= e(crm_source_label($s)) ?></option><?php endforeach; ?></select>
    <select name="platform" aria-label="Platform"><option value="">All platforms</option>
      <?php foreach (crm_platforms() as $k => $l): ?><option value="<?= $k ?>" <?= $f['platform'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <input name="campaign" value="<?= e($f['campaign']) ?>" placeholder="Campaign" aria-label="Campaign" style="max-width:150px">
    <?php if ($teams && !is_sales()): ?><select name="team" aria-label="Team"><option value="">All teams</option>
      <?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $f['team'] === (string) $t['id'] ? 'selected' : '' ?>><?= e((string) $t['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <?php if (!is_sales() || $isLeader): ?><select name="owner" aria-label="Salesperson"><option value="">Everyone</option>
      <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $f['owner'] === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <button class="btn btn-primary btn-sm">Apply</button>
    <?php if (count(crm_lib_active($f)) > 0): ?><a class="btn-link" href="?<?= $r !== '' ? 'r=' . e($r) : '' ?>">Clear</a><?php endif; ?>
  </form>
<?php };

if (!$rep):
  page_head('Reports', can_crm('dashboard') ? '<a class="btn btn-ghost btn-sm" href="crm_dashboard.php">Dashboard, targets &amp; leaderboard</a>' : '');
  $groups = [];
  foreach ($cat as $k => $c) $groups[$c[0]][$k] = $c;
  if ($favs) $groups = ['Starred' => array_intersect_key($cat, array_flip($favs))] + $groups; ?>
  <div class="rep-lib-top">
    <input type="search" id="rep-search" placeholder="Find a report — e.g. campaign, lost, visits" aria-label="Find a report">
  </div>
  <?php foreach ($groups as $g => $items): if (!$items) continue; ?>
    <section class="rep-group" data-group="<?= e($g) ?>">
      <h2 class="rep-group-h"><?= e($g) ?></h2>
      <div class="rep-cards">
        <?php foreach ($items as $k => [$grp, $title, $what]): $on = in_array($k, $favs, true); ?>
          <div class="rep-card" data-find="<?= e(mb_strtolower($title . ' ' . $what . ' ' . $grp)) ?>">
            <a href="?<?= e(http_build_query(['r' => $k] + $active)) ?>" class="rep-card-link"><strong><?= e($title) ?></strong><span><?= e($what) ?></span></a>
            <button type="button" class="rep-star <?= $on ? 'on' : '' ?>" data-fav="<?= e($k) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>" title="<?= $on ? 'Unstar' : 'Star — show it first' ?>">★</button>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
  <p class="text-muted rep-none" hidden>No report matches that.</p>
<?php else:
  [$grp, $title, $what] = $cat[$key];
  $on = in_array($key, $favs, true);
  $exportAllowed = true;                 // totals, not phone numbers: anyone who sees the report may keep it
  $shareText = $title . ' — ' . $periodWords . "\n";
  foreach ($rep['tiles'] as [$l, $v, $t]) $shareText .= $l . ': ' . crm_lib_fmt($v, $t, $cid) . "\n";
  if (!$rep['tiles'] && $rep['total']) foreach ($rep['cols'] as $ck => [$cl, $ct]) if ($ck !== 'label' && isset($rep['total'][$ck])) $shareText .= $cl . ': ' . crm_lib_fmt($rep['total'][$ck], $ct, $cid) . "\n";
  $shareText .= rtrim(app_base_url(), '/') . '/client/crm_reports.php' . $qs(['r' => $key]);
  $actions = '<a class="btn btn-ghost btn-sm" href="crm_reports.php' . e($qs()) . '">&larr; All reports</a>'
      . ($exportAllowed ? '<a class="btn btn-ghost btn-sm" href="' . e($qs(['r' => $key, 'export' => 'xlsx'])) . '">Excel</a>' : '')
      . '<button type="button" class="btn btn-ghost btn-sm" onclick="window.print()">PDF</button>'
      . '<a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="https://wa.me/?text=' . e(rawurlencode($shareText)) . '">Share on WhatsApp</a>';
  page_head($title, $actions); ?>
  <p class="rep-sub text-muted"><button type="button" class="rep-star <?= $on ? 'on' : '' ?>" data-fav="<?= e($key) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>" title="Star">★</button>
    <?= e($what) ?> <span class="rep-period"><?= e($periodWords) ?></span></p>
  <?php $filterBar($key); ?>

  <?php if (!empty($rep['empty'])): ?>
    <div class="card empty-state"><h2>No data in this period</h2>
      <p class="text-muted">Nothing matches these filters between <?= e($periodWords) ?>. Try a longer period, or clear a filter.</p></div>
  <?php else: ?>
    <?php if ($rep['tiles']): ?>
      <div class="crm-tiles rep-tiles">
        <?php foreach ($rep['tiles'] as [$l, $v, $t]): ?><div class="crm-tile"><span class="lbl"><?= e($l) ?></span><span class="val"><?= e(crm_lib_fmt($v, $t, $cid)) ?></span></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($rep['note']): ?><p class="rep-note text-muted"><?= e($rep['note']) ?>
      <?php if (!empty($rep['list'])): ?> <a href="crm.php?<?= e(http_build_query(['view' => 'table'] + $rep['list'])) ?>">Open them in the leads list</a><?php endif; ?></p><?php endif; ?>

    <?php /* ── Chart: bars with the number on each; a legend when there are two series ── */
    if ($rep['chart'] && $rep['rows']):
      $ch = $rep['chart']; $series = $ch['series']; $sk = array_keys($series);
      $max = 0; foreach ($rep['rows'] as $r) foreach ($sk as $s) $max = max($max, (float) ($r[$s] ?? 0));
      $rowsC = array_slice($rep['rows'], 0, 25); ?>
      <div class="card rep-chart" id="report-chart" role="img" aria-label="<?= e($title) ?> chart">
        <?php if (count($sk) > 1): ?><div class="rep-legend"><?php foreach ($series as $s => $l): ?><span><i class="sw sw-<?= array_search($s, $sk, true) ?> sw-<?= e($s) ?>"></i><?= e($l) ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php foreach ($rowsC as $r): ?>
          <div class="rep-bar-row">
            <span class="rep-bar-label" title="<?= e((string) $r[$ch['label']]) ?>"><?= e((string) $r[$ch['label']]) ?></span>
            <span class="rep-bar-track">
              <?php foreach ($sk as $i => $s): $v = (float) ($r[$s] ?? 0); $pct = $max > 0 ? max(0.5, 100 * $v / $max) : 0;
                $t = $rep['cols'][$s][1] ?? 'num'; ?>
                <span class="rep-bar-line"><span class="rep-bar sw-<?= $i ?> sw-<?= e($s) ?>" style="width:<?= $v > 0 ? round($pct, 2) : 0 ?>%" title="<?= e($series[$s] . ': ' . crm_lib_fmt($v, $t, $cid)) ?>"></span>
                  <span class="rep-bar-val"><?= e(crm_lib_fmt($r[$s] ?? null, $t, $cid)) ?></span></span>
              <?php endforeach; ?>
            </span>
          </div>
        <?php endforeach; ?>
        <?php if (count($rep['rows']) > 25): ?><p class="text-muted" style="font-size:12px;margin:8px 0 0">The chart shows the first 25; the table has all <?= count($rep['rows']) ?>.</p><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card card-flush"><div class="table-wrap">
      <table class="data rep-table" id="report">
        <thead><tr><?php foreach ($rep['cols'] as $k => [$l, $t]): ?><th class="<?= $t === 'text' ? '' : 'num' ?>"><?= e($l) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php /* A heat map (golden hours, cohorts): each number's cell shaded by its size, one hue, light to dark. */
          $heatMax = 0.0;
          if (!empty($rep['heat'])) foreach ($rep['rows'] as $r_) foreach ($rep['cols'] as $k_ => [$l_, $t_]) if ($t_ !== 'text' && is_numeric($r_[$k_] ?? null)) $heatMax = max($heatMax, (float) $r_[$k_]);
        foreach (array_merge($rep['rows'], $rep['total'] ? [$rep['total']] : []) as $i => $r): $isTotal = $rep['total'] && $i === count($rep['rows']); ?>
          <tr class="<?= $isTotal ? 'rep-total' : '' ?><?= !empty($r['_stage']) ? ' rep-group-row' : '' ?>">
            <?php foreach ($rep['cols'] as $k => [$l, $t]):
              $txt = $heatMax > 0 && ($r[$k] ?? null) === null && $t !== 'text' ? '' : crm_lib_fmt($r[$k] ?? null, $t, $cid);   // a heat map's empty cell stays empty
              $dr = $rep['drill'] && $k !== 'label' && ($r[$k] ?? 0) ? ($rep['drill'])($r, $k) : null; ?>
              <td class="<?= $t === 'text' ? '' : 'num' ?>"<?php if ($heatMax > 0 && $t !== 'text' && is_numeric($r[$k] ?? null)): $lv = (float) $r[$k] / $heatMax; ?> style="background:rgba(18,140,98,<?= round(0.08 + 0.82 * $lv, 2) ?>);<?= $lv > 0.72 ? 'color:#fff' : '' ?>"<?php endif; ?>><?php if ($k === 'label' && !empty($r['_href'])): ?><a href="<?= e($r['_href']) ?>"><?= e($txt) ?></a>
                <?php elseif ($dr !== null): ?><a href="crm.php?<?= e(http_build_query(['view' => 'table'] + $dr)) ?>" title="Open these leads"><?= e($txt) ?></a>
                <?php else: ?><?= e($txt) ?><?php endif; ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
    <?php if ($key === 'daily' && is_client_admin()): $ds = crm_settings($cid); ?>
      <form method="post" class="card rep-daily-set"><?= csrf_field() ?><input type="hidden" name="action" value="daily_settings">
        <strong>Send this every evening</strong>
        <label>to managers (and each team leader, their team) at <select name="hour"><option value="">— not sent —</option>
          <?php for ($h = 16; $h <= 23; $h++): ?><option value="<?= $h ?>" <?= $ds['daily_hour'] !== null && (int) $ds['daily_hour'] === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?></select></label>
        <label class="mod-all" style="margin:0"><input type="checkbox" name="whatsapp" value="1" <?= in_array('daily', explode(',', (string) $ds['staff_wa_kinds']), true) ? 'checked' : '' ?>> also on WhatsApp</label>
        <button class="btn btn-primary btn-sm">Save</button>
        <span class="text-muted" style="font-size:12px">It arrives in the bell; on WhatsApp through the alerts set up in <a href="crm_rules.php#staff-wa">Assignment rules</a>.</span>
      </form>
    <?php endif; ?>
    <p class="text-muted rep-foot">Numbers with a link open the leads behind them. <?= e($CLIENT['name']) ?> · <?= e($periodWords) ?> · made <?= e(date('j M Y, H:i')) ?></p>
  <?php endif; ?>
<?php endif; ?>
<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
document.querySelectorAll('[data-fav]').forEach(b => b.addEventListener('click', async e => {
  e.preventDefault();
  const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('action', 'fav'); fd.append('key', b.dataset.fav);
  const d = await fetch('crm_reports.php', {method: 'POST', body: fd}).then(r => r.json()).catch(() => ({}));
  document.querySelectorAll(`[data-fav="${b.dataset.fav}"]`).forEach(x => { x.classList.toggle('on', !!d.on); x.setAttribute('aria-pressed', d.on ? 'true' : 'false'); });
}));
const find = document.getElementById('rep-search');
if (find) find.addEventListener('input', () => {
  const q = find.value.trim().toLowerCase(); let any = false;
  document.querySelectorAll('.rep-group').forEach(g => { let n = 0;
    g.querySelectorAll('.rep-card').forEach(c => { const m = !q || c.dataset.find.includes(q); c.hidden = !m; if (m) n++; });
    g.hidden = !n; if (n) any = true; });
  document.querySelector('.rep-none').hidden = any;
});
</script>
<?php layout_footer();
