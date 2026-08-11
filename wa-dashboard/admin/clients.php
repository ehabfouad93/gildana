<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$err = '';

/* ── AJAX: quick enable/disable toggle ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'toggle_status') {
        $id  = (int) ($_POST['id'] ?? 0);
        $row = db_row("SELECT status FROM clients WHERE id=?", [$id]);
        if (!$row) json_out(['ok' => false]);
        $new = $row['status'] === 'active' ? 'disabled' : 'active';
        db_run("UPDATE clients SET status=? WHERE id=?", [$new, $id]);
        json_out(['ok' => true, 'status' => $new]);
    }
    json_out(['ok' => false]);
}

/* ── Create client ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_client') {
    verify_csrf();
    $name    = trim((string) ($_POST['name'] ?? ''));
    $email   = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass    = (string) ($_POST['password'] ?? '');
    $credits = max(0, (int) ($_POST['credits'] ?? 0));

    if ($name === '') {
        $err = 'Client name is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Enter a valid login email.';
    } elseif (strlen($pass) < 8) {
        $err = 'Login password must be at least 8 characters.';
    } elseif (db_val("SELECT COUNT(*) FROM users WHERE email = ?", [$email])) {
        $err = 'That login email is already in use.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $clientId = db_insert(
                "INSERT INTO clients
                    (name, sender_display, company, contact_person, contact_phone, contact_email,
                     category, timezone, default_country, notes, credits_balance, low_credit_threshold, status, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,0,?, 'active', NOW())",
                [
                    $name,
                    trim((string) ($_POST['sender_display'] ?? '')) ?: $name,
                    trim((string) ($_POST['company'] ?? '')),
                    trim((string) ($_POST['contact_person'] ?? '')),
                    trim((string) ($_POST['contact_phone'] ?? '')),
                    trim((string) ($_POST['contact_email'] ?? '')),
                    trim((string) ($_POST['category'] ?? '')),
                    trim((string) ($_POST['timezone'] ?? '')),
                    trim((string) ($_POST['default_country'] ?? '')),
                    trim((string) ($_POST['notes'] ?? '')) ?: null,
                    max(0, (int) ($_POST['low_credit_threshold'] ?? 100)),
                ]
            );
            db_insert(
                "INSERT INTO users (client_id, email, password_hash, role, status, created_at)
                 VALUES (?,?,?, 'client', 'active', NOW())",
                [$clientId, $email, password_hash($pass, PASSWORD_DEFAULT)]
            );
            $pdo->commit();
            if ($credits > 0) credits_adjust($clientId, $credits, 'initial_grant');
            flash('Client "' . $name . '" created. Now add their WhatsApp credentials.');
            redirect('client.php?id=' . $clientId);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('client create failed: ' . $ex->getMessage());
            $err = 'Could not create the client. Please try again.';
        }
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$where = '1=1'; $params = [];
if ($q !== '') { $where = "(c.name LIKE ? OR c.company LIKE ?)"; $params = ["%$q%", "%$q%"]; }

$clients = db_all(
    "SELECT c.*,
            (SELECT email FROM users u WHERE u.client_id = c.id ORDER BY u.id LIMIT 1) AS login_email,
            (SELECT COUNT(*) FROM campaigns cp WHERE cp.client_id = c.id) AS campaign_count
       FROM clients c WHERE $where ORDER BY c.id DESC", $params
);

$createBtn = '<button class="btn btn-primary" onclick="document.getElementById(\'m-create\').classList.add(\'open\')">+ New Client</button>';

layout_header('Clients', 'admin', 'clients');
page_head('Clients', $createBtn);

if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <form method="get" style="display:flex;gap:8px;max-width:340px;width:100%">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or company…">
      <button class="btn btn-ghost btn-sm" type="submit">Search</button>
    </form>
    <span class="text-muted" style="font-size:12.5px"><?= count($clients) ?> account<?= count($clients) === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Client</th><th>Login</th><th>WhatsApp</th><th>Credits</th><th>Campaigns</th><th>Enabled</th><th></th></tr></thead>
      <tbody>
      <?php if (!$clients): ?>
        <tr><td colspan="7"><div class="empty">No clients<?= $q ? ' match your search' : ' yet — create your first account' ?>.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($clients as $c):
        $low = (int) $c['credits_balance'] < (int) $c['low_credit_threshold'];
      ?>
        <tr id="cl-<?= (int) $c['id'] ?>">
          <td><strong><?= e($c['name']) ?></strong><?php if ($c['company']): ?><br><small class="text-muted"><?= e((string) $c['company']) ?></small><?php endif; ?></td>
          <td class="text-muted"><?= e((string) $c['login_email']) ?></td>
          <td><?= $c['phone_number_id'] ? '<span class="pill green">Connected</span>' : '<span class="pill gray">Not set</span>' ?></td>
          <td><span class="pill <?= $low ? 'red' : 'gold' ?>"><?= number_format((int) $c['credits_balance']) ?></span></td>
          <td><?= (int) $c['campaign_count'] ?></td>
          <td>
            <label class="switch">
              <input type="checkbox" <?= $c['status'] === 'active' ? 'checked' : '' ?> onchange="toggleStatus(<?= (int) $c['id'] ?>,this)">
              <span class="slider"></span>
            </label>
          </td>
          <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="client.php?id=<?= (int) $c['id'] ?>">Manage</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create client modal -->
<div class="modal-back" id="m-create">
  <form class="modal" method="post" style="max-width:640px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_client">
    <h2>New Client Account</h2>

    <div class="section-label">Business</div>
    <div class="grid2">
      <div class="field"><span class="lbl">Client / Brand Name *</span><input type="text" name="name" required></div>
      <div class="field"><span class="lbl">Company</span><input type="text" name="company"></div>
      <div class="field"><span class="lbl">Sender Display Name</span><input type="text" name="sender_display" placeholder="Defaults to client name"></div>
      <div class="field"><span class="lbl">Business Category</span><input type="text" name="category" placeholder="e.g. Retail, Hotel"></div>
      <div class="field"><span class="lbl">Contact Person</span><input type="text" name="contact_person"></div>
      <div class="field"><span class="lbl">Contact Phone</span><input type="text" name="contact_phone"></div>
      <div class="field"><span class="lbl">Contact Email</span><input type="email" name="contact_email"></div>
      <div class="field"><span class="lbl">Timezone</span><input type="text" name="timezone" placeholder="e.g. Africa/Cairo"></div>
      <div class="field"><span class="lbl">Default Country Code</span><input type="text" name="default_country" placeholder="e.g. 20"><div class="hint">Normalizes local numbers on import.</div></div>
    </div>
    <div class="field"><span class="lbl">Internal Notes</span><textarea name="notes" rows="2" placeholder="Only visible to you"></textarea></div>

    <div class="section-label">Credits</div>
    <div class="grid2">
      <div class="field"><span class="lbl">Starting Credits</span><input type="number" name="credits" min="0" value="0"></div>
      <div class="field"><span class="lbl">Low-credit Alert Below</span><input type="number" name="low_credit_threshold" min="0" value="100"></div>
    </div>

    <div class="section-label">Client Login</div>
    <div class="grid2">
      <div class="field"><span class="lbl">Login Email *</span><input type="email" name="email" required></div>
      <div class="field"><span class="lbl">Login Password *</span><input type="text" name="password" required minlength="8"><div class="hint">Share with the client. Min 8 chars.</div></div>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('m-create').classList.remove('open')">Cancel</button>
      <button type="submit" class="btn btn-primary">Create Account</button>
    </div>
  </form>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
async function toggleStatus(id, el){
  const fd=new FormData(); fd.append('ajax','1'); fd.append('csrf_token',CSRF); fd.append('action','toggle_status'); fd.append('id',id);
  const r=await fetch('',{method:'POST',body:fd}); const d=await r.json();
  if(d.ok){ showToast(d.status==='active'?'Account enabled.':'Account disabled.'); }
  else { el.checked=!el.checked; showToast('Could not update.',true); }
}
</script>

<?php layout_footer(); ?>
