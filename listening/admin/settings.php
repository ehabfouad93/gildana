<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * The admin's own account, plus the operational readouts you need when
 * something looks wrong: is the worker alive, did the migrations apply, and
 * which per-client credentials are actually configured.
 */

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_account') {
        $email  = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name   = trim((string) ($_POST['name'] ?? ''));
        $locale = (string) ($_POST['locale'] ?? 'en');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = t('setup.bad_email');
        } elseif (db_val("SELECT id FROM users WHERE email=? AND id<>?", [$email, (int) $ME['id']])) {
            $err = t('st.email_taken');
        } else {
            db_run("UPDATE users SET email=?, name=?, locale=? WHERE id=?",
                [$email, $name, $locale, (int) $ME['id']]);
            $_SESSION['email']  = $email;
            $_SESSION['name']   = $name;
            $_SESSION['locale'] = $locale;
            flash(t('ui.saved'));
            redirect('settings.php');
        }
    }

    if ($action === 'change_password') {
        $user = db_row("SELECT * FROM users WHERE id=?", [(int) $ME['id']]);
        $cur  = (string) ($_POST['current_password'] ?? '');
        $new  = (string) ($_POST['new_password'] ?? '');
        if (!$user || !password_verify($cur, (string) $user['password_hash'])) {
            $err = t('st.pass_wrong');
        } elseif (strlen($new) < 8) {
            $err = t('setup.short_pass');
        } else {
            db_run("UPDATE users SET password_hash=? WHERE id=?",
                [password_hash($new, PASSWORD_DEFAULT), (int) $ME['id']]);
            flash(t('st.pass_changed'));
            redirect('settings.php');
        }
    }

    if ($action === 'run_migrations') {
        try {
            $ran = migrate();
            flash($ran ? ('Applied: ' . implode(', ', $ran)) : 'Already up to date.');
        } catch (Throwable $ex) {
            error_log('migrate failed: ' . $ex->getMessage());
            $err = 'Migration failed: ' . $ex->getMessage();
        }
        if ($err === '') redirect('settings.php');
    }
}

$user = db_row("SELECT * FROM users WHERE id = ?", [(int) $ME['id']]) ?: [];

$applied = [];
try {
    $applied = db_all("SELECT filename, applied_at FROM schema_migrations ORDER BY filename");
} catch (Throwable $e) { /* table not created yet — the button below fixes that */ }

$pending = [];
foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $file) {
    $name = basename($file);
    if (!in_array($name, array_column($applied, 'filename'), true)) $pending[] = $name;
}

$heartbeat = @filemtime(dirname(__DIR__) . '/cron/.heartbeat');
$clientCreds = db_all(
    "SELECT id, name,
            ai_provider,
            ai_api_key_enc     IS NOT NULL AND ai_api_key_enc     <> '' AS has_ai,
            aggregator_key_enc IS NOT NULL AND aggregator_key_enc <> '' AS has_agg,
            youtube_key_enc    IS NOT NULL AND youtube_key_enc    <> '' AS has_yt,
            meta_token_enc     IS NOT NULL AND meta_token_enc     <> '' AS has_meta,
            meta_token_updated_at
       FROM clients WHERE status = 'active' ORDER BY name"
);

layout_header(t('nav.settings'), 'admin', 'settings');
page_head(t('st.title'));

if ($err !== '') echo '<div class="alert error">' . e($err) . '</div>';
?>

<div class="card">
  <h2>Background worker</h2>
  <?php if ($heartbeat === false): ?>
    <div class="alert error">The worker has never run. Add the cron job — see DEPLOY.md.</div>
  <?php elseif ($heartbeat < time() - 900): ?>
    <div class="alert error"><?= e(t('ad.cron_stale')) ?>
      Last run: <?= e(date('Y-m-d H:i', $heartbeat)) ?>.</div>
  <?php else: ?>
    <div class="alert success">Last run <?= e(time_ago(date('Y-m-d H:i:s', $heartbeat))) ?>.</div>
  <?php endif; ?>
  <p class="text-muted" style="font-size:12.5px">
    Expected cron (every 5 minutes):
    <code class="mono">*/5 * * * * php <?= e(dirname(__DIR__)) ?>/cron/worker.php</code>
  </p>
</div>

<div class="card">
  <h2>Database</h2>
  <?php if ($pending): ?>
    <div class="alert warn">
      Pending migrations: <?= e(implode(', ', $pending)) ?>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="run_migrations">
      <button class="btn btn-primary" type="submit">Run migrations now</button>
    </form>
  <?php else: ?>
    <p class="text-muted" style="font-size:12.5px">
      <?= count($applied) ?> migration(s) applied, none pending.
    </p>
  <?php endif; ?>
  <?php if ($applied): ?>
    <div class="filter-row" style="margin-top:12px">
      <?php foreach ($applied as $m): ?>
        <span class="pill gray"><?= e((string) $m['filename']) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card card-flush">
  <div class="card-head"><h2>Client credentials</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th><?= e(t('nav.clients')) ?></th><th>AI</th><th>SerpApi</th>
            <th>YouTube</th><th>Meta</th><th>Token age</th></tr>
      </thead>
      <tbody>
      <?php if (!$clientCreds): ?>
        <tr><td colspan="6"><div class="empty"><?= e(t('ui.empty')) ?></div></td></tr>
      <?php else: foreach ($clientCreds as $c):
        // Meta long-lived tokens last about 60 days — warn before one expires.
        $age = $c['meta_token_updated_at']
            ? (int) floor((time() - strtotime((string) $c['meta_token_updated_at'])) / 86400)
            : null; ?>
        <tr>
          <td><a href="client.php?id=<?= (int) $c['id'] ?>"><?= e((string) $c['name']) ?></a></td>
          <td><span class="pill <?= $c['has_ai'] ? 'green' : 'gray' ?>">
            <?= $c['has_ai'] ? e((string) $c['ai_provider']) : '—' ?></span></td>
          <td><span class="pill <?= $c['has_agg']  ? 'green' : 'gray' ?>"><?= $c['has_agg']  ? '✓' : '—' ?></span></td>
          <td><span class="pill <?= $c['has_yt']   ? 'green' : 'gray' ?>"><?= $c['has_yt']   ? '✓' : '—' ?></span></td>
          <td><span class="pill <?= $c['has_meta'] ? 'green' : 'gray' ?>"><?= $c['has_meta'] ? '✓' : '—' ?></span></td>
          <td class="text-muted">
            <?php if ($age === null): ?>—
            <?php elseif ($age >= 50): ?><span class="pill red"><?= $age ?>d — renew</span>
            <?php else: ?><?= $age ?>d<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2><?= e(t('st.account')) ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_account">
    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('ui.name')) ?></span>
        <input type="text" name="name" value="<?= e((string) ($user['name'] ?? '')) ?>">
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('auth.email')) ?></span>
        <input type="email" name="email" value="<?= e((string) ($user['email'] ?? '')) ?>" required>
      </div>
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('ui.language')) ?></span>
      <select name="locale">
        <?php foreach (locales() as $code => $meta): ?>
          <option value="<?= e($code) ?>" <?= (string) ($user['locale'] ?? 'en') === $code ? 'selected' : '' ?>>
            <?= e($meta['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
  </form>

  <hr style="margin:22px 0;border:none;border-top:1px solid var(--line)">

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('st.current_pass')) ?></span>
        <input type="password" name="current_password" required>
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('st.new_pass')) ?></span>
        <input type="password" name="new_password" required minlength="8">
      </div>
    </div>
    <button class="btn" type="submit"><?= e(t('st.change_pass')) ?></button>
  </form>
</div>

<?php layout_footer(); ?>
