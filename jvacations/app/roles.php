<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Admin → Roles & permissions. One tick per role per action; changes apply on
 * the next page load for everyone with that role. Admin always has everything.
 */
require_role('admin');

$roles = array_values(array_filter(ROLES, fn($r) => $r !== 'admin'));
$caps  = array_keys(cap_defaults());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['do'] ?? '') === 'reset') {
        save_setting('role_caps', '');
        flash(t('roles.reset_done'));
        redirect('roles.php');
    }
    $map = [];
    foreach ($roles as $r) {
        $map[$r] = array_values(array_intersect($caps, (array) ($_POST['cap'][$r] ?? [])));
    }
    save_setting('role_caps', json_encode($map));
    flash(t('ui.saved'));
    redirect('roles.php');
}

$current = role_caps();
$counts  = [];
foreach (db_all("SELECT role, COUNT(*) AS n FROM users WHERE status = 'active' GROUP BY role") as $r) $counts[$r['role']] = (int) $r['n'];

layout_header(t('nav.roles'), 'roles');
page_head(t('nav.roles'), t('roles.sub'));
?>
<form method="post" class="card card-flush">
  <?= csrf_field() ?>
  <div class="table-wrap"><table class="data roles-matrix">
    <thead><tr>
      <th><?= e(t('roles.action')) ?></th>
      <?php foreach ($roles as $r): ?><th class="center"><span class="pill role-<?= e($r) ?>"><?= e(t('role.' . $r)) ?></span>
        <div class="small text-muted"><?= e(t('roles.n_users', ['n' => (string) ($counts[$r] ?? 0)])) ?></div></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($caps as $cap): ?>
      <tr>
        <td><strong><?= e(t('cap.' . $cap)) ?></strong><div class="small text-muted"><?= e(t('cap_help.' . $cap)) ?></div></td>
        <?php foreach ($roles as $r):
            $on  = in_array($cap, $current[$r] ?? [], true);
            $def = in_array($r, cap_defaults()[$cap], true); ?>
          <td class="center"><label class="cap-cell <?= $def ? 'is-default' : '' ?>" title="<?= e($def ? t('roles.default') : '') ?>">
            <input type="checkbox" name="cap[<?= e($r) ?>][]" value="<?= e($cap) ?>" <?= $on ? 'checked' : '' ?>></label></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="card-foot">
    <span class="small text-muted"><?= e(t('roles.legend')) ?></span>
    <div class="btn-row">
      <button class="btn" type="submit" name="do" value="reset" formnovalidate data-confirm="<?= e(t('roles.reset_q')) ?>"><?= e(t('roles.reset')) ?></button>
      <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
    </div>
  </div>
</form>
<?php layout_footer();
