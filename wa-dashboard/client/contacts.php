<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/contacts.php';

$cid     = (int) $CLIENT['id'];
$country = (string) $CLIENT['default_country'];

/* ── CSV export ── */
if (($_GET['export'] ?? '') === '1') {
    // Export what is on screen, not the whole book: someone who filtered to a tag and then
    // pressed Export means that tag.
    [$xw, $xp] = contact_selection_where($cid, ['scope' => 'filter'] + $_GET);
    $rows = db_all("SELECT phone_e164,name,opt_in_status,tags,created_at FROM contacts WHERE {$xw} ORDER BY id DESC", $xp);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="contacts-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo "phone,name,opt_in_status,tags,created_at\n";
    foreach ($rows as $r) {
        echo csv_cell((string) $r['phone_e164']) . ','
           . csv_cell((string) $r['name']) . ','
           . csv_cell((string) $r['opt_in_status']) . ','
           . csv_cell((string) $r['tags']) . ','
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
    /* ── Bulk actions ──
       Every one takes either the ticked ids or the current filter, resolved by the shared
       selection helper, so "the 12 I picked" and "all 3,400 tagged hot" are the same code
       path and neither can reach another client's contacts. */
    if (in_array($a, ['bulk_tag', 'bulk_untag', 'bulk_list', 'bulk_optout', 'bulk_delete'], true)) {
        $ids = contact_selection_ids($cid, $_POST);
        if (!$ids) json_out(['ok' => false, 'error' => 'Nothing selected.']);

        if ($a === 'bulk_tag' || $a === 'bulk_untag') {
            $tag = trim((string) ($_POST['tag'] ?? ''));
            if ($tag === '') json_out(['ok' => false, 'error' => 'Enter a tag.']);
            if (str_contains($tag, ',')) json_out(['ok' => false, 'error' => 'A tag cannot contain a comma.']);
            $n = $a === 'bulk_tag'
                ? contacts_add_tag($cid, $ids, $tag)
                : contacts_remove_tag($cid, $ids, $tag);
            json_out(['ok' => true, 'n' => $n, 'total' => count($ids),
                      'msg' => $a === 'bulk_tag'
                          ? ($n . ' contact(s) tagged “' . $tag . '”.' . ($n < count($ids) ? ' The rest already had it.' : ''))
                          : ($n . ' contact(s) no longer tagged “' . $tag . '”.')]);
        }

        if ($a === 'bulk_list') {
            // An existing list, or a new one named here. Reusing a name adds to that list
            // rather than making a second one with the same name.
            $listId = (int) ($_POST['list_id'] ?? 0);
            $newName = trim((string) ($_POST['new_list'] ?? ''));
            if ($listId <= 0 && $newName !== '') $listId = contact_list_ensure($cid, $newName);
            if ($listId <= 0) json_out(['ok' => false, 'error' => 'Choose a list or name a new one.']);
            $added = contacts_add_to_list($cid, $ids, $listId);
            $name  = (string) db_val("SELECT name FROM contact_lists WHERE id=? AND client_id=?", [$listId, $cid]);
            json_out(['ok' => true, 'n' => $added, 'list_id' => $listId,
                      'msg' => $added . ' added to “' . $name . '”.'
                             . ($added < count($ids) ? ' The rest were already in it.' : '')]);
        }

        if ($a === 'bulk_optout') {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $n = db_run("UPDATE contacts SET opt_in_status='out', opted_out_at=NOW()
                          WHERE client_id=? AND opt_in_status<>'out' AND id IN ($in)",
                array_merge([$cid], $ids));
            json_out(['ok' => true, 'n' => $n, 'msg' => $n . ' contact(s) opted out.']);
        }

        if ($a === 'bulk_delete') {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $n = db_run("DELETE FROM contacts WHERE client_id=? AND id IN ($in)", array_merge([$cid], $ids));
            json_out(['ok' => true, 'n' => $n, 'msg' => $n . ' contact(s) deleted.']);
        }
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
                "INSERT INTO contacts (client_id,phone_e164,name,tags,opt_in_status,source,created_at)
                 VALUES (?,?,?,?, 'in','manual',NOW())
                 ON DUPLICATE KEY UPDATE name=VALUES(name), tags=VALUES(tags)",
                [$cid, $phone, $name, tags_join(tags_split((string) ($_POST['tags'] ?? '')))]
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

$tagQ = trim((string) ($_GET['tag'] ?? ''));
$optQ = in_array(($_GET['opt'] ?? ''), ['in', 'out'], true) ? (string) $_GET['opt'] : '';

// The same helper the bulk actions use, so what you see and what "select everything
// matching" acts on can never be two different sets.
[$where, $params] = contact_selection_where($cid, ['scope' => 'filter', 'q' => $q, 'tag' => $tagQ, 'opt' => $optQ]);
$allTags = contact_tags($cid);
$lists   = db_all("SELECT id, name FROM contact_lists WHERE client_id=? ORDER BY name", [$cid]);
$filtered = $q !== '' || $tagQ !== '' || $optQ !== '';
$qs = fn(array $over = []) => 'contacts.php?' . http_build_query(array_filter(
        ['q' => $q, 'tag' => $tagQ, 'opt' => $optQ] + $over, fn($v) => $v !== '' && $v !== null));
$totalContacts = (int) db_val("SELECT COUNT(*) FROM contacts WHERE $where", $params);
$list = db_all("SELECT * FROM contacts WHERE $where ORDER BY id DESC LIMIT $per OFFSET $off", $params);
$pages = (int) max(1, ceil($totalContacts / $per));

$actions = '<a class="btn btn-ghost btn-sm" href="' . e($qs(['export' => '1'])) . '">Export CSV</a>'
         . '<button class="btn btn-ghost btn-sm" onclick="document.getElementById(\'m-import\').classList.add(\'open\')">Import CSV</button>'
         . '<button class="btn btn-primary btn-sm" onclick="document.getElementById(\'m-add\').classList.add(\'open\')">+ Add Contact</button>';

client_header('Contacts', 'contacts', $CLIENT);
page_head('Contacts', $actions);

if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif;
if ($importSummary): ?>
  <div class="alert success">Import done — <?= (int) $importSummary['imported'] ?> added, <?= (int) $importSummary['updated'] ?> updated, <?= (int) $importSummary['skipped'] ?> skipped (invalid numbers) of <?= (int) $importSummary['total'] ?> rows.</div>
<?php endif; ?>

<div class="card card-flush">
  <div class="c-toolbar">
    <form method="get" class="c-search">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or number…">
      <?php if ($tagQ !== ''): ?><input type="hidden" name="tag" value="<?= e($tagQ) ?>"><?php endif; ?>
      <?php if ($optQ !== ''): ?><input type="hidden" name="opt" value="<?= e($optQ) ?>"><?php endif; ?>
      <button class="btn btn-ghost btn-sm" type="submit">Search</button>
    </form>
    <span class="text-muted" style="font-size:12.5px">
      <?= number_format($totalContacts) ?> contact<?= $totalContacts === 1 ? '' : 's' ?><?= $filtered ? ' matching' : '' ?>
    </span>
  </div>

  <?php /* Tags as filters. Clicking one is how you get from "everyone" to "everyone tagged
           hot", which is where the bulk actions become worth having. */ ?>
  <?php if ($allTags || $filtered): ?>
    <div class="c-tags">
      <a class="tag-chip filt <?= $tagQ === '' ? 'on' : '' ?>" href="<?= e($qs(['tag' => null, 'page' => null])) ?>">All</a>
      <?php foreach (array_slice($allTags, 0, 24) as $t): ?>
        <a class="tag-chip filt <?= strcasecmp($tagQ, $t['tag']) === 0 ? 'on' : '' ?>"
           href="<?= e($qs(['tag' => $t['tag'], 'page' => null])) ?>"><?= e($t['tag']) ?> <span><?= (int) $t['n'] ?></span></a>
      <?php endforeach; ?>
      <span class="c-sep"></span>
      <a class="tag-chip filt <?= $optQ === 'in' ? 'on' : '' ?>" href="<?= e($qs(['opt' => $optQ === 'in' ? null : 'in', 'page' => null])) ?>">Opted in</a>
      <a class="tag-chip filt <?= $optQ === 'out' ? 'on' : '' ?>" href="<?= e($qs(['opt' => $optQ === 'out' ? null : 'out', 'page' => null])) ?>">Opted out</a>
    </div>
  <?php endif; ?>

  <?php /* Appears only when something is picked; nothing here is reachable by accident. */ ?>
  <div class="bulkbar" id="bulkbar" hidden>
    <strong id="bulk-count">0 selected</strong>
    <span class="bulk-all" id="bulk-all" hidden>
      · <button type="button" class="btn-link" onclick="selectAllMatching()">Select all <?= number_format($totalContacts) ?> matching</button>
    </span>
    <span class="bulk-all" id="bulk-clear" hidden>
      · <button type="button" class="btn-link" onclick="clearSelection()">Clear</button>
    </span>
    <span class="grow"></span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="openBulk('list')">Add to list</button>
    <button type="button" class="btn btn-ghost btn-sm" onclick="openBulk('tag')">Add tag</button>
    <button type="button" class="btn btn-ghost btn-sm" onclick="openBulk('untag')">Remove tag</button>
    <button type="button" class="btn btn-ghost btn-sm" onclick="bulkOptout()">Opt out</button>
    <button type="button" class="btn btn-ghost btn-sm danger" onclick="bulkDelete()">Delete</button>
  </div>

  <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th class="ck"><input type="checkbox" id="ck-all" onchange="togglePage(this.checked)" aria-label="Select every contact on this page"></th>
        <th>Phone</th><th>Name</th><th>Tags</th><th>Status</th><th>Source</th><th>Added</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="8"><div class="empty">No contacts<?= $filtered ? ' match that' : ' yet — add one or import a CSV' ?>.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($list as $c): ?>
        <tr id="c-<?= (int) $c['id'] ?>">
          <td class="ck"><input type="checkbox" class="ck-row" value="<?= (int) $c['id'] ?>" onchange="onPick()" aria-label="Select <?= e((string) ($c['name'] ?: $c['phone_e164'])) ?>"></td>
          <td class="mono">+<?= e((string) $c['phone_e164']) ?></td>
          <td><?= e((string) $c['name']) ?: '<span class="text-muted">—</span>' ?></td>
          <td class="tags-cell"><?= tags_html((string) $c['tags']) ?></td>
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
        <a class="btn <?= $p === $page ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="<?= e($qs(['page' => $p])) ?>"><?= $p ?></a>
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
    <div class="field"><span class="lbl">Tags <span class="text-muted">(optional)</span></span>
      <input type="text" name="tags" list="all-tags" placeholder="hot, new-cairo">
      <div class="hint">Separate with commas. The same tags your automations apply.</div></div>
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

<!-- Every tag this client uses, offered to every tag input on the page. -->
<datalist id="all-tags">
  <?php foreach ($allTags as $t): ?><option value="<?= e($t['tag']) ?>"><?php endforeach; ?>
</datalist>

<!-- Bulk action modal: one shape for all three, so there is one place to get right. -->
<div class="modal-back" id="m-bulk">
  <form class="modal" onsubmit="event.preventDefault();runBulk()">
    <h2 id="bulk-title">Add to list</h2>
    <p class="text-muted" style="font-size:12.5px;margin-bottom:14px" id="bulk-scope"></p>

    <div id="bulk-list-fields">
      <div class="field"><span class="lbl">List</span>
        <select id="bulk-list-id" onchange="document.getElementById('bulk-new-list').disabled = this.value !== ''">
          <option value="">— create a new list —</option>
          <?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>"><?= e((string) $l['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><span class="lbl">New list name</span>
        <input type="text" id="bulk-new-list" placeholder="e.g. Hot leads — September">
        <div class="hint">A name you already use adds to that list rather than making a second one.</div>
      </div>
    </div>

    <div id="bulk-tag-fields" hidden>
      <div class="field"><span class="lbl">Tag</span>
        <input type="text" id="bulk-tag" list="all-tags" placeholder="hot">
        <div class="hint">One tag at a time. Case doesn't matter — “Hot” and “hot” are the same tag.</div>
      </div>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('m-bulk').classList.remove('open')">Cancel</button>
      <button type="submit" class="btn btn-primary" id="bulk-go">Apply</button>
    </div>
  </form>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
/* The filter the page is currently showing. "Select all matching" sends this instead of
   thousands of ids, so the browser never has to carry the whole set. */
const FILTER = <?= json_encode(['q' => $q, 'tag' => $tagQ, 'opt' => $optQ]) ?>;
const TOTAL  = <?= (int) $totalContacts ?>;

async function post(data){
  const fd = new FormData(); fd.append('ajax','1'); fd.append('csrf_token',CSRF);
  for (const k in data) {
    if (Array.isArray(data[k])) data[k].forEach(v => fd.append(k + '[]', v));
    else fd.append(k, data[k]);
  }
  const r = await fetch('', { method:'POST', body: fd }); return r.json();
}

/* ── selection ──────────────────────────────────────────────────────────────────
   Two states, and the difference matters: the ticked boxes on this page, or everything
   the current filter matches. The second is the only way to act on more than fifty. */
let SCOPE = 'ids';
const picked = () => [...document.querySelectorAll('.ck-row:checked')].map(c => c.value);

function onPick(){
  const n = picked().length, rows = document.querySelectorAll('.ck-row').length;
  if (SCOPE === 'filter') SCOPE = 'ids';          // touching a box drops "all matching"
  const bar = document.getElementById('bulkbar');
  bar.hidden = n === 0;
  document.getElementById('bulk-count').textContent = n + ' selected';
  // Offer the whole filtered set only when it is bigger than the page.
  document.getElementById('bulk-all').hidden = !(n === rows && n > 0 && TOTAL > rows);
  document.getElementById('bulk-clear').hidden = n === 0;
  const all = document.getElementById('ck-all');
  all.checked = n > 0 && n === rows;
  all.indeterminate = n > 0 && n < rows;
}
function togglePage(on){
  document.querySelectorAll('.ck-row').forEach(c => c.checked = on);
  SCOPE = 'ids'; onPick();
}
function selectAllMatching(){
  SCOPE = 'filter';
  document.getElementById('bulk-count').textContent = 'All ' + TOTAL.toLocaleString() + ' matching selected';
  document.getElementById('bulk-all').hidden = true;
  document.getElementById('bulk-clear').hidden = false;
}
function clearSelection(){
  document.querySelectorAll('.ck-row').forEach(c => c.checked = false);
  SCOPE = 'ids'; onPick();
}
/** What the current selection is, in words — shown before anything is applied to it. */
function scopeText(){
  return SCOPE === 'filter'
    ? 'All ' + TOTAL.toLocaleString() + ' contacts matching the current filter.'
    : picked().length + ' selected contact(s).';
}
function selectionPayload(){
  return SCOPE === 'filter' ? {scope:'filter', ...FILTER} : {scope:'ids', ids: picked()};
}

/* ── bulk modal ── */
let BULK = 'list';
function openBulk(kind){
  BULK = kind;
  document.getElementById('bulk-title').textContent =
    kind === 'list' ? 'Add to list' : kind === 'tag' ? 'Add a tag' : 'Remove a tag';
  document.getElementById('bulk-scope').textContent = scopeText();
  document.getElementById('bulk-list-fields').hidden = kind !== 'list';
  document.getElementById('bulk-tag-fields').hidden  = kind === 'list';
  document.getElementById('m-bulk').classList.add('open');
  setTimeout(() => document.getElementById(kind === 'list' ? 'bulk-list-id' : 'bulk-tag').focus(), 50);
}
async function runBulk(){
  const go = document.getElementById('bulk-go');
  go.disabled = true;
  const body = selectionPayload();
  if (BULK === 'list') {
    body.action = 'bulk_list';
    body.list_id = document.getElementById('bulk-list-id').value;
    body.new_list = document.getElementById('bulk-new-list').value;
  } else {
    body.action = BULK === 'tag' ? 'bulk_tag' : 'bulk_untag';
    body.tag = document.getElementById('bulk-tag').value;
  }
  const d = await post(body);
  go.disabled = false;
  if (!d.ok) { showToast(d.error || 'Could not do that.', true); return; }
  document.getElementById('m-bulk').classList.remove('open');
  showToast(d.msg || 'Done.');
  setTimeout(() => location.reload(), 700);
}
async function bulkOptout(){
  if(!confirm(scopeText() + '\n\nOpt them out? They will be excluded from every future campaign.')) return;
  const d = await post({action:'bulk_optout', ...selectionPayload()});
  if(d.ok){ showToast(d.msg); setTimeout(()=>location.reload(), 700); } else showToast(d.error||'Could not update.', true);
}
async function bulkDelete(){
  if(!confirm(scopeText() + '\n\nDelete them permanently? This cannot be undone.')) return;
  const d = await post({action:'bulk_delete', ...selectionPayload()});
  if(d.ok){ showToast(d.msg); setTimeout(()=>location.reload(), 700); } else showToast(d.error||'Could not delete.', true);
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
