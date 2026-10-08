<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

require_once __DIR__ . '/../includes/stats.php';

/**
 * The dashboard: the period's headline numbers against the period before, then how messages,
 * replies, campaigns and (with the CRM) leads moved over time. A salesperson sees only the
 * conversations of their own leads.
 */
$cid = (int) $CLIENT['id'];
$P   = viz_period($_GET, '30d');
$crm = function_exists('crm_enabled') && crm_enabled($CLIENT) && can_use('crm');
if ($crm) { require_once __DIR__ . '/../includes/crm.php'; require_once __DIR__ . '/../includes/crm_reports.php'; }
$scope = is_sales() && function_exists('crm_scope') ? crm_scope('c') : (is_sales() ? [' AND 1=0', []] : ['', []]);
$seeMoney = is_client_admin() || can_use('billing');

$now  = stats_msg_totals($cid, $P['start'], $P['end'], $scope);
$was  = stats_msg_totals($cid, $P['prev_start'], $P['prev_end'], $scope);
$ser  = stats_msg_series($cid, $P, $scope);
$rt   = stats_reply_time($cid, $P['start'], $P['end'], $scope);
$rtW  = stats_reply_time($cid, $P['prev_start'], $P['prev_end'], $scope);
$cr   = $seeMoney ? stats_credits($cid, $P['start'], $P['end']) : null;
$crW  = $seeMoney ? stats_credits($cid, $P['prev_start'], $P['prev_end']) : null;
// Leads come a few a day: columns per day over a month are too thin to read, so weeks.
$PL = $P['bucket'] === 'day' && $P['days'] > 14 ? ['bucket' => 'week'] + $P : $P;
$leads = $crm ? stats_leads($cid, $P['start'], $P['end'], $PL) : null;
$leadsW = $crm ? stats_leads($cid, $P['prev_start'], $P['prev_end']) : null;
$chans = stats_channels($cid, $P['start'], $P['end'], $scope);
$srcs  = stats_sources($cid, $P['start'], $P['end'], $scope);
$heat  = stats_heat($cid, $P['start'], $P['end'], $scope);
$readRate = stats_pct($now['read'], $now['sent']);
$readRateW = stats_pct($was['read'], $was['sent']);
$cmp = viz_prev_words($P);
$burn = $cr && $P['days'] ? $cr['used'] / $P['days'] : 0;
$daysLeft = $burn > 0 ? (int) floor((int) $CLIENT['credits_balance'] / $burn) : null;

$recent = can_use('campaigns') ? db_all(
    "SELECT id,name,status,total_count,sent_count,delivered_count,read_count,failed_count,created_at
       FROM campaigns WHERE client_id=? ORDER BY id DESC LIMIT 6", [$cid]
) : [];

client_header('Dashboard', 'dashboard', $CLIENT);
page_head('Welcome, ' . $CLIENT['name']);

if (!client_ready($CLIENT)): ?>
  <div class="alert info"><?= channel_is_personal($CLIENT)
      ? 'Your WhatsApp number isn\'t linked yet. Go to <strong>Settings → My WhatsApp Number</strong> and scan the QR code to start sending.'
      : 'Your WhatsApp number isn\'t connected yet. ' . e(BRAND_PARENT) . ' needs to add your API credentials before you can send campaigns.' ?></div>
<?php endif; ?>

<?php
/* The first-run walkthrough, answered from real data rather than a list someone ticks by
   hand. It disappears the moment everything is done — a checklist of six ticks is clutter. */
$gs = guide_checklist($CLIENT);
// Setup is the account Admin's job; a salesperson has no way to act on "connect WhatsApp".
if ($gs['done'] < $gs['total'] && is_client_admin()): ?>
  <div class="card">
    <div class="gs-head">
      <h2 style="margin:0;border:0;padding:0">Getting started</h2>
      <span class="gs-count"><?= $gs['done'] ?> of <?= $gs['total'] ?> done</span>
    </div>
    <p class="text-muted" style="font-size:12.5px;margin:0">
      In this order, and each one takes a few minutes. This card goes away once you are set up.
    </p>
    <div class="gs-bar"><span style="width:<?= (int) round(100 * $gs['done'] / max(1, $gs['total'])) ?>%"></span></div>
    <div class="gs-list">
      <?php foreach ($gs['items'] as $i): ?>
        <div class="gs-item <?= $i['done'] ? 'done' : '' ?>">
          <span class="gs-tick" aria-hidden="true">✓</span>
          <span class="gs-text">
            <b><?= e($i['label']) ?></b>
            <span><?= e($i['help']) ?></span>
          </span>
          <a class="btn btn-ghost btn-sm" href="<?= e($i['url']) ?>"><?= e($i['cta']) ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?= viz_period_bar($P) ?>

<div class="viz-kpis" id="dash-kpis">
  <?= viz_kpi('Messages sent', $now['sent'], $was['sent'], ['spark' => $ser['sent'], 'compare' => $cmp, 'sub' => $now['failed'] ? number_format($now['failed']) . ' failed' : '']) ?>
  <?= viz_kpi('Messages received', $now['received'], $was['received'], ['spark' => $ser['received'], 'slot' => 3, 'compare' => $cmp]) ?>
  <?= viz_kpi('Conversations', $now['conversations'], $was['conversations'], ['compare' => $cmp, 'sub' => 'people who wrote to you']) ?>
  <?= viz_kpi('Read rate', $readRate, $readRateW, ['fmt' => 'pct', 'compare' => $cmp, 'sub' => number_format($now['read']) . ' of ' . number_format($now['sent']) . ' read']) ?>
  <?= viz_kpi('First reply', $rt['median'], $rtW['median'], ['fmt' => 'dur', 'up_good' => false, 'compare' => $cmp,
       'sub' => $rt['in5'] !== null ? $rt['in5'] . '% answered within 5 min' : 'median time to answer']) ?>
  <?= viz_kpi('New contacts', $now['new_contacts'], $was['new_contacts'], ['compare' => $cmp]) ?>
  <?php if ($crm): ?>
    <?= viz_kpi('New leads', $leads['leads'], $leadsW['leads'], ['spark' => $leads['leads_series'], 'slot' => 3, 'compare' => $cmp, 'href' => 'crm.php']) ?>
    <?= viz_kpi('Deals won', $leads['won'], $leadsW['won'], ['spark' => $leads['won_series'], 'compare' => $cmp,
         'sub' => $leads['leads'] ? round(100 * $leads['won'] / $leads['leads'], 1) . '% of new leads' : '', 'href' => 'crm_dashboard.php?tab=reports']) ?>
  <?php endif; ?>
  <?php if ($seeMoney): ?>
    <?= viz_kpi('Credits used', $cr['used'], $crW['used'], ['up_good' => false, 'compare' => $cmp, 'href' => 'billing.php',
         'sub' => number_format((int) $CLIENT['credits_balance']) . ' left' . ($daysLeft !== null ? ' · about ' . number_format($daysLeft) . ' days at this pace' : '')]) ?>
  <?php endif; ?>
</div>

<?= viz_card('Messages over time', viz_trend($ser['labels'], [
      ['label' => 'Sent', 'values' => $ser['sent'], 'slot' => 1],
      ['label' => 'Received', 'values' => $ser['received'], 'slot' => 3],
    ], ['aria' => 'Messages sent and received', 'x_label' => ucfirst($P['bucket']), 'empty' => 'No messages in this period yet.']),
    'Sent counts everything that went out (failed ones excluded); received is what customers wrote. By ' . $P['bucket'] . '.', 'msg-trend') ?>

<div class="viz-grid2" style="margin-top:16px">
  <?= viz_card('What happened to sent messages', viz_funnel([
        ['Sent', $now['sent']], ['Delivered', $now['delivered']], ['Read', $now['read']],
      ], ['empty' => 'Nothing sent in this period.'])
      . ($now['failed'] ? '<p class="viz-note">' . number_format($now['failed']) . ' more failed and never arrived' . (can_use('campaigns') ? ' — <a href="failed.php">see why</a>' : '') . '.</p>' : ''),
      'Delivered and read come from WhatsApp, Messenger and Instagram receipts (people can turn read receipts off).', 'msg-funnel') ?>
  <?= viz_card('Who sent them', viz_bars(array_map(fn($r) => ['label' => stats_source_label((string) $r['src']), 'n' => (int) $r['n'],
        'note' => ($r['n'] - $r['failed']) > 0 ? round(100 * $r['rd'] / max(1, $r['n'] - $r['failed'])) . '% read' : ''], $srcs),
        [['n', 'Messages', 1]], ['sub' => 'note', 'empty' => 'Nothing sent in this period.']),
      'Outgoing messages by what sent them, with how many were read.', 'msg-sources') ?>
</div>

<div class="viz-grid2" style="margin-top:16px">
  <?= viz_card('When customers write', viz_heat($heat, ['unit' => 'messages received', 'empty' => 'No messages received in this period.']),
      'Incoming messages by day of the week and hour — staff your Inbox for the dark squares.', 'msg-heat') ?>
  <?php if (count($chans) > 1 || array_diff(array_keys($chans), ['whatsapp'])):
    $chName = ['whatsapp' => 'WhatsApp', 'messenger' => 'Messenger', 'instagram' => 'Instagram'];
    $slot = ['whatsapp' => 3, 'messenger' => 1, 'instagram' => 5];
    $parts = []; foreach ($chans as $ch => $c) $parts[] = [$chName[$ch] ?? ucfirst($ch), $c['in'] + $c['out'], $slot[$ch] ?? 4]; ?>
    <?= viz_card('Channels', viz_share($parts) . viz_trend($ser['labels'], array_values(array_filter([
          array_sum($ser['by_channel']['whatsapp']) ? ['label' => 'WhatsApp', 'values' => $ser['by_channel']['whatsapp'], 'slot' => 3] : null,
          array_sum($ser['by_channel']['messenger']) ? ['label' => 'Messenger', 'values' => $ser['by_channel']['messenger'], 'slot' => 1] : null,
          array_sum($ser['by_channel']['instagram']) ? ['label' => 'Instagram', 'values' => $ser['by_channel']['instagram'], 'slot' => 5] : null,
        ])), ['type' => 'columns', 'stack' => true, 'height' => 150, 'aria' => 'Messages by channel']),
        'All messages, in and out, by channel.', 'msg-channels') ?>
  <?php else: ?>
    <?= viz_card('How fast you answer', viz_share([
          ['Within 5 minutes', $rt['answered'] ? (int) round(($rt['in5'] ?? 0) * $rt['opened'] / 100) : 0, 3],
          ['Later', max(0, $rt['answered'] - (int) round(($rt['in5'] ?? 0) * $rt['opened'] / 100)), 4],
          ['Not answered yet', $rt['unanswered'], 'bad'],
        ], ['empty' => 'No new conversations in this period.'])
        . ($rt['median'] !== null ? '<p class="viz-note">Half of new conversations got an answer within <strong>' . e(viz_dur((float) $rt['median'])) . '</strong> (by a person, an automation or the AI agent).</p>' : ''),
        'New conversations (no message from them in the 6 hours before).', 'reply-speed') ?>
  <?php endif; ?>
</div>

<?php if ($crm): ?>
  <?= viz_card('Leads and deals', viz_trend(array_values(viz_buckets($PL)), [
        ['label' => 'New leads', 'values' => $leads['leads_series'], 'slot' => 3],
        ['label' => 'Won', 'values' => $leads['won_series'], 'slot' => 1],
        ['label' => 'Lost', 'values' => $leads['lost_series'], 'slot' => 2],
      ], ['type' => 'columns', 'aria' => 'Leads, won and lost', 'empty' => 'No leads in this period yet.']),
      'Leads added to the CRM, and deals won and lost, by ' . $PL['bucket'] . '.', 'crm-trend',
      '<a class="btn btn-ghost btn-sm" href="crm_dashboard.php?tab=reports">Sales dashboard →</a>') ?>
<?php endif; ?>

<?php if ($recent): ?>
<div class="card card-flush" style="margin-top:16px">
  <div style="padding:16px 22px" class="row-between">
    <h2 style="border:0;padding:0;margin:0">Recent campaigns</h2>
    <span style="display:flex;gap:6px"><a class="btn btn-ghost btn-sm" href="reports.php">Reports →</a><a class="btn btn-primary btn-sm" href="campaign_new.php">+ New Campaign</a></span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Campaign</th><th>Status</th><th>Results</th><th class="num">Sent</th><th class="num">Read</th><th class="num">Failed</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recent as $c): $tot = max(1, (int) $c['total_count']); ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td><?= status_pill((string) $c['status']) ?></td>
          <td style="min-width:150px"><?= viz_share([['Read', (int) $c['read_count'], 1], ['Delivered, not read', max(0, (int) $c['delivered_count'] - (int) $c['read_count']), 3],
                ['Sent, not delivered', max(0, (int) $c['sent_count'] - (int) $c['delivered_count']), 4], ['Failed', (int) $c['failed_count'], 'bad'],
                ['Waiting', max(0, (int) $c['total_count'] - (int) $c['sent_count'] - (int) $c['failed_count']), 7]], ['legend' => false, 'empty' => '—']) ?></td>
          <td class="num"><?= (int) $c['sent_count'] ?> / <?= (int) $c['total_count'] ?></td>
          <td class="num"><?= (int) $c['read_count'] ?> <small class="text-muted"><?= $c['sent_count'] ? round(100 * $c['read_count'] / $c['sent_count']) . '%' : '' ?></small></td>
          <td class="num"><?= (int) $c['failed_count'] ? '<span class="pill red">' . (int) $c['failed_count'] . '</span>' : '0' ?></td>
          <td class="text-muted"><?= e(date('d M, H:i', strtotime((string) $c['created_at']))) ?></td>
          <td style="text-align: end"><a class="btn-link" href="report.php?id=<?= (int) $c['id'] ?>">View →</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php layout_footer(); ?>
