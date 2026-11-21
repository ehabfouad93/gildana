<?php
/**
 * AI Chat Agent → Website chat: put the agent on the client's website and landing pages.
 * One agent can have many website chats (one per landing page), each with its own look and words.
 */
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/webchat.php';

$cid  = (int) $CLIENT['id'];
$fid  = (int) ($_GET['flow'] ?? 0);
$flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='agent'", [$fid, $cid]);
if (!$flow) { http_response_code(404); exit('Agent not found.'); }
if (!webchat_ready()) {
    client_header('Website chat', 'agents', $CLIENT);
    page_head('Website chat');
    echo '<div class="alert error">The website chat needs the latest database update. Run the deploy command (it runs migrate.php), then reload this page.</div>';
    layout_footer(); exit;
}
$canWrite = !function_exists('can_write') || can_write();
$wid = (int) ($_GET['w'] ?? 0);
$widget = $wid ? db_row("SELECT * FROM web_widgets WHERE id=? AND client_id=? AND flow_id=?", [$wid, $cid, $fid]) : null;
if ($wid && !$widget) { flash('That website chat is not on this agent.', 'error'); redirect('agent_web.php?flow=' . $fid); }
$self = 'agent_web.php?flow=' . $fid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canWrite) { flash('You can only look at this account.', 'error'); redirect($self); }
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'create') {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120) ?: 'Website chat';
        $s = webchat_defaults();
        $s['title'] = mb_substr((string) $flow['name'], 0, 120);
        $newId = db_insert("INSERT INTO web_widgets (client_id,flow_id,name,public_key,settings,status,created_at) VALUES (?,?,?,?,?,'active',NOW())",
                           [$cid, $fid, $name, webchat_new_key(), json_encode($s, JSON_UNESCAPED_UNICODE)]);
        flash('Website chat created. Set its look and words, then copy the code onto your page.');
        redirect($self . '&w=' . $newId);
    }
    if ($a === 'copy' && $widget) {
        $newId = db_insert("INSERT INTO web_widgets (client_id,flow_id,name,public_key,settings,allowed_domains,status,created_at) VALUES (?,?,?,?,?,?,'active',NOW())",
                           [$cid, $fid, mb_substr($widget['name'] . ' (copy)', 0, 120), webchat_new_key(), $widget['settings'], $widget['allowed_domains']]);
        flash('Copied — this one has its own code, so it can look different on another page.');
        redirect($self . '&w=' . $newId);
    }
    if ($a === 'toggle' && $widget) {
        db_run("UPDATE web_widgets SET status=?, updated_at=NOW() WHERE id=?", [$widget['status'] === 'active' ? 'paused' : 'active', $wid]);
        flash($widget['status'] === 'active' ? 'Paused: the chat bubble no longer shows on your pages.' : 'Switched on.');
        redirect($self);
    }
    if ($a === 'delete' && $widget) {
        db_run("DELETE FROM web_widgets WHERE id=?", [$wid]);
        flash('Website chat deleted. Its conversations stay in the Inbox.');
        redirect($self);
    }
    if ($a === 'save' && $widget) {
        $acts = [];
        foreach ((array) ($_POST['act_label'] ?? []) as $i => $lab) {
            $acts[] = ['label' => $lab, 'send' => $_POST['act_send'][$i] ?? '', 'url' => $_POST['act_url'][$i] ?? '', 'image' => $_POST['act_image'][$i] ?? ''];
        }
        $in = [];
        foreach (array_keys(webchat_defaults()) as $k) if (array_key_exists($k, $_POST)) $in[$k] = $_POST[$k];
        $in['actions'] = $acts;
        foreach (['add_lead', 'branding'] as $k) $in[$k] = !empty($_POST[$k]) ? 1 : 0;
        $s = webchat_clean($in);
        $doms = implode("\n", webchat_domains(['allowed_domains' => (string) ($_POST['allowed_domains'] ?? '')]));
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120) ?: (string) $widget['name'];
        db_run("UPDATE web_widgets SET name=?, settings=?, allowed_domains=?, updated_at=NOW() WHERE id=?",
               [$name, json_encode($s, JSON_UNESCAPED_UNICODE), $doms !== '' ? $doms : null, $wid]);
        flash('Saved. Pages that already have the code show the change on their next load.');
        redirect($self . '&w=' . $wid);
    }
    redirect($self);
}

$base = app_base_url();
$flowOff = $flow['status'] !== 'active';

/* ───────────── one widget: customise + embed codes ───────────── */
if ($widget) {
    $s = webchat_settings($widget);
    $key = (string) $widget['public_key'];
    $jsUrl = $base . '/widget.php?k=' . $key . '&js=1';
    $frameUrl = $base . '/widget.php?k=' . $key . '&inline=1';
    $acts = $s['actions'] ?: [];
    while (count($acts) < 3) $acts[] = ['label' => '', 'send' => '', 'url' => '', 'image' => ''];
    client_header('Website chat · ' . $widget['name'], 'agents', $CLIENT);
    ?>
<div class="page-head"><h1><?= e((string) $widget['name']) ?></h1>
  <div class="page-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e($self) ?>">← Website chats</a>
    <a class="btn btn-ghost btn-sm" href="agent_edit.php?id=<?= $fid ?>">Agent: <?= e((string) $flow['name']) ?></a>
  </div>
</div>
<?php if ($flowOff): ?><div class="alert error">The agent "<?= e((string) $flow['name']) ?>" is switched off, so visitors' messages go to the Inbox without an AI answer. Switch it on in <a href="agents.php">AI Chat Agent</a>.</div><?php endif; ?>
<?php if ($widget['status'] !== 'active'): ?><div class="alert info">This website chat is paused: the bubble does not show on your pages.</div><?php endif; ?>

<style>
  .wc-grid{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:18px;align-items:start}
  @media (max-width:1100px){.wc-grid{grid-template-columns:1fr}}
  .wc-prev{position:sticky;top:12px}
  .wc-prev iframe{width:100%;height:620px;border:1px solid var(--line);border-radius:16px;background:#fff;display:block}
  .wc-code{position:relative}
  .wc-code pre{background:var(--paper,#f6f7f9);border:1px solid var(--line);border-radius:10px;padding:12px;font-size:12px;line-height:1.5;overflow-x:auto;white-space:pre;margin:0;direction:ltr;text-align:left}
  .wc-code button{position:absolute;top:8px;inset-inline-end:8px}
  .wc-act{display:grid;grid-template-columns:1fr 1.3fr 1.2fr 1.2fr auto;gap:8px;margin-bottom:8px}
  @media (max-width:760px){.wc-act{grid-template-columns:1fr 1fr}}
  .wc-colors{display:flex;gap:14px;flex-wrap:wrap}
  .wc-colors input[type=color]{width:52px;height:36px;padding:2px;border-radius:8px}
  details.wc-more summary{cursor:pointer;font-weight:600;padding:6px 0}
</style>

<div class="wc-grid">
<form method="post" id="wc-form">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">

  <div class="card">
    <h2>Look</h2>
    <div class="grid3">
      <div class="field"><span class="lbl">Name in Revenect</span><input name="name" maxlength="120" value="<?= e((string) $widget['name']) ?>" placeholder="e.g. Porto Golf landing page"></div>
      <div class="field"><span class="lbl">Title</span><input name="title" data-live maxlength="120" value="<?= e($s['title']) ?>"></div>
      <div class="field"><span class="lbl">Line under the title</span><input name="subtitle" data-live maxlength="120" value="<?= e($s['subtitle']) ?>"></div>
    </div>
    <div class="grid3">
      <div class="field"><span class="lbl">Picture <span class="text-muted">(https link, square)</span></span><input name="avatar" data-live class="ltr" value="<?= e($s['avatar']) ?>" placeholder="https://…/logo.png"></div>
      <div class="field"><span class="lbl">Colours</span>
        <div class="wc-colors">
          <label style="display:flex;align-items:center;gap:6px"><input type="color" name="color" data-live value="<?= e($s['color']) ?>"> Main</label>
          <label style="display:flex;align-items:center;gap:6px"><input type="color" name="text_color" data-live value="<?= e($s['text_color']) ?>"> Text on it</label>
        </div></div>
      <div class="field"><span class="lbl">Bubble on the page</span>
        <select name="position" data-live><option value="right" <?= $s['position'] === 'right' ? 'selected' : '' ?>>Bottom right</option><option value="left" <?= $s['position'] === 'left' ? 'selected' : '' ?>>Bottom left</option></select></div>
    </div>
    <div class="grid3">
      <div class="field"><span class="lbl">Direction</span>
        <select name="dir" data-live><?php foreach (['auto' => 'From the words (Arabic → right to left)', 'rtl' => 'Right to left', 'ltr' => 'Left to right'] as $v => $l): ?><option value="<?= $v ?>" <?= $s['dir'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="field"><span class="lbl">Open by itself after <span class="text-muted">(seconds, 0 = no)</span></span><input type="number" name="auto_open" min="0" max="600" value="<?= (int) $s['auto_open'] ?>"></div>
      <div class="field"><span class="lbl">&nbsp;</span><label class="mod-opt"><input type="checkbox" name="branding" value="1" <?= $s['branding'] ? 'checked' : '' ?>> Show "Powered by Revenect"</label></div>
    </div>
  </div>

  <div class="card">
    <h2>Words</h2>
    <div class="field"><span class="lbl">Welcome message <span class="text-muted">(the first thing they see)</span></span><textarea name="welcome" data-live rows="3" maxlength="600"><?= e($s['welcome']) ?></textarea></div>
    <div class="grid3">
      <div class="field"><span class="lbl">Note next to the bubble <span class="text-muted">(optional)</span></span><input name="teaser" data-live maxlength="120" value="<?= e($s['teaser']) ?>" placeholder="عندك سؤال؟ اسألنا 👋"></div>
      <div class="field"><span class="lbl">Box placeholder</span><input name="placeholder" data-live maxlength="120" value="<?= e($s['placeholder']) ?>"></div>
      <div class="field"><span class="lbl">When nobody answers at once</span><input name="offline_note" maxlength="600" value="<?= e($s['offline_note']) ?>"></div>
    </div>
  </div>

  <div class="card">
    <h2>Quick actions</h2>
    <p class="text-muted" style="font-size:12.5px;margin-top:-4px">Buttons under the welcome message. Each one sends a message to the agent, or opens a link. Add a picture to any of them and they show as cards you swipe through.</p>
    <div class="wc-act text-muted" style="font-size:11.5px;margin-bottom:4px"><span>Button text</span><span>Sends this message</span><span>…or opens this link</span><span>Picture (optional)</span><span></span></div>
    <div id="acts">
      <?php foreach ($acts as $a): ?>
        <div class="wc-act">
          <input name="act_label[]" maxlength="40" value="<?= e((string) ($a['label'] ?? '')) ?>" placeholder="الأسعار">
          <input name="act_send[]" maxlength="300" value="<?= e((string) ($a['send'] ?? '')) ?>" placeholder="عايز أعرف الأسعار">
          <input name="act_url[]" class="ltr" value="<?= e((string) ($a['url'] ?? '')) ?>" placeholder="https://…">
          <input name="act_image[]" class="ltr" value="<?= e((string) ($a['image'] ?? '')) ?>" placeholder="https://…/photo.jpg">
          <button type="button" class="icon-btn" onclick="this.closest('.wc-act').remove()" title="Remove">&#x2715;</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" onclick="addAct()">+ Action</button>
  </div>

  <div class="card">
    <h2>Visitor details</h2>
    <div class="grid3">
      <div class="field"><span class="lbl">Ask for name and mobile</span>
        <select name="ask_details"><?php foreach (['off' => 'No — the agent can ask in the chat', 'optional' => 'Yes, they can skip it', 'required' => 'Yes, before they can write'] as $v => $l): ?><option value="<?= $v ?>" <?= $s['ask_details'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="field" style="grid-column:span 2"><span class="lbl">Line above those boxes</span><input name="details_note" maxlength="600" value="<?= e($s['details_note']) ?>"></div>
    </div>
    <label class="mod-opt"><input type="checkbox" name="add_lead" value="1" <?= $s['add_lead'] ? 'checked' : '' ?>> A visitor who leaves a mobile number becomes a lead in the CRM (source: Website)</label>
    <div class="hint">A number they leave joins them to the contact with that number, so a website chat and a WhatsApp chat with the same person end up in one conversation.</div>
  </div>

  <div class="card">
    <h2>Where it may be used</h2>
    <div class="field"><span class="lbl">Your websites <span class="text-muted">(one per line; empty = any website)</span></span>
      <textarea name="allowed_domains" rows="3" class="ltr" placeholder="landing.example.com&#10;*.example.com"><?= e((string) ($widget['allowed_domains'] ?? '')) ?></textarea>
      <div class="hint">Other websites cannot show this chat or send messages through it. *.example.com takes every sub-domain.</div></div>
  </div>

  <?php if ($canWrite): ?><div class="form-actions" style="display:flex;gap:10px;justify-content:flex-end"><button class="btn btn-primary">Save</button></div><?php endif; ?>
</form>

<div class="wc-prev">
  <div class="card" style="padding:14px">
    <h2 style="margin-bottom:8px">Preview</h2>
    <iframe id="wc-frame" src="<?= e($frameUrl) ?>" title="Chat preview"></iframe>
    <p class="text-muted" style="font-size:11.5px;margin:8px 0 0">This is the real chat: a message here reaches the agent and shows in the Inbox. Title, colours and words change as you type; quick actions and visitor details show after Save.</p>
  </div>
</div>
</div>

<div class="card">
  <h2>Put it on your page</h2>
  <p style="margin-top:-4px"><strong>Chat bubble</strong> — paste before <code>&lt;/body&gt;</code>. The data-… lines are optional: each page can change these for itself (delete the ones you don't need).</p>
  <div class="wc-code"><pre id="c1">&lt;script src="<?= e($jsUrl) ?>" async
  data-title="<?= e($s['title']) ?>"
  data-color="<?= e($s['color']) ?>"
  data-welcome="<?= e(str_replace("\n", ' ', $s['welcome'])) ?>"
  data-teaser="<?= e($s['teaser']) ?>"
  data-context="<?= e((string) $widget['name']) ?>"&gt;&lt;/script&gt;</pre><button type="button" class="btn btn-ghost btn-sm" onclick="copyCode('c1',this)">Copy</button></div>
  <p class="hint" style="margin-top:6px">Also: data-subtitle, data-avatar, data-text-color, data-position (left/right), data-dir (rtl/ltr), data-placeholder, data-auto-open (seconds). data-context names the page — visitors show in the Inbox as "Visitor · <em>that name</em>". Open it from your own button with <code>RevenectChat.open()</code>.</p>

  <p style="margin-top:18px"><strong>Inside a section of the page</strong> — the chat in a box, no bubble:</p>
  <div class="wc-code"><pre id="c2">&lt;iframe src="<?= e($frameUrl) ?>" style="width:100%;max-width:420px;height:600px;border:0;border-radius:16px" title="Chat"&gt;&lt;/iframe&gt;</pre><button type="button" class="btn btn-ghost btn-sm" onclick="copyCode('c2',this)">Copy</button></div>

  <details class="wc-more" style="margin-top:14px"><summary>For developers: the API</summary>
    <p class="text-muted" style="font-size:12.5px">Build your own chat design. <code>vid</code> is 32 hexadecimal characters your page makes once per visitor (crypto.getRandomValues) and keeps — the conversation hangs on it. JSON or form bodies.</p>
    <div class="wc-code"><pre id="c3"># the widget's look and words
curl "<?= e($base) ?>/widget_api.php?k=<?= e($key) ?>&amp;a=config"

# send what the visitor wrote — the answer comes back in "messages"
curl -X POST "<?= e($base) ?>/widget_api.php?k=<?= e($key) ?>&amp;a=send" \
  -H "Content-Type: application/json" \
  -d '{"vid":"0123456789abcdef0123456789abcdef","text":"عايز أعرف الأسعار","page":"https://your-site.com/offer"}'

# new messages since the last id you have (a person may answer from the Inbox) — every few seconds
curl "<?= e($base) ?>/widget_api.php?k=<?= e($key) ?>&amp;a=poll&amp;vid=0123456789abcdef0123456789abcdef&amp;after=0"

# the visitor's name and mobile (joins them to that contact, adds the lead)
curl -X POST "<?= e($base) ?>/widget_api.php?k=<?= e($key) ?>&amp;a=identify" \
  -H "Content-Type: application/json" -d '{"vid":"0123456789abcdef0123456789abcdef","name":"Ahmed","phone":"+201001234567"}'</pre><button type="button" class="btn btn-ghost btn-sm" onclick="copyCode('c3',this)">Copy</button></div>
    <p class="hint">Each message: <code>{id, from: visitor|bot|agent, text, at, choices?, image?}</code>. From a browser, calls are accepted only from the websites listed above (when any are listed).</p>
  </details>
</div>

<script>
function addAct(){
  const n = document.querySelectorAll('#acts .wc-act').length; if (n >= 8) { showToast('Up to 8 quick actions.', true); return; }
  document.getElementById('acts').insertAdjacentHTML('beforeend', `<div class="wc-act"><input name="act_label[]" maxlength="40" placeholder="Button text"><input name="act_send[]" maxlength="300" placeholder="Message it sends"><input name="act_url[]" class="ltr" placeholder="https://…"><input name="act_image[]" class="ltr" placeholder="https://…/photo.jpg"><button type="button" class="icon-btn" onclick="this.closest('.wc-act').remove()" title="Remove">&#x2715;</button></div>`);
}
function copyCode(id, b){
  navigator.clipboard.writeText(document.getElementById(id).textContent).then(()=>{ const t=b.textContent; b.textContent='Copied'; setTimeout(()=>b.textContent=t,1500); });
}
// Live preview: what a page may change for itself goes into the preview's URL as you type.
(function(){
  const f = document.getElementById('wc-form'), fr = document.getElementById('wc-frame'), base = <?= json_encode($frameUrl) ?>;
  let tm = null;
  const enc = o => btoa(unescape(encodeURIComponent(JSON.stringify(o))));
  f.addEventListener('input', e => {
    if (!e.target.hasAttribute('data-live')) return;
    clearTimeout(tm); tm = setTimeout(() => {
      const o = {}; f.querySelectorAll('[data-live]').forEach(i => o[i.name] = i.value);
      fr.src = base + '&o=' + encodeURIComponent(enc(o));
    }, 500);
  });
})();
</script>
<?php
    layout_footer(); exit;
}

/* ───────────── the agent's website chats + their conversations ───────────── */
$widgets = webchat_widgets($cid, $fid);
$stats = webchat_stats($cid, $fid, 30);
$byW = array_column($stats['widgets'], null, 'id');
$wIds = array_map('intval', array_column($widgets, 'id'));
$convos = $wIds ? db_all(
    "SELECT c.id, c.name, c.phone_e164, c.web_page, c.web_widget_id, c.web_last_in_at, c.stage_id,
            (SELECT COUNT(*) FROM messages m WHERE m.client_id=c.client_id AND m.contact_id=c.id AND m.channel='web') msgs,
            (SELECT m.body FROM messages m WHERE m.client_id=c.client_id AND m.contact_id=c.id AND m.channel='web' ORDER BY m.id DESC LIMIT 1) last_body,
            (SELECT r.context FROM flow_runs r WHERE r.contact_id=c.id AND r.flow_id=? ORDER BY r.id DESC LIMIT 1) ctx,
            (SELECT r.status FROM flow_runs r WHERE r.contact_id=c.id AND r.flow_id=? ORDER BY r.id DESC LIMIT 1) run_status
       FROM contacts c WHERE c.client_id=? AND c.web_widget_id IN (" . implode(',', $wIds) . ") AND c.web_last_in_at IS NOT NULL
      ORDER BY c.web_last_in_at DESC LIMIT 100", [$fid, $fid, $cid]) : [];
$wName = array_column($widgets, 'name', 'id');

client_header('Website chat · ' . $flow['name'], 'agents', $CLIENT);
?>
<div class="page-head"><h1>Website chat — <?= e((string) $flow['name']) ?></h1>
  <div class="page-actions">
    <a class="btn btn-ghost btn-sm" href="agents.php">← All agents</a>
    <a class="btn btn-ghost btn-sm" href="agent_edit.php?id=<?= $fid ?>">Edit agent</a>
    <a class="btn btn-ghost btn-sm" href="agent_chats.php?flow=<?= $fid ?>">All chats</a>
    <?php if ($canWrite): ?><button class="btn btn-primary btn-sm" onclick="document.getElementById('m-new').classList.add('open')">+ New website chat</button><?php endif; ?>
  </div>
</div>
<?php if ($flowOff): ?><div class="alert error">This agent is switched off: website visitors' messages go to the Inbox without an AI answer. Switch it on in <a href="agents.php">AI Chat Agent</a>.</div><?php endif; ?>

<div class="alert info" style="font-size:12.5px">
  Put this agent on your website or a landing page: it answers visitors from the same knowledge, captures what you asked for, and every chat shows in the <a href="inbox.php">Inbox</a> (Website tab), where your team can take over.
  Make one website chat per landing page to give each its own title, colours, welcome and quick actions — and to see each page's results here.
</div>

<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Visitors who wrote · 30 days</span><span class="val accent"><?= (int) $stats['total']['visitors'] ?></span></div>
  <div class="stat-tile"><span class="lbl">Their messages</span><span class="val"><?= (int) $stats['total']['msgs_in'] ?></span></div>
  <div class="stat-tile"><span class="lbl">Agent conversations</span><span class="val"><?= (int) $stats['total']['chats'] ?></span></div>
  <div class="stat-tile"><span class="lbl">Left a mobile number</span><span class="val"><?= (int) $stats['total']['with_phone'] ?></span></div>
</div>

<div class="card card-flush">
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Website chat</th><th>Visitors · 30d</th><th>Messages</th><th>Mobile numbers</th><th>Sites</th><th></th></tr></thead>
    <tbody>
    <?php if (!$widgets): ?><tr><td colspan="6"><div class="empty">No website chat yet. Press <strong>+ New website chat</strong>, set its look, and paste one line on your page.</div></td></tr><?php endif; ?>
    <?php foreach ($widgets as $w): $st = $byW[$w['id']] ?? []; $doms = webchat_domains($w); ?>
      <tr>
        <td><a href="<?= e($self) ?>&w=<?= (int) $w['id'] ?>"><strong><?= e((string) $w['name']) ?></strong></a> <?= $w['status'] !== 'active' ? '<span class="pill gray">paused</span>' : '' ?></td>
        <td><?= (int) ($st['visitors'] ?? 0) ?></td>
        <td><?= (int) ($st['msgs_in'] ?? 0) ?></td>
        <td><?= (int) ($st['with_phone'] ?? 0) ?></td>
        <td class="text-muted" style="font-size:12px"><?= $doms ? e(implode(', ', array_slice($doms, 0, 3)) . (count($doms) > 3 ? '…' : '')) : 'Any website' ?></td>
        <td style="text-align:end;white-space:nowrap">
          <a class="btn btn-ghost btn-sm" href="<?= e($self) ?>&w=<?= (int) $w['id'] ?>">Customise &amp; code</a>
          <?php if ($canWrite): ?>
          <form method="post" action="<?= e($self) ?>&w=<?= (int) $w['id'] ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="copy"><button class="btn btn-ghost btn-sm" title="Another landing page, its own look">Duplicate</button></form>
          <form method="post" action="<?= e($self) ?>&w=<?= (int) $w['id'] ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><button class="btn btn-ghost btn-sm"><?= $w['status'] === 'active' ? 'Pause' : 'Switch on' ?></button></form>
          <form method="post" action="<?= e($self) ?>&w=<?= (int) $w['id'] ?>" style="display:inline" onsubmit="return confirm('Delete this website chat? The code stops working on your pages. Its conversations stay in the Inbox.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="icon-btn" title="Delete">&#x2715;</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<h2 style="margin:22px 0 10px">Website conversations</h2>
<div class="card card-flush">
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Visitor</th><th>From</th><th>Messages</th><th>Last message</th><th>Captured</th><th>Agent</th><th>Last seen</th><th></th></tr></thead>
    <tbody>
    <?php if (!$convos): ?><tr><td colspan="8"><div class="empty">No website conversations yet.</div></td></tr><?php endif; ?>
    <?php foreach ($convos as $c):
      $ctx = json_decode((string) ($c['ctx'] ?? ''), true) ?: [];
      $cap = [];
      foreach ((array) ($ctx['fields'] ?? []) as $k => $v) { if ($k === 'not_interested_reason' || (string) $v === '' || is_array($v)) continue; $cap[] = $k . ': ' . $v; }
      $runPill = ['completed' => ['green', 'Done'], 'waiting_input' => ['gold', 'Chatting'], 'active' => ['gold', 'Chatting'], 'stopped' => ['gray', 'Team took over'], 'blocked' => ['red', 'Stopped']][(string) $c['run_status']] ?? ['gray', '—'];
      $path = $c['web_page'] ? (string) (parse_url((string) $c['web_page'], PHP_URL_HOST) . parse_url((string) $c['web_page'], PHP_URL_PATH)) : '';
    ?>
      <tr>
        <td><strong><?= e((string) $c['name']) ?></strong><?= $c['phone_e164'] ? '<br><span class="mono text-muted" style="font-size:12px">+' . e((string) $c['phone_e164']) . '</span>' : '' ?><?= $c['stage_id'] !== null ? ' <span class="pill blue">lead</span>' : '' ?></td>
        <td class="text-muted" style="font-size:12px;max-width:200px"><?= e((string) ($wName[$c['web_widget_id']] ?? '')) ?><?= $path !== '' ? '<br><span title="' . e((string) $c['web_page']) . '">' . e(mb_strimwidth($path, 0, 40, '…')) . '</span>' : '' ?></td>
        <td><?= (int) $c['msgs'] ?></td>
        <td class="text-muted" style="font-size:12.5px;max-width:240px"><?= e(mb_strimwidth((string) $c['last_body'], 0, 70, '…')) ?></td>
        <td class="text-muted" style="font-size:12px;max-width:220px"><?= $cap ? e(implode(' · ', array_slice($cap, 0, 3))) : '—' ?></td>
        <td><span class="pill <?= $runPill[0] ?>"><?= e($runPill[1]) ?></span></td>
        <td class="text-muted" style="white-space:nowrap"><?= e(date('d M, H:i', strtotime((string) $c['web_last_in_at']))) ?></td>
        <td style="text-align:end"><a class="btn btn-ghost btn-sm" href="inbox.php?contact=<?= (int) $c['id'] ?>">Open in Inbox</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="modal-back" id="m-new">
  <form class="modal" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <h2>New website chat</h2>
    <div class="field"><span class="lbl">Name <span class="text-muted">(which page it is for)</span></span><input name="name" maxlength="120" placeholder="e.g. Porto Golf landing page" required></div>
    <div class="hint">Answered by "<?= e((string) $flow['name']) ?>". You set its look and words next.</div>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-new').classList.remove('open')">Cancel</button><button class="btn btn-primary">Create</button></div>
  </form>
</div>
<?php layout_footer(); ?>
