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
    if ($a === 'substatus') {
        foreach (crm_stages($cid) as $st) {
            if ($st['kind'] === 'lost' || !isset($_POST['sub'][(int) $st['id']])) continue;
            crm_substatuses_save($cid, (int) $st['id'], preg_split('/\r\n|\r|\n/', (string) $_POST['sub'][(int) $st['id']]) ?: []);
        }
        flash('Sub-statuses saved.');
        redirect('crm_setup.php#substatus');
    }
    if ($a === 'general') {
        $cur = strtoupper((string) ($_POST['currency'] ?? 'EGP'));
        $days = (int) ($_POST['fresh_days'] ?? 30);
        crm_settings_set($cid, ['currency' => isset(crm_currencies()[$cur]) ? $cur : 'EGP', 'fresh_days' => max(1, min(365, $days))]);
        flash('Saved.');
        redirect('crm_setup.php#general');
    }
    if ($a === 'field_add' || $a === 'field_save') {
        $label = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 80);
        $type  = (string) ($_POST['type'] ?? 'text');
        $type  = isset(crm_field_types()[$type]) ? $type : 'text';
        $opts  = trim((string) ($_POST['options'] ?? ''));
        if ($label === '') flash('Give the field a name.', 'error');
        elseif ($type === 'list' && $opts === '') flash('A pick-list field needs its choices, one per line.', 'error');
        elseif ($a === 'field_add') {
            db_insert("INSERT INTO crm_fields (client_id,fkey,label,type,options,sort,active,created_at) VALUES (?,?,?,?,?,?,1,NOW())",
                      [$cid, crm_field_key($cid, $label), $label, $type, $type === 'list' ? $opts : null,
                       (int) db_val("SELECT COALESCE(MAX(sort),0)+10 FROM crm_fields WHERE client_id=?", [$cid])]);
            flash('Added ' . $label . '. It shows on every lead, in the filters and in exports.');
        } else {
            // The key stays: renaming a field must not orphan what is already filled in.
            db_run("UPDATE crm_fields SET label=?, type=?, options=? WHERE id=? AND client_id=?",
                   [$label, $type, $type === 'list' ? $opts : null, (int) ($_POST['field'] ?? 0), $cid]);
            flash('Saved ' . $label . '.');
        }
        redirect('crm_setup.php#fields');
    }
    if ($a === 'field_toggle') {
        db_run("UPDATE crm_fields SET active=1-active WHERE id=? AND client_id=?", [(int) ($_POST['field'] ?? 0), $cid]);
        redirect('crm_setup.php#fields');
    }
    if (in_array($a, ['unit_type', 'lost_reason'], true)) {
        crm_options_save($cid, $a, preg_split('/\r\n|\r|\n/', (string) ($_POST['labels'] ?? '')) ?: []);
        flash('Saved.');
        redirect('crm_setup.php#' . $a);
    }
}

$projects = crm_projects($cid);
$stagesAll = crm_stages($cid);
$subs = crm_substatuses($cid);
$fields = crm_fields($cid, false);
$settings = crm_settings($cid);
$counts = [];
foreach (db_all("SELECT project_id, COUNT(*) n FROM contacts WHERE client_id=? AND project_id IS NOT NULL AND stage_id IS NOT NULL GROUP BY project_id", [$cid]) as $r)
    $counts[(int) $r['project_id']] = (int) $r['n'];

client_header('Projects & lists', 'crm', $CLIENT);
page_head('Projects & lists', '<span class="setup-jump"><a href="#projects">Projects</a> · <a href="#substatus">Sub-statuses</a> · <a href="#fields">Your fields</a> · <a href="#unit_type">Lists</a> · <a href="#general">Currency</a></span>');
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

<div class="card" id="substatus">
  <h2>Sub-statuses</h2>
  <p class="text-muted" style="font-size:12.5px;margin-top:-4px">The detail under each stage — what actually happened. Salespeople pick one when
    they log a call or move a lead, and the reports count stage × sub-status, so you can see how many leads are "Contacted — no answer"
    rather than just "Contacted". One per line. <strong>Lost</strong> uses the lost reasons below as its sub-statuses.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="substatus">
    <div class="sub-grid">
      <?php foreach ($stagesAll as $st): if ($st['kind'] === 'lost') continue; ?>
        <div class="field"><span class="lbl"><?= e((string) $st['name']) ?></span>
          <textarea name="sub[<?= (int) $st['id'] ?>]" rows="6" placeholder="One per line"><?= e(implode("\n", $subs[(int) $st['id']] ?? [])) ?></textarea></div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary btn-sm">Save sub-statuses</button></form>
</div>

<div class="card card-flush" id="fields">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Your own fields</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Anything else you record about a lead — nationality, job, broker, preferred floor.
      Each field appears on the lead page, as a column and a filter in the leads list, and in imports and exports. Hiding a field keeps what was filled in.</p></div>
  <?php if ($fields): ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Field</th><th>Type</th><th>Choices</th><th></th></tr></thead><tbody>
    <?php foreach ($fields as $f): ?>
      <tr><td colspan="3"><form method="post" class="field-row">
            <?= csrf_field() ?><input type="hidden" name="action" value="field_save"><input type="hidden" name="field" value="<?= (int) $f['id'] ?>">
            <input name="label" value="<?= e((string) $f['label']) ?>" maxlength="80" aria-label="Field name" required>
            <select name="type" aria-label="Type"><?php foreach (crm_field_types() as $k => $l): ?><option value="<?= $k ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
            <textarea name="options" rows="2" aria-label="Choices, one per line" placeholder="Choices, one per line (pick-list only)"><?= e((string) ($f['options'] ?? '')) ?></textarea>
            <button class="btn btn-ghost btn-sm">Save</button>
            <?php if (!(int) $f['active']): ?><span class="pill gray">Hidden</span><?php endif; ?></form></td>
        <td style="text-align:right"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="field_toggle"><input type="hidden" name="field" value="<?= (int) $f['id'] ?>">
          <button class="btn-link"><?= (int) $f['active'] ? 'Hide' : 'Show again' ?></button></form></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <form method="post" class="field-row" style="padding:14px 18px">
    <?= csrf_field() ?><input type="hidden" name="action" value="field_add">
    <input name="label" maxlength="80" placeholder="Nationality" required aria-label="New field name">
    <select name="type" aria-label="Type" onchange="this.form.options.hidden = this.value !== 'list'"><?php foreach (crm_field_types() as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
    <textarea name="options" rows="2" placeholder="Choices, one per line" hidden aria-label="Choices"></textarea>
    <button class="btn btn-primary btn-sm">Add field</button>
  </form>
</div>

<div class="card" id="general" style="max-width:640px">
  <h2>Money and fresh leads</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="general">
    <div class="grid2">
      <div class="field"><span class="lbl">Currency</span><select name="currency">
        <?php foreach (crm_currencies() as $k => $l): ?><option value="<?= $k ?>" <?= ($settings['currency'] ?? 'EGP') === $k ? 'selected' : '' ?>><?= $k ?> — <?= e($l) ?></option><?php endforeach; ?></select>
        <span class="text-muted" style="font-size:12px">Used for deal values in the CRM, the dashboard and every report.</span></div>
      <div class="field"><span class="lbl">Fresh for (days)</span><input type="number" name="fresh_days" min="1" max="365" value="<?= (int) ($settings['fresh_days'] ?? 30) ?>">
        <span class="text-muted" style="font-size:12px">A lead is <strong>fresh</strong> when it arrives from an ad, a form, a message or is added by hand.
          Imported data is <strong>cold</strong>, and so is a contact you already had for longer than this before it became a lead.
          Someone cold who fills in a form again becomes fresh.</span></div>
    </div>
    <button class="btn btn-primary btn-sm">Save</button></form>
</div>
<?php layout_footer();
