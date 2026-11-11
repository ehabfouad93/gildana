<?php
declare(strict_types=1);
/**
 * Lead Qualifier → Report: how far each lead got and what they said.
 *
 * Every lead is put on one stage — where they stopped:
 *   queued → not delivered → sent → delivered (not read) → read (no reply) →
 *   replied but stopped part-way → finished (hot / warm / cold)
 * from the outreach message's delivery (flow_messages), the conversation the run keeps
 * (flow_runs.context: transcript + captured fields) and the answers recorded per step (reply_events).
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/stats.php';
require_once __DIR__ . '/../includes/automation.php';

$cid  = (int) $CLIENT['id'];
$fid  = (int) ($_GET['id'] ?? 0);
$flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='qualifier'", [$fid, $cid]);
if (!$flow) { http_response_code(404); exit('Lead Qualifier not found.'); }

$P = viz_period($_GET, '90d');
$CAP = 5000;      // the newest leads of the period; more than this is summarised from them
$rows = db_all("SELECT r.id, r.contact_id, r.status, r.grade, r.score, r.context, r.created_at, r.updated_at, c.name, c.phone_e164
                  FROM flow_runs r JOIN contacts c ON c.id=r.contact_id
                 WHERE r.flow_id=? AND r.created_at BETWEEN ? AND ? ORDER BY r.id DESC LIMIT " . ($CAP + 1), [$fid, $P['start'], $P['end']]);
$capped = count($rows) > $CAP; if ($capped) array_pop($rows);

// The outreach's delivery, per lead: the furthest any of its messages got, and why one failed.
$deliv = [];
foreach (db_all("SELECT run_id, MAX(FIELD(status,'failed','sent','delivered','read')) rk,
                        MAX(IF(status='failed', error_title, NULL)) err
                   FROM flow_messages WHERE flow_id=? AND created_at >= ? GROUP BY run_id", [$fid, $P['start']]) as $d) $deliv[(int) $d['run_id']] = $d;

$STAGES = [
    'queued'    => ['Waiting to be sent',        'The outreach has not gone out yet.'],
    'failed'    => ['Not delivered',             'WhatsApp did not deliver the first message.'],
    'sent'      => ['Sent, not delivered yet',   'Sent, but it has not reached the phone.'],
    'delivered' => ['Delivered, not read',       'It reached the phone and was not opened.'],
    'read'      => ['Read, no reply',            'They opened it and did not answer.'],
    'partial'   => ['Replied, stopped part-way', 'They answered, then stopped before the conversation finished.'],
    'done'      => ['Finished the conversation', 'They answered to the end and were scored.'],
];
$leads = []; $byStage = array_fill_keys(array_keys($STAGES), 0); $stopQ = []; $fieldVals = []; $answersN = []; $grades = ['hot' => 0, 'warm' => 0, 'cold' => 0];
$n = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'replied' => 0];
foreach ($rows as $r) {
    $ctx = json_decode((string) $r['context'], true) ?: [];
    $tr  = array_values(array_filter((array) ($ctx['transcript'] ?? []), fn($t) => is_array($t) && trim((string) ($t['text'] ?? '')) !== ''));
    $mine = array_values(array_filter($tr, fn($t) => ($t['role'] ?? '') === 'user'));
    $replies = count($mine);
    $rk = (int) ($deliv[(int) $r['id']]['rk'] ?? 0);                    // 0 none · 1 failed · 2 sent · 3 delivered · 4 read
    $graded = in_array($r['grade'], ['hot', 'warm', 'cold'], true);
    $stage = $r['status'] === 'completed' || $graded ? 'done'
           : ($replies > 0 ? 'partial'
           : ($r['status'] === 'queued' ? 'queued'
           : ($rk === 4 ? 'read' : ($rk === 3 ? 'delivered' : ($rk === 2 ? 'sent' : ($rk === 1 || $r['status'] === 'blocked' ? 'failed' : 'queued'))))));
    // What they were last asked and did not answer: the last thing the qualifier said.
    $lastBot = '';
    for ($i = count($tr) - 1; $i >= 0; $i--) if (($tr[$i]['role'] ?? '') !== 'user') { $lastBot = trim((string) $tr[$i]['text']); break; }
    $unanswered = $tr && ($tr[count($tr) - 1]['role'] ?? '') !== 'user' ? $lastBot : '';
    $fields = array_filter((array) ($ctx['fields'] ?? []), fn($v, $k) => $k !== '' && ((string) $k)[0] !== '_' && is_scalar($v) && trim((string) $v) !== '', ARRAY_FILTER_USE_BOTH);

    $byStage[$stage]++;
    if ($rk >= 2 || $replies) $n['sent']++;
    if ($rk >= 3 || $replies) $n['delivered']++;
    if ($rk >= 4 || $replies) $n['read']++;
    if ($replies) { $n['replied']++; $answersN[min($replies, 6)] = ($answersN[min($replies, 6)] ?? 0) + 1; }
    if ($graded) $grades[$r['grade']]++;
    if ($stage === 'partial' && $unanswered !== '') { $k = mb_strimwidth(preg_replace('/\s+/u', ' ', $unanswered), 0, 110, '…'); $stopQ[$k] = ($stopQ[$k] ?? 0) + 1; }
    foreach ($fields as $k => $v) { $v = mb_strimwidth(trim((string) $v), 0, 60, '…'); $fieldVals[(string) $k][$v] = ($fieldVals[(string) $k][$v] ?? 0) + 1; }

    $leads[] = ['id' => (int) $r['id'], 'contact_id' => (int) $r['contact_id'], 'name' => (string) $r['name'], 'phone' => (string) $r['phone_e164'], 'stage' => $stage,
                'replies' => $replies, 'last_reply' => $replies ? trim((string) $mine[$replies - 1]['text']) : '', 'unanswered' => $unanswered,
                'fields' => $fields, 'score' => (int) $r['score'], 'grade' => (string) ($r['grade'] ?? ''), 'created' => (string) $r['created_at'], 'tr' => $tr,
                'err' => (string) ($deliv[(int) $r['id']]['err'] ?? '')];
}
$total = count($leads);
arsort($stopQ);
$stageFilter = isset($STAGES[(string) ($_GET['stage'] ?? '')]) ? (string) $_GET['stage'] : '';
$gradeFilter = in_array((string) ($_GET['grade'] ?? ''), ['hot', 'warm', 'cold'], true) ? (string) $_GET['grade'] : '';
$shown = array_values(array_filter($leads, fn($l) => ($stageFilter === '' || $l['stage'] === $stageFilter) && ($gradeFilter === '' || $l['grade'] === $gradeFilter)));
$stageWords = fn(array $l) => $STAGES[$l['stage']][0] . ($l['stage'] === 'done' && $l['grade'] !== '' ? ' — ' . $l['grade'] : '')
                            . ($l['stage'] === 'partial' ? ' after ' . $l['replies'] . ' answer' . ($l['replies'] === 1 ? '' : 's') : '');

/* ── CSV: every lead of the period (or the filtered ones), with the conversation ── */
if (($_GET['export'] ?? '') === '1') {
    $keys = array_keys($fieldVals);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="lead-qualifier-' . $fid . '-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF" . implode(',', array_map('csv_cell', array_merge(['phone', 'name', 'stage', 'stopped_at_question', 'answers', 'last_reply', 'score', 'grade', 'started'], $keys, ['conversation']))) . "\n";
    foreach ($shown as $l) {
        echo implode(',', array_map('csv_cell', array_merge(
            [$l['phone'], $l['name'], $stageWords($l), $l['stage'] === 'partial' ? $l['unanswered'] : '', (string) $l['replies'], $l['last_reply'], (string) $l['score'], $l['grade'], $l['created']],
            array_map(fn($k) => (string) ($l['fields'][$k] ?? ''), $keys),
            [implode(' | ', array_map(fn($t) => (($t['role'] ?? '') === 'user' ? 'Lead: ' : 'Us: ') . preg_replace('/\s+/u', ' ', (string) $t['text']), $l['tr']))]))) . "\n";
    }
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 50; $pages = (int) max(1, ceil(count($shown) / $per));
$pageRows = array_slice($shown, ($page - 1) * $per, $per);
$qs = fn(array $o = []) => 'qualifier_report.php?' . http_build_query(array_filter(array_merge(['id' => $fid, 'p' => isset($_GET['p']) ? (string) $_GET['p'] : null, 'from' => isset($_GET['from']) ? (string) $_GET['from'] : null, 'to' => isset($_GET['to']) ? (string) $_GET['to'] : null,
                                                                                               'stage' => $stageFilter, 'grade' => $gradeFilter], $o), fn($v) => $v !== null && $v !== ''));
$pct = fn(int $a, int $of) => $of > 0 ? round(100 * $a / $of) . '%' : '—';

$actions = '<a class="btn btn-ghost btn-sm" href="' . e($qs(['export' => 1])) . '">Export CSV</a><a class="btn btn-ghost btn-sm" href="qualifier_edit.php?id=' . $fid . '">Edit</a>'
         . '<a class="btn btn-ghost btn-sm" href="leads.php?flow=' . $fid . '">Leads</a><a class="btn btn-ghost btn-sm" href="qualifiers.php">← Lead Qualifier</a>';
client_header('Report · ' . $flow['name'], 'qualifier', $CLIENT);
page_head('Report — ' . $flow['name'], $actions);
?>
<div class="stats-row">
  <a class="stat-tile" href="<?= e($qs(['stage' => '', 'grade' => ''])) ?>#leads"><span class="lbl">Leads</span><span class="val accent"><?= number_format($total) ?></span><span class="sub"><?= e(strtolower($P['label'])) ?></span></a>
  <div class="stat-tile"><span class="lbl">Delivered</span><span class="val"><?= number_format($n['delivered']) ?></span><span class="sub"><?= $pct($n['delivered'], $total) ?> of leads</span></div>
  <div class="stat-tile"><span class="lbl">Read</span><span class="val"><?= number_format($n['read']) ?></span><span class="sub"><?= $pct($n['read'], $n['delivered']) ?> of delivered</span></div>
  <div class="stat-tile"><span class="lbl">Replied</span><span class="val accent"><?= number_format($n['replied']) ?></span><span class="sub"><?= $pct($n['replied'], $n['read']) ?> of read</span></div>
  <a class="stat-tile" href="<?= e($qs(['stage' => 'done', 'grade' => ''])) ?>#leads"><span class="lbl">Finished</span><span class="val"><?= number_format($byStage['done']) ?></span><span class="sub"><?= $pct($byStage['done'], $n['replied']) ?> of replied</span></a>
  <a class="stat-tile" href="<?= e($qs(['stage' => '', 'grade' => 'hot'])) ?>#leads"><span class="lbl">Hot leads</span><span class="val danger"><?= number_format($grades['hot']) ?></span><span class="sub"><?= number_format($grades['warm']) ?> warm · <?= number_format($grades['cold']) ?> cold</span></a>
</div>

<?= viz_period_bar($P, ['id' => $fid]) ?>
<?php if ($capped): ?><div class="alert info" style="font-size:12.5px">This period has more than <?= number_format($CAP) ?> leads; the report shows the newest <?= number_format($CAP) ?>. Choose a shorter period to see the rest.</div><?php endif; ?>

<div class="viz-grid2" style="margin:16px 0">
  <?= viz_card('How far leads get', viz_funnel([['Leads', $total], ['Sent', $n['sent']], ['Delivered', $n['delivered']], ['Read', $n['read']], ['Replied', $n['replied']],
        ['Finished the conversation', $byStage['done']], ['Hot', $grades['hot']]], ['empty' => 'No leads in this period.']),
      'Each bar is the leads that got at least that far.', 'q-funnel') ?>
  <div class="card" style="margin:0" id="q-stages">
    <h2>Where leads stopped</h2>
    <p class="text-muted" style="font-size:12.5px;margin:-6px 0 12px">Every lead is in exactly one place. Click one to list those leads.</p>
    <?php if (!$total): ?><div class="viz-empty">No leads in this period.</div><?php else: foreach ($STAGES as $k => [$label, $hint]): $c = $byStage[$k]; ?>
      <a href="<?= e($qs(['stage' => $k, 'grade' => '', 'page' => null])) ?>#leads" title="<?= e($hint) ?>" style="display:block;text-decoration:none;color:inherit;margin-bottom:9px<?= $stageFilter === $k ? ';font-weight:700' : '' ?>">
        <div style="display:flex;justify-content:space-between;font-size:13px"><span><?= e($label) ?></span><span><strong><?= number_format($c) ?></strong> <span class="text-muted"><?= $pct($c, $total) ?></span></span></div>
        <div style="height:8px;border-radius:4px;background:var(--paper);overflow:hidden;margin-top:3px"><div style="height:100%;width:<?= $total ? round(100 * $c / $total, 1) : 0 ?>%;background:<?= ['queued' => '#9ca3af', 'failed' => '#dc2626', 'sent' => '#93c5fd', 'delivered' => '#60a5fa', 'read' => '#3b82f6', 'partial' => '#f59e0b', 'done' => '#16a34a'][$k] ?>;border-radius:4px"></div></div>
      </a>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="viz-grid2" style="margin-bottom:16px">
  <div class="card" style="margin:0" id="q-stopq">
    <h2>The question they stopped at</h2>
    <p class="text-muted" style="font-size:12.5px;margin:-6px 0 12px">Leads who replied and then went quiet: the last thing they were asked and did not answer.</p>
    <?php if (!$stopQ): ?><div class="viz-empty">Nobody stopped part-way in this period.</div>
    <?php else: $mx = max($stopQ); foreach (array_slice($stopQ, 0, 8, true) as $q => $c): ?>
      <div style="margin-bottom:10px"><div style="display:flex;gap:10px;justify-content:space-between;font-size:13px"><span style="min-width:0">“<?= e($q) ?>”</span><strong><?= number_format($c) ?></strong></div>
        <div style="height:6px;border-radius:3px;background:var(--paper);margin-top:3px"><div style="height:100%;width:<?= round(100 * $c / $mx, 1) ?>%;background:#f59e0b;border-radius:3px"></div></div></div>
    <?php endforeach; endif; ?>
    <?php if ($answersN): ksort($answersN); ?><h3 class="viz-sub">How many answers leads gave</h3>
      <?= viz_bars(array_map(fn($k, $v) => ['label' => $k >= 6 ? '6 or more' : $k . ($k === 1 ? ' answer' : ' answers'), 'n' => $v], array_keys($answersN), $answersN), [['n', 'Leads', 1]]) ?><?php endif; ?>
  </div>
  <div class="card" style="margin:0" id="q-captured">
    <h2>What leads told us</h2>
    <p class="text-muted" style="font-size:12.5px;margin:-6px 0 12px">The details the qualifier captured, and the most common answers to each.</p>
    <?php if (!$fieldVals): ?><div class="viz-empty">Nothing captured yet. Add what to capture under “What to find out” in the qualifier.</div>
    <?php else: foreach (array_slice($fieldVals, 0, 8, true) as $k => $vals): arsort($vals); ?>
      <div style="margin-bottom:12px"><div style="font-size:12.5px;font-weight:700;margin-bottom:4px"><?= e(ucfirst(str_replace('_', ' ', (string) $k))) ?> <span class="text-muted" style="font-weight:400"><?= number_format(array_sum($vals)) ?> lead<?= array_sum($vals) === 1 ? '' : 's' ?></span></div>
        <?php foreach (array_slice($vals, 0, 6, true) as $v => $c): ?><span class="pill gray" style="margin:0 4px 4px 0;display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom" title="<?= e((string) $v) ?>"><?= e((string) $v) ?> · <?= (int) $c ?></span><?php endforeach; ?>
        <?php if (count($vals) > 6): ?><span class="text-muted" style="font-size:12px">+<?= count($vals) - 6 ?> more</span><?php endif; ?></div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="card card-flush" id="leads">
  <div style="padding:14px 18px" class="row-between">
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a class="btn <?= $stageFilter === '' && $gradeFilter === '' ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="<?= e($qs(['stage' => '', 'grade' => '', 'page' => null])) ?>#leads">All</a>
      <?php foreach ($STAGES as $k => [$label]): if (!$byStage[$k]) continue; ?>
        <a class="btn <?= $stageFilter === $k ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="<?= e($qs(['stage' => $k, 'grade' => '', 'page' => null])) ?>#leads"><?= e($label) ?> <span style="opacity:.7"><?= number_format($byStage[$k]) ?></span></a>
      <?php endforeach; ?>
      <?php foreach (['hot', 'warm', 'cold'] as $g): if (!$grades[$g]) continue; ?>
        <a class="btn <?= $gradeFilter === $g ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="<?= e($qs(['stage' => '', 'grade' => $g, 'page' => null])) ?>#leads"><?= ucfirst($g) ?> <span style="opacity:.7"><?= number_format($grades[$g]) ?></span></a>
      <?php endforeach; ?>
    </div>
    <span class="text-muted" style="font-size:12.5px"><?= number_format(count($shown)) ?> lead<?= count($shown) === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Lead</th><th>Where they stopped</th><th>Last reply</th><th>Told us</th><th>Score</th><th>Started</th><th></th></tr></thead>
    <tbody>
    <?php if (!$pageRows): ?><tr><td colspan="7"><div class="empty">No leads here.</div></td></tr><?php endif; ?>
    <?php foreach ($pageRows as $l): $sc = ['queued' => 'gray', 'failed' => 'red', 'sent' => 'gray', 'delivered' => 'blue', 'read' => 'blue', 'partial' => 'gold', 'done' => 'green'][$l['stage']]; ?>
      <tr>
        <td><?= e($l['name']) ?: '—' ?><div class="mono text-muted" style="font-size:12px"><?= e(function_exists('crm_phone_show') ? crm_phone_show($l['phone']) : '+' . $l['phone']) ?></div></td>
        <td style="font-size:12.5px;max-width:280px"><span class="pill <?= $sc ?>"><?= e($stageWords($l)) ?></span>
          <?php if ($l['stage'] === 'partial' && $l['unanswered'] !== ''): ?><div class="text-muted" style="margin-top:3px">Did not answer: “<?= e(mb_strimwidth($l['unanswered'], 0, 90, '…')) ?>”</div>
          <?php elseif ($l['stage'] === 'failed' && $l['err'] !== ''): ?><div class="text-muted" style="margin-top:3px"><?= e(mb_strimwidth($l['err'], 0, 90, '…')) ?></div><?php endif; ?></td>
        <td style="font-size:12.5px;max-width:240px"><?= $l['last_reply'] !== '' ? e(mb_strimwidth($l['last_reply'], 0, 90, '…')) . ($l['replies'] > 1 ? ' <span class="text-muted">+' . ($l['replies'] - 1) . '</span>' : '') : '<span class="text-muted">—</span>' ?></td>
        <td style="font-size:12.5px;max-width:240px"><?php if ($l['fields']): foreach (array_slice($l['fields'], 0, 4, true) as $k => $v): ?><div><span class="text-muted"><?= e((string) $k) ?>:</span> <?= e(mb_strimwidth((string) $v, 0, 50, '…')) ?></div><?php endforeach; else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td><?= $l['score'] ?><?= $l['grade'] !== '' && $l['grade'] !== 'no_answer' ? ' <span class="pill ' . (['hot' => 'red', 'warm' => 'gold'][$l['grade']] ?? 'gray') . '">' . e($l['grade']) . '</span>' : '' ?></td>
        <td class="text-muted" style="white-space:nowrap"><?= e(date('d M, H:i', strtotime($l['created']))) ?></td>
        <td style="text-align:end;white-space:nowrap">
          <?php if ($l['tr']): ?><button type="button" class="btn-link" onclick="this.closest('tr').nextElementSibling.hidden = !this.closest('tr').nextElementSibling.hidden">Conversation</button> · <?php endif; ?>
          <a class="btn-link" href="inbox.php?contact=<?= $l['contact_id'] ?>">Inbox</a></td>
      </tr>
      <tr hidden><td colspan="7" style="background:var(--paper)"><div style="max-height:300px;overflow:auto;font-size:12.5px">
        <?php foreach (array_slice($l['tr'], -60) as $t): $me = ($t['role'] ?? '') !== 'user'; ?>
          <div style="margin:4px 0;<?= $me ? '' : 'font-weight:600' ?>"><span class="text-muted" style="font-weight:400"><?= $me ? 'Qualifier' : 'Lead' ?>:</span> <?= nl2br(e(mb_strimwidth((string) $t['text'], 0, 600, '…'))) ?></div>
        <?php endforeach; ?></div></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
    <div style="padding:14px 18px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
      <?php for ($p = 1; $p <= min($pages, 40); $p++): ?><a class="btn <?= $p === $page ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="<?= e($qs(['page' => $p])) ?>#leads"><?= $p ?></a><?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<?php layout_footer(); ?>
