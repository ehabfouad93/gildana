<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/import.php';

/**
 * Bring leads in from a spreadsheet: upload, check the columns, import.
 *
 * Two steps on purpose. Guessing columns from header words is right most of the time, and the
 * times it is wrong — "Number" meaning unit number, a Budget column in thousands — are exactly the
 * ones that fill a pipeline with nonsense. Showing the first rows next to the guess lets someone
 * catch that before a thousand leads land.
 */
$cid = (int) $CLIENT['id'];
$me  = (int) ($PERM_USER['id'] ?? 0);
$stages = crm_stages($cid);
$people = crm_assignable_users($cid);
const CRM_IMPORT_MAX_ROWS = 20000;

/** Where an uploaded sheet waits between step 1 and step 2. Tied to this session and client. */
function crm_import_path(string $token): string
{
    return sys_get_temp_dir() . '/crmimp_' . preg_replace('/[^a-f0-9]/', '', $token);
}

$err = ''; $step = 'upload'; $read = null; $summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');

    if ($a === 'upload') {
        if (empty($_FILES['file']['tmp_name']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $err = 'Choose a file to upload. If it is large, save it as .xlsx or .csv and try again.';
        } else {
            $name = (string) $_FILES['file']['name'];
            $read = import_read((string) $_FILES['file']['tmp_name'], $name);
            if (empty($read['ok'])) {
                $err = (string) $read['error'];
            } elseif (count($read['rows']) > CRM_IMPORT_MAX_ROWS) {
                $err = 'That file has ' . number_format(count($read['rows'])) . ' rows. Split it into files of '
                     . number_format(CRM_IMPORT_MAX_ROWS) . ' or fewer.';
            } else {
                $token = bin2hex(random_bytes(12));
                move_uploaded_file((string) $_FILES['file']['tmp_name'], crm_import_path($token));
                $_SESSION['crm_import'] = ['token' => $token, 'name' => $name, 'client' => $cid];
                $step = 'map';
            }
        }
    }

    if ($a === 'run') {
        $job = $_SESSION['crm_import'] ?? null;
        $path = $job ? crm_import_path((string) $job['token']) : '';
        if (!$job || (int) $job['client'] !== $cid || !is_file($path)) {
            $err = 'That upload has expired. Upload the file again.';
        } else {
            $read = import_read($path, (string) $job['name']);
            $map = [];
            foreach (array_keys(import_fields($cid)) as $field) {
                $v = (string) ($_POST['map'][$field] ?? '');
                if ($v !== '') $map[$field] = (int) $v;
            }
            $mode = ($_POST['mode'] ?? 'add') === 'update' ? 'update' : 'add';
            $match = ($_POST['match'] ?? 'phone') === 'code' ? 'code' : 'phone';
            if ($mode === 'update' && $match === 'code' ? !isset($map['code']) : !isset($map['phone'])) {
                $err = $match === 'code' && $mode === 'update' ? 'Choose which column holds the lead code.' : 'Choose which column holds the phone number.';
                $step = 'map';
            } else {
                // Recorded before it runs, so what it makes can be undone later.
                $importId = 0;
                try {
                    $importId = db_insert("INSERT INTO crm_imports (client_id,user_id,filename,mode,created_at) VALUES (?,?,?,?,NOW())",
                                          [$cid, $me ?? (int) ($PERM_USER['id'] ?? 0), mb_substr((string) $job['name'], 0, 255), $mode]);
                } catch (Throwable $e) {}
                $summary = import_contacts($CLIENT, $read['header'], $read['rows'], $map, [
                    'mode'     => $mode,
                    'match'    => $match,
                    'import_id'=> $importId,
                    'crm'      => true,
                    'owner'    => (string) ($_POST['owner'] ?? 'auto'),
                    'stage_id' => (int) ($_POST['stage_id'] ?? 0) ?: null,
                    'extras'   => !empty($_POST['extras']),
                    'data_type'=> (string) ($_POST['data_type'] ?? 'cold'),
                    'country'  => preg_replace('/\D+/', '', (string) ($_POST['country'] ?? '')) ?: (string) ($CLIENT['default_country'] ?? ''),
                ]);
                if ($importId) db_run("UPDATE crm_imports SET total=?, added=?, updated=?, leads=?, skipped=?, problems=? WHERE id=?",
                    [$summary['total'], $summary['added'], $summary['updated'], $summary['leads'], $summary['skipped'],
                     $summary['problems'] ? mb_substr(implode("\n", $summary['problems']), 0, 60000) : null, $importId]);
                @unlink($path);
                unset($_SESSION['crm_import']);
                $step = 'done';
            }
        }
    }
}

if ($step === 'map' && !$read) {
    $job = $_SESSION['crm_import'] ?? null;
    if ($job) $read = import_read(crm_import_path((string) $job['token']), (string) $job['name']);
}
$guess = $read && !empty($read['ok']) ? import_guess_mapping($read['header'], $cid) : [];

client_header('Import leads', 'crm', $CLIENT);
page_head('Import leads', '<a class="btn btn-ghost btn-sm" href="crm.php">&larr; CRM</a>');
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
  <div class="card" style="max-width:640px">
    <h2>1. Choose a file</h2>
    <p class="text-muted" style="font-size:13px">An Excel file (.xlsx) or a CSV with a header row. Each row is one lead;
      the only column that must be there is a phone number. Names, emails, budgets, stages and owners are picked up
      when the file has them.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload">
      <input type="file" name="file" required accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
      <button class="btn btn-primary mt10">Upload and check columns</button>
    </form>
    <p class="text-muted" style="font-size:12px;margin-top:14px">Tip: format the phone column as <strong>Text</strong> in
      Excel before saving. Otherwise Excel can shorten long numbers, and those rows cannot be imported.</p>
  </div>

<?php elseif ($step === 'map' && $read && !empty($read['ok'])): ?>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="run">
    <h2>2. Add new leads, or update ones you have?</h2>
    <div class="imp-mode">
      <label class="act-kind"><input type="radio" name="mode" value="add" checked onchange="impMode()"><span>Add new leads</span></label>
      <label class="act-kind"><input type="radio" name="mode" value="update" onchange="impMode()"><span>Update leads I already have</span></label>
    </div>
    <p class="text-muted imp-update-note" style="font-size:12.5px" hidden>Each row finds its lead by
      <select name="match" style="width:auto;display:inline-block"><option value="phone">phone number</option><option value="code">lead code (#4DE2F2)</option></select>
      and changes only the columns you match below that have a value — stage, sub-status, owner, value, campaign, your own fields… Rows that match no lead
      are listed, not added. Every change goes into the lead's history.</p>
    <h2>3. Check the columns</h2>
    <p class="text-muted" style="font-size:13px"><?= number_format(count($read['rows'])) ?> rows in
      <strong><?= e((string) ($_SESSION['crm_import']['name'] ?? '')) ?></strong>. We matched the columns below from their
      headings — check them against the first rows before importing.</p>

    <div class="grid2">
      <?php foreach (import_fields($cid) as $field => $fd): ?>
        <div class="field"><span class="lbl"><?= e($fd['label']) ?><?= $field === 'phone' ? ' *' : '' ?></span>
          <select name="map[<?= e($field) ?>]">
            <option value="">— not in this file —</option>
            <?php foreach ($read['header'] as $i => $h): ?>
              <option value="<?= $i ?>" <?= isset($guess[$field]) && $guess[$field] === $i ? 'selected' : '' ?>><?= e($h !== '' ? $h : 'Column ' . ($i + 1)) ?></option>
            <?php endforeach; ?>
          </select></div>
      <?php endforeach; ?>
    </div>
    <label class="mod-all"><input type="checkbox" name="extras" value="1" checked>
      Keep the other columns as details on each lead</label>

    <div class="table-wrap" style="margin:14px 0">
      <table class="data"><thead><tr><?php foreach ($read['header'] as $i => $h): ?><th><?= e($h !== '' ? $h : 'Column ' . ($i + 1)) ?></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach (array_slice($read['rows'], 0, 5) as $r): ?><tr>
          <?php foreach ($read['header'] as $i => $_): ?><td><?= e((string) ($r[$i] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table>
    </div>

    <div class="imp-add-only">
    <h2>4. Where they go</h2>
    <div class="grid2">
      <div class="field"><span class="lbl">Stage (when the file has none)</span><select name="stage_id">
        <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <?php if (!is_sales()): ?>
      <div class="field"><span class="lbl">Owner (when the file has none)</span><select name="owner">
        <option value="auto">Share out between the sales team</option><option value="none">Leave unassigned</option>
        <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>">All to <?= e((string) $u['name']) ?></option><?php endforeach; ?></select></div>
      <?php else: ?>
      <div class="field"><span class="lbl">Owner</span><input value="You" disabled></div>
      <?php endif; ?>
      <div class="field"><span class="lbl">This data is</span><select name="data_type">
        <option value="cold">Cold data — old leads, a list from before</option>
        <option value="fresh">Fresh — new leads from this week's campaign</option></select></div>
      <div class="field"><span class="lbl">Country code for local numbers</span>
        <input name="country" value="<?= e((string) ($CLIENT['default_country'] ?? '')) ?>" inputmode="numeric" placeholder="20"></div>
    </div>
    <p class="text-muted" style="font-size:12.5px">A number that is already in your contacts is updated, not duplicated, and a
      lead that is already in the pipeline keeps its owner. You can undo an import from <?= is_client_admin() ? '<a href="crm_manage.php#imports">Requests, bin &amp; imports</a>' : 'Requests, bin &amp; imports (ask an Admin)' ?>.</p>
    </div>
    <button class="btn btn-primary" id="imp-go">Import <?= number_format(count($read['rows'])) ?> rows</button>
    <a class="btn btn-ghost" href="crm_import.php">Choose a different file</a>
  </form>
  <script>
  function impMode(){
    const upd = document.querySelector('input[name=mode][value=update]').checked;
    document.querySelector('.imp-update-note').hidden = !upd;
    document.querySelector('.imp-add-only').hidden = upd;
    document.getElementById('imp-go').textContent = (upd ? 'Update from ' : 'Import ') + <?= json_encode(number_format(count($read['rows']))) ?> + ' rows';
  }
  </script>

<?php elseif ($step === 'done' && $summary): ?>
  <div class="card" style="max-width:720px">
    <h2>Import finished</h2>
    <div class="stats-row">
      <div class="stat-tile"><span class="lbl">New contacts</span><span class="val"><?= number_format($summary['added']) ?></span></div>
      <div class="stat-tile"><span class="lbl">Updated</span><span class="val"><?= number_format($summary['updated']) ?></span></div>
      <div class="stat-tile"><span class="lbl">New leads</span><span class="val accent"><?= number_format($summary['leads']) ?></span></div>
      <div class="stat-tile"><span class="lbl">Skipped</span><span class="val"><?= number_format($summary['skipped']) ?></span></div>
    </div>
    <?php if ($summary['problems']): ?>
      <h2 style="margin-top:14px">Worth a look</h2>
      <ul style="font-size:13px;padding-inline-start:18px"><?php foreach ($summary['problems'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <a class="btn btn-primary" href="crm.php">Open the CRM</a>
    <a class="btn btn-ghost" href="crm_import.php">Import another file</a>
  </div>
<?php else: ?>
  <div class="card"><p>Start by <a href="crm_import.php">choosing a file</a>.</p></div>
<?php endif; ?>
<?php layout_footer();
