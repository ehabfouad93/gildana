<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/../includes/inbox.php';

$clients = db_all("SELECT id, name FROM clients ORDER BY name");
$cid = (int) ($_GET['client'] ?? 0);
if (!$cid && $clients) $cid = (int) $clients[0]['id'];
$target = $cid ? db_row("SELECT * FROM clients WHERE id=?", [$cid]) : null;

// Live AJAX for the selected client — handled + exits before any output.
if ($target) inbox_handle_ajax($target);

layout_header('Inbox', 'admin', 'inbox');
$picker = '<select onchange="location=\'inbox.php?client=\'+this.value" style="padding:7px 10px;border:1px solid var(--line,#e5e2dc);border-radius:8px">';
foreach ($clients as $c) $picker .= '<option value="' . (int) $c['id'] . '"' . ((int) $c['id'] === $cid ? ' selected' : '') . '>' . e((string) $c['name']) . '</option>';
$picker .= '</select>';
page_head('Inbox', $picker);

if (!$target) {
    echo '<div class="alert info">No clients yet.</div>';
    layout_footer();
    exit;
}
$IB_ENDPOINT = 'inbox.php?client=' . $cid;
require __DIR__ . '/../includes/inbox_view.php';
layout_footer();
