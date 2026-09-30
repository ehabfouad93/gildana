<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm_materials.php';

/**
 * The team's material, in four drawers: marketing, sales, legal, financial. An Admin adds and
 * removes; everyone with the CRM browses, previews and downloads.
 */
$cid = (int) $CLIENT['id'];
$isAdmin = is_client_admin();
$cats = crm_material_categories();
$projects = crm_project_names($cid);
$ready = (bool) db_val("SHOW TABLES LIKE 'crm_materials'");
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    if (!$isAdmin) { flash('Only an Admin can change the material.', 'error'); redirect('crm_materials.php'); }
    if ($a === 'add') {
        // Several files at once share the category, project and description; each gets its own title.
        $files = [];
        if (!empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
            foreach ($_FILES['files']['name'] as $i => $n) {
                if ((int) $_FILES['files']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                $files[] = ['name' => $n, 'tmp_name' => $_FILES['files']['tmp_name'][$i], 'error' => $_FILES['files']['error'][$i], 'size' => $_FILES['files']['size'][$i]];
            }
        }
        $added = 0;
        if ($files) {
            foreach ($files as $f) {
                $r = crm_material_add($cid, ['title' => count($files) === 1 ? ($_POST['title'] ?? '') : ''] + $_POST, $f, (int) $ME['id']);
                if ($r['ok']) $added++; else $err = $f['name'] . ': ' . $r['error'];
            }
        } else {
            $r = crm_material_add($cid, $_POST, null, (int) $ME['id']);
            if ($r['ok']) $added++; else $err = $r['error'];
        }
        if ($added && $err === '') { flash($added === 1 ? 'Added.' : $added . ' files added.'); redirect('crm_materials.php?cat=' . urlencode((string) $_POST['category'])); }
        if ($added) flash($added . ' added.');
    }
    if ($a === 'delete') {
        crm_material_delete($cid, (int) ($_POST['id'] ?? 0));
        flash('Removed.');
        redirect('crm_materials.php' . (!empty($_POST['cat']) ? '?cat=' . urlencode((string) $_POST['cat']) : ''));
    }
}

$f = ['cat' => (string) ($_GET['cat'] ?? ''), 'project' => (int) ($_GET['project'] ?? 0), 'q' => trim((string) ($_GET['q'] ?? ''))];
$list = $ready ? crm_materials($cid, $f) : [];
$counts = $ready ? array_column(db_all("SELECT category, COUNT(*) n FROM crm_materials WHERE client_id=? GROUP BY category", [$cid]), 'n', 'category') : [];
$icons = ['image' => '🖼', 'video' => '🎬', 'audio' => '🎧', 'pdf' => '📕', 'doc' => '📄', 'sheet' => '📊', 'slides' => '📽', 'file' => '📦', 'link' => '🔗'];

client_header('Materials', 'crm', $CLIENT);
page_head('Materials', $isAdmin && $ready ? '<button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById(\'mat-dlg\').showModal()">+ Add material</button>' : '');
if (!$ready): ?><div class="card"><p>Run the latest update (migration 057) to use materials.</p></div><?php layout_footer(); exit; endif;
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<p class="text-muted" style="margin-top:-6px">Brochures, price lists, payment plans, contracts — everything the team needs to send a buyer. <?= $isAdmin ? 'Add files or links; the whole team can see them.' : 'Open or download what you need.' ?></p>

<div class="mat-cats" role="tablist">
  <a class="mat-cat <?= $f['cat'] === '' ? 'on' : '' ?>" href="crm_materials.php"><span>All</span><b><?= array_sum($counts) ?></b></a>
  <?php foreach ($cats as $k => $l): ?>
    <a class="mat-cat mat-<?= $k ?> <?= $f['cat'] === $k ? 'on' : '' ?>" href="?cat=<?= $k ?>"><span><?= e($l) ?></span><b><?= (int) ($counts[$k] ?? 0) ?></b></a>
  <?php endforeach; ?>
</div>

<form class="mat-filter" method="get">
  <?php if ($f['cat'] !== ''): ?><input type="hidden" name="cat" value="<?= e($f['cat']) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search the material" aria-label="Search the material">
  <?php if ($projects): ?><select name="project" onchange="this.form.submit()"><option value="0">All projects</option>
    <?php foreach ($projects as $pid => $pn): ?><option value="<?= (int) $pid ?>" <?= $f['project'] === (int) $pid ? 'selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn btn-ghost btn-sm">Search</button>
</form>

<?php if (!$list): ?>
  <div class="card mat-empty"><p><?= $f['q'] !== '' || $f['cat'] !== '' || $f['project'] ? 'Nothing here matches.' : 'No material yet.' ?></p>
    <?php if ($isAdmin && !$counts): ?><button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('mat-dlg').showModal()">+ Add the first file</button><?php endif; ?></div>
<?php else: ?>
<div class="mat-grid">
  <?php foreach ($list as $m): $kind = crm_material_kind($m); $url = $kind === 'link' ? (string) $m['link_url'] : 'material_file.php?id=' . (int) $m['id']; ?>
    <div class="mat-card mat-<?= e((string) $m['category']) ?>">
      <a class="mat-thumb" href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer">
        <?php if ($kind === 'image'): ?><img src="<?= e($url) ?>" alt="" loading="lazy"><?php else: ?><span aria-hidden="true"><?= $icons[$kind] ?></span><?php endif; ?>
      </a>
      <div class="mat-body">
        <span class="mat-tag"><?= e($cats[$m['category']] ?? '') ?></span>
        <strong class="mat-title"><?= e((string) $m['title']) ?></strong>
        <?php if (!empty($m['description'])): ?><span class="mat-desc"><?= e((string) $m['description']) ?></span><?php endif; ?>
        <span class="mat-meta"><?= e(implode(' · ', array_filter([(string) ($m['project_name'] ?? ''), $kind === 'link' ? 'Link' : strtoupper(pathinfo((string) $m['file_name'], PATHINFO_EXTENSION)),
            crm_material_size($m['size_bytes'] !== null ? (int) $m['size_bytes'] : null), date('j M Y', strtotime((string) $m['created_at']))]))) ?></span>
        <div class="mat-acts">
          <a class="btn btn-ghost btn-sm" href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= $kind === 'link' ? 'Open link' : 'Open' ?></a>
          <?php if ($kind !== 'link'): ?><a class="btn btn-ghost btn-sm" href="<?= e($url) ?>&download=1">Download</a><?php endif; ?>
          <?php if ($isAdmin): ?>
          <form method="post" onsubmit="return confirm('Remove this for everyone?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="cat" value="<?= e($f['cat']) ?>">
            <button class="btn-link" style="color:var(--danger)">Remove</button></form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<dialog class="lead-dlg" id="mat-dlg" aria-labelledby="mat-title" <?= $err ? 'open' : '' ?>>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <h2 id="mat-title" style="margin-top:0">Add material</h2>
    <div class="field"><span class="lbl">Category</span>
      <div class="lead-quick"><?php foreach ($cats as $k => $l): ?><label class="act-kind"><input type="radio" name="category" value="<?= $k ?>" required <?= ($f['cat'] ?: 'marketing') === $k ? 'checked' : '' ?>><span><?= e($l) ?></span></label><?php endforeach; ?></div></div>
    <div class="field"><span class="lbl">Files <span class="text-muted">(pictures, PDF, Word, Excel, PowerPoint, video, audio, ZIP — up to 30 MB each)</span></span>
      <input type="file" name="files[]" multiple accept="<?= e(implode(',', array_map(fn($x) => '.' . $x, array_keys(crm_material_types())))) ?>"></div>
    <div class="field"><span class="lbl">…or a link <span class="text-muted">(a video, a Drive folder)</span></span><input type="text" inputmode="url" name="link_url" maxlength="500" placeholder="https://…"></div>
    <div class="grid2">
      <div class="field"><span class="lbl">Title <span class="text-muted">(one file or a link)</span></span><input name="title" maxlength="160" placeholder="Porto Said brochure 2026"></div>
      <div class="field"><span class="lbl">Project</span><select name="project_id"><option value="0">—</option>
        <?php foreach ($projects as $pid => $pn): ?><option value="<?= (int) $pid ?>"><?= e($pn) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><span class="lbl">Description <span class="text-muted">(optional)</span></span><input name="description" maxlength="500" placeholder="Prices valid until the end of the month"></div>
    <div class="dlg-btns"><button class="btn btn-primary">Add</button><button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php layout_footer();
