<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm_integrations.php';

/**
 * Connect the CRM to another CRM: send leads out as they change (webhooks), and take leads in —
 * through the API with a key, or through an incoming URL that maps the other system's fields.
 * Admins only: keys and URLs here open the account's leads to another system.
 */
$cid = (int) $CLIENT['id'];
$me = (int) $ME['id'];
if (!is_client_admin()) {
    client_header('Integrations', 'crm', $CLIENT);
    echo '<div class="card"><p>Only an Admin can manage integrations.</p></div>'; layout_footer(); exit;
}
$ready = crm_int_ready();
$events = crm_int_events();
$fresh = $_SESSION['int_fresh'] ?? null; unset($_SESSION['int_fresh']);       // a key or secret shown once
$back = fn(string $h) => redirect('crm_integrations.php' . $h);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    // ── Send ──
    if ($a === 'hook_add') {
        $url = trim((string) ($_POST['url'] ?? ''));
        $ev = array_values(array_intersect(array_keys($events), (array) ($_POST['events'] ?? [])));
        if (($e = crm_hook_url_ok($url)) !== '') { flash($e, 'error'); $back('#send'); }
        if (!$ev) { flash('Choose at least one event to send.', 'error'); $back('#send'); }
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        db_insert("INSERT INTO crm_webhooks (client_id,name,url,secret_enc,events,skip_api,created_by,created_at) VALUES (?,?,?,?,?,?,?,NOW())",
                  [$cid, mb_substr(trim((string) ($_POST['name'] ?? '')) ?: (string) parse_url($url, PHP_URL_HOST), 0, 80), mb_substr($url, 0, 500),
                   encrypt_secret($secret), count($ev) === count($events) ? '*' : implode(',', $ev), !empty($_POST['skip_api']) ? 1 : 0, $me]);
        $_SESSION['int_fresh'] = ['kind' => 'secret', 'value' => $secret];
        flash('Webhook added. Copy its signing secret now.'); $back('#send');
    }
    if ($a === 'hook_edit') {
        $ev = array_values(array_intersect(array_keys($events), (array) ($_POST['events'] ?? [])));
        if ($ev) db_run("UPDATE crm_webhooks SET events=?, skip_api=? WHERE id=? AND client_id=?",
                        [count($ev) === count($events) ? '*' : implode(',', $ev), !empty($_POST['skip_api']) ? 1 : 0, $id, $cid]);
        flash('Saved.'); $back('#send');
    }
    if ($a === 'hook_toggle') { db_run("UPDATE crm_webhooks SET active=1-active, fail_streak=0 WHERE id=? AND client_id=?", [$id, $cid]); $back('#send'); }
    if ($a === 'hook_delete') { db_run("DELETE FROM crm_webhooks WHERE id=? AND client_id=?", [$id, $cid]); flash('Webhook removed.'); $back('#send'); }
    if ($a === 'hook_secret') {
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        db_run("UPDATE crm_webhooks SET secret_enc=? WHERE id=? AND client_id=?", [encrypt_secret($secret), $id, $cid]);
        $_SESSION['int_fresh'] = ['kind' => 'secret', 'value' => $secret];
        flash('New signing secret made. The old one stops working now.'); $back('#send');
    }
    if ($a === 'hook_test') {
        $h = db_row("SELECT * FROM crm_webhooks WHERE id=? AND client_id=?", [$id, $cid]);
        if ($h) {
            $sample = db_val("SELECT id FROM contacts WHERE client_id=? AND stage_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$cid]);
            $body = json_encode(['event' => 'test', 'occurred_at' => date('c'), 'origin' => 'app', 'lead' => $sample ? crm_int_lead((int) $sample) : null],
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            [$code, $resp] = crm_hook_post($h, 'test', $body, 'test-' . time());
            db_run("UPDATE crm_webhooks SET last_status=?, last_at=NOW() WHERE id=?", [mb_substr(($code ? 'HTTP ' . $code : 'No connection') . ($resp !== '' ? ' — ' . $resp : ''), 0, 255), $id]);
            flash($code >= 200 && $code < 300 ? 'Test sent — the other side answered ' . $code . '.' : 'The test did not go through: ' . ($code ? 'HTTP ' . $code : $resp), $code >= 200 && $code < 300 ? 'success' : 'error');
        }
        $back('#send');
    }
    if ($a === 'delivery_retry') {
        db_run("UPDATE crm_webhook_deliveries SET status='queued', next_at=NOW() WHERE id=? AND client_id=? AND status='failed'", [$id, $cid]);
        flash('Queued again.'); $back('#log');
    }
    // ── Receive: API keys ──
    if ($a === 'key_add') {
        $key = crm_api_key_create($cid, (string) ($_POST['name'] ?? ''), (string) ($_POST['access'] ?? 'read'), (string) ($_POST['system'] ?? ''), $me);
        $_SESSION['int_fresh'] = ['kind' => 'key', 'value' => $key];
        flash('API key made. Copy it now — it is not shown again.'); $back('#keys');
    }
    if ($a === 'key_revoke') { db_run("UPDATE crm_api_keys SET revoked_at=NOW() WHERE id=? AND client_id=?", [$id, $cid]); flash('Key revoked. Anything using it stops working now.'); $back('#keys'); }
    // ── Receive: incoming URLs ──
    if ($a === 'in_add') { crm_inbound_create($cid, (string) ($_POST['name'] ?? ''), $me); flash('Incoming URL made. Send a test lead to it, then map the fields.'); $back('#receive'); }
    if ($a === 'in_save') {
        $map = [];
        foreach ((array) ($_POST['map'] ?? []) as $ours => $theirs) {
            $theirs = trim((string) $theirs);
            if ($theirs !== '' && (isset(crm_int_fields()[$ours]) || str_starts_with((string) $ours, 'cf:'))) $map[$ours] = mb_substr($theirs, 0, 120);
        }
        $owner = (string) ($_POST['owner_rule'] ?? 'auto');
        if (!in_array($owner, ['auto', 'none'], true) && !db_val("SELECT COUNT(*) FROM users WHERE id=? AND client_id=?", [(int) $owner, $cid])) $owner = 'auto';
        $stage = (int) ($_POST['stage_id'] ?? 0);
        db_run("UPDATE crm_inbound_hooks SET mapping=?, owner_rule=?, stage_id=?, update_existing=?, system=?, active=? WHERE id=? AND client_id=?",
               [$map ? json_encode($map, JSON_UNESCAPED_UNICODE) : null, $owner, isset(crm_stage_map($cid)[$stage]) ? $stage : null,
                !empty($_POST['update_existing']) ? 1 : 0, mb_substr(trim((string) ($_POST['system'] ?? '')), 0, 40) ?: null, !empty($_POST['active']) ? 1 : 0, $id, $cid]);
        flash('Saved.'); $back('#in-' . $id);
    }
    if ($a === 'in_rotate') {
        $token = bin2hex(random_bytes(24));
        db_run("UPDATE crm_inbound_hooks SET token_hash=?, token_hint=?, token_enc=? WHERE id=? AND client_id=?", [hash('sha256', $token), substr($token, 0, 6), encrypt_secret($token), $id, $cid]);
        flash('New URL made. The old one stops working now.'); $back('#in-' . $id);
    }
    if ($a === 'in_delete') { db_run("DELETE FROM crm_inbound_hooks WHERE id=? AND client_id=?", [$id, $cid]); flash('Removed.'); $back('#receive'); }
}

$hooks = $ready ? db_all("SELECT * FROM crm_webhooks WHERE client_id=? ORDER BY id", [$cid]) : [];
$keys  = $ready ? db_all("SELECT * FROM crm_api_keys WHERE client_id=? ORDER BY revoked_at IS NOT NULL, id DESC", [$cid]) : [];
$ins   = $ready ? db_all("SELECT * FROM crm_inbound_hooks WHERE client_id=? ORDER BY id", [$cid]) : [];
$log   = $ready ? db_all("SELECT d.id, d.event, d.status, d.attempts, d.http_code, d.response, d.created_at, d.sent_at, d.contact_id, h.name hook
                            FROM crm_webhook_deliveries d JOIN crm_webhooks h ON h.id=d.webhook_id WHERE d.client_id=? ORDER BY d.id DESC LIMIT 40", [$cid]) : [];
$people = crm_assignable_users($cid);
$stages = crm_stages($cid);
$cfields = function_exists('crm_fields') ? crm_fields($cid) : [];
$api = rtrim(app_base_url(), '/') . '/api.php/v1';

client_header('Integrations', 'crm', $CLIENT);
page_head('Integrations');
if (!$ready): ?><div class="card"><p>Run the latest update (migration 058) to use integrations.</p></div><?php layout_footer(); exit; endif; ?>

<p class="text-muted" style="margin-top:-6px">Connect the CRM to another CRM — or n8n, Zapier, Make, or your own system — in both directions.
  <strong>Send</strong> pushes each lead to the other side the moment something happens to it. <strong>Receive</strong> lets the other side add and update leads here.</p>

<?php if ($fresh): ?>
<div class="card int-fresh">
  <h2><?= $fresh['kind'] === 'key' ? 'Your new API key' : 'The webhook\'s signing secret' ?></h2>
  <p class="text-muted" style="font-size:13px;margin-top:-4px"><?= $fresh['kind'] === 'key' ? 'Copy it now and keep it somewhere safe — it is not shown again. Anyone with it can reach your leads.'
       : 'Copy it now — it is not shown again. The other system uses it to check that each delivery really came from here.' ?></p>
  <div class="int-copy"><code id="fresh-val"><?= e($fresh['value']) ?></code><button type="button" class="btn btn-primary btn-sm" data-copy="fresh-val">Copy</button></div>
</div>
<?php endif; ?>

<nav class="int-tabs" aria-label="Sections"><a href="#send">Send data</a><a href="#receive">Receive data</a><a href="#keys">API keys</a><a href="#log">Delivery log</a><a href="#docs">Developer guide</a></nav>

<!-- ═══ SEND ═══ -->
<div class="card" id="send">
  <h2>Send data to another system</h2>
  <p class="text-muted" style="font-size:13px;margin-top:-4px">Each webhook gets the full lead as JSON, signed, whenever one of its events happens. Failed sends are retried for about 9 hours.</p>
  <?php if (!$hooks): ?><p class="text-muted">No webhooks yet.</p><?php endif; ?>
  <?php foreach ($hooks as $h): $ev = $h['events'] === '*' ? array_keys($events) : explode(',', (string) $h['events']); ?>
    <div class="int-item">
      <div class="int-item-head">
        <div><strong><?= e((string) $h['name']) ?></strong> <span class="pill <?= (int) $h['active'] ? ((int) $h['fail_streak'] >= 3 ? 'red' : 'green') : 'gray' ?>"><?= (int) $h['active'] ? ((int) $h['fail_streak'] >= 3 ? 'Failing' : 'On') : 'Paused' ?></span>
          <span class="int-url ltr"><?= e((string) $h['url']) ?></span>
          <?php if ($h['last_status']): ?><span class="text-muted" style="font-size:12px;display:block">Last: <?= e((string) $h['last_status']) ?> · <?= e(date('j M, H:i', strtotime((string) $h['last_at']))) ?></span><?php endif; ?></div>
        <div class="int-acts">
          <?php foreach (['hook_test' => 'Send test', 'hook_toggle' => (int) $h['active'] ? 'Pause' : 'Resume', 'hook_secret' => 'New secret', 'hook_delete' => 'Remove'] as $act => $lbl): ?>
            <form method="post" <?= in_array($act, ['hook_delete', 'hook_secret'], true) ? 'onsubmit="return confirm(\'' . ($act === 'hook_delete' ? 'Remove this webhook?' : 'Make a new secret? The other system must be updated with it.') . '\')"' : '' ?>>
              <?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
              <button class="<?= $act === 'hook_delete' ? 'btn-link' : 'btn btn-ghost btn-sm' ?>" <?= $act === 'hook_delete' ? 'style="color:var(--danger)"' : '' ?>><?= $lbl ?></button></form>
          <?php endforeach; ?>
        </div>
      </div>
      <details class="int-more"><summary>Events: <?= count($ev) === count($events) ? 'all' : e(implode(', ', array_map(fn($k) => $events[$k] ?? $k, $ev))) ?></summary>
        <form method="post" class="mt10"><?= csrf_field() ?><input type="hidden" name="action" value="hook_edit"><input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
          <div class="int-events"><?php foreach ($events as $k => $l): ?><label class="mod-all"><input type="checkbox" name="events[]" value="<?= $k ?>" <?= in_array($k, $ev, true) ? 'checked' : '' ?>> <?= e($l) ?> <code><?= $k ?></code></label><?php endforeach; ?></div>
          <label class="mod-all"><input type="checkbox" name="skip_api" value="1" <?= (int) $h['skip_api'] ? 'checked' : '' ?>> Don't send back changes that came in from the other system</label>
          <button class="btn btn-ghost btn-sm mt10">Save</button></form></details>
    </div>
  <?php endforeach; ?>
  <details class="int-add" <?= !$hooks ? 'open' : '' ?>><summary class="btn btn-primary btn-sm">+ Add a webhook</summary>
    <form method="post" class="mt10">
      <?= csrf_field() ?><input type="hidden" name="action" value="hook_add">
      <div class="grid2">
        <div class="field"><span class="lbl">Name</span><input name="name" maxlength="80" placeholder="Salesforce, HubSpot, n8n…"></div>
        <div class="field"><span class="lbl">URL to send to</span><input name="url" type="text" inputmode="url" required maxlength="500" placeholder="https://their-crm.example.com/webhooks/revenect"></div>
      </div>
      <div class="lbl-sm">Send when</div>
      <div class="int-events"><?php foreach ($events as $k => $l): ?><label class="mod-all"><input type="checkbox" name="events[]" value="<?= $k ?>" <?= in_array($k, ['lead.created', 'lead.stage_changed', 'lead.assigned'], true) ? 'checked' : '' ?>> <?= e($l) ?> <code><?= $k ?></code></label><?php endforeach; ?></div>
      <label class="mod-all"><input type="checkbox" name="skip_api" value="1" checked> Don't send back changes that came in from the other system (stops the two systems echoing each other)</label>
      <button class="btn btn-primary btn-sm mt10">Add webhook</button>
    </form>
  </details>
</div>

<!-- ═══ RECEIVE ═══ -->
<div class="card" id="receive">
  <h2>Receive data from another system</h2>
  <p class="text-muted" style="font-size:13px;margin-top:-4px">Two ways in. An <strong>incoming URL</strong> takes whatever JSON the other system sends and maps its fields to a lead — nothing to program.
    The <strong>API</strong> (keys below) lets a developer create, update and read leads exactly. Either way, a lead with the same external id or phone is updated rather than added twice.</p>
  <?php foreach ($ins as $in): $tok = decrypt_secret((string) ($in['token_enc'] ?? '')); $url = $tok !== '' ? crm_inbound_url($tok) : '';
        $map = json_decode((string) ($in['mapping'] ?? ''), true) ?: [];
        $paths = $in['last_payload'] ? array_keys(crm_int_flatten(json_decode((string) $in['last_payload'], true) ?: [])) : []; ?>
    <div class="int-item" id="in-<?= (int) $in['id'] ?>">
      <div class="int-item-head">
        <div><strong><?= e((string) $in['name']) ?></strong> <span class="pill <?= (int) $in['active'] ? 'green' : 'gray' ?>"><?= (int) $in['active'] ? 'On' : 'Off' ?></span>
          <span class="text-muted" style="font-size:12px;display:block"><?= (int) $in['received'] ?> received<?= $in['last_at'] ? ' · last ' . e(date('j M, H:i', strtotime((string) $in['last_at']))) . ' — ' . e((string) $in['last_result']) : '' ?></span></div>
        <div class="int-acts">
          <form method="post" onsubmit="return confirm('Make a new URL? The old one stops working.')"><?= csrf_field() ?><input type="hidden" name="action" value="in_rotate"><input type="hidden" name="id" value="<?= (int) $in['id'] ?>"><button class="btn btn-ghost btn-sm">New URL</button></form>
          <form method="post" onsubmit="return confirm('Remove this incoming URL?')"><?= csrf_field() ?><input type="hidden" name="action" value="in_delete"><input type="hidden" name="id" value="<?= (int) $in['id'] ?>"><button class="btn-link" style="color:var(--danger)">Remove</button></form>
        </div>
      </div>
      <?php if ($url !== ''): ?><div class="int-copy"><code id="in-url-<?= (int) $in['id'] ?>"><?= e($url) ?></code><button type="button" class="btn btn-ghost btn-sm" data-copy="in-url-<?= (int) $in['id'] ?>">Copy URL</button></div><?php endif; ?>
      <details class="int-more" <?= isset($_GET['map']) && (int) $_GET['map'] === (int) $in['id'] ? 'open' : '' ?>><summary>Field mapping and options</summary>
        <form method="post" class="mt10"><?= csrf_field() ?><input type="hidden" name="action" value="in_save"><input type="hidden" name="id" value="<?= (int) $in['id'] ?>">
          <p class="text-muted" style="font-size:12.5px">For each of our fields, the name of their field (use dots for nested ones: <code>contact.phone</code>). Leave all empty to recognise common names
            automatically (phone, mobile, email, full_name, first_name, campaign, utm_campaign…). <?= $paths ? 'Their fields from the last thing received are offered as you type.' : 'Send a test lead first, and their field names will be offered here.' ?></p>
          <datalist id="paths-<?= (int) $in['id'] ?>"><?php foreach ($paths as $pth): ?><option value="<?= e((string) $pth) ?>"><?php endforeach; ?></datalist>
          <div class="int-map">
            <?php foreach (crm_int_fields() as $k => $l): ?>
              <label><span><?= e($l) ?></span><input name="map[<?= $k ?>]" value="<?= e((string) ($map[$k] ?? '')) ?>" list="paths-<?= (int) $in['id'] ?>" placeholder="their field" autocomplete="off"></label>
            <?php endforeach; ?>
            <?php foreach ($cfields as $f_): $k = 'cf:' . $f_['fkey']; ?>
              <label><span><?= e((string) $f_['label']) ?> <small class="text-muted">(your field)</small></span><input name="map[<?= e($k) ?>]" value="<?= e((string) ($map[$k] ?? '')) ?>" list="paths-<?= (int) $in['id'] ?>" placeholder="their field" autocomplete="off"></label>
            <?php endforeach; ?>
          </div>
          <div class="grid2 mt10">
            <div class="field"><span class="lbl">New leads go to</span><select name="owner_rule">
              <option value="auto" <?= $in['owner_rule'] === 'auto' ? 'selected' : '' ?>>The assignment rules</option>
              <option value="none" <?= $in['owner_rule'] === 'none' ? 'selected' : '' ?>>Nobody (unassigned)</option>
              <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $in['owner_rule'] === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
              <small class="text-muted">An "owner email" field, when sent, wins.</small></div>
            <div class="field"><span class="lbl">…in the stage</span><select name="stage_id"><option value="0">The first stage</option>
              <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $in['stage_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><span class="lbl">Their system's name</span><input name="system" maxlength="40" value="<?= e((string) ($in['system'] ?? '')) ?>" placeholder="Salesforce"></div>
          </div>
          <label class="mod-all"><input type="checkbox" name="update_existing" value="1" <?= (int) $in['update_existing'] ? 'checked' : '' ?>> Update a lead that already exists (same external id or phone)</label>
          <label class="mod-all"><input type="checkbox" name="active" value="1" <?= (int) $in['active'] ? 'checked' : '' ?>> On</label>
          <button class="btn btn-primary btn-sm mt10">Save mapping</button>
        </form>
        <?php if ($in['last_payload']): ?><details class="mt10"><summary class="text-muted" style="font-size:12.5px">The last thing received</summary><pre class="int-pre"><?= e((string) $in['last_payload']) ?></pre></details><?php endif; ?>
      </details>
    </div>
  <?php endforeach; ?>
  <form method="post" class="int-inline mt10"><?= csrf_field() ?><input type="hidden" name="action" value="in_add">
    <input name="name" maxlength="80" placeholder="Name, e.g. Leads from HubSpot"><button class="btn btn-primary btn-sm">+ Make an incoming URL</button></form>
</div>

<!-- ═══ API KEYS ═══ -->
<div class="card" id="keys">
  <h2>API keys</h2>
  <p class="text-muted" style="font-size:13px;margin-top:-4px">For a developer or a tool that calls the API. <strong>Read</strong> keys can only look; <strong>read &amp; write</strong> keys can add and change leads and log activities.</p>
  <?php if ($keys): ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Name</th><th>Key</th><th>Access</th><th>System</th><th>Last used</th><th></th></tr></thead>
    <tbody><?php foreach ($keys as $k): ?>
      <tr class="<?= $k['revoked_at'] ? 'int-revoked' : '' ?>"><td><?= e((string) $k['name']) ?></td><td><code><?= e((string) $k['prefix']) ?>_…</code></td>
        <td><?= $k['access'] === 'write' ? 'Read &amp; write' : 'Read' ?></td><td><?= e((string) ($k['system'] ?? '—')) ?></td>
        <td><?= $k['last_used_at'] ? e(date('j M, H:i', strtotime((string) $k['last_used_at']))) : 'Never' ?></td>
        <td><?php if ($k['revoked_at']): ?><span class="text-muted">Revoked</span><?php else: ?>
          <form method="post" onsubmit="return confirm('Revoke this key? Anything using it stops working.')"><?= csrf_field() ?><input type="hidden" name="action" value="key_revoke"><input type="hidden" name="id" value="<?= (int) $k['id'] ?>"><button class="btn-link" style="color:var(--danger)">Revoke</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <form method="post" class="int-inline mt10"><?= csrf_field() ?><input type="hidden" name="action" value="key_add">
    <input name="name" maxlength="80" placeholder="Key name" required>
    <select name="access"><option value="read">Read only</option><option value="write">Read &amp; write</option></select>
    <input name="system" maxlength="40" placeholder="Their system (optional)">
    <button class="btn btn-primary btn-sm">+ Make a key</button></form>
</div>

<!-- ═══ LOG ═══ -->
<div class="card card-flush" id="log">
  <div style="padding:14px 18px"><h2 style="margin:0;border:0;padding:0">Delivery log</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">The last 40 things sent out.</p></div>
  <?php if (!$log): ?><p class="text-muted" style="padding:0 18px 16px;margin:0">Nothing sent yet.</p><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>When</th><th>Webhook</th><th>Event</th><th>Lead</th><th>Result</th><th></th></tr></thead>
    <tbody><?php foreach ($log as $d): ?>
      <tr><td><?= e(date('j M, H:i:s', strtotime((string) $d['created_at']))) ?></td><td><?= e((string) $d['hook']) ?></td><td><code><?= e((string) $d['event']) ?></code></td>
        <td><?php if ($d['contact_id']): ?><a href="crm_lead.php?id=<?= (int) $d['contact_id'] ?>">Open</a><?php endif; ?></td>
        <td><span class="pill <?= ['sent' => 'green', 'failed' => 'red', 'queued' => 'gold', 'sending' => 'blue'][$d['status']] ?? 'gray' ?>"><?= e(['sent' => 'Sent', 'failed' => 'Failed', 'queued' => (int) $d['attempts'] ? 'Retrying' : 'Waiting', 'sending' => 'Sending'][$d['status']] ?? $d['status']) ?></span>
          <span class="text-muted" style="font-size:12px"><?= $d['http_code'] ? 'HTTP ' . (int) $d['http_code'] : '' ?><?= $d['response'] ? ' · ' . e(mb_substr((string) $d['response'], 0, 80)) : '' ?><?= (int) $d['attempts'] > 1 ? ' · ' . (int) $d['attempts'] . ' tries' : '' ?></span></td>
        <td><?php if ($d['status'] === 'failed'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delivery_retry"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn btn-ghost btn-sm">Retry</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>

<!-- ═══ DOCS ═══ -->
<div class="card int-docs" id="docs">
  <h2>Developer guide</h2>
  <h3>The API</h3>
  <p>Base address <code class="ltr"><?= e($api) ?></code> — send the key as <code>Authorization: Bearer rvn_…</code> (or <code>X-Api-Key</code>). JSON in, JSON out; 120 requests a minute per key.</p>
  <div class="table-wrap"><table class="data int-endpoints"><tbody>
    <tr><td><code>GET /leads</code></td><td>Leads, newest first. Filters: <code>updated_since</code>, <code>created_since</code>, <code>stage</code>, <code>owner_email</code>, <code>phone</code>, <code>external_id</code>, <code>page</code>, <code>per_page</code> (up to 200).</td></tr>
    <tr><td><code>GET /leads/{ref}</code></td><td>One lead. <code>{ref}</code> is our id, the lead code (<code>A1B2C3</code>) or <code>ext:&lt;your id&gt;</code>.</td></tr>
    <tr><td><code>POST /leads</code></td><td>Add a lead — or update the one with the same <code>external_id</code> or phone. 201 when added, 200 when updated.</td></tr>
    <tr><td><code>PATCH /leads/{ref}</code></td><td>Change only the fields you send, including <code>stage</code>, <code>substatus</code>, <code>owner_email</code>.</td></tr>
    <tr><td><code>POST /leads/{ref}/activities</code></td><td>Log a call, meeting, WhatsApp, email or note: <code>{"kind":"call","outcome":"answered","body":"…"}</code></td></tr>
    <tr><td><code>GET /leads/{ref}/activities</code></td><td>What was logged on a lead.</td></tr>
    <tr><td><code>GET /stages · /users · /projects · /fields · /me</code></td><td>The names to use, and what the key can do.</td></tr>
  </tbody></table></div>
  <p class="lbl-sm">Fields you can send</p>
  <p class="int-fields"><?php foreach (crm_int_fields() as $k => $l): ?><span><code><?= $k ?></code> <?= e($l) ?></span><?php endforeach; ?><span><code>custom</code> {your_field_key: value}</span></p>
  <pre class="int-pre ltr">curl -X POST <?= e($api) ?>/leads \
  -H "Authorization: Bearer rvn_xxxxxx_…" -H "Content-Type: application/json" \
  -d '{"external_id":"SF-00Q123","name":"Ali Kharboush","phone":"+201066677788",
       "email":"ali@example.com","campaign":"Porto Said July","project":"Porto Said",
       "stage":"Contacted","owner_email":"sara@yourcompany.com","note":"From the website"}'</pre>
  <h3>Webhooks we send</h3>
  <p>A POST with JSON: <code>{"event":"lead.stage_changed","occurred_at":"…","origin":"app","lead":{…the lead as GET /leads/{ref} returns it…},"data":{"from_stage":"Contacted","to_stage":"Viewing"}}</code>.
    <code>origin</code> is <code>api</code> when the change came in from another system. Answer with any 2xx within 10 seconds; anything else is retried after 1 min, 5 min, 30 min, 2 h and 6 h.</p>
  <p>Headers: <code>X-Revenect-Event</code>, <code>X-Revenect-Delivery</code> (unique — use it to ignore repeats), <code>X-Revenect-Timestamp</code>, and
    <code>X-Revenect-Signature: sha256=HMAC_SHA256(secret, timestamp + "." + body)</code>. Check it like this:</p>
  <pre class="int-pre ltr">// PHP
$expected = 'sha256=' . hash_hmac('sha256', $_SERVER['HTTP_X_REVENECT_TIMESTAMP'] . '.' . file_get_contents('php://input'), $secret);
if (!hash_equals($expected, $_SERVER['HTTP_X_REVENECT_SIGNATURE'])) { http_response_code(401); exit; }

// Node.js
const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(req.headers['x-revenect-timestamp'] + '.' + rawBody).digest('hex');</pre>
  <h3>Incoming URLs</h3>
  <p>POST any JSON object (or form fields), one lead at a time or a list of up to 100 (<code>[{…},{…}]</code> or <code>{"leads":[…]}</code>). The answer is
    <code>{"ok":true,"id":123,"code":"A1B2C3","created":true}</code>, or <code>{"ok":false,"error":"…"}</code> with status 422.</p>
</div>

<script>
document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => {
  const t = document.getElementById(b.dataset.copy).textContent.trim();
  (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(() => { b.textContent = 'Copied'; }).catch(() => {
    const r = document.createRange(); r.selectNodeContents(document.getElementById(b.dataset.copy)); getSelection().removeAllRanges(); getSelection().addRange(r); });
}));
</script>
<?php layout_footer();
