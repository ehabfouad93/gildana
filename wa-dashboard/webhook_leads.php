<?php
declare(strict_types=1);

/**
 * Meta calls this when someone submits a Facebook or Instagram lead form on a connected Page.
 *
 * Register it in the Meta app under Webhooks → Page → subscribe to `leadgen`, with the same verify
 * token as the WhatsApp webhook. The payload carries only ids — the answers are fetched with the
 * Page's own token in meta_process_lead(), so nothing sensitive rides on this request.
 *
 * Every POST must be signed with the platform Meta app's secret. Unlike the WhatsApp webhook there
 * is no "unsigned is accepted for now" mode: this endpoint creates leads and notifies salespeople,
 * and there has never been a version of it that anyone depended on without signatures.
 */

require_once __DIR__ . '/includes/config_loader.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/meta_leads.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode  = (string) ($_GET['hub_mode'] ?? '');
    $token = (string) ($_GET['hub_verify_token'] ?? '');
    if ($mode === 'subscribe' && hash_equals((string) config('webhook_verify_token'), $token)) {
        header('Content-Type: text/plain');
        echo (string) ($_GET['hub_challenge'] ?? '');
        exit;
    }
    http_response_code(403);
    exit('Verification failed');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$raw    = (string) file_get_contents('php://input');
$secret = meta_cfg()['app_secret'];
$sig    = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($secret === '' || $sig === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig)) {
    http_response_code(401);
    exit('Bad signature');
}

$data = json_decode($raw, true);
if (!is_array($data) || ($data['object'] ?? '') !== 'page') { http_response_code(200); exit('ignored'); }

$handled = 0;
foreach ((array) ($data['entry'] ?? []) as $entry) {
    foreach ((array) ($entry['changes'] ?? []) as $change) {
        if (($change['field'] ?? '') !== 'leadgen') continue;
        $v = (array) ($change['value'] ?? []);
        $pageId = (string) ($v['page_id'] ?? $entry['id'] ?? '');
        $leadId = (string) ($v['leadgen_id'] ?? '');
        if ($pageId === '' || $leadId === '') continue;
        /* The same Page can be connected by more than one account (an agency and its client).
           Each gets the lead in its own CRM; the dedupe key is per account. */
        foreach (db_all("SELECT * FROM meta_pages WHERE page_id=? AND subscribed=1", [$pageId]) as $page) {
            meta_process_lead($page, $leadId, 'webhook', null, (string) ($v['form_id'] ?? ''));
            $handled++;
        }
    }
}
// Always 200 once the signature is good: Meta retries non-200s for hours, and a lead we could not
// read has been released for the poller to pick up, so a retry storm would only add noise.
http_response_code(200);
echo 'ok ' . $handled;
