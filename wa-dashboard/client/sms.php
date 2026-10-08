<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/sms_forms.php';
require_once __DIR__ . '/../includes/charts.php';
require_once __DIR__ . '/../includes/crm.php';
require_once __DIR__ . '/../includes/crm_list.php';
require_once __DIR__ . '/../includes/crm_automation.php';
require_once __DIR__ . '/../includes/crm_integrations.php';
require_once __DIR__ . '/../includes/import.php';

/**
 * SMS: send to a list, CRM leads, pasted numbers or a spreadsheet; SMS campaigns; the log of every
 * SMS; the account's own provider and opt-outs; and API keys with the developer guide.
 */
$cid = (int) $CLIENT['id'];
if (!sms_ready()) { http_response_code(503); exit('SMS is being installed — try again shortly.'); }
$tab = in_array($_GET['tab'] ?? '', ['send', 'campaigns', 'log', 'settings', 'api'], true) ? (string) $_GET['tab'] : 'send';
$admin = is_client_admin();
$write = can_write();
$crmOn = function_exists('crm_enabled') && crm_enabled($CLIENT) && can_use('crm');
$gw = sms_client_gateway($CLIENT);
$senders = sms_client_senders($CLIENT);
$rate = sms_rate($CLIENT);
$me = current_user_full() ?: [];

/** The people a Send form points at: [recipients, how many numbers were looked at]. */
$audience = function (array $in, ?array $files = null) use ($cid, $CLIENT, $crmOn): array {
    $src = (string) ($in['src'] ?? 'list');
    $rows = [];
    if ($src === 'list') {
        foreach (db_all("SELECT c.id FROM contact_list_members m JOIN contacts c ON c.id=m.contact_id JOIN contact_lists l ON l.id=m.list_id
                          WHERE l.id=? AND l.client_id=? AND c.phone_e164 IS NOT NULL AND c.deleted_at IS NULL", [(int) ($in['list_id'] ?? 0), $cid]) as $r) $rows[] = ['contact_id' => (int) $r['id']];
    } elseif ($src === 'crm' && $crmOn) {
        [$w, $p] = crm_list_where($cid, crm_list_filters($in));
        foreach (db_all("SELECT c.id FROM contacts c JOIN crm_stages s ON s.id=c.stage_id WHERE $w AND c.phone_e164 IS NOT NULL AND c.deleted_at IS NULL LIMIT 50000", $p) as $r) $rows[] = ['contact_id' => (int) $r['id']];
    } elseif ($src === 'numbers') {
        foreach (preg_split('/[\s,;]+/', (string) ($in['numbers'] ?? '')) as $n) if (trim($n) !== '') $rows[] = ['phone' => trim($n)];
    } elseif ($src === 'file' && $files && !empty($files['tmp_name']) && (int) $files['error'] === UPLOAD_ERR_OK) {
        $r = import_read((string) $files['tmp_name'], (string) $files['name']);
        if ($r['ok']) {
            $col = 0;
            foreach ($r['header'] as $i => $h) if (preg_match('/phone|mobile|number|رقم|موبايل|هاتف/iu', (string) $h)) { $col = $i; break; }
            foreach ($r['rows'] as $row) if (trim((string) ($row[$col] ?? '')) !== '') $rows[] = ['phone' => trim((string) $row[$col])];
        }
    }
    // A salesperson reaches only the leads they may see.
    if (is_sales()) {
        $ok = []; foreach ($rows as $r) if (!empty($r['contact_id'])) $ok[] = $r;
        $ids = array_column($ok, 'contact_id');
        [$scope, $sp] = crm_scope('c');
        $allowed = $ids ? array_flip(array_column(db_all("SELECT c.id FROM contacts c WHERE c.id IN (" . implode(',', array_map('intval', $ids)) . ")$scope", $sp), 'id')) : [];
        $rows = array_values(array_filter($ok, fn($r) => isset($allowed[$r['contact_id']])));
    }
    return $rows;
};

/* ── AJAX: how many people, and an estimate of the cost ── */
if (isset($_GET['count'])) {
    $rows = $audience($_GET);
    // Not counted: people who opted out of SMS (they are skipped when sending).
    $cids = array_values(array_filter(array_map(fn($r) => (int) ($r['contact_id'] ?? 0), $rows)));
    if ($cids) {
        $out = array_flip(array_column(db_all("SELECT id FROM contacts WHERE client_id=? AND sms_opt_out_at IS NOT NULL AND id IN (" . implode(',', $cids) . ")", [$cid]), 'id'));
        $rows = array_values(array_filter($rows, fn($r) => empty($r['contact_id']) || !isset($out[(int) $r['contact_id']])));
    }
    $p = sms_parts((string) ($_GET['text'] ?? ''));
    json_out(['people' => count($rows), 'parts' => $p['parts'], 'credits' => count($rows) * $p['parts'] * $rate]);
}

/* ── Log export ── */
$logWhere = function () use ($cid): array {
    $w = "m.client_id=?"; $p = [$cid];
    foreach (['status' => 'm.status', 'source' => 'm.source'] as $k => $col) if (($v = (string) ($_GET[$k] ?? '')) !== '') { $w .= " AND $col=?"; $p[] = $v; }
    if (($v = preg_replace('/\D+/', '', (string) ($_GET['num'] ?? ''))) !== '') { $w .= " AND m.to_e164 LIKE ?"; $p[] = '%' . $v . '%'; }
    if (($v = trim((string) ($_GET['ref'] ?? ''))) !== '') { $w .= " AND m.reference=?"; $p[] = $v; }
    if (!empty($_GET['from']) && strtotime((string) $_GET['from'])) { $w .= " AND m.created_at >= ?"; $p[] = date('Y-m-d 00:00:00', strtotime((string) $_GET['from'])); }
    if (!empty($_GET['to']) && strtotime((string) $_GET['to'])) { $w .= " AND m.created_at <= ?"; $p[] = date('Y-m-d 23:59:59', strtotime((string) $_GET['to'])); }
    if (is_sales()) { [$s, $sp] = crm_scope('c'); $w .= " AND m.contact_id IS NOT NULL AND EXISTS (SELECT 1 FROM contacts c WHERE c.id=m.contact_id$s)"; $p = array_merge($p, $sp); }
    return [$w, $p];
};
if ($tab === 'log' && ($_GET['export'] ?? '') !== '') {
    [$w, $p] = $logWhere();
    $rows = db_all("SELECT m.*, c.name FROM sms_messages m LEFT JOIN contacts c ON c.id=m.contact_id WHERE $w ORDER BY m.id DESC LIMIT 100000", $p);
    $head = ['Date', 'Number', 'Name', 'Sender', 'Message', 'Parts', 'Credits', 'Status', 'Error', 'Source', 'Reference', 'Sent', 'Delivered'];
    $mask = function_exists('crm_phone_show') ? 'crm_phone_show' : fn($x) => $x;
    $out = array_map(fn($r) => [(string) $r['created_at'], '+' . $mask((string) $r['to_e164']), (string) $r['name'], (string) $r['sender'], (string) $r['body'], (string) $r['parts'],
                                (string) $r['credits'], (string) $r['status'], (string) $r['error_title'], (string) $r['source'], (string) $r['reference'], (string) $r['sent_at'], (string) $r['delivered_at']], $rows);
    if (($_GET['export'] ?? '') === 'xlsx' && function_exists('crm_xlsx')) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="sms-log-' . date('Y-m-d') . '.xlsx"');
        echo crm_xlsx($head, $out, 'SMS'); exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sms-log-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF" . implode(',', array_map('csv_cell', $head)) . "\n";
    foreach ($out as $l) echo implode(',', array_map('csv_cell', $l)) . "\n";
    exit;
}

/* ── Posts ── */
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string) ($_POST['action'] ?? '');
    if (isset($_POST['gw_action'])) {
        if (!$admin || ($CLIENT['sms_mode'] ?? 'platform') !== 'own') { flash('Only an admin of an account allowed its own provider can change this.', 'error'); redirect('sms.php?tab=settings'); }
        $r = sms_gateway_handle($cid, $_POST + ['is_default' => 1]);
        if ($r) { flash($r[0], $r[1]); redirect('sms.php?tab=settings#own'); }
    }
    if ($act === 'send') {
        if (!$write) { flash('Your role can view SMS but not send.', 'error'); redirect('sms.php'); }
        $text = trim((string) ($_POST['text'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? '')) ?: 'SMS ' . date('j M H:i');
        $sched = null;
        if (($_POST['when'] ?? '') === 'later') {
            $ts = strtotime((string) ($_POST['scheduled_at'] ?? ''));
            if (!$ts || $ts < time() - 60) $err = 'Choose a future date and time.';
            else $sched = date('Y-m-d H:i:s', $ts);
        }
        if ($text === '') $err = $err ?: 'Write the message.';
        if ($err === '') {
            $rows = $audience($_POST, $_FILES['file'] ?? null);
            if (!$rows) $err = 'Nobody to send to — check the audience.';
            else {
                [$k, $q] = sms_campaign_create($CLIENT, $name, $text, $rows, ['sender' => (string) ($_POST['sender'] ?? ''), 'scheduled_at' => $sched,
                                                                              'user_id' => (int) ($me['id'] ?? 0) ?: null, 'list_id' => ($_POST['src'] ?? '') === 'list' ? (int) ($_POST['list_id'] ?? 0) : null]);
                if (!$k) $err = (string) $q['error'];
                else {
                    if (!$sched) trigger_worker();
                    flash(($sched ? 'Scheduled: ' : 'Sending: ') . number_format(count($q['queued'])) . ' SMS, ' . number_format($q['credits']) . ' credits'
                          . ($q['skipped'] ? ' · ' . count($q['skipped']) . ' skipped (invalid, duplicate or opted out)' : '') . '.');
                    redirect('report.php?id=' . $k);
                }
            }
        }
    }
    if ($act === 'optout' && $write) {
        $n = 0;
        foreach (preg_split('/[\s,;]+/', (string) ($_POST['numbers'] ?? '')) as $raw) {
            $ph = normalize_phone($raw, (string) ($CLIENT['default_country'] ?? ''));
            if ($ph !== '' && sms_opt_out($cid, $ph, ($_POST['how'] ?? 'out') === 'out')) $n++;
        }
        flash(number_format($n) . ' number(s) ' . (($_POST['how'] ?? 'out') === 'out' ? 'will not receive SMS.' : 'can receive SMS again.'));
        redirect('sms.php?tab=settings#optout');
    }
    if ($act === 'key_new' && $admin) {
        $key = crm_api_key_create($cid, (string) ($_POST['key_name'] ?? 'SMS key'), 'write', (string) ($_POST['key_system'] ?? ''), (int) ($me['id'] ?? 0) ?: null);
        db_run("UPDATE crm_api_keys SET scopes=? WHERE key_hash=?", [!empty($_POST['key_crm']) ? 'crm,sms' : 'sms', hash('sha256', $key)]);
        $_SESSION['sms_new_key'] = $key;
        redirect('sms.php?tab=api#keys');
    }
    if ($act === 'key_revoke' && $admin) {
        db_run("UPDATE crm_api_keys SET revoked_at=NOW() WHERE id=? AND client_id=?", [(int) ($_POST['key_id'] ?? 0), $cid]);
        flash('Key revoked — it stops working now.');
        redirect('sms.php?tab=api#keys');
    }
    if ($act === 'secret_renew' && $admin) {
        sms_callback_secret($cid, true);
        flash('A new callback secret was made. Update it in your system.');
        redirect('sms.php?tab=api#callbacks');
    }
}

client_header('SMS', 'sms', $CLIENT);
page_head('SMS');
?>
<nav class="dv-tabs" aria-label="SMS sections">
  <?php foreach (['send' => 'Send', 'campaigns' => 'Campaigns', 'log' => 'Log'] + ($admin ? ['settings' => 'Settings', 'api' => 'API'] : []) as $k => $l): ?>
    <a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $l ?></a>
  <?php endforeach; ?>
</nav>
<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
<?php if (!$gw): ?>
  <div class="alert info">SMS is not connected for this account yet. <?= ($CLIENT['sms_mode'] ?? '') === 'own' && $admin ? 'Add your SMS provider in <a href="?tab=settings">Settings</a>.' : 'Ask ' . e(BRAND_PARENT) . ' to connect an SMS provider.' ?></div>
<?php endif; ?>

<?php if ($tab === 'send'):
  $lists = db_all("SELECT l.id, l.name, (SELECT COUNT(*) FROM contact_list_members m WHERE m.list_id=l.id) n FROM contact_lists l WHERE l.client_id=? ORDER BY l.name", [$cid]);
  $stages = $crmOn ? crm_stages($cid) : [];
  $preSrc = (string) ($_GET['src'] ?? ($_POST['src'] ?? ($lists ? 'list' : ($crmOn ? 'crm' : 'numbers'))));
  $crmPre = $crmOn ? crm_list_active(crm_list_filters($_GET + $_POST)) : [];
  $tokens = function_exists('crm_tpl_tokens') ? array_slice(crm_tpl_tokens($cid), 0, 40, true) : ['name' => 'Name']; ?>
  <form method="post" enctype="multipart/form-data" class="card" id="sms-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="send">
    <h2>Who receives it</h2>
    <div class="sms-src" role="radiogroup">
      <?php foreach (['list' => 'A list', 'crm' => 'CRM leads', 'numbers' => 'Paste numbers', 'file' => 'Upload a file'] as $k => $l): if ($k === 'crm' && !$crmOn) continue; ?>
        <label><input type="radio" name="src" value="<?= $k ?>" <?= $preSrc === $k ? 'checked' : '' ?>> <?= $l ?></label>
      <?php endforeach; ?>
    </div>
    <div class="sms-pane" data-src="list"><div class="field" style="max-width:420px"><span class="lbl">List</span><select name="list_id">
      <?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) ($_POST['list_id'] ?? 0) === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?> (<?= (int) $l['n'] ?>)</option><?php endforeach; ?>
      <?php if (!$lists): ?><option value="0">No lists yet</option><?php endif; ?></select></div></div>
    <?php if ($crmOn): ?>
    <div class="sms-pane" data-src="crm">
      <?php if ($crmPre): ?><p class="hint">Using the filters from the leads list: <?= e(implode(' · ', array_map(fn($k, $v) => $k . ' = ' . $v, array_keys($crmPre), $crmPre))) ?>
        <?php foreach ($crmPre as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?></p>
      <?php else: ?>
      <div class="grid3">
        <div class="field"><span class="lbl">Stage</span><select name="stage"><option value="">Any stage</option>
          <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><span class="lbl">Source</span><select name="source"><option value="">Any source</option>
          <?php foreach (db_all("SELECT DISTINCT source FROM contacts WHERE client_id=? AND stage_id IS NOT NULL AND source IS NOT NULL", [$cid]) as $s): ?><option value="<?= e($s['source']) ?>"><?= e(crm_source_label((string) $s['source'])) ?></option><?php endforeach; ?></select></div>
        <?php if (!is_sales()): ?><div class="field"><span class="lbl">Salesperson</span><select name="owner"><option value="">Anyone</option>
          <?php foreach (crm_assignable_users($cid) as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      </div>
      <p class="hint">More filters: open <a href="crm.php?view=table">the leads list</a>, filter it, and choose <strong>Send SMS</strong> there.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="sms-pane" data-src="numbers"><div class="field"><span class="lbl">Numbers <span class="text-muted">(one per line or separated by commas)</span></span>
      <textarea name="numbers" rows="4" placeholder="01001234567&#10;+201112223334"><?= e((string) ($_POST['numbers'] ?? '')) ?></textarea></div></div>
    <div class="sms-pane" data-src="file"><div class="field"><span class="lbl">CSV or Excel file <span class="text-muted">(the column named phone / mobile / number is used, else the first)</span></span>
      <input type="file" name="file" accept=".csv,.xlsx"></div></div>

    <h2 style="margin-top:18px">Message</h2>
    <div class="grid2">
      <div class="field"><span class="lbl">Campaign name</span><input type="text" name="name" maxlength="190" value="<?= e((string) ($_POST['name'] ?? '')) ?>" placeholder="October offer — SMS"></div>
      <div class="field"><span class="lbl">Sender</span><select name="sender">
        <?php foreach ($senders as $s): ?><option value="<?= e($s) ?>" <?= ($_POST['sender'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
        <?php if (!$senders): ?><option value="">The provider's default</option><?php endif; ?></select></div>
    </div>
    <div class="field"><span class="lbl">Text</span>
      <textarea name="text" id="sms-text" rows="5" maxlength="1530" required placeholder="Hi {{first_name}}, …"><?= e((string) ($_POST['text'] ?? '')) ?></textarea>
      <div class="sms-counter" aria-live="polite"><span><b id="sms-chars">0</b> characters</span><span><b id="sms-parts">1</b> part(s)</span><span id="sms-enc">English</span><span><b id="sms-left">160</b> left in this part</span></div>
      <div class="sw-fields" style="margin-top:6px"><?php foreach ($tokens as $k => $l): if ($k === 'text') continue; ?><span class="crm-chip" data-tok="{{<?= e($k) ?>}}" title="<?= e($l) ?>"><?= e(strtolower(preg_replace(['/^Lead: /', '/^(Salesperson|Visit|Event): /', '/^Custom field: /'], ['', '$1 ', ''], $l))) ?></span><?php endforeach; ?></div>
      <span class="hint">Variables are filled per person (names, salesperson, custom fields). A filled-in name can push a message into a second part.</span>
    </div>
    <div class="field"><span class="lbl">When</span><div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
      <label class="mod-opt"><input type="radio" name="when" value="now" checked> Send now</label>
      <label class="mod-opt"><input type="radio" name="when" value="later"> Schedule</label>
      <input type="datetime-local" name="scheduled_at" style="width:auto"></div></div>
    <div class="card" style="background:var(--surface-2);margin:10px 0" id="sms-est">
      <strong id="sms-people">…</strong> recipients · about <strong id="sms-credits">…</strong> credits <span class="text-muted">(<?= $rate ?> credit<?= $rate === 1 ? '' : 's' ?> per part; balance <?= number_format((int) $CLIENT['credits_balance']) ?>)</span>
      <div class="hint">Opted-out and duplicate numbers are skipped; failed messages are refunded.</div>
    </div>
    <button class="btn btn-primary" <?= !$gw || !$write ? 'disabled' : '' ?>>Send SMS</button>
  </form>
  <script>
  (function () {
    var f = document.getElementById('sms-form'), t = document.getElementById('sms-text');
    var gsm = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà", ext = "^{}\\[~]|€\f";
    function parts(s) {
      var len = 0, isG = true, ch = Array.from(s);
      for (var i = 0; i < ch.length; i++) { if (gsm.indexOf(ch[i]) >= 0) len++; else if (ext.indexOf(ch[i]) >= 0) len += 2; else { isG = false; break; } }
      if (!isG) { len = 0; ch.forEach(function (c) { len += c.length > 1 ? 2 : 1; }); }
      var one = isG ? 160 : 70, many = isG ? 153 : 67, p = len <= one ? 1 : Math.ceil(len / many), per = p === 1 ? one : many;
      return {len: len, parts: p, gsm: isG, left: p * per - len};
    }
    var timer;
    function sync() {
      var src = (f.querySelector('input[name=src]:checked') || {}).value;
      f.querySelectorAll('.sms-pane').forEach(function (p) { p.hidden = p.dataset.src !== src; });
      var r = parts(t.value);
      document.getElementById('sms-chars').textContent = r.len; document.getElementById('sms-parts').textContent = r.parts;
      document.getElementById('sms-enc').textContent = r.gsm ? 'English (GSM)' : 'Arabic / unicode'; document.getElementById('sms-left').textContent = r.left;
      clearTimeout(timer);
      timer = setTimeout(function () {
        if (src === 'file') { document.getElementById('sms-people').textContent = 'From the file'; document.getElementById('sms-credits').textContent = '—'; return; }
        var q = new URLSearchParams(new FormData(f)); q.delete('csrf_token'); q.delete('file'); q.set('count', 1);
        fetch('sms.php?' + q).then(function (x) { return x.json(); }).then(function (d) {
          document.getElementById('sms-people').textContent = d.people.toLocaleString(); document.getElementById('sms-credits').textContent = d.credits.toLocaleString();
        });
      }, 300);
    }
    f.addEventListener('input', sync); f.addEventListener('change', sync);
    f.querySelectorAll('[data-tok]').forEach(function (c) { c.addEventListener('click', function () {
      var s = t.selectionStart || t.value.length; t.value = t.value.slice(0, s) + c.dataset.tok + t.value.slice(s); t.focus(); sync(); }); });
    sync();
  })();
  </script>

<?php elseif ($tab === 'campaigns'):
  $camps = db_all("SELECT * FROM campaigns WHERE client_id=? AND channel='sms' ORDER BY id DESC LIMIT 200", [$cid]); ?>
  <div class="card card-flush"><div class="table-wrap"><table class="data">
    <thead><tr><th>Campaign</th><th>Status</th><th>Results</th><th class="num">Sent</th><th class="num">Delivered</th><th class="num">Failed</th><th>When</th><th></th></tr></thead>
    <tbody>
    <?php if (!$camps): ?><tr><td colspan="8"><div class="empty">No SMS campaigns yet. <a href="?tab=send">Send your first one</a>.</div></td></tr><?php endif; ?>
    <?php foreach ($camps as $c): ?>
      <tr><td><strong><?= e((string) $c['name']) ?></strong><br><small class="text-muted"><?= e(mb_strimwidth((string) $c['body_text'], 0, 70, '…')) ?></small></td>
        <td><?= status_pill((string) $c['status']) ?></td>
        <td style="min-width:130px"><?= viz_share([['Delivered', (int) $c['delivered_count'], 3], ['Sent', max(0, (int) $c['sent_count'] - (int) $c['delivered_count']), 1],
              ['Failed', (int) $c['failed_count'], 'bad'], ['Waiting', max(0, (int) $c['total_count'] - (int) $c['sent_count'] - (int) $c['failed_count']), 7]], ['legend' => false]) ?></td>
        <td class="num"><?= (int) $c['sent_count'] ?> / <?= (int) $c['total_count'] ?></td><td class="num"><?= (int) $c['delivered_count'] ?></td>
        <td class="num"><?= (int) $c['failed_count'] ? '<span class="pill red">' . (int) $c['failed_count'] . '</span>' : '0' ?></td>
        <td class="text-muted"><?= e(date('j M, H:i', strtotime((string) ($c['scheduled_at'] ?: $c['created_at'])))) ?></td>
        <td style="text-align:end"><a class="btn btn-ghost btn-sm" href="report.php?id=<?= (int) $c['id'] ?>">Report</a></td></tr>
    <?php endforeach; ?></tbody></table></div></div>

<?php elseif ($tab === 'log'):
  [$w, $p] = $logWhere();
  $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 100;
  $total = (int) db_val("SELECT COUNT(*) FROM sms_messages m WHERE $w", $p);
  $rows = db_all("SELECT m.*, c.name FROM sms_messages m LEFT JOIN contacts c ON c.id=m.contact_id WHERE $w ORDER BY m.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
  $sum = db_row("SELECT SUM(status IN ('sent','delivered')) ok, SUM(status='delivered') dl, SUM(status IN ('failed','undelivered')) bad, SUM(status IN ('queued','sending')) wait, SUM(IF(status IN ('sent','delivered'), credits, 0)) cr FROM sms_messages m WHERE $w", $p) ?: [];
  $q = fn(array $set) => '?' . http_build_query(array_filter($set + ['tab' => 'log'] + array_intersect_key($_GET, array_flip(['status', 'source', 'num', 'ref', 'from', 'to'])), 'strlen')); ?>
  <div class="viz-kpis">
    <?= viz_kpi('Sent', (int) ($sum['ok'] ?? 0)) ?><?= viz_kpi('Delivered', (int) ($sum['dl'] ?? 0), null, ['sub' => 'where the provider reports it']) ?>
    <?= viz_kpi('Failed', (int) ($sum['bad'] ?? 0)) ?><?= viz_kpi('Waiting', (int) ($sum['wait'] ?? 0)) ?><?= viz_kpi('Credits', (int) ($sum['cr'] ?? 0)) ?>
  </div>
  <div class="card card-flush">
    <form method="get" style="padding:12px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:end">
      <input type="hidden" name="tab" value="log">
      <label class="field" style="margin:0"><span class="lbl">Status</span><select name="status"><option value="">Any</option>
        <?php foreach (['queued' => 'Waiting', 'sent' => 'Sent', 'delivered' => 'Delivered', 'failed' => 'Failed', 'undelivered' => 'Not delivered', 'skipped' => 'Skipped'] as $k => $l): ?><option value="<?= $k ?>" <?= ($_GET['status'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label class="field" style="margin:0"><span class="lbl">Sent by</span><select name="source"><option value="">Anything</option>
        <?php foreach (['campaign' => 'Campaigns', 'api' => 'API', 'crm' => 'CRM', 'automation' => 'Automations', 'alert' => 'Alerts to the team', 'manual' => 'Typed'] as $k => $l): ?><option value="<?= $k ?>" <?= ($_GET['source'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label class="field" style="margin:0"><span class="lbl">Number</span><input type="search" name="num" value="<?= e((string) ($_GET['num'] ?? '')) ?>" style="width:150px"></label>
      <label class="field" style="margin:0"><span class="lbl">Reference</span><input type="search" name="ref" value="<?= e((string) ($_GET['ref'] ?? '')) ?>" style="width:130px"></label>
      <label class="field" style="margin:0"><span class="lbl">From</span><input type="date" name="from" value="<?= e((string) ($_GET['from'] ?? '')) ?>"></label>
      <label class="field" style="margin:0"><span class="lbl">To</span><input type="date" name="to" value="<?= e((string) ($_GET['to'] ?? '')) ?>"></label>
      <button class="btn btn-ghost btn-sm">Show</button>
      <span style="margin-inline-start:auto;display:flex;gap:6px"><a class="btn btn-ghost btn-sm" href="<?= e($q(['export' => 'csv'])) ?>">CSV</a><a class="btn btn-ghost btn-sm" href="<?= e($q(['export' => 'xlsx'])) ?>">Excel</a></span>
    </form>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>When</th><th>To</th><th>Message</th><th class="num">Parts</th><th>Status</th><th>Sent by</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6"><div class="empty">No SMS yet.</div></td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr><td class="text-muted" style="white-space:nowrap"><?= e(date('j M, H:i', strtotime((string) $r['created_at']))) ?></td>
          <td><span class="mono">+<?= e(function_exists('crm_phone_show') ? crm_phone_show((string) $r['to_e164']) : (string) $r['to_e164']) ?></span><?= $r['name'] ? '<br><small class="text-muted">' . e((string) $r['name']) . '</small>' : '' ?></td>
          <td style="max-width:360px;font-size:12.5px"><?= e(mb_strimwidth((string) $r['body'], 0, 160, '…')) ?><?= $r['sender'] ? '<br><small class="text-muted">from ' . e((string) $r['sender']) . '</small>' : '' ?></td>
          <td class="num"><?= (int) $r['parts'] ?></td>
          <td><?= msg_status_pill(['queued' => 'sending', 'sending' => 'sending', 'undelivered' => 'failed', 'skipped' => 'failed'][$r['status']] ?? (string) $r['status']) ?>
            <?= $r['error_title'] ? '<br><small class="text-muted">' . e((string) $r['error_title']) . '</small>' : '' ?><?= $r['scheduled_at'] && $r['status'] === 'queued' ? '<br><small class="text-muted">at ' . e(date('j M H:i', strtotime((string) $r['scheduled_at']))) . '</small>' : '' ?></td>
          <td class="text-muted" style="font-size:12.5px"><?= e(['campaign' => 'Campaign', 'api' => 'API', 'crm' => 'CRM', 'automation' => 'Automation', 'alert' => 'Team alert', 'test' => 'Test', 'manual' => 'Typed'][$r['source']] ?? $r['source']) ?><?= $r['reference'] ? '<br>ref ' . e((string) $r['reference']) : '' ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php if ($total > $per): ?><div style="padding:12px;display:flex;gap:6px;justify-content:center">
      <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($q(['page' => $page - 1])) ?>">← Newer</a><?php endif; ?>
      <span class="text-muted" style="font-size:12.5px;align-self:center"><?= number_format($total) ?> SMS</span>
      <?php if ($page * $per < $total): ?><a class="btn btn-ghost btn-sm" href="<?= e($q(['page' => $page + 1])) ?>">Older →</a><?php endif; ?></div><?php endif; ?>
  </div>

<?php elseif ($tab === 'settings' && $admin):
  $ownGw = sms_gateway_load(db_row("SELECT * FROM sms_gateways WHERE client_id=? ORDER BY id LIMIT 1", [$cid]) ?: null);
  $outs = db_all("SELECT phone_e164, name, sms_opt_out_at FROM contacts WHERE client_id=? AND sms_opt_out_at IS NOT NULL ORDER BY sms_opt_out_at DESC LIMIT 200", [$cid]); ?>
  <div class="card">
    <h2>Sending</h2>
    <p>Sender names you can use: <?= $senders ? implode(' ', array_map(fn($s) => '<span class="pill gray">' . e($s) . '</span>', $senders)) : '<span class="text-muted">the provider\'s default</span>' ?></p>
    <p class="text-muted" style="font-size:13px">Each SMS part (160 English or 70 Arabic characters; 153 / 67 when a message is split) costs <strong><?= $rate ?></strong> credit<?= $rate === 1 ? '' : 's' ?>.
      <?= ($CLIENT['sms_mode'] ?? 'platform') === 'own' ? 'You send through your own provider account (below).' : 'Sent through ' . e(BRAND_PARENT) . '\'s SMS provider. To add a sender name, ask us — it must be registered with the provider.' ?></p>
  </div>
  <?php if (($CLIENT['sms_mode'] ?? 'platform') === 'own'): ?>
    <div class="card" id="own">
      <h2>Your SMS provider</h2>
      <?= $ownGw ? sms_gateway_card($ownGw, 'sms.php?tab=settings&edit=1#own-form') : '<p class="text-muted">Connect your SMS provider account: SMS Misr, Victory Link, Twilio, mShastra or any provider with an HTTP API.</p>' ?>
      <?php if (!$ownGw || isset($_GET['edit'])): ?><div id="own-form" style="margin-top:14px"><?= sms_gateway_form($ownGw, false) ?></div><?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="card" id="optout">
    <h2>Do not send SMS to</h2>
    <p class="text-muted" style="font-size:13px">People who asked not to get SMS. Campaigns, the API, the CRM and automations all skip them. WhatsApp is not affected.</p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start"><?= csrf_field() ?><input type="hidden" name="action" value="optout">
      <textarea name="numbers" rows="2" placeholder="Numbers, one per line" style="flex:1 1 260px"></textarea>
      <select name="how" style="width:auto"><option value="out">Stop SMS to them</option><option value="in">Allow SMS again</option></select>
      <button class="btn btn-ghost btn-sm">Save</button></form>
    <?php if ($outs): ?><div class="table-wrap" style="margin-top:10px"><table class="data"><thead><tr><th>Number</th><th>Name</th><th>Since</th></tr></thead><tbody>
      <?php foreach ($outs as $o): ?><tr><td class="mono">+<?= e((string) $o['phone_e164']) ?></td><td><?= e((string) $o['name']) ?></td><td class="text-muted"><?= e(date('j M Y', strtotime((string) $o['sms_opt_out_at']))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>

<?php elseif ($tab === 'api' && $admin):
  $keys = db_all("SELECT * FROM crm_api_keys WHERE client_id=? AND revoked_at IS NULL AND FIND_IN_SET('sms', scopes) ORDER BY id DESC", [$cid]);
  $newKey = $_SESSION['sms_new_key'] ?? null; unset($_SESSION['sms_new_key']);
  $base = rtrim(app_base_url(), '/') . '/api.php/v1'; ?>
  <div class="card" id="keys">
    <h2>API keys</h2>
    <?php if ($newKey): ?><div class="alert success">Your new key — copy it now, it is shown only once:<br><code style="font-size:13px;user-select:all"><?= e($newKey) ?></code></div><?php endif; ?>
    <div class="table-wrap"><table class="data"><thead><tr><th>Name</th><th>Key</th><th>Can use</th><th>Last used</th><th></th></tr></thead><tbody>
      <?php if (!$keys): ?><tr><td colspan="5"><div class="empty">No SMS keys yet.</div></td></tr><?php endif; ?>
      <?php foreach ($keys as $k): ?><tr><td><?= e((string) $k['name']) ?><?= $k['system'] ? '<br><small class="text-muted">' . e((string) $k['system']) . '</small>' : '' ?></td><td class="mono"><?= e((string) $k['prefix']) ?>_…</td>
        <td><?= e(str_replace(['crm', 'sms', ','], ['CRM', 'SMS', ' + '], (string) $k['scopes'])) ?></td><td class="text-muted"><?= $k['last_used_at'] ? e(date('j M, H:i', strtotime((string) $k['last_used_at']))) : 'never' ?></td>
        <td style="text-align:end"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="key_revoke"><input type="hidden" name="key_id" value="<?= (int) $k['id'] ?>"><button class="btn btn-ghost btn-sm" onclick="return confirm('Revoke this key? Systems using it stop working.')">Revoke</button></form></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="key_new">
      <label class="field" style="margin:0"><span class="lbl">Name</span><input type="text" name="key_name" placeholder="Website" required></label>
      <label class="field" style="margin:0"><span class="lbl">System (optional)</span><input type="text" name="key_system" placeholder="e.g. Odoo"></label>
      <?php if ($crmOn): ?><label class="mod-opt" style="margin-bottom:8px"><input type="checkbox" name="key_crm" value="1"> also the CRM API</label><?php endif; ?>
      <button class="btn btn-primary btn-sm">Make a key</button></form>
  </div>
  <div class="card" id="callbacks">
    <h2>Delivery callbacks</h2>
    <p class="text-muted" style="font-size:13px">Send <code>callback_url</code> with a message and we POST its status there when it changes (sent, delivered, failed), signed:
      <code>X-Revenect-Signature: sha256=HMAC_SHA256(timestamp + "." + body, secret)</code> with <code>X-Revenect-Timestamp</code>. Your outgoing webhooks in CRM → Integrations can also subscribe to <code>sms.status</code>.</p>
    <details><summary class="btn btn-ghost btn-sm">Show the signing secret</summary><code style="user-select:all"><?= e(sms_callback_secret($cid)) ?></code>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="secret_renew"><button class="btn-link" onclick="return confirm('Make a new secret? The old one stops working.')">Make a new one</button></form></details>
  </div>
  <div class="card">
    <h2>Developer guide</h2>
    <p class="text-muted" style="font-size:13px">Send <code>Authorization: Bearer YOUR_KEY</code> (or <code>X-Api-Key</code>). JSON in, JSON out. Up to <?= (int) config('api_rate_per_minute', 120) ?> requests a minute per key, up to 1,000 numbers per request.</p>
    <h3 class="viz-sub">Send</h3>
    <pre class="sms-code">curl -X POST <?= e($base) ?>/sms \
  -H "Authorization: Bearer YOUR_KEY" -H "Content-Type: application/json" \
  -d '{"to": ["+201001234567", "01112223334"], "text": "Your code is 4821", "sender": "<?= e($senders[0] ?? 'YourBrand') ?>",
       "reference": "order-1001", "callback_url": "https://your-site.com/sms-status"}'</pre>
    <p class="text-muted" style="font-size:12.5px">Optional: <code>schedule_at</code> (ISO date-time). The same <code>reference</code> within 24 hours is not sent twice — you get the first answer back.</p>
    <pre class="sms-code">{"data": {"accepted": 2, "credits": 2, "messages": [{"id": 9182, "to": "+201001234567", "status": "queued", "parts": 1, "credits": 1}, …], "skipped": []}}</pre>
    <h3 class="viz-sub">Check, list, balance, senders</h3>
    <pre class="sms-code">GET <?= e($base) ?>/sms/9182
GET <?= e($base) ?>/sms?reference=order-1001&amp;status=failed&amp;since=2026-10-01&amp;page=1
GET <?= e($base) ?>/sms/balance
GET <?= e($base) ?>/sms/senders</pre>
    <h3 class="viz-sub">PHP</h3>
    <pre class="sms-code">$ch = curl_init('<?= e($base) ?>/sms');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Authorization: Bearer YOUR_KEY', 'Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode(['to' => '+201001234567', 'text' => 'Hello from our site'])]);
$answer = json_decode(curl_exec($ch), true);</pre>
    <h3 class="viz-sub">JavaScript (server side)</h3>
    <pre class="sms-code">await fetch('<?= e($base) ?>/sms', {method: 'POST',
  headers: {'Authorization': 'Bearer YOUR_KEY', 'Content-Type': 'application/json'},
  body: JSON.stringify({to: '+201001234567', text: 'Hello'})});</pre>
    <p class="text-muted" style="font-size:12.5px">Statuses: <code>queued</code> → <code>sent</code> → <code>delivered</code> (when the provider reports it), or <code>failed</code> / <code>undelivered</code> with an <code>error</code>. Failed messages are refunded.</p>
  </div>
<?php endif; ?>
<?php layout_footer(); ?>
