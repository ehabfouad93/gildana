<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';
require_once __DIR__ . '/../includes/automation.php';

/**
 * An automation's Data: every row its "Export data" steps sent — to a sheet, the CRM, another
 * system or email — kept here too, so nothing gathered in a conversation is ever only in one place.
 */
$cid  = (int) $CLIENT['id'];
$id   = (int) ($_GET['id'] ?? 0);
$flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=?", [$id, $cid]);
if (!$flow) { http_response_code(404); exit('Automation not found.'); }

$ch   = (string) ($_GET['ch'] ?? '');
$from = (string) ($_GET['from'] ?? '');
$to   = (string) ($_GET['to'] ?? '');
$w = "e.client_id=? AND e.flow_id=?"; $p = [$cid, $id];
if (in_array($ch, ['whatsapp', 'messenger', 'instagram'], true)) { $w .= " AND e.channel=?"; $p[] = $ch; }
if ($from !== '' && strtotime($from)) { $w .= " AND e.created_at >= ?"; $p[] = date('Y-m-d 00:00:00', strtotime($from)); }
if ($to !== '' && strtotime($to))     { $w .= " AND e.created_at <= ?"; $p[] = date('Y-m-d 23:59:59', strtotime($to)); }
// A salesperson sees what came from their own leads only.
[$scope, $sp] = crm_scope('c');
$rows = db_all("SELECT e.*, c.name cname FROM flow_exports e LEFT JOIN contacts c ON c.id=e.contact_id WHERE $w" . ($scope ? " AND (e.contact_id IS NULL OR 1=1 $scope)" : '') . " ORDER BY e.id DESC LIMIT 5000", array_merge($p, $sp));

// Every column that appears in any row, in the order first seen.
$cols = [];
foreach (array_reverse($rows) as $r) foreach (array_keys(json_decode((string) $r['data'], true) ?: []) as $k) $cols[$k] = true;
$cols = array_keys($cols);

if (($_GET['export'] ?? '') !== '') {
    $head = array_merge(['When', 'Channel'], $cols, ['Sent to']);
    $out = [];
    foreach ($rows as $r) {
        $d = json_decode((string) $r['data'], true) ?: [];
        $line = [(string) $r['created_at'], ucfirst((string) $r['channel'])];
        foreach ($cols as $k) $line[] = (string) ($d[$k] ?? '');
        $line[] = (string) $r['sent_to'];
        $out[] = $line;
    }
    $fname = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $flow['name']) . '-' . date('Y-m-d');
    if ($_GET['export'] === 'xlsx' && function_exists('crm_xlsx')) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fname . '.xlsx"');
        echo crm_xlsx($head, $out, 'Data');
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '.csv"');
    echo "\xEF\xBB\xBF" . implode(',', array_map('csv_cell', $head)) . "\n";
    foreach ($out as $l) echo implode(',', array_map('csv_cell', $l)) . "\n";
    exit;
}

$q = fn(array $set) => 'automation_data.php?' . http_build_query(array_filter($set + ['id' => $id, 'ch' => $ch, 'from' => $from, 'to' => $to], 'strlen'));
client_header('Data · ' . $flow['name'], 'automations', $CLIENT);
page_head('Data — ' . $flow['name'], '<a class="btn btn-ghost btn-sm" href="automation_edit.php?id=' . $id . '">← Back to the automation</a>'
    . '<a class="btn btn-ghost btn-sm" href="' . e($q(['export' => 'csv'])) . '">CSV</a><a class="btn btn-primary btn-sm" href="' . e($q(['export' => 'xlsx'])) . '">Excel</a>');
?>
<div class="card card-flush">
  <form method="get" style="padding:14px 18px;display:flex;gap:8px;flex-wrap:wrap;align-items:end">
    <input type="hidden" name="id" value="<?= $id ?>">
    <label class="field" style="margin:0"><span class="lbl">Channel</span><select name="ch"><option value="">All</option>
      <?php foreach (['whatsapp' => 'WhatsApp', 'messenger' => 'Messenger', 'instagram' => 'Instagram'] as $k => $l): ?><option value="<?= $k ?>" <?= $ch === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label class="field" style="margin:0"><span class="lbl">From</span><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="field" style="margin:0"><span class="lbl">To</span><input type="date" name="to" value="<?= e($to) ?>"></label>
    <button class="btn btn-ghost btn-sm">Show</button>
    <span class="text-muted" style="margin-inline-start:auto;font-size:12.5px"><?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?></span>
  </form>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>When</th><th>Channel</th><?php foreach ($cols as $k): ?><th><?= e($k) ?></th><?php endforeach; ?><th>Sent to</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="<?= count($cols) + 3 ?>"><div class="empty">Nothing yet. Add an <strong>Export data</strong> step to the automation — each conversation that reaches it adds a row here.</div></td></tr><?php endif; ?>
    <?php foreach (array_slice($rows, 0, 500) as $r): $d = json_decode((string) $r['data'], true) ?: []; ?>
      <tr><td class="text-muted" style="white-space:nowrap"><?= e(date('j M, H:i', strtotime((string) $r['created_at']))) ?></td>
        <td><?= e(ucfirst((string) $r['channel'])) ?></td>
        <?php foreach ($cols as $k): ?><td style="max-width:280px;white-space:pre-wrap;font-size:12.5px"><?= e(mb_strimwidth((string) ($d[$k] ?? ''), 0, 300, '…')) ?></td><?php endforeach; ?>
        <td class="text-muted" style="font-size:12px"><?= e((string) $r['sent_to']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php layout_footer(); ?>
