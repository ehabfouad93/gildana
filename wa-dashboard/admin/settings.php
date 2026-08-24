<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/push.php';
require_once __DIR__ . '/../assets/icons/generate.php';   // icons_build()

$me  = current_user();
$err = ''; $ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $user = db_row("SELECT * FROM users WHERE id=?", [(int) $me['id']]);

    // Generate the VAPID keypair from the browser — the client deploys by ZIP and never
    // edits PHP, so there is no practical way to run a CLI script.
    if ($action === 'gen_vapid') {
        $keys = push_vapid_keys(true);
        if ($keys) { $ok = 'Push notification keys generated. Clients can now enable notifications.'; }
        else { $err = 'Could not generate keys — this server\'s PHP is missing EC support in OpenSSL.'; }
    }

    /* ── Branding: upload / remove artwork ──
       Files live in assets/brand/ and are picked up by brand_logo(). Uploading here and
       dropping the same filenames in over FTP are equivalent — nothing is stored in the DB. */
    if ($action === 'upload_brand') {
        $slot = (string) ($_POST['slot'] ?? '');
        $bases = ['logo' => 'logo', 'logo_light' => 'logo-light', 'icon' => 'icon-source'];
        if (!isset($bases[$slot])) {
            $err = 'Unknown upload.';
        } elseif (empty($_FILES['file']['tmp_name']) || (int) ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $err = 'No file received (it may exceed the server upload limit).';
        } else {
            $tmp  = (string) $_FILES['file']['tmp_name'];
            $name = (string) $_FILES['file']['name'];
            $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            if ($ext === 'jpeg') $ext = 'jpg';
            $allowed = $slot === 'icon' ? ['png', 'jpg', 'webp'] : ['svg', 'png', 'webp', 'jpg'];
            if (!in_array($ext, $allowed, true)) {
                $err = $slot === 'icon'
                    ? 'The app icon must be a PNG, JPG or WEBP — an SVG cannot be resized into icon files.'
                    : 'Use an SVG, PNG, WEBP or JPG file.';
            } elseif ((int) $_FILES['file']['size'] > 3 * 1024 * 1024) {
                $err = 'That file is over 3 MB — please use a smaller one.';
            } elseif ($ext !== 'svg' && !@getimagesize($tmp)) {
                // Catches a script renamed to .png.
                $err = 'That file is not a readable image.';
            } elseif ($ext === 'svg' && !preg_match('~^\s*(<\?xml|<svg)~i', (string) file_get_contents($tmp, false, null, 0, 512))) {
                $err = 'That does not look like an SVG file.';
            } else {
                $dir = brand_dir_path();
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                if (!is_dir($dir) || !is_writable($dir)) {
                    $err = 'The folder wa-dashboard/assets/brand is not writable. Create it and set its permissions to 755, then try again.';
                } else {
                    // Drop other extensions for this slot so switching formats can't leave two files.
                    foreach (BRAND_LOGO_EXT as $e) @unlink("$dir/{$bases[$slot]}.$e");
                    if (!@move_uploaded_file($tmp, "$dir/{$bases[$slot]}.$ext")) {
                        $err = 'Could not save the file.';
                    } elseif ($slot === 'icon') {
                        $made = icons_build("$dir/{$bases[$slot]}.$ext");
                        $ok = 'App icon updated — ' . count($made) . ' icon files regenerated. Installed phones refresh it within a day or after reinstalling.';
                    } else {
                        $ok = 'Logo updated.';
                    }
                }
            }
        }
    }

    if ($action === 'remove_brand') {
        $bases = ['logo' => 'logo', 'logo_light' => 'logo-light', 'icon' => 'icon-source'];
        $slot  = (string) ($_POST['slot'] ?? '');
        if (isset($bases[$slot])) {
            foreach (BRAND_LOGO_EXT as $e) @unlink(brand_dir_path() . "/{$bases[$slot]}.$e");
            if ($slot === 'icon') { icons_build(null); $ok = 'App icon reset to the built-in Revenect mark.'; }
            else { $ok = 'Logo removed — the built-in Revenect logo is back.'; }
        }
    }

    if ($action === 'save_gateway') {
        setting_set('pw_base_url', trim((string) ($_POST['pw_base_url'] ?? '')));
        setting_set('pw_hook_base', trim((string) ($_POST['pw_hook_base'] ?? '')));
        $k = trim((string) ($_POST['pw_api_key'] ?? ''));
        if ($k !== '') setting_set('pw_api_key', encrypt_secret($k));   // blank = keep current
        setting_set('pw_auth_header', trim((string) ($_POST['pw_auth_header'] ?? 'apikey')) ?: 'apikey');
        flash('WhatsApp gateway settings saved.');
        redirect('settings.php#gateway');
    }

    if ($action === 'update_email') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email.';
        elseif ($email !== $user['email'] && db_val("SELECT COUNT(*) FROM users WHERE email=? AND id<>?", [$email, (int) $me['id']])) $err = 'That email is already in use.';
        else { db_run("UPDATE users SET email=? WHERE id=?", [$email, (int) $me['id']]); $_SESSION['email'] = $email; flash('Email updated.'); redirect('settings.php'); }
    }

    if ($action === 'change_password') {
        $cur  = (string) ($_POST['current_password'] ?? '');
        $new  = (string) ($_POST['new_password'] ?? '');
        $conf = (string) ($_POST['confirm_password'] ?? '');
        if (!password_verify($cur, $user['password_hash'])) $err = 'Current password is incorrect.';
        elseif (strlen($new) < 8) $err = 'New password must be at least 8 characters.';
        elseif ($new !== $conf) $err = 'New passwords do not match.';
        else { db_run("UPDATE users SET password_hash=? WHERE id=?", [password_hash($new, PASSWORD_DEFAULT), (int) $me['id']]); flash('Password changed.'); redirect('settings.php'); }
    }
}

$user = db_row("SELECT * FROM users WHERE id=?", [(int) $me['id']]);

layout_header('Settings', 'admin', 'settings');
page_head('My Settings');
// $ok was being set in several places but never rendered — the "keys generated"
// confirmation never appeared either.
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif;
if ($ok):  ?><div class="alert success"><?= e($ok) ?></div><?php endif; ?>

<div class="card">
  <h2>Profile</h2>
  <form method="post" style="max-width:420px">
    <?= csrf_field() ?><input type="hidden" name="action" value="update_email">
    <div class="field"><span class="lbl">Admin Email</span><input type="email" name="email" value="<?= e((string) $user['email']) ?>" required></div>
    <button class="btn btn-primary">Save Email</button>
  </form>
</div>

<div class="card">
  <h2>Branding</h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">
    Upload your own logo and app icon. You can also upload the same filenames straight into
    <span class="mono">wa-dashboard/assets/brand/</span> over FTP — both work the same way.
    Leave these empty to keep the built-in Revenect artwork.
  </p>
  <?php
    $slots = [
      'logo'       => ['Logo', 'Shown in the top bar and on the sign-in screen. Wide artwork works best (about 4:1). SVG or transparent PNG.', 'logo'],
      'logo_light' => ['Logo for dark backgrounds', 'Optional. The sign-in screen is near-black, so a dark logo would disappear there.', 'logo-light'],
      'icon'       => ['App icon', 'One square image, 512×512 or larger, PNG/JPG/WEBP. Regenerates every phone and browser icon.', 'icon-source'],
    ];
    foreach ($slots as $slot => [$label, $hint, $base]):
      $cur = brand_find($base);
  ?>
    <div style="display:flex;gap:16px;align-items:flex-start;padding:14px 0;border-top:1px solid var(--line)">
      <div style="width:96px;flex-shrink:0;display:flex;align-items:center;justify-content:center;min-height:56px;background:<?= $slot === 'logo_light' ? 'var(--ink)' : 'var(--bg)' ?>;border-radius:8px;padding:8px">
        <?php if ($cur): ?>
          <img src="../assets/brand/<?= e($cur) ?>?v=<?= (int) @filemtime(brand_dir_path() . '/' . $cur) ?>" style="max-width:100%;max-height:44px" alt="<?= e($label) ?>">
        <?php else: ?>
          <span class="text-muted" style="font-size:11px">built-in</span>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;font-size:13.5px"><?= e($label) ?></div>
        <div class="text-muted" style="font-size:12px;margin-bottom:8px"><?= $hint ?></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_brand">
            <input type="hidden" name="slot" value="<?= e($slot) ?>">
            <input type="file" name="file" required accept="<?= $slot === 'icon' ? '.png,.jpg,.jpeg,.webp' : '.svg,.png,.webp,.jpg,.jpeg' ?>" style="max-width:230px">
            <button class="btn btn-primary btn-sm">Upload</button>
          </form>
          <?php if ($cur): ?>
            <form method="post" onsubmit="return confirm('Remove this and go back to the built-in artwork?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove_brand">
              <input type="hidden" name="slot" value="<?= e($slot) ?>">
              <button class="btn btn-ghost btn-sm">Remove</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2>Push Notifications</h2>
  <?php
    $vk = push_vapid_keys(false);
    $subCount = 0;
    try { $subCount = (int) db_val("SELECT COUNT(*) FROM push_subscriptions"); } catch (Throwable $e) {}
  ?>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">
    One keypair per installation. Clients then turn notifications on per device in their own Settings.
  </p>
  <?php if ($vk): ?>
    <div class="alert success" style="margin-bottom:12px">
      Keys are set up · <strong><?= $subCount ?></strong> device<?= $subCount === 1 ? '' : 's' ?> subscribed.
    </div>
    <div class="field"><span class="lbl">Public key</span>
      <input type="text" class="mono" value="<?= e($vk['public']) ?>" readonly onclick="this.select()"></div>
    <p class="text-muted" style="font-size:12px">The private key is stored encrypted and never shown.</p>
  <?php else: ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="gen_vapid">
      <button type="submit" class="btn btn-primary">Generate keys</button>
    </form>
    <p class="text-muted" style="font-size:12px;margin-top:8px">Run this once. Regenerating later would silently break every device already subscribed.</p>
  <?php endif; ?>
</div>

<div class="card" id="gateway">
  <h2>Personal-Number Gateway</h2>
  <?php
    require_once __DIR__ . '/../includes/personal_wa.php';
    $gwBase = (string) (setting_get('pw_base_url', '') ?? '');
    $gwHook = (string) (setting_get('pw_hook_base', '') ?? '');
    $gwHdr  = (string) (setting_get('pw_auth_header', 'apikey') ?? 'apikey');
    $gwKey  = decrypt_secret((string) (setting_get('pw_api_key', '') ?? ''));
    $gwCount = 0;
    try { $gwCount = (int) db_val("SELECT COUNT(*) FROM clients WHERE channel='personal'"); } catch (Throwable $e) {}
  ?>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">
    Lets a client send from their <strong>own WhatsApp number</strong> instead of the Cloud API.
    Set once here for the whole platform — clients never see these details, they just scan a QR
    in their own Settings. <strong><?= $gwCount ?></strong> account<?= $gwCount === 1 ? '' : 's' ?> currently on this channel.
  </p>
  <div class="alert info" style="font-size:12.5px;margin-bottom:14px">
    Sending from a personal number is against WhatsApp's Terms and the number can be banned.
    Every send on this channel is paced (a small batch, then a pause) to reduce that risk — the
    limits are set per client. Point the base URL at <code>http://127.0.0.1:3000</code> when the
    gateway runs on this server: it holds your clients' live WhatsApp sessions and should not be
    reachable from the internet.
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_gateway">
    <div class="grid2">
      <div class="field"><span class="lbl">Gateway base URL</span>
        <input type="text" name="pw_base_url" value="<?= e($gwBase) ?>" placeholder="http://127.0.0.1:3000"></div>
      <div class="field"><span class="lbl">Auth header</span>
        <input type="text" name="pw_auth_header" value="<?= e($gwHdr) ?>" placeholder="apikey">
        <span class="text-muted" style="font-size:11.5px">Header name the gateway expects, or <code>bearer</code>.</span></div>
      <div class="field"><span class="lbl">API key <?= $gwKey !== '' ? '<span class="pill green" style="margin-left:6px">••• set</span>' : '' ?></span>
        <input type="text" name="pw_api_key" autocomplete="off" placeholder="<?= $gwKey !== '' ? 'Leave blank to keep current' : 'Gateway API key' ?>"></div>
      <div class="field"><span class="lbl">Callback base URL <span class="text-muted">(optional)</span></span>
        <input type="text" name="pw_hook_base" value="<?= e($gwHook) ?>" placeholder="<?= e(app_base_url()) ?>">
        <span class="text-muted" style="font-size:11.5px">Where the gateway calls back to deliver
          incoming messages. Blank = this site's public URL. If the gateway runs as a container
          beside the dashboard, set the internal name (e.g. <code>http://revenect</code>) — the
          public URL makes its callback leave the server and hairpin back through the proxy,
          which many Docker setups drop silently and inbound messages just never arrive.</span></div>
    </div>
    <button type="submit" class="btn btn-primary">Save gateway</button>
  </form>
</div>

<div class="card">
  <h2>Change Password</h2>
  <form method="post" style="max-width:420px">
    <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
    <div class="field"><span class="lbl">Current Password</span><input type="password" name="current_password" required></div>
    <div class="field"><span class="lbl">New Password (min 8)</span><input type="password" name="new_password" required minlength="8"></div>
    <div class="field"><span class="lbl">Confirm New Password</span><input type="password" name="confirm_password" required></div>
    <button class="btn btn-primary">Change Password</button>
  </form>
</div>

<?php layout_footer(); ?>
