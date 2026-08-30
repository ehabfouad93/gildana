<?php
declare(strict_types=1);
/**
 * Help & support — reachable from the floating button on every page.
 *
 * Deliberately at the app root rather than under client/ or admin/: both roles use it, and a
 * shared page means the FAQ can't drift into two copies.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/view.php';
require_once __DIR__ . '/includes/help.php';
require_once __DIR__ . '/includes/notify.php';

$me = current_user_full();
if (!$me) redirect('login.php');

$role     = ($me['role'] ?? '') === 'admin' ? 'admin' : 'client';
$clientId = $me['client_id'] ?? null;
$sent = false; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ticket') {
    verify_csrf();
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $email   = trim((string) ($_POST['email'] ?? ($me['email'] ?? '')));

    if ($subject === '' || $message === '') {
        $err = 'Please fill in both the subject and the message.';
    } else {
        // Stored first, mailed second: email fails quietly often enough that a request must
        // never depend on it to survive.
        db_run("INSERT INTO support_tickets (client_id,user_id,name,email,subject,message,status,created_at)
                VALUES (?,?,?,?,?,?, 'open', NOW())",
            [$clientId ? (int) $clientId : null, (int) $me['id'], user_display_name($me), $email,
             mb_substr($subject, 0, 200), $message]);

        if (function_exists('notify_admin')) {
            @notify_admin('Support request: ' . mb_substr($subject, 0, 120),
                "From: " . user_display_name($me) . " <{$email}>\n\n{$message}");
        }
        $sent = true;
    }
}

$faqs = faq_live();

layout_header('Help & Support', $role, '', []);
page_head('Help & Support');
?>

<?php if ($sent): ?>
  <div class="alert success">Thanks — your message is with us. We'll reply to <strong><?= e((string) ($me['email'] ?? '')) ?></strong>.</div>
<?php endif; ?>
<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<?php if (intro_video_on()): ?>
<div class="card">
  <h2>Getting started</h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 12px">A short walkthrough of the dashboard.</p>
  <div style="max-width:720px;aspect-ratio:16/9;background:#000;border-radius:10px;overflow:hidden">
    <?php $v = trim(help_setting('intro_video_url', '')); ?>
    <?php if (video_is_file($v)): ?>
      <video src="<?= e($v) ?>" controls playsinline style="width:100%;height:100%"></video>
    <?php else: ?>
      <iframe src="<?= e(video_embed_url($v)) ?>" allow="encrypted-media; fullscreen" allowfullscreen style="width:100%;height:100%;border:0"></iframe>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Frequently asked questions</h2>
  <?php if (!$faqs): ?>
    <p class="text-muted" style="font-size:13px">
      No questions have been published yet<?= $role === 'admin' ? ' — add some in <a href="admin/help_admin.php">Help Content</a>.' : '.' ?>
      Use the form below and we'll answer directly.
    </p>
  <?php else: ?>
    <div class="faq-list">
      <?php foreach ($faqs as $f): ?>
        <details class="faq-item">
          <summary><?= e((string) $f['question']) ?></summary>
          <div class="faq-answer"><?= nl2br(e((string) $f['answer'])) ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card" id="contact">
  <h2>Contact technical support</h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">
    Still stuck? Tell us what happened and we'll come back to you.
  </p>
  <form method="post" style="max-width:620px">
    <?= csrf_field() ?><input type="hidden" name="action" value="ticket">
    <div class="grid2">
      <div class="field"><span class="lbl">Your name</span>
        <input type="text" value="<?= e(user_display_name($me)) ?>" disabled></div>
      <div class="field"><span class="lbl">Reply to</span>
        <input type="email" name="email" value="<?= e((string) ($me['email'] ?? '')) ?>" required></div>
    </div>
    <div class="field"><span class="lbl">Subject</span>
      <input type="text" name="subject" maxlength="200" required placeholder="e.g. Messages aren't sending"></div>
    <div class="field"><span class="lbl">What's happening?</span>
      <textarea name="message" rows="6" required placeholder="What you did, what you expected, and what happened instead."></textarea></div>
    <button class="btn btn-primary">Send to support</button>
  </form>
</div>

<?php layout_footer(); ?>
