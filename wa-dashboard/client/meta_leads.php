<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/meta_leads.php';

/**
 * Lead forms from Facebook and Instagram, feeding the CRM.
 *
 * Connect once; choose which Pages and forms; say who their leads go to. The log at the bottom
 * lists every lead Meta reported and what became of it, because "my lead never arrived" is the
 * question this page will be opened to answer.
 */
$cid = (int) $CLIENT['id'];
$me  = (int) ($PERM_USER['id'] ?? 0);
$isAdmin = is_client_admin();
$stages  = crm_stages($cid);
$people  = crm_assignable_users($cid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$isAdmin) { flash('Only an Admin can change lead form settings.', 'error'); redirect('meta_leads.php'); }
    $a = (string) ($_POST['action'] ?? '');

    if ($a === 'connect') {
        if (!meta_configured()) { flash('Facebook is not set up on this platform yet.', 'error'); redirect('meta_leads.php'); }
        header('Location: ' . meta_auth_url($cid, $me));
        exit;
    }

    $page = db_row("SELECT * FROM meta_pages WHERE id=? AND client_id=?", [(int) ($_POST['page'] ?? 0), $cid]);
    if ($page && $a === 'subscribe') {
        $on = !empty($_POST['on']);
        $r  = meta_page_subscribe($page, $on);
        flash($r['ok'] ? ($on ? 'Leads from ' . $page['name'] . ' will now arrive in your CRM.' : 'Stopped leads from ' . $page['name'] . '.')
                       : 'Facebook refused: ' . $r['error'], $r['ok'] ? 'success' : 'error');
    }
    if ($page && $a === 'save_page') {
        $rule = (string) ($_POST['owner_rule'] ?? 'auto');
        if (!in_array($rule, ['auto', 'none'], true) && !in_array((int) $rule, array_map('intval', array_column($people, 'id')), true)) $rule = 'auto';
        db_run("UPDATE meta_pages SET all_forms=?, owner_rule=?, stage_id=? WHERE id=?",
               [!empty($_POST['all_forms']) ? 1 : 0, $rule, (int) ($_POST['stage_id'] ?? 0) ?: null, (int) $page['id']]);
        flash('Saved.');
    }
    if ($page && $a === 'refresh') {
        $r = meta_sync_forms($page);
        flash($r['ok'] ? 'Forms refreshed.' : 'Facebook refused: ' . $r['error'], $r['ok'] ? 'success' : 'error');
    }
    if ($page && $a === 'disconnect') {
        if ((int) $page['subscribed']) meta_page_subscribe($page, false);
        db_run("DELETE FROM meta_forms WHERE client_id=? AND page_id=?", [$cid, (string) $page['page_id']]);
        db_run("DELETE FROM meta_pages WHERE id=?", [(int) $page['id']]);
        flash('Disconnected ' . $page['name'] . '. Leads already in your CRM stay there.');
    }
    if ($a === 'form') {
        db_run("UPDATE meta_forms SET enabled=? WHERE id=? AND client_id=?", [!empty($_POST['on']) ? 1 : 0, (int) ($_POST['form'] ?? 0), $cid]);
        flash('Saved.');
    }
    if ($a === 'check_now') {
        // Force this account's forms to be re-read now, rather than on the next scheduled pass.
        db_run("UPDATE meta_forms SET last_polled_at=NULL WHERE client_id=?", [$cid]);
        $r = meta_poll(0, 200);
        flash($r['imported'] ? $r['imported'] . ' lead(s) found that had not arrived yet.' : 'Checked ' . $r['forms'] . ' form(s). Nothing was missing.');
    }
    redirect('meta_leads.php');
}

$pages = db_all("SELECT * FROM meta_pages WHERE client_id=? ORDER BY subscribed DESC, name", [$cid]);
$log   = db_all("SELECT l.*, f.name AS form_name, c.name AS contact_name FROM meta_lead_log l
                   LEFT JOIN meta_forms f ON f.client_id=l.client_id AND f.form_id=l.form_id
                   LEFT JOIN contacts c ON c.id=l.contact_id
                  WHERE l.client_id=? ORDER BY l.id DESC LIMIT 30", [$cid]);

client_header('Lead forms', 'crm', $CLIENT);
page_head('Facebook & Instagram lead forms', '<a class="btn btn-ghost btn-sm" href="crm.php">&larr; CRM</a>');
?>
<?php if (!meta_configured()): ?>
  <div class="card" style="max-width:640px"><h2>Not available yet</h2>
    <p class="text-muted">Connecting lead forms needs a Facebook app set up once for the whole platform.
      Ask <?= e(BRAND_PARENT) ?> to switch it on.</p></div>
<?php else: ?>

<div class="card">
  <div class="row-between">
    <div><h2 style="margin:0;border:0;padding:0">Your Facebook Pages</h2>
      <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Every lead from a form on a Page that is switched on
        becomes a CRM lead within seconds, and goes to the salesperson you choose.</p></div>
    <?php if ($isAdmin): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="connect">
      <button class="btn btn-primary"><?= $pages ? 'Connect more Pages' : 'Connect Facebook' ?></button></form>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($pages as $p):
  $forms = db_all("SELECT * FROM meta_forms WHERE client_id=? AND page_id=? ORDER BY name", [$cid, (string) $p['page_id']]); ?>
  <div class="card">
    <div class="row-between" style="flex-wrap:wrap;gap:10px">
      <div><h2 style="margin:0;border:0;padding:0"><?= e((string) $p['name']) ?></h2>
        <span class="pill <?= (int) $p['subscribed'] ? 'green' : 'gray' ?>"><?= (int) $p['subscribed'] ? 'Leads arriving' : 'Off' ?></span></div>
      <?php if ($isAdmin): ?>
      <span style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="subscribe"><input type="hidden" name="page" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="on" value="<?= (int) $p['subscribed'] ? '' : '1' ?>">
          <button class="btn btn-sm <?= (int) $p['subscribed'] ? 'btn-ghost' : 'btn-primary' ?>"><?= (int) $p['subscribed'] ? 'Stop leads' : 'Send leads to my CRM' ?></button></form>
        <?php if ((int) $p['subscribed']): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><input type="hidden" name="page" value="<?= (int) $p['id'] ?>">
          <button class="btn btn-ghost btn-sm">Refresh forms</button></form>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Disconnect this Page? Leads already in your CRM stay.')"><?= csrf_field() ?>
          <input type="hidden" name="action" value="disconnect"><input type="hidden" name="page" value="<?= (int) $p['id'] ?>">
          <button class="btn-link" style="color:var(--danger)">Disconnect</button></form>
      </span>
      <?php endif; ?>
    </div>
    <?php if ($p['last_error']): ?>
      <div class="alert error" style="font-size:12.5px;margin-top:10px">Facebook said: <?= e((string) $p['last_error']) ?>
        — reconnecting usually fixes this; it happens when the person who connected lost admin rights on the Page.</div>
    <?php endif; ?>

    <?php if ((int) $p['subscribed']): ?>
      <form method="post" class="grid2" style="margin-top:12px">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_page"><input type="hidden" name="page" value="<?= (int) $p['id'] ?>">
        <fieldset <?= $isAdmin ? '' : 'disabled' ?> style="display:contents">
        <div class="field"><span class="lbl">Who gets these leads</span><select name="owner_rule">
          <option value="auto" <?= $p['owner_rule'] === 'auto' ? 'selected' : '' ?>>Share out between the sales team</option>
          <option value="none" <?= $p['owner_rule'] === 'none' ? 'selected' : '' ?>>Nobody yet — an Admin assigns</option>
          <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $p['owner_rule'] === (string) $u['id'] ? 'selected' : '' ?>>All to <?= e((string) $u['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><span class="lbl">Stage they start in</span><select name="stage_id">
          <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $p['stage_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <label class="mod-all" style="grid-column:1/-1"><input type="checkbox" name="all_forms" value="1" <?= (int) $p['all_forms'] ? 'checked' : '' ?>>
          Import every form on this Page, including new ones I create later</label>
        <?php if ($isAdmin): ?><div><button class="btn btn-primary btn-sm">Save</button></div><?php endif; ?>
        </fieldset>
      </form>

      <?php if ($forms): ?>
        <div class="table-wrap" style="margin-top:12px"><table class="data">
          <thead><tr><th>Form</th><th>Leads</th><th style="text-align:right">Import</th></tr></thead><tbody>
          <?php foreach ($forms as $fm):
            $n = (int) db_val("SELECT COUNT(*) FROM meta_lead_log WHERE client_id=? AND form_id=? AND outcome='imported'", [$cid, (string) $fm['form_id']]); ?>
            <tr><td><?= e((string) ($fm['name'] ?: $fm['form_id'])) ?></td><td class="num"><?= $n ?></td>
              <td style="text-align:right"><?php if ($isAdmin): ?>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="form">
                  <input type="hidden" name="form" value="<?= (int) $fm['id'] ?>"><input type="hidden" name="on" value="<?= (int) $fm['enabled'] ? '' : '1' ?>">
                  <button class="btn btn-sm <?= (int) $fm['enabled'] ? 'btn-ghost' : 'btn-primary' ?>"><?= (int) $fm['enabled'] ? 'On — switch off' : 'Off — switch on' ?></button></form>
              <?php else: ?><?= (int) $fm['enabled'] ? 'On' : 'Off' ?><?php endif; ?></td></tr>
          <?php endforeach; ?></tbody></table></div>
      <?php else: ?>
        <p class="text-muted" style="font-size:12.5px">No forms found on this Page yet. Create one in Meta Ads Manager, then press Refresh forms.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <div><h2 style="border:0;padding:0;margin:0">Recent leads from forms</h2>
      <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Every lead Facebook reported, and what happened to it.
        Forms are also re-checked every few minutes, so a lead Facebook failed to announce still arrives.</p></div>
    <?php if ($isAdmin && $pages): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="check_now">
      <button class="btn btn-ghost btn-sm">Check for missed leads now</button></form>
    <?php endif; ?>
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>When</th><th>Form</th><th>Lead</th><th>Result</th></tr></thead><tbody>
    <?php if (!$log): ?><tr><td colspan="4"><div class="empty">No leads yet.</div></td></tr><?php endif; ?>
    <?php foreach ($log as $l): ?>
      <tr><td class="text-muted"><?= e(date('j M, H:i', strtotime((string) $l['created_at']))) ?></td>
        <td><?= e((string) ($l['form_name'] ?: $l['form_id'] ?: '—')) ?></td>
        <td><?php if ($l['contact_id']): ?><a href="crm_lead.php?id=<?= (int) $l['contact_id'] ?>"><?= e((string) ($l['contact_name'] ?: 'Open lead')) ?></a><?php else: ?>—<?php endif; ?></td>
        <td><span class="pill <?= ['imported' => 'green', 'skipped' => 'gray', 'error' => 'red'][$l['outcome']] ?? 'gray' ?>"><?= e(ucfirst((string) $l['outcome'])) ?></span>
          <?php if ($l['detail']): ?><span class="text-muted d-block" style="font-size:12px"><?= e((string) $l['detail']) ?></span><?php endif; ?>
          <span class="text-muted" style="font-size:11px">via <?= $l['via'] === 'poll' ? 'check' : 'instant notification' ?></span></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>
<?php layout_footer();
