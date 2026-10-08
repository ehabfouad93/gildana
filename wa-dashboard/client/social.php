<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/social_connect.php';

/**
 * Facebook & Instagram: connect the Page once, then choose what arrives — Messenger and Instagram
 * messages in the Inbox, comments on the Comments page. Reached from the Inbox, Comments and the
 * CRM; lead forms keep their own page under CRM.
 */
$cid = (int) $CLIENT['id'];
$isAdmin = is_client_admin();
if (!client_has_social($CLIENT)) {
    client_header('Facebook & Instagram', 'inbox', $CLIENT);
    page_head('Facebook & Instagram');
    echo '<div class="card"><div class="empty">Facebook and Instagram are not part of your plan. Contact the Revenect team to add Messenger, Instagram or comments.</div></div>';
    layout_footer(); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $r = social_connect_handle($CLIENT, $_POST, $isAdmin, (int) ($PERM_USER['id'] ?? 0));
    if ($r) { flash($r[0], $r[1]); redirect($r[2]); }
    redirect('social.php');
}

$st = social_connect_status($CLIENT);
$step = !$st['pages'] ? 1 : ($st['missing'] ? 2 : 3);

client_header('Facebook & Instagram', 'inbox', $CLIENT);
page_head('Facebook & Instagram', function_exists('crm_enabled') && crm_enabled($CLIENT) && can_use('crm') ? '<a class="btn btn-ghost btn-sm" href="meta_leads.php">Lead forms →</a>' : '');
?>
<ol class="sc-steps" aria-label="Setup">
  <li class="<?= $step > 1 ? 'done' : 'now' ?>"><span>Connect your Facebook Page</span></li>
  <li class="<?= $step > 2 ? 'done' : ($step === 2 ? 'now' : '') ?>"><span>Choose what arrives</span></li>
  <li class="<?= $step === 3 ? 'done now' : '' ?>"><span>Answer from the Inbox</span></li>
</ol>

<?php if (!$st['pages']): ?>
<div class="card sc-hero">
  <div>
    <h2 style="margin:0 0 6px">Connect Facebook &amp; Instagram</h2>
    <p class="text-muted" style="margin:0;max-width:620px">Sign in with the Facebook account that manages your Page, tick the Page (and its Instagram account)
      and allow the permissions. Messages then arrive in the <a href="inbox.php">Inbox</a> next to WhatsApp<?= client_has_channel($CLIENT, 'fb_comments') || client_has_channel($CLIENT, 'ig_comments') ? ', and comments on the <a href="comments.php">Comments</a> page' : '' ?>.</p>
    <ul class="sc-need">
      <li>You are an admin of the Facebook Page.</li>
      <?php if (client_has_channel($CLIENT, 'instagram') || client_has_channel($CLIENT, 'ig_comments')): ?>
      <li>Instagram: a professional (Business or Creator) account linked to that Page, with <em>Allow access to messages</em> on in the Instagram app (Settings → Privacy → Messages).</li>
      <?php endif; ?>
    </ul>
  </div>
  <?php if (!$st['configured']): ?>
    <div class="alert error" style="margin:0">Facebook is not set up on this platform yet — ask the Revenect team.</div>
  <?php elseif ($isAdmin): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fb_connect"><input type="hidden" name="return" value="social.php">
      <button class="btn btn-primary fb-connect-btn"><?= social_fb_icon() ?> Connect Facebook &amp; Instagram</button></form>
  <?php else: ?>
    <p class="text-muted">Ask your account Admin to connect it.</p>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card" id="social">
  <div class="row-between" style="flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0">Messages and comments</h2>
      <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Choose what reaches Revenect from each Page.</p>
    </div>
    <?php if ($isAdmin && $st['missing']): ?>
      <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="social_all_on"><button class="btn btn-primary btn-sm">Turn everything on</button></form>
    <?php endif; ?>
  </div>
  <div class="table-wrap" style="margin-top:12px"><table class="data"><thead><tr><th>Page</th><th>Instagram</th><?php foreach ($st['switches'] as [$ch, $l]): ?><th><?= e($l) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($st['pages'] as $p): ?>
    <tr><td><strong><?= e((string) $p['name']) ?></strong><?php if ($p['last_error']): ?><span style="display:block;font-size:12px;color:var(--danger)"><?= e(meta_explain_error((string) $p['last_error'])) ?></span><?php endif; ?></td>
      <td><?= !empty($p['ig_username']) ? '@' . e((string) $p['ig_username']) : '<span class="text-muted">None linked</span>' ?></td>
      <?php foreach ($st['switches'] as $k => [$ch, $l]): $on = (int) ($p[$k] ?? 0); ?>
        <td><?php if ($isAdmin): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="social_set"><input type="hidden" name="page" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="what" value="<?= $k ?>"><input type="hidden" name="on" value="<?= $on ? '' : '1' ?>">
          <button class="btn btn-sm <?= $on ? 'btn-primary' : 'btn-ghost' ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>" aria-label="<?= e($l . ' for ' . $p['name']) ?>"><?= $on ? 'On' : 'Off' ?></button></form>
          <?php else: ?><span class="pill <?= $on ? 'green' : 'gray' ?>"><?= $on ? 'On' : 'Off' ?></span><?php endif; ?></td>
      <?php endforeach; ?></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php if ($isAdmin): ?>
  <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="fb_connect"><input type="hidden" name="return" value="social.php">
    <button class="btn btn-ghost btn-sm"><?= social_fb_icon() ?> Reconnect / add another Page</button>
    <span class="text-muted" style="font-size:12px">— also fixes "permission" errors: Meta asks again for what is missing.</span></form>
  <?php endif; ?>
</div>
<?php if ($step === 3): ?>
  <div class="alert success">All set. New Messenger and Instagram messages appear in the <a href="inbox.php">Inbox</a><?= client_has_channel($CLIENT, 'fb_comments') || client_has_channel($CLIENT, 'ig_comments') ? '; comments on the <a href="comments.php">Comments</a> page' : '' ?>. Automations can start from them too (Automations → Trigger).</div>
<?php endif; ?>
<?php endif; ?>
<?php layout_footer(); ?>
