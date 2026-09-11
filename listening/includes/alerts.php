<?php
declare(strict_types=1);

/**
 * Alert rules: evaluate, fire, deliver.
 *
 * Two design choices worth stating. Alerts are batched — one notification
 * listing ten negative mentions, never ten emails. And a spike is measured
 * against the client's own baseline, because five negatives is noise for one
 * brand and a crisis for another.
 */

/** Rules that are active and past their cooldown. */
function alert_rules_due(int $clientId = 0): array
{
    $sql = "SELECT r.*, c.name AS client_name, c.alert_email, c.brand_name
              FROM alert_rules r
              JOIN clients c ON c.id = r.client_id
             WHERE r.status = 'active' AND c.status = 'active'
               AND (r.last_fired_at IS NULL
                    OR r.last_fired_at <= DATE_SUB(NOW(), INTERVAL r.cooldown_min MINUTE))";
    $params = [];
    if ($clientId > 0) { $sql .= " AND r.client_id = ?"; $params[] = $clientId; }
    return db_all($sql . " ORDER BY r.client_id, r.id", $params);
}

/**
 * Decide whether a rule should fire right now.
 *
 * @return array{fire:bool,level:string,title:string,body:string,mention_ids:array,count:int}
 */
function alert_evaluate(array $rule, bool $dryRun = false): array
{
    $cid    = (int) $rule['client_id'];
    $window = max(5, (int) $rule['window_minutes']);
    $type   = (string) $rule['type'];
    $minCon = (float) $rule['min_confidence'];
    $none   = ['fire' => false, 'level' => 'info', 'title' => '', 'body' => '', 'mention_ids' => [], 'count' => 0];

    // Only consider mentions we have not already alerted on.
    $since = $rule['last_fired_at'] && !$dryRun
        ? (string) $rule['last_fired_at']
        : gmdate('Y-m-d H:i:s', time() - $window * 60);

    $where  = "m.client_id = ? AND m.is_hidden = 0 AND m.fetched_at >= ?";
    $params = [$cid, $since];

    if (!empty($rule['keyword_id'])) { $where .= " AND m.keyword_id = ?"; $params[] = (int) $rule['keyword_id']; }
    if ((string) $rule['connector'] !== '') { $where .= " AND m.connector = ?"; $params[] = (string) $rule['connector']; }

    switch ($type) {
        case 'any_negative':
            $where .= " AND m.sentiment = 'negative' AND m.sentiment_confidence >= ?";
            $params[] = $minCon;
            break;

        case 'high_reach_negative':
            $where .= " AND m.sentiment = 'negative' AND (m.reach >= ? OR m.author_followers >= ?)";
            $params[] = (int) $rule['threshold'];
            $params[] = (int) $rule['threshold'];
            break;

        case 'keyword_match':
            // Any mention at all — used for crisis terms and competitor launches.
            break;

        case 'negative_spike':
            $where .= " AND m.sentiment = 'negative' AND m.sentiment_confidence >= ?";
            $params[] = $minCon;
            break;

        case 'volume_spike':
            break;

        default:
            return $none;
    }

    $rows = db_all(
        "SELECT m.id, m.title, m.snippet, m.url, m.connector, m.sentiment
           FROM mentions m WHERE $where
          ORDER BY m.id DESC LIMIT 50",
        $params
    );

    $count = count($rows);
    if ($count === 0) return $none;

    $threshold = max(1, (int) $rule['threshold']);
    $brand     = (string) ($rule['brand_name'] ?: $rule['client_name']);

    if ($type === 'negative_spike' || $type === 'volume_spike') {
        if ($count < $threshold) return $none;

        // Compare against this client's own 7-day baseline for the same window
        // length. Without it a busy brand alerts constantly and a quiet one never.
        $isNeg    = $type === 'negative_spike';
        $baseline = (float) db_val(
            "SELECT COUNT(*) / 7.0
               FROM mentions
              WHERE client_id = ? AND is_hidden = 0"
              . ($isNeg ? " AND sentiment = 'negative'" : "")
              . " AND fetched_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$cid]
        );
        // Per-window share of the daily average.
        $expected = $baseline * ($window / 1440);
        if ($expected > 0.5 && $count < $expected * 2) return $none;
    } elseif ($count < $threshold) {
        return $none;
    }

    $ids   = array_map('intval', array_column($rows, 'id'));
    $level = in_array($type, ['negative_spike', 'high_reach_negative'], true) ? 'critical'
           : ($type === 'any_negative' ? 'warn' : 'info');

    $title = sprintf('%s — %s (%d)', $brand, (string) $rule['name'], $count);

    $lines = [];
    foreach (array_slice($rows, 0, 10) as $r) {
        $lines[] = '• ' . trim((string) ($r['title'] ?: $r['snippet']));
        if ((string) $r['url'] !== '') $lines[] = '  ' . (string) $r['url'];
    }
    if ($count > 10) $lines[] = sprintf('… and %d more.', $count - 10);

    return [
        'fire' => true, 'level' => $level, 'title' => $title,
        'body' => implode("\n", $lines), 'mention_ids' => $ids, 'count' => $count,
    ];
}

/** Record a fired alert and stamp the rule's cooldown. */
function alert_fire(array $rule, array $eval): int
{
    $id = db_insert(
        "INSERT INTO alerts (client_id, rule_id, mention_id, level, title, body, payload_json,
                             email_status, is_read, created_at)
         VALUES (?,?,?,?,?,?,?,?,0,NOW())",
        [
            (int) $rule['client_id'], (int) $rule['id'],
            $eval['mention_ids'] ? (int) $eval['mention_ids'][0] : null,
            $eval['level'], mb_substr($eval['title'], 0, 200), $eval['body'],
            json_encode(['mention_ids' => $eval['mention_ids'], 'count' => $eval['count'],
                         'window' => (int) $rule['window_minutes']], JSON_UNESCAPED_UNICODE),
            alert_recipients($rule) ? 'pending' : 'skipped',
        ]
    );
    db_run("UPDATE alert_rules SET last_fired_at = NOW() WHERE id = ?", [(int) $rule['id']]);
    return $id;
}

/** Rule recipients, falling back to the client's alert email. */
function alert_recipients(array $rule): array
{
    $raw = trim((string) $rule['recipients']);
    if ($raw === '') $raw = trim((string) ($rule['alert_email'] ?? ''));
    $out = [];
    foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $addr) {
        $addr = trim($addr);
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) $out[] = $addr;
    }
    return array_values(array_unique($out));
}

/** Evaluate every due rule. Returns the number that fired. */
function alerts_run(int $clientId = 0): int
{
    $fired = 0;
    foreach (alert_rules_due($clientId) as $rule) {
        try {
            $eval = alert_evaluate($rule);
        } catch (Throwable $ex) {
            error_log('alert rule ' . $rule['id'] . ' failed: ' . $ex->getMessage());
            continue;
        }
        if (!$eval['fire']) continue;
        alert_fire($rule, $eval);
        $fired++;
    }
    return $fired;
}

/**
 * Send the pending alert emails.
 *
 * The in-app `alerts` row is written first and independently, so a host with no
 * working mailer still shows every alert in the UI — the notification is never
 * lost, only its delivery channel.
 */
function alerts_deliver(int $limit = 50): int
{
    $rows = db_all(
        "SELECT a.*, r.recipients, c.alert_email, c.name AS client_name
           FROM alerts a
           LEFT JOIN alert_rules r ON r.id = a.rule_id
           JOIN clients c ON c.id = a.client_id
          WHERE a.email_status = 'pending'
          ORDER BY a.id
          LIMIT " . (int) max(1, $limit)
    );

    $sent = 0;
    foreach ($rows as $a) {
        $to = alert_recipients([
            'recipients'  => (string) ($a['recipients'] ?? ''),
            'alert_email' => (string) ($a['alert_email'] ?? ''),
        ]);
        if (!$to) {
            db_run("UPDATE alerts SET email_status = 'skipped' WHERE id = ?", [(int) $a['id']]);
            continue;
        }

        $body = (string) $a['body'] . "\n\n—\n" . t('app.name');
        $ok   = notify_mail(implode(',', $to), (string) $a['title'], $body);

        db_run("UPDATE alerts SET email_status = ?, email_error = ? WHERE id = ?",
            [$ok ? 'sent' : 'failed', $ok ? '' : 'mail() returned false', (int) $a['id']]);
        if ($ok) $sent++;
    }
    return $sent;
}
