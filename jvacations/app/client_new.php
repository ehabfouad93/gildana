<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Step 1 — the advisor adds a lead. The same form edits a lead while it is
 * still waiting for the booker; after that the lead belongs to the pipeline.
 */
require_role('advisor');

$id = (int) ($_GET['id'] ?? 0);
$c  = null;
if ($id) {
    $c = client_or_403($id, $ME);
    if ($c['stage'] !== 'new' && $ME['role'] !== 'admin') {
        flash(t('client.locked'), 'error');
        redirect('client.php?id=' . $id);
    }
}

$fields = ['full_name', 'phone', 'phone2', 'email', 'city', 'job', 'marital_status', 'source', 'notes'];
$val = [];
foreach ($fields as $f) $val[$f] = $_SERVER['REQUEST_METHOD'] === 'POST' ? post_str($f) : (string) ($c[$f] ?? '');

$err = '';
$dupeWarn = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $phoneDigits = preg_replace('/\D/', '', $val['phone']);
    if ($val['full_name'] === '') {
        $err = t('client.need_name');
    } elseif (strlen($phoneDigits) < 7) {
        $err = t('client.need_phone');
    } elseif ($val['email'] !== '' && !filter_var($val['email'], FILTER_VALIDATE_EMAIL)) {
        $err = t('err.bad_email');
    } else {
        $dupe = db_row("SELECT id, full_name FROM clients WHERE (phone = ? OR phone2 = ?) AND id <> ?", [$val['phone'], $val['phone'], $id]);
        if ($dupe && empty($_POST['allow_dupe'])) {
            $err = t('client.dupe', ['name' => $dupe['full_name'], 'id' => (string) $dupe['id']]);
            $dupeWarn = true;
        }
    }

    if ($err === '') {
        $params = array_map(fn($f) => $val[$f] !== '' ? $val[$f] : null, $fields);
        if ($c) {
            db_run("UPDATE clients SET full_name=?, phone=?, phone2=?, email=?, city=?, job=?, marital_status=?, source=?, notes=?, updated_at=NOW() WHERE id=?",
                array_merge($params, [$id]));
            log_event($id, 'edited');
        } else {
            $id = db_insert("INSERT INTO clients (full_name, phone, phone2, email, city, job, marital_status, source, notes, stage, created_by, created_at, updated_at)
                             VALUES (?,?,?,?,?,?,?,?,?, 'new', ?, NOW(), NOW())", array_merge($params, [$ME['id']]));
            log_event($id, 'created');
        }
        flash(t('ui.saved'));
        redirect(isset($_POST['and_new']) ? 'client_new.php' : 'clients.php');
    }
}

$sources = array_combine(setting_list('lead_sources'), setting_list('lead_sources'));
$marital = ['single' => t('marital.single'), 'married' => t('marital.married'), 'other' => t('marital.other')];

layout_header($c ? t('client.edit') : t('nav.client_new'), $c ? 'clients' : 'client_new');
page_head($c ? t('client.edit') : t('nav.client_new'), t('client.new_sub'));
?>
<?php if ($err !== ''): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
<form method="post" class="card form-card">
  <?= csrf_field() ?>
  <div class="grid2">
    <div class="field"><span class="lbl"><?= e(t('client.full_name')) ?> *</span>
      <input type="text" name="full_name" value="<?= e($val['full_name']) ?>" required autofocus></div>
    <div class="field"><span class="lbl"><?= e(t('client.phone')) ?> *</span>
      <input type="tel" name="phone" value="<?= e($val['phone']) ?>" required dir="ltr"></div>
    <div class="field"><span class="lbl"><?= e(t('client.phone2')) ?></span>
      <input type="tel" name="phone2" value="<?= e($val['phone2']) ?>" dir="ltr"></div>
    <div class="field"><span class="lbl"><?= e(t('client.email')) ?></span>
      <input type="email" name="email" value="<?= e($val['email']) ?>" dir="ltr"></div>
    <div class="field"><span class="lbl"><?= e(t('client.city')) ?></span>
      <input type="text" name="city" value="<?= e($val['city']) ?>"></div>
    <div class="field"><span class="lbl"><?= e(t('client.job')) ?></span>
      <input type="text" name="job" value="<?= e($val['job']) ?>"></div>
    <div class="field"><span class="lbl"><?= e(t('client.marital')) ?></span>
      <select name="marital_status"><?= options($marital, $val['marital_status']) ?></select></div>
    <div class="field"><span class="lbl"><?= e(t('client.source')) ?></span>
      <select name="source"><?= options($sources, $val['source']) ?></select></div>
  </div>
  <div class="field"><span class="lbl"><?= e(t('client.notes')) ?></span>
    <textarea name="notes"><?= e($val['notes']) ?></textarea></div>
  <?php if ($dupeWarn): ?>
    <label class="check"><input type="checkbox" name="allow_dupe" value="1"> <?= e(t('client.allow_dupe')) ?></label>
  <?php endif; ?>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
    <?php if (!$c): ?><button class="btn" type="submit" name="and_new" value="1"><?= e(t('client.save_new')) ?></button><?php endif; ?>
    <a class="btn btn-ghost" href="clients.php"><?= e(t('ui.cancel')) ?></a>
  </div>
</form>
<?php layout_footer();
