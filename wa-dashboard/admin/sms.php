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
    $r = sms_gateway_handle(null, $_POST);
    if ($r) { flash($r[0], $r[1]); redirect('sms.php' . (!empty($r[2]) ? '#gw' . (int) $r[2] : '')); }
}

$gws = array_map('sms_gateway_load', db_all("SELECT * FROM sms_gateways WHERE client_id IS NULL ORDER BY is_default DESC, name"));
$edit = isset($_GET['edit']) ? sms_gateway_load(db_row("SELECT * FROM sms_gateways WHERE id=? AND client_id IS NULL", [(int) $_GET['edit']]) ?: null) : null;
$adding = isset($_GET['new']) || !$gws;
$use = [];
foreach (db_all("SELECT COALESCE(c.sms_gateway_id, 0) g, COUNT(*) n FROM clients c WHERE c.sms_mode='platform' GROUP BY g") as $r) $use[(int) $r['g']] = (int) $r['n'];
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

<?php foreach ($gws as $gw): ?>
  <div class="card" id="gw<?= (int) $gw['id'] ?>">
    <?= sms_gateway_card($gw, 'sms.php?edit=' . (int) $gw['id'] . '#form') ?>
    <p class="text-muted" style="font-size:12px;margin:8px 0 0"><?= (int) ($use[(int) $gw['id']] ?? 0) + ((int) $gw['is_default'] ? (int) ($use[0] ?? 0) : 0) ?> client(s) send through it.</p>
  </div>
<?php endforeach; ?>

<?php if ($adding || $edit): ?>
  <div class="card" id="form">
    <h2><?= $edit ? 'Edit ' . e((string) $edit['name']) : 'Add an SMS gateway' ?></h2>
    <?= sms_gateway_form($edit) ?>
  </div>
<?php endif; ?>
<?php layout_footer(); ?>
