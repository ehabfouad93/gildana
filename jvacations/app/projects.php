<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/** Resorts / projects that contracts and stays point at. */
require_cap('projects.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pid  = (int) ($_POST['id'] ?? 0);
    $name = mb_substr(post_str('name'), 0, 150);
    if ($name === '') {
        flash(t('proj.need_name'), 'error');
    } else {
        $loc    = mb_substr(post_str('location'), 0, 190) ?: null;
        $notes  = post_str('notes') ?: null;
        $active = isset($_POST['active']) ? 1 : 0;
        if ($pid) {
            db_run("UPDATE projects SET name=?, location=?, notes=?, active=? WHERE id=?", [$name, $loc, $notes, $active, $pid]);
        } else {
            db_insert("INSERT INTO projects (name, location, notes, active, created_at) VALUES (?,?,?,1,NOW())", [$name, $loc, $notes]);
        }
        flash(t('ui.saved'));
    }
    redirect('projects.php');
}

$rows = db_all("SELECT p.*, (SELECT COUNT(*) FROM contracts ct WHERE ct.project_id = p.id) AS contracts,
                       (SELECT COUNT(*) FROM stays s WHERE s.project_id = p.id AND s.status = 'confirmed' AND s.check_out >= CURDATE()) AS upcoming
                  FROM projects p ORDER BY p.active DESC, p.name");
$edit = isset($_GET['edit']) ? db_row("SELECT * FROM projects WHERE id = ?", [(int) $_GET['edit']]) : null;

layout_header(t('nav.projects'), 'projects');
page_head(t('nav.projects'), t('proj.sub'));
?>
<div class="grid-main">
<div class="card card-flush">
  <?php if (!$rows): ?><div class="empty"><?= e(t('proj.empty')) ?></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th><?= e(t('proj.name')) ?></th><th><?= e(t('proj.location')) ?></th><th><?= e(t('nav.reservations')) ?></th><th><?= e(t('stay.tab_upcoming')) ?></th><th></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): ?>
      <tr>
        <td><strong><?= e($p['name']) ?></strong><?= $p['notes'] ? '<div class="small text-muted">' . e($p['notes']) . '</div>' : '' ?></td>
        <td><?= e($p['location'] ?? '—') ?></td>
        <td><?= (int) $p['contracts'] ?></td>
        <td><?= (int) $p['upcoming'] ?></td>
        <td><?= $p['active'] ? '<span class="pill green dot">' . e(t('ui.active')) . '</span>' : '<span class="pill gray dot">' . e(t('ui.inactive')) . '</span>' ?></td>
        <td><a class="btn btn-sm" href="?edit=<?= (int) $p['id'] ?>"><?= e(t('ui.edit')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<form method="post" class="card action-card">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <h2><?= e($edit ? t('proj.edit') : t('proj.add')) ?></h2>
  <div class="field"><span class="lbl"><?= e(t('proj.name')) ?> *</span><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
  <div class="field"><span class="lbl"><?= e(t('proj.location')) ?></span><input type="text" name="location" value="<?= e($edit['location'] ?? '') ?>"></div>
  <div class="field"><span class="lbl"><?= e(t('client.notes')) ?></span><textarea name="notes" rows="2"><?= e($edit['notes'] ?? '') ?></textarea></div>
  <?php if ($edit): ?><label class="check"><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> <?= e(t('ui.active')) ?></label><?php endif; ?>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
    <?php if ($edit): ?><a class="btn btn-ghost" href="projects.php"><?= e(t('ui.cancel')) ?></a><?php endif; ?>
  </div>
</form>
</div>
<?php layout_footer();
