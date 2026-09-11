<?php
declare(strict_types=1);

/**
 * Outbound email. Uses PHP mail(), which is what shared cPanel hosting gives us
 * without Composer; silently no-ops when the host has no mailer configured.
 *
 * Subjects are MIME-encoded — an Arabic subject line sent as raw UTF-8 arrives
 * as mojibake in most clients, and these alerts are frequently Arabic.
 *
 * Deliverability note for the operator: mail() from a subdomain without an SPF
 * record aligned to the sending host tends to land in spam, and an alert in
 * spam is worse than no alert. See DEPLOY.md before relying on this.
 */

function notify_mail(string $to, string $subject, string $bodyText): bool
{
    if (trim($to) === '') return false;

    $appName = (string) config('app_name', 'Gildana Listening');
    $from    = (string) config('mail_from', 'noreply@gildana.net');
    $replyTo = (string) config('admin_email', '');

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= 'From: ' . mb_encode_mimeheader($appName, 'UTF-8', 'B') . " <{$from}>\r\n";
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers .= "Reply-To: {$replyTo}\r\n";
    }
    $headers .= 'X-Mailer: PHP/' . PHP_VERSION . "\r\n";

    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B');

    return @mail($to, $encodedSubject, $bodyText, $headers);
}

/** Agency-level notification: a source stuck in error, the worker stalling. */
function notify_admin(string $subject, string $body): void
{
    $to = (string) config('admin_email', '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    notify_mail($to, $subject, $body);
}

/** Notify a client at its configured alert address. */
function notify_client(array $client, string $subject, string $body): bool
{
    $to = trim((string) ($client['alert_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    return notify_mail($to, $subject, $body);
}

/**
 * Send the daily/weekly digests that are due.
 * Idempotent: last_digest_at is stamped whether or not mail() succeeds, so a
 * broken mailer cannot turn into a loop that re-sends every tick.
 */
function digests_run(int $limit = 5): int
{
    $rows = db_all(
        "SELECT * FROM clients
          WHERE status = 'active' AND digest_freq <> 'off' AND alert_email <> ''
            AND (last_digest_at IS NULL
                 OR (digest_freq = 'daily'  AND last_digest_at <= DATE_SUB(NOW(), INTERVAL 1 DAY))
                 OR (digest_freq = 'weekly' AND last_digest_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)))
          LIMIT " . (int) max(1, $limit)
    );

    $sent = 0;
    foreach ($rows as $client) {
        $days  = (string) $client['digest_freq'] === 'weekly' ? 7 : 1;
        $range = ['days' => $days,
                  'from' => gmdate('Y-m-d H:i:s', strtotime("-{$days} days") ?: time()),
                  'to'   => gmdate('Y-m-d H:i:s')];

        $cid = (int) $client['id'];
        $t   = metrics_totals($cid, $range);

        $brand = (string) ($client['brand_name'] ?: $client['name']);
        $subject = sprintf('%s — %s', $brand, $days === 7 ? 'weekly listening summary' : 'daily listening summary');

        $lines = [
            $brand . ' — last ' . $days . ' day(s)',
            '',
            'Total mentions : ' . $t['total'],
            'Positive       : ' . $t['positive'],
            'Negative       : ' . $t['negative'],
            'Neutral        : ' . $t['neutral'],
            'Net sentiment  : ' . $t['net'] . '%',
            'Estimated reach: ' . number_format($t['reach']),
            '',
        ];

        $negatives = metrics_recent($cid, 5, 'negative');
        if ($negatives) {
            $lines[] = 'Most recent negative mentions:';
            foreach ($negatives as $m) {
                $lines[] = '• ' . trim((string) ($m['title'] ?: $m['snippet']));
                if ((string) $m['url'] !== '') $lines[] = '  ' . (string) $m['url'];
            }
            $lines[] = '';
        }

        $base = rtrim((string) config('base_url', ''), '/');
        if ($base !== '') $lines[] = 'Open the dashboard: ' . $base . '/client/index.php';

        if (notify_client($client, $subject, implode("\n", $lines))) $sent++;

        db_run("UPDATE clients SET last_digest_at = NOW() WHERE id = ?", [$cid]);
    }
    return $sent;
}
