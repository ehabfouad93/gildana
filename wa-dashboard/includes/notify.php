<?php
declare(strict_types=1);

/**
 * Best-effort admin email notifications (campaign complete, low credit).
 * Uses PHP mail(); silently no-ops if the host has no mailer.
 */
function notify_admin(string $subject, string $body): void
{
    $to = (string) config('admin_email', '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;

    $appName = (string) config('app_name', 'WhatsApp Dashboard');
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "From: {$appName} <noreply@gildana.net>\r\n";
    $headers .= 'X-Mailer: PHP/' . PHP_VERSION . "\r\n";

    @mail($to, $subject, $body, $headers);
}
