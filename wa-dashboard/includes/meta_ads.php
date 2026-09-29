<?php
declare(strict_types=1);

/**
 * Ad spend from Meta, so the CRM can say what each lead, visit and sale cost.
 *
 * Read with the Marketing API's insights, per ad per day, from each ad account the client
 * connects — either through the same Facebook login as lead forms (needs the ads_read
 * permission; the token lasts about 60 days, then the login is repeated) or with a pasted
 * System User token from Business Settings (does not expire). Tokens are stored encrypted and
 * never shown back.
 *
 * The last three days are re-read every hour, because Meta keeps correcting recent spend. The
 * first read goes back 90 days.
 */

require_once __DIR__ . '/meta_leads.php';

const META_ADS_BACKFILL_DAYS = 90;
const META_ADS_REFRESH_DAYS  = 3;

/** The ad accounts a token can read. */
function meta_ads_accounts_for(string $token): array
{
    $r = meta_http('GET', 'me/adaccounts', ['fields' => 'account_id,name,currency,account_status', 'limit' => 100, 'access_token' => $token]);
    if (!$r['ok']) return ['ok' => false, 'error' => meta_ads_explain((string) $r['error'])];
    $out = [];
    foreach ((array) ($r['json']['data'] ?? []) as $a) {
        $id = (string) ($a['id'] ?? ('act_' . ($a['account_id'] ?? '')));
        if ($id === 'act_') continue;
        $out[] = ['account_id' => str_starts_with($id, 'act_') ? $id : 'act_' . $id, 'name' => (string) ($a['name'] ?? $id),
                  'currency' => (string) ($a['currency'] ?? ''), 'active' => (int) ($a['account_status'] ?? 1) === 1];
    }
    return ['ok' => true, 'accounts' => $out];
}

/** Save the ad accounts a token can read (keeping each one's on/off choice). */
function meta_ads_save_accounts(int $clientId, string $token, string $kind, array $accounts, ?string $expires = null): int
{
    $n = 0;
    foreach ($accounts as $a) {
        db_run("INSERT INTO meta_ad_accounts (client_id,account_id,name,currency,token_enc,token_kind,token_expires,enabled,created_at)
                VALUES (?,?,?,?,?,?,?,1,NOW())
                ON DUPLICATE KEY UPDATE name=VALUES(name), currency=VALUES(currency), token_enc=VALUES(token_enc),
                                        token_kind=VALUES(token_kind), token_expires=VALUES(token_expires), last_error=NULL",
               [$clientId, $a['account_id'], mb_substr($a['name'], 0, 190), $a['currency'] ?: null, encrypt_secret($token), $kind, $expires]);
        $n++;
    }
    return $n;
}

function meta_ads_accounts(int $clientId): array
{
    try { return db_all("SELECT * FROM meta_ad_accounts WHERE client_id=? ORDER BY name", [$clientId]); }
    catch (Throwable $e) { return []; }
}

/** Meta's error, in words a client can act on. */
function meta_ads_explain(string $err): string
{
    if (stripos($err, 'ads_read') !== false || stripos($err, 'permission') !== false || stripos($err, '(#200)') !== false || stripos($err, '(#10)') !== false) {
        return 'Facebook did not give permission to read ad spend (ads_read). Connect again and allow it, or paste a System User token.';
    }
    if (stripos($err, 'expired') !== false || stripos($err, 'Session has expired') !== false || stripos($err, 'OAuthException') !== false) {
        return 'The Facebook connection has expired. Connect again, or use a System User token, which does not expire.';
    }
    return $err !== '' ? $err : 'Facebook did not answer.';
}

/**
 * Read one ad account's spend for these days, per ad per day, and store it.
 *
 * @return array{ok:bool, rows?:int, error?:string}
 */
function meta_ads_sync_account(array $acc, string $since, string $until): array
{
    $token = $acc['token_enc'] ? decrypt_secret((string) $acc['token_enc']) : '';
    if ($token === '') return ['ok' => false, 'error' => 'No token for this ad account.'];
    $params = ['level' => 'ad', 'time_increment' => 1, 'limit' => 500, 'access_token' => $token,
               'fields' => 'ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,spend,impressions,clicks,date_start',
               'time_range' => json_encode(['since' => $since, 'until' => $until])];
    $rows = 0; $guard = 0;
    do {
        $r = meta_http('GET', $acc['account_id'] . '/insights', $params);
        if (!$r['ok']) {
            $err = meta_ads_explain((string) $r['error']);
            db_run("UPDATE meta_ad_accounts SET last_error=? WHERE id=?", [mb_substr($err, 0, 255), (int) $acc['id']]);
            return ['ok' => false, 'error' => $err];
        }
        foreach ((array) ($r['json']['data'] ?? []) as $d) {
            if (empty($d['ad_id']) || empty($d['date_start'])) continue;
            db_run("INSERT INTO meta_ad_spend (client_id,account_id,day,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,spend,impressions,clicks)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE ad_name=VALUES(ad_name), adset_id=VALUES(adset_id), adset_name=VALUES(adset_name),
                        campaign_id=VALUES(campaign_id), campaign_name=VALUES(campaign_name), spend=VALUES(spend),
                        impressions=VALUES(impressions), clicks=VALUES(clicks)",
                   [(int) $acc['client_id'], $acc['account_id'], substr((string) $d['date_start'], 0, 10), (string) $d['ad_id'],
                    mb_substr((string) ($d['ad_name'] ?? ''), 0, 190) ?: null, (string) ($d['adset_id'] ?? '') ?: null,
                    mb_substr((string) ($d['adset_name'] ?? ''), 0, 190) ?: null, (string) ($d['campaign_id'] ?? '') ?: null,
                    mb_substr((string) ($d['campaign_name'] ?? ''), 0, 190) ?: null, (float) ($d['spend'] ?? 0),
                    (int) ($d['impressions'] ?? 0), (int) ($d['clicks'] ?? 0)]);
            $rows++;
        }
        $after = (string) ($r['json']['paging']['cursors']['after'] ?? '');
        $more = !empty($r['json']['paging']['next']) && $after !== '';
        $params['after'] = $after;
    } while ($more && ++$guard < 200);
    db_run("UPDATE meta_ad_accounts SET last_synced_at=NOW(), last_error=NULL, synced_from=LEAST(COALESCE(synced_from, ?), ?) WHERE id=?",
           [$since, $since, (int) $acc['id']]);
    meta_ads_link_leads((int) $acc['client_id']);
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Leads that know their ad but not its campaign (click-to-WhatsApp ads say only the ad) get the
 * campaign and ad set from the spend rows, so they count under the right campaign.
 */
function meta_ads_link_leads(int $clientId): int
{
    return (int) db_run("UPDATE contacts c JOIN (SELECT ad_id, MAX(ad_name) ad_name, MAX(adset_id) adset_id, MAX(adset_name) adset_name,
                                                        MAX(campaign_id) campaign_id, MAX(campaign_name) campaign_name
                                                   FROM meta_ad_spend WHERE client_id=? GROUP BY ad_id) a ON a.ad_id = c.meta_ad_id
                            SET c.meta_campaign_id = COALESCE(NULLIF(c.meta_campaign_id,''), a.campaign_id),
                                c.campaign = COALESCE(NULLIF(c.campaign,''), a.campaign_name),
                                c.meta_adset_id = COALESCE(NULLIF(c.meta_adset_id,''), a.adset_id),
                                c.adset = COALESCE(NULLIF(c.adset,''), a.adset_name),
                                c.ad_name = COALESCE(NULLIF(c.ad_name,''), a.ad_name)
                          WHERE c.client_id=? AND (c.meta_campaign_id IS NULL OR c.meta_campaign_id='')", [$clientId, $clientId]);
}

/** The worker's pass: each enabled account, once an hour — 90 days the first time, then the last 3. */
function meta_ads_tick(): int
{
    try {
        $accs = db_all("SELECT * FROM meta_ad_accounts WHERE enabled=1 AND (last_synced_at IS NULL OR last_synced_at < NOW() - INTERVAL 1 HOUR) LIMIT 20");
    } catch (Throwable $e) { return 0; }
    $n = 0;
    foreach ($accs as $a) {
        $since = $a['last_synced_at'] ? date('Y-m-d', strtotime('-' . META_ADS_REFRESH_DAYS . ' days')) : date('Y-m-d', strtotime('-' . META_ADS_BACKFILL_DAYS . ' days'));
        $r = meta_ads_sync_account($a, $since, date('Y-m-d'));
        if ($r['ok']) $n++;
        else db_run("UPDATE meta_ad_accounts SET last_synced_at=NOW() WHERE id=?", [(int) $a['id']]);   // try again in an hour, not every minute
    }
    return $n;
}

/** Spend in the period for the campaigns (or ads) of an account's clients — key => spend, clicks, name. */
function meta_ads_spend(int $clientId, string $from, string $to, string $level = 'campaign'): array
{
    $col = $level === 'ad' ? 'ad_id' : ($level === 'adset' ? 'adset_id' : 'campaign_id');
    $name = $level === 'ad' ? 'ad_name' : ($level === 'adset' ? 'adset_name' : 'campaign_name');
    $out = [];
    try {
        foreach (db_all("SELECT $col k, MAX($name) name, SUM(spend) spend, SUM(clicks) clicks, SUM(impressions) imp
                           FROM meta_ad_spend WHERE client_id=? AND day BETWEEN ? AND ? AND $col IS NOT NULL GROUP BY $col", [$clientId, $from, $to]) as $r)
            $out[(string) $r['k']] = ['name' => (string) $r['name'], 'spend' => (float) $r['spend'], 'clicks' => (int) $r['clicks'], 'imp' => (int) $r['imp']];
    } catch (Throwable $e) {}
    return $out;
}
