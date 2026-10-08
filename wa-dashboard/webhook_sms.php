<?php
declare(strict_types=1);

/**
 * Delivery reports from SMS providers: webhook_sms.php?g=<gateway id>&t=<that gateway's token>.
 *
 * The token is per gateway and secret, so nobody can mark messages delivered without it, and a
 * report can only touch messages sent through that same gateway. Twilio posts MessageSid and
 * MessageStatus; other providers send an id and a status (field names configurable per gateway).
 * Accepts GET or POST, form fields or JSON. Always answers 200 for a valid token so providers do
 * not retry forever over a message we no longer know.
 */
require_once __DIR__ . '/includes/config_loader.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/crm_integrations.php';

header('Content-Type: text/plain; charset=UTF-8');
$gw = sms_gateway((int) ($_GET['g'] ?? 0));
if (!$gw || !hash_equals((string) $gw['dlr_token'], (string) ($_GET['t'] ?? ''))) { http_response_code(403); exit('Forbidden'); }

$in = $_GET + $_POST;
$raw = (string) file_get_contents('php://input');
if ($raw !== '' && ($j = json_decode($raw, true)) && is_array($j)) {
    // Some providers send a list of reports at once.
    $list = array_is_list($j) ? $j : [$j];
} else {
    $list = [$in];
}
$n = 0;
foreach (array_slice($list, 0, 500) as $one) {
    if (!is_array($one)) continue;
    $d = sms_provider_dlr($gw, $one + array_diff_key($in, ['g' => 1, 't' => 1]));
    if ($d && sms_apply_dlr($gw, $d[0], $d[1], $d[2])) $n++;
}
echo 'OK ' . $n;
