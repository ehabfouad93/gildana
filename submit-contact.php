<?php
declare(strict_types=1);
require __DIR__ . '/includes/functions.php';

const NOTIFY_EMAIL = 'info@gildana.net';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$type = (string) ($_POST['type'] ?? 'contact');
$now  = date('c');

/* ── newsletter signup ── */
if ($type === 'newsletter') {
    $email = trim((string) ($_POST['email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $rows   = read_json_list(NEWSLETTER_DATA_FILE);
        $rows[] = ['email' => $email, 'created_at' => $now];
        write_json_list(NEWSLETTER_DATA_FILE, $rows);

        /* email notification */
        $subject = 'New Newsletter Signup — Gildana';
        $body    = "A new subscriber joined the Gildana newsletter.\r\n\r\n"
                 . "Email : {$email}\r\n"
                 . "Date  : {$now}\r\n";

        send_notification(NOTIFY_EMAIL, $subject, $body, $email);
    }

    header('Location: index.php?joined=1#contact');
    exit;
}

/* ── contact form ── */
$name    = trim((string) ($_POST['name']    ?? ''));
$email   = trim((string) ($_POST['email']   ?? ''));
$phone   = trim((string) ($_POST['phone']   ?? ''));
$company = trim((string) ($_POST['company'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {

    /* save to JSON */
    $rows   = read_json_list(CONTACT_DATA_FILE);
    $rows[] = [
        'name'       => $name,
        'phone'      => $phone,
        'email'      => $email,
        'company'    => $company,
        'message'    => $message,
        'created_at' => $now,
    ];
    write_json_list(CONTACT_DATA_FILE, $rows);

    /* email notification */
    $subject = "New Contact from {$name} — Gildana";

    $body  = "You have received a new contact form submission on Gildana.\r\n";
    $body .= str_repeat('-', 48) . "\r\n\r\n";
    $body .= "Name    : {$name}\r\n";
    if ($email   !== '') $body .= "Email   : {$email}\r\n";
    if ($phone   !== '') $body .= "Phone   : {$phone}\r\n";
    if ($company !== '') $body .= "Company : {$company}\r\n";
    $body .= "\r\nMessage:\r\n{$message}\r\n\r\n";
    $body .= str_repeat('-', 48) . "\r\n";
    $body .= "Submitted: {$now}\r\n";
    $body .= "Reply directly to this email to respond to {$name}.\r\n";

    send_notification(NOTIFY_EMAIL, $subject, $body, $email, $name);
}

header('Location: index.php?sent=1#contact');
exit;

/* ──────────────────────────────────────────
   send_notification()
   Sends a plain-text email. Works on any PHP
   host with mail() configured (cPanel, etc.).
   $reply_email / $reply_name let you hit
   "Reply" in your email client to go straight
   back to the visitor.
────────────────────────────────────────── */
function send_notification(
    string $to,
    string $subject,
    string $body,
    string $reply_email = '',
    string $reply_name  = ''
): void {
    $from_name    = 'Gildana Website';
    $from_address = 'noreply@gildana.net';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "From: {$from_name} <{$from_address}>\r\n";

    if ($reply_email !== '' && filter_var($reply_email, FILTER_VALIDATE_EMAIL)) {
        $safe_name = $reply_name !== '' ? "{$reply_name} <{$reply_email}>" : $reply_email;
        $headers  .= "Reply-To: {$safe_name}\r\n";
    }

    $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

    @mail($to, $subject, $body, $headers);
}
