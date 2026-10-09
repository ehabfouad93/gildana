<?php
declare(strict_types=1);

/**
 * "Get started" requests: the form on the landing page and on its own page (request.php, the
 * link to share), and what Admin → Requests does with them.
 *
 * There is no self-signup — an account is opened by the operator, with a WhatsApp number attached
 * — so the front door is a request, kept as a record the operator works through to an account.
 */
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/seo.php';

function access_request_statuses(): array
{
    return ['new' => 'New', 'contacted' => 'Contacted', 'converted' => 'Converted', 'declined' => 'Declined'];
}

function access_requests_ready(): bool
{
    static $ok = null;
    if ($ok === null) { try { db_val("SELECT 1 FROM access_requests LIMIT 1"); $ok = true; } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

/** Requests nobody has picked up yet: the badge on the admin menu. */
function access_request_new_count(): int
{
    if (!access_requests_ready()) return 0;
    try { return (int) db_val("SELECT COUNT(*) FROM access_requests WHERE status='new'"); } catch (Throwable $e) { return 0; }
}

/** The link to the form on its own page — the one to put in ads, bios, emails and signatures. */
function access_request_link(): string
{
    return seo_site_url() . '/request.php';
}

/**
 * Handle a POST from either copy of the form.
 * @return array{0:bool,1:string} [sent, error]
 */
function access_request_handle(string $source): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'access') return [false, ''];
    verify_csrf();
    $p = fn(string $k, int $max) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $name = $p('name', 120); $email = $p('email', 190); $job = $p('job_title', 120);
    $company = $p('company', 150); $phone = $p('phone', 32); $about = $p('about', 2000);

    // Hidden from people, irresistible to bots. Silently accepted so the bot doesn't retry.
    if (trim((string) ($_POST['website'] ?? '')) !== '') return [true, ''];
    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
        return [false, 'Please give us your name and a valid email address.'];
    // Five requests an hour from one address is plenty for a person; more is a bot filling the inbox.
    $ip = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if ($ip !== '' && access_requests_ready()
        && (int) db_val("SELECT COUNT(*) FROM access_requests WHERE ip=? AND created_at > NOW() - INTERVAL 1 HOUR", [$ip]) >= 5)
        return [false, 'We already have several requests from you — we will be in touch shortly.'];

    $body = "Name: {$name}\nJob title: " . ($job ?: '—') . "\nEmail: {$email}\nBusiness: " . ($company ?: '—')
          . "\nWhatsApp: " . ($phone ?: '—') . "\n\n" . ($about ?: '(no message)');
    try {
        if (access_requests_ready()) {
            $u = fn(string $k) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, 100) ?: null;
            db_insert("INSERT INTO access_requests (name,email,job_title,company,phone,about,status,source,utm_source,utm_medium,utm_campaign,referrer,ip,user_agent,created_at)
                       VALUES (?,?,?,?,?,?,'new',?,?,?,?,?,?,?,NOW())",
                      [$name, $email, $job ?: null, $company ?: null, $phone ?: null, $about ?: null, $source,
                       $u('utm_source'), $u('utm_medium'), $u('utm_campaign'),
                       mb_substr(trim((string) ($_POST['ref'] ?? '')), 0, 255) ?: null,
                       mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                       mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null]);
        } else {
            // Before migration 060: where requests always went.
            db_run("INSERT INTO support_tickets (client_id,user_id,name,email,subject,message,status,created_at)
                    VALUES (NULL, NULL, ?, ?, ?, ?, 'open', NOW())",
                   [$name, $email, 'Access request: ' . mb_substr($company ?: $name, 0, 150), $body]);
        }
        @notify_admin('Access request from ' . $name, $body . "\n\nOpen it: " . seo_site_url() . '/admin/requests.php');
        return [true, ''];
    } catch (Throwable $e) {
        error_log('access request failed: ' . $e->getMessage());
        return [false, 'Something went wrong on our side. Please try again in a moment.'];
    }
}

/** The form itself, shared by the landing page and request.php. */
function access_request_form(bool $sent, string $err, string $action): string
{
    // Where the visitor came from, carried through the POST so it is stored with the request.
    $carry = '';
    foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $k) {
        $v = (string) ($_POST[$k] ?? $_GET[$k] ?? '');
        if ($v !== '') $carry .= '<input type="hidden" name="' . $k . '" value="' . e(mb_substr($v, 0, 100)) . '">';
    }
    $ref = (string) ($_POST['ref'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '' && !str_contains($ref, (string) ($_SERVER['HTTP_HOST'] ?? '~'))) $carry .= '<input type="hidden" name="ref" value="' . e(mb_substr($ref, 0, 255)) . '">';
    $v = fn(string $k) => $sent ? '' : old($k);

    ob_start(); ?>
    <form class="form" method="post" action="<?= e($action) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="access">
      <?= $carry ?>
      <?php if ($sent): ?>
        <div class="note ok" role="status">Thank you — that is with us. We will reply by email today or
          the next working day.</div>
      <?php elseif ($err): ?>
        <div class="note bad" role="alert"><?= e($err) ?></div>
      <?php endif; ?>

      <div class="fi">
        <label for="a-name">Your name</label>
        <input id="a-name" type="text" name="name" value="<?= $v('name') ?>" required maxlength="120" autocomplete="name">
      </div>
      <div class="fi">
        <label for="a-job">Job title</label>
        <input id="a-job" type="text" name="job_title" value="<?= $v('job_title') ?>" maxlength="120" autocomplete="organization-title" placeholder="e.g. Sales Director, Marketing Manager, Owner">
      </div>
      <div class="fi">
        <label for="a-email">Email</label>
        <input id="a-email" type="email" name="email" value="<?= $v('email') ?>" required maxlength="190" autocomplete="email">
      </div>
      <div class="fi">
        <label for="a-company">Business name</label>
        <input id="a-company" type="text" name="company" value="<?= $v('company') ?>" maxlength="150" autocomplete="organization">
      </div>
      <div class="fi">
        <label for="a-phone">WhatsApp number <span style="font-weight:400;opacity:.6">(optional)</span></label>
        <input id="a-phone" type="tel" name="phone" value="<?= $v('phone') ?>" maxlength="32" placeholder="+20 …" autocomplete="tel">
      </div>
      <div class="fi">
        <label for="a-about">What do you want to use it for?</label>
        <textarea id="a-about" name="about" maxlength="2000" placeholder="e.g. we have 4,000 customers in a sheet and want to send an offer, then answer whoever replies"><?= $v('about') ?></textarea>
      </div>
      <!-- Hidden from people, irresistible to bots. -->
      <div style="position:absolute;left:-9999px" aria-hidden="true">
        <label for="a-website">Website</label>
        <input id="a-website" type="text" name="website" tabindex="-1" autocomplete="off">
      </div>
      <button type="submit" class="btn btn-primary">Request access</button>
      <p class="fine">Already have an account? <a href="login.php" style="color:#fff">Log in</a>.</p>
    </form>
    <?php return (string) ob_get_clean();
}

/** A best guess at the country code from a number written with "+", for the new account's default. */
function access_request_country(string $phone): string
{
    $d = preg_replace('~\D~', '', $phone);
    if (!str_starts_with(trim($phone), '+') && !str_starts_with(trim($phone), '00')) return '';
    if (str_starts_with(trim($phone), '00')) $d = substr($d, 2);
    foreach (['966', '971', '965', '974', '973', '968', '962', '961', '964', '212', '213', '216', '218', '249', '20', '44', '49', '33', '90', '1'] as $cc)
        if (str_starts_with($d, $cc)) return $cc;
    return '';
}
