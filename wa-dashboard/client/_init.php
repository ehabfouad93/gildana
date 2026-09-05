<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/view.php';
require __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/channel.php';
require __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/billing.php';

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

/**
 * May this client edit their own WhatsApp API credentials?
 *
 * No, when they are sending under the platform's WhatsApp account. wa_token()
 * (includes/whatsapp.php:24) prefers a client's OWN token whenever one is set, so a
 * platform-mode client pasting a token would quietly move themselves onto their own Meta
 * billing: the operator would stop being billed by Meta for them, the cost-plus-markup
 * charging in includes/billing.php would stop matching reality, and nothing would error.
 *
 * The personal channel is a different case — the fields are unused there but still theirs, so
 * that screen shows them dimmed rather than hiding them, exactly as the admin screen does.
 */
function client_may_edit_credentials(array $client): bool
{
    return !billing_on_platform_waba($client);
}

/** True once the client can actually send — which depends on their channel. */
function client_ready(array $client): bool
{
    // A personal-number client has no Cloud credentials at all; readiness is whether they
    // have linked their phone. Checking the Cloud fields here used to lock them out of
    // creating campaigns entirely.
    if (function_exists('channel_is_personal') && channel_is_personal($client)) {
        return ($client['personal_status'] ?? '') === 'connected';
    }
    return $client['access_token_enc'] && $client['phone_number_id'];
}
