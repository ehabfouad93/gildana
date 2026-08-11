<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';   // require_admin()

$id = (int) ($_GET['id'] ?? 0);
$client = db_row("SELECT id FROM clients WHERE id = ?", [$id]);
if (!$client) { http_response_code(404); exit('Client not found.'); }

$_SESSION['impersonate_client_id'] = $id;
redirect('../client/index.php');
