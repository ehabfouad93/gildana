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
$miss = $st['perms_missing'] ?: [];
$cfgId = meta_cfg()['config_id'];
// One row of switches for a Page.
$row = function (array $p) use ($st, $isAdmin): string {
    $h = '<tr><td><strong>' . e((string) $p['name']) . '</strong>'
       . ($p['last_error'] ? '<span style="display:block;font-size:12px;color:var(--danger);max-width:320px">' . e(meta_explain_error((string) $p['last_error'])) . '</span>' : '') . '</td>'
       . '<td>' . (!empty($p['ig_username']) ? '@' . e((string) $p['ig_username']) : '<span class="text-muted">None linked</span>') . '</td>';
    foreach ($st['switches'] as $k => [$ch, $l]) {
        $on = (int) ($p[$k] ?? 0); $broken = $on && trim((string) $p['last_error']) !== '';
        $h .= '<td>' . ($isAdmin
            ? '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="action" value="social_set"><input type="hidden" name="page" value="' . (int) $p['id'] . '">'
              . '<input type="hidden" name="what" value="' . $k . '"><input type="hidden" name="on" value="' . ($on ? '' : '1') . '">'
              . '<button class="btn btn-sm ' . ($broken ? 'btn-ghost sc-broken' : ($on ? 'btn-primary' : 'btn-ghost')) . '" aria-pressed="' . ($on ? 'true' : 'false') . '" aria-label="' . e($l . ' for ' . $p['name']) . '"'
              . ($broken ? ' title="Switched on, but Facebook refused — see the reason under the Page name"' : '') . '>' . ($broken ? 'Not working' : ($on ? 'On' : 'Off')) . '</button></form>'
            : '<span class="pill ' . ($broken ? 'red' : ($on ? 'green' : 'gray')) . '">' . ($broken ? 'Not working' : ($on ? 'On' : 'Off')) . '</span>') . '</td>';
    }
    return $h . '</tr>';
};
$thead = '<thead><tr><th>Page</th><th>Instagram</th>' . implode('', array_map(fn($x) => '<th>' . e($x[1]) . '</th>', $st['switches'])) . '</tr></thead>';
$usedIds = array_map(fn($p) => (int) $p['id'], $st['used']);

client_header('Facebook & Instagram', 'inbox', $CLIENT);
page_head('Facebook & Instagram', function_exists('crm_enabled') && crm_enabled($CLIENT) && can_use('crm') ? '<a class="btn btn-ghost btn-sm" href="meta_leads.php">Lead forms →</a>' : '');
?>
<ol class="sc-steps" aria-label="Setup">
  <li class="<?= $step > 1 ? 'done' : 'now' ?>"><span>Connect your Facebook Page</span></li>
  <li class="<?= $step > 2 ? 'done' : ($step === 2 ? 'now' : '') ?>"><span><?= $st['pages'] && !$st['used'] ? 'Choose your Page' : 'Choose what arrives' ?></span></li>
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
<?php if ($miss): ?>
<div class="card sc-perms" id="perms">
  <h2 style="margin:0 0 6px">Facebook didn't give Revenect every permission</h2>
  <p style="margin:0 0 8px">On the Facebook screen these were not allowed, so messages and comments can't arrive yet:</p>
  <ul class="sc-need" style="color:var(--ink)">
    <?php foreach ($miss as $perm => $what): ?><li><strong><?= e(ucfirst($what)) ?></strong> <span class="text-muted">(<?= e($perm) ?>)</span></li><?php endforeach; ?>
  </ul>
  <p class="text-muted" style="font-size:12.5px;margin:10px 0">Press <strong>Reconnect</strong>. On the Facebook screen choose <em>Edit access</em> (or <em>Edit settings</em>), tick your Page and its Instagram account, and leave every permission switched on.</p>
  <?php if ($cfgId !== ''): ?>
    <p class="text-muted" style="font-size:12.5px;margin:0 0 10px">If Facebook never asks for these, the platform's Facebook app login (configuration <?= e($cfgId) ?>) doesn't include them yet — the Revenect administrator adds them in Meta for Developers → Facebook Login for Business → Configurations, and they need Advanced access (App Review).</p>
  <?php endif; ?>
  <?php if ($isAdmin): ?><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="fb_connect"><input type="hidden" name="return" value="social.php">
    <button class="btn btn-primary fb-connect-btn"><?= social_fb_icon() ?> Reconnect Facebook</button></form><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="card" id="choose">
  <h2 style="margin:0 0 4px"><?= $st['used'] ? 'Add another Page' : 'Choose your Page' ?></h2>
  <p class="text-muted" style="font-size:12.5px;margin:0 0 10px"><?= count($st['pages']) ?> Page<?= count($st['pages']) === 1 ? '' : 's' ?> came back from Facebook. Pick the one whose messages<?= isset($st['switches']['comments_on']) || isset($st['switches']['ig_comments_on']) ? ' and comments' : '' ?> Revenect should handle — everything your plan includes is switched on for it.</p>
  <form method="post" class="sc-choose"><?= csrf_field() ?><input type="hidden" name="action" value="social_use_page">
    <select name="page" required aria-label="Facebook Page">
      <option value="">Choose a Page…</option>
      <?php foreach ($st['pages'] as $p): if (in_array((int) $p['id'], $usedIds, true)) continue; ?>
        <option value="<?= (int) $p['id'] ?>"><?= e((string) $p['name']) ?><?= !empty($p['ig_username']) ? ' · @' . e((string) $p['ig_username']) : '' ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm"<?= $miss ? ' disabled title="Reconnect Facebook first (see above)"' : '' ?>>Use this Page</button>
  </form>
</div>
<?php endif; ?>

<?php if ($st['used']): ?>
<div class="card" id="social">
  <div class="row-between" style="flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0">Pages in use</h2>
      <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">What reaches Revenect from each Page.</p>
    </div>
    <?php if ($isAdmin && $st['missing'] && !$miss): ?>
      <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="social_all_on"><button class="btn btn-primary btn-sm">Turn everything on</button></form>
    <?php endif; ?>
  </div>
  <div class="table-wrap" style="margin-top:12px"><table class="data"><?= $thead ?><tbody><?php foreach ($st['used'] as $p) echo $row($p); ?></tbody></table></div>
</div>
<?php endif; ?>

<?php if (count($st['pages']) > count($st['used'])): ?>
<details class="card sc-all">
  <summary><strong>All your Pages (<?= count($st['pages']) ?>)</strong> <span class="text-muted" style="font-size:12.5px">— switch single things on or off per Page</span></summary>
  <div class="table-wrap" style="margin-top:12px"><table class="data"><?= $thead ?><tbody><?php foreach ($st['pages'] as $p) if (!in_array((int) $p['id'], $usedIds, true)) echo $row($p); ?></tbody></table></div>
</details>
<?php endif; ?>

<?php if ($isAdmin && !$miss): ?>
<form method="post" class="sc-reconnect"><?= csrf_field() ?><input type="hidden" name="action" value="fb_connect"><input type="hidden" name="return" value="social.php">
  <button class="btn btn-ghost btn-sm"><?= social_fb_icon() ?> Reconnect / add Pages from Facebook</button>
  <span class="text-muted" style="font-size:12px">— also fixes "permission" errors: Facebook asks again for what is missing.</span></form>
<?php endif; ?>
<?php if ($step === 3): ?>
  <div class="alert success">All set. New Messenger and Instagram messages appear in the <a href="inbox.php">Inbox</a><?= client_has_channel($CLIENT, 'fb_comments') || client_has_channel($CLIENT, 'ig_comments') ? '; comments on the <a href="comments.php">Comments</a> page' : '' ?>. Automations can start from them too (Automations → Trigger).</div>
<?php endif; ?>
<?php endif; ?>
<?php layout_footer(); ?>
