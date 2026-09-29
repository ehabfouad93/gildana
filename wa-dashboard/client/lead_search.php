<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm_list.php';
require_once __DIR__ . '/../includes/crm_control.php';

/**
 * Leads for the Ctrl+K search: by name, phone, email or lead code. The same filter the leads list
 * uses, so a salesperson finds only the leads they can see, and a hidden number stays hidden.
 */
header('Content-Type: application/json; charset=UTF-8');
$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'leads' => []]); exit; }

[$w, $p] = crm_list_where((int) $CLIENT['id'], crm_list_filters(['q' => $q]));
$rows = db_all("SELECT c.id, c.name, c.phone_e164, s.name AS stage" . (db_has_column('contacts', 'code') ? ', c.code' : ", '' AS code")
             . " FROM contacts c JOIN crm_stages s ON s.id = c.stage_id WHERE $w ORDER BY c.id DESC LIMIT 8", $p);
$out = [];
foreach ($rows as $r) {
    $out[] = [
        'name'  => (string) ($r['name'] ?: 'No name'),
        'phone' => crm_phone_show((string) $r['phone_e164']),
        'code'  => (string) ($r['code'] ?? ''),
        'stage' => function_exists('t') ? t((string) $r['stage']) : (string) $r['stage'],
        'href'  => 'crm_lead.php?id=' . (int) $r['id'],
    ];
}
echo json_encode(['ok' => true, 'leads' => $out]);
