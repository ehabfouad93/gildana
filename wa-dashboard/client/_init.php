<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/channel.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/crm.php';        // the bell's CRM notices and the CRM menu's counts, on every page

/** @var array $ME, array $CLIENT */
[$ME, $CLIENT] = require_client();

/* The user's row, read from the database on every request rather than trusted from the session.
   A role or module change must bite on the next click: if it only applied at the next login, a
   salesperson who has just been demoted or removed would keep their old access for as long as
   their session lived. */
$PERM_USER = current_user_full() ?: $ME;
if (($ME['role'] ?? '') !== 'admin'
    && (empty($PERM_USER['id']) || ($PERM_USER['status'] ?? 'active') !== 'active')) {
    logout();
    redirect('../login.php');
}

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

/* ── Access: one gate for every client page ──
   Every client page loads this file, so enforcing here means no page can forget to. */
(function () use ($CLIENT) {
    $user = $GLOBALS['PERM_USER'];
    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $ajax = !empty($_POST['ajax']) || !empty($_GET['ajax'])
         || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

    $refuse = function (int $code, string $title, string $why) use ($ajax, $CLIENT): void {
        http_response_code($code);
        if ($ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $why]); exit; }
        client_header($title, '', $CLIENT);
        echo '<div class="card" style="max-width:560px"><h2 style="margin-top:0">' . e($title) . '</h2>'
           . '<p class="text-muted">' . e($why) . '</p>'
           . '<a class="btn btn-ghost" href="index.php">&larr; Back to the dashboard</a></div>';
        layout_footer();
        exit;
    };

    $why = page_denied($page, $user, $CLIENT);
    if ($why === 'plan') {
        $refuse(403, 'Not included in your plan',
            'This part of the app is not switched on for your account. Ask ' . BRAND_PARENT . ' to add it.');
    }
    if ($why === 'role') {
        $refuse(403, 'Not available for your role',
            'Your account admin has not given you access to this. Ask them to add it on the Team page.');
    }

    // Viewers can look but never change or send. Refusing every POST here, rather than asking
    // each form to check, is what makes that true everywhere including pages added later.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !can_write()) {
        $refuse(403, 'View-only access', 'Your role can view this account but not change anything or send messages.');
    }
})();
