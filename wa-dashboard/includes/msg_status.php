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

/* ───────────────────────── why a qualifier's outreach failed ───────────────────────── */

/** The last failure recorded for a lead (run alias r): WhatsApp's code and wording. */
function qualifier_fail_sql(string $r = 'r'): string
{
    return "(SELECT fm.error_code FROM flow_messages fm WHERE fm.run_id={$r}.id AND fm.status='failed' ORDER BY fm.id DESC LIMIT 1) AS fail_code,
            (SELECT fm.error_title FROM flow_messages fm WHERE fm.run_id={$r}.id AND fm.status='failed' ORDER BY fm.id DESC LIMIT 1) AS fail_title";
}

/** One lead's failure, explained: [slug, label, action, hint, code, detail]. */
function qualifier_fail_reason(array $run): array
{
    if (!function_exists('wa_error_explain')) require_once __DIR__ . '/whatsapp.php';
    $ctx   = json_decode((string) ($run['context'] ?? ''), true) ?: [];
    $code  = (string) ($run['fail_code'] ?? '') ?: (string) ($ctx['send_error_code'] ?? '');
    $title = (string) ($run['fail_title'] ?? '') ?: (string) ($ctx['send_error'] ?? '');
    if ($code === '' && $title === '') {
        $x = ['action' => 'later', 'label' => 'Stopped before sending', 'hint' => 'The message never went out — usually no credits or no outreach template at the time. Resending is safe once that is fixed.'];
    } elseif (stripos($title, 'credit') !== false) {
        $x = ['action' => 'fix', 'label' => 'Out of credits', 'hint' => 'There were no credits left when this lead came in. Top up, then resend.'];
    } elseif (stripos($title, 'template configured') !== false) {
        $x = ['action' => 'fix', 'label' => 'No outreach template', 'hint' => 'The qualifier had no first message set. Choose one in Edit, or resend with another template.'];
    } else {
        $x = wa_error_explain($code, $title);
    }
    $slug = trim((string) preg_replace('~[^a-z0-9]+~', '-', strtolower($x['label'])), '-');
    return [$slug, $x['label'], $x['action'], $x['hint'], $code, $title];
}

/**
 * Every lead of a qualifier whose outreach failed, grouped by cause, biggest first.
 * Each group: label, action (later | never | fix), hint, codes, sample, ids (all), resend (ids that can be resent).
 */
function qualifier_failures(int $flowId): array
{
    $rows = db_all("SELECT r.id, r.status, r.grade, r.context, " . qualifier_fail_sql('r') . "
                      FROM flow_runs r WHERE r.flow_id=? AND " . qualifier_msg_filter('failed', 'r') . " ORDER BY r.id DESC", [$flowId]);
    $g = [];
    foreach ($rows as $r) {
        [$slug, $label, $action, $hint, $code, $title] = qualifier_fail_reason($r);
        $g[$slug] ??= ['slug' => $slug, 'label' => $label, 'action' => $action, 'hint' => $hint, 'codes' => [], 'sample' => $title, 'ids' => [], 'resend' => []];
        $g[$slug]['ids'][] = (int) $r['id'];
        if ($code !== '') $g[$slug]['codes'][$code] = true;
        // A lead that already has an outcome (hot, not interested…) is left alone; one already queued is on its way.
        if (in_array((string) $r['grade'], ['', 'no_answer'], true) && $r['status'] !== 'queued') $g[$slug]['resend'][] = (int) $r['id'];
    }
    usort($g, fn($a, $b) => count($b['ids']) <=> count($a['ids']));
    return $g;
}
