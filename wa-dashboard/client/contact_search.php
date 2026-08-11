<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid     = (int) $CLIENT['id'];
$q       = trim((string) ($_GET['q'] ?? ''));
$exclude = (int) ($_GET['exclude_list'] ?? 0);

if ($q === '') json_out([]);

$sql = "SELECT c.id, c.phone_e164 AS phone, c.name
          FROM contacts c
         WHERE c.client_id = ?
           AND (c.phone_e164 LIKE ? OR c.name LIKE ?)";
$params = [$cid, "%$q%", "%$q%"];

if ($exclude) {
    $sql .= " AND c.id NOT IN (SELECT contact_id FROM contact_list_members WHERE list_id = ?)";
    $params[] = $exclude;
}
$sql .= " ORDER BY c.id DESC LIMIT 15";

json_out(db_all($sql, $params));
