<?php
declare(strict_types=1);

/**
 * Zoho CRM's Notifications API calls this when a Lead, Contact or Deal changes
 * (registered per connection by zoho_job('watch'), URL ?t=<connection's random token>).
 *
 * The token in the URL picks the connection and must match the token Zoho echoes in the body;
 * the body only carries record ids, so each record is read back from Zoho with the connection's
 * own access before anything changes in Revenect.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/integrations.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$t = (string) ($_GET['t'] ?? '');
if (!preg_match('/^[0-9a-f]{48}$/', $t) || !integrations_ready()) { http_response_code(401); exit; }
$row = db_row("SELECT * FROM integrations WHERE hook_token=? AND provider='zoho_crm' AND status IN ('active','error')", [$t]);
$body = json_decode((string) file_get_contents('php://input', false, null, 0, 1024 * 1024), true);
if (!$row || !is_array($body) || !hash_equals(substr($t, 0, 50), (string) ($body['token'] ?? ''))) { http_response_code(401); exit; }

$it = integration_load($row);
$module = (string) ($body['module'] ?? '');
if (!in_array($module, ['Leads', 'Contacts', 'Deals'], true)) { http_response_code(200); exit; }
$op = (string) ($body['operation'] ?? 'update');
$n = 0;
foreach (array_slice((array) ($body['ids'] ?? []), 0, 100) as $id) {
    if (!preg_match('/^\d{1,30}$/', (string) $id)) continue;
    integration_job($it, 'pull', (int) $id, ['module' => $module, 'operation' => $op]);
    $n++;
}
http_response_code(200);
echo 'ok';
if ($n && function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); integration_jobs_tick(20); }
