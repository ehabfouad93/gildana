<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/sending.php';

/**
 * The client's own team: who can log in, their role, and which modules each one sees.
 *
 * Until this page only the platform operator could add a login, and every login saw everything.
 * Now the account's own Admins manage their people — within the modules the operator has given
 * the account, never beyond them.
 */
$cid = (int) $CLIENT['id'];

/* The module gate lets anyone with "team" in, but managing people is an Admin's job: a
   salesperson who had been handed the Team module could otherwise make themselves Admin. */
if (!is_client_admin()) {
    http_response_code(403);
    client_header('Team', 'team', $CLIENT);
    echo '<div class="card" style="max-width:560px"><h2 style="margin-top:0">Admins only</h2>'
       . '<p class="text-muted">Only an Admin of this account can add people or change their access.</p></div>';
    layout_footer();
    exit;
}

$roles    = perm_roles();
$clientHas = client_modules($CLIENT);

/** Modules from the form, limited to what the account has. NULL = the role's defaults. */
function team_modules_from_post(array $clientHas): ?string
{
    if (($_POST['mod_mode'] ?? 'role') !== 'custom') return null;
    $picked = array_values(array_intersect($clientHas, array_map('strval', (array) ($_POST['modules'] ?? []))));
    return implode(',', $picked);        // explicit list, even if empty: "custom, nothing ticked"
}

/** How many active Admins the account has — so the last one cannot remove themselves. */
function team_admin_count(int $cid): int
{
    return (int) db_val("SELECT COUNT(*) FROM users WHERE client_id=? AND role='client'
                           AND client_role='admin' AND status='active'", [$cid]);
}

/** How this person sends, from the form. Only the known choices are stored. */
function team_send_via_from_post(): ?string
{
    $v = (string) ($_POST['send_via'] ?? '');
    return $v !== '' && isset(send_via_labels()[$v]) ? $v : null;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $role   = (string) ($_POST['client_role'] ?? 'sales');
    if (!isset($roles[$role])) $role = 'sales';

    if ($action === 'add') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name  = trim((string) ($_POST['name'] ?? ''));
        $pass  = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))                        $err = 'Enter a valid email address.';
        elseif (strlen($pass) < 8)                                            $err = 'The password needs at least 8 characters.';
        elseif (db_val("SELECT COUNT(*) FROM users WHERE email=?", [$email])) $err = 'Someone already uses that email.';
        else {
            db_insert("INSERT INTO users (client_id,email,name,password_hash,role,client_role,modules,send_via,status,created_at)
                       VALUES (?,?,?,?, 'client', ?, ?, ?, 'active', NOW())",
                [$cid, $email, $name, password_hash($pass, PASSWORD_DEFAULT),     // name is NOT NULL: '' when blank
                 $role, team_modules_from_post($clientHas), team_send_via_from_post()]);
            flash(($name ?: $email) . ' added as ' . $roles[$role]['label'] . '.');
            redirect('team.php');
        }
    }

    if ($action === 'update' || $action === 'remove') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $u   = db_row("SELECT * FROM users WHERE id=? AND client_id=? AND role='client'", [$uid, $cid]);
        if (!$u) {
            $err = 'That person is not on this account.';
        } else {
            $wasAdmin   = user_client_role($u) === 'admin' && ($u['status'] ?? '') === 'active';
            $losesAdmin = $action === 'remove' || $role !== 'admin' || ($_POST['status'] ?? 'active') !== 'active';
            if ($wasAdmin && $losesAdmin && team_admin_count($cid) <= 1) {
                // Otherwise nobody would be left who could open this page to undo it.
                $err = 'This is the only Admin on the account. Make someone else Admin first.';
            } elseif ($action === 'remove') {
                db_run("DELETE FROM users WHERE id=? AND client_id=?", [$uid, $cid]);
                flash('Removed. They are signed out on their next click.');
                redirect('team.php');
            } else {
                $status = ($_POST['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active';
                db_run("UPDATE users SET client_role=?, modules=?, send_via=?, status=?, name=? WHERE id=? AND client_id=?",
                    [$role, team_modules_from_post($clientHas), team_send_via_from_post(), $status,
                     trim((string) ($_POST['name'] ?? '')), $uid, $cid]);      // NOT NULL column
                $pass = (string) ($_POST['password'] ?? '');
                if ($pass !== '') {
                    if (strlen($pass) < 8) { $err = 'The new password needs at least 8 characters.'; }
                    else db_run("UPDATE users SET password_hash=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $uid]);
                }
                if (!$err) { flash('Saved. It applies on their next click.'); redirect('team.php'); }
            }
        }
    }
}

$people = db_all("SELECT * FROM users WHERE client_id=? AND role='client' ORDER BY status, client_role, email", [$cid]);

client_header('Team', 'team', $CLIENT);
page_head('Team', '<button class="btn btn-primary btn-sm" onclick="teamOpen(null)">+ Add person</button>');
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="alert info" style="font-size:12.5px">
  <strong>Admin</strong> sees everything this account has and manages the team.
  <strong>Sales</strong> sees the CRM, Inbox and Contacts — and only the leads assigned to them.
  <strong>Viewer</strong> can look but never change or send. You can narrow any person further below.
</div>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Person</th><th>Role</th><th>Sees</th><th>Sends from</th><th>Last login</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($people as $p):
        $mods = user_modules($p + ['role' => 'client'], $CLIENT);
        $custom = perm_parse($p['modules'] ?? null) !== null; ?>
        <tr style="<?= ($p['status'] ?? '') !== 'active' ? 'opacity:.55' : '' ?>">
          <td><strong><?= e((string) ($p['name'] ?: $p['email'])) ?></strong>
            <?php if ($p['name']): ?><span class="text-muted d-block"><?= e((string) $p['email']) ?></span><?php endif; ?>
            <?php if (($p['status'] ?? '') !== 'active'): ?><span class="pill gray">disabled</span><?php endif; ?></td>
          <td><span class="pill <?= user_client_role($p) === 'admin' ? 'gold' : 'gray' ?>"><?= e($roles[user_client_role($p)]['label']) ?></span></td>
          <td class="text-muted" style="font-size:12.5px">
            <?= e(implode(', ', array_map(fn($k) => perm_modules()[$k]['label'], $mods))) ?: '—' ?>
            <?= $custom ? '<span class="pill blue" style="margin-left:4px">custom</span>' : '' ?></td>
          <td style="font-size:12.5px"><?php
            $sv = (string) ($p['send_via'] ?? '');
            echo e(send_via_labels()[$sv] ?? '—');
            if ($sv === 'own') {
                $pc = user_channel((int) $p['id']);
                echo ($pc && $pc['status'] === 'connected')
                    ? ' <span class="pill green">linked' . (!empty($pc['msisdn']) ? ' +' . e((string) $pc['msisdn']) : '') . '</span>'
                    : ' <span class="pill gray" title="They link it from My WhatsApp">not linked yet</span>';
            } ?></td>
          <td class="text-muted"><?= $p['last_login_at'] ? e(date('j M, H:i', strtotime((string) $p['last_login_at']))) : 'Never' ?></td>
          <td style="text-align:right;white-space:nowrap">
            <button class="btn btn-ghost btn-sm" onclick='teamOpen(<?= json_encode([
                'id' => (int) $p['id'], 'email' => $p['email'], 'name' => $p['name'],
                'role' => user_client_role($p), 'status' => $p['status'], 'send_via' => (string) ($p['send_via'] ?? ''),
                'modules' => perm_parse($p['modules'] ?? null)], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back" id="m-team">
  <form class="modal" method="post" id="team-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" id="t-action" value="add">
    <input type="hidden" name="user_id" id="t-id" value="">
    <h2 id="t-title">Add a person</h2>

    <div class="field"><span class="lbl">Name</span><input type="text" name="name" id="t-name" placeholder="Ahmed Mahmoud"></div>
    <div class="field" id="t-email-wrap"><span class="lbl">Email</span><input type="email" name="email" id="t-email" placeholder="ahmed@company.com"></div>
    <div class="field"><span class="lbl" id="t-pass-lbl">Password</span>
      <input type="password" name="password" id="t-pass" minlength="8" autocomplete="new-password" placeholder="At least 8 characters"></div>

    <div class="field"><span class="lbl">Role</span>
      <select name="client_role" id="t-role" onchange="teamDefaults()">
        <?php foreach ($roles as $k => $r): ?><option value="<?= e($k) ?>"><?= e($r['label']) ?></option><?php endforeach; ?>
      </select></div>

    <div class="field"><span class="lbl">What they can open</span>
      <label class="mod-all"><input type="radio" name="mod_mode" value="role" id="t-mode-role" checked onchange="teamMode()">
        The role's usual set</label>
      <label class="mod-all"><input type="radio" name="mod_mode" value="custom" id="t-mode-custom" onchange="teamMode()">
        Choose exactly</label>
      <div class="mod-grid" id="t-mods">
        <?php foreach ($clientHas as $k): ?>
          <label class="mod-opt"><input type="checkbox" class="t-mod" name="modules[]" value="<?= e($k) ?>">
            <?= e(perm_modules()[$k]['label']) ?></label>
        <?php endforeach; ?>
      </div>
      <span class="text-muted" style="font-size:11.5px">Only modules your account has are listed.</span>
    </div>

    <div class="field"><span class="lbl">Sends messages from</span>
      <select name="send_via" id="t-send">
        <?php foreach (send_via_labels() as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
      </select>
      <span class="text-muted" style="font-size:11.5px">"Their own phone" — they link it themselves from My WhatsApp.
        It can be banned by WhatsApp like any personal number.</span></div>

    <div class="field" id="t-status-wrap" hidden><span class="lbl">Access</span>
      <select name="status" id="t-status"><option value="active">Active</option><option value="disabled">Disabled — cannot sign in</option></select></div>

    <div class="row-between mt10">
      <button type="button" class="btn btn-ghost" id="t-remove" hidden
        onclick="if(confirm('Remove this person? They are signed out immediately.')){document.getElementById('t-action').value='remove';document.getElementById('team-form').submit();}">Remove</button>
      <span style="display:flex;gap:8px;margin-left:auto">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('m-team').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </span>
    </div>
  </form>
</div>

<script>
const ROLE_DEFAULTS = <?= json_encode(array_map(fn($r) => $r['defaults'], $roles)) ?>;
const CLIENT_HAS = <?= json_encode($clientHas) ?>;
const $t = id => document.getElementById(id);

/* The checkboxes always show what the person will actually see — the role's set while "usual"
   is chosen, so switching to "choose exactly" starts from something sensible, not from blank. */
function teamDefaults(){
  if ($t('t-mode-custom').checked) return;
  const d = ROLE_DEFAULTS[$t('t-role').value] || CLIENT_HAS;
  document.querySelectorAll('.t-mod').forEach(c => c.checked = d.includes(c.value));
}
function teamMode(){
  const custom = $t('t-mode-custom').checked;
  document.querySelectorAll('.t-mod').forEach(c => c.disabled = !custom);
  teamDefaults();
}
function teamOpen(p){
  const edit = !!p;
  $t('t-title').textContent  = edit ? 'Edit ' + (p.name || p.email) : 'Add a person';
  $t('t-action').value       = edit ? 'update' : 'add';
  $t('t-id').value           = edit ? p.id : '';
  $t('t-name').value         = edit ? (p.name || '') : '';
  $t('t-email').value        = edit ? p.email : '';
  $t('t-email-wrap').hidden  = edit;                     // email is the login; changed by support
  $t('t-pass').value         = '';
  $t('t-pass').required      = !edit;
  $t('t-pass-lbl').textContent = edit ? 'New password (leave empty to keep)' : 'Password';
  $t('t-role').value         = edit ? p.role : 'sales';
  $t('t-send').value         = edit ? (p.send_via || '') : '';
  $t('t-status-wrap').hidden = !edit;
  $t('t-status').value       = edit ? p.status : 'active';
  $t('t-remove').hidden      = !edit;
  const custom = edit && Array.isArray(p.modules);
  $t('t-mode-custom').checked = custom; $t('t-mode-role').checked = !custom;
  if (custom) document.querySelectorAll('.t-mod').forEach(c => c.checked = p.modules.includes(c.value));
  teamMode();
  $t('m-team').classList.add('open');
}
</script>
<?php layout_footer();
