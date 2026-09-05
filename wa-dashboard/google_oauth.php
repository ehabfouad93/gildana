<?php
declare(strict_types=1);

/**
 * Where Google sends the browser back after the client approves.
 *
 * This URL must be registered verbatim in the Cloud console as an authorised redirect URI —
 * Google refuses the exchange otherwise, and that mismatch is the single most common reason a
 * connection fails, so the error below says so rather than just relaying "redirect_uri_mismatch".
 */

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/google.php';

$state = (string) ($_GET['state'] ?? '');
$row   = google_take_state($state);       // single use: a replayed callback finds nothing

function google_done(string $title, string $message, string $back, bool $ok): void
{
    http_response_code($ok ? 200 : 400);
    $cls = $ok ? 'success' : 'error';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($title) . '</title>'
       . '<link rel="stylesheet" href="assets/dashboard.css"></head><body style="padding:40px">'
       . '<div class="card" style="max-width:560px;margin:0 auto">'
       . '<h2>' . e($title) . '</h2>'
       . '<div class="alert ' . $cls . '" style="font-size:13px">' . $message . '</div>'
       . '<a class="btn btn-primary" href="' . e($back) . '">Back to the dashboard</a>'
       . '</div></body></html>';
    exit;
}

$back = 'client/settings.php#google';
if ($row && !empty($row['return_to'])) $back = (string) $row['return_to'];

if (!$row) {
    google_done('Connection expired', 'That sign-in link has already been used or is over an hour old. '
        . 'Start again from the dashboard.', 'client/settings.php#google', false);
}

// The user declined, or Google refused before we ever saw a code.
$err = (string) ($_GET['error'] ?? '');
if ($err !== '') {
    google_done('Not connected', $err === 'access_denied'
        ? 'You cancelled at the Google screen — nothing was changed.'
        : 'Google reported: <strong>' . e($err) . '</strong>', $back, false);
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') google_done('Not connected', 'Google did not send an authorisation code.', $back, false);

$res = google_finish_connect((int) $row['client_id'], $code);
if (empty($res['ok'])) {
    $hint = stripos($res['error'], 'redirect_uri') !== false
        ? '<br><br>The redirect URI registered in Google Cloud must be exactly:<br><span class="mono">'
          . e(google_redirect_uri()) . '</span>'
        : '';
    google_done('Could not connect', e($res['error']) . $hint, $back, false);
}

google_done('Google connected',
    'Connected as <strong>' . e((string) ($res['email'] ?: 'your Google account')) . '</strong>. '
    . 'You can now choose a spreadsheet.', $back, true);
