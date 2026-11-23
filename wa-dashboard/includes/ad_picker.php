<?php
declare(strict_types=1);
/**
 * Choosing ads for an automation's "Someone arrives from an ad" trigger, from the client's own
 * Meta ad accounts: account → campaigns → ads. A whole campaign can be chosen; its ads (also ones
 * added later) are recognised through meta_ad_map, since a click-to-WhatsApp message carries
 * only the ad's id.
 *
 * Uses the ad-account connection Revenect already keeps for ad spend (meta_ad_accounts: the
 * Facebook login with ads_read, or a pasted System User token).
 */

require_once __DIR__ . '/meta_leads.php';   // meta_http
require_once __DIR__ . '/meta_ads.php';

function adp_ready(): bool
{
    static $ok = null;
    if ($ok === null) { try { $ok = (bool) db_val("SHOW TABLES LIKE 'meta_ad_map'"); } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

function adp_account(int $clientId, string $accountId): ?array
{
    $a = db_row("SELECT * FROM meta_ad_accounts WHERE client_id=? AND account_id=?", [$clientId, $accountId]);
    return $a && !empty($a['token_enc']) ? $a : null;
}

function adp_token(array $acc): string
{
    return decrypt_secret((string) $acc['token_enc']);
}

/** Every page of a Graph list (Meta pages at `limit`), up to $max rows. */
function adp_list(string $path, array $params, string $token, int $max = 500): array
{
    $out = []; $params['access_token'] = $token; $params['limit'] = min(200, $max);
    for ($i = 0; $i < 10 && count($out) < $max; $i++) {
        $r = meta_http('GET', $path, $params);
        if (!$r['ok']) return ['ok' => false, 'error' => function_exists('meta_ads_explain') ? meta_ads_explain((string) $r['error']) : (string) $r['error'], 'data' => $out];
        foreach ((array) ($r['json']['data'] ?? []) as $row) $out[] = $row;
        $after = (string) ($r['json']['paging']['cursors']['after'] ?? '');
        if ($after === '' || empty($r['json']['paging']['next'])) break;
        $params['after'] = $after;
    }
    return ['ok' => true, 'error' => '', 'data' => $out];
}

/** Meta's delivery state, in a word people use. */
function adp_state(string $s): string
{
    return ['ACTIVE' => 'Active', 'PAUSED' => 'Paused', 'CAMPAIGN_PAUSED' => 'Paused', 'ADSET_PAUSED' => 'Paused', 'ARCHIVED' => 'Archived',
            'DELETED' => 'Deleted', 'IN_PROCESS' => 'In review', 'PENDING_REVIEW' => 'In review', 'DISAPPROVED' => 'Rejected',
            'WITH_ISSUES' => 'Has issues', 'PREAPPROVED' => 'Approved'][strtoupper($s)] ?? ucfirst(strtolower(str_replace('_', ' ', $s)));
}

function adp_campaigns(array $acc): array
{
    $r = adp_list($acc['account_id'] . '/campaigns', ['fields' => 'id,name,effective_status,objective,updated_time'], adp_token($acc), 500);
    if (!$r['ok']) return $r;
    $rows = [];
    foreach ($r['data'] as $c) {
        if (in_array(($c['effective_status'] ?? ''), ['DELETED'], true)) continue;
        $rows[] = ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? $c['id']), 'status' => adp_state((string) ($c['effective_status'] ?? '')),
                   'active' => ($c['effective_status'] ?? '') === 'ACTIVE', 'objective' => (string) ($c['objective'] ?? ''), 'updated' => (string) ($c['updated_time'] ?? '')];
    }
    // Running campaigns first, then the most recently changed.
    usort($rows, fn($a, $b) => [$b['active'], $b['updated']] <=> [$a['active'], $a['updated']]);
    return ['ok' => true, 'campaigns' => $rows];
}

/** A campaign's ads; each is also remembered as belonging to that campaign. */
function adp_ads(int $clientId, array $acc, string $campaignId, string $campaignName = ''): array
{
    $r = adp_list($campaignId . '/ads', ['fields' => 'id,name,effective_status,adset{name},creative{thumbnail_url}'], adp_token($acc), 500);
    if (!$r['ok']) return $r;
    $rows = [];
    foreach ($r['data'] as $a) {
        if (($a['effective_status'] ?? '') === 'DELETED') continue;
        $rows[] = ['id' => (string) $a['id'], 'name' => (string) ($a['name'] ?? $a['id']), 'status' => adp_state((string) ($a['effective_status'] ?? '')),
                   'active' => ($a['effective_status'] ?? '') === 'ACTIVE', 'adset' => (string) ($a['adset']['name'] ?? ''),
                   'thumb' => (string) ($a['creative']['thumbnail_url'] ?? '')];
        adp_remember($clientId, (string) $a['id'], $campaignId, $campaignName, (string) ($a['name'] ?? ''), (string) $acc['account_id']);
    }
    usort($rows, fn($x, $y) => $y['active'] <=> $x['active']);
    return ['ok' => true, 'ads' => $rows];
}

function adp_remember(int $clientId, string $adId, string $campaignId, string $campaignName = '', string $adName = '', string $accountId = ''): void
{
    if (!adp_ready() || $adId === '') return;
    try {
        db_run("INSERT INTO meta_ad_map (client_id,ad_id,campaign_id,campaign_name,ad_name,account_id,updated_at) VALUES (?,?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE campaign_id=VALUES(campaign_id), campaign_name=COALESCE(VALUES(campaign_name), campaign_name),
                                        ad_name=COALESCE(VALUES(ad_name), ad_name), account_id=COALESCE(VALUES(account_id), account_id), updated_at=NOW()",
               [$clientId, $adId, $campaignId, $campaignName !== '' ? mb_substr($campaignName, 0, 190) : null,
                $adName !== '' ? mb_substr($adName, 0, 190) : null, $accountId !== '' ? $accountId : null]);
    } catch (Throwable $e) {}
}

/**
 * The campaign an ad belongs to. Known ads come from meta_ad_map; an unknown one is asked of
 * Meta with each of the client's ad-account tokens, once (a miss is remembered for a day).
 */
function adp_campaign_of(int $clientId, string $adId): string
{
    if (!adp_ready() || $adId === '') return '';
    $row = db_row("SELECT campaign_id, updated_at FROM meta_ad_map WHERE client_id=? AND ad_id=?", [$clientId, $adId]);
    if ($row && ($row['campaign_id'] !== '' || strtotime((string) $row['updated_at']) > time() - 86400)) return (string) $row['campaign_id'];
    foreach (db_all("SELECT * FROM meta_ad_accounts WHERE client_id=? AND token_enc IS NOT NULL", [$clientId]) as $acc) {
        $r = meta_http('GET', $adId, ['fields' => 'name,campaign{id,name}', 'access_token' => adp_token($acc)]);
        if ($r['ok'] && !empty($r['json']['campaign']['id'])) {
            adp_remember($clientId, $adId, (string) $r['json']['campaign']['id'], (string) ($r['json']['campaign']['name'] ?? ''), (string) ($r['json']['name'] ?? ''), (string) $acc['account_id']);
            return (string) $r['json']['campaign']['id'];
        }
    }
    adp_remember($clientId, $adId, '');
    return '';
}
