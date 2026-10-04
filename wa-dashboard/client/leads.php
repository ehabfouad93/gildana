<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/automation.php';

$cid  = (int) $CLIENT['id'];
$fid  = (int) ($_GET['flow'] ?? 0);
$flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='qualifier'", [$fid, $cid]);
if (!$flow) { http_response_code(404); exit('Qualifier not found.'); }

/* ── Import now (manual trigger from the Google Sheet) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_now') {
    verify_csrf();
    $r = automation_ingest_flow($CLIENT, $flow);
    if ($r['error'] !== '') {
        flash('Import failed: ' . $r['error'], 'error');
    } else {
        trigger_worker();
        flash("Imported {$r['imported']} new · {$r['skipped']} already in this qualifier · {$r['invalid']} invalid number(s) of {$r['rows']} rows. Outreach is sending in the background.");
    }
    redirect('leads.php?flow=' . $fid);
}

/* ── Manual CSV upload → import leads into this qualifier ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_csv') {
    verify_csrf();
    if (empty($_FILES['leads_csv']['tmp_name']) || (int) ($_FILES['leads_csv']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        flash('Please choose a CSV file to upload.', 'error');
        redirect('leads.php?flow=' . $fid);
    }
    $csv = (string) file_get_contents((string) $_FILES['leads_csv']['tmp_name']);
    $country = preg_replace('/\D+/', '', (string) ($_POST['import_country'] ?? ''));
    if ($country === '') {
        $scq = json_decode((string) $flow['source_config'], true) ?: [];
        $country = trim((string) ($scq['country'] ?? '')) ?: (string) ($CLIENT['default_country'] ?? '');
    }
    $r = automation_ingest_rows($CLIENT, $flow, $csv, 5000, $country);
    if ($r['error'] !== '') {
        flash('Upload failed: ' . $r['error'], 'error');
    } else {
        trigger_worker();
        flash("Imported {$r['imported']} new · {$r['skipped']} already in this qualifier · {$r['invalid']} invalid number(s) of {$r['rows']} rows. Outreach is sending in the background.");
    }
    redirect('leads.php?flow=' . $fid);
}

/* ── Add a lead manually (phone + name → enqueue outreach) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_lead') {
    verify_csrf();
    $phone = normalize_phone((string) ($_POST['phone'] ?? ''), (string) ($CLIENT['default_country'] ?? ''));
    $name  = trim((string) ($_POST['name'] ?? ''));
    if ($phone === '') { flash('Enter a valid phone number.', 'error'); redirect('leads.php?flow=' . $fid); }
    $contact = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $phone]);
    if (!$contact) {
        db_run("INSERT INTO contacts (client_id,phone_e164,name,opt_in_status,source,created_at) VALUES (?,?,?, 'in','manual',NOW())",
            [$cid, $phone, $name]);
        $contact = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $phone]);
    } elseif ($name !== '' && (string) $contact['name'] === '') {
        db_run("UPDATE contacts SET name=? WHERE id=?", [$name, (int) $contact['id']]);
        $contact['name'] = $name;
    }
    if (!$contact) { flash('Could not add the lead.', 'error'); redirect('leads.php?flow=' . $fid); }
    if (db_row("SELECT id FROM flow_runs WHERE flow_id=? AND contact_id=?", [$fid, (int) $contact['id']])) {
        flash('That number is already in this qualifier.', 'error'); redirect('leads.php?flow=' . $fid);
    }
    automation_enqueue_lead($CLIENT, $flow, $contact);
    flash('Lead added — outreach is sending now.');
    redirect('leads.php?flow=' . $fid);
}

/* ── Edit a lead's phone / name (fix a wrong number) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_lead') {
    verify_csrf();
    $run = db_row("SELECT * FROM flow_runs WHERE id=? AND flow_id=? AND client_id=?", [(int) ($_POST['run_id'] ?? 0), $fid, $cid]);
    if (!$run) { flash('Lead not found.', 'error'); redirect('leads.php?flow=' . $fid); }
    $phone = normalize_phone((string) ($_POST['phone'] ?? ''), (string) ($CLIENT['default_country'] ?? ''));
    $name  = trim((string) ($_POST['name'] ?? ''));
    if ($phone === '') { flash('Enter a valid phone number.', 'error'); redirect('leads.php?flow=' . $fid); }
    if (db_row("SELECT id FROM contacts WHERE client_id=? AND phone_e164=? AND id<>?", [$cid, $phone, (int) $run['contact_id']])) {
        flash('Another contact already uses that number.', 'error'); redirect('leads.php?flow=' . $fid);
    }
    db_run("UPDATE contacts SET phone_e164=?, name=? WHERE id=?", [$phone, $name, (int) $run['contact_id']]);
    flash('Number updated. Use "Send now" to retry the outreach if it was stuck.');
    redirect('leads.php?flow=' . $fid);
}

/* ── Resend the outreach template to a lead (re-queue) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    verify_csrf();
    $run = db_row("SELECT * FROM flow_runs WHERE id=? AND flow_id=? AND client_id=?", [(int) ($_POST['run_id'] ?? 0), $fid, $cid]);
    if (!$run) { flash('Lead not found.', 'error'); redirect('leads.php?flow=' . $fid); }
    $ctx = json_decode((string) $run['context'], true) ?: [];
    unset($ctx['send_error'], $ctx['send_error_code'], $ctx['outreach']);
    db_run("UPDATE flow_runs SET status='queued', wait_until=NULL, context=?, updated_at=NOW() WHERE id=?",
        [json_encode($ctx, JSON_UNESCAPED_UNICODE), (int) $run['id']]);
    trigger_worker();
    flash('Re-queued — the outreach will be sent again shortly.');
    redirect('leads.php?flow=' . $fid);
}

/* ── Resend the leads whose outreach failed: all of one cause, or every one worth resending,
      with the qualifier's own message or another template / message, now or in 24 hours. ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_failed') {
    verify_csrf();
    require_once __DIR__ . '/../includes/msg_status.php';
    $scope = (string) ($_POST['scope'] ?? 'retry');
    $ids = [];
    foreach (qualifier_failures($fid) as $g) {
        if ($scope === 'all' || ($scope === 'retry' && $g['action'] !== 'never') || $scope === $g['slug']) $ids = array_merge($ids, $g['resend']);
    }
    $ov = null;
    $use = (string) ($_POST['use'] ?? 'same');
    if ($use === 'template') {
        $tpl = db_row("SELECT id, components FROM templates WHERE id=? AND client_id=? AND status='APPROVED'", [(int) ($_POST['template_id'] ?? 0), $cid]);
        if (!$tpl) { flash('Choose an approved template.', 'error'); redirect('leads.php?flow=' . $fid . '&msg=failed'); }
        $cfg = ['vars' => []];
        foreach ((array) ($_POST['var'] ?? []) as $i => $v) {
            $v = trim((string) $v);
            $cfg['vars'][(string) (int) $i] = $v === '{name}' ? ['source' => 'name', 'fallback' => trim((string) ($_POST['fallback'] ?? ''))] : ['source' => 'static', 'value' => $v];
        }
        if (($m = trim((string) ($_POST['header_media'] ?? ''))) !== '') $cfg['header_media'] = $m;
        $ov = ['template_id' => (int) $tpl['id'], 'cfg' => $cfg];
    } elseif ($use === 'text') {
        $text = trim((string) ($_POST['text'] ?? ''));
        if ($text === '' || !channel_is_personal($CLIENT)) { flash('Write the message to send.', 'error'); redirect('leads.php?flow=' . $fid . '&msg=failed'); }
        $ov = ['text' => mb_substr($text, 0, 4000)];
    }
    $later = ($_POST['when'] ?? 'now') === 'later';
    foreach ($ids as $id) {
        $ctx = json_decode((string) db_val("SELECT context FROM flow_runs WHERE id=?", [$id]), true) ?: [];
        unset($ctx['send_error'], $ctx['send_error_code'], $ctx['outreach']);
        if ($ov) $ctx['outreach'] = $ov;
        $ctx['resends'] = (int) ($ctx['resends'] ?? 0) + 1;
        $ctx['resent_at'] = date('Y-m-d H:i:s');
        db_run("UPDATE flow_runs SET status='queued', wait_until=?, context=?, updated_at=NOW() WHERE id=? AND flow_id=?",
               [$later ? date('Y-m-d H:i:s', time() + 86400) : null, json_encode($ctx, JSON_UNESCAPED_UNICODE), $id, $fid]);
    }
    if ($ids && !$later) trigger_worker();
    flash($ids ? count($ids) . ' lead' . (count($ids) === 1 ? '' : 's') . ' queued to resend ' . ($later ? 'in 24 hours' : 'now') . '. They leave the Failed list once delivered.'
               : 'Nothing to resend in that group.', $ids ? 'success' : 'error');
    redirect('leads.php?flow=' . $fid . '&msg=failed');
}

/* ── Manually mark a lead as "Not interested" (with a reason) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_not_interested') {
    verify_csrf();
    $run = db_row("SELECT * FROM flow_runs WHERE id=? AND flow_id=? AND client_id=?", [(int) ($_POST['run_id'] ?? 0), $fid, $cid]);
    if (!$run) { flash('Lead not found.', 'error'); redirect('leads.php?flow=' . $fid); }
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $ctx = json_decode((string) $run['context'], true) ?: [];
    $ctx['fields'] = (array) ($ctx['fields'] ?? []);
    $ctx['fields']['not_interested_reason'] = $reason;
    $ctx['not_interested'] = ['reason' => $reason];
    db_run("UPDATE flow_runs SET grade='not_interested', status='completed', context=?, updated_at=NOW() WHERE id=?",
        [json_encode($ctx, JSON_UNESCAPED_UNICODE), (int) $run['id']]);
    flash('Marked as not interested.');
    redirect('leads.php?flow=' . $fid);
}

require_once __DIR__ . '/../includes/msg_status.php';
$grade = (string) ($_GET['grade'] ?? '');
$msg = (string) ($_GET['msg'] ?? '');
if (!in_array($msg, ['sent', 'read', 'unread', 'failed'], true)) $msg = '';
$where = "r.flow_id=?"; $params = [$fid];
if (in_array($grade, ['hot','warm','cold','not_interested','no_answer'], true)) { $where .= " AND r.grade=?"; $params[] = $grade; }
if ($msg !== '') $where .= " AND " . qualifier_msg_filter($msg, 'r');
// One cause of failure, from the "Why messages failed" report.
$failures = qualifier_failures($fid);
$reason = (string) ($_GET['reason'] ?? '');
$rg = $msg === 'failed' ? (array_values(array_filter($failures, fn($g) => $g['slug'] === $reason))[0] ?? null) : null;
if (!$rg) $reason = '';
else $where .= ' AND r.id IN (' . (implode(',', array_map('intval', $rg['ids'])) ?: '0') . ')';
/* ── CSV export: the leads the page is showing (grade, message and failure-reason filters), with
      what happened to each one's message and why it failed. ── */
if (($_GET['export'] ?? '') === '1') {
    $rows = db_all(
        "SELECT r.*, c.phone_e164, c.name, " . qualifier_best_sql('r') . " AS msg_best, " . qualifier_fail_sql('r') . "
           FROM flow_runs r JOIN contacts c ON c.id=r.contact_id WHERE $where ORDER BY r.id DESC", $params
    );
    // union of captured field keys (not_interested_reason gets its own column, below)
    $fieldKeys = [];
    foreach ($rows as $r) {
        $ctx = json_decode((string) $r['context'], true) ?: [];
        foreach (array_keys((array) ($ctx['fields'] ?? [])) as $k) {
            if ($k === 'not_interested_reason') continue;
            $fieldKeys[$k] = true;
        }
    }
    $fieldKeys = array_keys($fieldKeys);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="leads-' . $fid . ($msg ? '-' . $msg : '') . ($grade ? '-' . $grade : '') . '-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map('csv_cell', array_merge(['phone', 'name', 'message', 'failure_reason', 'error_code', 'error_detail', 'status', 'score', 'grade', 'not_interested_reason', 'resends', 'created'], $fieldKeys))) . "\n";
    foreach ($rows as $r) {
        $ctx = json_decode((string) $r['context'], true) ?: [];
        $fields = (array) ($ctx['fields'] ?? []);
        $b = (int) $r['msg_best'];
        $fail = $b === 0 && ($r['fail_title'] !== null || ($r['status'] === 'blocked' && !$r['grade'])) ? qualifier_fail_reason($r) : null;
        $out = [
            (string) $r['phone_e164'], (string) $r['name'],
            [3 => 'read', 2 => 'delivered', 1 => 'sent'][$b] ?? ($fail ? 'failed' : 'not sent yet'),
            $fail ? $fail[1] : '', $fail ? $fail[4] : '', $fail ? $fail[5] : '',
            (string) $r['status'], (string) (int) $r['score'], (string) ($r['grade'] ?? ''),
            (string) ($fields['not_interested_reason'] ?? ''), (string) (int) ($ctx['resends'] ?? 0), (string) $r['created_at'],
        ];
        foreach ($fieldKeys as $k) $out[] = (string) ($fields[$k] ?? '');
        echo implode(',', array_map('csv_cell', $out)) . "\n";
    }
    exit;
}

$counts = db_row(
    "SELECT COUNT(*) total,
            SUM(grade='hot') hot, SUM(grade='warm') warm, SUM(grade='cold') cold,
            SUM(grade='not_interested') noint, SUM(grade='no_answer') noans,
            SUM(status IN ('waiting_input','active') AND (grade IS NULL OR grade='')) chatting,
            SUM(status='completed') completed,
            SUM(status='blocked') blocked
       FROM flow_runs WHERE flow_id=?", [$fid]
) ?: [];

$mc = qualifier_msg_counts($cid, $fid)[$fid] ?? ['leads' => 0, 'sent' => 0, 'read' => 0, 'unread' => 0, 'failed' => 0];
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 50; $off = ($page - 1) * $per;
$total = (int) db_val("SELECT COUNT(*) FROM flow_runs r WHERE $where", $params);
$leads = db_all("SELECT r.*, c.phone_e164, c.name, " . qualifier_best_sql('r') . " AS msg_best, (" . qualifier_msg_filter('failed', 'r') . ") AS msg_failed, " . qualifier_fail_sql('r') . " FROM flow_runs r JOIN contacts c ON c.id=r.contact_id WHERE $where ORDER BY r.id DESC LIMIT $per OFFSET $off", $params);
$q = fn(array $set) => 'leads.php?' . http_build_query(array_filter($set + ['flow' => $fid, 'grade' => $grade, 'msg' => $msg, 'reason' => $reason], 'strlen'));
$pages = (int) max(1, ceil($total / $per));

function lead_status_pill(string $s): string {
    $map = ['queued' => ['gold','Queued'], 'completed' => ['green','Scored'], 'waiting_input' => ['gold','Chatting'], 'active' => ['gold','Chatting'],
            'waiting_timer' => ['gold','Waiting'], 'blocked' => ['red','Stopped'], 'stopped' => ['gray','Stopped']];
    [$cls,$lbl] = $map[$s] ?? ['gray', ucfirst($s)];
    return '<span class="pill ' . $cls . '">' . e($lbl) . '</span>';
}
function grade_pill(?string $g): string {
    if (!$g) return '<span class="text-muted">—</span>';
    $map = ['hot' => ['red','Hot'], 'warm' => ['gold','Warm'], 'cold' => ['gray','Cold'],
            'not_interested' => ['red','Not interested'], 'no_answer' => ['gray','No answer']];
    [$c,$lbl] = $map[$g] ?? ['gray', ucfirst($g)];
    return '<span class="pill ' . $c . '">' . e($lbl) . '</span>';
}

$actions = '<button class="btn btn-primary btn-sm" onclick="document.getElementById(\'m-add\').classList.add(\'open\')">+ Add lead</button>'
         . '<button class="btn btn-ghost btn-sm" onclick="document.getElementById(\'m-upload\').classList.add(\'open\')">&#8593; Upload CSV</button>'
         . '<form method="post" style="display:inline">' . csrf_field()
         . '<input type="hidden" name="action" value="import_now">'
         . '<button class="btn btn-ghost btn-sm">&#8635; Import now</button></form>'
         . '<a class="btn btn-ghost btn-sm" href="qualifier_edit.php?id=' . $fid . '">Edit</a>'
         . '<a class="btn btn-ghost btn-sm" href="' . e($q(['export' => '1', 'page' => ''])) . '" title="The leads shown below, with what happened to each message">Export CSV'
         . ($msg || $grade ? ' (' . number_format($total) . ')' : '') . '</a>';
client_header('Leads · ' . $flow['name'], 'qualifier', $CLIENT);
page_head('Leads — ' . $flow['name'], $actions);
?>
<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Total leads</span><span class="val accent"><?= (int) ($counts['total'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Hot</span><span class="val danger"><?= (int) ($counts['hot'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Warm</span><span class="val"><?= (int) ($counts['warm'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Cold</span><span class="val"><?= (int) ($counts['cold'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Not interested</span><span class="val"><?= (int) ($counts['noint'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">No answer</span><span class="val"><?= (int) ($counts['noans'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Chatting</span><span class="val"><?= (int) ($counts['chatting'] ?? 0) ?></span><span class="sub"><?= (int) ($counts['completed'] ?? 0) ?> scored</span></div>
</div>
<?php $pc = fn(int $n, int $of) => $of > 0 ? round(100 * $n / $of) . '%' : '—'; ?>
<div class="stats-row">
  <?php foreach (['sent' => ['Sent', $pc($mc['sent'], $mc['leads']) . ' of leads', ''], 'read' => ['Read', $pc($mc['read'], $mc['sent']) . ' of sent', 'accent'],
                  'unread' => ['Unread', $pc($mc['unread'], $mc['sent']) . ' of sent', ''], 'failed' => ['Failed', $pc($mc['failed'], $mc['leads']) . ' of leads', 'danger']] as $k => [$l, $s, $cls]): ?>
    <a class="stat-tile <?= $msg === $k ? 'on' : '' ?>" href="<?= e($q(['msg' => $msg === $k ? '' : $k, 'page' => '', 'reason' => ''])) ?>"><span class="lbl"><?= $l ?></span><span class="val <?= $cls ?>"><?= $mc[$k] ?></span><span class="sub"><?= $s ?></span></a>
  <?php endforeach; ?>
</div>

<?php if ($failures):
  $nFail = array_sum(array_map(fn($g) => count($g['ids']), $failures));
  $nRetry = array_sum(array_map(fn($g) => $g['action'] !== 'never' ? count($g['resend']) : 0, $failures));
  $act = ['later' => ['blue', 'Worth resending'], 'fix' => ['gold', 'Fix first'], 'never' => ['red', "Won't get through"]]; ?>
<div class="card" id="why-failed">
  <div class="row-between" style="flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0">Why messages failed</h2>
      <p class="text-muted" style="font-size:12.5px;margin:4px 0 0"><?= $nFail ?> lead<?= $nFail === 1 ? '' : 's' ?> never received the first message, by cause. Hover a cause for what WhatsApp said.</p>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a class="btn btn-ghost btn-sm" href="<?= e('leads.php?' . http_build_query(['flow' => $fid, 'msg' => 'failed', 'export' => '1'])) ?>">Export failed</a>
      <button type="button" class="btn btn-primary btn-sm" onclick="openResend('retry')"<?= $nRetry ? '' : ' disabled' ?>>Resend failed (<?= $nRetry ?>)</button>
    </div>
  </div>
  <div class="table-wrap" style="margin-top:12px">
    <table class="data fail-table">
      <thead><tr><th>Cause</th><th class="num">Leads</th><th class="num">Share</th><th>What to do</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($failures as $g): [$cls, $lbl] = $act[$g['action']] ?? $act['later']; $n = count($g['ids']); ?>
        <tr<?= $reason === $g['slug'] ? ' class="on"' : '' ?>>
          <td title="<?= e($g['sample']) ?>"><strong><?= e($g['label']) ?></strong>
            <?php if ($g['codes']): ?><div class="text-muted" style="font-size:11.5px">code <?= e(implode(', ', array_keys($g['codes']))) ?></div><?php endif; ?></td>
          <td class="num"><?= $n ?></td>
          <td class="num"><div class="fail-bar"><span style="width:<?= round($n / $nFail * 100) ?>%"></span></div><?= round($n / $nFail * 100) ?>%</td>
          <td style="max-width:420px"><span class="pill <?= $cls ?>"><?= e($lbl) ?></span> <span class="text-muted" style="font-size:12px"><?= strip_tags($g['hint'], '<em><strong>') ?></span></td>
          <td style="text-align:end;white-space:nowrap">
            <a class="btn btn-ghost btn-sm" href="<?= e($q(['msg' => 'failed', 'reason' => $g['slug'], 'grade' => '', 'page' => ''])) ?>">Show</a>
            <?php if ($g['resend']): ?><button type="button" class="btn btn-ghost btn-sm" onclick="openResend(<?= e(json_encode($g['slug'])) ?>)">Resend <?= count($g['resend']) ?></button><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <div style="display:flex;gap:6px">
      <?php foreach (['' => 'All','hot' => 'Hot','warm' => 'Warm','cold' => 'Cold','not_interested' => 'Not interested','no_answer' => 'No answer'] as $v => $l): ?>
        <a class="btn <?= $grade === $v ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="<?= e($q(['grade' => $v, 'page' => '', 'reason' => ''])) ?>"><?= $l ?></a>
      <?php endforeach; ?>
      <span class="sep-v"></span>
      <?php foreach (['' => 'Any message', 'sent' => 'Sent', 'read' => 'Read', 'unread' => 'Unread', 'failed' => 'Failed'] as $v => $l): ?>
        <a class="btn <?= $msg === $v ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="<?= e($q(['msg' => $v, 'page' => '', 'reason' => ''])) ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>
    <span class="text-muted" style="font-size:12.5px"><?php if ($rg): ?><a class="pill red" href="<?= e($q(['reason' => '', 'page' => ''])) ?>" title="Show every failed lead"><?= e($rg['label']) ?> ✕</a> <?php endif; ?><?= number_format($total) ?> lead<?= $total === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Phone</th><th>Name</th><th>Message</th><th>Status</th><th>Score</th><th>Grade</th><th>Reason</th><th>Added</th><th></th></tr></thead>
      <tbody>
      <?php if (!$leads): ?><tr><td colspan="9"><div class="empty">No leads<?= $msg !== '' || $grade !== '' ? ' match this filter' : ' yet' ?>. <?= $msg === '' && $grade === '' ? ' Activate the qualifier and it imports from your sheet.' : '' ?></div></td></tr><?php endif; ?>
      <?php foreach ($leads as $r):
        $ctx    = json_decode((string) $r['context'], true) ?: [];
        $tr     = json_encode($ctx['transcript'] ?? [], JSON_UNESCAPED_UNICODE);
        $reason = (string) (($ctx['fields'] ?? [])['not_interested_reason'] ?? '');
        $resends = (int) ($ctx['resends'] ?? 0);
        $fail   = (int) $r['msg_best'] === 0 && (int) $r['msg_failed'] ? qualifier_fail_reason($r) : null;
        if ($reason === '' && $fail) $reason = $fail[1] . ($fail[4] !== '' ? ' (' . $fail[4] . ')' : '');   // why WhatsApp refused it
      ?>
        <tr>
          <td class="mono">+<?= e((string) $r['phone_e164']) ?></td>
          <td><?= e((string) $r['name']) ?: '<span class="text-muted">—</span>' ?></td>
          <td><?php $b = (int) $r['msg_best']; ?><span class="pill <?= [3 => 'green', 2 => 'blue', 1 => 'gray'][$b] ?? ((int) $r['msg_failed'] ? 'red' : 'gray') ?>"><?= [3 => 'Read', 2 => 'Delivered · unread', 1 => 'Sent · unread'][$b] ?? ((int) $r['msg_failed'] ? 'Failed' : 'Not sent yet') ?></span></td>
          <td><?= lead_status_pill((string) $r['status']) ?></td>
          <td><strong><?= (int) $r['score'] ?></strong></td>
          <td><?= grade_pill($r['grade']) ?></td>
          <td class="text-muted" style="max-width:220px;font-size:12px"<?= $fail ? ' title="' . e($fail[5]) . '"' : '' ?>><?= $reason !== '' ? e($reason) : '<span class="text-muted">—</span>' ?><?= $resends ? '<div>Resent ×' . $resends . '</div>' : '' ?></td>
          <td class="text-muted"><?= e(date('d M, H:i', strtotime((string) $r['created_at']))) ?></td>
          <td style="text-align: end;white-space:nowrap">
            <button class="btn-link" onclick='editLead(this)' data-run="<?= (int) $r['id'] ?>" data-phone="<?= e((string) $r['phone_e164']) ?>" data-name="<?= e((string) $r['name']) ?>">Edit</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Send the outreach to this lead again?')"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="run_id" value="<?= (int) $r['id'] ?>"><button class="btn-link">Resend</button></form>
            <button class="btn-link" onclick='markNI(this)' data-run="<?= (int) $r['id'] ?>">Not interested</button>
            <button class="btn-link" onclick='viewChat(this)' data-tr='<?= e($tr) ?>' data-name="<?= e((string) $r['name'] ?: $r['phone_e164']) ?>">Transcript</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div style="padding:14px 18px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
      <?php for ($p = 1; $p <= min($pages, 30); $p++): ?>
        <a class="btn <?= $p === $page ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="<?= e($q(['page' => $p])) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<div class="modal-back" id="m-add">
  <form class="modal" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_lead">
    <h2>Add a lead</h2>
    <div class="field"><span class="lbl">Phone</span><input type="text" name="phone" placeholder="201022627976 or 01022627976" required></div>
    <div class="field"><span class="lbl">Name <span class="text-muted">(optional)</span></span><input type="text" name="name"></div>
    <p class="text-muted" style="font-size:12px">The outreach template is sent immediately. Numbers are auto-formatted using the account's default country.</p>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-add').classList.remove('open')">Cancel</button><button class="btn btn-primary">Add &amp; send</button></div>
  </form>
</div>

<div class="modal-back" id="m-edit">
  <form class="modal" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="edit_lead"><input type="hidden" name="run_id" id="edit-run">
    <h2>Edit lead</h2>
    <div class="field"><span class="lbl">Phone</span><input type="text" name="phone" id="edit-phone" required></div>
    <div class="field"><span class="lbl">Name</span><input type="text" name="name" id="edit-name"></div>
    <p class="text-muted" style="font-size:12px">Fix a wrong number, then press <strong>Send now</strong> on the qualifier to retry the outreach.</p>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-edit').classList.remove('open')">Cancel</button><button class="btn btn-primary">Save</button></div>
  </form>
</div>

<div class="modal-back" id="m-upload">
  <form class="modal" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="upload_csv">
    <h2>Upload leads (CSV)</h2>
    <div class="field"><span class="lbl">CSV file <span class="text-muted">(columns: phone, name)</span></span><input type="file" name="leads_csv" accept=".csv,.txt" required></div>
    <div class="field"><span class="lbl">Country <span class="text-muted">(local numbers get this code)</span></span>
      <?php $qsc = json_decode((string) $flow['source_config'], true) ?: []; ?>
      <?= function_exists('country_picker_html')
            ? country_picker_html('import_country', (string) ($qsc['country'] ?? ''))
            : '<select name="import_country"><option value="">Auto-detect</option><option value="20">Egypt (+20)</option><option value="966">Saudi Arabia (+966)</option><option value="971">UAE (+971)</option></select>' ?>
    </div>
    <p class="text-muted" style="font-size:12px">New numbers get the outreach template immediately. Numbers already in this qualifier are skipped.</p>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-upload').classList.remove('open')">Cancel</button><button class="btn btn-primary">Upload &amp; send</button></div>
  </form>
</div>

<div class="modal-back" id="m-ni">
  <form class="modal" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_not_interested"><input type="hidden" name="run_id" id="ni-run">
    <h2>Mark as not interested</h2>
    <div class="field"><span class="lbl">Reason <span class="text-muted">(optional)</span></span><input type="text" name="reason" id="ni-reason" placeholder="e.g. budget too low / bought elsewhere"></div>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-ni').classList.remove('open')">Cancel</button><button class="btn btn-primary">Save</button></div>
  </form>
</div>

<div class="modal-back" id="m-chat">
  <div class="modal">
    <h2 id="chat-title">Transcript</h2>
    <div id="chat-body" style="max-height:60vh;overflow-y:auto"></div>
    <div class="modal-actions"><button class="btn btn-ghost" onclick="document.getElementById('m-chat').classList.remove('open')">Close</button></div>
  </div>
</div>

<script>
function editLead(btn){
  document.getElementById('edit-run').value   = btn.dataset.run || '';
  document.getElementById('edit-phone').value = btn.dataset.phone || '';
  document.getElementById('edit-name').value  = btn.dataset.name || '';
  document.getElementById('m-edit').classList.add('open');
}
function markNI(btn){
  document.getElementById('ni-run').value = btn.dataset.run || '';
  document.getElementById('ni-reason').value = '';
  document.getElementById('m-ni').classList.add('open');
}
function viewChat(btn){
  const tr = JSON.parse(btn.dataset.tr || '[]');
  document.getElementById('chat-title').textContent = 'Transcript — ' + btn.dataset.name;
  const box = document.getElementById('chat-body');
  box.innerHTML = tr.length ? tr.map(m=>{
    const bot = m.role==='assistant';
    return `<div style="display:flex;justify-content:${bot?'flex-start':'flex-end'};margin:6px 0">
      <div style="max-width:78%;padding:8px 11px;border-radius:10px;font-size:13px;background:${bot?'var(--paper)':'#d9f5e3'}">${(m.text||'').replace(/</g,'&lt;')}</div></div>`;
  }).join('') : '<p class="text-muted">No messages yet.</p>';
  document.getElementById('m-chat').classList.add('open');
}
</script>

<?php if ($failures):
  $tpls = db_all("SELECT id, wa_name, language, components, body_text FROM templates WHERE client_id=? AND status='APPROVED' ORDER BY wa_name", [$cid]);
  $tplJs = array_map(function ($t) { $sp = wa_template_spec(json_decode((string) $t['components'], true) ?: []);
      return ['id' => (int) $t['id'], 'name' => $t['wa_name'] . ' · ' . $t['language'], 'body' => (string) $t['body_text'], 'vars' => (int) $sp['body_vars'],
              'media' => in_array(strtoupper((string) $sp['header']['format']), ['IMAGE', 'VIDEO', 'DOCUMENT'], true)]; }, $tpls);
  $personal = channel_is_personal($CLIENT); ?>
<dialog class="lead-dlg" id="resend-dlg" aria-labelledby="resend-title">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="resend_failed">
    <h2 id="resend-title">Resend failed messages</h2>
    <div class="field"><span class="lbl">Which leads</span>
      <select name="scope" id="rs-scope">
        <option value="retry">Every failed lead worth resending (<?= $nRetry ?>)</option>
        <option value="all">Every failed lead, whatever the cause (<?= array_sum(array_map(fn($g) => count($g['resend']), $failures)) ?>)</option>
        <?php foreach ($failures as $g): if (!$g['resend']) continue; ?><option value="<?= e($g['slug']) ?>">Only: <?= e($g['label']) ?> (<?= count($g['resend']) ?>)</option><?php endforeach; ?>
      </select>
      <div class="hint">Leads that already have a grade are left alone. Each resend uses one credit.</div></div>
    <div class="field"><span class="lbl">Send</span>
      <label class="mod-opt"><input type="radio" name="use" value="same" checked> The qualifier's own first message</label>
      <?php if ($tplJs): ?><label class="mod-opt"><input type="radio" name="use" value="template"> Another approved template</label><?php endif; ?>
      <?php if ($personal): ?><label class="mod-opt"><input type="radio" name="use" value="text"> A message I write now</label><?php endif; ?>
    </div>
    <div id="rs-tpl" hidden>
      <div class="field"><span class="lbl">Template</span><select name="template_id" id="rs-tid"><?php foreach ($tplJs as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select>
        <div class="hint" id="rs-body" style="white-space:pre-wrap"></div></div>
      <div id="rs-vars"></div>
      <div class="field" id="rs-media" hidden><span class="lbl">Header image / video / document link</span><input type="text" name="header_media" class="ltr" placeholder="https://…"></div>
    </div>
    <div class="field" id="rs-text" hidden><span class="lbl">Message</span><textarea name="text" rows="4" placeholder="Hi {{name}}, …"></textarea>
      <div class="hint">{{name}} becomes the lead's name.</div></div>
    <div class="field"><span class="lbl">When</span>
      <label class="mod-opt"><input type="radio" name="when" value="now" checked> Now</label>
      <label class="mod-opt"><input type="radio" name="when" value="later"> In 24 hours <span class="text-muted">— best for “Capped by WhatsApp”, which lifts after a day</span></label>
    </div>
    <div class="dlg-btns">
      <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary">Queue the resend</button>
    </div>
  </form>
</dialog>
<script>
const RS_TPLS = <?= json_encode($tplJs, JSON_UNESCAPED_UNICODE) ?>;
function openResend(scope) {
  const d = document.getElementById('resend-dlg'); document.getElementById('rs-scope').value = scope; d.showModal();
}
(function () {
  const d = document.getElementById('resend-dlg'); if (!d) return;
  const tid = document.getElementById('rs-tid');
  function drawVars() {
    const t = RS_TPLS.find(x => String(x.id) === (tid ? tid.value : '')); const box = document.getElementById('rs-vars'); box.innerHTML = '';
    if (!t) return;
    document.getElementById('rs-body').textContent = t.body;
    document.getElementById('rs-media').hidden = !t.media;
    for (let i = 1; i <= t.vars; i++) {
      const f = document.createElement('div'); f.className = 'field';
      f.innerHTML = '<span class="lbl">{{' + i + '}}</span><input type="text" name="var[' + i + ']" value="' + (i === 1 ? '{name}' : '') + '" placeholder="Text, or {name} for the lead\'s name">';
      box.appendChild(f);
    }
    if (t.vars) { const fb = document.createElement('div'); fb.className = 'field';
      fb.innerHTML = '<span class="lbl">If a lead has no name, use</span><input type="text" name="fallback" placeholder="e.g. there">'; box.appendChild(fb); }
  }
  function sync() {
    const use = d.querySelector('input[name=use]:checked').value;
    document.getElementById('rs-tpl').hidden = use !== 'template'; document.getElementById('rs-text').hidden = use !== 'text';
    if (use === 'template') drawVars();
  }
  d.querySelectorAll('input[name=use]').forEach(r => r.addEventListener('change', sync));
  if (tid) tid.addEventListener('change', drawVars);
})();
</script>
<?php endif; ?>

<?php layout_footer(); ?>
