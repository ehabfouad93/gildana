<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';

/**
 * Keeping the lead data safe, for managers: what salespeople are asking for (to delete a lead, or
 * to add one), the recycle bin, every import (and undoing one), and a log of who exported leads or
 * opened hidden phone numbers — with the two switches that decide how strict the account is.
 */
$cid = (int) $CLIENT['id'];
$me  = (int) ($PERM_USER['id'] ?? 0);
if (!is_client_admin()) {
    http_response_code(403);
    client_header('Requests, bin & imports', 'crm', $CLIENT);
    echo '<div class="card" style="max-width:560px"><h2 style="margin-top:0">For managers</h2><p class="text-muted">Only an Admin can see this page.</p></div>';
    layout_footer(); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'approve' || $a === 'refuse') {
        $r = crm_request_decide($CLIENT, (int) ($_POST['request'] ?? 0), $a === 'approve', $me, (string) ($_POST['answer'] ?? ''));
        flash($r['ok'] ? ($a === 'approve' ? 'Approved. They have been told.' : 'Refused. They have been told.') : $r['error'], $r['ok'] ? 'success' : 'error');
        redirect('crm_manage.php#requests');
    }
    if ($a === 'restore') {
        $n = 0;
        foreach ((array) ($_POST['ids'] ?? [$_POST['id'] ?? 0]) as $x) if (crm_restore_lead($CLIENT, (int) $x, $me)) $n++;
        flash($n . ' lead' . ($n === 1 ? '' : 's') . ' brought back into the pipeline.');
        redirect('crm_manage.php#bin');
    }
    if ($a === 'undo_import') {
        $r = crm_import_undo($CLIENT, (int) ($_POST['import'] ?? 0), $me);
        flash($r['ok'] ? 'Import undone: ' . $r['removed'] . ' lead' . ($r['removed'] === 1 ? '' : 's') . ' removed'
            . ($r['kept'] ? ', ' . $r['kept'] . ' kept because someone had already worked on them' : '') . '.' : $r['error'], $r['ok'] ? 'success' : 'error');
        redirect('crm_manage.php#imports');
    }
    if ($a === 'settings') {
        crm_settings_set($cid, ['hide_phones' => !empty($_POST['hide_phones']) ? 1 : 0, 'approve_new_leads' => !empty($_POST['approve_new_leads']) ? 1 : 0]);
        flash('Saved.');
        redirect('crm_manage.php#settings');
    }
}

$pending = crm_requests($cid, 'pending');
$decided = crm_requests($cid, 'decided');
$bin = crm_bin($cid);
$imports = crm_imports($cid);
$s = crm_settings($cid);
$stageMap = crm_stage_map($cid);
$log = [];
try {
    $log = db_all("SELECT a.*, COALESCE(NULLIF(u.name,''), u.email) AS who, c.name AS lead_name, c.code
                     FROM crm_audit a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN contacts c ON c.id=a.contact_id
                    WHERE a.client_id=? ORDER BY a.id DESC LIMIT 200", [$cid]);
} catch (Throwable $e) {}
$phonesBy = [];
foreach ($log as $l) if ($l['action'] === 'reveal' && strtotime((string) $l['created_at']) > time() - 7 * 86400) $phonesBy[(string) $l['who']] = ($phonesBy[(string) $l['who']] ?? 0) + 1;

client_header('Requests, bin & imports', 'crm', $CLIENT);
page_head('Requests, bin & imports', '<span class="setup-jump"><a href="#requests">Requests</a> · <a href="#bin">Recycle bin</a> · <a href="#imports">Imports</a> · <a href="#log">Log</a> · <a href="#settings">Settings</a></span>');
$leadName = fn(array $r) => (string) ($r['lead_name'] ?? $r['name'] ?? '') !== '' ? (string) ($r['lead_name'] ?? $r['name']) : ('#' . ($r['code'] ?? ''));
?>
<div class="card card-flush" id="requests">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Requests <?= $pending ? '<span class="pill gold">' . count($pending) . ' waiting</span>' : '' ?></h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Salespeople ask to delete a lead<?= (int) $s['approve_new_leads'] ? ', or to add one' : '' ?>. Whoever asked is told what you decided.</p></div>
  <?php if (!$pending): ?><div class="empty" style="padding:0 18px 16px">Nothing waiting.</div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Asked</th><th>By</th><th>What</th><th>Why</th><th></th></tr></thead><tbody>
    <?php foreach ($pending as $r): $p = json_decode((string) ($r['payload'] ?? ''), true) ?: [];
      $dup = $r['kind'] === 'create' && !empty($p['phone']) ? db_row("SELECT id, owner_user_id FROM contacts WHERE client_id=? AND phone_e164=? AND stage_id IS NOT NULL", [$cid, normalize_phone((string) $p['phone'], (string) ($CLIENT['default_country'] ?? ''))]) : null; ?>
      <tr><td class="text-muted"><?= e(date('j M, H:i', strtotime((string) $r['created_at']))) ?></td>
        <td><?= e((string) $r['by_name']) ?></td>
        <td><?php if ($r['kind'] === 'delete'): ?>Delete <a href="crm_lead.php?id=<?= (int) $r['contact_id'] ?>"><?= e($leadName($r)) ?></a>
            <?php else: ?>Add <strong><?= e((string) ($p['name'] ?? '') ?: 'a lead') ?></strong> <span class="text-muted"><?= e((string) ($p['phone'] ?? '')) ?></span>
              <?php if ($dup): ?><span class="pill red">Already a lead — <?= e(crm_user_name($dup['owner_user_id'] !== null ? (int) $dup['owner_user_id'] : null)) ?></span><?php endif; ?><?php endif; ?></td>
        <td><?= e((string) ($r['reason'] ?? '')) ?></td>
        <td><form method="post" class="req-act"><?= csrf_field() ?><input type="hidden" name="request" value="<?= (int) $r['id'] ?>">
            <input name="answer" maxlength="255" placeholder="Note to them (optional)" aria-label="Note">
            <button class="btn btn-primary btn-sm" name="action" value="approve" <?= $dup ? 'disabled title="That number is already a lead"' : '' ?>>Approve</button>
            <button class="btn btn-ghost btn-sm" name="action" value="refuse">Refuse</button></form></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <?php if ($decided): ?>
  <details style="padding:0 18px 14px"><summary class="btn-link">Decided recently (<?= count($decided) ?>)</summary>
    <table class="data mt10"><tbody>
    <?php foreach (array_slice($decided, 0, 50) as $r): $p = json_decode((string) ($r['payload'] ?? ''), true) ?: []; ?>
      <tr><td class="text-muted"><?= e(date('j M', strtotime((string) $r['decided_at']))) ?></td><td><?= e((string) $r['by_name']) ?></td>
        <td><?= $r['kind'] === 'delete' ? 'Delete ' . e($leadName($r)) : 'Add ' . e((string) ($p['name'] ?? '')) ?></td>
        <td><span class="pill <?= $r['status'] === 'approved' ? 'green' : 'gray' ?>"><?= $r['status'] === 'approved' ? 'Approved' : 'Refused' ?></span> by <?= e((string) $r['decided_name']) ?></td></tr>
    <?php endforeach; ?></tbody></table></details>
  <?php endif; ?>
</div>

<div class="card card-flush" id="bin">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Recycle bin</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Deleted leads stay here for <?= CRM_BIN_DAYS ?> days, out of every list and report. Bring one back and it returns to
      the stage it was in, with its owner and history. After <?= CRM_BIN_DAYS ?> days it leaves the bin; the contact and its conversation stay in Contacts.</p></div>
  <?php if (!$bin): ?><div class="empty" style="padding:0 18px 16px">The bin is empty.</div><?php else: ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="restore">
  <div class="table-wrap"><table class="data">
    <thead><tr><th style="width:28px"><input type="checkbox" onclick="this.closest('table').querySelectorAll('.bin-pick').forEach(c => c.checked = this.checked)" aria-label="Select all"></th>
      <th>Lead</th><th>Was in</th><th>Owner</th><th>Deleted</th><th class="num">Days left</th></tr></thead><tbody>
    <?php foreach ($bin as $b): $left = CRM_BIN_DAYS - (int) floor((time() - strtotime((string) $b['deleted_at'])) / 86400); ?>
      <tr><td><input type="checkbox" class="bin-pick" name="ids[]" value="<?= (int) $b['id'] ?>" aria-label="Select"></td>
        <td><a href="crm_lead.php?id=<?= (int) $b['id'] ?>"><strong><?= e((string) ($b['name'] ?: '#' . $b['code'])) ?></strong></a>
          <span class="crm-sub-line"><span class="crm-code">#<?= e((string) $b['code']) ?></span></span></td>
        <td><?= e((string) ($stageMap[(int) $b['deleted_stage_id']]['name'] ?? '—')) ?></td>
        <td><?= e((string) ($b['owner_name'] ?? 'Unassigned')) ?></td>
        <td class="text-muted"><?= e(date('j M, H:i', strtotime((string) $b['deleted_at']))) ?> by <?= e((string) ($b['deleted_by_name'] ?? 'someone')) ?></td>
        <td class="num"><?= max(0, $left) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <div style="padding:12px 18px"><button class="btn btn-primary btn-sm">Bring the ticked ones back</button></div>
  </form>
  <?php endif; ?>
</div>

<div class="card card-flush" id="imports">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Imports</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Every spreadsheet brought in. <strong>Undo</strong> takes out the leads an import added — except any someone has
      already called, messaged or moved, which are kept. Details it changed on leads you already had are not rolled back.</p></div>
  <?php if (!$imports): ?><div class="empty" style="padding:0 18px 16px">No imports yet. <a href="crm_import.php">Import leads</a></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>When</th><th>By</th><th>File</th><th class="num">Rows</th><th class="num">New leads</th><th class="num">Updated</th><th class="num">Skipped</th><th></th></tr></thead><tbody>
    <?php foreach ($imports as $i): ?>
      <tr><td class="text-muted"><?= e(date('j M Y, H:i', strtotime((string) $i['created_at']))) ?></td>
        <td><?= e((string) ($i['by_name'] ?? '')) ?></td>
        <td><?= e((string) ($i['filename'] ?? '')) ?><?= $i['mode'] === 'update' ? ' <span class="pill blue">Update</span>' : '' ?></td>
        <td class="num"><?= (int) $i['total'] ?></td><td class="num"><?= (int) $i['leads'] ?></td><td class="num"><?= (int) $i['updated'] ?></td><td class="num"><?= (int) $i['skipped'] ?></td>
        <td style="text-align:right"><?php if ($i['undone_at']): ?><span class="text-muted" style="font-size:12px">Undone <?= e(date('j M', strtotime((string) $i['undone_at']))) ?> — <?= e((string) $i['undo_note']) ?></span>
          <?php elseif ((int) $i['undoable'] > 0): ?><form method="post" onsubmit="return confirm('Undo this import? Its <?= (int) $i['undoable'] ?> new leads are taken out (except any someone already worked on).')">
            <?= csrf_field() ?><input type="hidden" name="action" value="undo_import"><input type="hidden" name="import" value="<?= (int) $i['id'] ?>">
            <button class="btn btn-ghost btn-sm">Undo</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>

<div class="card card-flush" id="log">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Who took what</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Exports, hidden phone numbers opened, deletions and undone imports — the last 200.
      <?php if ($phonesBy): ?>Numbers opened in the last 7 days: <?= e(implode(', ', array_map(fn($w, $n) => $w . ' ' . $n, array_keys($phonesBy), $phonesBy))) ?>.<?php endif; ?></p></div>
  <?php if (!$log): ?><div class="empty" style="padding:0 18px 16px">Nothing yet.</div><?php else: ?>
  <div class="table-wrap"><table class="data"><tbody>
    <?php foreach ($log as $l): ?>
      <tr><td class="text-muted" style="white-space:nowrap"><?= e(date('j M, H:i', strtotime((string) $l['created_at']))) ?></td>
        <td><?= e((string) ($l['who'] ?? 'Automatically')) ?></td>
        <td><?= e(crm_audit_words($l)) ?><?php if ($l['contact_id']): ?> — <a href="crm_lead.php?id=<?= (int) $l['contact_id'] ?>"><?= e((string) ($l['lead_name'] ?: '#' . $l['code'])) ?></a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>

<div class="card" id="settings" style="max-width:720px">
  <h2>How strict</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
    <label class="mod-all" style="align-items:flex-start"><input type="checkbox" name="hide_phones" value="1" <?= (int) $s['hide_phones'] ? 'checked' : '' ?>>
      <span><strong>Hide phone numbers from Sales.</strong> They see <code>+20 ••• ••• 840</code> and still call or WhatsApp from the buttons; each number
        they open is recorded above. Tick <em>See phone numbers</em> on the <a href="team.php">Team</a> page for anyone who needs them.</span></label>
    <label class="mod-all" style="align-items:flex-start"><input type="checkbox" name="approve_new_leads" value="1" <?= (int) $s['approve_new_leads'] ? 'checked' : '' ?>>
      <span><strong>New leads added by Sales need approval.</strong> Stops someone adding a number by hand to claim it. Leads from ads, forms and
        messages are not affected.</span></label>
    <p class="text-muted" style="font-size:12.5px">Who may export to Excel and who may delete without asking is ticked per person on the <a href="team.php">Team</a> page.</p>
    <button class="btn btn-primary btn-sm">Save</button></form>
</div>
<?php layout_footer();
