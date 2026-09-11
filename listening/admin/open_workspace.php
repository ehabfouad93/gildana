<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Enter a client's workspace as an admin.
 *
 * A CSRF token is required even though this is a GET link: it changes what the
 * rest of the session can see and write, so it must not be triggerable by an
 * <img> tag on another site.
 */
if (!hash_equals(csrf_token(), (string) ($_GET['csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid security token.');
}

$id     = (int) ($_GET['id'] ?? 0);
$client = db_row("SELECT id FROM clients WHERE id = ?", [$id]);
if (!$client) { http_response_code(404); exit('Client not found.'); }

$_SESSION['impersonate_client_id'] = $id;
redirect('../client/index.php');
