<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require dirname(__DIR__) . '/includes/docx.php';

/**
 * Admin → Contract templates. Upload the company's own Word contract with
 * {placeholders}; every reservation can then be downloaded filled in. A
 * template can be limited to one project, and one language.
 */
require_role('admin');


if (($_GET['starter'] ?? '') !== '') {
    $lang = $_GET['starter'] === 'en' ? 'en' : 'ar';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="jvacations-contract-template-' . $lang . '.docx"');
    echo docx_starter($lang);
    exit;
}
if (isset($_GET['download'])) {
    $tpl = db_row("SELECT * FROM contract_templates WHERE id = ?", [(int) $_GET['download']]);
    $path = $tpl ? templates_dir() . '/' . basename($tpl['file_name']) : '';
    if (!$tpl || !is_file($path)) { http_response_code(404); exit(t('err.not_found')); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $tpl['name']) . '.docx"');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'upload') {
        $f    = $_FILES['file'] ?? null;
        $name = mb_substr(post_str('name'), 0, 150);
        $lang = ($_POST['language'] ?? '') === 'en' ? 'en' : 'ar';
        $proj = (int) ($_POST['project_id'] ?? 0) ?: null;
        $ok   = $f && ($f['error'] ?? 1) === UPLOAD_ERR_OK && $f['size'] > 0 && $f['size'] <= 10 * 1024 * 1024
             && strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) === 'docx' && docx_valid((string) $f['tmp_name']);
        if ($name === '')     flash(t('tpl.need_name'), 'error');
        elseif (!$ok)         flash(t('tpl.bad_file'), 'error');
        else {
            if (!is_dir(templates_dir())) @mkdir(templates_dir(), 0775, true);
            $stored = bin2hex(random_bytes(12)) . '.docx';
            if (!move_uploaded_file((string) $f['tmp_name'], templates_dir() . '/' . $stored)) {
                flash(t('tpl.cant_save'), 'error');
            } else {
                db_insert("INSERT INTO contract_templates (name, language, project_id, file_name, active, uploaded_by, created_at) VALUES (?,?,?,?,1,?,NOW())",
                    [$name, $lang, $proj, $stored, $ME['id']]);
                flash(t('tpl.uploaded'));
            }
        }
    } elseif ($do === 'toggle') {
        db_run("UPDATE contract_templates SET active = 1 - active WHERE id = ?", [(int) $_POST['id']]);
    } elseif ($do === 'delete') {
        $tpl = db_row("SELECT * FROM contract_templates WHERE id = ?", [(int) $_POST['id']]);
        if ($tpl) {
            @unlink(templates_dir() . '/' . basename($tpl['file_name']));
            db_run("DELETE FROM contract_templates WHERE id = ?", [$tpl['id']]);
            flash(t('ui.deleted'));
        }
    }
    redirect('contract_templates.php');
}

$rows = db_all("SELECT t.*, p.name AS project_name, u.name AS by_name FROM contract_templates t
                  LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN users u ON u.id = t.uploaded_by ORDER BY t.active DESC, t.id DESC");
$projects = [];
foreach (db_all("SELECT id, name FROM projects ORDER BY name") as $p) $projects[$p['id']] = $p['name'];

layout_header(t('nav.templates'), 'templates');
page_head(t('nav.templates'), t('tpl.sub'),
    '<a class="btn" href="?starter=ar">' . e(t('tpl.starter_ar')) . '</a><a class="btn" href="?starter=en">' . e(t('tpl.starter_en')) . '</a>');
?>
<div class="grid-main">
<div>
  <div class="card card-flush">
    <?php if (!$rows): ?><div class="empty"><?= e(t('tpl.empty')) ?></div><?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th><?= e(t('tpl.name')) ?></th><th><?= e(t('tpl.language')) ?></th><th><?= e(t('contract.project')) ?></th><th><?= e(t('tpl.uploaded_by')) ?></th><th></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['active'] ? '' : 'row-muted' ?>">
          <td><strong><?= e($r['name']) ?></strong></td>
          <td><?= $r['language'] === 'ar' ? 'العربية' : 'English' ?></td>
          <td><?= e($r['project_name'] ?? t('tpl.any_project')) ?></td>
          <td class="small text-muted"><?= e(($r['by_name'] ?? '—') . ' · ' . fmt_dt($r['created_at'])) ?></td>
          <td><?= $r['active'] ? '<span class="pill green dot">' . e(t('ui.active')) . '</span>' : '<span class="pill gray dot">' . e(t('ui.inactive')) . '</span>' ?></td>
          <td class="nowrap">
            <a class="btn btn-sm" href="?download=<?= (int) $r['id'] ?>"><?= e(t('tpl.download')) ?></a>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm" type="submit"><?= e($r['active'] ? t('wa.pause') : t('wa.resume')) ?></button></form>
            <form method="post" class="inline" data-confirm-form="<?= e(t('tpl.delete_q')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-danger-ghost" type="submit">✕</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= e(t('tpl.placeholders')) ?></h2>
    <p class="hint-line"><?= e(t('tpl.placeholders_hint')) ?></p>
    <div class="ph-grid">
      <?php foreach (contract_var_keys() as $k): ?>
        <div class="ph-row"><code>{<?= e($k) ?>}</code><span><?= e(t('tpl.var.' . $k)) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<form method="post" enctype="multipart/form-data" class="card action-card">
  <?= csrf_field() ?><input type="hidden" name="do" value="upload">
  <h2><?= e(t('tpl.upload')) ?></h2>
  <ol class="howto small"><li><?= e(t('tpl.step1')) ?></li><li><?= e(t('tpl.step2')) ?></li><li><?= e(t('tpl.step3')) ?></li></ol>
  <div class="field"><span class="lbl"><?= e(t('tpl.name')) ?> *</span><input type="text" name="name" required></div>
  <div class="grid2">
    <div class="field"><span class="lbl"><?= e(t('tpl.language')) ?></span><select name="language"><option value="ar">العربية</option><option value="en">English</option></select></div>
    <div class="field"><span class="lbl"><?= e(t('contract.project')) ?></span><select name="project_id"><option value=""><?= e(t('tpl.any_project')) ?></option><?= options($projects, '', false) ?></select></div>
  </div>
  <div class="field"><span class="lbl"><?= e(t('tpl.file')) ?> (.docx) *</span><input type="file" name="file" accept=".docx" required></div>
  <button class="btn btn-primary" type="submit"><?= e(t('tpl.upload_btn')) ?></button>
</form>
</div>
<?php layout_footer();
