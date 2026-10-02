<?php
declare(strict_types=1);

/**
 * Delivery receipts from the personal-number gateway (Evolution): sent → delivered → read.
 *
 * The Business API webhook (webhook.php) has done this for its own messages all along. The
 * gateway reports the same thing as MESSAGES_UPDATE events, with WhatsApp's own ack levels; this
 * turns them into the same statuses and moves every place the message is recorded forward — the
 * Inbox, a campaign's message, a qualifier's outreach. Never backwards: a late "delivered" after
 * "read" changes nothing.
 */

/** The receipts in one gateway payload, as [[message id, status], …]. Both v1 and v2 shapes. */
function pw_parse_status_updates(array $data): array
{
    $ev = strtolower(str_replace('_', '.', (string) ($data['event'] ?? '')));
    if ($ev !== 'messages.update') return [];
    $d = $data['data'] ?? [];
    $items = is_array($d) && array_is_list($d) ? $d : [$d];
    $levels = [0 => 'failed', 1 => '', 2 => 'sent', 3 => 'delivered', 4 => 'read', 5 => 'read'];
    $names = ['ERROR' => 'failed', 'PENDING' => '', 'SERVER_ACK' => 'sent', 'DELIVERY_ACK' => 'delivered', 'READ' => 'read', 'PLAYED' => 'read'];
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $key = is_array($it['key'] ?? null) ? $it['key'] : [];
        if (isset($key['fromMe']) && !$key['fromMe'] || isset($it['fromMe']) && !$it['fromMe']) continue;   // their message, not ours
        $id = (string) ($it['keyId'] ?? $key['id'] ?? '');
        $raw = $it['status'] ?? ($it['update']['status'] ?? null);
        $st = is_numeric($raw) ? ($levels[(int) $raw] ?? '') : ($names[strtoupper((string) $raw)] ?? '');
        if ($id !== '' && $st !== '') $out[] = [$id, $st];
    }
    return $out;
}

/**
 * Move a sent message forward to $status wherever it is recorded.
 * @return int the campaign it belongs to (to recount), or 0
 */
function msg_status_apply(int $clientId, string $wamid, string $status, string $ts = '', string $error = ''): int
{
    if ($wamid === '' || !in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) return 0;
    $ts = $ts ?: date('Y-m-d H:i:s');
    $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3];
    try {
        if ($status === 'failed') {
            db_run("UPDATE messages SET status='failed', error_title=? WHERE client_id=? AND wa_message_id=? AND COALESCE(status,'') NOT IN ('delivered','read')",
                   [mb_substr($error ?: 'Failed', 0, 255), $clientId, $wamid]);
        } else {
            db_run("UPDATE messages SET status=? WHERE client_id=? AND wa_message_id=? AND COALESCE(FIELD(status,'sent','delivered','read'),0) < ?",
                   [$status, $clientId, $wamid, $rank[$status]]);
        }
    } catch (Throwable $e) { /* inbox table not ready */ }

    // A qualifier's / automation's outreach.
    if ($status === 'delivered') db_run("UPDATE flow_messages SET status='delivered' WHERE client_id=? AND wa_message_id=? AND status='sent'", [$clientId, $wamid]);
    elseif ($status === 'read') db_run("UPDATE flow_messages SET status='read' WHERE client_id=? AND wa_message_id=? AND status IN ('sent','delivered')", [$clientId, $wamid]);
    elseif ($status === 'failed') db_run("UPDATE flow_messages SET status='failed', error_title=? WHERE client_id=? AND wa_message_id=? AND status='sent'", [mb_substr($error ?: 'Failed', 0, 255), $clientId, $wamid]);

    // A campaign's message.
    $m = db_row("SELECT id, campaign_id, status FROM campaign_messages WHERE client_id=? AND wa_message_id=? LIMIT 1", [$clientId, $wamid]);
    if (!$m) return 0;
    $cur = $rank[$m['status']] ?? 0;
    if ($status === 'failed') {
        if ($cur >= 2) return 0;
        db_run("UPDATE campaign_messages SET status='failed', error_title=?, updated_at=NOW() WHERE id=?", [mb_substr($error ?: 'Failed', 0, 255), (int) $m['id']]);
    } else {
        if ($rank[$status] <= $cur) return 0;
        db_run("UPDATE campaign_messages SET status=?, sent_at=COALESCE(sent_at,?), delivered_at=IF(? >= 2, COALESCE(delivered_at,?), delivered_at),
                       read_at=IF(? = 3, COALESCE(read_at,?), read_at), updated_at=NOW() WHERE id=?",
               [$status, $ts, $rank[$status], $ts, $rank[$status], $ts, (int) $m['id']]);
    }
    return (int) $m['campaign_id'];
}

/* ───────────────────────── qualifier leads by what happened to their outreach ───────────────────────── */

/**
 * SQL for one lead (flow run, alias r): the furthest its outreach got. 0 nothing reached them,
 * 1 sent, 2 delivered, 3 read. A lead with several messages counts once, by the best of them.
 */
function qualifier_best_sql(string $r = 'r'): string
{
    return "COALESCE((SELECT MAX(FIELD(m.status,'sent','delivered','read')) FROM flow_messages m WHERE m.run_id={$r}.id), 0)";
}

/** The condition for one of: sent, read, unread, failed — leads, not messages. */
function qualifier_msg_filter(string $kind, string $r = 'r'): string
{
    $best = qualifier_best_sql($r);
    return match ($kind) {
        'sent'   => "$best >= 1",
        'read'   => "$best = 3",
        'unread' => "$best IN (1,2)",
        // Nothing reached them: a send failed, or the run stopped before one could go out.
        'failed' => "$best = 0 AND (EXISTS (SELECT 1 FROM flow_messages m2 WHERE m2.run_id={$r}.id AND m2.status='failed')
                                   OR ({$r}.status='blocked' AND {$r}.grade IS NULL))",
        default  => '1=1',
    };
}

/** Sent / read / unread / failed leads for each qualifier of an account (or one), keyed by flow id. */
function qualifier_msg_counts(int $clientId, ?int $flowId = null): array
{
    $best = qualifier_best_sql('r');
    $rows = db_all("SELECT x.flow_id, COUNT(*) leads, SUM(x.best >= 1) sent, SUM(x.best = 3) rd, SUM(x.best IN (1,2)) unread,
                           SUM(x.best = 0 AND (x.failed > 0 OR (x.status='blocked' AND x.grade IS NULL))) failed
                      FROM (SELECT r.flow_id, r.status, r.grade, $best AS best,
                                   (SELECT COUNT(*) FROM flow_messages m2 WHERE m2.run_id=r.id AND m2.status='failed') AS failed
                              FROM flow_runs r WHERE r.client_id=?" . ($flowId ? " AND r.flow_id=?" : '') . ") x
                     GROUP BY x.flow_id", $flowId ? [$clientId, $flowId] : [$clientId]);
    $out = [];
    foreach ($rows as $r) $out[(int) $r['flow_id']] = ['leads' => (int) $r['leads'], 'sent' => (int) $r['sent'], 'read' => (int) $r['rd'],
                                                     'unread' => (int) $r['unread'], 'failed' => (int) $r['failed']];
    return $out;
}
