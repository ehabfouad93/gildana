<?php
declare(strict_types=1);

/**
 * The signed-in person's own name and picture.
 *
 * Shared by the admin and client Settings pages: the upload rules (what a picture may be, how
 * big, where it lands) should not drift between the two, and an avatar is one of the easier
 * places to smuggle a script onto a server if each page invents its own checks.
 */

function avatar_dir(): string { return dirname(__DIR__) . '/uploads/avatars'; }

/**
 * Save the display name, and the picture when one was uploaded.
 * Returns ['ok'=>bool, 'error'=>string]. A bad picture never silently drops the name.
 */
function profile_save(int $userId, array $post, array $files): array
{
    $name = trim((string) ($post['display_name'] ?? ''));
    if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);
    db_run("UPDATE users SET name=? WHERE id=?", [$name, $userId]);

    $f = $files['avatar'] ?? null;
    if (!$f || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'error' => ''];          // name only — nothing else to do
    }
    if ((int) $f['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'The picture did not upload (it may exceed the server limit).'];
    }
    if ((int) $f['size'] > 2 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'That picture is over 2 MB — please use a smaller one.'];
    }

    // Trust the decoded image, not the filename: getimagesize() is what tells a real PNG from
    // a script that has simply been named like one.
    $info = @getimagesize((string) $f['tmp_name']);
    $ext  = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
    if ($ext === null) {
        return ['ok' => false, 'error' => 'Use a PNG, JPG, WEBP or GIF image.'];
    }

    $dir = avatar_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'error' => 'Could not create uploads/avatars — check the folder permissions.'];
    }
    // Name it after the user so one account can only ever hold one avatar, and stamp it so a
    // replacement is not hidden behind a cached copy of the old one.
    foreach (['png', 'jpg', 'webp', 'gif'] as $e) @unlink("$dir/u{$userId}.$e");
    $file = "u{$userId}.$ext";
    if (!@move_uploaded_file((string) $f['tmp_name'], "$dir/$file")) {
        return ['ok' => false, 'error' => 'Could not save the picture.'];
    }
    @chmod("$dir/$file", 0644);
    db_run("UPDATE users SET avatar=? WHERE id=?", [$file, $userId]);
    return ['ok' => true, 'error' => ''];
}

/** Remove the picture, keeping the name. */
function profile_clear_avatar(int $userId): void
{
    $dir = avatar_dir();
    foreach (['png', 'jpg', 'webp', 'gif'] as $e) @unlink("$dir/u{$userId}.$e");
    db_run("UPDATE users SET avatar=NULL WHERE id=?", [$userId]);
}

/** The profile card, identical on both Settings pages. */
function profile_card_html(array $user, string $base = './'): string
{
    ob_start(); ?>
<div class="card" id="profile">
  <h2>Your Profile</h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">
    Your name and picture appear in the top bar. Both are optional — without a picture you get
    your initials.
  </p>
  <form method="post" enctype="multipart/form-data" style="max-width:520px">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_profile">
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
      <?= user_avatar_html($user, 64, $base) ?>
      <div class="field" style="flex:1;min-width:200px;margin:0">
        <span class="lbl">Picture</span>
        <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif">
        <span class="text-muted" style="font-size:11.5px">PNG, JPG, WEBP or GIF · up to 2 MB</span>
      </div>
    </div>
    <div class="field"><span class="lbl">Display name</span>
      <input type="text" name="display_name" value="<?= e((string) ($user['name'] ?? '')) ?>" placeholder="<?= e(user_display_name($user)) ?>" maxlength="120">
    </div>
    <div class="row-between mt10">
      <?php if (!empty($user['avatar'])): ?>
        <button class="btn btn-ghost btn-sm" name="action" value="clear_avatar">Remove picture</button>
      <?php else: ?><span></span><?php endif; ?>
      <button class="btn btn-primary">Save profile</button>
    </div>
  </form>
</div>
<?php
    return (string) ob_get_clean();
}
