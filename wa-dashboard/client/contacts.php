<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid     = (int) $CLIENT['id'];
$country = (string) $CLIENT['default_country'];

/* ── CSV export ── */
if (($_GET['export'] ?? '') === '1') {
    $rows = db_all("SELECT phone_e164,name,opt_in_status,attributes,created_at FROM contacts WHERE client_id=? ORDER BY id DESC", [$cid]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="contacts-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo "phone,name,opt_in_status,created_at\n";
    foreach ($rows as $r) {
        echo csv_cell((string) $r['phone_e164']) . ','
           . csv_cell((string) $r['name']) . ','
           . csv_cell((string) $r['opt_in_status']) . ','
           . csv_cell((string) $r['created_at']) . "\n";
    }
    exit;
}

/* ── AJAX actions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'delete_contact') {
        $ok = db_run("DELETE FROM contacts WHERE id=? AND client_id=?", [(int) ($_POST['id'] ?? 0), $cid]) > 0;
        json_out(['ok' => $ok]);
    }
    if ($a === 'toggle_optout') {
        $row = db_row("SELECT opt_in_status FROM contacts WHERE id=? AND client_id=?", [(int) ($_POST['id'] ?? 0), $cid]);
        if (!$row) json_out(['ok' => false]);
        $newStatus = $row['opt_in_status'] === 'out' ? 'in' : 'out';
        db_run("UPDATE contacts SET opt_in_status=?, opted_out_at=" . ($newStatus === 'out' ? 'NOW()' : 'NULL') . " WHERE id=? AND client_id=?",
            [$newStatus, (int) $_POST['id'], $cid]);
        json_out(['ok' => true, 'status' => $newStatus]);
    }
    json_out(['ok' => false, 'error' => 'unknown action']);
}

/* ── Add single contact ── */
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_contact') {
    verify_csrf();
    $phone = normalize_phone((string) ($_POST['phone'] ?? ''), $country);
    $name  = trim((string) ($_POST['name'] ?? ''));
    if ($phone === '') {
        $err = 'Enter a valid phone number (with country code).';
    } else {
        try {
            db_run(
                "INSERT INTO contacts (client_id,phone_e164,name,opt_in_status,source,created_at)
                 VALUES (?,?,?, 'in','manual',NOW())
                 ON DUPLICATE KEY UPDATE name=VALUES(name)",
                [$cid, $phone, $name]
            );
            flash('Contact saved.');
            redirect('contacts.php');
        } catch (Throwable $ex) {
            $err = 'Could not save contact.';
        }
    }
}

/* ── CSV import ── */
$importSummary = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    verify_csrf();
    if (empty($_FILES['csv']['tmp_name']) || ($_FILES['csv']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $err = 'Please choose a CSV file to upload.';
    } else {
        $imported = 0; $updated = 0; $skipped = 0; $total = 0;
        // Chosen country overrides the account default for this import batch.
        $importCountry = preg_replace('/\D+/', '', (string) ($_POST['import_country'] ?? '')) ?: $country;
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if ($fh) {
            $header = fgetcsv($fh);
            // Normalize header names → find phone & name columns; rest = attributes
            $cols = array_map(fn($h) => strtolower(trim((string) $h)), $header ?: []);
            $phoneIdx = null; $nameIdx = null;
            foreach ($cols as $i => $h) {
                if ($phoneIdx === null && in_array($h, ['phone','number','mobile','msisdn','whatsapp','tel'], true)) $phoneIdx = $i;
                if ($nameIdx === null && in_array($h, ['name','full_name','fullname','contact'], true)) $nameIdx = $i;
            }
            if ($phoneIdx === null) $phoneIdx = 0; // assume first column is the phone

            $stmt = db()->prepare(
                "INSERT INTO contacts (client_id,phone_e164,name,attributes,opt_in_status,source,created_at)
                 VALUES (?,?,?,?, 'in','import',NOW())
                 ON DUPLICATE KEY UPDATE name=VALUES(name), attributes=VALUES(attributes)"
            );

            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) === 1 && trim((string) $row[0]) === '') continue;
                $total++;
                $phone = normalize_phone((string) ($row[$phoneIdx] ?? ''), $importCountry);
                if ($phone === '') { $skipped++; continue; }
                $name = $nameIdx !== null ? trim((string) ($row[$nameIdx] ?? '')) : '';

                // any non phone/name column → attributes
                $attrs = [];
                foreach ($cols as $i => $h) {
                    if ($i === $phoneIdx || $i === $nameIdx) continue;
                    $v = trim((string) ($row[$i] ?? ''));
                    if ($h !== '' && $v !== '') $attrs[$h] = $v;
                }
                $exists = (int) db_val("SELECT COUNT(*) FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $phone]);
                $stmt->execute([$cid, $phone, $name, $attrs ? json_encode($attrs, JSON_UNESCAPED_UNICODE) : null]);
                if ($exists) $updated++; else $imported++;
            }
            fclose($fh);
            $importSummary = compact('total', 'imported', 'updated', 'skipped');
        } else {
            $err = 'Could not read the uploaded file.';
        }
    }
}

/* ── list + search + paging ── */
$q     = trim((string) ($_GET['q'] ?? ''));
$page  = max(1, (int) ($_GET['page'] ?? 1));
$per   = 50;
$off   = ($page - 1) * $per;

$where  = "client_id = ?";
$params = [$cid];
if ($q !== '') {
    $where .= " AND (phone_e164 LIKE ? OR name LIKE ?)";
    $params[] = "%$q%"; $params[] = "%$q%";
}
$totalContacts = (int) db_val("SELECT COUNT(*) FROM contacts WHERE $where", $params);
$list = db_all("SELECT * FROM contacts WHERE $where ORDER BY id DESC LIMIT $per OFFSET $off", $params);
$pages = (int) max(1, ceil($totalContacts / $per));

$actions = '<a class="btn btn-ghost btn-sm" href="contacts.php?export=1">Export CSV</a>'
         . '<button class="btn btn-ghost btn-sm" onclick="document.getElementById(\'m-import\').classList.add(\'open\')">Import CSV</button>'
         . '<button class="btn btn-primary btn-sm" onclick="document.getElementById(\'m-add\').classList.add(\'open\')">+ Add Contact</button>';

client_header('Contacts', 'contacts', $CLIENT);
page_head('Contacts', $actions);

if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif;
if ($importSummary): ?>
  <div class="alert success">Import done — <?= (int) $importSummary['imported'] ?> added, <?= (int) $importSummary['updated'] ?> updated, <?= (int) $importSummary['skipped'] ?> skipped (invalid numbers) of <?= (int) $importSummary['total'] ?> rows.</div>
<?php endif; ?>

<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <form method="get" style="display:flex;gap:8px;max-width:360px;width:100%">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or number…">
      <button class="btn btn-ghost btn-sm" type="submit">Search</button>
    </form>
    <span class="text-muted" style="font-size:12.5px"><?= number_format($totalContacts) ?> contact<?= $totalContacts === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Phone</th><th>Name</th><th>Status</th><th>Source</th><th>Added</th><th></th></tr></thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="6"><div class="empty">No contacts<?= $q ? ' match your search' : ' yet — add one or import a CSV' ?>.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($list as $c): ?>
        <tr id="c-<?= (int) $c['id'] ?>">
          <td class="mono">+<?= e((string) $c['phone_e164']) ?></td>
          <td><?= e((string) $c['name']) ?: '<span class="text-muted">—</span>' ?></td>
          <td>
            <?php if ($c['opt_in_status'] === 'out'): ?>
              <span class="pill red">Opted out</span>
            <?php else: ?>
              <span class="pill green">Opted in</span>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= e((string) $c['source']) ?></td>
          <td class="text-muted"><?= e(date('d M Y', strtotime((string) $c['created_at']))) ?></td>
          <td style="text-align:right;white-space:nowrap">
            <button class="btn-link" onclick="toggleOptout(<?= (int) $c['id'] ?>,this)"><?= $c['opt_in_status'] === 'out' ? 'Opt in' : 'Opt out' ?></button>
            <button class="icon-btn" title="Delete" onclick="delContact(<?= (int) $c['id'] ?>)">&#x2715;</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div style="padding:14px 18px;display:flex;gap:6px;justify-content:center">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a class="btn <?= $p === $page ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="contacts.php?page=<?= $p ?><?= $q ? '&q=' . urlencode($q) : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Add contact modal -->
<div class="modal-back" id="m-add">
  <form class="modal" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_contact">
    <h2>Add Contact</h2>
    <div class="field"><span class="lbl">Phone Number</span><input type="text" name="phone" placeholder="+20 100 123 4567" required><div class="hint">Include country code. Local numbers use the account default (<?= $country ? '+' . e($country) : 'none set' ?>).</div></div>
    <div class="field"><span class="lbl">Name <span class="text-muted">(optional)</span></span><input type="text" name="name"></div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('m-add').classList.remove('open')">Cancel</button>
      <button type="submit" class="btn btn-primary">Save Contact</button>
    </div>
  </form>
</div>

<!-- Import CSV modal -->
<div class="modal-back" id="m-import">
  <form class="modal" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import_csv">
    <h2>Import Contacts (CSV)</h2>
    <p class="text-muted" style="font-size:12.5px;margin-bottom:14px">
      First row = header. A <strong>phone</strong> column is required; <strong>name</strong> is optional.
      Any extra columns are stored as personalization variables. Local numbers are normalized with the account country code<?= $country ? ' (+' . e($country) . ')' : '' ?>.
    </p>
    <div class="field"><span class="lbl">CSV File</span><input type="file" name="csv" accept=".csv,text/csv" required></div>
    <div class="field"><span class="lbl">Country <span class="text-muted">(local numbers get this code; empty = account default<?= $country ? ' +' . e($country) : '' ?>)</span></span>
      <?= function_exists('country_picker_html')
            ? country_picker_html('import_country', '')
            : '<select name="import_country"><option value="">Auto-detect</option><option value="20">Egypt (+20)</option><option value="966">Saudi Arabia (+966)</option><option value="971">UAE (+971)</option></select>' ?>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('m-import').classList.remove('open')">Cancel</button>
      <button type="submit" class="btn btn-primary">Import</button>
    </div>
  </form>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
async function post(data){
  const fd = new FormData(); fd.append('ajax','1'); fd.append('csrf_token',CSRF);
  for (const k in data) fd.append(k, data[k]);
  const r = await fetch('', { method:'POST', body: fd }); return r.json();
}
async function delContact(id){
  if(!confirm('Delete this contact?')) return;
  const d = await post({action:'delete_contact', id});
  if(d.ok){ document.getElementById('c-'+id)?.remove(); showToast('Contact deleted.'); }
  else showToast('Could not delete.', true);
}
async function toggleOptout(id, btn){
  const d = await post({action:'toggle_optout', id});
  if(d.ok){ location.reload(); } else showToast('Could not update.', true);
}
</script>

<?php layout_footer(); ?>
