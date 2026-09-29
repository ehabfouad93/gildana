<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';

/**
 * Site visits, a week at a time. Salespeople see the visits they host or whose lead is theirs;
 * managers see everyone's. Visits that have happened but nobody marked are listed first, because
 * "did they come?" is what the visit-to-sale numbers are built from.
 */
$cid = (int) $CLIENT['id'];
$isAdmin = is_client_admin();
$start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? strtotime((string) $_GET['from']) : strtotime(date('Y-m-d'));
$f = ['user' => is_sales() ? 0 : (int) ($_GET['user'] ?? 0), 'project' => (int) ($_GET['project'] ?? 0)];
$days = 7;
$visits = crm_visits_between($cid, date('Y-m-d 00:00:00', $start), date('Y-m-d 00:00:00', strtotime("+$days days", $start)), $f);
$byDay = [];
foreach ($visits as $v) $byDay[date('Y-m-d', strtotime((string) $v['starts_at']))][] = $v;
$unmarked = array_filter(crm_visits_between($cid, date('Y-m-d H:i:s', strtotime('-30 days')), date('Y-m-d H:i:s'), $f),
                         fn($v) => $v['status'] === 'scheduled');
$people = crm_assignable_users($cid);
$projects = crm_projects($cid);
$focus = (int) ($_GET['visit'] ?? 0);
$q = fn(array $x) => e(http_build_query(array_filter($x + ['user' => $f['user'] ?: null, 'project' => $f['project'] ?: null])));

client_header('Site visits', 'crm', $CLIENT);
page_head('Site visits', $isAdmin ? '<a class="btn btn-ghost btn-sm" href="crm_messages.php#visit-msgs">Visit messages</a>' : '');
?>
<form class="crm-bar" method="get">
  <input type="hidden" name="from" value="<?= e(date('Y-m-d', $start)) ?>">
  <?php if (!is_sales()): ?>
  <select name="user"><option value="">Everyone</option>
    <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $f['user'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <?php if ($projects): ?>
  <select name="project"><option value="">Every project</option>
    <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $f['project'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <button class="btn btn-ghost btn-sm">Filter</button>
  <span class="cal-nav">
    <a class="btn btn-ghost btn-sm" href="?<?= $q(['from' => date('Y-m-d', strtotime('-7 days', $start))]) ?>" aria-label="Previous week">←</a>
    <a class="btn btn-ghost btn-sm" href="?<?= $q(['from' => date('Y-m-d')]) ?>">Today</a>
    <a class="btn btn-ghost btn-sm" href="?<?= $q(['from' => date('Y-m-d', strtotime('+7 days', $start))]) ?>" aria-label="Next week">→</a>
  </span>
</form>

<?php if ($unmarked): ?>
<div class="card" id="unmarked">
  <h2>Did they come?</h2>
  <p class="text-muted" style="font-size:12.5px;margin-top:-4px">These visits have passed without an answer. Mark them on the lead — it is what the
    visit-to-sale numbers count.</p>
  <?php foreach ($unmarked as $v): ?>
    <div class="dup-row"><div><a href="crm_lead.php?id=<?= (int) $v['contact_id'] ?>#visits"><strong><?= e((string) ($v['lead_name'] ?: crm_phone_show((string) $v['phone_e164']))) ?></strong></a>
      <span class="text-muted" style="display:block;font-size:12.5px"><?= e(date('D j M, H:i', strtotime((string) $v['starts_at']))) ?>
        <?= $v['project_name'] ? ' · ' . e((string) $v['project_name']) : '' ?><?= $v['host_name'] ? ' · ' . e((string) $v['host_name']) : '' ?></span></div>
      <a class="btn btn-ghost btn-sm" href="crm_lead.php?id=<?= (int) $v['contact_id'] ?>#visits">Mark it</a></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="cal-week" id="cal">
  <?php for ($i = 0; $i < $days; $i++): $d = strtotime("+$i days", $start); $key = date('Y-m-d', $d); $list = $byDay[$key] ?? []; ?>
    <section class="cal-day <?= $key === date('Y-m-d') ? 'today' : '' ?>">
      <header><span class="cal-dow"><?= e(date('D', $d)) ?></span> <span class="cal-date"><?= e(date('j M', $d)) ?></span>
        <?php if ($list): ?><span class="crm-n"><?= count($list) ?></span><?php endif; ?></header>
      <?php if (!$list): ?><p class="cal-none">No visits</p><?php endif; ?>
      <?php foreach ($list as $v): ?>
        <a class="cal-visit <?= e((string) $v['status']) ?> <?= $focus === (int) $v['id'] ? 'focus' : '' ?>" id="visit-<?= (int) $v['id'] ?>"
           href="crm_lead.php?id=<?= (int) $v['contact_id'] ?>#visits">
          <span class="cal-time"><?= e(date('H:i', strtotime((string) $v['starts_at']))) ?><?= ($v['kind'] ?? 'site') === 'online' ? ' · Online' : '' ?></span>
          <strong><?= e((string) ($v['lead_name'] ?: crm_phone_show((string) $v['phone_e164']))) ?></strong>
          <?php if ($v['project_name']): ?><span class="cal-meta"><?= e((string) $v['project_name']) ?></span><?php endif; ?>
          <?php if ($v['host_name'] && !is_sales()): ?><span class="cal-meta">with <?= e((string) $v['host_name']) ?></span><?php endif; ?>
          <?php if ($v['status'] !== 'scheduled'): ?><span class="cal-status"><?= e(crm_visit_statuses()[$v['status']]) ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </section>
  <?php endfor; ?>
</div>
<p class="text-muted" style="font-size:12px">Book a visit from the lead's page. Visits there can be moved, cancelled, and marked came or didn't come.</p>
<?php if ($focus): ?><script>document.getElementById('visit-<?= $focus ?>')?.scrollIntoView({block: 'center'});</script><?php endif; ?>
<?php layout_footer();
