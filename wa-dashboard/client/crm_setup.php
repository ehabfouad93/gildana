<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';

/**
 * The words the CRM uses, set by the account: its projects, the unit types it sells, and the
 * reasons it loses deals. Kept as short lists so reports can count them — free text would give
 * "villa", "Villa " and "فيلا" as three different answers.
 */
$cid = (int) $CLIENT['id'];
if (!is_client_admin()) {
    http_response_code(403);
    client_header('Projects & lists', 'crm', $CLIENT);
    echo '<div class="card" style="max-width:560px"><h2 style="margin-top:0">For managers</h2><p class="text-muted">Only an Admin can change projects and lists.</p></div>';
    layout_footer(); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'add_project') {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        if ($name === '') flash('Type the project name.', 'error');
        elseif (db_val("SELECT COUNT(*) FROM crm_projects WHERE client_id=? AND LOWER(name)=?", [$cid, mb_strtolower($name)])) flash('That project is already on the list.', 'error');
        else {
            db_insert("INSERT INTO crm_projects (client_id,name,sort,created_at) VALUES (?,?,?,NOW())",
                      [$cid, $name, (int) db_val("SELECT COALESCE(MAX(sort),0)+10 FROM crm_projects WHERE client_id=?", [$cid])]);
            flash('Added ' . $name . '.');
        }
        redirect('crm_setup.php');
    }
    $pid = (int) ($_POST['project'] ?? 0);
    if ($a === 'rename_project' && $pid) {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        if ($name !== '') db_run("UPDATE crm_projects SET name=? WHERE id=? AND client_id=?", [$name, $pid, $cid]);
        if (db_has_column('crm_projects', 'address')) {
            db_run("UPDATE crm_projects SET address=? WHERE id=? AND client_id=?", [mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 255) ?: null, $pid, $cid]);
        }
        redirect('crm_setup.php');
    }
    if ($a === 'toggle_project' && $pid) {
        db_run("UPDATE crm_projects SET active=1-active WHERE id=? AND client_id=?", [$pid, $cid]);
        redirect('crm_setup.php');
    }
    if (in_array($a, ['unit_type', 'lost_reason'], true)) {
        crm_options_save($cid, $a, preg_split('/\r\n|\r|\n/', (string) ($_POST['labels'] ?? '')) ?: []);
        flash('Saved.');
        redirect('crm_setup.php#' . $a);
    }
}

$projects = crm_projects($cid);
$counts = [];
foreach (db_all("SELECT project_id, COUNT(*) n FROM contacts WHERE client_id=? AND project_id IS NOT NULL AND stage_id IS NOT NULL GROUP BY project_id", [$cid]) as $r)
    $counts[(int) $r['project_id']] = (int) $r['n'];

client_header('Projects & lists', 'crm', $CLIENT);
page_head('Projects & lists');
?>
<div class="card card-flush" id="projects">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Projects</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Each lead can be filed under a project — by hand, by the lead form it came from, or
      from a spreadsheet column. Then the pipeline filters by project, the reports compare them, and assignment rules can send each
      project's leads to its own team. A project you stop selling can be hidden; its leads keep it.</p></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Project</th><th class="num">Leads</th><th></th></tr></thead><tbody>
    <?php if (!$projects): ?><tr><td colspan="3"><div class="empty">No projects yet.</div></td></tr><?php endif; ?>
    <?php foreach ($projects as $p): ?>
      <tr><td><form method="post" style="display:flex;gap:6px;align-items:center">
            <?= csrf_field() ?><input type="hidden" name="action" value="rename_project"><input type="hidden" name="project" value="<?= (int) $p['id'] ?>">
            <input name="name" value="<?= e((string) $p['name']) ?>" maxlength="120" aria-label="Project name" style="max-width:220px">
            <input name="address" value="<?= e((string) ($p['address'] ?? '')) ?>" maxlength="255" aria-label="Where it is" placeholder="Where visits happen — address or map link" style="max-width:300px">
            <button class="btn btn-ghost btn-sm">Save</button>
            <?php if (!(int) $p['active']): ?><span class="pill gray">Hidden</span><?php endif; ?></form></td>
        <td class="num"><a href="crm.php?view=table&project=<?= (int) $p['id'] ?>"><?= $counts[(int) $p['id']] ?? 0 ?></a></td>
        <td style="text-align:right"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_project"><input type="hidden" name="project" value="<?= (int) $p['id'] ?>">
          <button class="btn-link"><?= (int) $p['active'] ? 'Hide from lists' : 'Show again' ?></button></form></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <form method="post" style="display:flex;gap:8px;padding:14px 18px;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_project">
    <input name="name" maxlength="120" placeholder="Porto Golf Marina" required style="flex:1;min-width:200px" aria-label="New project">
    <button class="btn btn-primary">Add project</button>
  </form>
</div>

<div class="grid2 setup-lists">
  <?php foreach (['unit_type' => ['Unit types', 'What a lead can ask for. Used on the lead page, in forms and imports, and by assignment rules.'],
                  'lost_reason' => ['Lost reasons', 'Asked when a lead is moved to Lost. The reports count them, so you can see why deals are lost — per project.']] as $k => [$t, $d]): ?>
  <div class="card" id="<?= $k ?>">
    <h2><?= $t ?></h2>
    <p class="text-muted" style="font-size:12.5px;margin-top:-4px"><?= $d ?> One per line.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $k ?>">
      <textarea name="labels" rows="9"><?= e(implode("\n", crm_options($cid, $k))) ?></textarea>
      <button class="btn btn-primary btn-sm mt10">Save</button></form>
  </div>
  <?php endforeach; ?>
</div>
<?php layout_footer();
