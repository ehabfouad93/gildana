<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/push.php';   // setting_set

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_plan') {
        $pid  = (int) ($_POST['plan_id'] ?? 0);
        $code = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) ($_POST['code'] ?? '')) ?? '');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($code === '' || $name === '') {
            $err = 'A plan needs a code and a name.';
        } else {
            $vals = [
                $code, $name,
                (float) ($_POST['price_month'] ?? 0),
                max(0, (int) ($_POST['included_credits'] ?? 0)),
                (float) ($_POST['overage_per_1k'] ?? 0),
                max(0, (int) ($_POST['max_numbers'] ?? 1)),
                max(1, (int) ($_POST['max_seats'] ?? 1)),
                max(0, (int) ($_POST['max_contacts'] ?? 0)),
                max(0, (int) ($_POST['max_flows'] ?? 0)),
                ($_POST['ai_mode'] ?? 'byo') === 'included' ? 'included' : 'byo',
                max(0, (int) ($_POST['included_ai_credits'] ?? 0)),
                !empty($_POST['is_active']) ? 1 : 0,
            ];
            if ($pid > 0) {
                db_run("UPDATE plans SET code=?,name=?,price_month=?,included_credits=?,overage_per_1k=?,
                               max_numbers=?,max_seats=?,max_contacts=?,max_flows=?,ai_mode=?,included_ai_credits=?,is_active=?
                         WHERE id=?", array_merge($vals, [$pid]));
            } else {
                db_insert("INSERT INTO plans (code,name,price_month,included_credits,overage_per_1k,
                               max_numbers,max_seats,max_contacts,max_flows,ai_mode,included_ai_credits,is_active,created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())", $vals);
            }
            flash('Plan saved.');
            redirect('plans.php');
        }
    }

    if ($action === 'save_money') {
        // How a credit converts to money, and what we add on top of cost.
        setting_set('credit_value',   (string) max(0.0001, (float) ($_POST['credit_value'] ?? 0.01)));
        setting_set('billing_markup', (string) max(1, (float) ($_POST['billing_markup'] ?? 1)));
        flash('Pricing updated.');
        redirect('plans.php');
    }

    if ($action === 'save_platform') {
        // Credentials for clients who send under OUR WhatsApp account.
        $tok = trim((string) ($_POST['wa_platform_token'] ?? ''));
        if ($tok !== '') setting_set('wa_platform_token', encrypt_secret($tok));
        setting_set('wa_platform_phone_id', trim((string) ($_POST['wa_platform_phone_id'] ?? '')));

        $aiKey = trim((string) ($_POST['ai_platform_key'] ?? ''));
        if ($aiKey !== '') setting_set('ai_platform_key', encrypt_secret($aiKey));
        setting_set('ai_platform_provider', in_array(($_POST['ai_platform_provider'] ?? ''), ['claude','openai'], true) ? (string) $_POST['ai_platform_provider'] : '');
        setting_set('ai_platform_model', trim((string) ($_POST['ai_platform_model'] ?? '')));
        flash('Platform credentials saved.');
        redirect('plans.php');
    }

    if ($action === 'delete_plan') {
        $pid = (int) ($_POST['plan_id'] ?? 0);
        if (db_val("SELECT COUNT(*) FROM clients WHERE plan_id=?", [$pid])) {
            $err = 'That plan is still assigned to clients — move them first.';
        } else {
            db_run("DELETE FROM plans WHERE id=?", [$pid]);
            flash('Plan deleted.');
            redirect('plans.php');
        }
    }
}

$plans = db_all("SELECT p.*, (SELECT COUNT(*) FROM clients c WHERE c.plan_id=p.id) AS clients
                   FROM plans p ORDER BY sort, price_month");
$get   = fn(string $k, string $d = '') => (string) (db_val("SELECT v FROM app_settings WHERE k=?", [$k]) ?: $d);

layout_header('Plans & pricing', 'admin', 'plans');
?>
<div class="page-head"><h1>Plans &amp; pricing</h1>
  <p class="text-muted">What each tier includes, and how usage converts to money.</p></div>
<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="card">
  <h2 style="margin-top:0">How credits convert to money</h2>
  <form method="post" class="grid2">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_money">
    <div class="field"><span class="lbl">One credit is worth</span>
      <input type="number" step="0.0001" min="0.0001" name="credit_value" value="<?= e($get('credit_value','0.01')) ?>">
      <span class="text-muted" style="font-size:11.5px">In your currency. 0.01 means a credit is one cent.</span></div>
    <div class="field"><span class="lbl">Markup on cost</span>
      <input type="number" step="0.1" min="1" name="billing_markup" value="<?= e($get('billing_markup','1')) ?>">
      <span class="text-muted" style="font-size:11.5px">2 = charge twice what a message costs you. Only applies to clients on your WhatsApp account.</span></div>
    <div><button class="btn btn-primary">Save</button></div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Your WhatsApp &amp; AI accounts</h2>
  <p class="text-muted" style="margin-top:0">Used only by clients you host — the ones sending under your WhatsApp
     account rather than their own, and those whose plan includes AI. Clients with their own
     credentials never touch these, and cost you nothing.</p>
  <form method="post" class="grid2">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_platform">
    <div class="field"><span class="lbl">WhatsApp access token
      <?= $get('wa_platform_token') ? '<span class="pill green">••• set</span>' : '' ?></span>
      <input type="text" name="wa_platform_token" placeholder="<?= $get('wa_platform_token') ? 'Leave blank to keep' : 'System-user token' ?>" autocomplete="off"></div>
    <div class="field"><span class="lbl">WhatsApp phone number ID</span>
      <input type="text" name="wa_platform_phone_id" value="<?= e($get('wa_platform_phone_id')) ?>"></div>
    <div class="field"><span class="lbl">AI provider</span>
      <select name="ai_platform_provider">
        <?php foreach (['' => '— none —', 'claude' => 'Claude', 'openai' => 'OpenAI'] as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= $get('ai_platform_provider') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><span class="lbl">AI key
      <?= $get('ai_platform_key') ? '<span class="pill green">••• set</span>' : '' ?></span>
      <input type="text" name="ai_platform_key" placeholder="<?= $get('ai_platform_key') ? 'Leave blank to keep' : 'sk-…' ?>" autocomplete="off"></div>
    <div class="field"><span class="lbl">AI model</span>
      <input type="text" name="ai_platform_model" value="<?= e($get('ai_platform_model')) ?>" placeholder="leave blank for the default"></div>
    <div><button class="btn btn-primary">Save</button></div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Plans</h2>
  <?php if ($plans): ?>
    <table class="table">
      <thead><tr><th>Plan</th><th>Price</th><th>Credits</th><th>AI</th><th>Limits</th><th>Clients</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($plans as $p): ?>
        <tr>
          <td><strong><?= e((string) $p['name']) ?></strong>
              <span class="text-muted d-block"><?= e((string) $p['code']) ?></span>
              <?= $p['is_active'] ? '' : '<span class="pill">inactive</span>' ?></td>
          <td><?= number_format((float) $p['price_month'], 2) ?> / mo</td>
          <td><?= number_format((int) $p['included_credits']) ?></td>
          <td><?= $p['ai_mode'] === 'included'
                   ? number_format((int) $p['included_ai_credits']) . ' credits included'
                   : '<span class="text-muted">their own key</span>' ?></td>
          <td class="text-muted" style="font-size:12px">
            <?= (int) $p['max_numbers'] ?> number(s) · <?= (int) $p['max_seats'] ?> seat(s)<br>
            <?= $p['max_contacts'] ? number_format((int) $p['max_contacts']) . ' contacts' : 'unlimited contacts' ?></td>
          <td><?= (int) $p['clients'] ?></td>
          <td class="text-right">
            <button class="btn btn-sm btn-ghost" onclick='editPlan(<?= json_encode($p) ?>)'>Edit</button>
            <?php if (!(int) $p['clients']): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this plan?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_plan">
                <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-sm btn-ghost">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="text-muted">No plans yet. Add the first one below.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0" id="form-title">Add a plan</h2>
  <form method="post" id="plan-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_plan">
    <input type="hidden" name="plan_id" id="f-plan_id" value="0">
    <div class="grid3">
      <div class="field"><span class="lbl">Code</span><input type="text" name="code" id="f-code" placeholder="growth" required></div>
      <div class="field"><span class="lbl">Name</span><input type="text" name="name" id="f-name" placeholder="Growth" required></div>
      <div class="field"><span class="lbl">Price per month</span><input type="number" step="0.01" min="0" name="price_month" id="f-price_month" value="0"></div>
      <div class="field"><span class="lbl">Credits included each month</span><input type="number" min="0" name="included_credits" id="f-included_credits" value="0"></div>
      <div class="field"><span class="lbl">Price per extra 1,000 credits</span><input type="number" step="0.01" min="0" name="overage_per_1k" id="f-overage_per_1k" value="0"></div>
      <div class="field"><span class="lbl">Connected numbers</span><input type="number" min="0" name="max_numbers" id="f-max_numbers" value="1">
        <span class="text-muted" style="font-size:11.5px">Each personal number holds a live session on your server.</span></div>
      <div class="field"><span class="lbl">Team seats</span><input type="number" min="1" name="max_seats" id="f-max_seats" value="1"></div>
      <div class="field"><span class="lbl">Contacts</span><input type="number" min="0" name="max_contacts" id="f-max_contacts" value="0">
        <span class="text-muted" style="font-size:11.5px">0 = unlimited</span></div>
      <div class="field"><span class="lbl">Automations</span><input type="number" min="0" name="max_flows" id="f-max_flows" value="0"></div>
      <div class="field"><span class="lbl">AI</span>
        <select name="ai_mode" id="f-ai_mode">
          <option value="byo">Client brings their own key</option>
          <option value="included">Included — uses your key</option>
        </select></div>
      <div class="field"><span class="lbl">AI credits included</span><input type="number" min="0" name="included_ai_credits" id="f-included_ai_credits" value="0">
        <span class="text-muted" style="font-size:11.5px">When these run out, AI steps fall back to their written message.</span></div>
      <div class="field"><label style="display:flex;gap:8px;align-items:center;font-weight:normal;margin-top:22px">
        <input type="checkbox" name="is_active" id="f-is_active" value="1" checked style="width:auto"> Available to assign</label></div>
    </div>
    <div style="display:flex;gap:8px;margin-top:10px">
      <button class="btn btn-primary">Save plan</button>
      <button type="button" class="btn btn-ghost" onclick="resetPlan()">Clear</button>
    </div>
  </form>
</div>

<script>
function editPlan(p){
  document.getElementById('form-title').textContent = 'Edit ' + p.name;
  document.getElementById('f-plan_id').value = p.id;
  ['code','name','price_month','included_credits','overage_per_1k','max_numbers','max_seats',
   'max_contacts','max_flows','ai_mode','included_ai_credits'].forEach(k => {
    const el = document.getElementById('f-'+k); if(el) el.value = p[k];
  });
  document.getElementById('f-is_active').checked = Number(p.is_active) === 1;
  document.getElementById('plan-form').scrollIntoView({behavior:'smooth'});
}
function resetPlan(){
  document.getElementById('form-title').textContent = 'Add a plan';
  document.getElementById('plan-form').reset();
  document.getElementById('f-plan_id').value = 0;
}
</script>
<?php layout_footer(); ?>
