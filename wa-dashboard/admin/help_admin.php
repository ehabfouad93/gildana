<?php
declare(strict_types=1);
/**
 * Help content: the FAQ clients read, the intro video, and the support requests they send.
 *
 * All of it is editable here so the operator never needs a deploy to answer a question that
 * keeps coming up, or to swap the walkthrough video.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/help.php';
require_once __DIR__ . '/../includes/push.php';   // setting_get / setting_set live here

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'faq_save') {
        $id  = (int) ($_POST['id'] ?? 0);
        $q   = trim((string) ($_POST['question'] ?? ''));
        $a   = trim((string) ($_POST['answer'] ?? ''));
        $srt = (int) ($_POST['sort'] ?? 0);
        $st  = ($_POST['status'] ?? 'active') === 'hidden' ? 'hidden' : 'active';
        if ($q === '' || $a === '') {
            $err = 'A question needs both a title and an answer.';
        } elseif ($id > 0) {
            db_run("UPDATE faq_items SET question=?, answer=?, sort=?, status=?, updated_at=NOW() WHERE id=?",
                [mb_substr($q, 0, 255), $a, $srt, $st, $id]);
            flash('Question updated.'); redirect('help_admin.php');
        } else {
            db_run("INSERT INTO faq_items (sort,question,answer,status,created_at) VALUES (?,?,?,?,NOW())",
                [$srt, mb_substr($q, 0, 255), $a, $st]);
            flash('Question added.'); redirect('help_admin.php');
        }
    }

    if ($action === 'faq_delete') {
        db_run("DELETE FROM faq_items WHERE id=?", [(int) ($_POST['id'] ?? 0)]);
        flash('Question deleted.'); redirect('help_admin.php');
    }

    /* Two video slots, saved by the same handler because they are the same three fields:
       the walkthrough clients see in Help, and the demo a visitor sees on the public page.
       A file, when one is attached, wins over whatever is in the link box — someone who
       picked a file meant the file. */
    if ($action === 'save_video' || $action === 'save_promo') {
        $isPromo = $action === 'save_promo';
        $keyUrl  = $isPromo ? 'promo_video_url' : 'intro_video_url';
        $keyOn   = $isPromo ? 'promo_video_on'  : 'intro_video_on';
        $url     = trim((string) ($_POST[$keyUrl] ?? ''));

        if (($_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$stored, $upErr] = video_store_upload($_FILES['video_file']);
            if ($upErr !== '') { $err = $upErr; }
            else               { $url = $stored; }
        }

        if ($err === '') {
            setting_set($keyUrl, $url);
            setting_set($keyOn, empty($_POST[$keyOn]) ? '0' : '1');
            flash($isPromo ? 'Landing page video updated.' : 'Intro video updated.');
            redirect('help_admin.php#' . ($isPromo ? 'promo' : 'video'));
        }
    }

    if ($action === 'ticket_close') {
        db_run("UPDATE support_tickets SET status='closed' WHERE id=?", [(int) ($_POST['id'] ?? 0)]);
        flash('Request closed.'); redirect('help_admin.php#tickets');
    }
}

$editing = null;
if (($_GET['edit'] ?? '') !== '') $editing = db_row("SELECT * FROM faq_items WHERE id=?", [(int) $_GET['edit']]);

$faqs    = db_all("SELECT * FROM faq_items ORDER BY sort, id");
$tickets = db_all("SELECT t.*, c.name AS client_name FROM support_tickets t
                    LEFT JOIN clients c ON c.id = t.client_id
                   ORDER BY t.status='closed', t.id DESC LIMIT 100");
$openN   = (int) db_val("SELECT COUNT(*) FROM support_tickets WHERE status='open'");
$vidUrl  = help_setting('intro_video_url', '');
$vidOn   = help_setting('intro_video_on', '0') === '1';
$proUrl  = help_setting('promo_video_url', '');
$proOn   = help_setting('promo_video_on', '0') === '1';

layout_header('Help Content', 'admin', 'help');
page_head('Help Content');
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<?php
/* The two video slots are the same three fields — a link or a file, a preview, and an on
   switch — so they are rendered by one function rather than two near-identical blocks that
   drift apart the first time one of them is edited. */
function video_card(string $id, string $title, string $blurb, string $action,
                    string $keyUrl, string $keyOn, string $url, bool $on, string $onLabel): void
{ ?>
<div class="card" id="<?= e($id) ?>">
  <h2><?= e($title) ?></h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px"><?= $blurb ?></p>
  <form method="post" enctype="multipart/form-data" style="max-width:620px">
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= e($action) ?>">

    <div class="field"><span class="lbl">Upload a video file</span>
      <input type="file" name="video_file" accept="video/mp4,video/webm">
      <span class="hint">MP4 or WebM, up to <?= (int) (video_max_bytes() / 1024 / 1024) ?> MB on this
        server. Prefer MP4 — some older iPhones will not play WebM. Choosing a file replaces the
        link below.<?php if (video_max_bytes() < VIDEO_MAX): ?> <em>PHP's
        <span class="mono">upload_max_filesize</span> is what caps this; raise it in
        <span class="mono">wa-dashboard/.user.ini</span> if you need more.</em><?php endif; ?></span></div>

    <div class="field"><span class="lbl">…or paste a link</span>
      <input type="text" name="<?= e($keyUrl) ?>" value="<?= e($url) ?>"
             placeholder="https://www.youtube.com/watch?v=…"></div>

    <label style="display:flex;gap:8px;align-items:center;font-weight:normal;margin:6px 0 14px">
      <input type="checkbox" name="<?= e($keyOn) ?>" value="1" <?= $on ? 'checked' : '' ?> style="width:auto">
      <?= e($onLabel) ?>
    </label>

    <?php if ($url !== ''): ?>
      <div style="max-width:420px;aspect-ratio:16/9;background:#000;border-radius:10px;overflow:hidden;margin-bottom:14px">
        <?php if (video_is_file($url)): ?>
          <video src="<?= e(video_src($url, '../')) ?>" controls playsinline style="width:100%;height:100%"></video>
        <?php else: ?>
          <iframe src="<?= e(video_embed_url($url)) ?>" allowfullscreen style="width:100%;height:100%;border:0"></iframe>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <button class="btn btn-primary">Save</button>
  </form>
</div>
<?php }

video_card('video', 'Intro video for clients',
    'Shown behind the play button that floats on every signed-in page, at the top of Help, and '
  . 'linked from every <strong>How to use</strong> panel. Upload the walkthrough, or paste a '
  . 'YouTube or Vimeo link exactly as it appears in your browser — it is converted to an '
  . 'embeddable one automatically.',
    'save_video', 'intro_video_url', 'intro_video_on', $vidUrl, $vidOn,
    'Show the video button to clients');

video_card('promo', 'Demo video on the public site',
    'Shown on the landing page, under the headline. This is what someone who has not signed up '
  . 'sees, so it is usually the shorter cut. Leave it switched off and the section does not '
  . 'appear at all.',
    'save_promo', 'promo_video_url', 'promo_video_on', $proUrl, $proOn,
    'Show the video on the landing page');
?>

<div class="card">
  <h2><?= $editing ? 'Edit question' : 'Add a question' ?></h2>
  <form method="post" style="max-width:720px">
    <?= csrf_field() ?><input type="hidden" name="action" value="faq_save">
    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
    <div class="field"><span class="lbl">Question</span>
      <input type="text" name="question" maxlength="255" required value="<?= e((string) ($editing['question'] ?? '')) ?>"
             placeholder="e.g. Why isn't my number receiving replies?"></div>
    <div class="field"><span class="lbl">Answer</span>
      <textarea name="answer" rows="6" required placeholder="Plain text. Line breaks are kept."><?= e((string) ($editing['answer'] ?? '')) ?></textarea></div>
    <div class="grid2">
      <div class="field"><span class="lbl">Order</span>
        <input type="number" name="sort" value="<?= (int) ($editing['sort'] ?? count($faqs)) ?>">
        <span class="text-muted" style="font-size:11.5px">Lower shows first.</span></div>
      <div class="field"><span class="lbl">Status</span>
        <select name="status">
          <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Visible to clients</option>
          <option value="hidden" <?= ($editing['status'] ?? '') === 'hidden' ? 'selected' : '' ?>>Hidden</option>
        </select></div>
    </div>
    <div class="row-between mt10">
      <?php if ($editing): ?><a class="btn btn-ghost btn-sm" href="help_admin.php">Cancel</a><?php else: ?><span></span><?php endif; ?>
      <button class="btn btn-primary"><?= $editing ? 'Save changes' : 'Add question' ?></button>
    </div>
  </form>
</div>

<div class="card card-flush">
  <div style="padding:16px 18px 0"><h2 style="border:0;padding:0;margin:0">Questions (<?= count($faqs) ?>)</h2></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>#</th><th>Question</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$faqs): ?><tr><td colspan="4"><div class="empty">Nothing published yet.</div></td></tr><?php endif; ?>
    <?php foreach ($faqs as $f): ?>
      <tr>
        <td class="text-muted"><?= (int) $f['sort'] ?></td>
        <td><strong><?= e((string) $f['question']) ?></strong></td>
        <td><span class="pill <?= $f['status'] === 'active' ? 'green' : 'gray' ?>"><?= e(ucfirst((string) $f['status'])) ?></span></td>
        <td style="text-align:right;white-space:nowrap">
          <a class="btn-link" href="help_admin.php?edit=<?= (int) $f['id'] ?>">Edit</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this question?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="faq_delete"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <button class="btn-link" style="color:var(--danger)">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card card-flush" id="tickets">
  <div style="padding:16px 18px 0"><h2 style="border:0;padding:0;margin:0">Support requests<?= $openN ? ' — ' . $openN . ' open' : '' ?></h2></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>When</th><th>From</th><th>Subject</th><th>Message</th><th></th></tr></thead>
    <tbody>
    <?php if (!$tickets): ?><tr><td colspan="5"><div class="empty">No requests yet.</div></td></tr><?php endif; ?>
    <?php foreach ($tickets as $t): ?>
      <tr style="<?= $t['status'] === 'closed' ? 'opacity:.55' : '' ?>">
        <td class="text-muted" style="white-space:nowrap"><?= e(date('d M H:i', strtotime((string) $t['created_at']))) ?></td>
        <td><?= e((string) ($t['client_name'] ?: '—')) ?><br>
            <span class="text-muted" style="font-size:11.5px"><?= e((string) $t['email']) ?></span></td>
        <td><strong><?= e((string) $t['subject']) ?></strong></td>
        <td class="text-muted" style="font-size:12px;max-width:380px"><?= nl2br(e(mb_substr((string) $t['message'], 0, 300))) ?></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if ($t['status'] !== 'closed'): ?>
            <a class="btn-link" href="mailto:<?= e((string) $t['email']) ?>?subject=<?= rawurlencode('Re: ' . (string) $t['subject']) ?>">Reply</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="ticket_close"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button class="btn-link">Close</button>
            </form>
          <?php else: ?><span class="pill gray">Closed</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php layout_footer(); ?>
