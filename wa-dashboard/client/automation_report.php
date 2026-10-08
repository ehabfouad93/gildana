<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/stats.php';

$cid  = (int) $CLIENT['id'];
$fid  = (int) ($_GET['id'] ?? 0);
$flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='bot'", [$fid, $cid]);
if (!$flow) { http_response_code(404); exit('Automation not found.'); }

if (($_GET['export'] ?? '') === 'runs') {
    $rows = db_all("SELECT r.*, c.phone_e164, c.name FROM flow_runs r JOIN contacts c ON c.id=r.contact_id WHERE r.flow_id=? ORDER BY r.id DESC", [$fid]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="automation-' . $fid . '-runs.csv"');
    echo "\xEF\xBB\xBF"; echo "phone,name,status,score,grade,created\n";
    foreach ($rows as $r) echo implode(',', array_map('csv_cell', [(string) $r['phone_e164'], (string) $r['name'], (string) $r['status'], (string) (int) $r['score'], (string) ($r['grade'] ?? ''), (string) $r['created_at']])) . "\n";
    exit;
}

$counts = db_row("SELECT COUNT(*) total, SUM(status='completed') completed, SUM(status IN ('waiting_input','waiting_timer','active')) active, SUM(status='blocked') blocked FROM flow_runs WHERE flow_id=?", [$fid]) ?: [];
$msg = db_row("SELECT COUNT(*) sends, SUM(status IN ('delivered','read')) delivered, SUM(status='read') `read`, SUM(status='failed') failed FROM flow_messages WHERE flow_id=?", [$fid]) ?: [];
$perStep = db_all(
    "SELECT s.type, m.step_id, COUNT(*) sends, SUM(m.status IN ('delivered','read')) delivered, SUM(m.status='read') `read`
       FROM flow_messages m LEFT JOIN flow_steps s ON s.id=m.step_id
      WHERE m.flow_id=? GROUP BY m.step_id ORDER BY m.step_id", [$fid]
);
$runs = db_all("SELECT r.*, c.phone_e164, c.name FROM flow_runs r JOIN contacts c ON c.id=r.contact_id WHERE r.flow_id=? ORDER BY r.id DESC LIMIT 100", [$fid]);

/* Charts: runs over the period, how far people get, and how runs end. */
$P = viz_period($_GET, '90d');
$fs = stats_flow_series($cid, $P, $fid);
$stepNames = ['text' => 'Message', 'image' => 'Picture', 'template' => 'Template', 'buttons' => 'Buttons', 'question' => 'Question', 'list_msg' => 'List',
              'ai_chat' => 'AI chat', 'ai_branch' => 'AI decision', 'comment_reply' => 'Comment reply', 'comment_dm' => 'Private reply'];
$reach = db_all("SELECT m.step_id, s.type, COUNT(DISTINCT m.run_id) n, SUM(m.status='read') rd, COUNT(*) sends
                   FROM flow_messages m LEFT JOIN flow_steps s ON s.id=m.step_id
                  WHERE m.flow_id=? AND m.created_at BETWEEN ? AND ? GROUP BY m.step_id ORDER BY MIN(s.sort), m.step_id", [$fid, $P['start'], $P['end']]);
$pc = db_row("SELECT COUNT(*) total, SUM(status='completed') completed, SUM(status IN ('waiting_input','waiting_timer','active')) waiting,
                     SUM(status='blocked') blocked, SUM(status NOT IN ('completed','waiting_input','waiting_timer','active','blocked')) other
                FROM flow_runs WHERE flow_id=? AND created_at BETWEEN ? AND ?", [$fid, $P['start'], $P['end']]) ?: [];
$grades = db_all("SELECT COALESCE(NULLIF(grade,''),'—') g, COUNT(*) n FROM flow_runs WHERE flow_id=? AND created_at BETWEEN ? AND ? GROUP BY g", [$fid, $P['start'], $P['end']]);

$actions = '<a class="btn btn-ghost btn-sm" href="automation_edit.php?id=' . $fid . '">Edit</a><a class="btn btn-ghost btn-sm" href="automation_report.php?id=' . $fid . '&export=runs">Export CSV</a>';
client_header('Report · ' . $flow['name'], 'automations', $CLIENT);
page_head('Report — ' . $flow['name'], $actions);
?>
<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Runs</span><span class="val accent"><?= (int) ($counts['total'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Completed</span><span class="val"><?= (int) ($counts['completed'] ?? 0) ?></span><span class="sub"><?= (int) ($counts['active'] ?? 0) ?> in progress</span></div>
  <div class="stat-tile"><span class="lbl">Messages sent</span><span class="val"><?= (int) ($msg['sends'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Delivered</span><span class="val"><?= (int) ($msg['delivered'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Blocked</span><span class="val <?= (int) ($counts['blocked'] ?? 0) ? 'danger' : '' ?>"><?= (int) ($counts['blocked'] ?? 0) ?></span></div>
</div>

<?= viz_period_bar($P, ['id' => $fid]) ?>
<?= viz_card('Runs over time', viz_trend($fs['labels'], [['label' => 'Started', 'values' => $fs['runs'], 'slot' => 1], ['label' => 'Finished', 'values' => $fs['done'], 'slot' => 3]],
      ['aria' => 'Runs started and finished', 'x_label' => ucfirst($P['bucket']), 'empty' => 'No runs in this period.']),
    'People who entered this automation, by the ' . $P['bucket'] . ' they started (' . strtolower($P['label']) . ').', 'flow-trend') ?>
<div class="viz-grid2" style="margin:16px 0">
  <?= viz_card('How far people get', viz_funnel(array_map(fn($i, $r) => [($i + 1) . '. ' . ($stepNames[$r['type']] ?? ucfirst((string) ($r['type'] ?? 'Step'))), (int) $r['n']], array_keys($reach), $reach),
        ['empty' => 'No messages sent in this period.']),
      'People who received each sending step, in order — where the bar drops, people stopped answering.', 'flow-funnel') ?>
  <?= viz_card('How runs ended', viz_share([['Finished', (int) ($pc['completed'] ?? 0), 3], ['Waiting for an answer', (int) ($pc['waiting'] ?? 0), 1],
        ['Stopped', (int) ($pc['blocked'] ?? 0), 'bad'], ['Other', (int) ($pc['other'] ?? 0), 7]], ['empty' => 'No runs in this period.'])
      . (count($grades) > 1 || ($grades && $grades[0]['g'] !== '—') ? '<h3 class="viz-sub">Lead grades</h3>' . viz_bars(array_map(fn($g) => ['label' => ucfirst((string) $g['g']), 'n' => (int) $g['n']], $grades), [['n', 'Runs', 4]]) : ''),
      'Runs that started in this period, by where they are now.', 'flow-ends') ?>
</div>

<?php if ($perStep): ?>
<div class="card card-flush">
  <div style="padding:16px 22px"><h2 style="border:0;padding:0;margin:0">Per-step performance</h2></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Step</th><th>Sent</th><th>Delivered</th><th>Read</th></tr></thead>
    <tbody><?php foreach ($perStep as $p): ?>
      <tr><td><?= e((string) ($p['type'] ?? 'step')) ?></td><td><?= (int) $p['sends'] ?></td><td><?= (int) $p['delivered'] ?></td><td><?= (int) $p['read'] ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="card card-flush">
  <div style="padding:16px 22px"><h2 style="border:0;padding:0;margin:0">Recent runs</h2></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Phone</th><th>Name</th><th>Status</th><th>Score</th><th>Created</th></tr></thead>
    <tbody>
    <?php if (!$runs): ?><tr><td colspan="5"><div class="empty">No runs yet.</div></td></tr><?php endif; ?>
    <?php foreach ($runs as $r): ?>
      <tr>
        <td class="mono"><?= $r['phone_e164'] ? '+' . e((string) $r['phone_e164']) : '<span class="text-muted">' . e(ucfirst((string) ($r['channel'] ?? '—'))) . '</span>' ?></td>
        <td><?= e((string) $r['name']) ?: '—' ?></td>
        <td><?= msg_status_pill($r['status'] === 'waiting_input' || $r['status'] === 'waiting_timer' ? 'sending' : ($r['status'] === 'completed' ? 'delivered' : ($r['status'] === 'blocked' ? 'failed' : 'sent'))) ?></td>
        <td><?= (int) $r['score'] ?></td>
        <td class="text-muted"><?= e(date('d M, H:i', strtotime((string) $r['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php layout_footer(); ?>
