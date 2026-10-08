<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/stats.php';
require_once __DIR__ . '/../includes/ads.php';

/**
 * Reports on messaging: campaigns, templates, automations and ads over a chosen period, each
 * against the period before. (Sales reports live in CRM → Reports.)
 */
$cid = (int) $CLIENT['id'];
$P   = viz_period($_GET, '90d');
$tab = in_array($_GET['tab'] ?? '', ['campaigns', 'templates', 'automations', 'ads'], true) ? (string) $_GET['tab'] : 'campaigns';
$cmp = viz_prev_words($P);
$pct = fn($n, $d) => (float) $d > 0 ? round(100 * (float) $n / (float) $d, 1) : null;

/* ── data for the tab ── */
if ($tab === 'campaigns') {
    $rows  = stats_campaigns($cid, $P['start'], $P['end']);
    $prev  = stats_campaigns($cid, $P['prev_start'], $P['prev_end']);
    $agg = function (array $rows) {
        $a = ['n' => count($rows), 'total' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'replied' => 0];
        foreach ($rows as $r) { $a['total'] += (int) $r['total_count']; $a['sent'] += (int) $r['sent_count']; $a['delivered'] += (int) $r['delivered_count'];
                                $a['read'] += (int) $r['read_count']; $a['failed'] += (int) $r['failed_count']; $a['replied'] += (int) $r['replied']; }
        return $a;
    };
    $A = $agg($rows); $B = $agg($prev);
    $series = stats_campaign_series($cid, $P);
    $fails = db_all("SELECT COALESCE(NULLIF(cm.error_title,''),'No reason given') label, COUNT(*) n FROM campaign_messages cm JOIN campaigns k ON k.id=cm.campaign_id
                      WHERE k.client_id=? AND k.created_at BETWEEN ? AND ? AND cm.status IN ('failed','dead','review') GROUP BY label ORDER BY n DESC LIMIT 8",
                    [$cid, $P['start'], $P['end']]);
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="campaigns-' . $P['from'] . '-' . $P['to'] . '.csv"');
        echo "\xEF\xBB\xBF" . implode(',', array_map('csv_cell', ['Campaign', 'Channel', 'Template', 'Status', 'Created', 'Recipients', 'Sent', 'Delivered', 'Read', 'Replied', 'Failed', 'Read rate %', 'Reply rate %'])) . "\n";
        foreach ($rows as $r) {
            echo implode(',', array_map('csv_cell', [(string) $r['name'], (string) $r['channel'], (string) $r['template_name'], (string) $r['status'], (string) $r['created_at'],
                (string) $r['total_count'], (string) $r['sent_count'], (string) $r['delivered_count'], (string) $r['read_count'], (string) $r['replied'], (string) $r['failed_count'],
                (string) ($pct($r['read_count'], $r['sent_count']) ?? ''), (string) ($pct($r['replied'], $r['sent_count']) ?? '')])) . "\n";
        }
        exit;
    }
} elseif ($tab === 'templates') {
    $tpls = stats_templates($cid, $P['start'], $P['end']);
} elseif ($tab === 'automations') {
    $flows = stats_flows($cid, $P['start'], $P['end']);
    $flowsW = stats_flows($cid, $P['prev_start'], $P['prev_end']);
    $fs = stats_flow_series($cid, $P);
    $sumF = fn($rows, $k) => array_sum(array_map(fn($r) => (int) $r[$k], $rows));
} else {
    $ads = ads_list($cid);
}

client_header('Reports', 'reports', $CLIENT);
page_head('Reports', $tab === 'campaigns' ? '<a class="btn btn-ghost btn-sm" href="?' . e(http_build_query(['tab' => 'campaigns', 'export' => 'csv', 'p' => $P['key'], 'from' => $P['from'], 'to' => $P['to']])) . '">Export CSV</a>' : '');
?>
<nav class="dv-tabs" aria-label="Report sections">
  <?php foreach (['campaigns' => 'Campaigns', 'templates' => 'Templates', 'automations' => 'Automations', 'ads' => 'Ads'] as $k => $l): ?>
    <a href="?<?= e(http_build_query(['tab' => $k, 'p' => $P['key'], 'from' => $P['key'] === 'custom' ? $P['from'] : '', 'to' => $P['key'] === 'custom' ? $P['to'] : ''])) ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $l ?></a>
  <?php endforeach; ?>
</nav>
<?php if ($tab !== 'ads') echo viz_period_bar($P, ['tab' => $tab]); ?>

<?php if ($tab === 'campaigns'): ?>
  <div class="viz-kpis">
    <?= viz_kpi('Campaigns', $A['n'], $B['n'], ['compare' => $cmp]) ?>
    <?= viz_kpi('Recipients', $A['total'], $B['total'], ['compare' => $cmp]) ?>
    <?= viz_kpi('Sent', $A['sent'], $B['sent'], ['compare' => $cmp, 'spark' => $series['sent']]) ?>
    <?= viz_kpi('Delivered', $pct($A['delivered'], $A['sent']), $pct($B['delivered'], $B['sent']), ['fmt' => 'pct', 'compare' => $cmp, 'sub' => number_format($A['delivered']) . ' messages']) ?>
    <?= viz_kpi('Read', $pct($A['read'], $A['sent']), $pct($B['read'], $B['sent']), ['fmt' => 'pct', 'compare' => $cmp, 'sub' => number_format($A['read']) . ' messages']) ?>
    <?= viz_kpi('Replied', $pct($A['replied'], $A['sent']), $pct($B['replied'], $B['sent']), ['fmt' => 'pct', 'compare' => $cmp, 'sub' => number_format($A['replied']) . ' people, within 3 days']) ?>
    <?= viz_kpi('Failed', $A['failed'], $B['failed'], ['compare' => $cmp, 'up_good' => false, 'sub' => ($pct($A['failed'], $A['total']) ?? 0) . '% of recipients', 'href' => 'failed.php']) ?>
  </div>

  <?= viz_card('Campaign messages over time', viz_trend($series['labels'], [
        ['label' => 'Sent', 'values' => $series['sent'], 'slot' => 1],
        ['label' => 'Read', 'values' => $series['read'], 'slot' => 3],
        ['label' => 'Failed', 'values' => $series['failed'], 'slot' => 2],
      ], ['type' => 'columns', 'aria' => 'Campaign messages sent, read and failed', 'x_label' => ucfirst($P['bucket']), 'empty' => 'No campaign messages in this period.']),
      'By the ' . $P['bucket'] . ' each message went out.', 'camp-trend') ?>

  <div class="viz-grid2" style="margin-top:16px">
    <?php $top = array_slice(array_values(array_filter($rows, fn($r) => (int) $r['sent_count'] > 0)), 0, 10); ?>
    <?= viz_card('Read and replied, by campaign', viz_bars(array_map(fn($r) => ['label' => (string) $r['name'], 'href' => 'report.php?id=' . (int) $r['id'],
          'rr' => $pct($r['read_count'], $r['sent_count']) ?? 0, 'rp' => $pct($r['replied'], $r['sent_count']) ?? 0, 'note' => number_format((int) $r['sent_count']) . ' sent'], $top),
          [['rr', 'Read', 1], ['rp', 'Replied', 3]], ['fmt' => 'pct', 'sub' => 'note', 'empty' => 'No campaigns sent in this period.']),
        'Share of sent messages that were read, and of people who wrote back within 3 days. The latest ten.', 'camp-rates') ?>
    <?= viz_card('Why messages failed', viz_bars(array_map(fn($r) => ['label' => (string) $r['label'], 'n' => (int) $r['n']], $fails), [['n', 'Messages', 2]],
          ['empty' => 'Nothing failed in this period.']) . ($fails ? '<p class="viz-note"><a href="failed.php">Resend the ones that can be fixed →</a></p>' : ''),
        'Campaign messages that never arrived, by the reason WhatsApp or Meta gave.', 'camp-fails') ?>
  </div>

  <div class="card card-flush" style="margin-top:16px" id="camp-table">
    <div style="padding:16px 22px"><h2 style="border:0;padding:0;margin:0">Every campaign in this period</h2></div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Campaign</th><th>Status</th><th>Results</th><th class="num">Sent</th><th class="num">Delivered</th><th class="num">Read</th><th class="num">Replied</th><th class="num">Failed</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="9"><div class="empty">No campaigns in this period.</div></td></tr><?php endif; ?>
      <?php foreach ($rows as $c): $s = (int) $c['sent_count']; ?>
        <tr>
          <td><strong><?= e((string) $c['name']) ?></strong><br><small class="text-muted"><?= e(in_array($c['channel'], ['messenger', 'instagram'], true) ? ucfirst((string) $c['channel']) : (string) ($c['template_name'] ?? '')) ?> · <?= e(date('j M Y', strtotime((string) $c['created_at']))) ?></small></td>
          <td><?= status_pill((string) $c['status']) ?></td>
          <td style="min-width:140px"><?= viz_share([['Read', (int) $c['read_count'], 1], ['Delivered, not read', max(0, (int) $c['delivered_count'] - (int) $c['read_count']), 3],
                ['Sent, not delivered', max(0, $s - (int) $c['delivered_count']), 4], ['Failed', (int) $c['failed_count'], 'bad'],
                ['Waiting', max(0, (int) $c['total_count'] - $s - (int) $c['failed_count']), 7]], ['legend' => false, 'empty' => '—']) ?></td>
          <td class="num"><?= number_format($s) ?> <small class="text-muted">/ <?= number_format((int) $c['total_count']) ?></small></td>
          <td class="num"><?= number_format((int) $c['delivered_count']) ?> <small class="text-muted"><?= e(viz_fmt($pct($c['delivered_count'], $s), 'pct')) ?></small></td>
          <td class="num"><?= number_format((int) $c['read_count']) ?> <small class="text-muted"><?= e(viz_fmt($pct($c['read_count'], $s), 'pct')) ?></small></td>
          <td class="num"><?= number_format((int) $c['replied']) ?> <small class="text-muted"><?= e(viz_fmt($pct($c['replied'], $s), 'pct')) ?></small></td>
          <td class="num"><?= (int) $c['failed_count'] ? '<span class="pill red">' . number_format((int) $c['failed_count']) . '</span>' : '0' ?></td>
          <td style="text-align:end"><a class="btn btn-ghost btn-sm" href="report.php?id=<?= (int) $c['id'] ?>">Details</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

<?php elseif ($tab === 'templates'): ?>
  <?php $ts = array_slice($tpls, 0, 12); ?>
  <div class="viz-grid2">
    <?= viz_card('Messages sent, by template', viz_bars(array_map(fn($r) => ['label' => (string) $r['label'], 'n' => (int) $r['sent'] - (int) $r['failed'],
          'note' => (int) $r['failed'] ? number_format((int) $r['failed']) . ' failed' : ''], $ts), [['n', 'Sent', 1]], ['sub' => 'note', 'empty' => 'No template messages in this period.']),
        'Every send — campaigns, automations, the Inbox and the CRM.', 'tpl-sent') ?>
    <?= viz_card('Read and replied, by template', viz_bars(array_map(fn($r) => ['label' => (string) $r['label'], 'rr' => $r['read_rate'], 'rp' => $r['reply_rate']], $ts),
          [['rr', 'Read', 1], ['rp', 'Replied', 3]], ['fmt' => 'pct', 'empty' => 'No template messages in this period.']),
        'Which wording gets read and answered (replies within 3 days).', 'tpl-rates') ?>
  </div>
  <?php if ($tpls): ?>
  <div class="card card-flush" style="margin-top:16px"><div class="table-wrap"><table class="data">
    <thead><tr><th>Template</th><th class="num">Sent</th><th class="num">Delivered</th><th class="num">Read</th><th class="num">Replied</th><th class="num">Failed</th></tr></thead>
    <tbody><?php foreach ($tpls as $t): $ok = max(1, (int) $t['sent'] - (int) $t['failed']); ?>
      <tr><td><strong><?= e((string) $t['label']) ?></strong></td><td class="num"><?= number_format((int) $t['sent']) ?></td>
        <td class="num"><?= number_format((int) $t['delivered']) ?> <small class="text-muted"><?= round(100 * $t['delivered'] / $ok) ?>%</small></td>
        <td class="num"><?= number_format((int) $t['rd']) ?> <small class="text-muted"><?= e(viz_fmt($t['read_rate'], 'pct')) ?></small></td>
        <td class="num"><?= number_format((int) $t['replied']) ?> <small class="text-muted"><?= e(viz_fmt($t['reply_rate'], 'pct')) ?></small></td>
        <td class="num"><?= (int) $t['failed'] ? '<span class="pill red">' . (int) $t['failed'] . '</span>' : '0' ?></td></tr>
    <?php endforeach; ?></tbody></table></div></div>
  <?php endif; ?>

<?php elseif ($tab === 'automations'): $runs = $sumF($flows, 'runs'); $done = $sumF($flows, 'completed'); ?>
  <div class="viz-kpis">
    <?= viz_kpi('Runs started', $runs, $sumF($flowsW, 'runs'), ['compare' => $cmp, 'spark' => $fs['runs']]) ?>
    <?= viz_kpi('Finished', $pct($done, $runs), $pct($sumF($flowsW, 'completed'), $sumF($flowsW, 'runs')), ['fmt' => 'pct', 'compare' => $cmp, 'sub' => number_format($done) . ' runs reached the end']) ?>
    <?= viz_kpi('Waiting for an answer', $sumF($flows, 'waiting'), null, ['sub' => 'still in progress']) ?>
    <?= viz_kpi('Stopped', $sumF($flows, 'blocked'), $sumF($flowsW, 'blocked'), ['compare' => $cmp, 'up_good' => false, 'sub' => 'could not continue (window closed, no credits…)']) ?>
  </div>
  <?= viz_card('Runs over time', viz_trend($fs['labels'], [
        ['label' => 'Started', 'values' => $fs['runs'], 'slot' => 1], ['label' => 'Finished', 'values' => $fs['done'], 'slot' => 3],
      ], ['aria' => 'Automation runs started and finished', 'x_label' => ucfirst($P['bucket']), 'empty' => 'No automation runs in this period.']),
      'People who entered an automation, by the ' . $P['bucket'] . ' they started.', 'flow-trend') ?>
  <div style="margin-top:16px">
  <?= viz_card('By automation', viz_bars(array_map(fn($r) => ['label' => (string) $r['label'], 'href' => $r['kind'] === 'bot' ? 'automation_report.php?id=' . (int) $r['id'] : 'agent_chats.php?flow=' . (int) $r['id'],
        'runs' => (int) $r['runs'], 'done' => (int) $r['completed'],
        'note' => ($r['kind'] === 'agent' ? 'AI agent · ' : '') . ($pct($r['completed'], $r['runs']) ?? 0) . '% finished' . ((int) $r['blocked'] ? ' · ' . (int) $r['blocked'] . ' stopped' : '')], $flows),
        [['runs', 'Started', 1], ['done', 'Finished', 3]], ['sub' => 'note', 'max_rows' => 20, 'empty' => 'No automation runs in this period.']),
      'Open one for its step-by-step report.', 'flow-bars') ?>
  </div>

<?php else: ?>
  <?php if (!$ads): ?>
    <div class="card"><div class="empty">No ad clicks yet. When people message you by tapping a Click-to-WhatsApp ad, each ad appears here with the leads it brought.</div></div>
  <?php else: ?>
    <?= viz_card('Leads by ad', viz_bars(array_map(fn($a) => ['label' => ads_label($a['headline'], (string) $a['source_id']), 'leads' => (int) $a['leads'], 'hot' => (int) $a['hot']],
          array_slice(array_values(array_filter($ads, fn($a) => (int) $a['leads'] > 0)), 0, 12)), [['leads', 'Leads', 1], ['hot', 'Hot', 2]]),
        'People who messaged you by tapping an ad, credited to the ad that first brought them. Hot = graded hot by an automation.', 'ads') ?>
    <div class="card card-flush" style="margin-top:16px">
      <div class="table-wrap"><table class="data">
        <thead><tr><th>Ad</th><th class="num">Leads</th><th class="num">Hot</th><th>First click</th><th>Last click</th></tr></thead>
        <tbody>
        <?php foreach ($ads as $a): ?>
          <tr>
            <td><strong><?= e(ads_label($a['headline'], (string) $a['source_id'])) ?></strong><br><small class="text-muted mono"><?= e((string) $a['source_id']) ?></small></td>
            <td class="num"><?= (int) $a['leads'] ?></td>
            <td class="num"><?= (int) $a['hot'] ? '<span class="pill red">' . (int) $a['hot'] . '</span>' : '0' ?></td>
            <td class="text-muted"><?= e(date('j M Y', strtotime((string) $a['first_seen_at']))) ?></td>
            <td class="text-muted"><?= e(date('j M, H:i', strtotime((string) $a['last_seen_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php if (can_use('crm')): ?><p class="text-muted" style="font-size:12.5px;margin-top:14px">Sales numbers — leads, deals, salespeople, ROI — are in <a href="crm_reports.php">CRM → Reports</a>.</p><?php endif; ?>
<?php layout_footer(); ?>
