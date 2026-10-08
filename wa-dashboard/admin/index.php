<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/stats.php';

/**
 * The platform at a glance: how much every client sends and receives, credits spent and sold,
 * which clients are growing, and who is about to run out of credits.
 */
$P   = viz_period($_GET, '30d');
$cmp = viz_prev_words($P);

$clientCount   = (int) db_val("SELECT COUNT(*) FROM clients");
$activeClients = (int) db_val("SELECT COUNT(*) FROM clients WHERE status='active'");
$now  = stats_msg_totals(null, $P['start'], $P['end']);
$was  = stats_msg_totals(null, $P['prev_start'], $P['prev_end']);
$ser  = stats_msg_series(null, $P);
$cr   = stats_credits(null, $P['start'], $P['end']);
$crW  = stats_credits(null, $P['prev_start'], $P['prev_end']);
$act  = stats_active_clients($P['start'], $P['end']);
$actW = stats_active_clients($P['prev_start'], $P['prev_end']);
$newC = (int) db_val("SELECT COUNT(*) FROM clients WHERE created_at BETWEEN ? AND ?", [$P['start'], $P['end']]);
$newCW = (int) db_val("SELECT COUNT(*) FROM clients WHERE created_at BETWEEN ? AND ?", [$P['prev_start'], $P['prev_end']]);
$chans = stats_channels(null, $P['start'], $P['end']);
$per   = stats_per_client($P['start'], $P['end']);
$credSeries = stats_credit_series(null, $P);

// Running out: active clients whose balance lasts under two weeks at this period's pace, or under 100.
$runningOut = array_values(array_filter($per, fn($r) => $r['status'] === 'active' && ((int) $r['credits_balance'] < 100 || ($r['days_left'] !== null && $r['days_left'] < 14))));
usort($runningOut, fn($a, $b) => ($a['days_left'] ?? 9999) <=> ($b['days_left'] ?? 9999) ?: (int) $a['credits_balance'] <=> (int) $b['credits_balance']);

$recent = db_all(
    "SELECT c.id, c.name, c.status, c.total_count, c.sent_count, c.read_count, c.failed_count, c.created_at, cl.name AS client_name, cl.id AS client_id
       FROM campaigns c JOIN clients cl ON cl.id = c.client_id
       ORDER BY c.id DESC LIMIT 8"
);

layout_header('Overview', 'admin', 'overview');
page_head('Overview', '<a class="btn btn-ghost btn-sm" href="reports.php?' . e(http_build_query(['p' => $P['key'], 'from' => $P['from'], 'to' => $P['to']])) . '">Per-client report →</a>');
?>
<?= viz_period_bar($P) ?>

<div class="viz-kpis">
  <?= viz_kpi('Clients sending', $act, $actW, ['compare' => $cmp, 'sub' => $activeClients . ' active of ' . $clientCount . ' · ' . $newC . ' new', 'href' => 'clients.php']) ?>
  <?= viz_kpi('Messages sent', $now['sent'], $was['sent'], ['compare' => $cmp, 'spark' => $ser['sent'], 'sub' => number_format($now['failed']) . ' failed']) ?>
  <?= viz_kpi('Messages received', $now['received'], $was['received'], ['compare' => $cmp, 'spark' => $ser['received'], 'slot' => 3]) ?>
  <?= viz_kpi('Read rate', stats_pct($now['read'], $now['sent']), stats_pct($was['read'], $was['sent']), ['fmt' => 'pct', 'compare' => $cmp]) ?>
  <?= viz_kpi('Credits used', $cr['used'], $crW['used'], ['compare' => $cmp, 'spark' => $credSeries, 'slot' => 4]) ?>
  <?= viz_kpi('Credits added', $cr['added'], $crW['added'], ['compare' => $cmp, 'sub' => 'top-ups and plan grants']) ?>
  <?= viz_kpi('New clients', $newC, $newCW, ['compare' => $cmp]) ?>
  <?php $smA = stats_sms(null, $P['start'], $P['end']); $smAW = stats_sms(null, $P['prev_start'], $P['prev_end']); if ($smA['sent'] || $smAW['sent']): ?>
    <?= viz_kpi('SMS sent', $smA['sent'], $smAW['sent'], ['compare' => $cmp, 'sub' => number_format($smA['failed']) . ' failed · ' . number_format($smA['parts']) . ' parts', 'href' => 'sms.php']) ?>
  <?php endif; ?>
  <?= viz_kpi('Running low', count($runningOut), null, ['up_good' => false, 'sub' => 'under 2 weeks of credits, or under 100', 'href' => '#running-out']) ?>
</div>

<?= viz_card('Messages across all clients', viz_trend($ser['labels'], [
      ['label' => 'Sent', 'values' => $ser['sent'], 'slot' => 1], ['label' => 'Received', 'values' => $ser['received'], 'slot' => 3],
      ['label' => 'Failed', 'values' => $ser['failed'], 'slot' => 2],
    ], ['aria' => 'Messages sent, received and failed across all clients', 'x_label' => ucfirst($P['bucket'])]),
    'By ' . $P['bucket'] . ', ' . strtolower($P['label']) . '.', 'platform-trend') ?>

<div class="viz-grid2" style="margin-top:16px">
  <?= viz_card('Busiest clients', viz_bars(array_map(fn($r) => ['label' => (string) $r['name'], 'href' => 'client.php?id=' . (int) $r['id'],
        'sent' => (int) $r['sent'], 'recv' => (int) $r['recv'], 'note' => ($r['read_rate'] !== null ? $r['read_rate'] . '% read · ' : '') . number_format((int) $r['used']) . ' credits'],
        array_slice(array_values(array_filter($per, fn($r) => $r['sent'] + $r['recv'] > 0)), 0, 10)),
        [['sent', 'Sent', 1], ['recv', 'Received', 3]], ['sub' => 'note', 'empty' => 'No messages in this period.']),
      'The ten clients that sent the most.', 'top-clients') ?>
  <?php $chName = ['whatsapp' => 'WhatsApp', 'messenger' => 'Messenger', 'instagram' => 'Instagram']; $slot = ['whatsapp' => 3, 'messenger' => 1, 'instagram' => 5]; ?>
  <?= viz_card('Credits', viz_trend($ser['labels'], [['label' => 'Credits used', 'values' => $credSeries, 'slot' => 4]], ['type' => 'columns', 'height' => 160, 'aria' => 'Credits used'])
      . '<h3 class="viz-sub">Messages by channel</h3>'
      . viz_share(array_map(fn($ch, $c) => [$chName[$ch] ?? ucfirst($ch), $c['in'] + $c['out'], $slot[$ch] ?? 4], array_keys($chans), $chans)),
      'Credits spent by clients (net of refunds), and how messages split across channels.', 'credits') ?>
</div>

<?php if ($runningOut): ?>
<div class="card card-flush" id="running-out" style="margin-top:16px">
  <div style="padding:16px 22px"><h2 style="border:0;padding:0;margin:0">Running low on credits</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">At the pace of <?= e(strtolower($P['label'])) ?>.</p></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Client</th><th class="num">Balance</th><th class="num">Used in period</th><th class="num">Lasts about</th><th></th></tr></thead>
    <tbody>
    <?php foreach (array_slice($runningOut, 0, 12) as $c): ?>
      <tr><td><?= e((string) $c['name']) ?></td>
        <td class="num"><span class="pill <?= (int) $c['credits_balance'] < 100 ? 'red' : 'gold' ?>"><?= number_format((int) $c['credits_balance']) ?></span></td>
        <td class="num"><?= number_format((int) $c['used']) ?></td>
        <td class="num"><?= $c['days_left'] !== null ? number_format($c['days_left']) . ' days' : '—' ?></td>
        <td style="text-align:end"><a class="btn-link" href="client.php?id=<?= (int) $c['id'] ?>#credits">Top up →</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>

<div class="card card-flush" style="margin-top:16px">
  <div style="padding:16px 22px" class="row-between">
    <h2 style="border:0;padding:0;margin:0">Recent campaigns</h2>
    <a class="btn btn-ghost btn-sm" href="clients.php">Manage clients →</a>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Campaign</th><th>Client</th><th>Status</th><th>Results</th><th class="num">Sent</th><th>Created</th></tr></thead>
      <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="6"><div class="empty">No campaigns yet.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td><a class="btn-link" href="client.php?id=<?= (int) $c['client_id'] ?>"><?= e($c['client_name']) ?></a></td>
          <td><?= status_pill((string) $c['status']) ?></td>
          <td style="min-width:130px"><?= viz_share([['Read', (int) $c['read_count'], 1], ['Sent, not read', max(0, (int) $c['sent_count'] - (int) $c['read_count']), 3],
                ['Failed', (int) $c['failed_count'], 'bad'], ['Waiting', max(0, (int) $c['total_count'] - (int) $c['sent_count'] - (int) $c['failed_count']), 7]], ['legend' => false, 'empty' => '—']) ?></td>
          <td class="num"><?= (int) $c['sent_count'] ?> / <?= (int) $c['total_count'] ?></td>
          <td class="text-muted"><?= e(date('d M, H:i', strtotime((string) $c['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_footer();
