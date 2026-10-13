<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    /* ── AJAX: verify a saved credential actually works ── */
    if ($action === 'test_ai') {
        $fresh = db_row("SELECT * FROM clients WHERE id = ?", [$cid]);   // re-read: the key may have just been saved
        $r = ai_test_key($fresh ?: []);
        json_out(['ok' => $r['ok'], 'error' => $r['error'] ?: 'Connection failed', 'message' => 'Connected']);
    }

    if ($action === 'test_meta') {
        $fresh = db_row("SELECT * FROM clients WHERE id = ?", [$cid]);
        $token = decrypt_secret((string) ($fresh['meta_token_enc'] ?? ''));
        $page  = trim((string) ($fresh['meta_page_id'] ?? ''));
        if ($token === '' || $page === '') json_out(['ok' => false, 'error' => 'Page ID and token are both required']);
        $v = (string) config('graph_version', 'v21.0');
        $r = lh_http_json('GET', "https://graph.facebook.com/{$v}/" . rawurlencode($page)
            . '?fields=id,name&access_token=' . urlencode($token), [], null, ['timeout' => 15, 'tries' => 1]);
        json_out($r['error'] === ''
            ? ['ok' => true, 'message' => (string) ($r['json']['name'] ?? 'Connected')]
            : ['ok' => false, 'error' => $r['error']]);
    }

    if ($action === 'test_youtube') {
        $fresh = db_row("SELECT * FROM clients WHERE id = ?", [$cid]);
        $key   = decrypt_secret((string) ($fresh['youtube_key_enc'] ?? ''));
        if ($key === '') json_out(['ok' => false, 'error' => 'No key saved']);
        // videos.list costs 1 quota unit; search.list would cost 100 just to test.
        $r = lh_http_json('GET', 'https://www.googleapis.com/youtube/v3/videos?part=id&id=dQw4w9WgXcQ&key='
            . urlencode($key), [], null, ['timeout' => 15, 'tries' => 1]);
        json_out($r['error'] === ''
            ? ['ok' => true, 'message' => 'Connected']
            : ['ok' => false, 'error' => $r['error']]);
    }

    /* ── monitoring credentials ── */
    if ($action === 'save_engine') {
        $provider = (string) ($_POST['ai_provider'] ?? '');
        if (!in_array($provider, ['', 'claude', 'openai'], true)) $provider = '';
        $model = trim((string) ($_POST['ai_model'] ?? ''));
        $cap   = max(0, (int) ($_POST['ai_daily_cap'] ?? 500));

        // Blank means "keep the current secret" — the input is never pre-filled
        // with the stored value, so an empty post must not wipe it.
        $keyEnc = trim((string) ($_POST['ai_api_key'] ?? '')) !== ''
            ? encrypt_secret(trim((string) $_POST['ai_api_key']))
            : $CLIENT['ai_api_key_enc'];

        db_run("UPDATE clients SET ai_provider=?, ai_model=?, ai_api_key_enc=?, ai_daily_cap=? WHERE id=?",
            [$provider, $model, $keyEnc, $cap, $cid]);
        flash(t('ui.saved'));
        redirect('settings.php#engine');
    }

    if ($action === 'save_sources') {
        $vendor = (string) ($_POST['aggregator_vendor'] ?? '');
        if (!in_array($vendor, ['', 'serpapi'], true)) $vendor = '';

        $aggEnc = trim((string) ($_POST['aggregator_key'] ?? '')) !== ''
            ? encrypt_secret(trim((string) $_POST['aggregator_key'])) : $CLIENT['aggregator_key_enc'];
        $ytEnc  = trim((string) ($_POST['youtube_key'] ?? '')) !== ''
            ? encrypt_secret(trim((string) $_POST['youtube_key'])) : $CLIENT['youtube_key_enc'];
        $metaEnc = trim((string) ($_POST['meta_token'] ?? '')) !== ''
            ? encrypt_secret(trim((string) $_POST['meta_token'])) : $CLIENT['meta_token_enc'];

        db_run(
            "UPDATE clients SET aggregator_vendor=?, aggregator_key_enc=?, youtube_key_enc=?,
                    meta_page_id=?, meta_ig_user_id=?, meta_token_enc=?,
                    meta_token_updated_at = CASE WHEN ? <> '' THEN NOW() ELSE meta_token_updated_at END
              WHERE id=?",
            [
                $vendor, $aggEnc, $ytEnc,
                trim((string) ($_POST['meta_page_id'] ?? '')),
                trim((string) ($_POST['meta_ig_user_id'] ?? '')),
                $metaEnc,
                trim((string) ($_POST['meta_token'] ?? '')),
                $cid,
            ]
        );
        flash(t('ui.saved'));
        redirect('settings.php#sources');
    }

    if ($action === 'save_alerts') {
        $email = trim((string) ($_POST['alert_email'] ?? ''));
        $freq  = (string) ($_POST['digest_freq'] ?? 'off');
        if (!in_array($freq, ['off', 'daily', 'weekly'], true)) $freq = 'off';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = t('setup.bad_email');
        } else {
            db_run("UPDATE clients SET alert_email=?, digest_freq=? WHERE id=?", [$email, $freq, $cid]);
            flash(t('ui.saved'));
            redirect('settings.php#alerts');
        }
    }

    /* ── the signed-in user's own account ── */
    if ($action === 'save_account') {
        $email  = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name   = trim((string) ($_POST['name'] ?? ''));
        $locale = (string) ($_POST['locale'] ?? 'en');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = t('setup.bad_email');
        } elseif (db_val("SELECT id FROM users WHERE email=? AND id<>?", [$email, (int) $ME['id']])) {
            $err = t('st.email_taken');
        } else {
            db_run("UPDATE users SET email=?, name=?, locale=? WHERE id=?", [$email, $name, $locale, (int) $ME['id']]);
            $_SESSION['email']  = $email;
            $_SESSION['name']   = $name;
            $_SESSION['locale'] = $locale;
            flash(t('ui.saved'));
            redirect('settings.php');
        }
    }

    if ($action === 'change_password') {
        $user = db_row("SELECT * FROM users WHERE id=?", [(int) $ME['id']]);
        $cur  = (string) ($_POST['current_password'] ?? '');
        $new  = (string) ($_POST['new_password'] ?? '');
        if (!$user || !password_verify($cur, (string) $user['password_hash'])) {
            $err = t('st.pass_wrong');
        } elseif (strlen($new) < 8) {
            $err = t('setup.short_pass');
        } else {
            db_run("UPDATE users SET password_hash=? WHERE id=?",
                [password_hash($new, PASSWORD_DEFAULT), (int) $ME['id']]);
            flash(t('st.pass_changed'));
            redirect('settings.php');
        }
    }
}

$CLIENT = db_row("SELECT * FROM clients WHERE id = ?", [$cid]) ?: $CLIENT;
$user   = db_row("SELECT * FROM users WHERE id = ?", [(int) $ME['id']]) ?: [];

/** Badge + placeholder for a stored secret, which is never re-rendered. */
function key_state(?string $enc): array
{
    $set = trim((string) $enc) !== '';
    return [
        $set ? '<span class="pill green" style="margin-inline-start:6px">' . e(t('st.key_set')) . '</span>' : '',
        $set ? t('st.key_keep') : t('st.key_paste'),
    ];
}

client_header(t('nav.settings'), 'settings', $CLIENT);
page_head(t('st.title'));

if ($err !== '') echo '<div class="alert error">' . e($err) . '</div>';
?>

<div class="card" id="engine">
  <h2><?= e(t('st.ai')) ?></h2>
  <p class="text-muted" style="font-size:12.5px;margin-bottom:14px"><?= e(t('st.ai_sub')) ?></p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_engine">
    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('st.provider')) ?></span>
        <select name="ai_provider">
          <option value=""     <?= (string) $CLIENT['ai_provider'] === ''       ? 'selected' : '' ?>><?= e(t('ui.none')) ?></option>
          <option value="claude" <?= (string) $CLIENT['ai_provider'] === 'claude' ? 'selected' : '' ?>>Claude (Anthropic)</option>
          <option value="openai" <?= (string) $CLIENT['ai_provider'] === 'openai' ? 'selected' : '' ?>>OpenAI</option>
        </select>
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('st.model')) ?></span>
        <input type="text" name="ai_model" value="<?= e((string) $CLIENT['ai_model']) ?>"
               placeholder="<?= e(AI_DEFAULT_CLAUDE_MODEL) ?>">
      </div>
    </div>
    <?php [$badge, $ph] = key_state($CLIENT['ai_api_key_enc']); ?>
    <div class="field">
      <span class="lbl"><?= e(t('st.api_key')) ?> <?= $badge ?></span>
      <input type="text" name="ai_api_key" autocomplete="off" placeholder="<?= e($ph) ?>">
      <span class="hint"><?= e(t('st.key_note')) ?></span>
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('st.daily_cap')) ?></span>
      <input type="number" name="ai_daily_cap" min="0" value="<?= (int) $CLIENT['ai_daily_cap'] ?>">
      <span class="hint">Used today: <?= (string) $CLIENT['ai_calls_day'] === date('Y-m-d')
          ? (int) $CLIENT['ai_calls_today'] : 0 ?></span>
    </div>
    <div class="page-actions">
      <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
      <button type="button" class="btn" data-test="test_ai" data-test-url="settings.php" data-test-out="ai-test">
        <?= e(t('ui.test')) ?></button>
      <span id="ai-test"></span>
    </div>
  </form>
</div>

<div class="card" id="sources">
  <h2><?= e(t('nav.sources')) ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_sources">

    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('st.aggregator')) ?></span>
        <select name="aggregator_vendor">
          <option value=""       <?= (string) $CLIENT['aggregator_vendor'] === ''        ? 'selected' : '' ?>><?= e(t('ui.none')) ?></option>
          <option value="serpapi" <?= (string) $CLIENT['aggregator_vendor'] === 'serpapi' ? 'selected' : '' ?>>SerpApi</option>
        </select>
      </div>
      <?php [$badge, $ph] = key_state($CLIENT['aggregator_key_enc']); ?>
      <div class="field">
        <span class="lbl">SerpApi key <?= $badge ?></span>
        <input type="text" name="aggregator_key" autocomplete="off" placeholder="<?= e($ph) ?>">
      </div>
    </div>

    <?php [$badge, $ph] = key_state($CLIENT['youtube_key_enc']); ?>
    <div class="field">
      <span class="lbl"><?= e(t('st.youtube')) ?> <?= $badge ?></span>
      <input type="text" name="youtube_key" autocomplete="off" placeholder="<?= e($ph) ?>">
      <span class="hint">A search costs 100 of the 10,000 free daily quota units.</span>
    </div>

    <h2 style="margin-top:20px"><?= e(t('st.meta')) ?></h2>
    <p class="text-muted" style="font-size:12.5px;margin-bottom:12px"><?= e(t('st.meta_note')) ?></p>
    <div class="grid2">
      <div class="field">
        <span class="lbl">Facebook Page ID</span>
        <input type="text" name="meta_page_id" value="<?= e((string) $CLIENT['meta_page_id']) ?>">
      </div>
      <div class="field">
        <span class="lbl">Instagram User ID</span>
        <input type="text" name="meta_ig_user_id" value="<?= e((string) $CLIENT['meta_ig_user_id']) ?>">
      </div>
    </div>
    <?php [$badge, $ph] = key_state($CLIENT['meta_token_enc']); ?>
    <div class="field">
      <span class="lbl">Long-lived access token <?= $badge ?></span>
      <input type="text" name="meta_token" autocomplete="off" placeholder="<?= e($ph) ?>">
      <?php if ($CLIENT['meta_token_updated_at']): ?>
        <span class="hint">Saved <?= e(time_ago((string) $CLIENT['meta_token_updated_at'])) ?>.
          Long-lived tokens expire after about 60 days.</span>
      <?php endif; ?>
    </div>

    <div class="page-actions">
      <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
      <button type="button" class="btn" data-test="test_meta" data-test-url="settings.php" data-test-out="meta-test">
        <?= e(t('ui.test')) ?> (Meta)</button>
      <span id="meta-test"></span>
      <button type="button" class="btn" data-test="test_youtube" data-test-url="settings.php" data-test-out="yt-test">
        <?= e(t('ui.test')) ?> (YouTube)</button>
      <span id="yt-test"></span>
    </div>
  </form>
</div>

<div class="card" id="alerts">
  <h2><?= e(t('nav.alerts')) ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_alerts">
    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('st.alert_email')) ?></span>
        <input type="email" name="alert_email" value="<?= e((string) $CLIENT['alert_email']) ?>">
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('rp.digest')) ?></span>
        <select name="digest_freq">
          <?php foreach (['off', 'daily', 'weekly'] as $f): ?>
            <option value="<?= e($f) ?>" <?= (string) $CLIENT['digest_freq'] === $f ? 'selected' : '' ?>>
              <?= e(t('rp.digest.' . $f)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
  </form>
</div>

<div class="card">
  <h2><?= e(t('st.account')) ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_account">
    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('ui.name')) ?></span>
        <input type="text" name="name" value="<?= e((string) ($user['name'] ?? '')) ?>">
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('auth.email')) ?></span>
        <input type="email" name="email" value="<?= e((string) ($user['email'] ?? '')) ?>" required>
      </div>
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('ui.language')) ?></span>
      <select name="locale">
        <?php foreach (locales() as $code => $meta): ?>
          <option value="<?= e($code) ?>" <?= (string) ($user['locale'] ?? 'en') === $code ? 'selected' : '' ?>>
            <?= e($meta['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
  </form>

  <hr style="margin:22px 0;border:none;border-top:1px solid var(--line)">

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('st.current_pass')) ?></span>
        <input type="password" name="current_password" required>
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('st.new_pass')) ?></span>
        <input type="password" name="new_password" required minlength="8">
      </div>
    </div>
    <button class="btn" type="submit"><?= e(t('st.change_pass')) ?></button>
  </form>
</div>

<?php layout_footer(); ?>
