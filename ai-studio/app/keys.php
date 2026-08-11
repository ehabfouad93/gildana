<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_admin();

$specs = studio_key_specs();

// Test a vendor connection (AJAX).
if (($_GET['test'] ?? '') !== '') {
    header('Content-Type: application/json');
    $vendor = (string) $_GET['test'];
    if (!isset($specs[$vendor])) json_out(['ok' => false, 'error' => 'Unknown vendor']);
    $r = adapter_test_vendor($vendor);
    json_out($r);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $vendor = (string) ($_POST['vendor'] ?? '');
    if (!isset($specs[$vendor])) { flash('Unknown vendor.', 'error'); redirect('keys.php'); }

    // Merge with existing so blank secret fields don't wipe stored values.
    $existing = studio_credentials($vendor);
    $creds = [];
    foreach ($specs[$vendor]['fields'] as $name => $f) {
        $val = trim((string) ($_POST[$name] ?? ''));
        if ($val === '' && !empty($f['secret']) && !empty($existing[$name])) {
            $creds[$name] = $existing[$name]; // keep existing secret
        } elseif ($val !== '') {
            $creds[$name] = $val;
        }
    }
    $enc = encrypt_json($creds);
    $has = db_val("SELECT id FROM provider_keys WHERE vendor=?", [$vendor]);
    if ($has) {
        db_run("UPDATE provider_keys SET credentials_enc=?, updated_by=?, updated_at=NOW() WHERE vendor=?", [$enc, $USER['id'], $vendor]);
    } else {
        db_run("INSERT INTO provider_keys (vendor, credentials_enc, updated_by, updated_at) VALUES (?,?,?,NOW())", [$vendor, $enc, $USER['id']]);
    }
    flash(ucfirst($vendor) . ' credentials saved.');
    redirect('keys.php');
}

layout_header('Provider Keys', 'keys');
page_head('Provider Keys', 'Connect the AI providers. Keys are encrypted (AES-256-GCM) and never shown again after saving.');
?>
<div class="keys-grid">
<?php foreach ($specs as $vendor => $spec): $creds = studio_credentials($vendor); ?>
  <form method="post" class="key-card">
    <?= csrf_field() ?>
    <input type="hidden" name="vendor" value="<?= e($vendor) ?>">
    <div class="key-head">
      <strong><?= e($spec['label']) ?></strong>
      <?= vendor_pill($vendor) ?>
    </div>
    <p class="key-help"><?= e($spec['help']) ?></p>
    <?php foreach ($spec['fields'] as $name => $f):
        $set = !empty($creds[$name]);
        $isSecret = !empty($f['secret']); ?>
      <label class="fld">
        <span><?= e($f['label']) ?><?= $set ? ' <em class="set">• set</em>' : '' ?></span>
        <input type="<?= $isSecret ? 'password' : 'text' ?>" name="<?= e($name) ?>"
               placeholder="<?= e($isSecret && $set ? 'Leave blank to keep current' : (string) $f['placeholder']) ?>"
               value="<?= $isSecret ? '' : e((string) ($creds[$name] ?? '')) ?>">
      </label>
    <?php endforeach; ?>
    <div class="key-actions">
      <button class="btn primary" type="submit">Save</button>
      <button class="btn ghost" type="button" onclick="testVendor('<?= e($vendor) ?>',this)">Test connection</button>
      <span class="test-result"></span>
    </div>
  </form>
<?php endforeach; ?>
</div>
<?php layout_footer();
