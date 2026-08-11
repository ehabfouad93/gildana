<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/../includes/ai.php';
require __DIR__ . '/../includes/notify.php';
require __DIR__ . '/../includes/automation.php';

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
    unset($ctx['send_error']);
    db_run("UPDATE flow_runs SET status='queued', context=?, updated_at=NOW() WHERE id=?",
        [json_encode($ctx, JSON_UNESCAPED_UNICODE), (int) $run['id']]);
    trigger_worker();
    flash('Re-queued — the outreach will be sent again shortly.');
    redirect('leads.php?flow=' . $fid);
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

/* ── CSV export (leads + captured answers) ── */
if (($_GET['export'] ?? '') === '1') {
    $rows = db_all(
        "SELECT r.*, c.phone_e164, c.name FROM flow_runs r JOIN contacts c ON c.id=r.contact_id
          WHERE r.flow_id=? ORDER BY r.id DESC", [$fid]
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
    header('Content-Disposition: attachment; filename="leads-' . $fid . '-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map('csv_cell', array_merge(['phone', 'name', 'status', 'score', 'grade', 'not_interested_reason', 'created'], $fieldKeys))) . "\n";
    foreach ($rows as $r) {
        $ctx = json_decode((string) $r['context'], true) ?: [];
        $fields = (array) ($ctx['fields'] ?? []);
        $out = [
            (string) $r['phone_e164'], (string) $r['name'], (string) $r['status'],
            (string) (int) $r['score'], (string) ($r['grade'] ?? ''),
            (string) (($fields['not_interested_reason'] ?? '') ?: ($ctx['send_error'] ?? '')), (string) $r['created_at'],
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

$grade = (string) ($_GET['grade'] ?? '');
$where = "r.flow_id=?"; $params = [$fid];
if (in_array($grade, ['hot','warm','cold','not_interested','no_answer'], true)) { $where .= " AND r.grade=?"; $params[] = $grade; }
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 50; $off = ($page - 1) * $per;
$total = (int) db_val("SELECT COUNT(*) FROM flow_runs r WHERE $where", $params);
$leads = db_all("SELECT r.*, c.phone_e164, c.name FROM flow_runs r JOIN contacts c ON c.id=r.contact_id WHERE $where ORDER BY r.id DESC LIMIT $per OFFSET $off", $params);
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
         . '<a class="btn btn-ghost btn-sm" href="leads.php?flow=' . $fid . '&export=1">Export CSV</a>';
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

<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <div style="display:flex;gap:6px">
      <?php foreach (['' => 'All','hot' => 'Hot','warm' => 'Warm','cold' => 'Cold','not_interested' => 'Not interested','no_answer' => 'No answer'] as $v => $l): ?>
        <a class="btn <?= $grade === $v ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="leads.php?flow=<?= $fid ?><?= $v ? '&grade=' . $v : '' ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>
    <span class="text-muted" style="font-size:12.5px"><?= number_format($total) ?> lead<?= $total === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Phone</th><th>Name</th><th>Status</th><th>Score</th><th>Grade</th><th>Reason</th><th>Added</th><th></th></tr></thead>
      <tbody>
      <?php if (!$leads): ?><tr><td colspan="8"><div class="empty">No leads yet. Activate the qualifier and it imports from your sheet.</div></td></tr><?php endif; ?>
      <?php foreach ($leads as $r):
        $ctx    = json_decode((string) $r['context'], true) ?: [];
        $tr     = json_encode($ctx['transcript'] ?? [], JSON_UNESCAPED_UNICODE);
        $reason = (string) (($ctx['fields'] ?? [])['not_interested_reason'] ?? '');
        if ($reason === '') $reason = (string) ($ctx['send_error'] ?? '');   // surface Meta send error for blocked leads
      ?>
        <tr>
          <td class="mono">+<?= e((string) $r['phone_e164']) ?></td>
          <td><?= e((string) $r['name']) ?: '<span class="text-muted">—</span>' ?></td>
          <td><?= lead_status_pill((string) $r['status']) ?></td>
          <td><strong><?= (int) $r['score'] ?></strong></td>
          <td><?= grade_pill($r['grade']) ?></td>
          <td class="text-muted" style="max-width:220px;font-size:12px"><?= $reason !== '' ? e($reason) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted"><?= e(date('d M, H:i', strtotime((string) $r['created_at']))) ?></td>
          <td style="text-align:right;white-space:nowrap">
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
        <a class="btn <?= $p === $page ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="leads.php?flow=<?= $fid ?><?= $grade ? '&grade=' . $grade : '' ?>&page=<?= $p ?>"><?= $p ?></a>
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

<?php layout_footer(); ?>
