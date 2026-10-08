<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/stats.php';

/** Every client over a period: what they sent, how it landed, what they spent, and how long their credits last. */
$P   = viz_period($_GET, '30d');
$cmp = viz_prev_words($P);
$per = stats_per_client($P['start'], $P['end']);
$perW = [];
foreach (stats_per_client($P['prev_start'], $P['prev_end']) as $r) $perW[(int) $r['id']] = $r;
$sum = fn(array $rows, string $k) => array_sum(array_map(fn($r) => (float) $r[$k], $rows));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clients-' . $P['from'] . '-' . $P['to'] . '.csv"');
    echo "\xEF\xBB\xBF" . implode(',', array_map('csv_cell', ['Client', 'Status', 'Campaigns', 'Sent', 'Sent before', 'Delivered', 'Read', 'Read rate %', 'Failed', 'Received', 'People who wrote', 'Credits used', 'Balance', 'Days left'])) . "\n";
    foreach ($per as $r) {
        echo implode(',', array_map('csv_cell', [(string) $r['name'], (string) $r['status'], (string) $r['campaigns'], (string) $r['sent'], (string) ($perW[(int) $r['id']]['sent'] ?? 0),
            (string) $r['dl'], (string) $r['rd'], (string) ($r['read_rate'] ?? ''), (string) $r['failed'], (string) $r['recv'], (string) $r['people'], (string) $r['used'],
            (string) $r['credits_balance'], (string) ($r['days_left'] ?? '')])) . "\n";
    }
    exit;
}

$sent = $sum($per, 'sent'); $sentW = $sum($perW, 'sent');
layout_header('Reports', 'admin', 'reports');
page_head('Global reports', '<a class="btn btn-ghost btn-sm" href="?' . e(http_build_query(['p' => $P['key'], 'from' => $P['from'], 'to' => $P['to'], 'export' => 'csv'])) . '">Export CSV</a>');
?>
<?= viz_period_bar($P) ?>
<div class="viz-kpis">
  <?= viz_kpi('Messages sent', $sent, $sentW, ['compare' => $cmp]) ?>
  <?= viz_kpi('Delivered', stats_pct($sum($per, 'dl'), $sent), stats_pct($sum($perW, 'dl'), $sentW), ['fmt' => 'pct', 'compare' => $cmp]) ?>
  <?= viz_kpi('Read', stats_pct($sum($per, 'rd'), $sent), stats_pct($sum($perW, 'rd'), $sentW), ['fmt' => 'pct', 'compare' => $cmp]) ?>
  <?= viz_kpi('Failed', $sum($per, 'failed'), $sum($perW, 'failed'), ['compare' => $cmp, 'up_good' => false]) ?>
  <?= viz_kpi('Messages received', $sum($per, 'recv'), $sum($perW, 'recv'), ['compare' => $cmp]) ?>
  <?= viz_kpi('Credits used', $sum($per, 'used'), $sum($perW, 'used'), ['compare' => $cmp]) ?>
</div>

<?php $withSends = array_values(array_filter($per, fn($r) => (int) $r['sent'] >= 20)); usort($withSends, fn($a, $b) => ($b['read_rate'] ?? 0) <=> ($a['read_rate'] ?? 0)); ?>
<div class="viz-grid2">
  <?= viz_card('Growth by client', viz_bars(array_map(fn($r) => ['label' => (string) $r['name'], 'href' => 'client.php?id=' . (int) $r['id'],
        'now' => (int) $r['sent'], 'was' => (int) ($perW[(int) $r['id']]['sent'] ?? 0)], array_slice(array_values(array_filter($per, fn($r) => $r['sent'] > 0)), 0, 10)),
        [['now', 'This period', 1], ['was', 'Period before', 7]], ['empty' => 'No messages in this period.']),
      'Messages sent, against the period before — who is growing and who went quiet.', 'growth') ?>
  <?= viz_card('Read rate by client', viz_bars(array_map(fn($r) => ['label' => (string) $r['name'], 'href' => 'client.php?id=' . (int) $r['id'], 'rr' => $r['read_rate'] ?? 0,
        'note' => number_format((int) $r['sent']) . ' sent · ' . number_format((int) $r['failed']) . ' failed'], array_slice($withSends, 0, 10)),
        [['rr', 'Read', 3]], ['fmt' => 'pct', 'sub' => 'note', 'empty' => 'No client sent 20 messages or more in this period.']),
      'Clients that sent at least 20 messages. A low rate can mean a poor list or a wrong template.', 'read-rates') ?>
</div>

<div class="card card-flush" style="margin-top:16px">
  <div style="padding:16px 22px"><h2 style="border:0;padding:0;margin:0">Every client</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Client</th><th class="num">Campaigns</th><th class="num">Sent</th><th>Results</th><th class="num">Read</th><th class="num">Failed</th><th class="num">Received</th><th class="num">Credits used</th><th class="num">Balance</th><th class="num">Lasts</th></tr></thead>
      <tbody>
      <?php if (!$per): ?><tr><td colspan="10"><div class="empty">No clients yet.</div></td></tr><?php endif; ?>
      <?php foreach ($per as $r): $s = (int) $r['sent']; $w = (int) ($perW[(int) $r['id']]['sent'] ?? 0); ?>
        <tr>
          <td><a class="btn-link" href="client.php?id=<?= (int) $r['id'] ?>"><?= e((string) $r['name']) ?></a><?= $r['status'] !== 'active' ? ' <span class="pill gray">' . e((string) $r['status']) . '</span>' : '' ?></td>
          <td class="num"><?= (int) $r['campaigns'] ?></td>
          <td class="num"><?= number_format($s) ?><?php if ($w || $s): ?><br><small class="text-muted"><?= $w ? (($s >= $w ? '▲ ' : '▼ ') . abs((int) round(100 * ($s - $w) / $w)) . '%') : 'new' ?></small><?php endif; ?></td>
          <td style="min-width:120px"><?= viz_share([['Read', (int) $r['rd'], 1], ['Delivered, not read', max(0, (int) $r['dl'] - (int) $r['rd']), 3],
                ['Sent, not delivered', max(0, $s - (int) $r['dl']), 4], ['Failed', (int) $r['failed'], 'bad']], ['legend' => false, 'empty' => '—']) ?></td>
          <td class="num"><?= e(viz_fmt($r['read_rate'], 'pct')) ?></td>
          <td class="num"><?= (int) $r['failed'] ? '<span class="pill red">' . number_format((int) $r['failed']) . '</span>' : '0' ?></td>
          <td class="num"><?= number_format((int) $r['recv']) ?></td>
          <td class="num"><?= number_format((int) $r['used']) ?></td>
          <td class="num"><?= number_format((int) $r['credits_balance']) ?></td>
          <td class="num"><?= $r['days_left'] !== null ? '<span class="' . ($r['days_left'] < 14 ? 'pill red' : '') . '">' . number_format($r['days_left']) . ' d</span>' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_footer(); ?>
