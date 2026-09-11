<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ((string) ($_POST['action'] ?? '') === 'create') {
        $name   = trim((string) ($_POST['name'] ?? ''));
        $brand  = trim((string) ($_POST['brand_name'] ?? ''));
        $email  = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass   = (string) ($_POST['password'] ?? '');
        $lang   = (string) ($_POST['default_lang'] ?? 'ar');
        $country = strtoupper(trim((string) ($_POST['default_country'] ?? 'EG')));
        $locale = (string) ($_POST['locale'] ?? 'en');

        if ($name === '') {
            $err = t('ui.name') . ' is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = t('setup.bad_email');
        } elseif (strlen($pass) < 8) {
            $err = t('setup.short_pass');
        } elseif (db_val("SELECT id FROM users WHERE email = ?", [$email])) {
            $err = t('st.email_taken');
        } else {
            // Client and its first login are created together, in one
            // transaction — a client with no way to log in is not useful.
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $cid = db_insert(
                    "INSERT INTO clients (name, brand_name, default_lang, default_country, locale,
                                          alert_email, status, created_at)
                     VALUES (?,?,?,?,?,?, 'active', NOW())",
                    [$name, $brand ?: $name, $lang, $country, $locale, $email]
                );
                db_run(
                    "INSERT INTO users (client_id, name, email, password_hash, role, locale, status, created_at)
                     VALUES (?,?,?,?, 'client', ?, 'active', NOW())",
                    [$cid, $name, $email, password_hash($pass, PASSWORD_DEFAULT), $locale]
                );
                $pdo->commit();
                flash(t('ui.saved'));
                redirect('client.php?id=' . $cid);
            } catch (Throwable $ex) {
                $pdo->rollBack();
                error_log('client create failed: ' . $ex->getMessage());
                $err = t('ui.error');
            }
        }
    }
}

$clients = db_all(
    "SELECT c.*,
            (SELECT COUNT(*) FROM users u    WHERE u.client_id    = c.id) AS users,
            (SELECT COUNT(*) FROM keywords k WHERE k.client_id    = c.id) AS keywords,
            (SELECT COUNT(*) FROM sources s  WHERE s.client_id    = c.id) AS sources,
            (SELECT COUNT(*) FROM mentions m WHERE m.client_id    = c.id) AS mentions
       FROM clients c ORDER BY c.name"
);

$addBtn = '<button class="btn btn-primary" data-modal="m-client">+ ' . e(t('ad.add_client')) . '</button>';

layout_header(t('nav.clients'), 'admin', 'clients');
page_head(t('ad.clients'), '', $addBtn);

if ($err !== '') echo '<div class="alert error">' . e($err) . '</div>';
?>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th><?= e(t('ui.name')) ?></th><th><?= e(t('nav.keywords')) ?></th>
            <th><?= e(t('nav.sources')) ?></th><th><?= e(t('nav.mentions')) ?></th>
            <th><?= e(t('ui.status')) ?></th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$clients): ?>
        <tr><td colspan="6"><div class="empty"><?= e(t('ui.empty')) ?></div></td></tr>
      <?php else: foreach ($clients as $c): ?>
        <tr>
          <td><strong><?= e((string) $c['name']) ?></strong>
            <br><span class="text-muted" style="font-size:11.5px"><?= e((string) $c['brand_name']) ?></span></td>
          <td><?= (int) $c['keywords'] ?></td>
          <td><?= (int) $c['sources'] ?></td>
          <td><?= e(fmt_num((int) $c['mentions'])) ?></td>
          <td><?= status_pill((string) $c['status'] === 'active' ? 'active' : 'paused') ?></td>
          <td class="nowrap">
            <a class="btn btn-sm btn-ghost" href="client.php?id=<?= (int) $c['id'] ?>"><?= e(t('ui.edit')) ?></a>
            <a class="btn btn-sm" href="open_workspace.php?id=<?= (int) $c['id'] ?>&amp;csrf=<?= e(csrf_token()) ?>">
              <?= e(t('ad.open_ws')) ?> →</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back" id="m-client">
  <form class="modal" method="post">
    <button type="button" class="modal-x">&times;</button>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <h2><?= e(t('ad.add_client')) ?></h2>

    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('ui.name')) ?></span>
        <input type="text" name="name" required>
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('ad.brand')) ?></span>
        <input type="text" name="brand_name" dir="auto">
      </div>
    </div>

    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('kw.lang')) ?></span>
        <select name="default_lang">
          <option value="ar">العربية</option>
          <option value="en">English</option>
          <option value="both">Both</option>
        </select>
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('kw.country')) ?></span>
        <input type="text" name="default_country" value="EG" maxlength="4">
      </div>
    </div>

    <h2 style="margin-top:18px;font-size:14px"><?= e(t('ad.first_user')) ?></h2>
    <div class="field">
      <span class="lbl"><?= e(t('auth.email')) ?></span>
      <input type="email" name="email" required>
    </div>
    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('auth.password')) ?></span>
        <input type="password" name="password" required minlength="8">
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('ui.language')) ?></span>
        <select name="locale">
          <?php foreach (locales() as $code => $meta): ?>
            <option value="<?= e($code) ?>"><?= e($meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn btn-ghost modal-x" style="position:static"><?= e(t('ui.cancel')) ?></button>
      <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
    </div>
  </form>
</div>

<?php layout_footer(); ?>
