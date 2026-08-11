<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/view.php';
require __DIR__ . '/../includes/whatsapp.php';
require __DIR__ . '/../includes/credits.php';

/** @var array $ME, array $CLIENT */
[$ME, $CLIENT] = require_client();

/** Credits chip for the topbar. */
function credits_chip(array $client): string
{
    $bal = (int) $client['credits_balance'];
    $cls = $bal < 100 ? 'credits-chip low' : 'credits-chip';
    return '<span class="' . $cls . '">' . number_format($bal) . ' credits</span>';
}

/** Client-scoped header (adds the credits chip + impersonation banner). */
function client_header(string $title, string $active, array $client): void
{
    layout_header($title, 'client', $active, ['credits_html' => credits_chip($client)]);
    if (is_impersonating()) {
        echo '<div class="impersonate-bar">'
           . 'Managing <strong>' . e((string) $client['name']) . '</strong> as admin — changes affect this client\'s live account. '
           . '<a href="../admin/exit_workspace.php">Exit to admin →</a>'
           . '</div>';
    }
}

/** True once the client has the minimum credentials to send. */
function client_ready(array $client): bool
{
    return $client['access_token_enc'] && $client['phone_number_id'];
}
