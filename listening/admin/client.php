<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$id     = (int) ($_GET['id'] ?? 0);
$client = db_row("SELECT * FROM clients WHERE id = ?", [$id]);
if (!$client) { flash('Client not found.', 'error'); redirect('clients.php'); }

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_profile') {
        $name    = trim((string) ($_POST['name'] ?? ''));
        $status  = ($_POST['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active';
        if ($name === '') {
            $err = t('ui.name') . ' is required.';
        } else {
            db_run(
                "UPDATE clients SET name=?, brand_name=?, website=?, default_lang=?, default_country=?,
                        locale=?, alert_email=?, status=? WHERE id=?",
                [
                    $name,
                    trim((string) ($_POST['brand_name'] ?? '')),
                    trim((string) ($_POST['website'] ?? '')),
                    (string) ($_POST['default_lang'] ?? 'ar'),
                    strtoupper(trim((string) ($_POST['default_country'] ?? 'EG'))),
                    (string) ($_POST['locale'] ?? 'en'),
                    trim((string) ($_POST['alert_email'] ?? '')),
                    $status, $id,
                ]
            );
            flash(t('ui.saved'));
            redirect('client.php?id=' . $id);
        }
    }

    if ($action === 'add_user') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass  = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = t('setup.bad_email');
        } elseif (strlen($pass) < 8) {
            $err = t('setup.short_pass');
        } elseif (db_val("SELECT id FROM users WHERE email = ?", [$email])) {
            $err = t('st.email_taken');
        } else {
            db_run(
                "INSERT INTO users (client_id, name, email, password_hash, role, locale, status, created_at)
                 VALUES (?,?,?,?, 'client', ?, 'active', NOW())",
                [$id, trim((string) ($_POST['name'] ?? '')), $email,
                 password_hash($pass, PASSWORD_DEFAULT), (string) $client['locale']]
            );
            flash(t('ui.saved'));
            redirect('client.php?id=' . $id . '#users');
        }
    }

    if ($action === 'reset_password') {
        $uid  = (int) ($_POST['user_id'] ?? 0);
        $pass = (string) ($_POST['password'] ?? '');
        if (strlen($pass) < 8) {
            $err = t('setup.short_pass');
        } else {
            db_run("UPDATE users SET password_hash=? WHERE id=? AND client_id=?",
                [password_hash($pass, PASSWORD_DEFAULT), $uid, $id]);
            flash(t('st.pass_changed'));
            redirect('client.php?id=' . $id . '#users');
        }
    }

    if ($action === 'delete_client') {
        // Every child table cascades from clients.id, so this is one statement.
        db_run("DELETE FROM clients WHERE id = ?", [$id]);
        flash(t('ui.deleted'));
        redirect('clients.php');
    }
}

$client = db_row("SELECT * FROM clients WHERE id = ?", [$id]) ?: $client;
$users  = db_all("SELECT * FROM users WHERE client_id = ? ORDER BY id", [$id]);
$stats  = db_row(
    "SELECT COUNT(*) AS mentions,
            SUM(sentiment='negative') AS negative,
            SUM(classify_state='needs_ai') AS pending_ai
       FROM mentions WHERE client_id = ?",
    [$id]
) ?: [];
$sources = db_all("SELECT * FROM sources WHERE client_id = ? ORDER BY connector", [$id]);

layout_header((string) $client['name'], 'admin', 'clients');
page_head((string) $client['name'], (string) $client['brand_name'],
    '<a class="btn btn-primary" href="open_workspace.php?id=' . $id . '&amp;csrf=' . e(csrf_token()) . '">'
    . e(t('ad.open_ws')) . ' →</a>');

if ($err !== '') echo '<div class="alert error">' . e($err) . '</div>';
?>

<div class="stats-row">
  <?= stat_tile(t('nav.mentions'),      fmt_num((int) ($stats['mentions'] ?? 0)), 'accent') ?>
  <?= stat_tile(t('sent.negative'),     fmt_num((int) ($stats['negative'] ?? 0)), 'danger') ?>
  <?= stat_tile('AI queue',             fmt_num((int) ($stats['pending_ai'] ?? 0))) ?>
  <?= stat_tile(t('nav.sources'),       fmt_num(count($sources))) ?>
</div>

<div class="card">
  <h2><?= e(t('ui.edit')) ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_profile">
    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('ui.name')) ?></span>
        <input type="text" name="name" value="<?= e((string) $client['name']) ?>" required>
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('ad.brand')) ?></span>
        <input type="text" name="brand_name" value="<?= e((string) $client['brand_name']) ?>" dir="auto">
      </div>
    </div>
    <div class="grid2">
      <div class="field">
        <span class="lbl">Website</span>
        <input type="text" name="website" value="<?= e((string) $client['website']) ?>">
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('st.alert_email')) ?></span>
        <input type="email" name="alert_email" value="<?= e((string) $client['alert_email']) ?>">
      </div>
    </div>
    <div class="grid3">
      <div class="field">
        <span class="lbl"><?= e(t('kw.lang')) ?></span>
        <select name="default_lang">
          <?php foreach (['ar' => 'العربية', 'en' => 'English', 'both' => 'Both'] as $v => $l): ?>
            <option value="<?= e($v) ?>" <?= (string) $client['default_lang'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('kw.country')) ?></span>
        <input type="text" name="default_country" value="<?= e((string) $client['default_country']) ?>" maxlength="4">
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('ui.status')) ?></span>
        <select name="status">
          <option value="active"   <?= (string) $client['status'] === 'active'   ? 'selected' : '' ?>><?= e(t('ui.active')) ?></option>
          <option value="disabled" <?= (string) $client['status'] === 'disabled' ? 'selected' : '' ?>><?= e(t('ui.paused')) ?></option>
        </select>
      </div>
    </div>
    <input type="hidden" name="locale" value="<?= e((string) $client['locale']) ?>">
    <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
  </form>
</div>

<div class="card">
  <h2><?= e(t('nav.settings')) ?></h2>
  <p class="text-muted" style="font-size:12.5px">
    API keys and Meta credentials are entered inside the client's own workspace so the
    secret never passes through an admin screen.
    <a href="open_workspace.php?id=<?= $id ?>&amp;csrf=<?= e(csrf_token()) ?>"><?= e(t('ad.open_ws')) ?> →</a>
  </p>
  <div class="filter-row" style="margin-top:12px">
    <span class="pill <?= trim((string) $client['ai_api_key_enc']) !== '' ? 'green' : 'gray' ?>">
      AI: <?= trim((string) $client['ai_api_key_enc']) !== '' ? e((string) $client['ai_provider']) : e(t('ui.none')) ?></span>
    <span class="pill <?= trim((string) $client['aggregator_key_enc']) !== '' ? 'green' : 'gray' ?>">
      SerpApi</span>
    <span class="pill <?= trim((string) $client['youtube_key_enc']) !== '' ? 'green' : 'gray' ?>">YouTube</span>
    <span class="pill <?= trim((string) $client['meta_token_enc']) !== '' ? 'green' : 'gray' ?>">Meta</span>
  </div>
</div>

<div class="card card-flush" id="users">
  <div class="card-head">
    <h2><?= e(t('nav.team')) ?></h2>
    <button class="btn btn-sm btn-primary" data-modal="m-user">+ <?= e(t('ui.add')) ?></button>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th><?= e(t('auth.email')) ?></th><th><?= e(t('ui.name')) ?></th>
                 <th>Last login</th><th></th></tr></thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="4"><div class="empty"><?= e(t('ui.empty')) ?></div></td></tr>
      <?php else: foreach ($users as $u): ?>
        <tr>
          <td><?= e((string) $u['email']) ?></td>
          <td><?= e((string) $u['name']) ?></td>
          <td class="text-muted"><?= e(time_ago((string) $u['last_login_at'])) ?></td>
          <td class="nowrap">
            <form method="post" style="display:flex;gap:6px;align-items:center">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
              <input type="password" name="password" placeholder="<?= e(t('st.new_pass')) ?>"
                     minlength="8" style="width:160px">
              <button class="btn btn-sm" type="submit"><?= e(t('ui.save')) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2 style="color:var(--danger)">Danger zone</h2>
  <p class="text-muted" style="font-size:12.5px;margin-bottom:12px">
    Deleting this client permanently removes its keywords, sources, mentions and alerts.
  </p>
  <form method="post" onsubmit="return confirm('<?= e((string) $client['name']) ?> — <?= e(t('ui.delete')) ?>?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_client">
    <button class="btn btn-danger" type="submit"><?= e(t('ui.delete')) ?></button>
  </form>
</div>

<div class="modal-back" id="m-user">
  <form class="modal" method="post">
    <button type="button" class="modal-x">&times;</button>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_user">
    <h2><?= e(t('ui.add')) ?></h2>
    <div class="field">
      <span class="lbl"><?= e(t('ui.name')) ?></span>
      <input type="text" name="name">
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('auth.email')) ?></span>
      <input type="email" name="email" required>
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('auth.password')) ?></span>
      <input type="password" name="password" required minlength="8">
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost modal-x" style="position:static"><?= e(t('ui.cancel')) ?></button>
      <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
    </div>
  </form>
</div>

<?php layout_footer(); ?>
