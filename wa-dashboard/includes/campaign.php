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

/** Build the template `components` array for one contact (empty if no vars). */
function campaign_components(array $varMap, int $varCount, array $contact): array
{
    if ($varCount < 1) return [];
    $params = [];
    for ($i = 1; $i <= $varCount; $i++) {
        $spec = $varMap[(string) $i] ?? $varMap[$i] ?? ['source' => 'static', 'value' => '', 'fallback' => ''];
        $params[] = ['type' => 'text', 'text' => campaign_resolve_value((array) $spec, $contact)];
    }
    return [['type' => 'body', 'parameters' => $params]];
}

/** Recompute a campaign's status counters from its messages. */
function campaign_refresh_counts(int $campaignId): void
{
    $row = db_row(
        "SELECT
            COUNT(*) AS total,
            SUM(status IN ('sent','delivered','read'))       AS sent,
            SUM(status IN ('delivered','read'))              AS delivered,
            SUM(status = 'read')                             AS `read`,
            SUM(status = 'failed')                           AS failed,
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
