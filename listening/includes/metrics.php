<?php
declare(strict_types=1);

/**
 * Dashboard aggregates. Every query is client-scoped and hits one of the
 * composite indexes on `mentions` — see migrations/001_init.sql.
 */

/** Resolve a range selector into ['from','to','days'] UTC datetimes. */
function metrics_range(string $preset = '30'): array
{
    $days = in_array($preset, ['7', '30', '90'], true) ? (int) $preset : 30;
    return [
        'days' => $days,
        'from' => gmdate('Y-m-d H:i:s', strtotime('-' . ($days - 1) . ' days midnight') ?: time()),
        'to'   => gmdate('Y-m-d H:i:s'),
    ];
}

/**
 * Headline counters for the KPI row.
 * `net` is the share of classified mentions that are positive minus negative,
 * as a percentage — the single number a client actually asks about.
 */
function metrics_totals(int $clientId, array $range): array
{
    $row = db_row(
        "SELECT COUNT(*) AS total,
                SUM(sentiment = 'positive') AS positive,
                SUM(sentiment = 'negative') AS negative,
                SUM(sentiment = 'neutral')  AS neutral,
                SUM(sentiment = 'unknown')  AS unknown,
                SUM(is_read = 0)            AS unread,
                COALESCE(SUM(reach), 0)     AS reach
           FROM mentions
          WHERE client_id = ? AND is_hidden = 0
            AND COALESCE(published_at, fetched_at) BETWEEN ? AND ?",
        [$clientId, $range['from'], $range['to']]
    ) ?: [];

    $pos = (int) ($row['positive'] ?? 0);
    $neg = (int) ($row['negative'] ?? 0);
    $neu = (int) ($row['neutral'] ?? 0);
    $classified = $pos + $neg + $neu;

    return [
        'total'    => (int) ($row['total'] ?? 0),
        'positive' => $pos,
        'negative' => $neg,
        'neutral'  => $neu,
        'unknown'  => (int) ($row['unknown'] ?? 0),
        'unread'   => (int) ($row['unread'] ?? 0),
        'reach'    => (int) ($row['reach'] ?? 0),
        'net'      => $classified > 0 ? (int) round((($pos - $neg) / $classified) * 100) : 0,
    ];
}

/** Per-day sentiment counts, zero-filled so the chart has no gaps. */
function metrics_timeseries(int $clientId, array $range): array
{
    $rows = db_all(
        "SELECT DATE(COALESCE(published_at, fetched_at)) AS d,
                SUM(sentiment = 'positive') AS positive,
                SUM(sentiment = 'negative') AS negative,
                SUM(sentiment IN ('neutral','unknown')) AS neutral
           FROM mentions
          WHERE client_id = ? AND is_hidden = 0
            AND COALESCE(published_at, fetched_at) BETWEEN ? AND ?
          GROUP BY d ORDER BY d",
        [$clientId, $range['from'], $range['to']]
    );

    $byDay = [];
    foreach ($rows as $r) {
        $byDay[(string) $r['d']] = [
            'positive' => (int) $r['positive'],
            'negative' => (int) $r['negative'],
            'neutral'  => (int) $r['neutral'],
        ];
    }

    $out = [];
    for ($i = $range['days'] - 1; $i >= 0; $i--) {
        $day = gmdate('Y-m-d', strtotime("-{$i} days") ?: time());
        $out[] = ['day' => $day] + ($byDay[$day] ?? ['positive' => 0, 'negative' => 0, 'neutral' => 0]);
    }
    return $out;
}

/** Volume by connector, biggest first. */
function metrics_by_connector(int $clientId, array $range, int $limit = 8): array
{
    return db_all(
        "SELECT connector, COUNT(*) AS total,
                SUM(sentiment = 'negative') AS negative
           FROM mentions
          WHERE client_id = ? AND is_hidden = 0
            AND COALESCE(published_at, fetched_at) BETWEEN ? AND ?
          GROUP BY connector ORDER BY total DESC
          LIMIT " . (int) $limit,
        [$clientId, $range['from'], $range['to']]
    );
}

/** Volume by tracked keyword — brand vs competitor share of voice. */
function metrics_by_keyword(int $clientId, array $range, int $limit = 8): array
{
    return db_all(
        "SELECT k.id, k.term, k.kind, COUNT(m.id) AS total,
                SUM(m.sentiment = 'positive') AS positive,
                SUM(m.sentiment = 'negative') AS negative
           FROM keywords k
           LEFT JOIN mentions m
                  ON m.keyword_id = k.id AND m.is_hidden = 0
                 AND COALESCE(m.published_at, m.fetched_at) BETWEEN ? AND ?
          WHERE k.client_id = ?
          GROUP BY k.id, k.term, k.kind
          ORDER BY total DESC
          LIMIT " . (int) $limit,
        [$range['from'], $range['to'], $clientId]
    );
}

/** Loudest domains and authors in the range. */
function metrics_top_domains(int $clientId, array $range, int $limit = 8): array
{
    return db_all(
        "SELECT domain, COUNT(*) AS total
           FROM mentions
          WHERE client_id = ? AND is_hidden = 0 AND domain <> ''
            AND COALESCE(published_at, fetched_at) BETWEEN ? AND ?
          GROUP BY domain ORDER BY total DESC
          LIMIT " . (int) $limit,
        [$clientId, $range['from'], $range['to']]
    );
}

/** Newest mentions, optionally restricted to one sentiment. */
function metrics_recent(int $clientId, int $limit = 10, string $sentiment = ''): array
{
    $sql    = "SELECT * FROM mentions WHERE client_id = ? AND is_hidden = 0";
    $params = [$clientId];
    if ($sentiment !== '') { $sql .= " AND sentiment = ?"; $params[] = $sentiment; }
    $sql .= " ORDER BY COALESCE(published_at, fetched_at) DESC LIMIT " . (int) max(1, $limit);
    return db_all($sql, $params);
}

/** When did any of this client's sources last succeed? Drives the stale banner. */
function metrics_last_fetch(int $clientId): ?string
{
    $v = db_val("SELECT MAX(last_ok_at) FROM sources WHERE client_id = ?", [$clientId]);
    return $v ? (string) $v : null;
}
