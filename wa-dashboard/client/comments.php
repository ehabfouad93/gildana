<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';
require_once __DIR__ . '/../includes/automation.php';
require_once __DIR__ . '/../includes/social.php';

/**
 * Comments on the client's Facebook posts and Instagram posts / reels, in one feed grouped by post:
 * answer publicly or privately, hide, delete, open the person's conversation or make them a lead.
 * The Rules tab holds moderation (hide / delete what matches) and simple keyword auto-replies,
 * applied as comments arrive, before any automation.
 */
$cid = (int) $CLIENT['id'];
$hasFb = client_has_channel($CLIENT, 'fb_comments');
$hasIg = client_has_channel($CLIENT, 'ig_comments');
if (!$hasFb && !$hasIg) { http_response_code(403); exit('Comments are not part of your plan.'); }
$tab = ($_GET['tab'] ?? '') === 'rules' ? 'rules' : 'feed';
$me  = current_user_full() ?: [];
$by  = 'user:' . (int) ($me['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act  = (string) ($_POST['action'] ?? '');
    $back = (string) ($_POST['back'] ?? 'comments.php');
    if (!preg_match('~^comments\.php(\?[^\s<>"]*)?$~', $back)) $back = 'comments.php';

    if (str_starts_with($act, 'rule_')) {
        if (!is_client_admin()) { flash('Only an admin can change comment rules.', 'error'); redirect('comments.php?tab=rules'); }
        $rid = (int) ($_POST['rule_id'] ?? 0);
        $rule = $rid ? db_row("SELECT * FROM social_rules WHERE id=? AND client_id=?", [$rid, $cid]) : null;
        if ($act === 'rule_save') {
            $kind  = ($_POST['kind'] ?? '') === 'reply' ? 'reply' : 'moderate';
            $plat  = in_array($_POST['platform'] ?? '', ['fb', 'ig'], true) ? (string) $_POST['platform'] : 'both';
            $match = in_array($_POST['match_kind'] ?? '', ['keywords', 'phone', 'link', 'any', 'ai'], true) ? (string) $_POST['match_kind'] : 'keywords';
            if ($kind === 'reply' && in_array($match, ['phone', 'link', 'ai'], true)) $match = 'keywords';
            $posts = implode(',', array_slice(array_filter(array_map(fn($p) => preg_replace('/[^0-9A-Za-z_]/', '', (string) $p), (array) ($_POST['posts'] ?? []))), 0, 50));
            $vals = [
                'name'       => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120),
                'kind'       => $kind, 'platform' => $plat, 'posts' => $posts !== '' ? $posts : null,
                'match_kind' => $match,
                'keywords'   => mb_substr(trim((string) ($_POST['keywords'] ?? '')), 0, 4000),
                'action'     => ($_POST['rule_action'] ?? '') === 'delete' ? 'delete' : 'hide',
                'reply_text' => mb_substr(trim((string) ($_POST['reply_text'] ?? '')), 0, 2000),
                'dm_text'    => mb_substr(trim((string) ($_POST['dm_text'] ?? '')), 0, 2000),
                'active'     => !empty($_POST['active']) ? 1 : 0,
            ];
            $err = '';
            if ($match === 'keywords' && $vals['keywords'] === '') $err = 'Add at least one keyword, or choose "Any comment".';
            if ($kind === 'reply' && $vals['reply_text'] === '' && $vals['dm_text'] === '') $err = 'Write the public reply, the private message, or both.';
            if ($err !== '') { flash($err, 'error'); redirect('comments.php?tab=rules' . ($rid ? '&edit=' . $rid : '&new=' . $kind)); }
            if ($vals['name'] === '') $vals['name'] = $kind === 'reply' ? 'Auto-reply' : 'Moderation';
            if ($rule) {
                db_run("UPDATE social_rules SET name=?, kind=?, platform=?, posts=?, match_kind=?, keywords=?, action=?, reply_text=?, dm_text=?, active=? WHERE id=?",
                       array_merge(array_values($vals), [$rid]));
            } else {
                $sort = (int) db_val("SELECT COALESCE(MAX(sort),0)+1 FROM social_rules WHERE client_id=?", [$cid]);
                db_insert("INSERT INTO social_rules (name,kind,platform,posts,match_kind,keywords,action,reply_text,dm_text,active,client_id,sort,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
                          array_merge(array_values($vals), [$cid, $sort]));
            }
            flash('Rule saved.');
        } elseif ($rule && $act === 'rule_toggle') {
            db_run("UPDATE social_rules SET active=1-active WHERE id=?", [$rid]);
            flash($rule['active'] ? 'Rule paused.' : 'Rule turned on.');
        } elseif ($rule && $act === 'rule_delete') {
            db_run("DELETE FROM social_rules WHERE id=?", [$rid]);
            flash('Rule deleted.');
        }
        redirect('comments.php?tab=rules');
    }

    if (!can_write()) { flash('Your role can view comments but not act on them.', 'error'); redirect($back); }
    $cm = db_row("SELECT * FROM social_comments WHERE id=? AND client_id=?", [(int) ($_POST['id'] ?? 0), $cid]);
    if (!$cm) { flash('That comment is no longer here.', 'error'); redirect($back); }
    $res = match ($act) {
        'reply_public'  => social_comment_reply($CLIENT, $cm, (string) ($_POST['text'] ?? ''), 'public', $by),
        'reply_private' => social_comment_reply($CLIENT, $cm, (string) ($_POST['text'] ?? ''), 'private', $by),
        'hide', 'unhide', 'delete' => social_comment_action($CLIENT, $cm, $act, $by),
        'add_lead'      => $cm['contact_id']
            ? ['ok' => crm_add_lead($CLIENT, (int) $cm['contact_id'], $cm['platform'] === 'ig' ? 'ig_comment' : 'fb_comment', 'auto',
                                    (int) (crm_settings($cid)['social_lead_stage'] ?? 0) ?: null, (int) ($me['id'] ?? 0) ?: null), 'error' => 'Could not add the lead.']
            : ['ok' => false, 'error' => 'We don\'t know who wrote this comment.'],
        default         => ['ok' => false, 'error' => 'Unknown action.'],
    };
    if ($res['ok']) {
        flash(['reply_public' => 'Reply posted.', 'reply_private' => 'Private message sent.', 'hide' => 'Comment hidden — only its writer and their friends see it now.',
               'unhide' => 'Comment visible again.', 'delete' => 'Comment deleted.', 'add_lead' => 'Added to the CRM as a lead.'][$act] ?? 'Done.');
    } else {
        flash((string) ($res['error'] ?? 'That did not work.'), 'error');
    }
    redirect($back);
}

/* ───────────── the feed ───────────── */
$plat = in_array($_GET['p'] ?? '', ['fb', 'ig'], true) ? (string) $_GET['p'] : '';
$show = in_array($_GET['show'] ?? '', ['unanswered', 'hidden', 'flagged', 'deleted'], true) ? (string) $_GET['show'] : '';
$post = preg_replace('/[^0-9A-Za-z_]/', '', (string) ($_GET['post'] ?? ''));
$qtxt = trim((string) ($_GET['q'] ?? ''));
$q = fn(array $set) => 'comments.php?' . http_build_query(array_filter($set + ['p' => $plat, 'show' => $show, 'post' => $post, 'q' => $qtxt], 'strlen'));
$here = $q([]);

$platforms = array_values(array_filter([$hasFb ? 'fb' : '', $hasIg ? 'ig' : '']));
$pph = implode(',', array_fill(0, count($platforms), '?'));
$w = "client_id=? AND from_page=0 AND parent_id IS NULL AND platform IN ($pph)"; $p = array_merge([$cid], $platforms);
if ($plat !== '') { $w .= " AND platform=?"; $p[] = $plat; }
if ($post !== '') { $w .= " AND post_id=?";  $p[] = $post; }
if ($qtxt !== '') { $w .= " AND (body LIKE ? OR author_name LIKE ?)"; $p[] = '%' . $qtxt . '%'; $p[] = '%' . $qtxt . '%'; }
$w .= match ($show) {
    'unanswered' => " AND status='visible' AND replied_public=0 AND replied_private=0",
    'hidden'     => " AND status='hidden'",
    'flagged'    => " AND flagged IS NOT NULL",
    'deleted'    => " AND status='deleted'",
    default      => " AND status<>'deleted'",
};
$comments = db_all("SELECT * FROM social_comments WHERE $w ORDER BY created_at DESC, id DESC LIMIT 300", $p);

// Answers under each comment: ours, and their follow-ups.
$replies = [];
if ($comments) {
    $ids = array_column($comments, 'comment_id');
    foreach (db_all("SELECT * FROM social_comments WHERE client_id=? AND parent_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND status<>'deleted' ORDER BY created_at, id",
                    array_merge([$cid], $ids)) as $r) {
        $replies[$r['parent_id']][] = $r;
    }
}
// Grouped by post, newest activity first.
$byPost = [];
foreach ($comments as $c) $byPost[$c['post_id']][] = $c;

$counts = db_row("SELECT SUM(status='visible' AND replied_public=0 AND replied_private=0) unanswered, SUM(status='hidden') hidden, SUM(flagged IS NOT NULL) flagged
                  FROM social_comments WHERE client_id=? AND from_page=0 AND parent_id IS NULL AND platform IN ($pph)", array_merge([$cid], $platforms)) ?: [];
$recentPosts = db_all("SELECT post_id, platform, MAX(post_text) post_text, MAX(created_at) last_at, COUNT(*) n FROM social_comments
                       WHERE client_id=? AND platform IN ($pph) GROUP BY post_id, platform ORDER BY last_at DESC LIMIT 60", array_merge([$cid], $platforms));
$inCrm = [];
if ($comments && crm_enabled($CLIENT)) {
    $cids = array_values(array_unique(array_filter(array_map('intval', array_column($comments, 'contact_id')))));
    if ($cids) foreach (db_all("SELECT id FROM contacts WHERE client_id=? AND stage_id IS NOT NULL AND id IN (" . implode(',', $cids) . ")", [$cid]) as $l) $inCrm[(int) $l['id']] = (int) $l['id'];
}
$write = can_write();
$platName = ['fb' => 'Facebook', 'ig' => 'Instagram'];

$rules = db_all("SELECT * FROM social_rules WHERE client_id=? ORDER BY kind, sort, id", [$cid]);

require_once __DIR__ . '/../includes/social_connect.php';
client_header('Comments', 'comments', $CLIENT);
page_head('Comments', '<a class="btn btn-ghost btn-sm" href="social.php">' . social_fb_icon() . ' Facebook &amp; Instagram</a>');
echo social_connect_banner($CLIENT, 'comments.php', is_client_admin());
?>
<nav class="dv-tabs" aria-label="Comments sections">
  <a href="comments.php" class="<?= $tab === 'feed' ? 'on' : '' ?>">Comments</a>
  <a href="comments.php?tab=rules" class="<?= $tab === 'rules' ? 'on' : '' ?>">Moderation &amp; auto-replies<?= $rules ? ' (' . count($rules) . ')' : '' ?></a>
</nav>

<?php if ($tab === 'feed'): ?>
<div class="card card-flush">
  <form method="get" class="cm-filters">
    <div class="cm-show" role="group" aria-label="Show">
      <?php foreach (['' => 'All', 'unanswered' => 'Unanswered', 'hidden' => 'Hidden', 'flagged' => 'Flagged', 'deleted' => 'Deleted'] as $k => $l):
        $n = $k !== '' && $k !== 'deleted' ? (int) ($counts[$k] ?? 0) : null; ?>
        <a class="<?= $show === $k ? 'on' : '' ?>" href="<?= e($q(['show' => $k])) ?>"><?= $l ?><?= $n ? ' <span class="cm-n">' . $n . '</span>' : '' ?></a>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="show" value="<?= e($show) ?>">
    <?php if (count($platforms) > 1): ?>
      <select name="p" aria-label="Platform"><option value="">Facebook &amp; Instagram</option>
        <?php foreach ($platforms as $pl): ?><option value="<?= $pl ?>" <?= $plat === $pl ? 'selected' : '' ?>><?= $platName[$pl] ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <select name="post" aria-label="Post"><option value="">Every post</option>
      <?php foreach ($recentPosts as $rp): ?><option value="<?= e((string) $rp['post_id']) ?>" <?= $post === (string) $rp['post_id'] ? 'selected' : '' ?>><?= e($platName[$rp['platform']] . ' · ' . (mb_strimwidth((string) $rp['post_text'], 0, 50, '…') ?: 'Post ' . $rp['post_id'])) ?> (<?= (int) $rp['n'] ?>)</option><?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= e($qtxt) ?>" placeholder="Search comments or names">
    <button class="btn btn-ghost btn-sm">Show</button>
  </form>
</div>

<?php if (!$byPost): ?>
  <div class="card"><div class="empty">
    <?php if ($show === '' && $qtxt === '' && $post === ''): ?>
      No comments yet — new comments on your Page's posts appear here as they are written.
      <?php $scSt = social_connect_status($CLIENT); if (!$scSt['pages'] || array_intersect_key($scSt['missing'], ['comments_on' => 1, 'ig_comments_on' => 1])): ?>
        <div style="margin-top:12px"><?php if (is_client_admin() && $scSt['configured'] && !$scSt['pages']): ?>
          <form method="post" action="social.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="fb_connect"><input type="hidden" name="return" value="comments.php">
            <button class="btn btn-primary fb-connect-btn"><?= social_fb_icon() ?> Connect Facebook &amp; Instagram</button></form>
        <?php else: ?><a class="btn btn-primary" href="social.php">Turn on comments</a><?php endif; ?></div>
      <?php endif; ?>
    <?php else: ?>Nothing matches these filters.<?php endif; ?>
  </div></div>
<?php endif; ?>

<?php foreach ($byPost as $postId => $list): $first = $list[0]; ?>
  <section class="card cm-post">
    <header class="cm-post-head">
      <?php if (preg_match('~^https://~i', (string) $first['post_image'])): ?><img src="<?= e((string) $first['post_image']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
      <div>
        <span class="pill <?= $first['platform'] === 'ig' ? 'gold' : 'blue' ?>"><?= $platName[$first['platform']] ?></span>
        <p><?= e(mb_strimwidth((string) ($first['post_text'] ?: 'Post without text'), 0, 220, '…')) ?></p>
        <div class="cm-post-links">
          <?php if (preg_match('~^https://~i', (string) $first['post_link'])): ?><a href="<?= e((string) $first['post_link']) ?>" target="_blank" rel="noopener noreferrer">Open the post ↗</a><?php endif; ?>
          <?php if ($post === ''): ?><a href="<?= e($q(['post' => (string) $postId])) ?>">Only this post</a><?php endif; ?>
        </div>
      </div>
    </header>
    <?php foreach ($list as $c): $cidN = (int) $c['contact_id']; $old = strtotime((string) $c['created_at']) < time() - 7 * 86400; ?>
      <article class="cm-item cm-<?= e((string) $c['status']) ?>" id="c<?= (int) $c['id'] ?>">
        <div class="cm-meta">
          <strong><?= e((string) ($c['author_name'] ?: 'Someone')) ?></strong>
          <span class="text-muted"><?= e(date('j M, H:i', strtotime((string) $c['created_at']))) ?></span>
          <?php if ($c['status'] === 'hidden'): ?><span class="pill gray">Hidden</span><?php endif; ?>
          <?php if ($c['status'] === 'deleted'): ?><span class="pill red">Deleted</span><?php endif; ?>
          <?php if ($c['flagged']): ?><span class="pill red" title="<?= e((string) $c['flagged']) ?>">Flagged: <?= e(str_replace(['keyword:', 'ai:'], ['', 'AI '], (string) $c['flagged'])) ?></span><?php endif; ?>
          <?php if ($c['replied_public']): ?><span class="pill green">Replied</span><?php endif; ?>
          <?php if ($c['replied_private']): ?><span class="pill green">Messaged</span><?php endif; ?>
          <?php if (str_starts_with((string) $c['acted_by'], 'rule:')): ?><span class="pill gray">By a rule</span><?php elseif (str_starts_with((string) $c['acted_by'], 'flow:')): ?><span class="pill gray">By an automation</span><?php endif; ?>
        </div>
        <p class="cm-body"><?= nl2br(e((string) $c['body'])) ?></p>
        <?php foreach ($replies[$c['comment_id']] ?? [] as $r): ?>
          <div class="cm-reply <?= (int) $r['from_page'] ? 'cm-ours' : '' ?>"><strong><?= e((string) ($r['author_name'] ?: ((int) $r['from_page'] ? 'You' : 'Someone'))) ?></strong> <?= e((string) $r['body']) ?></div>
        <?php endforeach; ?>
        <?php if ($write && $c['status'] !== 'deleted'): ?>
          <div class="cm-actions">
            <details class="cm-reply-box"><summary class="btn btn-ghost btn-sm">Reply</summary>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="back" value="<?= e($here . '#c' . (int) $c['id']) ?>">
                <textarea name="text" rows="2" maxlength="2000" required placeholder="Write a reply…"></textarea>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                  <button class="btn btn-primary btn-sm" name="action" value="reply_public">Reply under the comment</button>
                  <?php if (!$c['replied_private'] && !$old): ?>
                    <button class="btn btn-ghost btn-sm" name="action" value="reply_private" title="Sent to their inbox. Meta allows one private reply per comment, within 7 days.">Send privately</button>
                  <?php endif; ?>
                </div>
              </form>
            </details>
            <form method="post" class="cm-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="back" value="<?= e($here . '#c' . (int) $c['id']) ?>">
              <?php if ($c['status'] === 'hidden'): ?><button class="btn btn-ghost btn-sm" name="action" value="unhide">Unhide</button>
              <?php else: ?><button class="btn btn-ghost btn-sm" name="action" value="hide">Hide</button><?php endif; ?>
              <button class="btn btn-ghost btn-sm" name="action" value="delete" onclick="return confirm('Delete this comment from <?= $platName[$c['platform']] ?>? This cannot be undone.')">Delete</button>
              <?php if ($cidN && crm_enabled($CLIENT) && empty($inCrm[$cidN])): ?><button class="btn btn-ghost btn-sm" name="action" value="add_lead">Add to CRM</button><?php endif; ?>
            </form>
            <?php if ($cidN && can_use('inbox')): ?><a class="btn btn-ghost btn-sm" href="inbox.php?contact=<?= $cidN ?>">Conversation</a><?php endif; ?>
            <?php if ($cidN && !empty($inCrm[$cidN])): ?><a class="btn btn-ghost btn-sm" href="crm_lead.php?id=<?= $inCrm[$cidN] ?>">Lead</a><?php endif; ?>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>

<?php else: /* ───────────── rules ───────────── */
$editId = (int) ($_GET['edit'] ?? 0);
$edit = $editId ? (db_row("SELECT * FROM social_rules WHERE id=? AND client_id=?", [$editId, $cid]) ?: null) : null;
$newKind = ($_GET['new'] ?? '') === 'reply' ? 'reply' : (($_GET['new'] ?? '') === 'moderate' ? 'moderate' : '');
$matchName = ['keywords' => 'has a keyword', 'phone' => 'has a phone number', 'link' => 'has a link', 'any' => 'any comment', 'ai' => 'AI: spam or abuse'];
$admin = is_client_admin();
?>
<div class="alert info" style="font-size:13px">Rules run on every new comment, top to bottom, before your automations. <strong>Moderation</strong> hides or deletes the comment (hidden comments stay visible to their writer and their friends, so nobody feels blocked). <strong>Auto-replies</strong> answer under the comment and/or privately — the first matching reply rule wins.</div>

<?php foreach (['moderate' => 'Moderation', 'reply' => 'Auto-replies'] as $k => $title): $list = array_filter($rules, fn($r) => $r['kind'] === $k); ?>
  <div class="card card-flush">
    <div class="card-head" style="padding:14px 18px;display:flex;align-items:center;gap:8px"><h2 style="margin:0"><?= $title ?></h2>
      <?php if ($admin): ?><a class="btn btn-ghost btn-sm" style="margin-inline-start:auto" href="comments.php?tab=rules&new=<?= $k ?>#rule-form">+ Add</a><?php endif; ?></div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Name</th><th>Where</th><th>When the comment…</th><th><?= $k === 'moderate' ? 'Then' : 'Reply' ?></th><th>Used</th><th></th></tr></thead>
      <tbody>
      <?php if (!$list): ?><tr><td colspan="6"><div class="empty"><?= $k === 'moderate' ? 'No moderation yet. A common start: hide comments with a phone number or a link, so competitors can\'t poach your customers.' : 'No auto-replies yet. Example: "price" → reply "Sent you the details in a message 📩" and send the price list privately.' ?></div></td></tr><?php endif; ?>
      <?php foreach ($list as $r): $np = count(array_filter(explode(',', (string) $r['posts']))); ?>
        <tr class="<?= (int) $r['active'] ? '' : 'text-muted' ?>">
          <td><strong><?= e((string) $r['name']) ?></strong><?= (int) $r['active'] ? '' : ' <span class="pill gray">Paused</span>' ?></td>
          <td><?= ['both' => 'Facebook & Instagram', 'fb' => 'Facebook', 'ig' => 'Instagram'][$r['platform']] ?? '' ?><br><span class="text-muted" style="font-size:12px"><?= $np ? $np . ' post' . ($np === 1 ? '' : 's') : 'Every post' ?></span></td>
          <td><?= e($matchName[$r['match_kind']] ?? '') ?><?php if ($r['match_kind'] === 'keywords'): ?><br><span class="text-muted" style="font-size:12px"><?= e(mb_strimwidth((string) $r['keywords'], 0, 80, '…')) ?></span><?php endif; ?></td>
          <td style="font-size:12.5px"><?php if ($k === 'moderate'): ?><?= $r['action'] === 'delete' ? 'Delete' : 'Hide' ?>
            <?php else: ?><?= $r['reply_text'] !== '' && $r['reply_text'] !== null ? 'Public: ' . e(mb_strimwidth((string) $r['reply_text'], 0, 60, '…')) . '<br>' : '' ?><?= $r['dm_text'] !== '' && $r['dm_text'] !== null ? 'Private: ' . e(mb_strimwidth((string) $r['dm_text'], 0, 60, '…')) : '' ?><?php endif; ?></td>
          <td><?= (int) $r['hits'] ?></td>
          <td style="white-space:nowrap"><?php if ($admin): ?>
            <a class="btn btn-ghost btn-sm" href="comments.php?tab=rules&edit=<?= (int) $r['id'] ?>#rule-form">Edit</a>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-ghost btn-sm" name="action" value="rule_toggle"><?= (int) $r['active'] ? 'Pause' : 'Turn on' ?></button>
              <button class="btn btn-ghost btn-sm" name="action" value="rule_delete" onclick="return confirm('Delete this rule?')">Delete</button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  </div>
<?php endforeach; ?>

<?php if ($admin && ($edit || $newKind)):
  $r = $edit ?: ['id' => 0, 'name' => '', 'kind' => $newKind, 'platform' => 'both', 'posts' => '', 'match_kind' => $newKind === 'moderate' ? 'phone' : 'keywords',
                 'keywords' => '', 'action' => 'hide', 'reply_text' => '', 'dm_text' => '', 'active' => 1];
  $selPosts = array_filter(explode(',', (string) $r['posts'])); ?>
  <form method="post" class="card" id="rule-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="rule_save"><input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="kind" value="<?= e((string) $r['kind']) ?>">
    <h2><?= $r['id'] ? 'Edit rule' : ($r['kind'] === 'reply' ? 'New auto-reply' : 'New moderation rule') ?></h2>
    <div class="grid3">
      <div class="field"><span class="lbl">Name</span><input type="text" name="name" value="<?= e((string) $r['name']) ?>" maxlength="120" placeholder="<?= $r['kind'] === 'reply' ? 'Price question' : 'Hide phone numbers' ?>"></div>
      <div class="field"><span class="lbl">On</span><select name="platform">
        <?php foreach (array_filter(['both' => $hasFb && $hasIg ? 'Facebook & Instagram' : '', 'fb' => $hasFb ? 'Facebook' : '', 'ig' => $hasIg ? 'Instagram' : '']) as $v => $l): ?>
          <option value="<?= $v ?>" <?= $r['platform'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="field"><span class="lbl">When the comment…</span><select name="match_kind" id="rule-match">
        <?php foreach ($matchName as $v => $l): if ($r['kind'] === 'reply' && in_array($v, ['phone', 'link', 'ai'], true)) continue; ?>
          <option value="<?= $v ?>" <?= $r['match_kind'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field" id="rule-kw"><span class="lbl">Keywords <span class="text-muted">(comma or one per line — any of them matches)</span></span>
      <textarea name="keywords" rows="2" placeholder="price, how much, بكام, السعر"><?= e((string) $r['keywords']) ?></textarea></div>
    <div class="field"><span class="lbl">Posts <span class="text-muted">(none chosen = every post, including new ones)</span></span>
      <select name="posts[]" multiple size="<?= max(3, min(8, count($recentPosts))) ?>">
        <?php foreach ($recentPosts as $rp): ?><option value="<?= e((string) $rp['post_id']) ?>" <?= in_array((string) $rp['post_id'], $selPosts, true) ? 'selected' : '' ?>><?= e($platName[$rp['platform']] . ' · ' . (mb_strimwidth((string) $rp['post_text'], 0, 70, '…') ?: 'Post ' . $rp['post_id'])) ?></option><?php endforeach; ?>
      </select>
      <?php if (!$recentPosts): ?><div class="hint">Posts appear here once they get their first comment.</div><?php endif; ?>
    </div>
    <?php if ($r['kind'] === 'moderate'): ?>
      <div class="field"><span class="lbl">Then</span><select name="rule_action">
        <option value="hide" <?= $r['action'] !== 'delete' ? 'selected' : '' ?>>Hide the comment (can be undone)</option>
        <option value="delete" <?= $r['action'] === 'delete' ? 'selected' : '' ?>>Delete the comment</option></select>
        <div class="hint" id="rule-ai-hint" style="display:none">Uses your AI key (Settings → AI) on each new comment.</div></div>
    <?php else: ?>
      <div class="grid2">
        <div class="field"><span class="lbl">Public reply under the comment</span><textarea name="reply_text" rows="3" maxlength="2000" placeholder="Thanks {{name}}! We sent you the details in a message 📩"><?= e((string) $r['reply_text']) ?></textarea></div>
        <div class="field"><span class="lbl">Private message to them</span><textarea name="dm_text" rows="3" maxlength="2000" placeholder="Hi {{name}}, here are the prices…"><?= e((string) $r['dm_text']) ?></textarea>
          <div class="hint">One private message per comment, within 7 days of it (Meta's rule). <code>{{name}}</code> is their name.</div></div>
      </div>
    <?php endif; ?>
    <label class="mod-opt"><input type="checkbox" name="active" value="1" <?= (int) $r['active'] ? 'checked' : '' ?>> On</label>
    <div style="display:flex;gap:8px;margin-top:10px"><button class="btn btn-primary">Save rule</button><a class="btn btn-ghost" href="comments.php?tab=rules">Cancel</a></div>
    <p class="hint">Need more — ask for a phone number, score the lead, send a WhatsApp template? Build it in <a href="automations.php">Automations</a> with a "comment on your post" trigger.</p>
  </form>
  <script>
  (function () {
    var m = document.getElementById('rule-match'), kw = document.getElementById('rule-kw'), ai = document.getElementById('rule-ai-hint');
    function sync() { kw.style.display = m.value === 'keywords' ? '' : 'none'; if (ai) ai.style.display = m.value === 'ai' ? '' : 'none'; }
    m.addEventListener('change', sync); sync();
  })();
  </script>
<?php endif; ?>
<?php endif; ?>
<?php layout_footer(); ?>
