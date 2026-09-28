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

/* ── The form picker talks to this page in JSON ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') !== '') {
    verify_csrf();
    if (!$isAdmin) json_out(['ok' => false, 'error' => 'Only an Admin can change lead form settings.']);
    $page = db_row("SELECT * FROM meta_pages WHERE id=? AND client_id=?", [(int) ($_POST['page'] ?? 0), $cid]);
    $a = (string) $_POST['ajax'];

    if ($a === 'forms') {
        if (!$page) json_out(['ok' => false, 'error' => 'Choose a Page first.']);
        $r = meta_page_forms($page);
        $mine = [];
        foreach (db_all("SELECT form_id, mapping, owner_rule, stage_id, enabled FROM meta_forms WHERE client_id=? AND page_id=?",
                        [$cid, (string) $page['page_id']]) as $f) $mine[$f['form_id']] = $f;
        foreach ($r['forms'] as &$f) {
            $saved = $mine[$f['id']] ?? null;
            $f['connected'] = $saved && (int) $saved['enabled'] && $saved['mapping'] !== null;
            $f['mapping']   = $saved ? json_decode((string) $saved['mapping'], true) : null;
            $f['owner_rule'] = $saved['owner_rule'] ?? null;
            $f['stage_id']   = $saved['stage_id'] ?? null;
        }
        unset($f);
        json_out($r);
    }

    if ($a === 'save_form') {
        if (!$page) json_out(['ok' => false, 'error' => 'Choose a Page first.']);
        $formId = preg_replace('/\D+/', '', (string) ($_POST['form_id'] ?? ''));
        if ($formId === '') json_out(['ok' => false, 'error' => 'Choose a form.']);
        $targets = meta_targets();
        $map = [];
        foreach ((array) ($_POST['map'] ?? []) as $k => $t) {
            $k = mb_strtolower(trim((string) $k));
            if ($k !== '' && isset($targets[(string) $t])) $map[$k] = (string) $t;
        }
        $phones = count(array_filter($map, fn($t) => $t === 'phone'));
        if ($phones !== 1) json_out(['ok' => false, 'error' => $phones ? 'Only one question can be the phone number.'
                                                             : 'Choose which question holds the phone number — a lead cannot be reached without one.']);
        $rule = (string) ($_POST['owner_rule'] ?? 'auto');
        if (!in_array($rule, ['auto', 'none'], true) && !in_array((int) $rule, array_map('intval', array_column($people, 'id')), true)) $rule = 'auto';
        $stage = (int) ($_POST['stage_id'] ?? 0);
        if (!in_array($stage, array_map('intval', array_column($stages, 'id')), true)) $stage = 0;

        // Forms are chosen one by one now: nothing else on this Page imports unless it is picked too.
        db_run("UPDATE meta_pages SET all_forms=0 WHERE id=?", [(int) $page['id']]);
        $page['all_forms'] = 0;
        if (!(int) $page['subscribed']) {
            $r = meta_page_subscribe($page, true);
            if (!$r['ok']) json_out(['ok' => false, 'error' => 'Facebook refused to send this Page\'s leads: ' . meta_explain_error($r['error'])]);
        }
        db_run("INSERT INTO meta_forms (client_id,page_id,form_id,name,enabled,owner_rule,stage_id,mapping,leads_count)
                VALUES (?,?,?,?,1,?,?,?,?)
                ON DUPLICATE KEY UPDATE page_id=VALUES(page_id), name=VALUES(name), enabled=1, owner_rule=VALUES(owner_rule),
                                        stage_id=VALUES(stage_id), mapping=VALUES(mapping), leads_count=COALESCE(VALUES(leads_count), leads_count)",
               [$cid, (string) $page['page_id'], $formId, mb_substr(trim((string) ($_POST['form_name'] ?? '')), 0, 190) ?: $formId,
                $rule, $stage ?: null, json_encode($map, JSON_UNESCAPED_UNICODE),
                ($_POST['leads_count'] ?? '') !== '' ? (int) $_POST['leads_count'] : null]);
        flash('Saved "' . ((string) ($_POST['form_name'] ?? '') ?: 'the form') . '". New leads will arrive by themselves — press Sync leads to bring in the ones already on it.');
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Unknown action.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$isAdmin) { flash('Only an Admin can change lead form settings.', 'error'); redirect('meta_leads.php'); }
    $a = (string) ($_POST['action'] ?? '');

    if ($a === 'connect') {
        if (!meta_configured()) { flash('Facebook is not set up on this platform yet.', 'error'); redirect('meta_leads.php'); }
        header('Location: ' . meta_auth_url($cid, $me));
        exit;
    }

    $form = db_row("SELECT * FROM meta_forms WHERE id=? AND client_id=?", [(int) ($_POST['form'] ?? 0), $cid]);
    if ($form && $a === 'sync') {
        @set_time_limit(300);
        $r = meta_sync_form($form);
        if (!$r['ok'] && !$r['imported']) flash('Facebook refused: ' . $r['error'], 'error');
        else flash($r['imported'] . ' new lead' . ($r['imported'] === 1 ? '' : 's') . ' added to your CRM'
            . ($r['already'] ? ', ' . $r['already'] . ' already there' : '')
            . ($r['skipped'] ? ', ' . $r['skipped'] . ' skipped (see the list below for why)' : '') . '.'
            . ($r['more'] ? ' There are more — press Sync leads again.' : ''));
    }
    if ($form && $a === 'form') {
        db_run("UPDATE meta_forms SET enabled=? WHERE id=?", [!empty($_POST['on']) ? 1 : 0, (int) $form['id']]);
        flash(!empty($_POST['on']) ? 'New leads from ' . $form['name'] . ' will arrive again.' : 'Stopped ' . $form['name'] . '. Leads already in your CRM stay.');
    }
    if ($form && $a === 'remove_form') {
        db_run("DELETE FROM meta_forms WHERE id=?", [(int) $form['id']]);
        flash('Removed ' . $form['name'] . '. Leads already in your CRM stay.');
    }

    $page = db_row("SELECT * FROM meta_pages WHERE id=? AND client_id=?", [(int) ($_POST['page'] ?? 0), $cid]);
    if ($page && $a === 'disconnect') {
        if ((int) $page['subscribed']) meta_page_subscribe($page, false);
        db_run("DELETE FROM meta_forms WHERE client_id=? AND page_id=?", [$cid, (string) $page['page_id']]);
        db_run("DELETE FROM meta_pages WHERE id=?", [(int) $page['id']]);
        flash('Disconnected ' . $page['name'] . '. Leads already in your CRM stay.');
    }
    if ($a === 'check_now') {
        db_run("UPDATE meta_forms SET last_polled_at=NULL WHERE client_id=?", [$cid]);
        $r = meta_poll(0, 200);
        flash($r['imported'] ? $r['imported'] . ' lead(s) found that had not arrived yet.' : 'Checked ' . $r['forms'] . ' form(s). Nothing was missing.');
    }
    redirect('meta_leads.php');
}

$pages = db_all("SELECT * FROM meta_pages WHERE client_id=? ORDER BY name", [$cid]);
$pageName = array_column($pages, 'name', 'page_id');
$pagesById = array_column($pages, 'id', 'page_id');
$forms = db_all("SELECT f.*, (SELECT COUNT(*) FROM meta_lead_log l WHERE l.client_id=f.client_id AND l.form_id=f.form_id AND l.outcome='imported') AS imported
                   FROM meta_forms f JOIN meta_pages p ON p.client_id=f.client_id AND p.page_id=f.page_id AND p.subscribed=1
                  WHERE f.client_id=? AND (f.mapping IS NOT NULL OR f.enabled=1)
                  ORDER BY f.enabled DESC, f.name", [$cid]);
$log   = db_all("SELECT l.*, f.name AS form_name, c.name AS contact_name FROM meta_lead_log l
                   LEFT JOIN meta_forms f ON f.client_id=l.client_id AND f.form_id=l.form_id
                   LEFT JOIN contacts c ON c.id=l.contact_id
                  WHERE l.client_id=? ORDER BY l.id DESC LIMIT 30", [$cid]);
// Is the automatic import actually running? Answered from what it last did, not from settings.
$lastHook  = db_val("SELECT MAX(created_at) FROM meta_lead_log WHERE client_id=? AND via='webhook'", [$cid]);
$lastCheck = db_val("SELECT MAX(last_polled_at) FROM meta_forms WHERE client_id=? AND enabled=1", [$cid]);
$liveForms = count(array_filter($forms, fn($f) => (int) $f['enabled']));
$ago = function ($t): string {
    if (!$t) return 'not yet';
    $m = (int) floor((time() - strtotime((string) $t)) / 60);
    return $m < 1 ? 'just now' : ($m < 60 ? $m . ' min ago' : ($m < 1440 ? floor($m / 60) . ' h ago' : date('j M, H:i', strtotime((string) $t))));
};
$checkStale = $liveForms && (!$lastCheck || strtotime((string) $lastCheck) < time() - 20 * 60);
$pageErrors = array_filter($pages, fn($p) => (int) $p['subscribed'] && $p['last_error']);

client_header('Lead forms', 'crm', $CLIENT);
page_head('Facebook & Instagram lead forms', '<a class="btn btn-ghost btn-sm" href="crm.php">&larr; CRM</a>');
?>
<?php if (!meta_configured()): ?>
  <div class="card" style="max-width:640px"><h2>Not available yet</h2>
    <p class="text-muted">Connecting lead forms needs a Facebook app set up once for the whole platform.
      Ask <?= e(BRAND_PARENT) ?> to switch it on.</p></div>
<?php elseif (!$pages): ?>
  <div class="card" style="max-width:640px"><h2>Connect Facebook</h2>
    <p class="text-muted">Sign in with the Facebook account that manages your Pages. You then choose a Page, pick a lead
      form, and match its questions to your CRM — and every new lead from that form lands in the CRM within seconds.</p>
    <?php if ($isAdmin): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="connect">
      <button class="btn btn-primary">Connect Facebook</button></form><?php endif; ?></div>
<?php else: ?>

<?php foreach ($pageErrors as $p): ?>
  <div class="alert error" style="font-size:12.5px">Facebook said about <strong><?= e((string) $p['name']) ?></strong>: <?= e((string) $p['last_error']) ?>
    — pressing Reconnect Facebook usually fixes this.</div>
<?php endforeach; ?>

<?php if ($isAdmin): ?>
<div class="card" id="mf-add">
  <div class="row-between" style="flex-wrap:wrap;gap:10px">
    <h2 style="margin:0;border:0;padding:0">Add a lead form</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="connect">
      <button class="btn btn-ghost btn-sm">Reconnect Facebook</button></form>
  </div>
  <div class="grid2" style="margin-top:12px">
    <div class="field"><span class="lbl">1. Page</span>
      <select id="mf-page"><option value="">Choose a Page…</option>
        <?php foreach ($pages as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e((string) $p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><span class="lbl">2. Form</span>
      <select id="mf-form" disabled><option value="">Choose a Page first</option></select>
      <span class="text-muted" id="mf-form-msg" style="font-size:12px"></span></div>
  </div>

  <div id="mf-map" hidden>
    <h3 style="margin:14px 0 6px;font-size:14px">3. Match the form's questions to your CRM</h3>
    <div class="table-wrap"><table class="data"><thead><tr><th>Question on the form</th><th>Goes to</th></tr></thead>
      <tbody id="mf-rows"></tbody></table></div>
    <div class="grid2" style="margin-top:12px">
      <div class="field"><span class="lbl">Who gets these leads</span><select id="mf-owner">
        <option value="auto">Share out between the sales team</option>
        <option value="none">Nobody yet — an Admin assigns</option>
        <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>">All to <?= e((string) $u['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><span class="lbl">Stage they start in</span><select id="mf-stage">
        <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap" class="mt10">
      <button type="button" class="btn btn-primary" id="mf-save">Save form</button>
      <span id="mf-save-msg" style="font-size:12.5px"></span>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($liveForms): ?>
<div class="alert <?= $checkStale ? 'warn' : 'info' ?>" id="mf-auto" style="font-size:12.5px">
  <strong>Automatic import is on for <?= $liveForms ?> form<?= $liveForms === 1 ? '' : 's' ?>.</strong>
  New leads come in by themselves — you only need Sync leads once, for the ones from before you connected.
  <span style="display:block;margin-top:4px" class="text-muted">
    Instant from Facebook: last lead <?= e($ago($lastHook)) ?> ·
    Background check every 5 minutes: last ran <?= e($ago($lastCheck)) ?></span>
  <?php if ($checkStale): ?><span style="display:block;margin-top:4px">The background check has not run for a while, so leads may be
    delayed until Facebook's instant notice arrives. Ask <?= e(BRAND_PARENT) ?> to check the background worker is running.</span><?php endif; ?>
</div>
<?php endif; ?>
<style>
/* On a phone each form becomes a small card, so Sync leads is in reach instead of off to the right. */
@media (max-width: 560px) {
  #mf-list thead { display: none; }
  #mf-list tr { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 4px 10px; padding: 10px 14px; border-bottom: 1px solid var(--line); }
  #mf-list td { border: 0; padding: 0; text-align: left !important; }
  #mf-list td:first-child, #mf-list td:last-child { grid-column: 1 / -1; }
  #mf-list td[data-l]::before { content: attr(data-l); display: block; font-size: 11px; color: var(--muted); }
  #mf-list td:last-child { margin-top: 6px; }
}
</style>
<div class="card card-flush">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Your lead forms</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">New leads arrive by themselves within seconds.
      <strong>Sync leads</strong> brings in the ones already on the form — Facebook keeps them for 90 days.</p></div>
  <div class="table-wrap"><table class="data" id="mf-list">
    <thead><tr><th>Form</th><th class="num">On Facebook</th><th class="num">In your CRM</th><th>Last synced</th><th style="text-align:right"></th></tr></thead><tbody>
    <?php if (!$forms): ?><tr><td colspan="5"><div class="empty">No forms yet — add one above.</div></td></tr><?php endif; ?>
    <?php foreach ($forms as $f): ?>
      <tr data-form="<?= e((string) $f['form_id']) ?>">
        <td><strong><?= e((string) ($f['name'] ?: $f['form_id'])) ?></strong>
          <span class="text-muted" style="display:block;font-size:12px"><?= e((string) ($pageName[$f['page_id']] ?? '')) ?></span>
          <?php if (!(int) $f['enabled']): ?><span class="pill gray">Stopped</span><?php endif; ?></td>
        <td class="num" data-l="On Facebook"><?= $f['leads_count'] !== null ? (int) $f['leads_count'] : '—' ?></td>
        <td class="num" data-l="In your CRM"><?= (int) $f['imported'] ?></td>
        <td class="text-muted" style="font-size:12.5px" data-l="Last synced"><?= $f['last_synced_at'] ? e(date('j M, H:i', strtotime((string) $f['last_synced_at']))) : 'Never' ?></td>
        <td style="text-align:right;white-space:nowrap"><?php if ($isAdmin): ?>
          <form method="post" style="display:inline" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Syncing…'">
            <?= csrf_field() ?><input type="hidden" name="action" value="sync"><input type="hidden" name="form" value="<?= (int) $f['id'] ?>">
            <button class="btn btn-primary btn-sm">Sync leads</button></form>
          <button type="button" class="btn btn-ghost btn-sm" onclick="mfEdit(<?= (int) ($pagesById[$f['page_id']] ?? 0) ?>, '<?= e((string) $f['form_id']) ?>')">Edit</button>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="form">
            <input type="hidden" name="form" value="<?= (int) $f['id'] ?>"><input type="hidden" name="on" value="<?= (int) $f['enabled'] ? '' : '1' ?>">
            <button class="btn-link"><?= (int) $f['enabled'] ? 'Stop' : 'Start again' ?></button></form>
        <?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>

<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <div><h2 style="border:0;padding:0;margin:0">Recent leads from forms</h2>
      <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Every lead Facebook reported, and what happened to it.</p></div>
    <?php if ($isAdmin && $forms): ?>
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
          <span class="text-muted" style="font-size:11px">via <?= $l['via'] === 'poll' ? 'sync' : 'instant notification' ?></span></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>

<?php if ($isAdmin): ?>
<details class="card" style="font-size:13px"><summary><strong>Connected Pages (<?= count($pages) ?>)</strong></summary>
  <div class="table-wrap" style="margin-top:10px"><table class="data"><tbody>
  <?php foreach ($pages as $p): ?>
    <tr><td><?= e((string) $p['name']) ?></td><td><span class="pill <?= (int) $p['subscribed'] ? 'green' : 'gray' ?>"><?= (int) $p['subscribed'] ? 'Leads arriving' : 'Not used' ?></span></td>
      <td style="text-align:right"><form method="post" onsubmit="return confirm('Disconnect this Page and its forms? Leads already in your CRM stay.')"><?= csrf_field() ?>
        <input type="hidden" name="action" value="disconnect"><input type="hidden" name="page" value="<?= (int) $p['id'] ?>">
        <button class="btn-link" style="color:var(--danger)">Disconnect</button></form></td></tr>
  <?php endforeach; ?></tbody></table></div>
</details>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>, TARGETS = <?= json_encode(meta_targets(), JSON_UNESCAPED_UNICODE) ?>;
const $ = id => document.getElementById(id);
let FORMS = [];
async function mf(action, data){
  const fd = new FormData(); fd.append('ajax', action); fd.append('csrf_token', CSRF);
  for (const [k, v] of Object.entries(data)) {
    if (v && typeof v === 'object') for (const [kk, vv] of Object.entries(v)) fd.append(k + '[' + kk + ']', vv); else fd.append(k, v ?? '');
  }
  return (await fetch('meta_leads.php', {method:'POST', body:fd})).json().catch(() => ({ok:false, error:'Something went wrong.'}));
}
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
async function loadForms(pick){
  const sel = $('mf-form'); $('mf-map').hidden = true; $('mf-save-msg').textContent = '';
  if (!$('mf-page').value) { sel.disabled = true; sel.innerHTML = '<option value="">Choose a Page first</option>'; return; }
  sel.disabled = true; sel.innerHTML = '<option>Loading forms…</option>'; $('mf-form-msg').textContent = '';
  const d = await mf('forms', {page: $('mf-page').value});
  if (!d.ok) { sel.innerHTML = '<option value="">—</option>'; $('mf-form-msg').textContent = d.error; return; }
  FORMS = d.forms;
  if (!FORMS.length) { sel.innerHTML = '<option value="">No lead forms on this Page</option>';
    $('mf-form-msg').textContent = 'Create an Instant Form in Meta Ads Manager for this Page, then choose the Page again.'; return; }
  sel.innerHTML = '<option value="">Choose a form…</option>' + FORMS.map(f => '<option value="' + esc(f.id) + '">' + esc(f.name)
    + (f.leads_count !== null ? ' — ' + f.leads_count + ' leads' : '') + (f.status && f.status !== 'ACTIVE' ? ' (' + esc(f.status.toLowerCase()) + ')' : '')
    + (f.connected ? ' ✓ connected' : '') + '</option>').join('');
  sel.disabled = false;
  if (pick) { sel.value = pick; showMap(); }
}
function showMap(){
  const f = FORMS.find(x => x.id === $('mf-form').value);
  $('mf-map').hidden = !f; $('mf-save-msg').textContent = '';
  if (!f) return;
  const opts = cur => Object.entries(TARGETS).map(([k, l]) => '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + esc(l) + '</option>').join('');
  $('mf-rows').innerHTML = f.questions.length ? f.questions.map(q => '<tr><td>' + esc(q.label)
      + '<span class="text-muted" style="display:block;font-size:11.5px">' + esc(q.key) + '</span></td><td><select data-key="' + esc(q.key) + '">'
      + opts((f.mapping && f.mapping[q.key.toLowerCase()]) || q.guess) + '</select></td></tr>').join('')
    : '<tr><td colspan="2" class="text-muted">Facebook did not list this form\'s questions. Standard ones (name, phone, email) are still recognised.</td></tr>';
  if (f.owner_rule) $('mf-owner').value = f.owner_rule;
  if (f.stage_id) $('mf-stage').value = f.stage_id;
  $('mf-save').textContent = f.connected ? 'Save changes' : 'Save form';
}
$('mf-page').addEventListener('change', () => loadForms());
$('mf-form').addEventListener('change', showMap);
$('mf-save').addEventListener('click', async () => {
  const f = FORMS.find(x => x.id === $('mf-form').value); if (!f) return;
  const map = {}; document.querySelectorAll('#mf-rows select').forEach(s => map[s.dataset.key] = s.value);
  if (!f.questions.length) { map['phone_number'] = 'phone'; }
  $('mf-save').disabled = true; $('mf-save-msg').textContent = 'Saving…';
  const d = await mf('save_form', {page: $('mf-page').value, form_id: f.id, form_name: f.name, leads_count: f.leads_count ?? '',
                                   owner_rule: $('mf-owner').value, stage_id: $('mf-stage').value, map});
  $('mf-save').disabled = false;
  if (!d.ok) { $('mf-save-msg').innerHTML = '<span style="color:var(--danger)">' + esc(d.error) + '</span>'; return; }
  location.href = 'meta_leads.php?saved=' + Date.now() + '#mf-list';   // a new URL, so the page really reloads
});
function mfEdit(pageId, formId){
  if (!pageId) return;
  $('mf-page').value = pageId; loadForms(formId);
  $('mf-add').scrollIntoView({behavior: 'smooth'});
}
</script>
<?php endif; ?>
<?php endif; ?>
<?php layout_footer();
