<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/integrations.php';

/**
 * Connected apps: Shopify, Zoho CRM, Zoho Books and Zoho Inventory, built in. Connect with one
 * button (or keys), then choose what syncs, which customer messages go, and watch what happened.
 * Everyone with the module sees it; only an Admin connects, changes or disconnects.
 */
$cid = (int) $CLIENT['id'];
$uid = (int) ($PERM_USER['id'] ?? $ME['id'] ?? 0);
$isAdmin = is_client_admin();
$cat = integrations_catalog();
$ready = integrations_ready();
$crmOn = function_exists('crm_enabled') && crm_enabled($CLIENT);

$open = (int) ($_GET['app'] ?? 0);
$it = $open && $ready ? integration_get($open, $cid) : null;
if ($it && $it['status'] === 'disconnected') $it = null;
$here = fn(string $h = '') => 'apps.php' . ($it ? '?app=' . (int) $it['id'] : '') . $h;

// Back from WooCommerce's approval screen. The key itself arrived separately (woo_auth.php).
if (isset($_GET['woo']) && $ready && !$it) {
    if (($_GET['success'] ?? '1') === '0') flash('You did not approve the connection in WooCommerce — nothing was changed.', 'error');
    else {
        $w = db_row("SELECT id, display_name FROM integrations WHERE client_id=? AND provider='woocommerce' AND status<>'disconnected' AND updated_at > NOW() - INTERVAL 15 MINUTE ORDER BY updated_at DESC LIMIT 1", [$cid]);
        if ($w) { flash('Connected ' . $w['display_name'] . '. Importing now — this page fills in over the next minutes.'); redirect('apps.php?app=' . (int) $w['id']); }
        flash('WooCommerce did not send the key back. Your store must use https:// and reach this site — or connect with keys instead.', 'error');
    }
    redirect('apps.php#woocommerce');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    verify_csrf();
    if (!$isAdmin) { flash('Only an Admin can change connected apps.', 'error'); redirect($here()); }
    $a = (string) ($_POST['action'] ?? '');
    $prov = (string) ($_POST['provider'] ?? '');
    $dc = zoho_dc((string) ($_POST['dc'] ?? 'com'));
    $done = function (array $r) {
        if (!empty($r['ok'])) { flash('Connected ' . $r['name'] . '. Importing now — this page fills in over the next minutes.'); redirect('apps.php?app=' . (int) $r['id']); }
        flash((string) $r['error'], 'error'); redirect('apps.php');
    };

    if ($a === 'shopify_connect') {
        $shop = shopify_shop((string) ($_POST['shop'] ?? ''));
        if ($shop === '') { flash('Enter your store address, like mystore.myshopify.com.', 'error'); redirect('apps.php#shopify'); }
        if (!shopify_configured()) { flash('The Connect button is not set up on this platform yet — connect with keys instead.', 'error'); redirect('apps.php#shopify'); }
        redirect(shopify_auth_url($cid, $uid, $shop));
    }
    if ($a === 'shopify_keys') {
        $done(shopify_connect_keys($cid, $uid, (string) ($_POST['shop'] ?? ''), trim((string) ($_POST['token'] ?? '')), trim((string) ($_POST['app_id'] ?? '')), trim((string) ($_POST['secret'] ?? ''))));
    }
    if ($a === 'woo_connect') {
        $store = woo_store((string) ($_POST['store'] ?? ''));
        if ($store === '') { flash('Enter your store address, like https://shop.example.com.', 'error'); redirect('apps.php#woocommerce'); }
        if (($bad = crm_hook_url_ok($store)) !== '') { flash($bad, 'error'); redirect('apps.php#woocommerce'); }
        redirect(woo_auth_url($cid, $uid, $store));
    }
    if ($a === 'woo_keys') {
        $done(woo_connect_keys($cid, $uid, (string) ($_POST['store'] ?? ''), trim((string) ($_POST['ck'] ?? '')), trim((string) ($_POST['cs'] ?? ''))));
    }
    $isZoho = str_starts_with($prov, 'zoho_') && isset($cat[$prov]);
    if ($a === 'zoho_connect' && $isZoho) {
        if (!zoho_configured()) { flash('The Connect button is not set up on this platform yet — connect with a Self Client instead.', 'error'); redirect('apps.php#' . $prov); }
        redirect(zoho_auth_url($cid, $uid, $prov, $dc));
    }
    if ($a === 'zoho_keys' && $isZoho) {
        $done(zoho_connect_keys($cid, $uid, $prov, $dc, trim((string) ($_POST['zid'] ?? '')), trim((string) ($_POST['zsecret'] ?? '')),
                                trim((string) ($_POST['code'] ?? '')), trim((string) ($_POST['refresh'] ?? ''))));
    }

    // Everything below acts on one connection of this account.
    if (!$it) redirect('apps.php');
    $s = integration_settings($it);
    if ($a === 'disconnect') {
        match ($it['provider']) { 'shopify' => shopify_disconnect($it), 'woocommerce' => woo_disconnect($it), default => zoho_disconnect($it) };
        flash($it['display_name'] . ' disconnected. Orders and contacts already received stay.'); redirect('apps.php');
    }
    if ($a === 'sync') {
        $cur = $it['cursor'];
        if (in_array($it['provider'], ['shopify', 'woocommerce'], true)) { integration_job($it, 'register_webhooks', 0); integration_job($it, 'backfill', 0, ['what' => 'customers']); $cur['poll_at'] = 0; }
        elseif ($it['provider'] === 'zoho_crm') { $cur['poll_at'] = 0; $cur['since'] = date('c', time() - 86400); integration_job($it, 'watch', 0); integration_job($it, 'users', 0); integration_job($it, 'fields', 0); }
        else $cur['poll_at'] = 0;
        integration_cursor_save($it, $cur);
        integration_error($it, '');
        flash('Syncing now — give it a minute.'); redirect($here());
    }
    if ($a === 'retry') {
        db_run("UPDATE integration_jobs SET status='queued', attempts=0, next_at=NOW() WHERE integration_id=? AND status='failed'", [(int) $it['id']]);
        integration_error($it, '');
        flash('Trying again.'); redirect($here('#log'));
    }
    if ($a === 'push_all' && $it['provider'] === 'zoho_crm') {
        $n = 0;
        foreach (db_all("SELECT id FROM contacts WHERE client_id=? AND stage_id IS NOT NULL ORDER BY id DESC LIMIT 2000", [$cid]) as $row) { integration_job($it, 'push_lead', (int) $row['id'], [], $n++ % 600); }
        flash($n . ' leads queued for Zoho — they go over the next few minutes.'); redirect($here('#log'));
    }
    if ($a === 'save_data') {
        if (in_array($it['provider'], ['shopify', 'woocommerce'], true)) {
            $s['sync_customers'] = !empty($_POST['sync_customers']) ? 1 : 0;
            $s['to_crm'] = in_array($_POST['to_crm'] ?? '', ['none', 'buyers', 'all'], true) ? $_POST['to_crm'] : 'buyers';
            $s['crm_stage_id'] = (int) ($_POST['crm_stage_id'] ?? 0);
            $s['import_days'] = max(1, min(365, (int) ($_POST['import_days'] ?? 60)));
        } elseif ($it['provider'] === 'zoho_crm') {
            foreach (['push_leads', 'pull_leads', 'deals', 'notes'] as $k) $s[$k] = !empty($_POST[$k]) ? 1 : 0;
            $s['module'] = ($_POST['module'] ?? '') === 'Contacts' ? 'Contacts' : 'Leads';
            $s['won_stage'] = mb_substr(trim((string) ($_POST['won_stage'] ?? 'Closed Won')), 0, 100) ?: 'Closed Won';
            $s['lost_stage'] = mb_substr(trim((string) ($_POST['lost_stage'] ?? 'Closed Lost')), 0, 100) ?: 'Closed Lost';
            $clean = fn($arr) => array_filter(array_map(fn($v) => mb_substr(trim((string) $v), 0, 100), (array) $arr), 'strlen');
            $s['stage_map'] = $clean($_POST['stage_map'] ?? []);
            $s['deal_stages'] = $clean($_POST['deal_stages'] ?? []);
            $fm = [];
            $ours = zoho_revenect_fields($cid);
            foreach ((array) ($_POST['fm_ours'] ?? []) as $i => $k) {
                $theirs = trim((string) ($_POST['fm_theirs'][$i] ?? ''));
                if (isset($ours[$k]) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', $theirs)) $fm[$k] = $theirs;
            }
            $s['field_map'] = $fm;
        } else {
            $s['sync_customers'] = !empty($_POST['sync_customers']) ? 1 : 0;
        }
        integration_settings_save($it, $s);
        flash('Saved.'); redirect($here('#data'));
    }
    if ($a === 'save_messages') {
        $events = integration_message_events((string) $it['provider']);
        foreach ($events as $ev => $_) {
            $p = (array) ($_POST['m'][$ev] ?? []);
            $r = $s['messages'][$ev] ?? [];
            $r['on'] = !empty($p['on']) ? 1 : 0;
            $r['send_by'] = in_array($p['send_by'] ?? '', ['whatsapp', 'sms', 'wa_then_sms'], true) ? $p['send_by'] : 'whatsapp';
            $r['template_id'] = (int) ($p['template_id'] ?? 0);
            $r['vars'] = array_values(array_map(fn($v) => mb_substr((string) $v, 0, 200), array_slice((array) ($p['vars'] ?? []), 0, 20)));
            $r['sms_text'] = mb_substr(trim((string) ($p['sms_text'] ?? '')), 0, 1000);
            $r['delay'] = max(0, min(10080, (int) ($p['delay'] ?? 0)));
            if ($ev === 'cart_abandoned') { $r['delay'] = max(15, $r['delay']); $r['second_delay'] = max(0, min(72, (int) ($p['second_delay'] ?? 0))); }
            if ($r['on'] && $r['send_by'] !== 'sms' && !$r['template_id']) { flash('“' . $events[$ev] . '”: choose a WhatsApp template, or send it by SMS.', 'error'); redirect($here('#messages')); }
            if ($r['on'] && $r['send_by'] !== 'whatsapp' && $r['sms_text'] === '') { flash('“' . $events[$ev] . '”: write the SMS text.', 'error'); redirect($here('#messages')); }
            $s['messages'][$ev] = $r;
        }
        integration_settings_save($it, $s);
        flash('Messages saved.'); redirect($here('#messages'));
    }
    redirect($here());
}

$ago = function (?string $t): string {
    if (!$t) return 'never';
    $d = time() - strtotime($t);
    return $d < 90 ? 'just now' : ($d < 5400 ? round($d / 60) . ' min ago' : ($d < 129600 ? round($d / 3600) . ' h ago' : date('j M', strtotime($t))));
};
$pill = function (array $x): string {
    return match (true) {
        $x['status'] === 'paused' => '<span class="pill gray">Paused</span>',
        $x['status'] === 'error' || !empty($x['last_error']) => '<span class="pill red">Needs attention</span>',
        default => '<span class="pill green">Connected</span>',
    };
};
$mark = ['shopify' => ['S', 'ap-shopify'], 'woocommerce' => ['W', 'ap-woo'], 'zoho_crm' => ['Z', 'ap-zoho'], 'zoho_books' => ['B', 'ap-zoho'], 'zoho_inventory' => ['I', 'ap-zoho']];

client_header('Connected apps', 'integrations', $CLIENT);
?>
<style>
  .ap-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 330px), 1fr)); gap: 14px; }
  .ap-card { display: flex; flex-direction: column; gap: 10px; margin: 0; }
  .ap-head { display: flex; gap: 12px; align-items: center; }
  .ap-mark { width: 40px; height: 40px; border-radius: 10px; display: grid; place-items: center; font-weight: 800; font-size: 18px; color: #fff; flex: none; }
  .ap-shopify { background: #5E8E3E; } .ap-woo { background: #7F54B3; } .ap-zoho { background: #C8202B; }
  .ap-head h2 { margin: 0; border: 0; padding: 0; font-size: 16px; }
  .ap-group { font-size: 11.5px; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }
  .ap-blurb { font-size: 13px; color: var(--slate); margin: 0; }
  .ap-acct { display: flex; justify-content: space-between; gap: 10px; align-items: center; border: 1px solid var(--line); border-radius: 9px; padding: 8px 10px; font-size: 13px; }
  .ap-acct small { display: block; color: var(--muted); font-size: 12px; }
  .ap-connect { display: flex; gap: 8px; flex-wrap: wrap; }
  .ap-connect input, .ap-connect select { flex: 1 1 180px; min-width: 0; }
  .ap-keys summary { font-size: 12.5px; color: var(--brand); cursor: pointer; }
  .ap-keys .field { margin-top: 8px; }
  .ap-keys ol { font-size: 12.5px; color: var(--slate); padding-inline-start: 18px; margin: 8px 0; }
  .ap-stats { display: flex; gap: 10px; flex-wrap: wrap; }
  .ap-stat { border: 1px solid var(--line); border-radius: 10px; padding: 10px 14px; min-width: 130px; }
  .ap-stat b { display: block; font-size: 20px; font-variant-numeric: tabular-nums; }
  .ap-stat span { font-size: 12px; color: var(--muted); }
  .ap-rule { border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; }
  .ap-rule-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
  .ap-rule-body { display: grid; gap: 10px; margin-top: 10px; }
  .ap-rule[data-off="1"] .ap-rule-body { display: none; }
  .ap-vars { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 220px), 1fr)); gap: 8px; }
  .ap-vars label { font-size: 12px; color: var(--muted); display: grid; gap: 3px; }
  .ap-prev { background: var(--chip); border-radius: 9px; padding: 9px 11px; font-size: 13px; white-space: pre-wrap; }
  .ap-map { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 12px; align-items: center; font-size: 13px; }
  .ap-map select, .ap-map input { width: 100%; }
  @media (max-width: 640px) { .ap-map { grid-template-columns: 1fr; } }
</style>
<?php
if (!$ready) { page_head('Connected apps'); echo '<div class="card"><p>Run the latest update (migration 067) to connect apps.</p></div>'; layout_footer(); exit; }

/* ═════════════════ the list of apps ═════════════════ */
if (!$it):
    page_head('Connected apps', can_use('crm') && $isAdmin ? '<a class="btn btn-ghost btn-sm" href="crm_integrations.php">API keys &amp; webhooks →</a>' : '');
    $mine = [];
    foreach (integrations_for($cid) as $x) $mine[$x['provider']][] = $x;
    $dcs = zoho_dcs(); ?>
<p class="text-muted" style="margin-top:-6px">Connect your store and your Zoho apps. Orders become WhatsApp and SMS updates, customers become contacts and leads, and your CRM stays in step with Zoho — both ways.</p>
<div class="ap-grid">
<?php foreach ($cat as $prov => $c): [$letter, $cls] = $mark[$prov]; ?>
  <section class="card ap-card" id="<?= e($prov) ?>">
    <div class="ap-head"><div class="ap-mark <?= $cls ?>" aria-hidden="true"><?= $letter ?></div>
      <div><span class="ap-group"><?= e($c['group']) ?></span><h2><?= e($c['label']) ?></h2></div></div>
    <p class="ap-blurb"><?= e($c['blurb']) ?></p>
    <?php foreach ($mine[$prov] ?? [] as $x): ?>
      <div class="ap-acct"><div><strong><?= e((string) ($x['display_name'] ?: $x['account'])) ?></strong>
        <small><?= in_array($prov, ['shopify', 'woocommerce'], true) ? e((string) $x['account']) : e($dcs[zoho_dc((string) $x['data_center'])]['label'] ?? '') ?> · last activity <?= e($ago($x['last_event_at'] ?? $x['last_sync_at'] ?? null)) ?></small></div>
        <div style="display:flex;gap:8px;align-items:center"><?= $pill($x) ?><a class="btn btn-ghost btn-sm" href="apps.php?app=<?= (int) $x['id'] ?>">Open</a></div></div>
    <?php endforeach; ?>
    <?php if ($isAdmin): ?>
      <?php if ($prov === 'shopify'): ?>
        <form method="post" class="ap-connect"><?= csrf_field() ?><input type="hidden" name="action" value="shopify_connect">
          <input name="shop" required maxlength="120" placeholder="mystore.myshopify.com" aria-label="Your Shopify store address" autocomplete="off" dir="ltr">
          <button class="btn btn-primary btn-sm" <?= shopify_configured() ? '' : 'disabled title="Not set up on this platform yet — use keys"' ?>><?= empty($mine[$prov]) ? 'Connect Shopify' : 'Connect another store' ?></button></form>
        <details class="ap-keys"><summary>Connect with keys instead</summary>
          <ol><li>In Shopify: <strong>Settings → Apps → Develop apps</strong> (or the Dev Dashboard) → create an app.</li>
            <li>Give it the Admin API access <code>read_customers</code>, <code>read_orders</code>, <code>read_fulfillments</code>, <code>read_checkouts</code>, then install it on your store.</li>
            <li>Paste the <strong>Admin API access token</strong> (shpat_…) — or the app's <strong>client ID</strong> — and its <strong>API secret key</strong> below.</li></ol>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="shopify_keys">
            <div class="field"><span class="lbl">Store address</span><input name="shop" required maxlength="120" placeholder="mystore.myshopify.com" dir="ltr" autocomplete="off"></div>
            <div class="field"><span class="lbl">Admin API access token</span><input type="password" name="token" maxlength="200" placeholder="shpat_…" dir="ltr" autocomplete="new-password"></div>
            <div class="field"><span class="lbl">…or the app's client ID</span><input name="app_id" maxlength="200" dir="ltr" autocomplete="off"></div>
            <div class="field"><span class="lbl">API secret key</span><input name="secret" type="password" required maxlength="200" dir="ltr" autocomplete="off"></div>
            <button class="btn btn-primary btn-sm mt10">Connect with keys</button></form></details>
      <?php elseif ($prov === 'woocommerce'): ?>
        <form method="post" class="ap-connect"><?= csrf_field() ?><input type="hidden" name="action" value="woo_connect">
          <input name="store" required maxlength="190" placeholder="https://shop.example.com" aria-label="Your WooCommerce store address" autocomplete="off" dir="ltr">
          <button class="btn btn-primary btn-sm"><?= empty($mine[$prov]) ? 'Connect WooCommerce' : 'Connect another store' ?></button></form>
        <details class="ap-keys"><summary>Connect with keys instead</summary>
          <ol><li>In WordPress: <strong>WooCommerce → Settings → Advanced → REST API → Add key</strong>.</li>
            <li>Description "Revenect", a user who is an Administrator or Shop manager, Permissions <strong>Read/Write</strong> (Write is needed to add the order notifications).</li>
            <li>Paste the <strong>consumer key</strong> and <strong>consumer secret</strong> below. The store must use https://.</li></ol>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="woo_keys">
            <div class="field"><span class="lbl">Store address</span><input name="store" required maxlength="190" placeholder="https://shop.example.com" dir="ltr" autocomplete="off"></div>
            <div class="field"><span class="lbl">Consumer key</span><input name="ck" required maxlength="60" placeholder="ck_…" dir="ltr" autocomplete="off"></div>
            <div class="field"><span class="lbl">Consumer secret</span><input name="cs" type="password" required maxlength="60" placeholder="cs_…" dir="ltr" autocomplete="off"></div>
            <button class="btn btn-primary btn-sm mt10">Connect with keys</button></form></details>
      <?php else: ?>
        <form method="post" class="ap-connect"><?= csrf_field() ?><input type="hidden" name="action" value="zoho_connect"><input type="hidden" name="provider" value="<?= e($prov) ?>">
          <select name="dc" aria-label="Where your Zoho account is"><?php foreach ($dcs as $k => $d): ?><option value="<?= e($k) ?>"><?= e($d['label']) ?></option><?php endforeach; ?></select>
          <button class="btn btn-primary btn-sm" <?= zoho_configured() ? '' : 'disabled title="Not set up on this platform yet — use a Self Client"' ?>>Connect <?= e($c['label']) ?></button></form>
        <details class="ap-keys"><summary>Connect with a Self Client instead</summary>
          <ol><li>Open the Zoho API console (api-console.zoho.com on your data centre) → <strong>Add client → Self Client</strong>.</li>
            <li>Under <strong>Generate code</strong>, paste these scopes, choose 10 minutes, and create: <code style="overflow-wrap:anywhere"><?= e(zoho_scopes($prov)) ?></code></li>
            <li>Paste the client ID, client secret and the code below within 10 minutes.</li></ol>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="zoho_keys"><input type="hidden" name="provider" value="<?= e($prov) ?>">
            <div class="field"><span class="lbl">Data centre</span><select name="dc"><?php foreach ($dcs as $k => $d): ?><option value="<?= e($k) ?>"><?= e($d['label']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><span class="lbl">Client ID</span><input name="zid" required maxlength="200" dir="ltr" autocomplete="off"></div>
            <div class="field"><span class="lbl">Client secret</span><input name="zsecret" type="password" required maxlength="200" dir="ltr" autocomplete="off"></div>
            <div class="field"><span class="lbl">Grant code</span><input name="code" maxlength="300" dir="ltr" autocomplete="off" placeholder="1000.…"></div>
            <div class="field"><span class="lbl">…or a refresh token</span><input name="refresh" type="password" maxlength="300" dir="ltr" autocomplete="off"></div>
            <button class="btn btn-primary btn-sm mt10">Connect with keys</button></form></details>
      <?php endif; ?>
    <?php elseif (empty($mine[$prov])): ?>
      <p class="text-muted" style="font-size:12.5px;margin:0">Not connected. An Admin of your account can connect it.</p>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
</div>
<?php layout_footer(); exit; endif;

/* ═════════════════ one connected app ═════════════════ */
$prov = (string) $it['provider'];
$s = integration_settings($it);
$c = $cat[$prov];
[$letter, $cls] = $mark[$prov];
$hasOrders = $prov !== 'zoho_crm';
$events = integration_message_events($prov);
$stages = $crmOn ? crm_stages($cid) : [];
$log = db_all("SELECT * FROM integration_log WHERE integration_id=? ORDER BY id DESC LIMIT 80", [(int) $it['id']]);
$failed = db_all("SELECT * FROM integration_jobs WHERE integration_id=? AND status='failed' ORDER BY id DESC LIMIT 10", [(int) $it['id']]);
$queued = (int) db_val("SELECT COUNT(*) FROM integration_jobs WHERE integration_id=? AND status IN ('queued','running')", [(int) $it['id']]);
$links = db_all("SELECT kind, COUNT(*) n FROM integration_links WHERE integration_id=? GROUP BY kind", [(int) $it['id']]);
$linkN = array_column($links, 'n', 'kind');

page_head((string) ($it['display_name'] ?: $it['account']), '<a class="btn btn-ghost btn-sm" href="apps.php">← All apps</a>');
?>
<div class="card">
  <div class="ap-head" style="justify-content:space-between;flex-wrap:wrap">
    <div class="ap-head"><div class="ap-mark <?= $cls ?>" aria-hidden="true"><?= $letter ?></div>
      <div><span class="ap-group"><?= e($c['label']) ?> · <?= $it['auth_mode'] === 'key' ? 'connected with keys' : 'connected with the Connect button' ?></span>
        <h2 class="ltr" style="unicode-bidi:isolate"><?= e((string) ($it['display_name'] ?: $it['account'])) ?></h2>
        <span class="text-muted" style="font-size:12.5px"><?= e((string) $it['account']) ?> · last event <?= e($ago($it['last_event_at'])) ?> · last sync <?= e($ago($it['last_sync_at'])) ?><?= $queued ? ' · ' . $queued . ' waiting to sync' : '' ?></span></div></div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><?= $pill($it) ?>
      <?php if ($isAdmin): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><button class="btn btn-ghost btn-sm">Sync now</button></form>
        <form method="post" onsubmit="return confirm('Disconnect <?= e(addslashes((string) ($it['display_name'] ?: $it['account']))) ?>? Nothing more will sync or be sent. What already arrived stays.')"><?= csrf_field() ?><input type="hidden" name="action" value="disconnect"><button class="btn-link" style="color:var(--danger)">Disconnect</button></form>
      <?php endif; ?></div>
  </div>
  <?php if (!empty($it['last_error'])): ?><div class="alert error mt10" style="font-size:13px"><?= e((string) $it['last_error']) ?><?= $isAdmin ? ' — fix it, then press <strong>Sync now</strong>.' : '' ?></div><?php endif; ?>
  <div class="ap-stats mt10">
    <?php if ($prov === 'shopify'):
      $o = db_row("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM ext_orders WHERE integration_id=? AND kind='order' AND placed_at > NOW() - INTERVAL 30 DAY AND status NOT IN ('cancelled','refunded')", [(int) $it['id']]);
      $rc = db_row("SELECT COUNT(*) n, COALESCE(SUM(o.total),0) t FROM ext_orders c JOIN ext_orders o ON o.id=c.recovered_order_id WHERE c.integration_id=? AND c.kind='checkout' AND c.status='recovered' AND c.updated_at > NOW() - INTERVAL 30 DAY", [(int) $it['id']]);
      $cur = (string) ($s['currency'] ?? ''); ?>
      <div class="ap-stat"><b><?= (int) $o['n'] ?></b><span>Orders, 30 days</span></div>
      <div class="ap-stat"><b><?= e(trim($cur . ' ' . number_format((float) $o['t']))) ?></b><span>Sales, 30 days</span></div>
      <div class="ap-stat"><b><?= (int) $rc['n'] ?></b><span>Carts recovered by a reminder</span></div>
      <div class="ap-stat"><b><?= e(trim($cur . ' ' . number_format((float) $rc['t']))) ?></b><span>Recovered sales</span></div>
      <div class="ap-stat"><b><?= (int) ($linkN['customer'] ?? 0) ?></b><span>Customers linked to contacts</span></div>
    <?php elseif ($prov === 'woocommerce'):
      $o = db_row("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM ext_orders WHERE integration_id=? AND kind='order' AND placed_at > NOW() - INTERVAL 30 DAY AND status NOT IN ('cancelled','refunded','failed')", [(int) $it['id']]);
      $up = db_row("SELECT COUNT(*) n, SUM(status IN ('paid','shipped')) p FROM ext_orders WHERE integration_id=? AND kind='order' AND FIND_IN_SET('payment_pending', notified) AND placed_at > NOW() - INTERVAL 30 DAY", [(int) $it['id']]);
      $cur = (string) ($s['currency'] ?? ''); ?>
      <div class="ap-stat"><b><?= (int) $o['n'] ?></b><span>Orders, 30 days</span></div>
      <div class="ap-stat"><b><?= e(trim($cur . ' ' . number_format((float) $o['t']))) ?></b><span>Sales, 30 days</span></div>
      <div class="ap-stat"><b><?= (int) $up['p'] ?></b><span>Unpaid orders paid after a reminder</span></div>
      <div class="ap-stat"><b><?= (int) ($linkN['customer'] ?? 0) ?></b><span>Customers linked to contacts</span></div>
    <?php elseif ($prov === 'zoho_crm'): ?>
      <div class="ap-stat"><b><?= (int) ($linkN['lead'] ?? 0) ?></b><span>Leads linked</span></div>
      <div class="ap-stat"><b><?= (int) ($linkN['contact'] ?? 0) ?></b><span>Contacts linked</span></div>
      <div class="ap-stat"><b><?= (int) ($linkN['deal'] ?? 0) ?></b><span>Deals linked</span></div>
    <?php else:
      $inv = db_row("SELECT SUM(kind='invoice') i, SUM(kind='salesorder') so, SUM(kind='invoice' AND status='paid') p FROM ext_orders WHERE integration_id=?", [(int) $it['id']]); ?>
      <?php if ($prov === 'zoho_books'): ?><div class="ap-stat"><b><?= (int) $inv['i'] ?></b><span>Invoices</span></div><div class="ap-stat"><b><?= (int) $inv['p'] ?></b><span>Paid</span></div><?php endif; ?>
      <div class="ap-stat"><b><?= (int) $inv['so'] ?></b><span>Sales orders</span></div>
      <div class="ap-stat"><b><?= (int) ($linkN['customer'] ?? 0) ?></b><span>Customers linked to contacts</span></div>
    <?php endif; ?>
  </div>
</div>

<nav class="int-tabs" aria-label="Sections"><a href="#data">Data</a><?php if ($events): ?><a href="#messages">Messages</a><?php endif; ?><?php if ($hasOrders): ?><a href="#orders"><?= $prov === 'shopify' ? 'Orders & carts' : ($prov === 'woocommerce' ? 'Orders' : 'Documents') ?></a><?php endif; ?><a href="#log">Log</a><a href="#setup">Setup details</a></nav>

<!-- ═══ DATA ═══ -->
<div class="card" id="data">
  <h2>What syncs</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_data">
  <fieldset <?= $isAdmin ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
  <?php if (in_array($prov, ['shopify', 'woocommerce'], true)): ?>
    <label class="mod-all"><input type="checkbox" name="sync_customers" value="1" <?= !empty($s['sync_customers']) ? 'checked' : '' ?>> Add <?= e($c['label']) ?> customers to Contacts (matched by phone, then email)</label>
    <div class="grid2 mt10">
      <div class="field"><span class="lbl">Put in the CRM pipeline</span><select name="to_crm" <?= $crmOn ? '' : 'disabled' ?>>
        <?php foreach (['none' => 'Nobody — contacts only', 'buyers' => 'People who place an order', 'all' => 'Every customer'] as $k => $l): ?><option value="<?= $k ?>" <?= ($s['to_crm'] ?? 'buyers') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <?php if (!$crmOn): ?><small class="text-muted">The CRM is not part of your plan.</small><?php endif; ?></div>
      <div class="field"><span class="lbl">…in the stage</span><select name="crm_stage_id"><option value="0">The first stage</option>
        <?php foreach ($stages as $st): ?><option value="<?= (int) $st['id'] ?>" <?= (int) ($s['crm_stage_id'] ?? 0) === (int) $st['id'] ? 'selected' : '' ?>><?= e((string) $st['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><span class="lbl">On connecting, import orders from the last</span><select name="import_days">
        <?php foreach ([7, 30, 60, 90, 180, 365] as $d): ?><option value="<?= $d ?>" <?= (int) ($s['import_days'] ?? 60) === $d ? 'selected' : '' ?>><?= $d ?> days</option><?php endforeach; ?></select></div>
    </div>
    <p class="text-muted" style="font-size:12.5px"><?= $prov === 'woocommerce' ? 'WooCommerce does not record marketing consent, so its customers are not opted in to campaigns.' : 'Customers who agreed to marketing in Shopify are opted in for campaigns.' ?> Everyone else still gets their own order updates, but not campaigns. A buyer's lead shows their orders and what they have spent.</p>
  <?php elseif ($prov === 'zoho_crm'):
    $zf = (array) ($it['cursor']['fields'] ?? []);
    $leadStatus = (array) ($zf['Leads']['Lead_Status']['pick'] ?? []);
    $dealStages = (array) ($zf['Deals']['Stage']['pick'] ?? []);
    $opts = function (array $list, string $cur, string $none = '— not sent —'): string {
        $h = '<option value="">' . e($none) . '</option>';
        if ($cur !== '' && !in_array($cur, $list, true)) $list[] = $cur;
        foreach ($list as $v) $h .= '<option value="' . e($v) . '"' . ($v === $cur ? ' selected' : '') . '>' . e($v) . '</option>';
        return $h;
    }; ?>
    <div class="grid2">
      <div><label class="mod-all"><input type="checkbox" name="push_leads" value="1" <?= !empty($s['push_leads']) ? 'checked' : '' ?>> Send Revenect leads to Zoho as they are added and change</label>
        <label class="mod-all"><input type="checkbox" name="pull_leads" value="1" <?= !empty($s['pull_leads']) ? 'checked' : '' ?>> Bring Zoho leads and contacts into Revenect (new ones and edits)</label>
        <label class="mod-all"><input type="checkbox" name="deals" value="1" <?= !empty($s['deals']) ? 'checked' : '' ?>> Keep a Zoho deal for each won, lost or chosen-stage lead</label>
        <label class="mod-all"><input type="checkbox" name="notes" value="1" <?= !empty($s['notes']) ? 'checked' : '' ?>> Log WhatsApp, Messenger, Instagram and SMS conversations, notes, calls and visits on the Zoho record (every 15 minutes)</label></div>
      <div class="field"><span class="lbl">Revenect leads go to Zoho as</span><select name="module">
        <option value="Leads" <?= ($s['module'] ?? 'Leads') === 'Leads' ? 'selected' : '' ?>>Leads (converted to a contact and deal when won)</option>
        <option value="Contacts" <?= ($s['module'] ?? '') === 'Contacts' ? 'selected' : '' ?>>Contacts (with deals)</option></select></div>
    </div>
    <?php if (!$zf): ?><p class="alert info mt10" style="font-size:13px">Zoho's fields are still loading. Press <strong>Sync now</strong> if they do not appear in a minute.</p><?php endif; ?>
    <div class="lbl-sm">Stages</div>
    <div class="ap-map">
      <strong class="text-muted" style="font-size:12px">Revenect stage</strong><strong class="text-muted" style="font-size:12px">Zoho lead status · deal stage</strong>
      <?php foreach ($stages as $st): $k = (string) $st['id']; ?>
        <span><?= e((string) $st['name']) ?> <small class="text-muted">(<?= e((string) $st['kind']) ?>)</small></span>
        <span style="display:flex;gap:6px">
          <select name="stage_map[<?= e($k) ?>]" aria-label="Zoho lead status for <?= e((string) $st['name']) ?>"><?= $opts($leadStatus, (string) ($s['stage_map'][$k] ?? '')) ?></select>
          <?php if ($st['kind'] === 'open'): ?><select name="deal_stages[<?= e($k) ?>]" aria-label="Open a Zoho deal at stage"><?= $opts($dealStages, (string) ($s['deal_stages'][$k] ?? ''), '— no deal —') ?></select><?php endif; ?></span>
      <?php endforeach; ?>
      <span>Won → Zoho deal stage</span><select name="won_stage"><?= $opts($dealStages, (string) $s['won_stage'], 'Closed Won') ?></select>
      <span>Lost → Zoho deal stage</span><select name="lost_stage"><?= $opts($dealStages, (string) $s['lost_stage'], 'Closed Lost') ?></select>
    </div>
    <div class="lbl-sm">Fields</div>
    <p class="text-muted" style="font-size:12.5px;margin-top:0">Name, phone and email always go. The salesperson goes as the Zoho owner when they have a Zoho user with the same email.</p>
    <div class="ap-map" id="fm">
      <?php $ourF = zoho_revenect_fields($cid); $zl = array_merge((array) ($zf['Leads'] ?? []), (array) ($zf['Contacts'] ?? []));
        $rows = zoho_field_map($s) + ['' => ''];
        foreach ($rows as $ours => $theirs): ?>
        <select name="fm_ours[]" aria-label="Revenect field"><option value="">—</option><?php foreach ($ourF as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === $ours ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <input name="fm_theirs[]" value="<?= e((string) $theirs) ?>" list="zoho-fields" placeholder="Zoho field (API name)" dir="ltr" aria-label="Zoho field">
      <?php endforeach; ?>
    </div>
    <datalist id="zoho-fields"><?php foreach ($zl as $api => $f): ?><option value="<?= e((string) $api) ?>"><?= e((string) $f['label']) ?></option><?php endforeach; ?></datalist>
    <?php if ($isAdmin): ?><button type="button" class="btn btn-ghost btn-sm mt10" onclick="const m=document.getElementById('fm');const r=m.querySelectorAll('select,input');m.append(r[r.length-2].cloneNode(true),r[r.length-1].cloneNode(true));m.lastElementChild.value=''">+ Add a field</button><?php endif; ?>
  <?php else: ?>
    <label class="mod-all"><input type="checkbox" name="sync_customers" value="1" <?= !empty($s['sync_customers']) ? 'checked' : '' ?>> Add the customers on invoices and sales orders to Contacts</label>
    <p class="text-muted" style="font-size:12.5px">Checked every 10 minutes. What existed before you connected is recorded, but nobody is messaged about it.</p>
  <?php endif; ?>
  <?php if ($isAdmin): ?><button class="btn btn-primary btn-sm mt10">Save</button><?php endif; ?>
  </fieldset></form>
  <?php if ($prov === 'zoho_crm' && $isAdmin && $crmOn): ?>
    <form method="post" class="mt10" onsubmit="return confirm('Send every lead in your pipeline (up to 2,000, newest first) to Zoho?')"><?= csrf_field() ?><input type="hidden" name="action" value="push_all">
      <button class="btn btn-ghost btn-sm">Send all existing leads to Zoho</button> <span class="text-muted" style="font-size:12px">Only new changes go by themselves.</span></form>
  <?php endif; ?>
</div>

<?php if ($events):
  $tpls = crm_tpl_choices($cid);
  $tokens = ['first_name' => 'Customer: first name', 'name' => 'Customer: full name'];
  foreach (integration_order_vars() as $k => $ex) $tokens['{{' . $k . '}}'] = ucfirst(str_replace('_', ' ', $k));
  if (!function_exists('crm_sms_available')) require_once __DIR__ . '/../includes/crm_notify.php';
  $smsOk = crm_sms_available($CLIENT); ?>
<!-- ═══ MESSAGES ═══ -->
<div class="card" id="messages">
  <h2>Messages to customers</h2>
  <p class="text-muted" style="font-size:13px;margin-top:-4px">Each one goes once per order, at any hour (they are updates the customer is waiting for), from your WhatsApp Business API number — or by SMS. People who opted out are skipped.
    Variables: <?= implode(' ', array_map(fn($k) => '<code>{{' . $k . '}}</code>', array_merge(['first_name'], array_keys(integration_order_vars())))) ?></p>
  <form method="post" id="msgs"><?= csrf_field() ?><input type="hidden" name="action" value="save_messages">
  <fieldset <?= $isAdmin ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
  <?php foreach ($events as $ev => $label): $r = $s['messages'][$ev]; $n = "m[$ev]"; ?>
    <div class="ap-rule" data-off="<?= empty($r['on']) ? 1 : 0 ?>" data-ev="<?= e($ev) ?>">
      <div class="ap-rule-head"><label class="mod-all" style="margin:0"><input type="checkbox" name="<?= $n ?>[on]" value="1" <?= !empty($r['on']) ? 'checked' : '' ?> onchange="this.closest('.ap-rule').dataset.off=this.checked?0:1"> <strong><?= e($label) ?></strong></label>
        <span class="pill <?= !empty($r['on']) ? 'green' : 'gray' ?>"><?= !empty($r['on']) ? 'On' : 'Off' ?></span></div>
      <div class="ap-rule-body">
        <div class="grid2">
          <div class="field"><span class="lbl">Send by</span><select name="<?= $n ?>[send_by]" class="ap-by">
            <option value="whatsapp" <?= $r['send_by'] === 'whatsapp' ? 'selected' : '' ?>>WhatsApp template</option>
            <?php if ($smsOk): ?><option value="sms" <?= $r['send_by'] === 'sms' ? 'selected' : '' ?>>SMS</option>
            <option value="wa_then_sms" <?= $r['send_by'] === 'wa_then_sms' ? 'selected' : '' ?>>WhatsApp, SMS if it fails</option><?php endif; ?></select></div>
          <div class="field"><span class="lbl"><?= $ev === 'cart_abandoned' ? 'Send after the cart is left for (minutes)' : 'Wait before sending (minutes)' ?></span>
            <input type="number" name="<?= $n ?>[delay]" min="<?= $ev === 'cart_abandoned' ? 15 : 0 ?>" max="10080" value="<?= (int) $r['delay'] ?>"></div>
          <?php if ($ev === 'cart_abandoned'): ?><div class="field"><span class="lbl">Second reminder after (hours, 0 = none)</span><input type="number" name="<?= $n ?>[second_delay]" min="0" max="72" value="<?= (int) ($r['second_delay'] ?? 0) ?>"></div><?php endif; ?>
        </div>
        <div class="ap-wa">
          <div class="field"><span class="lbl">WhatsApp template</span><select name="<?= $n ?>[template_id]" class="ap-tpl"><option value="0">— choose an approved template —</option>
            <?php foreach ($tpls as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $r['template_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name'] . ' (' . $t['lang'] . ')') ?></option><?php endforeach; ?></select>
            <?php if (!$tpls): ?><small class="text-muted">No approved templates yet — make one under Templates.</small><?php endif; ?></div>
          <div class="ap-vars" data-vars='<?= e(json_encode(array_values((array) $r['vars']))) ?>'></div>
        </div>
        <div class="ap-sms field"><span class="lbl">SMS text</span><textarea name="<?= $n ?>[sms_text]" rows="2" maxlength="1000" class="ap-smstext"><?= e((string) $r['sms_text']) ?></textarea>
          <div class="ap-prev" aria-live="polite"></div></div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if ($isAdmin): ?><button class="btn btn-primary btn-sm">Save messages</button><?php endif; ?>
  </fieldset></form>
</div>
<script>
(function(){
  const TPLS = <?= json_encode(array_map(fn($t) => ['body' => $t['body'], 'text' => $t['text']], $tpls), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>;
  const TOK = <?= json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>;
  const EX = Object.assign({first_name:'Mona', name:'Mona Adel'}, <?= json_encode(integration_order_vars(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>);
  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  document.querySelectorAll('.ap-rule').forEach(rule => {
    const ev = rule.dataset.ev, by = rule.querySelector('.ap-by'), tpl = rule.querySelector('.ap-tpl'), box = rule.querySelector('.ap-vars');
    const sms = rule.querySelector('.ap-smstext'), prev = rule.querySelector('.ap-prev');
    let saved = []; try { saved = JSON.parse(box.dataset.vars || '[]'); } catch(e) {}
    const vars = () => {
      const t = TPLS[tpl.value]; const n = t ? t.body : 0;
      box.innerHTML = '';
      for (let i = 0; i < n; i++) {
        const cur = box.querySelectorAll('select')[i]?.value ?? saved[i] ?? (i === 0 ? 'first_name' : '{{order_number}}');
        box.insertAdjacentHTML('beforeend', `<label>{{${i+1}}}<select name="m[${ev}][vars][]">${Object.entries(TOK).map(([k,l]) => `<option value="${esc(k)}" ${k===cur?'selected':''}>${esc(l)}</option>`).join('')}</select></label>`);
      }
      if (t && t.text) box.insertAdjacentHTML('beforeend', `<div class="ap-prev" style="grid-column:1/-1">${esc(t.text)}</div>`);
    };
    const show = () => {
      const v = by.value; rule.querySelector('.ap-wa').hidden = v === 'sms'; rule.querySelector('.ap-sms').hidden = v === 'whatsapp';
      prev.textContent = sms.value.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (m, k) => EX[k] ?? m);
    };
    tpl.addEventListener('change', () => { saved = []; vars(); }); by.addEventListener('change', show); sms.addEventListener('input', show);
    vars(); show();
  });
})();
</script>
<?php endif; ?>

<?php if ($hasOrders):
  $orders = db_all("SELECT * FROM ext_orders WHERE integration_id=? ORDER BY COALESCE(placed_at, created_at) DESC, id DESC LIMIT 60", [(int) $it['id']]);
  $stPill = ['placed' => 'blue', 'paid' => 'green', 'shipped' => 'green', 'delivered' => 'green', 'cancelled' => 'red', 'refunded' => 'red', 'void' => 'gray',
             'open' => 'gray', 'abandoned' => 'gold', 'recovered' => 'green', 'completed' => 'gray', 'sent' => 'blue', 'overdue' => 'red'];
  $kindL = ['order' => 'Order', 'checkout' => 'Cart', 'invoice' => 'Invoice', 'salesorder' => 'Sales order']; ?>
<!-- ═══ ORDERS ═══ -->
<div class="card card-flush" id="orders">
  <div style="padding:14px 18px"><h2 style="margin:0;border:0;padding:0"><?= $prov === 'shopify' ? 'Orders & carts' : ($prov === 'woocommerce' ? 'Orders' : 'Invoices & sales orders') ?></h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">The newest 60. Messages already sent for each are listed under it.</p></div>
  <?php if (!$orders): ?><p class="text-muted" style="padding:0 18px 16px;margin:0">Nothing yet.</p><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Date</th><th>What</th><th>Customer</th><th>Total</th><th>Status</th><th>Sent</th></tr></thead>
    <tbody><?php foreach ($orders as $o): $sent = array_filter(explode(',', (string) $o['notified']), fn($x) => $x !== '' && !str_starts_with($x, 't:')); ?>
      <tr><td><?= $o['placed_at'] ? e(date('j M, H:i', strtotime((string) $o['placed_at']))) : '' ?></td>
        <td><?= e($kindL[$o['kind']] ?? $o['kind']) ?> <?= e((string) ($o['number'] ?? '')) ?><?php if ($o['items']): ?><small class="text-muted" style="display:block;max-width:280px"><?= e(mb_substr((string) $o['items'], 0, 120)) ?></small><?php endif; ?></td>
        <td><?php if ($o['contact_id']): ?><a href="<?= $crmOn && can_use('crm') ? 'crm_lead.php?id=' : 'inbox.php?contact=' ?><?= (int) $o['contact_id'] ?>"><?= e((string) ($o['name'] ?: 'Contact #' . $o['contact_id'])) ?></a><?php else: ?><?= e((string) ($o['name'] ?: '—')) ?><?php endif; ?></td>
        <td style="font-variant-numeric:tabular-nums"><?= $o['total'] !== null ? e(trim(($o['currency'] ?? '') . ' ' . number_format((float) $o['total'], 2))) : '' ?></td>
        <td><span class="pill <?= $stPill[$o['status']] ?? 'gray' ?>"><?= e(ucfirst((string) $o['status'])) ?></span></td>
        <td style="font-size:12px" class="text-muted"><?= $sent ? e(implode(', ', array_map(fn($x) => $events[$x] ?? str_replace('_', ' ', $x), $sent))) : '' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══ LOG ═══ -->
<div class="card card-flush" id="log">
  <div style="padding:14px 18px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><h2 style="margin:0;border:0;padding:0">Log</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">What came in from <?= e($c['label']) ?> and what went out to it — the newest 80.</p></div>
    <?php if ($failed && $isAdmin): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><button class="btn btn-ghost btn-sm"><?= count($failed) ?> failed — try again</button></form><?php endif; ?></div>
  <?php if ($failed): ?><div style="padding:0 18px 10px"><?php foreach ($failed as $f): ?><div class="text-muted" style="font-size:12.5px;color:var(--danger)"><?= e((string) $f['kind']) ?>: <?= e((string) $f['last_error']) ?></div><?php endforeach; ?></div><?php endif; ?>
  <?php if (!$log): ?><p class="text-muted" style="padding:0 18px 16px;margin:0">Nothing yet.</p><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>When</th><th></th><th>Event</th><th>What happened</th></tr></thead>
    <tbody><?php foreach ($log as $l): ?>
      <tr><td style="white-space:nowrap"><?= e(date('j M, H:i:s', strtotime((string) $l['created_at']))) ?></td>
        <td><span class="pill <?= !(int) $l['ok'] ? 'red' : ($l['direction'] === 'in' ? 'blue' : 'green') ?>"><?= $l['direction'] === 'in' ? 'In' : 'Out' ?></span></td>
        <td><code><?= e((string) $l['event']) ?></code></td><td><?= e((string) $l['summary']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>

<!-- ═══ SETUP ═══ -->
<div class="card" id="setup">
  <h2>Setup details</h2>
  <?php if ($prov === 'shopify'): ?>
    <p class="text-muted" style="font-size:13px">Shopify sends this store's orders, fulfilments, customers and checkouts to Revenect the moment they happen; every 15 minutes we also check for anything missed.
      Abandoned carts need the store's checkout to collect a phone number (Shopify → Settings → Checkout → Customer contact method).</p>
    <div class="int-copy"><code id="sh-hook"><?= e(shopify_webhook_url()) ?></code><button type="button" class="btn btn-ghost btn-sm" data-copy="sh-hook">Copy</button></div>
  <?php elseif ($prov === 'woocommerce'): ?>
    <p class="text-muted" style="font-size:13px">The store sends new and changed orders and customers to Revenect as they happen (WooCommerce → Settings → Advanced → Webhooks shows them); every 15 minutes we also check for anything missed.
      WooCommerce keeps no record of abandoned carts, so the reminder goes for orders left <strong>pending payment</strong>, with the store's own payment link. "Completed" is treated as shipped;
      a tracking link is included when a shipment-tracking plugin adds one to the order. Customers are not opted in to campaigns, because WooCommerce does not record marketing consent.</p>
    <div class="int-copy"><code id="woo-hook"><?= e(woo_webhook_url($it)) ?></code><button type="button" class="btn btn-ghost btn-sm" data-copy="woo-hook">Copy</button></div>
  <?php elseif ($prov === 'zoho_crm'): ?>
    <p class="text-muted" style="font-size:13px">Zoho tells Revenect about changes to leads, contacts and deals as they happen (renewed every few days), and we check every 5 minutes as well.
      Changes that came from Zoho are never sent back to it, so the two systems do not echo each other.</p>
  <?php else: ?>
    <p class="text-muted" style="font-size:13px">Revenect reads new and changed <?= $prov === 'zoho_books' ? 'invoices and sales orders' : 'sales orders' ?> every 10 minutes. Invoice links are sent only when Zoho gives a public link for the invoice.</p>
  <?php endif; ?>
  <p class="text-muted" style="font-size:12.5px;margin-bottom:0">Automations can start from <?= e($c['label']) ?> events too: Automations → a new automation → Trigger.</p>
</div>
<script>
document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => { const el = document.getElementById(b.dataset.copy); if (el) navigator.clipboard?.writeText(el.textContent.trim()).then(() => { b.textContent = 'Copied'; }); }));
</script>
<?php layout_footer();
