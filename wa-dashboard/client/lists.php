<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];

/* ── AJAX member add/remove/search ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    verify_csrf();
    $a  = (string) ($_POST['action'] ?? '');
    $lid = (int) ($_POST['list_id'] ?? 0);
    // ensure list belongs to client
    $list = db_row("SELECT * FROM contact_lists WHERE id=? AND client_id=?", [$lid, $cid]);
    if (!$list) json_out(['ok' => false, 'error' => 'list not found']);

    if ($a === 'add_member') {
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $owns = (int) db_val("SELECT COUNT(*) FROM contacts WHERE id=? AND client_id=?", [$contactId, $cid]);
        if (!$owns) json_out(['ok' => false]);
        db_run("INSERT IGNORE INTO contact_list_members (list_id,contact_id) VALUES (?,?)", [$lid, $contactId]);
        json_out(['ok' => true]);
    }
    if ($a === 'remove_member') {
        db_run("DELETE FROM contact_list_members WHERE list_id=? AND contact_id=?", [$lid, (int) ($_POST['contact_id'] ?? 0)]);
        json_out(['ok' => true]);
    }
    if ($a === 'add_all_optin') {
        $n = db_run(
            "INSERT IGNORE INTO contact_list_members (list_id, contact_id)
             SELECT ?, id FROM contacts WHERE client_id=? AND opt_in_status='in'",
            [$lid, $cid]
        );
        json_out(['ok' => true, 'added' => $n]);
    }
    json_out(['ok' => false]);
}

/* ── create / delete list ── */
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_list') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') $err = 'Enter a list name.';
    else {
        $newId = db_insert("INSERT INTO contact_lists (client_id,name,created_at) VALUES (?,?,NOW())", [$cid, $name]);
        flash('List created.');
        redirect('lists.php?id=' . $newId);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_list') {
    verify_csrf();
    db_run("DELETE FROM contact_lists WHERE id=? AND client_id=?", [(int) ($_POST['list_id'] ?? 0), $cid]);
    flash('List deleted.');
    redirect('lists.php');
}

$detailId = (int) ($_GET['id'] ?? 0);

/* ══ DETAIL VIEW ══ */
if ($detailId) {
    $list = db_row("SELECT * FROM contact_lists WHERE id=? AND client_id=?", [$detailId, $cid]);
    if (!$list) { http_response_code(404); exit('List not found.'); }

    $members = db_all(
        "SELECT c.* FROM contact_list_members m JOIN contacts c ON c.id = m.contact_id
          WHERE m.list_id=? ORDER BY c.id DESC LIMIT 500", [$detailId]
    );
    $memberCount = (int) db_val("SELECT COUNT(*) FROM contact_list_members WHERE list_id=?", [$detailId]);

    client_header('List · ' . $list['name'], 'lists', $CLIENT);
    ?>
    <div class="page-head">
      <h1><?= e($list['name']) ?> <span class="text-muted" style="font-size:15px">· <?= $memberCount ?> members</span></h1>
      <div class="page-actions">
        <a class="btn btn-ghost btn-sm" href="lists.php">← All lists</a>
        <button class="btn btn-dark btn-sm" onclick="addAll()">Add all opted-in contacts</button>
      </div>
    </div>

    <div class="card">
      <h2>Add contacts</h2>
      <input type="search" id="search" placeholder="Search contacts to add…" oninput="searchContacts(this.value)">
      <div id="search-results" class="mt10"></div>
    </div>

    <div class="card card-flush">
      <div style="padding:16px 22px"><h2 style="border:0;padding:0;margin:0">Members</h2></div>
      <div class="table-wrap">
        <table class="data" id="members">
          <thead><tr><th>Phone</th><th>Name</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php if (!$members): ?><tr id="empty-row"><td colspan="4"><div class="empty">No members yet. Add contacts above.</div></td></tr><?php endif; ?>
          <?php foreach ($members as $m): ?>
            <tr id="m-<?= (int) $m['id'] ?>">
              <td class="mono">+<?= e((string) $m['phone_e164']) ?></td>
              <td><?= e((string) $m['name']) ?: '<span class="text-muted">—</span>' ?></td>
              <td><?= $m['opt_in_status'] === 'out' ? '<span class="pill red">Opted out</span>' : '<span class="pill green">Opted in</span>' ?></td>
              <td style="text-align:right"><button class="btn-link" onclick="removeMember(<?= (int) $m['id'] ?>)">Remove</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <script>
    const CSRF = <?= json_encode(csrf_token()) ?>;
    const LIST_ID = <?= $detailId ?>;
    async function post(data){
      const fd=new FormData(); fd.append('ajax','1'); fd.append('csrf_token',CSRF); fd.append('list_id',LIST_ID);
      for(const k in data) fd.append(k,data[k]);
      const r=await fetch('lists.php',{method:'POST',body:fd}); return r.json();
    }
    let searchTimer;
    function searchContacts(q){
      clearTimeout(searchTimer);
      searchTimer=setTimeout(async()=>{
        if(q.trim().length<1){ document.getElementById('search-results').innerHTML=''; return; }
        const r=await fetch('contact_search.php?q='+encodeURIComponent(q)+'&exclude_list='+LIST_ID);
        const rows=await r.json();
        const box=document.getElementById('search-results');
        if(!rows.length){ box.innerHTML='<p class="text-muted" style="font-size:12.5px">No matching contacts not already in this list.</p>'; return; }
        box.innerHTML='<div class="table-wrap"><table class="data"><tbody>'+rows.map(c=>
          `<tr id="sr-${c.id}"><td class="mono">+${c.phone}</td><td>${c.name||'—'}</td><td style="text-align:right"><button class="btn btn-ghost btn-sm" onclick="addMember(${c.id})">Add</button></td></tr>`
        ).join('')+'</tbody></table></div>';
      },250);
    }
    async function addMember(id){
      const d=await post({action:'add_member',contact_id:id});
      if(d.ok){ document.getElementById('sr-'+id)?.remove(); showToast('Added.'); setTimeout(()=>location.reload(),500);}
      else showToast('Could not add.',true);
    }
    async function removeMember(id){
      const d=await post({action:'remove_member',contact_id:id});
      if(d.ok){ document.getElementById('m-'+id)?.remove(); showToast('Removed.'); }
      else showToast('Could not remove.',true);
    }
    async function addAll(){
      if(!confirm('Add ALL opted-in contacts to this list?')) return;
      const d=await post({action:'add_all_optin'});
      if(d.ok){ showToast(d.added+' contact(s) added.'); setTimeout(()=>location.reload(),700);}
      else showToast('Could not add.',true);
    }
    </script>
    <?php
    layout_footer();
    exit;
}

/* ══ INDEX VIEW ══ */
$lists = db_all(
    "SELECT l.*, (SELECT COUNT(*) FROM contact_list_members m WHERE m.list_id=l.id) AS members
       FROM contact_lists l WHERE l.client_id=? ORDER BY l.id DESC", [$cid]
);

$actions = '<button class="btn btn-primary btn-sm" onclick="document.getElementById(\'m-new\').classList.add(\'open\')">+ New List</button>';
client_header('Lists', 'lists', $CLIENT);
page_head('Contact Lists', $actions);
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>List</th><th>Members</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php if (!$lists): ?><tr><td colspan="4"><div class="empty">No lists yet. Create a list to target campaigns.</div></td></tr><?php endif; ?>
      <?php foreach ($lists as $l): ?>
        <tr>
          <td><strong><?= e((string) $l['name']) ?></strong></td>
          <td><?= (int) $l['members'] ?></td>
          <td class="text-muted"><?= e(date('d M Y', strtotime((string) $l['created_at']))) ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="btn btn-ghost btn-sm" href="lists.php?id=<?= (int) $l['id'] ?>">Manage</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this list? Contacts are not deleted.')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_list"><input type="hidden" name="list_id" value="<?= (int) $l['id'] ?>">
              <button class="icon-btn" title="Delete list">&#x2715;</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back" id="m-new">
  <form class="modal" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_list">
    <h2>New List</h2>
    <div class="field"><span class="lbl">List Name</span><input type="text" name="name" placeholder="e.g. VIP customers" required></div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('m-new').classList.remove('open')">Cancel</button>
      <button type="submit" class="btn btn-primary">Create</button>
    </div>
  </form>
</div>

<?php layout_footer(); ?>
