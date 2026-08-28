<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/billing.php';

/**
 * What Meta charges for a message, by destination country and category.
 *
 * These are maintained here rather than in code on purpose: Meta changes them, and they
 * differ sharply between countries. A hardcoded table would quietly produce wrong invoices.
 * They apply ONLY to clients sending under your WhatsApp account — a client on their own
 * account is billed by Meta directly and never priced from this table.
 */
$err = '';
$CATS = ['marketing', 'utility', 'authentication', 'service'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_rate') {
        $cc  = strtoupper(trim((string) ($_POST['country_code'] ?? ''))) === '*'
             ? '*' : preg_replace('/\D+/', '', (string) ($_POST['country_code'] ?? '')) ?? '';
        $cat = in_array(($_POST['category'] ?? ''), $CATS, true) ? (string) $_POST['category'] : '';
        $from = (string) ($_POST['effective_from'] ?? date('Y-m-d'));
        if ($cc === '' || $cat === '') {
            $err = 'Enter a country prefix (or *) and pick a category.';
        } elseif (!strtotime($from)) {
            $err = 'Enter a valid start date.';
        } else {
            db_run("INSERT INTO wa_rates (country_code,category,cost,currency,effective_from)
                    VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE cost=VALUES(cost), currency=VALUES(currency)",
                [$cc, $cat, max(0, (float) ($_POST['cost'] ?? 0)),
                 strtoupper(substr(trim((string) ($_POST['currency'] ?? 'USD')), 0, 3)) ?: 'USD',
                 date('Y-m-d', strtotime($from))]);
            flash('Rate saved.');
            redirect('rates.php');
        }
    }

    if ($action === 'delete_rate') {
        db_run("DELETE FROM wa_rates WHERE id=?", [(int) ($_POST['rate_id'] ?? 0)]);
        flash('Rate removed.');
        redirect('rates.php');
    }

    if ($action === 'save_ai_rate') {
        $prov = in_array(($_POST['provider'] ?? ''), ['claude','openai'], true) ? (string) $_POST['provider'] : '';
        $model = trim((string) ($_POST['model'] ?? ''));
        if ($prov === '' || $model === '') {
            $err = 'Pick a provider and enter the model name.';
        } else {
            db_run("INSERT INTO ai_rates (provider,model,input_per_mtok,output_per_mtok,currency)
                    VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE input_per_mtok=VALUES(input_per_mtok),
                                            output_per_mtok=VALUES(output_per_mtok), currency=VALUES(currency)",
                [$prov, $model, max(0, (float) ($_POST['input_per_mtok'] ?? 0)),
                 max(0, (float) ($_POST['output_per_mtok'] ?? 0)),
                 strtoupper(substr(trim((string) ($_POST['currency'] ?? 'USD')), 0, 3)) ?: 'USD']);
            flash('AI rate saved.');
            redirect('rates.php');
        }
    }

    if ($action === 'delete_ai_rate') {
        db_run("DELETE FROM ai_rates WHERE id=?", [(int) ($_POST['rate_id'] ?? 0)]);
        flash('AI rate removed.');
        redirect('rates.php');
    }
}

$rates   = db_all("SELECT * FROM wa_rates ORDER BY (country_code='*') ASC, country_code, category, effective_from DESC");
$aiRates = db_all("SELECT * FROM ai_rates ORDER BY provider, model");
$hosted  = (int) db_val("SELECT COUNT(*) FROM clients WHERE waba_mode='platform' AND status='active'");

layout_header('Message rates', 'admin', 'rates');
?>
<div class="page-head"><h1>Message rates</h1>
  <p class="text-muted">What WhatsApp charges you per message. Used to price the
    <strong><?= $hosted ?></strong> client<?= $hosted === 1 ? '' : 's' ?> sending under your account.</p></div>
<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<?php if (!$rates): ?>
  <div class="alert warn">No rates set yet. Until you add them, messages for hosted clients are
    charged a flat credit — which will not cover what WhatsApp bills you.
    Take the current figures from Meta's pricing page for the countries you sell into.</div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0">Add or update a rate</h2>
  <form method="post" class="grid3">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_rate">
    <div class="field"><span class="lbl">Country prefix</span>
      <input type="text" name="country_code" placeholder="20" required>
      <span class="text-muted" style="font-size:11.5px">Dialling code without +. Use <code>*</code> for everywhere else.</span></div>
    <div class="field"><span class="lbl">Category</span>
      <select name="category">
        <?php foreach ($CATS as $c): ?><option value="<?= e($c) ?>"><?= e(ucfirst($c)) ?></option><?php endforeach; ?>
      </select>
      <span class="text-muted" style="font-size:11.5px">Service replies inside 24 hours are free — leave that one at 0.</span></div>
    <div class="field"><span class="lbl">Cost per message</span>
      <input type="number" step="0.000001" min="0" name="cost" value="0" required></div>
    <div class="field"><span class="lbl">Currency</span><input type="text" name="currency" value="USD" maxlength="3"></div>
    <div class="field"><span class="lbl">In effect from</span>
      <input type="date" name="effective_from" value="<?= date('Y-m-d') ?>"></div>
    <div><button class="btn btn-primary" style="margin-top:22px">Save rate</button></div>
  </form>
</div>

<?php if ($rates): ?>
<div class="card">
  <h2 style="margin-top:0">Current rates</h2>
  <table class="table">
    <thead><tr><th>Country</th><th>Category</th><th>Cost</th><th>From</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rates as $r): ?>
      <tr>
        <td><?= $r['country_code'] === '*' ? '<em>everywhere else</em>' : '+' . e((string) $r['country_code']) ?></td>
        <td><?= e(ucfirst((string) $r['category'])) ?></td>
        <td><?= e((string) $r['currency']) ?> <?= rtrim(rtrim(number_format((float) $r['cost'], 6), '0'), '.') ?></td>
        <td class="text-muted"><?= e((string) $r['effective_from']) ?></td>
        <td class="text-right">
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_rate">
            <input type="hidden" name="rate_id" value="<?= (int) $r['id'] ?>">
            <button class="btn btn-sm btn-ghost">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0">AI rates</h2>
  <p class="text-muted" style="margin-top:0">Per million tokens. Only used for clients whose plan
     includes AI from your key — a client on their own key costs you nothing.</p>
  <form method="post" class="grid3">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_ai_rate">
    <div class="field"><span class="lbl">Provider</span>
      <select name="provider"><option value="claude">Claude</option><option value="openai">OpenAI</option></select></div>
    <div class="field"><span class="lbl">Model</span><input type="text" name="model" placeholder="claude-sonnet-4" required></div>
    <div class="field"><span class="lbl">Currency</span><input type="text" name="currency" value="USD" maxlength="3"></div>
    <div class="field"><span class="lbl">Input per 1M tokens</span><input type="number" step="0.0001" min="0" name="input_per_mtok" value="0"></div>
    <div class="field"><span class="lbl">Output per 1M tokens</span><input type="number" step="0.0001" min="0" name="output_per_mtok" value="0"></div>
    <div><button class="btn btn-primary" style="margin-top:22px">Save AI rate</button></div>
  </form>

  <?php if ($aiRates): ?>
    <table class="table mt10">
      <thead><tr><th>Model</th><th>Input / 1M</th><th>Output / 1M</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($aiRates as $r): ?>
        <tr>
          <td><?= e((string) $r['provider']) ?> · <strong><?= e((string) $r['model']) ?></strong></td>
          <td><?= e((string) $r['currency']) ?> <?= number_format((float) $r['input_per_mtok'], 4) ?></td>
          <td><?= e((string) $r['currency']) ?> <?= number_format((float) $r['output_per_mtok'], 4) ?></td>
          <td class="text-right">
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_ai_rate">
              <input type="hidden" name="rate_id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-ghost">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php layout_footer(); ?>
