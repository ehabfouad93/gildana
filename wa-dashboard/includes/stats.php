<?php
declare(strict_types=1);

/**
 * The numbers behind the dashboards: messages, conversations, replies, campaigns, automations
 * and credits, for one client (or the whole platform when $clientId is null) over a period.
 *
 * $scope narrows to the contacts a salesperson may see: [" AND c.owner_user_id IN (…)", params]
 * from crm_scope('c'). Queries that need it join contacts as c.
 */

require_once __DIR__ . '/charts.php';

/** Credit reasons that are spending (and their refunds), as opposed to top-ups and grants. */
function stats_spend_sql(): string
{
    return "(reason IN ('send','campaign','inbox','inbox_template','automation','ai_usage') OR reason LIKE '%refund%')";
}

/** WHERE for one client or all, on a table alias. */
function stats_client(?int $clientId, string $alias): array
{
    // Platform-wide: only clients that still exist (messages are not removed with a deleted client).
    return $clientId === null ? ["$alias.client_id IN (SELECT id FROM clients)", []] : ["$alias.client_id=?", [$clientId]];
}

/** Message counts in [start, end]: sent, failed, delivered, read, received, conversations, new contacts. */
function stats_msg_totals(?int $clientId, string $start, string $end, array $scope = ['', []]): array
{
    [$cw, $cp] = stats_client($clientId, 'm');
    $join = $scope[0] !== '' ? ' JOIN contacts c ON c.id=m.contact_id' : '';
    $r = db_row("SELECT SUM(m.direction='out' AND COALESCE(m.status,'') <> 'failed') sent,
                        SUM(m.direction='out' AND m.status='failed') failed,
                        SUM(m.direction='out' AND m.status IN ('delivered','read')) delivered,
                        SUM(m.direction='out' AND m.status='read') `read`,
                        SUM(m.direction='in') received,
                        COUNT(DISTINCT IF(m.direction='in', m.contact_id, NULL)) conversations
                   FROM messages m$join WHERE $cw AND m.created_at BETWEEN ? AND ?{$scope[0]}",
                 array_merge($cp, [$start, $end], $scope[1])) ?: [];
    [$kw, $kp] = stats_client($clientId, 'c');
    $r['new_contacts'] = (int) db_val("SELECT COUNT(*) FROM contacts c WHERE $kw AND c.created_at BETWEEN ? AND ?{$scope[0]}", array_merge($kp, [$start, $end], $scope[1]));
    return array_map('intval', $r);
}

/** Per-bucket series: sent, received, failed, and received per channel. */
function stats_msg_series(?int $clientId, array $p, array $scope = ['', []]): array
{
    [$cw, $cp] = stats_client($clientId, 'm');
    $join = $scope[0] !== '' ? ' JOIN contacts c ON c.id=m.contact_id' : '';
    $b = viz_buckets($p);
    $hasCh = db_has_column('messages', 'channel');
    $rows = db_all("SELECT " . viz_bucket_sql('m.created_at', $p['bucket']) . " b, m.direction d, " . ($hasCh ? 'm.channel' : "'whatsapp'") . " ch,
                           SUM(m.direction='in' OR COALESCE(m.status,'') <> 'failed') n, SUM(m.direction='out' AND m.status='failed') f,
                           SUM(m.direction='out' AND m.status='read') r
                      FROM messages m$join WHERE $cw AND m.created_at BETWEEN ? AND ?{$scope[0]} GROUP BY b, d, ch",
                   array_merge($cp, [$p['start'], $p['end']], $scope[1]));
    $out = ['sent' => [], 'received' => [], 'failed' => [], 'read' => [], 'by_channel' => []];
    $sum = function (array $rows, string $col) use ($b) { $acc = []; foreach ($rows as $r) $acc[$r['b']] = ($acc[$r['b']] ?? 0) + (float) $r[$col]; return viz_fill($b, array_map(fn($k, $v) => ['b' => $k, 'n' => $v], array_keys($acc), $acc)); };
    $out['sent']     = $sum(array_filter($rows, fn($r) => $r['d'] === 'out'), 'n');
    $out['failed']   = $sum(array_filter($rows, fn($r) => $r['d'] === 'out'), 'f');
    $out['read']     = $sum(array_filter($rows, fn($r) => $r['d'] === 'out'), 'r');
    $out['received'] = $sum(array_filter($rows, fn($r) => $r['d'] === 'in'), 'n');
    foreach (['whatsapp', 'messenger', 'instagram'] as $ch) {
        $out['by_channel'][$ch] = $sum(array_filter($rows, fn($r) => $r['ch'] === $ch), 'n');
    }
    $out['labels'] = array_values($b);
    return $out;
}

/** Messages by channel and direction in the period: [channel => [in, out]]. */
function stats_channels(?int $clientId, string $start, string $end, array $scope = ['', []]): array
{
    if (!db_has_column('messages', 'channel')) return [];
    [$cw, $cp] = stats_client($clientId, 'm');
    $join = $scope[0] !== '' ? ' JOIN contacts c ON c.id=m.contact_id' : '';
    $out = [];
    foreach (db_all("SELECT m.channel ch, SUM(m.direction='in') i, SUM(m.direction='out') o, COUNT(DISTINCT m.contact_id) people
                       FROM messages m$join WHERE $cw AND m.created_at BETWEEN ? AND ?{$scope[0]} GROUP BY m.channel",
                    array_merge($cp, [$start, $end], $scope[1])) as $r) $out[$r['ch']] = ['in' => (int) $r['i'], 'out' => (int) $r['o'], 'people' => (int) $r['people']];
    return $out;
}

/** Outgoing messages by who/what sent them: campaign, automation, agent, qualifier, manual. */
function stats_sources(?int $clientId, string $start, string $end, array $scope = ['', []]): array
{
    [$cw, $cp] = stats_client($clientId, 'm');
    $join = $scope[0] !== '' ? ' JOIN contacts c ON c.id=m.contact_id' : '';
    return db_all("SELECT COALESCE(NULLIF(m.source,''),'other') src, COUNT(*) n, SUM(m.status='failed') failed, SUM(m.status='read') rd
                     FROM messages m$join WHERE $cw AND m.direction='out' AND m.created_at BETWEEN ? AND ?{$scope[0]}
                    GROUP BY src ORDER BY n DESC", array_merge($cp, [$start, $end], $scope[1]));
}

function stats_source_label(string $s): string
{
    return ['campaign' => 'Campaigns', 'automation' => 'Automations', 'agent' => 'AI agent', 'qualifier' => 'Lead Qualifier',
            'manual' => 'Typed by your team', 'crm' => 'From the CRM', 'other' => 'Other'][$s] ?? ucfirst($s);
}

/** Incoming messages by weekday (0 = Monday) and hour. */
function stats_heat(?int $clientId, string $start, string $end, array $scope = ['', []]): array
{
    [$cw, $cp] = stats_client($clientId, 'm');
    $join = $scope[0] !== '' ? ' JOIN contacts c ON c.id=m.contact_id' : '';
    $m = array_fill(0, 7, array_fill(0, 24, 0));
    foreach (db_all("SELECT WEEKDAY(m.created_at) d, HOUR(m.created_at) h, COUNT(*) n FROM messages m$join
                      WHERE $cw AND m.direction='in' AND m.created_at BETWEEN ? AND ?{$scope[0]} GROUP BY d, h",
                    array_merge($cp, [$start, $end], $scope[1])) as $r) $m[(int) $r['d']][(int) $r['h']] = (int) $r['n'];
    return $m;
}

/**
 * How fast people get an answer: for incoming messages that opened a conversation (none in the
 * 6 hours before), the time to the next outgoing message — median and the share answered in 5 minutes.
 * Sampled (latest 1500) so it stays quick on big accounts.
 */
function stats_reply_time(?int $clientId, string $start, string $end, array $scope = ['', []], bool $humansOnly = false): array
{
    [$cw, $cp] = stats_client($clientId, 'm');
    $join = $scope[0] !== '' ? ' JOIN contacts c ON c.id=m.contact_id' : '';
    $who = $humansOnly ? " AND o.source='manual'" : '';
    $rows = db_all("SELECT TIMESTAMPDIFF(SECOND, m.created_at,
                            (SELECT MIN(o.created_at) FROM messages o WHERE o.contact_id=m.contact_id AND o.direction='out' AND o.created_at >= m.created_at{$who}
                                AND o.created_at < m.created_at + INTERVAL 7 DAY)) s
                      FROM messages m$join
                     WHERE $cw AND m.direction='in' AND m.created_at BETWEEN ? AND ?{$scope[0]}
                       AND NOT EXISTS (SELECT 1 FROM messages p WHERE p.contact_id=m.contact_id AND p.direction='in' AND p.created_at < m.created_at
                                         AND p.created_at > m.created_at - INTERVAL 6 HOUR)
                     ORDER BY m.id DESC LIMIT 1500", array_merge($cp, [$start, $end], $scope[1]));
    $vals = array_values(array_filter(array_map(fn($r) => $r['s'] === null ? null : (int) $r['s'], $rows), fn($v) => $v !== null));
    sort($vals);
    $n = count($vals);
    return [
        'opened'   => count($rows),
        'answered' => $n,
        'median'   => $n ? (float) $vals[intdiv($n, 2)] : null,
        'in5'      => $n ? round(100 * count(array_filter($vals, fn($v) => $v <= 300)) / max(1, count($rows)), 1) : null,
        'unanswered' => count($rows) - $n,
    ];
}

/** Credits spent (net of refunds) and topped up in [start, end]. */
function stats_credits(?int $clientId, string $start, string $end): array
{
    [$cw, $cp] = stats_client($clientId, 't');
    $r = db_row("SELECT -SUM(IF(" . stats_spend_sql() . ", delta, 0)) used, SUM(IF(NOT " . stats_spend_sql() . " AND delta > 0, delta, 0)) added
                   FROM credit_transactions t WHERE $cw AND t.created_at BETWEEN ? AND ?", array_merge($cp, [$start, $end])) ?: [];
    return ['used' => max(0, (int) ($r['used'] ?? 0)), 'added' => (int) ($r['added'] ?? 0)];
}

function stats_credit_series(?int $clientId, array $p): array
{
    [$cw, $cp] = stats_client($clientId, 't');
    return viz_fill(viz_buckets($p), db_all("SELECT " . viz_bucket_sql('t.created_at', $p['bucket']) . " b, -SUM(t.delta) n FROM credit_transactions t
                                             WHERE $cw AND " . stats_spend_sql() . " AND t.created_at BETWEEN ? AND ? GROUP BY b",
                                            array_merge($cp, [$p['start'], $p['end']])));
}

/**
 * Campaign results for campaigns created in the period, with replies (an incoming message from the
 * recipient within 3 days of it being sent).
 */
function stats_campaigns(?int $clientId, string $start, string $end, int $limit = 200): array
{
    [$cw, $cp] = stats_client($clientId, 'k');
    $rows = db_all("SELECT k.id, k.client_id, k.name, k.status, k.created_at, k.total_count, k.sent_count, k.delivered_count, k.read_count, k.failed_count,
                           " . (db_has_column('campaigns', 'channel') ? 'k.channel' : "'whatsapp' channel") . ", t.wa_name template_name
                      FROM campaigns k LEFT JOIN templates t ON t.id=k.template_id
                     WHERE $cw AND k.created_at BETWEEN ? AND ? ORDER BY k.id DESC LIMIT $limit", array_merge($cp, [$start, $end]));
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $rep = [];
    foreach (db_all("SELECT cm.campaign_id id, COUNT(DISTINCT cm.contact_id) n FROM campaign_messages cm
                      WHERE cm.campaign_id IN (" . implode(',', $ids) . ") AND cm.sent_at IS NOT NULL
                        AND EXISTS (SELECT 1 FROM messages i WHERE i.contact_id=cm.contact_id AND i.direction='in'
                                      AND i.created_at BETWEEN cm.sent_at AND cm.sent_at + INTERVAL 3 DAY)
                      GROUP BY cm.campaign_id") as $r) $rep[(int) $r['id']] = (int) $r['n'];
    foreach ($rows as &$r) $r['replied'] = $rep[(int) $r['id']] ?? 0;
    return $rows;
}

/** Campaign messages sent / read / failed per bucket. */
function stats_campaign_series(?int $clientId, array $p): array
{
    [$cw, $cp] = stats_client($clientId, 'cm');
    $b = viz_buckets($p);
    $rows = db_all("SELECT " . viz_bucket_sql('COALESCE(cm.sent_at, cm.updated_at)', $p['bucket']) . " b,
                           SUM(cm.status IN ('sent','delivered','read')) sent, SUM(cm.status='read') rd, SUM(cm.status IN ('failed','dead','review')) failed
                      FROM campaign_messages cm WHERE $cw AND COALESCE(cm.sent_at, cm.updated_at) BETWEEN ? AND ? GROUP BY b",
                   array_merge($cp, [$p['start'], $p['end']]));
    return ['sent' => viz_fill($b, $rows, 'sent'), 'read' => viz_fill($b, $rows, 'rd'), 'failed' => viz_fill($b, $rows, 'failed'), 'labels' => array_values($b)];
}

/** Why messages failed, grouped by the reason people read. */
function stats_failures(?int $clientId, string $start, string $end, int $limit = 8): array
{
    [$cw, $cp] = stats_client($clientId, 'm');
    return db_all("SELECT COALESCE(NULLIF(m.error_title,''), 'No reason given') label, COUNT(*) n FROM messages m
                    WHERE $cw AND m.direction='out' AND m.status='failed' AND m.created_at BETWEEN ? AND ?
                    GROUP BY label ORDER BY n DESC LIMIT $limit", array_merge($cp, [$start, $end]));
}

/** Template performance in the period: sent, read, replies. */
function stats_templates(int $clientId, string $start, string $end): array
{
    if (!db_has_column('messages', 'template_id')) return [];
    $rows = db_all("SELECT t.id, t.wa_name label, COUNT(*) sent, SUM(m.status IN ('delivered','read')) delivered, SUM(m.status='read') rd, SUM(m.status='failed') failed,
                           SUM(EXISTS (SELECT 1 FROM messages i WHERE i.contact_id=m.contact_id AND i.direction='in'
                                         AND i.created_at BETWEEN m.created_at AND m.created_at + INTERVAL 3 DAY)) replied
                      FROM messages m JOIN templates t ON t.id=m.template_id
                     WHERE m.client_id=? AND m.direction='out' AND m.created_at BETWEEN ? AND ?
                     GROUP BY t.id ORDER BY sent DESC LIMIT 30", [$clientId, $start, $end]);
    foreach ($rows as &$r) {
        $ok = max(1, (int) $r['sent'] - (int) $r['failed']);
        $r['read_rate'] = round(100 * (int) $r['rd'] / $ok, 1);
        $r['reply_rate'] = round(100 * (int) $r['replied'] / $ok, 1);
    }
    return $rows;
}

/** Automations in the period: runs started, completed, waiting, blocked, per flow. */
function stats_flows(int $clientId, string $start, string $end): array
{
    return db_all("SELECT f.id, f.name label, f.kind, COUNT(r.id) runs, SUM(r.status='completed') completed,
                          SUM(r.status IN ('waiting_input','waiting_timer','active')) waiting, SUM(r.status='blocked') blocked
                     FROM flows f JOIN flow_runs r ON r.flow_id=f.id
                    WHERE f.client_id=? AND r.created_at BETWEEN ? AND ? GROUP BY f.id ORDER BY runs DESC LIMIT 30", [$clientId, $start, $end]);
}

function stats_flow_series(int $clientId, array $p, ?int $flowId = null): array
{
    $b = viz_buckets($p);
    $rows = db_all("SELECT " . viz_bucket_sql('r.created_at', $p['bucket']) . " b, COUNT(*) n, SUM(r.status='completed') done
                      FROM flow_runs r WHERE r.client_id=?" . ($flowId ? ' AND r.flow_id=' . (int) $flowId : '') . " AND r.created_at BETWEEN ? AND ? GROUP BY b",
                   [$clientId, $p['start'], $p['end']]);
    return ['runs' => viz_fill($b, $rows, 'n'), 'done' => viz_fill($b, $rows, 'done'), 'labels' => array_values($b)];
}

/** New contacts per bucket, and where they came from. */
function stats_contacts(int $clientId, array $p, array $scope = ['', []]): array
{
    $b = viz_buckets($p);
    $series = viz_fill($b, db_all("SELECT " . viz_bucket_sql('c.created_at', $p['bucket']) . " b, COUNT(*) n FROM contacts c
                                   WHERE c.client_id=? AND c.created_at BETWEEN ? AND ?{$scope[0]} GROUP BY b", array_merge([$clientId, $p['start'], $p['end']], $scope[1])));
    $src = db_all("SELECT COALESCE(NULLIF(c.source,''),'other') src, COUNT(*) n FROM contacts c
                    WHERE c.client_id=? AND c.created_at BETWEEN ? AND ?{$scope[0]} GROUP BY src ORDER BY n DESC LIMIT 10", array_merge([$clientId, $p['start'], $p['end']], $scope[1]));
    return ['series' => $series, 'sources' => $src];
}

/** Ratio as a percentage, or null when there is nothing to divide by. */
function stats_pct($n, $d): ?float
{
    return (float) $d > 0 ? round(100 * (float) $n / (float) $d, 1) : null;
}

/**
 * CRM leads in the period, the CRM's own way: leads added (crm_added_at) and deals won/lost
 * (moved into a won/lost stage, and still there). Needs crm.php and crm_reports.php.
 * Returns totals for [start, end] and, with $p, per-bucket series.
 */
function stats_leads(int $clientId, string $start, string $end, ?array $p = null): array
{
    [$w, $wp] = crm_report_where($clientId, []);
    $kinds = crm_stage_kinds($clientId);
    $out = ['leads' => (int) db_val("SELECT COUNT(*) FROM contacts c WHERE $w AND c.crm_added_at BETWEEN ? AND ?", array_merge($wp, [$start, $end]))];
    $bsql = $p ? viz_bucket_sql('e.created_at', $p['bucket']) : "''";
    foreach (['won', 'lost'] as $kind) {
        $ids = $kinds[$kind] ?: [0];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_all("SELECT $bsql b, COUNT(DISTINCT c.id) n FROM crm_events e JOIN contacts c ON c.id=e.contact_id
                         WHERE e.kind='stage' AND e.to_val IN ($ph) AND c.stage_id IN ($ph) AND e.created_at BETWEEN ? AND ? AND $w GROUP BY b",
                       array_merge(array_map('strval', $ids), $ids, [$start, $end], $wp));
        $out[$kind] = (int) array_sum(array_column($rows, 'n'));
        if ($p) $out[$kind . '_series'] = viz_fill(viz_buckets($p), $rows);
    }
    if ($p) {
        $out['leads_series'] = viz_fill(viz_buckets($p), db_all("SELECT " . viz_bucket_sql('c.crm_added_at', $p['bucket']) . " b, COUNT(*) n FROM contacts c
                                                                 WHERE $w AND c.crm_added_at BETWEEN ? AND ? GROUP BY b", array_merge($wp, [$p['start'], $p['end']])));
    }
    return $out;
}

/** Per client over the period: messages out/in, read, failed, credits used — for the platform admin. */
function stats_per_client(string $start, string $end): array
{
    $rows = db_all("SELECT cl.id, cl.name, cl.status, cl.credits_balance,
                           COALESCE(m.sent,0) sent, COALESCE(m.failed,0) failed, COALESCE(m.rd,0) rd, COALESCE(m.dl,0) dl, COALESCE(m.recv,0) recv, COALESCE(m.people,0) people,
                           COALESCE(t.used,0) used, COALESCE(k.n,0) campaigns
                      FROM clients cl
                      LEFT JOIN (SELECT client_id, SUM(direction='out' AND COALESCE(status,'') <> 'failed') sent, SUM(direction='out' AND status='failed') failed,
                                        SUM(direction='out' AND status='read') rd, SUM(direction='out' AND status IN ('delivered','read')) dl,
                                        SUM(direction='in') recv, COUNT(DISTINCT IF(direction='in', contact_id, NULL)) people
                                   FROM messages WHERE created_at BETWEEN ? AND ? GROUP BY client_id) m ON m.client_id=cl.id
                      LEFT JOIN (SELECT client_id, -SUM(delta) used FROM credit_transactions WHERE " . stats_spend_sql() . " AND created_at BETWEEN ? AND ? GROUP BY client_id) t ON t.client_id=cl.id
                      LEFT JOIN (SELECT client_id, COUNT(*) n FROM campaigns WHERE created_at BETWEEN ? AND ? GROUP BY client_id) k ON k.client_id=cl.id
                     ORDER BY sent DESC, cl.name", [$start, $end, $start, $end, $start, $end]);
    $days = max(1, (int) round((strtotime($end) - strtotime($start)) / 86400));
    foreach ($rows as &$r) {
        $burn = (float) $r['used'] / $days;
        $r['days_left'] = $burn > 0 ? (int) floor((int) $r['credits_balance'] / $burn) : null;
        $r['read_rate'] = stats_pct($r['rd'], $r['sent']);
    }
    return $rows;
}

/** Clients that sent anything in the period. */
function stats_active_clients(string $start, string $end): int
{
    return (int) db_val("SELECT COUNT(DISTINCT m.client_id) FROM messages m JOIN clients cl ON cl.id=m.client_id WHERE m.direction='out' AND m.created_at BETWEEN ? AND ?", [$start, $end]);
}
