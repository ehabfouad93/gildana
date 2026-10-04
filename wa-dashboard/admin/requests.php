<?php
declare(strict_types=1);
/**
 * Admin → Requests: everyone who filled in "Get started", on the landing page or on the shared
 * link (request.php). Work each one New → Contacted, then Convert to client — which opens the
 * account and its login, filled in from the request — or Decline.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/access_request.php';
require_once __DIR__ . '/../includes/client_account.php';

$ready = access_requests_ready();
$statuses = access_request_statuses();
$err = '';
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$R = $ready && $id ? db_row("SELECT * FROM access_requests WHERE id=?", [$id]) : null;

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string) ($_POST['action'] ?? '');
    if (!$R) { flash('That request is gone.', 'error'); redirect('requests.php'); }

    if ($act === 'save') {
        $st = (string) ($_POST['status'] ?? $R['status']);
        if (!isset($statuses[$st]) || ($st === 'converted' && !$R['client_id'])) $st = $R['status'];
        db_run("UPDATE access_requests SET status=?, notes=?, updated_at=NOW() WHERE id=?",
               [$st, trim((string) ($_POST['notes'] ?? '')) ?: null, $id]);
        flash('Saved.');
        redirect('requests.php?id=' . $id);
    }
    if ($act === 'delete') {
        db_run("DELETE FROM access_requests WHERE id=?", [$id]);
        flash('Request deleted.');
        redirect('requests.php');
    }
    if ($act === 'convert') {
        if ($R['client_id']) { flash('This request is already a client.', 'error'); redirect('requests.php?id=' . $id); }
        $r = client_account_create($_POST + ['login_name' => $R['name']]);
        if ($r['ok']) {
            $note = trim(($R['notes'] ? $R['notes'] . "\n" : '') . 'Converted to client #' . $r['id'] . ' on ' . date('Y-m-d') . '.');
            db_run("UPDATE access_requests SET status='converted', client_id=?, notes=?, updated_at=NOW() WHERE id=?", [$r['id'], $note, $id]);
            // Shown once, on the next page, so the login can be passed on — never stored in plain text.
            $_SESSION['ar_welcome'] = ['id' => $id, 'email' => strtolower(trim((string) $_POST['email'])), 'password' => (string) $_POST['password']];
            flash('Client "' . trim((string) $_POST['name']) . '" created from the request.');
            redirect('requests.php?id=' . $id);
        }
        $err = $r['error'];
    }
}

$welcome = null;
if (!empty($_SESSION['ar_welcome']) && (int) $_SESSION['ar_welcome']['id'] === $id) { $welcome = $_SESSION['ar_welcome']; unset($_SESSION['ar_welcome']); }

$filter = (string) ($_GET['status'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));
$counts = array_fill_keys(array_keys($statuses), 0);
$rows = [];
if ($ready) {
    foreach (db_all("SELECT status, COUNT(*) n FROM access_requests GROUP BY status") as $c) $counts[$c['status']] = (int) $c['n'];
    $w = '1=1'; $p = [];
    if (isset($statuses[$filter])) { $w .= ' AND r.status=?'; $p[] = $filter; }
    if ($q !== '') { $w .= ' AND (r.name LIKE ? OR r.email LIKE ? OR r.company LIKE ? OR r.phone LIKE ? OR r.job_title LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
    $rows = db_all("SELECT r.*, c.name client_name FROM access_requests r LEFT JOIN clients c ON c.id=r.client_id WHERE $w ORDER BY r.id DESC LIMIT 500", $p);
}
$total = array_sum($counts);
$link = access_request_link();
$pill = ['new' => 'blue', 'contacted' => 'gold', 'converted' => 'green', 'declined' => 'gray'];
$wa = fn(string $ph) => preg_replace('~\D~', '', $ph);

layout_header('Requests', 'admin', 'requests');
page_head('Requests');
?>
<?php if (!$ready): ?>
  <div class="alert error">Run the database update (migration 060) to start keeping requests here: <code>php deploy/docker/migrate.php</code>.</div>
<?php endif; ?>
<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="card">
  <h2>Link to the form</h2>
  <p class="text-muted" style="font-size:13px;margin:-6px 0 4px">Share it anywhere — ads, your bio, email signatures, WhatsApp. Everyone who fills it in appears below.</p>
  <div class="int-copy"><code id="ar-link"><?= e($link) ?></code><button type="button" class="btn btn-primary btn-sm" data-copy="ar-link">Copy link</button><a class="btn btn-ghost btn-sm" href="<?= e($link) ?>" target="_blank" rel="noopener">Open</a></div>
  <details class="int-more">
    <summary>Know which ad or post each request came from</summary>
    <p class="text-muted" style="font-size:12.5px">Add <code>utm_source</code>, <code>utm_medium</code> and <code>utm_campaign</code> to the link; they are saved with the request. For example:</p>
    <div class="int-copy"><code id="ar-utm"><?= e($link . '?utm_source=facebook&utm_medium=paid&utm_campaign=october') ?></code><button type="button" class="btn btn-ghost btn-sm" data-copy="ar-utm">Copy</button></div>
  </details>
</div>

<div class="stats-row">
  <a class="stat-tile<?= $filter === '' ? ' on' : '' ?>" href="requests.php"><span class="lbl">All requests</span><span class="val"><?= $total ?></span><span class="sub"><?= $total ? round($counts['converted'] / $total * 100) : 0 ?>% became clients</span></a>
  <?php foreach ($statuses as $k => $label): ?>
    <a class="stat-tile<?= $filter === $k ? ' on' : '' ?>" href="requests.php?status=<?= $k ?>"><span class="lbl"><?= e($label) ?></span><span class="val<?= $k === 'new' && $counts[$k] ? ' accent' : '' ?>"><?= $counts[$k] ?></span>
      <span class="sub"><?= ['new' => 'waiting for a reply', 'contacted' => 'in conversation', 'converted' => 'now clients', 'declined' => 'not a fit'][$k] ?></span></a>
  <?php endforeach; ?>
</div>

<?php if ($R): $isClient = (bool) $R['client_id']; ?>
<div class="card" id="req">
  <div class="row-between" style="align-items:flex-start;gap:12px;flex-wrap:wrap">
    <div>
      <h2 style="margin-bottom:2px"><?= e($R['name']) ?></h2>
      <div class="text-muted" style="font-size:13px"><?= e(trim(($R['job_title'] ?: '') . ($R['job_title'] && $R['company'] ? ' · ' : '') . ($R['company'] ?: ''))) ?: '—' ?></div>
    </div>
    <span class="pill dot <?= $pill[$R['status']] ?? 'gray' ?>"><?= e($statuses[$R['status']] ?? $R['status']) ?></span>
  </div>

  <?php if ($welcome): ?>
    <div class="alert success" style="margin-top:14px">
      <strong>Account ready.</strong> Send these login details to <?= e($R['name']) ?> — the password is shown only this once.
      <?php $msg = "Hi " . $R['name'] . ", your " . brand_name() . " account is ready.\n\nSign in: " . seo_site_url() . "/login.php\nEmail: " . $welcome['email'] . "\nPassword: " . $welcome['password'] . "\n\nPlease change the password after your first sign-in."; ?>
      <div class="int-copy"><code id="ar-welcome" style="white-space:pre-wrap"><?= e($msg) ?></code><button type="button" class="btn btn-primary btn-sm" data-copy="ar-welcome">Copy</button></div>
      <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
        <?php if ($R['phone'] && $wa($R['phone'])): ?><a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="https://wa.me/<?= $wa($R['phone']) ?>?text=<?= rawurlencode($msg) ?>">Send on WhatsApp</a><?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="mailto:<?= e($R['email']) ?>?subject=<?= rawurlencode('Your ' . brand_name() . ' account') ?>&body=<?= rawurlencode($msg) ?>">Send by email</a>
      </div>
    </div>
  <?php endif; ?>

  <dl class="lead-dl lead-dl-inline" style="margin-top:14px">
    <dt>Email</dt><dd><a href="mailto:<?= e($R['email']) ?>"><?= e($R['email']) ?></a></dd>
    <dt>WhatsApp</dt><dd><?php if ($R['phone']): ?><a class="ltr" target="_blank" rel="noopener" href="https://wa.me/<?= $wa($R['phone']) ?>"><?= e($R['phone']) ?></a><?php else: ?>—<?php endif; ?></dd>
    <dt>Job title</dt><dd><?= e($R['job_title'] ?: '—') ?></dd>
    <dt>Business</dt><dd><?= e($R['company'] ?: '—') ?></dd>
    <dt>Received</dt><dd><?= e(date('j M Y, H:i', strtotime($R['created_at']))) ?> · <?= $R['source'] === 'link' ? 'shared link' : 'landing page' ?></dd>
    <?php if ($R['utm_source'] || $R['utm_campaign'] || $R['referrer']): ?>
      <dt>Came from</dt><dd><?= e(implode(' · ', array_filter([$R['utm_source'], $R['utm_medium'], $R['utm_campaign']]))) ?><?= $R['referrer'] ? ' <span class="text-muted">' . e($R['referrer']) . '</span>' : '' ?></dd>
    <?php endif; ?>
    <?php if ($isClient): ?><dt>Client</dt><dd><a href="client.php?id=<?= (int) $R['client_id'] ?>">Open the account →</a></dd><?php endif; ?>
  </dl>
  <div class="section-label">What they want to use it for</div>
  <p style="white-space:pre-wrap;margin:0 0 14px"><?= e($R['about'] ?: '(no message)') ?></p>

  <form method="post" class="grid2" style="align-items:start">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><span class="lbl">Status</span>
      <select name="status"><?php foreach ($statuses as $k => $label): if ($k === 'converted' && !$isClient) continue; ?><option value="<?= $k ?>"<?= $R['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="field"><span class="lbl">Notes</span><textarea name="notes" rows="2" placeholder="Called on Sunday, wants a demo of the Lead Qualifier…"><?= e((string) $R['notes']) ?></textarea></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-primary btn-sm">Save</button>
      <?php if (!$isClient): ?><button type="button" class="btn btn-dark btn-sm" onclick="document.getElementById('conv-dlg').showModal()">Convert to client</button><?php endif; ?>
      <a class="btn btn-ghost btn-sm" href="mailto:<?= e($R['email']) ?>?subject=<?= rawurlencode('Your request for ' . brand_name()) ?>">Reply by email</a>
    </div>
  </form>
  <form method="post" style="margin-top:10px" onsubmit="return confirm('Delete this request for good?')">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-link btn-sm" style="color:var(--danger)">Delete (spam)</button> <a class="btn btn-link btn-sm" href="requests.php<?= $filter ? '?status=' . e($filter) : '' ?>">Close</a>
  </form>
</div>

<?php if (!$isClient):
  $pv = fn(string $k, string $d) => e((string) ($err && isset($_POST[$k]) ? $_POST[$k] : $d)); ?>
<dialog class="lead-dlg" id="conv-dlg" aria-labelledby="conv-title"<?= $err ? ' open' : '' ?>>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="convert"><input type="hidden" name="id" value="<?= $id ?>">
    <h2 id="conv-title">Convert to client</h2>
    <p class="text-muted" style="font-size:13px;margin:-4px 0 12px">Opens the account and its first login, filled in from the request. Change anything before you create it.</p>
    <div class="section-label">Business</div>
    <div class="grid2">
      <div class="field"><span class="lbl">Client / brand name *</span><input type="text" name="name" required value="<?= $pv('name', $R['company'] ?: $R['name']) ?>"></div>
      <div class="field"><span class="lbl">Company</span><input type="text" name="company" value="<?= $pv('company', (string) $R['company']) ?>"></div>
      <div class="field"><span class="lbl">Contact person</span><input type="text" name="contact_person" value="<?= $pv('contact_person', $R['name'] . ($R['job_title'] ? ' (' . $R['job_title'] . ')' : '')) ?>"></div>
      <div class="field"><span class="lbl">Contact phone</span><input type="text" name="contact_phone" value="<?= $pv('contact_phone', (string) $R['phone']) ?>"></div>
      <div class="field"><span class="lbl">Contact email</span><input type="email" name="contact_email" value="<?= $pv('contact_email', $R['email']) ?>"></div>
      <div class="field"><span class="lbl">Default country code</span><input type="text" name="default_country" value="<?= $pv('default_country', access_request_country((string) $R['phone'])) ?>" placeholder="e.g. 20"></div>
    </div>
    <div class="field"><span class="lbl">Internal notes</span><textarea name="notes" rows="2"><?= $pv('notes', trim("From the website request.\n" . ($R['about'] ?? ''))) ?></textarea></div>
    <div class="section-label">Credits and login</div>
    <div class="grid2">
      <div class="field"><span class="lbl">Starting credits</span><input type="number" name="credits" min="0" value="<?= $pv('credits', '0') ?>"></div>
      <div class="field"><span class="lbl">Low-credit alert below</span><input type="number" name="low_credit_threshold" min="0" value="<?= $pv('low_credit_threshold', '100') ?>"></div>
      <div class="field"><span class="lbl">Login email *</span><input type="email" name="email" required value="<?= $pv('email', strtolower($R['email'])) ?>"></div>
      <div class="field"><span class="lbl">Login password *</span><input type="text" name="password" required minlength="8" value="<?= $pv('password', client_account_password()) ?>"><div class="hint">You will see it once more after creating, to send to them.</div></div>
    </div>
    <div class="dlg-btns">
      <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary">Create client</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
<?php endif; ?>

<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <form method="get" style="display:flex;gap:8px;max-width:380px;width:100%">
      <?php if ($filter): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email, company, phone…">
      <button class="btn btn-ghost btn-sm" type="submit">Search</button>
    </form>
    <span class="text-muted" style="font-size:12.5px"><?= count($rows) ?> request<?= count($rows) === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Received</th><th>Name</th><th>Business</th><th>Contact</th><th>From</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7"><div class="empty"><?= $q || $filter ? 'No requests match.' : 'No requests yet. Share the link above to get the first one.' ?></div></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr<?= (int) $r['id'] === $id ? ' style="background:var(--brand-tint)"' : '' ?>>
          <td class="text-muted" style="white-space:nowrap"><?= e(date('j M, H:i', strtotime($r['created_at']))) ?></td>
          <td><a href="requests.php?id=<?= (int) $r['id'] ?><?= $filter ? '&status=' . e($filter) : '' ?>#req"><strong><?= e($r['name']) ?></strong></a>
            <?php if ($r['job_title']): ?><div class="text-muted" style="font-size:12px"><?= e($r['job_title']) ?></div><?php endif; ?></td>
          <td><?= e($r['company'] ?: '—') ?><?php if ($r['client_name']): ?><div style="font-size:12px"><a href="client.php?id=<?= (int) $r['client_id'] ?>">→ <?= e($r['client_name']) ?></a></div><?php endif; ?></td>
          <td style="font-size:12.5px"><?= e($r['email']) ?><?php if ($r['phone']): ?><div class="text-muted ltr"><?= e($r['phone']) ?></div><?php endif; ?></td>
          <td style="font-size:12.5px"><?= $r['source'] === 'link' ? 'Shared link' : 'Landing page' ?><?php if ($r['utm_source']): ?><div class="text-muted"><?= e($r['utm_source'] . ($r['utm_campaign'] ? ' · ' . $r['utm_campaign'] : '')) ?></div><?php endif; ?></td>
          <td><span class="pill dot <?= $pill[$r['status']] ?? 'gray' ?>"><?= e($statuses[$r['status']] ?? $r['status']) ?></span></td>
          <td style="text-align:end"><a class="btn btn-ghost btn-sm" href="requests.php?id=<?= (int) $r['id'] ?><?= $filter ? '&status=' . e($filter) : '' ?>#req">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => {
  const t = document.getElementById(b.dataset.copy).textContent;
  (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(() => showToast('Copied.'), () => {
    const r = document.createRange(); r.selectNodeContents(document.getElementById(b.dataset.copy));
    const s = getSelection(); s.removeAllRanges(); s.addRange(r); showToast('Selected — press Ctrl+C to copy.');
  });
}));
</script>
<?php layout_footer(); ?>
