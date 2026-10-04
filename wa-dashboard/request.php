<?php
declare(strict_types=1);
/**
 * The "Get started" form on a page of its own — the link to put in an ad, a bio, an email
 * signature or a WhatsApp message. Same form as the landing page's, same place it lands
 * (Admin → Requests); utm_source / utm_medium / utm_campaign on the link are kept with the request.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/access_request.php';
require_once __DIR__ . '/includes/site_chrome.php';

[$sent, $err] = access_request_handle('link');
$b = brand_name();

site_page_open(['title' => 'Get started with ' . $b . ' — request your WhatsApp CRM account',
                'description' => 'Tell us about your business and we will set up ' . $b . ' with you: your WhatsApp number connected, your first leads imported and one automation working. A reply the same working day.',
                'path' => 'request.php', 'crumbs' => [[$b, ''], ['Get started', 'request.php']]]);
?>
<main class="section cta on-dark req-page" id="access">
  <div class="wrap cta-in">
    <div>
      <span class="eyebrow">Get started</span>
      <h1>Tell us about your business</h1>
      <p class="lead">
        We set the account up with you rather than handing you an empty box: your number
        connected, your first list imported, and one automation working before you are left
        alone with it.
      </p>
      <ul class="ticks">
        <li>A reply the same working day</li>
        <li>We tell you which of the two channels fits you, honestly</li>
        <li>Campaigns, an AI agent, lead scoring and a full sales CRM — on WhatsApp</li>
        <li>Your data is yours — messages, campaigns and contacts are never deleted</li>
      </ul>
      <p class="req-more">Questions first? <a href="help-center.php">Read the Help Center</a>.</p>
    </div>
    <?= access_request_form($sent, $err, 'request.php#access') ?>
  </div>
</main>
<?php site_page_close();
