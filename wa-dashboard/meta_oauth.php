<?php
declare(strict_types=1);

/**
 * Where Facebook sends the browser back after the client approves the app for their Pages.
 *
 * This exact URL must be listed under "Valid OAuth Redirect URIs" in the Meta app's Facebook Login
 * settings. When it is not, Facebook refuses the exchange with a message that does not say which
 * URL it wanted — so the error below prints it.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/meta_leads.php';

$row = meta_take_state((string) ($_GET['state'] ?? ''));    // single use: a replayed callback finds nothing

function meta_done(string $title, string $message, bool $ok): void
{
    http_response_code($ok ? 200 : 400);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($title) . '</title>'
       . '<link rel="stylesheet" href="assets/dashboard.css"></head><body style="padding:40px 16px">'
       . '<div class="card" style="max-width:560px;margin:0 auto"><h2>' . e($title) . '</h2>'
       . '<div class="alert ' . ($ok ? 'success' : 'error') . '" style="font-size:13px">' . $message . '</div>'
       . '<a class="btn btn-primary" href="client/meta_leads.php">Back to lead forms</a></div></body></html>';
    exit;
}

if (!$row) {
    meta_done('Connection expired', 'That Facebook sign-in link has already been used or is over an hour old. Start again.', false);
}
if (($err = (string) ($_GET['error'] ?? '')) !== '') {
    meta_done('Not connected', $err === 'access_denied'
        ? 'You cancelled on the Facebook screen — nothing was changed.'
        : 'Facebook reported: <strong>' . e((string) ($_GET['error_description'] ?? $err)) . '</strong>', false);
}
$code = (string) ($_GET['code'] ?? '');
if ($code === '') meta_done('Not connected', 'Facebook did not send an authorisation code.', false);

$res = meta_finish_connect((int) $row['client_id'], $code);
if (empty($res['ok'])) {
    $hint = stripos((string) $res['error'], 'redirect') !== false
        ? '<br><br>The redirect URI registered in the Meta app must be exactly:<br><span class="mono">'
          . e(meta_redirect_uri()) . '</span>'
        : '';
    meta_done('Could not connect', e((string) $res['error']) . $hint, false);
}
meta_done('Facebook connected', (int) $res['pages'] . ' Page' . ((int) $res['pages'] === 1 ? '' : 's')
    . ' found. Choose which ones should send their leads to your CRM.', true);
