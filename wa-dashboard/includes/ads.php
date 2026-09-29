<?php
declare(strict_types=1);

/**
 * Click-to-WhatsApp ads: which ad brought this person, and which ads exist at all.
 *
 * When someone taps a Facebook or Instagram ad that opens WhatsApp, Meta attaches a
 * `referral` object to the FIRST inbound message of that conversation — and only the first.
 * It carries the ad's id, its own headline and body copy, the link, and a click id.
 *
 * Two jobs here, and they answer different questions:
 *
 *   the catalogue  — "which ads are sending me people?"  One row per ad in wa_ads, upserted
 *                    on every click. This is what the automation editor's picker lists, which
 *                    is the whole reason a client never has to type an ad id: the payload
 *                    tells us the ad's own headline, so the picker can show words.
 *
 *   first touch    — "which ad brought THIS lead?"  Three columns on the contact, written
 *                    once and never overwritten.
 *
 * First touch rather than last is deliberate. Someone who clicks a second ad months later is
 * still a lead the first ad earned; crediting the most recent click would quietly move
 * attribution away from the campaign that did the work.
 */

/**
 * Record a referral: upsert the ad, and credit the contact if it has no ad yet.
 *
 * Safe to call on every inbound — it no-ops when there is no referral, which is all of them
 * after the first.
 *
 * @param array $referral the raw `referral` object from the webhook payload
 * @return string the ad's source_id, or '' when there was nothing to record
 */
function ads_record(int $clientId, int $contactId, array $referral): string
{
    $sourceId = trim((string) ($referral['source_id'] ?? ''));
    if ($sourceId === '' || $clientId <= 0) return '';

    $type = (string) ($referral['source_type'] ?? 'ad');
    $head = trim((string) ($referral['headline'] ?? ''));
    $body = trim((string) ($referral['body'] ?? ''));
    $url  = trim((string) ($referral['source_url'] ?? ''));

    try {
        /* The headline is only overwritten when this click actually carries one: an ad whose
           later clicks arrive without copy should keep the words we already know it by, or the
           picker would start showing bare ids for ads it used to name. */
        db_run(
            "INSERT INTO wa_ads (client_id, source_id, source_type, headline, body, source_url,
                                 first_seen_at, last_seen_at)
             VALUES (?,?,?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE
                 headline     = COALESCE(NULLIF(VALUES(headline), ''),   headline),
                 body         = COALESCE(NULLIF(VALUES(body), ''),       body),
                 source_url   = COALESCE(NULLIF(VALUES(source_url), ''), source_url),
                 source_type  = VALUES(source_type),
                 last_seen_at = NOW()",
            [$clientId, mb_substr($sourceId, 0, 64), mb_substr($type, 0, 16),
             $head !== '' ? mb_substr($head, 0, 255) : null,
             $body !== '' ? $body : null,
             $url  !== '' ? mb_substr($url, 0, 512) : null]
        );

        /* First touch: the WHERE clause is the guard. A contact who already has an ad keeps it,
           so this is idempotent and a second campaign never steals the first one's credit. */
        if ($contactId > 0) {
            db_run(
                "UPDATE contacts SET ad_source_id=?, ad_clid=?, referred_at=NOW()
                  WHERE id=? AND client_id=? AND (ad_source_id IS NULL OR ad_source_id='')",
                [mb_substr($sourceId, 0, 64),
                 mb_substr(trim((string) ($referral['ctwa_clid'] ?? '')), 0, 255) ?: null,
                 $contactId, $clientId]
            );
            // The ad id, where the campaign reports look for it; its campaign is filled in once the
            // account's ad spend is read from Meta. A post or a page link is not an ad.
            if (function_exists('crm_set_origin')) crm_set_origin($contactId, $type === 'ad' ? ['meta_ad_id' => $sourceId, 'platform' => 'whatsapp_ad'] : []);
        }
    } catch (Throwable $e) {
        // Attribution is worth having, not worth dropping an inbound message for. The
        // conversation still runs; only the reporting loses this one click.
        error_log('ads_record failed: ' . $e->getMessage());
        return $sourceId;
    }

    return $sourceId;
}

/**
 * What the referral should make available to the flow as {{variables}}.
 *
 * auto_render() builds its map from the contact plus $ctx['fields'], so seeding these at
 * auto_start() is all it takes for {{ad_headline}} to work in any message body — no change
 * to the renderer at all.
 */
function ads_seed_fields(array $referral): array
{
    $head = trim((string) ($referral['headline'] ?? ''));
    $body = trim((string) ($referral['body'] ?? ''));

    /* Not every ad arrives with a headline — image and video ads often carry only body copy,
       and some carry neither. A flow written as "You asked about {{ad_headline}}…" would then
       send "You asked about  …" with a hole in the middle of the sentence, which is worse
       than being vague. Fall back to the body copy, then to something that still reads as a
       sentence. */
    if ($head === '') $head = $body !== '' ? mb_substr($body, 0, 80) : 'our ad';

    return array_filter([
        'ad_id'         => trim((string) ($referral['source_id'] ?? '')),
        'ad_headline'   => $head,
        'ad_body'       => $body,
        'ad_source_url' => trim((string) ($referral['source_url'] ?? '')),
    ], fn($v) => $v !== '');
}

/**
 * Every ad this client has ever been messaged from, most recently active first.
 *
 * The lead count is COUNT over contacts.idx_ad_source rather than a stored counter, so it
 * cannot drift away from the contacts it claims to be counting.
 *
 * @return array<int, array{source_id:string, headline:?string, leads:int, hot:int, last_seen_at:string}>
 */
function ads_list(int $clientId, int $limit = 200): array
{
    try {
        return db_all(
            "SELECT a.source_id, a.headline, a.source_url, a.last_seen_at, a.first_seen_at,
                    COUNT(c.id) AS leads,
                    SUM(EXISTS (SELECT 1 FROM flow_runs r
                                 WHERE r.contact_id = c.id AND r.grade = 'hot')) AS hot
               FROM wa_ads a
               LEFT JOIN contacts c
                      ON c.client_id = a.client_id AND c.ad_source_id = a.source_id
              WHERE a.client_id = ?
              GROUP BY a.id
              ORDER BY a.last_seen_at DESC
              LIMIT {$limit}", [$clientId]);
    } catch (Throwable $e) {
        return [];      // migration 028 not applied yet
    }
}

/** What to call an ad on screen: its own headline, or the id when it arrived without one. */
function ads_label(?string $headline, string $sourceId): string
{
    $headline = trim((string) $headline);
    return $headline !== '' ? $headline : 'Ad ' . $sourceId;
}
