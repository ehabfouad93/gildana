<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';

/**
 * Who gets which lead, and what happens when a lead is not contacted in time.
 *
 * Rules are checked top to bottom; the first that matches a new lead decides which people share
 * it. A lead no rule matches is shared by the whole sales team. The timers below apply to every
 * lead, whichever rule gave it out — and when a lead is taken back, the rules choose again.
 */
$cid = (int) $CLIENT['id'];
if (!is_client_admin()) {
    http_response_code(403);
    client_header('Assignment rules', 'crm', $CLIENT);
    echo '<div class="card" style="max-width:560px"><h2 style="margin-top:0">For managers</h2><p class="text-muted">Only an Admin can change how leads are shared out.</p></div>';
    layout_footer(); exit;
}
$people   = crm_assignable_users($cid);
$names    = array_column($people, 'name', 'id');
$projects = crm_projects($cid);
$pnames   = array_column($projects, 'name', 'id');
$units    = crm_options($cid, 'unit_type');
$sources  = ['meta_form' => 'Meta lead form', 'ctwa' => 'Click-to-WhatsApp ad', 'inbound' => 'WhatsApp message',
             'import' => 'Imported', 'manual' => 'Added by hand', 'qualifier' => 'Lead Qualifier', 'sheet' => 'Google Sheet'];
$forms = [];
try { foreach (db_all("SELECT form_id, name FROM meta_forms WHERE client_id=? AND enabled=1 ORDER BY name", [$cid]) as $f) $forms[$f['form_id']] = $f['name']; }
catch (Throwable $e) {}
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    $rid = (int) ($_POST['rule'] ?? 0);
    $rule = $rid ? db_row("SELECT * FROM crm_rules WHERE id=? AND client_id=?", [$rid, $cid]) : null;

    if ($a === 'save_rule') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $users = array_values(array_filter(array_map('intval', (array) ($_POST['users'] ?? [])), fn($u) => isset($names[$u])));
        $proj = (int) ($_POST['match_project'] ?? 0);
        $src  = (string) ($_POST['match_source'] ?? '');
        $form = (string) ($_POST['match_form'] ?? '');
        $unit = trim((string) ($_POST['match_unit'] ?? ''));
        if ($name === '') $err = 'Give the rule a name, like "Porto Golf team".';
        elseif (!$users) $err = 'Choose at least one person to receive these leads.';
        elseif (!$proj && $src === '' && $form === '' && $unit === '') $err = 'Choose at least one thing the rule matches — a project, a source, a form or a unit type.';
        else {
            $vals = [mb_substr($name, 0, 120), isset($pnames[$proj]) ? $proj : null, isset($sources[$src]) ? $src : null,
                     isset($forms[$form]) ? $form : null, $unit !== '' ? mb_substr($unit, 0, 80) : null,
                     implode(',', $users), ($_POST['method'] ?? '') === 'least' ? 'least' : 'rotate'];
            if ($rule) db_run("UPDATE crm_rules SET name=?, match_project=?, match_source=?, match_form=?, match_unit=?, users=?, method=? WHERE id=?",
                              array_merge($vals, [$rid]));
            else db_insert("INSERT INTO crm_rules (client_id,name,match_project,match_source,match_form,match_unit,users,method,sort,created_at)
                            VALUES (?,?,?,?,?,?,?,?,?,NOW())",
                           array_merge([$cid], $vals, [(int) db_val("SELECT COALESCE(MAX(sort),0)+10 FROM crm_rules WHERE client_id=?", [$cid])]));
            flash('Rule saved.');
            redirect('crm_rules.php');
        }
    }
    if ($rule && $a === 'toggle') { db_run("UPDATE crm_rules SET active=1-active WHERE id=?", [$rid]); redirect('crm_rules.php'); }
    if ($rule && $a === 'delete') { db_run("DELETE FROM crm_rules WHERE id=?", [$rid]); flash('Rule deleted.'); redirect('crm_rules.php'); }
    if ($rule && in_array($a, ['up', 'down'], true)) {
        $all = crm_rules($cid);
        $ids = array_map('intval', array_column($all, 'id'));
        $i = array_search($rid, $ids, true);
        $j = $a === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($ids[$j])) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; }
        foreach ($ids as $k => $id) db_run("UPDATE crm_rules SET sort=? WHERE id=?", [($k + 1) * 10, $id]);
        redirect('crm_rules.php');
    }
    if ($a === 'staff_wa') {
        $kinds = array_values(array_intersect(array_keys(crm_staff_wa_kinds()), (array) ($_POST['kinds'] ?? [])));
        $tpl = (int) ($_POST['template'] ?? 0);
        if ($tpl && !db_val("SELECT COUNT(*) FROM templates WHERE id=? AND client_id=?", [$tpl, $cid])) $tpl = 0;
        // Each alert's own template and fields (Business API), or its own wording (company phone).
        $custom = [];
        foreach ((array) ($_POST['custom'] ?? []) as $k => $c) {
            if (!isset(crm_staff_wa_kinds()[$k]) || !is_array($c)) continue;
            $ct = (int) ($c['template'] ?? 0);
            if ($ct && !db_val("SELECT COUNT(*) FROM templates WHERE id=? AND client_id=?", [$ct, $cid])) $ct = 0;
            $row = array_filter(['template' => $ct ?: null, 'vars' => $ct ? crm_tokens_from_post('vars', $c, crm_staff_tokens($cid)) : null,
                                 'text' => mb_substr(trim((string) ($c['text'] ?? '')), 0, 1000) ?: null]);
            if ($row) $custom[$k] = $row;
        }
        $stages = array_map('intval', (array) ($_POST['stages'] ?? []));
        crm_settings_set($cid, ['staff_wa_on' => !empty($_POST['on']) ? 1 : 0, 'staff_wa_template' => $tpl ?: null,
                                'staff_wa_kinds' => implode(',', $kinds),
                                'staff_wa_custom' => $custom ? json_encode($custom, JSON_UNESCAPED_UNICODE) : null,
                                'staff_wa_stages' => $stages ? implode(',', $stages) : null]);
        flash('WhatsApp alerts saved.');
        redirect('crm_rules.php#staff-wa');
    }
    if ($a === 'staff_wa_test') {
        $me = db_row("SELECT * FROM users WHERE id=?", [(int) ($PERM_USER['id'] ?? 0)]);
        if (empty($me['phone'])) { flash('Add your own WhatsApp number on your profile first.', 'error'); redirect('crm_rules.php#staff-wa'); }
        $r = crm_staff_send($CLIENT, (string) $me['phone'], 'Test alert from ' . brand_name() . ': this is how new leads will reach your team.',
                            rtrim(app_base_url(), '/') . '/client/crm.php');
        flash($r['ok'] ? 'Sent to +' . $me['phone'] . '. Check your WhatsApp.' : 'Could not send: ' . $r['error'], $r['ok'] ? 'success' : 'error');
        redirect('crm_rules.php#staff-wa');
    }
    if ($a === 'timers') {
        $num = fn(string $k, int $max) => ($v = trim((string) ($_POST[$k] ?? ''))) !== '' && (int) $v > 0 ? min($max, (int) $v) : null;
        $hour = fn(string $k) => ($v = (string) ($_POST[$k] ?? '')) !== '' ? max(0, min(23, (int) $v)) : null;
        $s = [
            'first_contact_minutes' => !empty($_POST['alert_on']) ? $num('first_contact_minutes', 1440) : null,
            'reclaim_minutes'       => !empty($_POST['reclaim_on']) ? $num('reclaim_minutes', 10080) : null,
            'reclaim_max'           => max(1, min(10, (int) ($_POST['reclaim_max'] ?? 2))),
            'stale_days'            => !empty($_POST['stale_on']) ? $num('stale_days', 365) : null,
            'stale_reassign'        => !empty($_POST['stale_reassign']) ? 1 : 0,
            'work_start'            => !empty($_POST['hours_on']) ? $hour('work_start') : null,
            'work_end'              => !empty($_POST['hours_on']) ? $hour('work_end') : null,
            'digest_hour'           => !empty($_POST['digest_on']) ? $hour('digest_hour') : null,
            'followup_reminders'    => !empty($_POST['followup_reminders']) ? 1 : 0,
            'require_lost_reason'   => !empty($_POST['require_lost_reason']) ? 1 : 0,
        ];
        if ($s['reclaim_minutes'] && $s['first_contact_minutes'] && $s['reclaim_minutes'] <= $s['first_contact_minutes']) {
            $err = 'Taking a lead back should come after the alert — make it a longer time than the alert.';
            $typed = $s;
        } elseif ($s['work_start'] !== null && $s['work_end'] !== null && $s['work_end'] <= $s['work_start']) {
            $err = 'Working hours must end after they start.';
            $typed = $s;
        } else {
            crm_settings_save($cid, $s);
            flash('Timers saved.');
            redirect('crm_rules.php#timers');
        }
    }
}

$rules = crm_rules($cid);
// A refused save shows the timers as typed, not as they were before.
$s = isset($typed) ? array_merge(crm_settings($cid), $typed) : crm_settings($cid);
$edit = isset($_GET['edit']) ? db_row("SELECT * FROM crm_rules WHERE id=? AND client_id=?", [(int) $_GET['edit'], $cid]) : null;
// A refused save shows what was typed; otherwise the rule being edited, or a blank form.
$form_ = ($_POST['action'] ?? '') === 'save_rule' ? $_POST : ($edit ?: []);
if (!$edit && ($_POST['action'] ?? '') === 'save_rule' && (int) ($_POST['rule'] ?? 0)) $edit = db_row("SELECT * FROM crm_rules WHERE id=? AND client_id=?", [(int) $_POST['rule'], $cid]);
$hours = fn($sel) => implode('', array_map(fn($h) => '<option value="' . $h . '"' . ((string) $sel === (string) $h ? ' selected' : '') . '>'
                                         . sprintf('%02d:00', $h) . '</option>', range(0, 23)));

$describe = function (array $r) use ($pnames, $sources, $forms): string {
    $w = [];
    if ($r['match_project'] !== null) $w[] = 'project is ' . ($pnames[(int) $r['match_project']] ?? 'a removed project');
    if ($r['match_source'])           $w[] = 'came from ' . ($sources[$r['match_source']] ?? $r['match_source']);
    if ($r['match_form'])             $w[] = 'form is ' . ($forms[$r['match_form']] ?? 'a stopped form');
    if ($r['match_unit'])             $w[] = 'unit type is ' . $r['match_unit'];
    return $w ? 'When the ' . implode(' and ', $w) : 'Every lead';
};

client_header('Assignment rules', 'crm', $CLIENT);
page_head('Assignment rules & timers', '<a class="btn btn-ghost btn-sm" href="crm_team.php">Team & transfer</a>');
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="card card-flush">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Rules</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">Checked from the top; the first rule that matches a new lead decides who shares it.
      Leads no rule matches are shared by the whole sales team. People who are away or at their limit are skipped.</p></div>
  <div class="table-wrap"><table class="data" id="rules">
    <thead><tr><th>Rule</th><th>Goes to</th><th></th></tr></thead><tbody>
    <?php if (!$rules): ?><tr><td colspan="3"><div class="empty">No rules yet — every lead is shared by the whole sales team, in turn.</div></td></tr><?php endif; ?>
    <?php foreach ($rules as $i => $r):
      $who = array_map(fn($u) => $names[(int) $u] ?? null, array_filter(explode(',', (string) $r['users']))); $who = array_filter($who); ?>
      <tr class="<?= (int) $r['active'] ? '' : 'muted-row' ?>">
        <td><strong><?= e((string) $r['name']) ?></strong> <?php if (!(int) $r['active']): ?><span class="pill gray">Off</span><?php endif; ?>
          <span class="text-muted" style="display:block;font-size:12px"><?= e($describe($r)) ?></span></td>
        <td style="font-size:13px"><?= e(implode(', ', $who) ?: 'Nobody') ?>
          <span class="text-muted" style="display:block;font-size:12px"><?= $r['method'] === 'least' ? 'whoever has the fewest open leads' : 'in turn' ?></span></td>
        <td style="text-align: end;white-space:nowrap">
          <?php foreach ([['up', '↑', $i > 0], ['down', '↓', $i < count($rules) - 1]] as [$act, $lbl, $show]): if (!$show) continue; ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="rule" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-ghost btn-sm" aria-label="Move <?= $act ?>"><?= $lbl ?></button></form>
          <?php endforeach; ?>
          <a class="btn btn-ghost btn-sm" href="?edit=<?= (int) $r['id'] ?>#rule-form">Edit</a>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="rule" value="<?= (int) $r['id'] ?>">
            <button class="btn-link"><?= (int) $r['active'] ? 'Turn off' : 'Turn on' ?></button></form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this rule?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="rule" value="<?= (int) $r['id'] ?>">
            <button class="btn-link" style="color:var(--danger)">Delete</button></form></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>

<div class="card" id="rule-form">
  <h2><?= $edit ? 'Edit rule' : 'Add a rule' ?></h2>
  <?php $v = fn($k) => (string) ($form_[$k] ?? ''); $chosen = array_map('intval', is_array($form_['users'] ?? null) ? $form_['users'] : explode(',', (string) ($form_['users'] ?? ''))); ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_rule"><input type="hidden" name="rule" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <div class="field"><span class="lbl">Name</span><input name="name" maxlength="120" value="<?= e($v('name')) ?>" placeholder="Porto Golf team"></div>
    <p class="lbl" style="margin:10px 0 6px">When a new lead…</p>
    <div class="grid2">
      <div class="field"><span class="lbl">is for the project</span><select name="match_project"><option value="0">Any project</option>
        <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $v('match_project') === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
        <?php if (!$projects): ?><span class="text-muted" style="font-size:12px">Add projects in <a href="crm_setup.php">Projects & lists</a>.</span><?php endif; ?></div>
      <div class="field"><span class="lbl">came from</span><select name="match_source"><option value="">Anywhere</option>
        <?php foreach ($sources as $k => $l): ?><option value="<?= $k ?>" <?= $v('match_source') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><span class="lbl">came from the lead form</span><select name="match_form"><option value="">Any form</option>
        <?php foreach ($forms as $k => $l): ?><option value="<?= e((string) $k) ?>" <?= $v('match_form') === (string) $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><span class="lbl">wants the unit type</span><select name="match_unit"><option value="">Any unit type</option>
        <?php foreach ($units as $u): ?><option <?= $v('match_unit') === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select></div>
    </div>
    <p class="lbl" style="margin:10px 0 6px">…give it to one of</p>
    <div class="mod-grid">
      <?php foreach ($people as $u): ?>
        <label class="mod-opt"><input type="checkbox" name="users[]" value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $chosen, true) ? 'checked' : '' ?>>
          <?= e((string) $u['name']) ?> <span class="text-muted" style="font-size:11.5px"><?= e(ucfirst((string) $u['client_role'])) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="field" style="max-width:360px"><span class="lbl">Choosing between them</span><select name="method">
      <option value="rotate">In turn</option>
      <option value="least" <?= $v('method') === 'least' ? 'selected' : '' ?>>Whoever has the fewest open leads</option></select></div>
    <button class="btn btn-primary"><?= $edit ? 'Save rule' : 'Add rule' ?></button>
    <?php if ($edit): ?><a class="btn btn-ghost" href="crm_rules.php">Cancel</a><?php endif; ?>
  </form>
</div>

<?php
  $approved = db_all("SELECT id, wa_name, language, category FROM templates WHERE client_id=? AND LOWER(status)='approved' ORDER BY wa_name", [$cid]);
  $hasPhone = ($CLIENT['personal_status'] ?? '') === 'connected';
  $noNumber = array_column(db_all("SELECT COALESCE(NULLIF(name,''), email) n FROM users WHERE client_id=? AND role='client' AND status='active'
                                    AND client_role IN ('sales','admin') AND (phone IS NULL OR phone='')", [$cid]), 'n');
  $waFail = db_all("SELECT n.created_at, n.wa_error, COALESCE(NULLIF(u.name,''), u.email) who FROM crm_notices n JOIN users u ON u.id=n.user_id
                     WHERE n.client_id=? AND n.wa_status='failed' ORDER BY n.id DESC LIMIT 5", [$cid]);
  $kindsOn = array_filter(explode(',', (string) $s['staff_wa_kinds']));
  $staffCustom = crm_staff_custom($s);
?>
<div class="card" id="staff-wa">
  <h2>WhatsApp alerts to salespeople</h2>
  <p class="text-muted" style="font-size:12.5px;margin-top:-4px">Besides the bell in the app, send each person the alert on their own WhatsApp.
    <?= $hasPhone ? 'They go out from the company\'s linked phone, as ordinary messages.'
                  : 'Without a linked company phone they go through the WhatsApp Business API, which needs an approved template:
                     {{1}} becomes the alert, {{2}} the link to open it. A utility template such as "REVENECT: {{1}} — {{2}}" works well.' ?></p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="staff_wa">
    <label class="mod-all"><input type="checkbox" name="on" value="1" <?= (int) $s['staff_wa_on'] ? 'checked' : '' ?>> Send WhatsApp alerts</label>
    <div class="mod-grid" style="margin:10px 0">
      <?php foreach (crm_staff_wa_kinds() as $k => $l): ?>
        <label class="mod-opt"><input type="checkbox" name="kinds[]" value="<?= $k ?>" <?= in_array($k, $kindsOn, true) ? 'checked' : '' ?>> <?= e($l) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="field" id="sw-stages"><span class="lbl">Stages that alert the salesperson</span>
      <div class="sw-chips"><?php $stOn = explode(',', (string) ($s['staff_wa_stages'] ?? '')); foreach (crm_stages($cid) as $st): ?>
        <label class="mod-opt"><input type="checkbox" name="stages[]" value="<?= (int) $st['id'] ?>" <?= in_array((string) $st['id'], $stOn, true) ? 'checked' : '' ?>> <?= e($st['name']) ?></label>
      <?php endforeach; ?></div>
      <div class="hint">With “A lead of theirs reaches a stage” ticked: when one of their leads moves into one of these stages.</div></div>

    <?php if (!$hasPhone): ?>
    <div class="field" style="max-width:420px"><span class="lbl">Template for alerts</span><select name="template">
      <option value="0">Choose an approved template…</option>
      <?php foreach ($approved as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $s['staff_wa_template'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['wa_name'] . ' (' . $t['language'] . ', ' . strtolower((string) $t['category']) . ')') ?></option><?php endforeach; ?>
    </select></div>
    <?php endif; ?>
    <div class="section-label">What each alert says</div>
    <p class="text-muted" style="font-size:12.5px;margin:-4px 0 8px"><?= $hasPhone
      ? 'Write each alert in your own words. Click a field to add it — it is filled in for each lead when the alert goes out.'
      : 'Give an alert its own approved template and choose what fills each {{n}} — the alert, the link, the lead\'s name, phone, project, stage, budget, your own fields… Left empty, it uses the template above with {{1}} the alert and {{2}} the link.' ?></p>
    <?php foreach (crm_staff_wa_kinds() as $k => $l): if ($k === 'daily') continue; $own = $staffCustom[$k] ?? []; ?>
      <details class="sw-kind" data-kind="<?= $k ?>"<?= $own ? ' open' : '' ?>>
        <summary><?= e($l) ?><?= $own ? ' <span class="pill blue">custom</span>' : '' ?></summary>
        <?php if ($hasPhone): ?>
          <textarea name="custom[<?= $k ?>][text]" rows="3" placeholder="<?= e(crm_staff_default_text()) ?>"><?= e((string) ($own['text'] ?? '')) ?></textarea>
          <div class="sw-fields"><?php foreach (crm_staff_tokens($cid) as $tk => $tl): if ($tk === 'text') continue; ?><button type="button" class="crm-chip" data-tok="<?= e($tk) ?>"><?= e($tl) ?></button><?php endforeach; ?></div>
        <?php else: ?>
          <div class="field"><span class="lbl">Template</span><select name="custom[<?= $k ?>][template]" class="sw-tpl">
            <option value="0">The alert template above</option>
            <?php foreach ($approved as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) ($own['template'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['wa_name'] . ' (' . $t['language'] . ')') ?></option><?php endforeach; ?>
          </select><div class="hint sw-prev" style="white-space:pre-wrap"></div></div>
          <div class="sw-vars" data-prefix="custom[<?= $k ?>][vars]" data-tokens="<?= e(json_encode(array_values((array) ($own['vars'] ?? [])))) ?>"></div>
        <?php endif; ?>
      </details>
    <?php endforeach; ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary">Save alerts</button></div>
  </form>
  <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="staff_wa_test">
    <button class="btn btn-ghost btn-sm">Send a test to my WhatsApp</button></form>
  <?php if ($noNumber): ?><p class="text-muted" style="font-size:12.5px;margin:12px 0 0">No WhatsApp number yet for: <?= e(implode(', ', $noNumber)) ?>.
    Add it on the <a href="team.php">Team</a> page, or they can add it on their profile.</p><?php endif; ?>
  <?php if ($waFail): ?>
    <div class="alert warn" style="font-size:12.5px;margin-top:12px"><strong>Recent alerts that did not go out</strong>
      <?php foreach ($waFail as $f): ?><div><?= e(date('j M, H:i', strtotime((string) $f['created_at']))) ?> · <?= e((string) $f['who']) ?> — <?= e((string) $f['wa_error']) ?></div><?php endforeach; ?></div>
  <?php endif; ?>
</div>

<div class="card" id="timers">
  <h2>Timers and reminders</h2>
  <form method="post" class="timers">
    <?= csrf_field() ?><input type="hidden" name="action" value="timers">
    <div class="timer-row">
      <label class="mod-all"><input type="checkbox" name="alert_on" value="1" <?= $s['first_contact_minutes'] ? 'checked' : '' ?>> Alert when a new lead is not contacted within</label>
      <input type="number" name="first_contact_minutes" min="1" value="<?= (int) ($s['first_contact_minutes'] ?: 15) ?>"> minutes
      <p class="text-muted">The salesperson's phone buzzes, and managers get one summary. A call logged or a message sent counts as contact.</p>
    </div>
    <div class="timer-row">
      <label class="mod-all"><input type="checkbox" name="reclaim_on" value="1" <?= $s['reclaim_minutes'] ? 'checked' : '' ?>> Take it back and give it to someone else after</label>
      <input type="number" name="reclaim_minutes" min="1" value="<?= (int) ($s['reclaim_minutes'] ?: 60) ?>"> minutes,
      at most <input type="number" name="reclaim_max" min="1" max="10" value="<?= (int) $s['reclaim_max'] ?>"> times
      <p class="text-muted">Chosen by the rules above, never back to someone who already had it. After the limit it stays, and managers see it as late.</p>
    </div>
    <div class="timer-row">
      <label class="mod-all"><input type="checkbox" name="stale_on" value="1" <?= $s['stale_days'] ? 'checked' : '' ?>> Alert when an open lead has had no activity for</label>
      <input type="number" name="stale_days" min="1" value="<?= (int) ($s['stale_days'] ?: 5) ?>"> days
      <label class="mod-all" style="display:block;margin-top:6px"><input type="checkbox" name="stale_reassign" value="1" <?= (int) $s['stale_reassign'] ? 'checked' : '' ?>> …and give it to someone else</label>
    </div>
    <div class="timer-row">
      <label class="mod-all"><input type="checkbox" name="hours_on" value="1" <?= $s['work_start'] !== null ? 'checked' : '' ?>> Only count working hours, from</label>
      <select name="work_start"><?= $hours($s['work_start'] ?? 9) ?></select> to <select name="work_end"><?= $hours($s['work_end'] ?? 21) ?></select>
      <p class="text-muted">A lead that arrives at night starts its clock when the working day does.</p>
    </div>
    <div class="timer-row">
      <label class="mod-all"><input type="checkbox" name="digest_on" value="1" <?= $s['digest_hour'] !== null ? 'checked' : '' ?>> Morning summary to each person's phone at</label>
      <select name="digest_hour"><?= $hours($s['digest_hour'] ?? 9) ?></select>
      <p class="text-muted">Today's follow-ups, overdue ones and new leads. Managers get the whole team's numbers.</p>
    </div>
    <div class="timer-row">
      <label class="mod-all"><input type="checkbox" name="followup_reminders" value="1" <?= (int) $s['followup_reminders'] ? 'checked' : '' ?>> Remind the owner when a follow-up is due</label>
    </div>
    <div class="timer-row">
      <label class="mod-all"><input type="checkbox" name="require_lost_reason" value="1" <?= (int) $s['require_lost_reason'] ? 'checked' : '' ?>> Ask why, when a lead is moved to Lost</label>
    </div>
    <button class="btn btn-primary">Save timers</button>
  </form>
</div>
<script>
(function () {
  const TPLS = <?= json_encode(crm_tpl_choices($cid), JSON_UNESCAPED_UNICODE) ?>;
  const TOKENS = <?= json_encode(crm_staff_tokens($cid), JSON_UNESCAPED_UNICODE) ?>;
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  function tokGroups(f){const g={};Object.entries(TOKENS).forEach(([k,l])=>{const i=l.indexOf(': '),p=i>0?l.slice(0,i):'';(g[p]=g[p]||[]).push(f([k,l]))});return Object.entries(g).map(([p,o])=>p?`<optgroup label="${p}">${o.join('')}</optgroup>`:o.join('')).join('')}
  // A field chip adds {{field}} where the cursor is in that alert's wording.
  document.querySelectorAll('.sw-kind .crm-chip[data-tok]').forEach(b => b.addEventListener('click', () => {
    const ta = b.closest('.sw-kind').querySelector('textarea'), tok = '{{' + b.dataset.tok + '}}';
    const at = ta.selectionStart ?? ta.value.length;
    ta.value = ta.value.slice(0, at) + tok + ta.value.slice(ta.selectionEnd ?? at); ta.focus(); ta.selectionStart = ta.selectionEnd = at + tok.length;
  }));
  // One picker per {{n}} of the alert's own template.
  function draw(sel, first) {
    const box = sel.closest('.sw-kind').querySelector('.sw-vars'), prev = sel.closest('.sw-kind').querySelector('.sw-prev');
    const t = TPLS[sel.value]; box.innerHTML = ''; prev.textContent = t ? t.text : '';
    if (!t) return;
    const cur = first ? JSON.parse(box.dataset.tokens || '[]') : [];
    const guess = ['alert', 'link', 'name', 'phone', 'project'];
    for (let i = 0; i < t.body; i++) {
      const tok = cur[i] || guess[i] || 'name', isText = tok.startsWith('text:');
      const row = document.createElement('div'); row.className = 'tpl-var';
      row.innerHTML = `<span class="tpl-var-n">{{${i + 1}}}</span><select name="${box.dataset.prefix}[${i}]">${tokGroups(([k, l]) => `<option value="${k}" ${(isText ? 'text' : tok) === k ? 'selected' : ''}>${esc(l)}</option>`)}</select>
        <input type="text" name="${box.dataset.prefix.slice(0, -1)}_text][${i}]" value="${isText ? esc(tok.slice(5)) : ''}" placeholder="Type the text" ${isText ? '' : 'hidden'}>`;
      row.querySelector('select').onchange = e => { row.querySelector('input').hidden = e.target.value !== 'text'; };
      box.appendChild(row);
    }
  }
  document.querySelectorAll('.sw-tpl').forEach(sel => { draw(sel, true); sel.addEventListener('change', () => draw(sel, false)); });
})();
</script>
<?php layout_footer();
