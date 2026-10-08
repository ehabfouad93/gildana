<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/social_campaigns.php';

/**
 * A campaign on Messenger or Instagram. Two audiences, both inside Meta's rules:
 * people who wrote in the last 24 hours, or (Messenger) people who agreed to receive offers.
 */
$cid = (int) $CLIENT['id'];
$channels = array_values(array_filter(['messenger', 'instagram'], fn($c) => client_has_channel($CLIENT, $c)));
$hasMM = client_has_channel($CLIENT, 'social_mm');
if (!$channels) { http_response_code(403); exit('Messenger and Instagram are not part of your plan.'); }
$pages = db_all("SELECT * FROM meta_pages WHERE client_id=? ORDER BY name", [$cid]);
$lists = db_all("SELECT id, name FROM contact_lists WHERE client_id=? ORDER BY name", [$cid]);

/* ── AJAX: how many people this audience reaches right now ── */
if (isset($_GET['count'])) {
    $ch = in_array($_GET['channel'] ?? '', $channels, true) ? (string) $_GET['channel'] : $channels[0];
    $kind = ($_GET['kind'] ?? '') === 'optin' ? 'optin' : 'window24';
    json_out(['count' => count(social_campaign_audience($CLIENT, $ch, $kind, (string) ($_GET['page'] ?? ''), (int) ($_GET['list'] ?? 0)))]);
}

$err = '';
$f = ['name' => '', 'channel' => $channels[0], 'kind' => 'window24', 'page_id' => (string) ($pages[0]['page_id'] ?? ''), 'list_id' => 0,
      'text' => '', 'image' => '', 'choices' => ['', '', ''], 'when' => 'now', 'scheduled_at' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!can_write()) { http_response_code(403); exit('Your role cannot send campaigns.'); }
    $f = [
        'name'    => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 190),
        'channel' => in_array($_POST['channel'] ?? '', $channels, true) ? (string) $_POST['channel'] : $channels[0],
        'kind'    => ($_POST['kind'] ?? '') === 'optin' ? 'optin' : 'window24',
        'page_id' => (string) ($_POST['page_id'] ?? ''),
        'list_id' => (int) ($_POST['list_id'] ?? 0),
        'text'    => trim((string) ($_POST['text'] ?? '')),
        'image'   => trim((string) ($_POST['image'] ?? '')),
        'choices' => array_map(fn($c) => mb_substr(trim((string) $c), 0, 20), array_slice(array_pad((array) ($_POST['choices'] ?? []), 3, ''), 0, 3)),
        'when'    => ($_POST['when'] ?? '') === 'later' ? 'later' : 'now',
        'scheduled_at' => trim((string) ($_POST['scheduled_at'] ?? '')),
    ];
    if ($f['kind'] === 'optin' && ($f['channel'] !== 'messenger' || !$hasMM)) $err = 'Offers to people who agreed are a Messenger feature of Marketing Messages.';
    if ($f['list_id'] && !db_val("SELECT 1 FROM contact_lists WHERE id=? AND client_id=?", [$f['list_id'], $cid])) $f['list_id'] = 0;
    if ($f['image'] !== '' && !preg_match('~^https://~i', $f['image'])) $err = 'The picture must be a public https:// link.';
    $sched = null;
    if ($f['when'] === 'later') {
        $ts = strtotime($f['scheduled_at']);
        if ($ts === false || $ts < time() - 60) $err = 'Choose a valid future date & time.';
        elseif ($f['kind'] === 'window24') $err = 'A 24-hour audience changes by the hour, so it can only be sent now.';
        else $sched = date('Y-m-d H:i:s', $ts);
    }
    if ($f['name'] === '') $err = $err ?: 'Enter a campaign name.';
    if ($f['text'] === '') $err = $err ?: 'Write the message.';
    if ($err === '') {
        [$campId, $err] = social_campaign_create($CLIENT, $f + ['scheduled_at' => $sched]);
        if ($campId) {
            if (!$sched) trigger_worker();
            flash($sched ? 'Campaign scheduled.' : 'Campaign is sending.');
            redirect('report.php?id=' . $campId);
        }
    }
}

$audCount = $f['page_id'] !== '' ? count(social_campaign_audience($CLIENT, $f['channel'], $f['kind'], $f['page_id'], (int) $f['list_id'])) : 0;
$optins = $hasMM ? (int) db_val("SELECT COUNT(*) FROM social_optins WHERE client_id=? AND status='active'", [$cid]) : 0;

client_header('New Messenger / Instagram campaign', 'campaigns', $CLIENT);
page_head('New Messenger / Instagram campaign', '<a class="btn btn-ghost btn-sm" href="campaigns.php">← Campaigns</a>');
?>
<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
<?php if (!$pages): ?>
  <div class="card"><div class="empty">Connect your Facebook Page first in <a href="meta_leads.php#social">CRM → Facebook &amp; Instagram</a>.</div></div>
<?php else: ?>
<form method="post" class="card" id="sc-form">
  <?= csrf_field() ?>
  <div class="grid3">
    <div class="field"><span class="lbl">Campaign name</span><input type="text" name="name" value="<?= e($f['name']) ?>" maxlength="190" required placeholder="October offer"></div>
    <div class="field"><span class="lbl">Send on</span><select name="channel" id="sc-ch">
      <?php foreach ($channels as $c): ?><option value="<?= $c ?>" <?= $f['channel'] === $c ? 'selected' : '' ?>><?= $c === 'messenger' ? 'Messenger' : 'Instagram' ?></option><?php endforeach; ?></select></div>
    <div class="field"><span class="lbl">From the Page</span><select name="page_id" id="sc-page">
      <?php foreach ($pages as $p): ?><option value="<?= e((string) $p['page_id']) ?>" <?= $f['page_id'] === (string) $p['page_id'] ? 'selected' : '' ?>><?= e((string) $p['name']) ?><?= $p['ig_username'] ? ' · @' . e((string) $p['ig_username']) : '' ?></option><?php endforeach; ?></select></div>
  </div>

  <fieldset class="sc-aud"><legend class="lbl">Who receives it</legend>
    <label class="sc-opt"><input type="radio" name="kind" value="window24" <?= $f['kind'] === 'window24' ? 'checked' : '' ?>>
      <span><strong>People who wrote in the last 24 hours</strong><small>Meta allows any message within 24 hours of their last message. Sent now only.</small></span></label>
    <?php if ($hasMM): ?>
      <label class="sc-opt" id="sc-opt-optin"><input type="radio" name="kind" value="optin" <?= $f['kind'] === 'optin' ? 'checked' : '' ?>>
        <span><strong>People who agreed to receive offers</strong> <span class="pill gray"><?= $optins ?></span><small>Messenger Marketing Messages. Each person gets at most one per the frequency they chose (daily / weekly / monthly). Ask for it from a Messenger conversation in the Inbox ("Ask to receive offers").</small></span></label>
    <?php endif; ?>
    <div class="field" style="max-width:320px;margin-top:8px"><span class="lbl">Only people in a list <span class="text-muted">(optional)</span></span>
      <select name="list_id" id="sc-list"><option value="0">Everyone in the audience</option>
        <?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) $f['list_id'] === (int) $l['id'] ? 'selected' : '' ?>><?= e((string) $l['name']) ?></option><?php endforeach; ?></select></div>
    <p class="sc-count">Reaches <strong id="sc-n"><?= $audCount ?></strong> <span id="sc-n-l"><?= $audCount === 1 ? 'person' : 'people' ?></span> right now · 1 credit each</p>
  </fieldset>

  <div class="field"><span class="lbl">Message <span class="text-muted">(<code>{{name}}</code> is their name)</span></span>
    <textarea name="text" rows="4" maxlength="2000" required placeholder="Hi {{name}}, this week only: 10% off units in New Cairo."><?= e($f['text']) ?></textarea></div>
  <div class="grid2">
    <div class="field"><span class="lbl">Picture <span class="text-muted">(optional, https link — sent before the words)</span></span><input type="url" name="image" value="<?= e($f['image']) ?>" placeholder="https://…/offer.jpg"></div>
    <div class="field"><span class="lbl">Quick replies <span class="text-muted">(optional, up to 3, 20 characters)</span></span>
      <div style="display:flex;gap:6px"><?php foreach ($f['choices'] as $i => $c): ?><input type="text" name="choices[]" value="<?= e($c) ?>" maxlength="20" placeholder="<?= ['I\'m interested', 'Call me', 'Not now'][$i] ?>"><?php endforeach; ?></div></div>
  </div>
  <div class="field"><span class="lbl">When</span>
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
      <label class="mod-opt"><input type="radio" name="when" value="now" <?= $f['when'] === 'now' ? 'checked' : '' ?>> Send now</label>
      <label class="mod-opt" id="sc-later"><input type="radio" name="when" value="later" <?= $f['when'] === 'later' ? 'checked' : '' ?>> Schedule</label>
      <input type="datetime-local" name="scheduled_at" id="sc-at" value="<?= e($f['scheduled_at']) ?>" style="width:auto">
    </div></div>
  <div style="display:flex;gap:8px"><button class="btn btn-primary" onclick="return confirm('Send this campaign to ' + document.getElementById('sc-n').textContent + ' people?')">Send campaign</button>
    <a class="btn btn-ghost" href="campaigns.php">Cancel</a></div>
</form>
<script>
(function () {
  var form = document.getElementById('sc-form'), n = document.getElementById('sc-n'), nl = document.getElementById('sc-n-l');
  var optin = document.getElementById('sc-opt-optin'), later = document.getElementById('sc-later');
  function sync() {
    var ch = form.channel.value, kind = form.querySelector('input[name=kind]:checked');
    if (optin) { optin.style.display = ch === 'messenger' ? '' : 'none'; if (ch !== 'messenger' && kind && kind.value === 'optin') { form.querySelector('input[value=window24]').checked = true; } }
    kind = form.querySelector('input[name=kind]:checked');
    later.style.display = document.getElementById('sc-at').style.display = kind && kind.value === 'optin' ? '' : 'none';
    if (!(kind && kind.value === 'optin')) form.querySelector('input[name=when][value=now]').checked = true;
    var q = new URLSearchParams({count: 1, channel: ch, kind: kind ? kind.value : 'window24', page: form.page_id.value, list: form.list_id.value});
    fetch('campaign_social.php?' + q).then(function (r) { return r.json(); }).then(function (d) { n.textContent = d.count; nl.textContent = d.count === 1 ? 'person' : 'people'; });
  }
  form.addEventListener('change', function (e) { if (['channel', 'kind', 'page_id', 'list_id'].indexOf(e.target.name) >= 0) sync(); });
  sync();
})();
</script>
<?php endif; ?>
<?php layout_footer(); ?>
