<?php
declare(strict_types=1);

/**
 * Zoho sends the browser here after the client approves the platform's Zoho client.
 * Register this address as the client's Authorized Redirect URI in the Zoho API console
 * (Admin → Settings → Zoho client shows it), and enable multi-DC so clients in every data centre can connect.
 *
 * Checked: the single-use state (which also says which client, which Zoho app and data centre),
 * and the accounts server Zoho names must be one of Zoho's own.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/integrations.php';

if (!integrations_ready() || !zoho_configured()) integration_done_page('Zoho is not set up', 'The platform\'s Zoho client is not configured yet.', false);
$r = zoho_finish_oauth($_GET);
if (empty($r['ok'])) integration_done_page('Could not connect Zoho', e((string) $r['error']), false);
integration_done_page('Zoho connected', '<strong>' . e((string) $r['name']) . '</strong> is connected. Choose what syncs under <strong>Data</strong>.', true, 'client/apps.php?app=' . (int) $r['id']);
