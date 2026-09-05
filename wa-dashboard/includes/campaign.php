<?php
declare(strict_types=1);

/**
 * Campaign helpers: resolve template variables per contact and build the
 * WhatsApp `components` payload stored on each queued message.
 *
 * $varMap: { "1": {source:'static'|'name'|'attribute', value:'', fallback:''}, ... }
 */

function campaign_resolve_value(array $spec, array $contact): string
{
    $source   = (string) ($spec['source'] ?? 'static');
    $value    = (string) ($spec['value'] ?? '');
    $fallback = trim((string) ($spec['fallback'] ?? ''));

    $out = '';
    if ($source === 'static') {
        $out = $value;
    } elseif ($source === 'name') {
        $out = trim((string) ($contact['name'] ?? ''));
    } elseif ($source === 'attribute') {
        $attrs = [];
        if (!empty($contact['attributes'])) {
            $decoded = is_array($contact['attributes']) ? $contact['attributes'] : json_decode((string) $contact['attributes'], true);
            if (is_array($decoded)) $attrs = $decoded;
        }
        $out = trim((string) ($attrs[$value] ?? ''));
    }

    if ($out === '') $out = ($fallback !== '' ? $fallback : 'Customer');
    // WhatsApp rejects newlines/tabs and 4+ consecutive spaces in params.
    $out = preg_replace('/[\r\n\t]+/', ' ', $out);
    $out = preg_replace('/ {4,}/', '   ', $out);
    return $out;
}

/**
 * Normalize a campaign's stored `variable_map` to the shared builder's config shape.
 *
 * Legacy campaigns stored a flat {"1":{...},"2":{...}} body-variable map; newer ones store
 * {vars, header_media, header_vars, header_loc, buttons}. Accept both so old campaigns and
 * reports keep working.
 */
function campaign_config(array $varMap): array
{
    if (isset($varMap['vars']) || isset($varMap['header_media']) || isset($varMap['header_vars'])
        || isset($varMap['header_loc']) || isset($varMap['buttons'])) {
        return $varMap;
    }
    return ['vars' => $varMap];   // legacy flat body-vars map
}

/**
 * Build the FULL template `components` array for one contact — header media/text, body
 * variables and dynamic buttons.
 *
 * Delegates to wa_build_components() (includes/whatsapp.php) so Campaigns, the bot canvas
 * and the Lead Qualifier all emit identical payloads. Previously this built BODY parameters
 * only, which made every template with an image header fail with Meta #132012.
 *
 * Header media is intentionally left in `link` form here: these components are rendered
 * once at campaign-creation time, and the worker swaps in a fresh media id at send time
 * (wa_apply_media_id) so scheduled campaigns can't ship an expired id.
 */
function campaign_components(array $varMap, array $tplComponents, array $contact): array
{
    return wa_build_components(
        $tplComponents,
        campaign_config($varMap),
        $contact,
        [],                                  // no client → keep links; the worker swaps in the id
        'campaign_resolve_value'             // campaigns also support contact attributes
    );
}

/**
 * Nudge a campaign's counters as individual messages change state.
 *
 * The full recount below re-scans every message of the campaign, which is fine at the end
 * of a run but costly to call repeatedly on a large campaign. This applies the delta
 * directly; campaign_refresh_counts() still runs afterwards and reconciles, so a missed or
 * doubled nudge self-corrects rather than drifting.
 */
function campaign_bump_counts(int $campaignId, string $field, int $delta = 1): void
{
    $allowed = ['sent_count', 'delivered_count', 'read_count', 'failed_count'];
    if (!in_array($field, $allowed, true) || $delta === 0) return;
    db_run("UPDATE campaigns SET {$field} = GREATEST(0, {$field} + ?) WHERE id = ?", [$delta, $campaignId]);
}

/** Recompute a campaign's status counters from its messages. Authoritative. */
function campaign_refresh_counts(int $campaignId): void
{
    $row = db_row(
        "SELECT
            COUNT(*) AS total,
            SUM(status IN ('sent','delivered','read'))       AS sent,
            SUM(status IN ('delivered','read'))              AS delivered,
            SUM(status = 'read')                             AS `read`,
            -- 'dead' (transient error, retries exhausted) and 'review' (sent once, outcome
            -- never confirmed) are both terminal for the campaign and both need a human, so
            -- they count as failed here and are listed on the Needs attention page.
            SUM(status IN ('failed','dead','review'))        AS failed,
            SUM(status IN ('queued','sending'))              AS pending
         FROM campaign_messages WHERE campaign_id = ?",
        [$campaignId]
    );
    if (!$row) return;

    db_run(
        "UPDATE campaigns SET total_count=?, sent_count=?, delivered_count=?, read_count=?, failed_count=? WHERE id=?",
        [(int) $row['total'], (int) $row['sent'], (int) $row['delivered'],
         (int) $row['read'], (int) $row['failed'], $campaignId]
    );

    // If nothing is pending and the campaign was sending, mark it completed.
    if ((int) $row['pending'] === 0) {
        db_run(
            "UPDATE campaigns SET status='completed', completed_at=COALESCE(completed_at,NOW())
             WHERE id=? AND status IN ('sending','queued')",
            [$campaignId]
        );
    }
}

/**
 * Render a plain-text campaign message for one contact (personal channel only).
 *
 * Cloud campaigns personalise through numbered template parameters ({{1}}, {{2}});
 * a personal number sends ordinary text, so it uses readable names instead —
 * {{name}}, {{phone}}, and any contact attribute by key.
 */
function campaign_render_text(string $body, array $contact): string
{
    $attrs = [];
    if (!empty($contact['attributes'])) {
        $decoded = is_array($contact['attributes'])
            ? $contact['attributes']
            : json_decode((string) $contact['attributes'], true);
        if (is_array($decoded)) $attrs = $decoded;
    }
    $map = ['name' => trim((string) ($contact['name'] ?? '')), 'phone' => (string) ($contact['phone_e164'] ?? '')];
    foreach ($attrs as $k => $v) if (is_scalar($v)) $map[strtolower((string) $k)] = (string) $v;

    return (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($map) {
        return $map[strtolower($m[1])] ?? '';
    }, $body);
}

/**
 * "July Promo" → "July Promo (copy)", then "(copy 2)" and so on.
 *
 * The same rule as flow_copy_name(), kept separate because campaigns and flows are
 * different tables with different owners; sharing one function would mean passing the
 * table name in, which is worse than eleven lines repeated.
 */
function campaign_copy_name(string $name, int $clientId): string
{
    $base = preg_replace('/\s*\(copy(?:\s+\d+)?\)$/u', '', trim($name));
    if ($base === '') $base = 'Untitled';
    $taken = array_column(db_all("SELECT name FROM campaigns WHERE client_id=?", [$clientId]), 'name');

    // 190 is the column width; leave room for the suffix rather than have MySQL truncate it.
    $fit = fn(string $suffix) => mb_substr($base, 0, 190 - mb_strlen($suffix)) . $suffix;

    $try = $fit(' (copy)');
    for ($n = 2; in_array($try, $taken, true) && $n < 200; $n++) $try = $fit(" (copy {$n})");
    return $try;
}
