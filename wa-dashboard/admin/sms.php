<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/sms_forms.php';
require_once __DIR__ . '/../includes/charts.php';

/**
 * Platform SMS gateways: the provider accounts clients send through unless they bring their own.
 * Which gateway, sender names and credits per part each client gets are set on the client's page.
 */
if (!sms_ready()) { layout_header('SMS gateways', 'admin', 'sms'); echo '<div class="alert error">Run migration 064 first.</div>'; layout_footer(); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'public_ip') {
        $ip = trim((string) ($_POST['ip'] ?? ''));
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) { flash('Enter an IP address like 203.0.113.10.', 'error'); redirect('sms.php'); }
        setting_set('sms_public_ip', $ip);
        flash($ip !== '' ? 'Saved — the mShastra guide and gateway cards now show ' . $ip . ' to whitelist.' : 'Server IP cleared.');
        redirect('sms.php');
    }
    $r = sms_gateway_handle(null, $_POST);
    if ($r) { flash($r[0], $r[1]); redirect('sms.php' . (!empty($r[2]) ? '#gw' . (int) $r[2] : '')); }
}

$gws = array_map('sms_gateway_load', db_all("SELECT * FROM sms_gateways WHERE client_id IS NULL ORDER BY is_default DESC, name"));
$edit = isset($_GET['edit']) ? sms_gateway_load(db_row("SELECT * FROM sms_gateways WHERE id=? AND client_id IS NULL", [(int) $_GET['edit']]) ?: null) : null;
$adding = isset($_GET['new']) || !$gws;
$use = [];
// Who actually sends through each gateway — the client admin's own choice included.
foreach (db_all("SELECT * FROM clients WHERE status='active'") as $c) {
    if (!in_array('sms', client_modules($c), true)) continue;
    $g = sms_client_gateway($c);
    if ($g && $g['client_id'] === null) $use[(int) $g['id']] = ($use[(int) $g['id']] ?? 0) + 1;
}
$own = (int) db_val("SELECT COUNT(DISTINCT client_id) FROM sms_gateways WHERE client_id IS NOT NULL");
$P = viz_period(['p' => '30d']);
$sent30 = db_row("SELECT COUNT(*) n, SUM(status IN ('sent','delivered')) ok, SUM(status IN ('failed','undelivered')) bad, SUM(parts) parts, SUM(IF(status IN ('sent','delivered'), credits, 0)) cr
                    FROM sms_messages WHERE created_at BETWEEN ? AND ?", [$P['start'], $P['end']]) ?: [];

layout_header('SMS gateways', 'admin', 'sms');
page_head('SMS gateways', $gws && !$adding && !$edit ? '<a class="btn btn-primary btn-sm" href="sms.php?new=1#form">+ Add gateway</a>' : '');
?>
<div class="viz-kpis">
  <?= viz_kpi('SMS sent (30 days)', (int) ($sent30['ok'] ?? 0)) ?>
  <?= viz_kpi('Failed (30 days)', (int) ($sent30['bad'] ?? 0)) ?>
  <?= viz_kpi('Parts (30 days)', (int) ($sent30['parts'] ?? 0)) ?>
  <?= viz_kpi('Credits charged', (int) ($sent30['cr'] ?? 0)) ?>
  <?= viz_kpi('Clients with their own provider', $own) ?>
</div>

<p class="text-muted" style="font-size:13px;max-width:760px">Clients send through the default gateway unless their page names another, or they are allowed their own provider.
  Each client's approved sender names and credits per SMS part are set on the client's page (SMS card). Sender names must be registered with the provider first.</p>

<form method="post" class="card" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="public_ip">
  <div class="field" style="margin:0;flex:1 1 280px"><span class="lbl">This server's public IP (for providers that whitelist IPs, like mShastra)</span>
    <input type="text" name="ip" value="<?= e(sms_public_ip()) ?>" placeholder="e.g. 203.0.113.10 — on the server run: curl -4 ifconfig.me"></div>
  <button class="btn btn-ghost btn-sm">Save</button>
</form>

<?php foreach ($gws as $gw): ?>
  <div class="card" id="gw<?= (int) $gw['id'] ?>">
    <?= sms_gateway_card($gw, 'sms.php?edit=' . (int) $gw['id'] . '#form') ?>
    <p class="text-muted" style="font-size:12px;margin:8px 0 0"><?= (int) ($use[(int) $gw['id']] ?? 0) ?> client(s) with SMS send through it.</p>
  </div>
<?php endforeach; ?>

<?php if ($adding || $edit): ?>
  <div class="card" id="form">
    <h2><?= $edit ? 'Edit ' . e((string) $edit['name']) : 'Add an SMS gateway' ?></h2>
    <?= sms_gateway_form($edit) ?>
  </div>
<?php endif; ?>
<?php layout_footer(); ?>
