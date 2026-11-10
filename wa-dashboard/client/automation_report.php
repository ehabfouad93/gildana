<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/stats.php';

$cid  = (int) $CLIENT['id'];
$fid  = (int) ($_GET['id'] ?? 0);
$flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='bot'", [$fid, $cid]);
if (!$flow) { http_response_code(404); exit('Automation not found.'); }

require_once __DIR__ . '/../includes/automation.php';  // step figures; replies.php: what people answered at each step

/* The steps, in the order they were laid out, each with words a person recognises. */
$stepNames = ['text' => 'Message', 'image' => 'Picture', 'template' => 'Template', 'buttons' => 'Buttons', 'question' => 'Question', 'list_msg' => 'Menu list',
              'ai_chat' => 'AI chat', 'ai_branch' => 'AI decision', 'comment_reply' => 'Comment reply', 'comment_dm' => 'Private reply', 'wait' => 'Wait', 'wait_until' => 'Wait until',
              'condition' => 'If / then', 'split' => 'Split test', 'tag' => 'Tag', 'list_add' => 'Add to list', 'notify' => 'Notify team', 'collect' => 'Save lead', 'sheet_export' => 'Google Sheet',
              'set_field' => 'Remember', 'http' => 'Web service', 'score' => 'Score', 'ai_score' => 'AI score', 'jump' => 'Go to automation'];
$steps = [];
foreach (db_all("SELECT * FROM flow_steps WHERE flow_id=? ORDER BY sort, id", [$fid]) as $i => $st) {
    $c = json_decode((string) $st['config'], true) ?: [];
    $words = trim((string) ($c['body'] ?? $c['prompt'] ?? $c['intro'] ?? $c['caption'] ?? $c['message'] ?? $c['tag'] ?? $c['field'] ?? ''));
    $steps[(int) $st['id']] = ['n' => $i + 1, 'type' => (string) $st['type'], 'name' => $stepNames[$st['type']] ?? ucfirst(str_replace('_', ' ', (string) $st['type'])),
                               'words' => $words, 'save_as' => trim((string) ($c['save_as'] ?? '')),
                               'asks' => in_array($st['type'], ['buttons', 'question', 'list_msg', 'ai_chat', 'ai_branch', 'comment_dm'], true)];
}
$stepLabel = fn($sid) => isset($steps[(int) $sid]) ? $steps[(int) $sid]['n'] . '. ' . $steps[(int) $sid]['name'] . ($steps[(int) $sid]['words'] !== '' ? ' — ' . mb_strimwidth($steps[(int) $sid]['words'], 0, 40, '…') : '') : '';
/** Where a run is now, in words. */
$whereNow = function (array $r) use ($stepLabel): string {
    if ($r['status'] === 'completed') return 'Finished';
    $l = $stepLabel($r['current_step_id'] ?? 0);
    return ($r['status'] === 'waiting_input' ? 'Waiting for an answer at ' : ($r['status'] === 'waiting_timer' ? 'Waiting at ' : 'Stopped at ')) . ($l !== '' ? $l : 'a step that was removed');
};
/** What the automation captured in a run (answers saved under a name), without its internal notes. */
$captured = function (array $r): array {
    $f = (array) ((json_decode((string) ($r['context'] ?? ''), true) ?: [])['fields'] ?? []);
    return array_filter($f, fn($v, $k) => $k !== '' && $k[0] !== '_' && is_scalar($v) && (string) $v !== '', ARRAY_FILTER_USE_BOTH);
};

if (($_GET['export'] ?? '') === 'runs') {
    $rows = db_all("SELECT r.*, c.phone_e164, c.name FROM flow_runs r JOIN contacts c ON c.id=r.contact_id WHERE r.flow_id=? ORDER BY r.id DESC", [$fid]);
    // One column per captured answer, across all runs.
    $keys = [];
    foreach ($rows as $r) foreach (array_keys($captured($r)) as $k) $keys[$k] = true;
    $keys = array_keys($keys);
    // Every answer of a run, step by step.
    $ans = [];
    if (replies_ready()) foreach (db_all("SELECT run_id, step_id, kind, body FROM reply_events WHERE flow_id=? ORDER BY id", [$fid]) as $a) {
        $ans[(int) $a['run_id']][] = (isset($steps[(int) $a['step_id']]) ? $steps[(int) $a['step_id']]['n'] . '. ' : '') . ($a['kind'] === 'button' ? '[button] ' : '') . $a['body'];
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="automation-' . $fid . '-runs.csv"');
    echo "\xEF\xBB\xBF"; echo implode(',', array_map('csv_cell', array_merge(['phone', 'name', 'status', 'where', 'score', 'grade', 'created', 'answers'], $keys))) . "\n";
    foreach ($rows as $r) {
        $cap = $captured($r);
        echo implode(',', array_map('csv_cell', array_merge(
            [(string) $r['phone_e164'], (string) $r['name'], (string) $r['status'], $whereNow($r), (string) (int) $r['score'], (string) ($r['grade'] ?? ''), (string) $r['created_at'],
             implode(' | ', $ans[(int) $r['id']] ?? [])],
            array_map(fn($k) => (string) ($cap[$k] ?? ''), $keys)))) . "\n";
    }
    exit;
}

$counts = db_row("SELECT COUNT(*) total, SUM(status='completed') completed, SUM(status IN ('waiting_input','waiting_timer','active')) active, SUM(status='blocked') blocked FROM flow_runs WHERE flow_id=?", [$fid]) ?: [];
$msg = db_row("SELECT COUNT(*) sends, SUM(status IN ('delivered','read')) delivered, SUM(status='read') `read`, SUM(status='failed') failed FROM flow_messages WHERE flow_id=?", [$fid]) ?: [];
$perStep = db_all(
    "SELECT s.type, m.step_id, COUNT(*) sends, SUM(m.status IN ('delivered','read')) delivered, SUM(m.status='read') `read`
       FROM flow_messages m LEFT JOIN flow_steps s ON s.id=m.step_id
      WHERE m.flow_id=? GROUP BY m.step_id ORDER BY m.step_id", [$fid]
);
// Recent runs — or the people at one step (?at=): the ones who stopped or are waiting there.
$atStep = (int) ($_GET['at'] ?? 0);
$runs = $atStep && isset($steps[$atStep])
    ? db_all("SELECT r.*, c.phone_e164, c.name FROM flow_runs r JOIN contacts c ON c.id=r.contact_id WHERE r.flow_id=? AND r.current_step_id=? AND r.status<>'completed' ORDER BY r.id DESC LIMIT 200", [$fid, $atStep])
    : db_all("SELECT r.*, c.phone_e164, c.name FROM flow_runs r JOIN contacts c ON c.id=r.contact_id WHERE r.flow_id=? ORDER BY r.id DESC LIMIT 100", [$fid]);
/* Step by step: who got there, who went on, who is still there, and what they answered. */
$sStats = automation_step_stats($fid);
$here = [];
foreach (db_all("SELECT current_step_id sid, SUM(status='waiting_input') waiting, SUM(status NOT IN ('waiting_input','completed')) stopped
                   FROM flow_runs WHERE flow_id=? AND status<>'completed' AND current_step_id IS NOT NULL GROUP BY current_step_id", [$fid]) as $h) $here[(int) $h['sid']] = $h;
$answers = []; $answerN = [];
if (replies_ready()) {
    foreach (db_all("SELECT step_id, body, kind, COUNT(*) n FROM reply_events WHERE flow_id=? GROUP BY step_id, body, kind ORDER BY n DESC", [$fid]) as $a) {
        $sid = (int) $a['step_id']; $answerN[$sid] = ($answerN[$sid] ?? 0) + (int) $a['n'];
        if (count($answers[$sid] ?? []) < 6) $answers[$sid][] = $a;
    }
}
$sent = [];
foreach ($perStep as $p) $sent[(int) $p['step_id']] = $p;
$runAnswers = [];
if ($runs && replies_ready()) {
    $ph = implode(',', array_fill(0, count($runs), '?'));
    foreach (db_all("SELECT run_id, step_id, kind, body, created_at FROM reply_events WHERE run_id IN ($ph) ORDER BY id", array_map(fn($r) => (int) $r['id'], $runs)) as $a) $runAnswers[(int) $a['run_id']][] = $a;
}

/* Charts: runs over the period, how far people get, and how runs end. */
$P = viz_period($_GET, '90d');
$fs = stats_flow_series($cid, $P, $fid);
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

<?php if ($steps): ?>
<div class="card card-flush" id="steps">
  <div style="padding:16px 22px"><h2 style="border:0;padding:0;margin:0">Step by step</h2>
    <p class="text-muted" style="font-size:12.5px;margin:6px 0 0">Who reached each step, who went on, who is still there — and what people answered. Click a number under "Still here" to see those people.</p></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Step</th><th>Reached</th><th>Went on</th><th>Still here</th><th>Sent · Read</th><th>What they answered</th></tr></thead>
    <tbody><?php foreach ($steps as $sid => $st): $ss = $sStats[$sid] ?? ['reached' => 0, 'advanced' => 0]; $h = $here[$sid] ?? ['waiting' => 0, 'stopped' => 0];
        $hereN = (int) $h['waiting'] + (int) $h['stopped'];
        // A step that asks something is only passed once it is answered: reached, minus those still there.
        if ($st['asks']) $ss['advanced'] = max(0, (int) $ss['reached'] - $hereN); ?>
      <tr<?= $atStep === $sid ? ' style="background:var(--paper)"' : '' ?>>
        <td style="max-width:300px"><strong><?= (int) $st['n'] ?>. <?= e($st['name']) ?></strong><?php if ($st['save_as'] !== ''): ?> <span class="pill gray" title="The answer is saved under this name">→ <?= e($st['save_as']) ?></span><?php endif; ?>
          <?php if ($st['words'] !== ''): ?><div class="text-muted" style="font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_strimwidth($st['words'], 0, 90, '…')) ?></div><?php endif; ?></td>
        <td><?= number_format((int) $ss['reached']) ?></td>
        <td><?= number_format((int) $ss['advanced']) ?><?php if ((int) $ss['reached']): ?> <span class="text-muted" style="font-size:12px"><?= round(100 * (int) $ss['advanced'] / max(1, (int) $ss['reached'])) ?>%</span><?php endif; ?></td>
        <td><?php if ($hereN): ?><a href="automation_report.php?id=<?= $fid ?>&at=<?= $sid ?>#runs"><strong><?= number_format($hereN) ?></strong></a>
              <span class="text-muted" style="font-size:12px"><?= (int) $h['waiting'] ? (int) $h['waiting'] . ' waiting for an answer' : '' ?><?= (int) $h['waiting'] && (int) $h['stopped'] ? ' · ' : '' ?><?= (int) $h['stopped'] ? (int) $h['stopped'] . ' stopped' : '' ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td class="text-muted"><?= isset($sent[$sid]) ? (int) $sent[$sid]['sends'] . ' · ' . (int) $sent[$sid]['read'] : '—' ?></td>
        <td style="max-width:340px"><?php if (!empty($answers[$sid])): ?>
            <?php foreach ($answers[$sid] as $a): ?><span class="pill <?= $a['kind'] === 'button' ? 'blue' : 'gray' ?>" style="margin:0 4px 4px 0;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;vertical-align:bottom" title="<?= e((string) $a['body']) ?>"><?= e(mb_strimwidth((string) $a['body'], 0, 32, '…')) ?> · <?= (int) $a['n'] ?></span><?php endforeach; ?>
            <div class="text-muted" style="font-size:11.5px"><?= number_format((int) $answerN[$sid]) ?> answer<?= (int) $answerN[$sid] === 1 ? '' : 's' ?></div>
          <?php elseif ($st['asks']): ?><span class="text-muted">No answers yet</span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="card card-flush" id="runs">
  <div style="padding:16px 22px" class="row-between"><h2 style="border:0;padding:0;margin:0"><?= $atStep && isset($steps[$atStep]) ? 'People at step ' . e($stepLabel($atStep)) : 'Recent runs' ?></h2>
    <?php if ($atStep): ?><a class="btn btn-ghost btn-sm" href="automation_report.php?id=<?= $fid ?>#runs">Show all runs</a><?php endif; ?></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Person</th><th>Where they are</th><th>Captured</th><th>Score</th><th>Started</th><th></th></tr></thead>
    <tbody>
    <?php if (!$runs): ?><tr><td colspan="6"><div class="empty">No runs<?= $atStep ? ' at this step' : ' yet' ?>.</div></td></tr><?php endif; ?>
    <?php foreach ($runs as $r): $cap = $captured($r); $tr = (array) ((json_decode((string) ($r['context'] ?? ''), true) ?: [])['transcript'] ?? []); ?>
      <tr>
        <td><?= e((string) $r['name']) ?: '—' ?><div class="mono text-muted" style="font-size:12px"><?= $r['phone_e164'] ? '+' . e((string) $r['phone_e164']) : e(ucfirst((string) ($r['channel'] ?? ''))) ?></div></td>
        <td style="font-size:12.5px"><?= $r['status'] === 'completed' ? '<span class="pill green">Finished</span>' : (in_array($r['status'], ['waiting_input', 'waiting_timer', 'active'], true) ? '<span class="pill gold">Waiting</span>' : '<span class="pill red">Stopped</span>') ?>
          <div class="text-muted" style="margin-top:3px"><?= e($whereNow($r)) ?></div></td>
        <td style="font-size:12.5px;max-width:300px"><?php if ($cap): foreach ($cap as $k => $v): ?><div><span class="text-muted"><?= e((string) $k) ?>:</span> <?= e(mb_strimwidth((string) $v, 0, 70, '…')) ?></div><?php endforeach; else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td><?= (int) $r['score'] ?><?= !empty($r['grade']) ? ' <span class="pill gray">' . e((string) $r['grade']) . '</span>' : '' ?></td>
        <td class="text-muted"><?= e(date('d M, H:i', strtotime((string) $r['created_at']))) ?></td>
        <td style="text-align:end;white-space:nowrap">
          <?php if ($tr || !empty($runAnswers[(int) $r['id']])): ?><button type="button" class="btn-link" onclick="this.closest('tr').nextElementSibling.hidden = !this.closest('tr').nextElementSibling.hidden">Conversation</button> · <?php endif; ?>
          <a class="btn-link" href="inbox.php?contact=<?= (int) $r['contact_id'] ?>">Inbox</a></td>
      </tr>
      <tr hidden><td colspan="6" style="background:var(--paper)">
        <?php if (!empty($runAnswers[(int) $r['id']])): ?><div style="margin-bottom:8px"><strong style="font-size:12.5px">Answers, step by step</strong>
          <?php foreach ($runAnswers[(int) $r['id']] as $a): ?><div style="font-size:12.5px"><span class="text-muted"><?= e($stepLabel($a['step_id']) ?: 'Step') ?>:</span> <?= $a['kind'] === 'button' ? '<span class="pill blue">button</span> ' : '' ?><?= e((string) $a['body']) ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($tr): ?><div style="max-height:260px;overflow:auto;font-size:12.5px">
          <?php foreach (array_slice($tr, -40) as $t): $me = ($t['role'] ?? '') !== 'user'; ?>
            <div style="margin:3px 0;<?= $me ? '' : 'font-weight:600' ?>"><span class="text-muted" style="font-weight:400"><?= $me ? 'Automation' : 'Customer' ?>:</span> <?= e(mb_strimwidth((string) ($t['text'] ?? ''), 0, 400, '…')) ?></div>
          <?php endforeach; ?></div><?php endif; ?>
      </td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php layout_footer(); ?>
