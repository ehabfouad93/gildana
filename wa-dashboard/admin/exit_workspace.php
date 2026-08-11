<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';   // require_admin()

$id = (int) ($_SESSION['impersonate_client_id'] ?? 0);
unset($_SESSION['impersonate_client_id']);
redirect($id ? ('client.php?id=' . $id) : 'index.php');
