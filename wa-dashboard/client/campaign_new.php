<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/../includes/campaign.php';

$cid = (int) $CLIENT['id'];

/* ── AJAX: opted-in recipient count for a list ── */
if (isset($_GET['count'])) {
    $lid = (int) $_GET['count'];
    $n = (int) db_val(
        "SELECT COUNT(*) FROM contact_list_members m JOIN contacts c ON c.id=m.contact_id
          WHERE m.list_id=? AND c.client_id=? AND c.opt_in_status='in'",
        [$lid, $cid]
    );
    json_out(['count' => $n]);
}

$templates = db_all("SELECT * FROM templates WHERE client_id=? AND status='APPROVED' ORDER BY wa_name", [$cid]);
$lists     = db_all("SELECT l.*, (SELECT COUNT(*) FROM contact_list_members m WHERE m.list_id=l.id) AS members FROM contact_lists l WHERE l.client_id=? ORDER BY l.name", [$cid]);
// Flows this campaign can hand its recipients to once the message has landed.
$flows     = db_all("SELECT id, name, kind FROM flows WHERE client_id=? AND status <> 'archived' ORDER BY name", [$cid]);
$err = '';

/* ── Duplicate: open this form already filled in from an existing campaign ──
   Deliberately not a one-click clone. Creating a campaign queues a message per contact and
   spends a credit each, so a copy goes through exactly the same read-it-and-confirm as a new
   one. Everything about the message is carried over; the audience and timing are the two
   things worth looking at again, so the schedule is never inherited — a copy of a campaign
   that was set for last Tuesday would be rejected as a past date anyway. */
$copy = [];
if (($copyId = (int) ($_GET['copy'] ?? 0)) > 0) {
    $src = db_row("SELECT * FROM campaigns WHERE id=? AND client_id=?", [$copyId, $cid]);
    if ($src) {
        $vm = campaign_config(json_decode((string) $src['variable_map'], true) ?: []);
        $copy = [
            'name'        => campaign_copy_name((string) $src['name'], $cid),
            'template_id' => (int) $src['template_id'],
            'list_id'     => (int) $src['list_id'],
            'body_text'   => (string) $src['body_text'],
            'body_media'  => (string) ($vm['header_media'] ?? ''),
            'fields'      => $vm,
            'follow_flow' => (int) $src['follow_flow_id'],
            'follow_trig' => (string) $src['follow_trigger'],
            'follow_del'  => (int) $src['follow_delay_minutes'],
            'follow_aud'  => (string) $src['follow_audience'],
        ];
    } else {
        $err = 'That campaign could not be found, so there was nothing to copy.';
    }
}

/* ── Create campaign ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    verify_csrf();
    $name       = trim((string) ($_POST['name'] ?? ''));
    $templateId = (int) ($_POST['template_id'] ?? 0);
    $listId     = (int) ($_POST['list_id'] ?? 0);
    // A personal number has no approved templates — the campaign carries its own text.
    $isPersonal = channel_is_personal($CLIENT);
    $bodyText   = trim((string) ($_POST['body_text'] ?? ''));
    $bodyMedia  = trim((string) ($_POST['body_media'] ?? ''));   // optional image, sent with the text as its caption
    $when       = (string) ($_POST['when'] ?? 'now');
    $schedRaw   = trim((string) ($_POST['scheduled_at'] ?? ''));

    $template = db_row("SELECT * FROM templates WHERE id=? AND client_id=? AND status='APPROVED'", [$templateId, $cid]);
    $list     = db_row("SELECT * FROM contact_lists WHERE id=? AND client_id=?", [$listId, $cid]);

    /* ── Follow-up automation (optional) ──
       Only accept a flow this client owns and that is not archived, so a posted id can't
       enrol their contacts into somebody else's flow. */
    $followFlowId = (int) ($_POST['follow_flow_id'] ?? 0) ?: null;
    if ($followFlowId !== null) {
        $ff = db_row("SELECT id FROM flows WHERE id=? AND client_id=? AND status <> 'archived'", [$followFlowId, $cid]);
        if (!$ff) $followFlowId = null;
    }
    $followTrigger  = in_array(($_POST['follow_trigger'] ?? ''), ['sent','delivered','read','replied','no_reply'], true)
                    ? (string) $_POST['follow_trigger'] : 'replied';
    $followAudience = in_array(($_POST['follow_audience'] ?? ''), ['all','delivered','replied','not_replied'], true)
                    ? (string) $_POST['follow_audience'] : 'all';
    $followDelay    = max(0, min(43200, (int) ($_POST['follow_delay_minutes'] ?? 0)));   // cap at 30 days
    // "After N minutes with no reply" is meaningless with no wait — default it to a day.
    if ($followTrigger === 'no_reply' && $followDelay === 0) $followDelay = 1440;

    $scheduledAt = null;
    if ($when === 'later') {
        $ts = strtotime($schedRaw);
        if ($ts === false || $ts < time() - 60) $err = 'Choose a valid future date & time to schedule.';
        else $scheduledAt = date('Y-m-d H:i:s', $ts);
    }

    if ($name === '')                      $err = $err ?: 'Enter a campaign name.';
    elseif ($isPersonal && $bodyText === '') $err = $err ?: 'Write the message you want to send.';
    elseif (!$isPersonal && !$template)      $err = $err ?: 'Choose an approved template.';
    elseif (!$list)                          $err = $err ?: 'Choose an audience list.';

    // ── Build the FULL field config from POST (header media/text/location, body vars,
    //    dynamic buttons) so templates with an image header send correctly.
    //    Skipped entirely on the personal channel, which sends plain text. ──
    $tplComponents = $template ? (json_decode((string) $template['components'], true) ?: []) : [];
    $spec = wa_template_spec($tplComponents);

    $varMap = ['vars' => [], 'header_vars' => [], 'header_loc' => [], 'buttons' => [], 'header_media' => ''];
    for ($i = 1; $i <= (int) $spec['body_vars']; $i++) {
        $varMap['vars'][(string) $i] = [
            'source'   => (string) ($_POST['var_source'][$i] ?? 'static'),
            'value'    => (string) ($_POST['var_value'][$i] ?? ''),
            'fallback' => (string) ($_POST['var_fallback'][$i] ?? ''),
        ];
    }
    $hf = strtoupper((string) $spec['header']['format']);
    if (in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
        $varMap['header_media'] = trim((string) ($_POST['header_media'] ?? ''));
    } elseif ($hf === 'LOCATION') {
        $varMap['header_loc'] = [
            'lat'     => trim((string) ($_POST['header_lat'] ?? '')),
            'lng'     => trim((string) ($_POST['header_lng'] ?? '')),
            'name'    => trim((string) ($_POST['header_loc_name'] ?? '')),
            'address' => trim((string) ($_POST['header_address'] ?? '')),
        ];
    } elseif ($hf === 'TEXT') {
        for ($i = 1; $i <= (int) $spec['header']['text_vars']; $i++) {
            $varMap['header_vars'][(string) $i] = [
                'source'   => (string) ($_POST['hvar_source'][$i] ?? 'static'),
                'value'    => (string) ($_POST['hvar_value'][$i] ?? ''),
                'fallback' => (string) ($_POST['hvar_fallback'][$i] ?? ''),
            ];
        }
    }
    foreach ((array) $spec['buttons'] as $b) {
        if (empty($b['dynamic'])) continue;
        $bi = (int) $b['index'];
        $varMap['buttons'][(string) $bi] = trim((string) ($_POST['btn_value'][$bi] ?? ''));
    }

    // A personal campaign's image lives in the same slot as a template's header media, so the
    // report and the worker read it from one place on either channel.
    if ($isPersonal) $varMap['header_media'] = $bodyMedia;

    // Catch the missing-header-media case here rather than letting Meta reject every message.
    if (!$isPersonal && $template && in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && $varMap['header_media'] === '') {
        $err = $err ?: 'This template has a ' . strtolower($hf) . ' header — upload or paste a ' . strtolower($hf) . ' URL before sending.';
    }

    if (!$err) {
        $recipients = db_all(
            "SELECT c.* FROM contact_list_members m JOIN contacts c ON c.id=m.contact_id
              WHERE m.list_id=? AND c.client_id=? AND c.opt_in_status='in'",
            [$listId, $cid]
        );
        if (!$recipients) {
            $err = 'That list has no opted-in contacts to message.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $status = $scheduledAt ? 'scheduled' : 'sending';
                $campaignId = db_insert(
                    "INSERT INTO campaigns (client_id,name,template_id,body_text,list_id,variable_map,status,scheduled_at,
                                            follow_flow_id,follow_trigger,follow_delay_minutes,follow_audience,
                                            total_count,created_at,started_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)",
                    [$cid, $name, $isPersonal ? null : $templateId, $isPersonal ? $bodyText : null,
                     $listId, json_encode($varMap, JSON_UNESCAPED_UNICODE),
                     $status, $scheduledAt,
                     $followFlowId, $followTrigger, $followDelay, $followAudience,
                     count($recipients), $scheduledAt ? null : date('Y-m-d H:i:s')]
                );

                $ins = $pdo->prepare(
                    "INSERT INTO campaign_messages (campaign_id,client_id,contact_id,phone_e164,rendered_components,status,updated_at)
                     VALUES (?,?,?,?,?, 'queued', NOW())"
                );
                foreach ($recipients as $c) {
                    // Personal: store the finished text per recipient (same slot as the
                    // Cloud path's components) so the worker sends without re-rendering.
                    $components = $isPersonal
                        ? ['text' => campaign_render_text($bodyText, $c)] + ($bodyMedia !== '' ? ['image' => $bodyMedia] : [])
                        : campaign_components($varMap, $tplComponents, $c);
                    $ins->execute([
                        $campaignId, $cid, (int) $c['id'], (string) $c['phone_e164'],
                        json_encode($components, JSON_UNESCAPED_UNICODE),
                    ]);
                }
                $pdo->commit();
                if (!$scheduledAt) {
                    trigger_worker(); // start sending immediately, don't wait for the cron
                    flash('Campaign created — sending has started.');
                } else {
                    flash('Campaign scheduled for ' . date('d M Y, H:i', strtotime($scheduledAt)) . '.');
                }
                redirect('report.php?id=' . $campaignId);
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('campaign create failed: ' . $ex->getMessage());
                $err = 'Could not create the campaign. Please try again.';
            }
        }
    }
}

$isCopy = $copy !== [];
client_header($isCopy ? 'Duplicate Campaign' : 'New Campaign', 'campaigns', $CLIENT);
page_head($isCopy ? 'Duplicate Campaign' : 'New Campaign');
if ($isCopy): ?>
  <div class="alert info">Copied from <strong><?= e((string) $src['name']) ?></strong> — the message and
    its settings are filled in below. <strong>Check the audience and timing</strong>, then create it.
    Nothing is sent until you do.</div>
<?php endif;

if (!client_ready($CLIENT)): ?>
  <div class="alert error"><?= channel_is_personal($CLIENT)
      ? 'Your WhatsApp number isn\'t linked yet. Go to <strong>Settings → My WhatsApp Number</strong> and scan the QR code first.'
      : 'Your WhatsApp account isn\'t connected yet. Contact ' . e(BRAND_PARENT) . ' before sending campaigns.' ?></div>
  <?php layout_footer(); exit;
endif;

$PERSONAL = channel_is_personal($CLIENT);
// Templates are a Cloud API concept — a personal number has none and needs none.
if (!$PERSONAL && !$templates): ?>
  <div class="alert info">You have no <strong>approved</strong> templates yet. Go to <a href="templates.php">Templates</a> and sync them first.</div>
  <?php layout_footer(); exit;
endif;
if (!$lists): ?>
  <div class="alert info">You have no audience lists yet. Create one in <a href="lists.php">Lists</a> first.</div>
  <?php layout_footer(); exit;
endif;

if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<form method="post" id="camp-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="create">

  <div class="card">
    <h2>1 · Campaign &amp; Message</h2>
    <div class="field"><span class="lbl">Campaign Name</span><input type="text" name="name" value="<?= old('name', (string) ($copy['name'] ?? '')) ?>" placeholder="e.g. July Promo" required></div>

    <?php if ($PERSONAL): ?>
      <!-- Personal number: no approved templates exist, so the message is written here. -->
      <div class="field">
        <span class="lbl">Message</span>
        <textarea name="body_text" id="body_text" rows="6" required
                  placeholder="أهلاً {{name}} 👋&#10;عرض خاص النهاردة…"><?= old('body_text', (string) ($copy['body_text'] ?? '')) ?></textarea>
        <span class="text-muted" style="font-size:12px">
          Personalise with <code>{{name}}</code>, <code>{{phone}}</code>, or any contact attribute
          (e.g. <code>{{city}}</code>). Blank if the contact has no value.
        </span>
      </div>
      <div class="field">
        <span class="lbl">Image <span class="text-muted">(optional)</span></span>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <input type="text" id="pm-url" name="body_media" value="<?= old('body_media', (string) ($copy['body_media'] ?? '')) ?>" placeholder="https://… or click Upload" style="flex:1;min-width:220px">
          <input type="file" id="pm-file" style="display:none" accept=".jpg,.jpeg,.png,.webp">
          <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('pm-file').click()">Upload</button>
          <span id="pm-status" class="text-muted" style="font-size:12px"></span>
        </div>
        <span class="text-muted" style="font-size:12px">Sent as a photo with the message above as its caption.</span>
      </div>
      <div class="alert info" style="font-size:12.5px">
        Sending from <strong>your own number</strong> — no template approval needed. Messages go out
        <strong><?= (int) ($CLIENT['slot_size'] ?: 15) ?> at a time</strong>, then pause
        <strong><?= round((int) ($CLIENT['slot_pause_sec'] ?: 180) / 60, 1) ?> minutes</strong>, to protect the number.
      </div>
    <?php else: ?>
      <div class="field">
        <span class="lbl">Template</span>
        <select name="template_id" id="template_id" required onchange="onTemplate()">
          <option value="">— choose an approved template —</option>
          <?php foreach ($templates as $t): ?>
            <option value="<?= (int) $t['id'] ?>" <?= (int) ($copy['template_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>
                    data-body="<?= e((string) $t['body_text']) ?>" data-lang="<?= e((string) $t['language']) ?>">
              <?= e((string) $t['wa_name']) ?> (<?= e((string) $t['language']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="tpl-preview" class="mt10" style="display:none">
        <span class="lbl">Preview</span>
        <div style="background:var(--paper);border-radius:10px;padding:14px;white-space:pre-wrap;font-size:13.5px" id="tpl-preview-body"></div>
      </div>
    <?php endif; ?>
  </div>

  <div class="card" id="vars-card" style="display:<?= $PERSONAL ? 'none' : 'none' ?>"<?= $PERSONAL ? ' hidden' : '' ?>>
    <h2>2 · Template Fields</h2>
    <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">Fill everything this template needs — header media, variables and dynamic buttons. Variables can be a fixed value or pulled from each contact's name / attribute (with a fallback if empty).</p>
    <div id="header-wrap"></div>
    <div id="vars-wrap"></div>
    <div id="btns-wrap"></div>
  </div>

  <div class="card">
    <h2>3 · Audience &amp; Timing</h2>
    <div class="field">
      <span class="lbl">Send to List</span>
      <select name="list_id" id="list_id" required onchange="onList()">
        <option value="">— choose a list —</option>
        <?php foreach ($lists as $l): ?>
          <option value="<?= (int) $l['id'] ?>" <?= (int) ($copy['list_id'] ?? 0) === (int) $l['id'] ? 'selected' : '' ?>><?= e((string) $l['name']) ?> (<?= (int) $l['members'] ?> members)</option>
        <?php endforeach; ?>
      </select>
      <div class="hint" id="reach-hint"></div>
    </div>
    <div class="field">
      <span class="lbl">When</span>
      <label style="display:flex;gap:8px;align-items:center;font-weight:normal;margin-bottom:6px"><input type="radio" name="when" value="now" checked onchange="onWhen()" style="width:auto"> Send now</label>
      <label style="display:flex;gap:8px;align-items:center;font-weight:normal"><input type="radio" name="when" value="later" onchange="onWhen()" style="width:auto"> Schedule for later</label>
      <input type="datetime-local" name="scheduled_at" id="sched" style="display:none;margin-top:8px;max-width:280px">
    </div>
  </div>

  <?php /* ── After sending: hand the recipients to an automation ── */ ?>
  <div class="card">
    <h2 style="margin-top:0">After sending</h2>
    <?php if (!$flows): ?>
      <p class="text-muted">Once you build an automation, you'll be able to continue this
        campaign into it — so people who reply keep talking to your bot instead of the
        conversation stopping here.
        <a href="automations.php">Create an automation →</a></p>
    <?php else: ?>
      <?php $hasFollow = (int) ($copy['follow_flow'] ?? 0) > 0; ?>
      <label style="display:flex;gap:8px;align-items:center;font-weight:normal;margin-bottom:6px">
        <input type="radio" name="has_follow" value="0" <?= $hasFollow ? '' : 'checked' ?> onchange="onFollow()" style="width:auto"> Do nothing
      </label>
      <label style="display:flex;gap:8px;align-items:center;font-weight:normal">
        <input type="radio" name="has_follow" value="1" <?= $hasFollow ? 'checked' : '' ?> onchange="onFollow()" style="width:auto"> Continue into an automation
      </label>

      <div id="follow-box" style="display:none;margin-top:12px">
        <div class="grid3">
          <div class="field">
            <span class="lbl">Automation</span>
            <select name="follow_flow_id" id="follow_flow_id">
              <?php foreach ($flows as $f): ?>
                <option value="<?= (int) $f['id'] ?>" <?= (int) ($copy['follow_flow'] ?? 0) === (int) $f['id'] ? 'selected' : '' ?>><?= e((string) $f['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <span class="lbl">Start it</span>
            <?php $ft = (string) ($copy['follow_trig'] ?? 'replied'); $fsel = fn(string $v) => $ft === $v ? 'selected' : ''; ?>
            <select name="follow_trigger" id="follow_trigger" onchange="onFollow()">
              <option value="replied" <?= $fsel('replied') ?>>When they reply</option>
              <option value="delivered" <?= $fsel('delivered') ?>>After the message is delivered</option>
              <option value="read" <?= $fsel('read') ?>>After they read it</option>
              <option value="sent" <?= $fsel('sent') ?>>As soon as it's sent</option>
              <option value="no_reply" <?= $fsel('no_reply') ?>>When they haven't replied</option>
            </select>
          </div>
          <div class="field">
            <span class="lbl" id="delay-label">Wait first (minutes)</span>
            <input type="number" name="follow_delay_minutes" id="follow_delay" min="0" max="43200" value="<?= (int) ($copy['follow_del'] ?? 0) ?>">
            <span class="text-muted" style="font-size:11.5px">60 = an hour · 1440 = a day</span>
          </div>
        </div>
        <div class="field">
          <span class="lbl">Who goes in</span>
          <?php $fa = (string) ($copy['follow_aud'] ?? 'all'); $asel = fn(string $v) => $fa === $v ? 'selected' : ''; ?>
          <select name="follow_audience" id="follow_audience">
            <option value="all" <?= $asel('all') ?>>Everyone who got the message</option>
            <option value="delivered" <?= $asel('delivered') ?>>Only those it reached</option>
            <option value="replied" <?= $asel('replied') ?>>Only those who replied</option>
            <option value="not_replied" <?= $asel('not_replied') ?>>Only those who didn't reply</option>
          </select>
          <span class="text-muted" style="font-size:11.5px">Messages that failed are never followed up.</span>
        </div>
        <div class="alert warn" id="follow-window-warn" style="display:none">
          Starting before they reply means WhatsApp's 24-hour rule still applies, so only
          <strong>template</strong> steps will go out until they answer. Plain text, images and
          buttons will be held. Starting <em>when they reply</em> avoids this entirely.
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="row-between">
      <div>
        <div class="lbl" style="font-size:12px">Estimated cost</div>
        <div style="font-size:20px;font-weight:800" id="cost">—</div>
        <div class="text-muted" style="font-size:12px">Balance: <?= number_format((int) $CLIENT['credits_balance']) ?> credits · 1 credit per message</div>
        <div class="alert error" id="cost-warn" style="display:none;margin-top:10px">Not enough credits for the full audience — messages beyond your balance will fail until topped up.</div>
      </div>
      <button type="submit" class="btn btn-primary" id="submit-btn">Create Campaign</button>
    </div>
  </div>
</form>

<script>
const BALANCE = <?= (int) $CLIENT['credits_balance'] ?>;
const CSRF = <?= json_encode(csrf_token()) ?>;
// Full parameter spec per template: what the send payload must supply.
const SPECS = <?= json_encode(array_reduce($templates, function ($acc, $t) {
    $comp = json_decode((string) $t['components'], true) ?: [];
    $s = wa_template_spec($comp);
    $s['header_example'] = wa_template_header_example($comp);
    $acc[(int) $t['id']] = $s;
    return $acc;
}, [])) ?>;
let reach = 0;
const eatt = s => (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');

function onTemplate(){
  const sel = document.getElementById('template_id');
  if (!sel) return;                     // personal channel: no template picker on the page
  const opt = document.querySelector('#template_id option:checked');
  const body = opt?.dataset.body || '';
  const prev = document.getElementById('tpl-preview');
  if (opt && opt.value){ prev.style.display='block'; document.getElementById('tpl-preview-body').textContent = body || '(no body text)'; }
  else prev.style.display='none';

  const spec = SPECS[opt?.value] || null;
  const card = document.getElementById('vars-card');
  const hw = document.getElementById('header-wrap'), vw = document.getElementById('vars-wrap'), bw = document.getElementById('btns-wrap');
  hw.innerHTML=''; vw.innerHTML=''; bw.innerHTML='';
  if(!spec){ card.style.display='none'; return; }

  const fmt = (spec.header?.format||'').toUpperCase();
  const dynBtns = (spec.buttons||[]).filter(b=>b.dynamic);
  let any = false;

  // ── HEADER ──
  if(['IMAGE','VIDEO','DOCUMENT'].includes(fmt)){
    any = true;
    const kind = fmt.toLowerCase();
    const cur = spec.header_example || '';
    const note = cur ? ' <span class="text-muted">(sample from the template — upload your own to be safe)</span>' : '';
    hw.insertAdjacentHTML('beforeend', sect(`Header ${kind}${note}`,
      `<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
         <input type="text" id="hm-url" name="header_media" value="${eatt(cur)}" placeholder="https://… or click Upload" style="flex:1;min-width:220px">
         <input type="file" id="hm-file" style="display:none" accept="${kind==='image'?'.jpg,.jpeg,.png,.webp':kind==='video'?'.mp4,.3gp':'.pdf'}">
         <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('hm-file').click()">Upload</button>
         <span id="hm-status" class="text-muted" style="font-size:12px"></span>
       </div>
       <div class="hint">Uploaded once to WhatsApp and reused for the whole campaign — this is what makes big image sends reliable.</div>`));
  } else if(fmt==='LOCATION'){
    any = true;
    hw.insertAdjacentHTML('beforeend', sect('Header location',
      `<div class="grid2">
         <label><span class="lbl">Latitude</span><input type="text" name="header_lat" placeholder="30.0444"></label>
         <label><span class="lbl">Longitude</span><input type="text" name="header_lng" placeholder="31.2357"></label>
       </div>
       <div class="grid2">
         <label><span class="lbl">Name</span><input type="text" name="header_loc_name" placeholder="Showroom"></label>
         <label><span class="lbl">Address</span><input type="text" name="header_address" placeholder="Nasr City, Cairo"></label>
       </div>`));
  } else if(fmt==='TEXT' && (spec.header?.text_vars||0)>0){
    any = true;
    let h='';
    for(let i=1;i<=spec.header.text_vars;i++) h+=varRow(i,'hvar','Header variable');
    hw.insertAdjacentHTML('beforeend', sect('Header text variables', h));
  }

  // ── BODY ──
  if((spec.body_vars||0)>0){
    any = true;
    let h='';
    for(let i=1;i<=spec.body_vars;i++) h+=varRow(i,'var','Variable');
    vw.insertAdjacentHTML('beforeend', sect('Message variables', h));
  }

  // ── DYNAMIC BUTTONS ──
  if(dynBtns.length){
    any = true;
    let h='';
    dynBtns.forEach(b=>{
      const isCode = b.type==='COPY_CODE';
      h += `<label style="display:block;margin-bottom:10px"><span class="lbl">${eatt(b.text||('Button '+(b.index+1)))} — ${isCode?'coupon code':'URL suffix'}</span>
        <input type="text" name="btn_value[${b.index}]" placeholder="${isCode?'e.g. EID20':'e.g. summer-sale'}"></label>`;
    });
    bw.insertAdjacentHTML('beforeend', sect('Dynamic buttons', h));
  }

  card.style.display = any ? 'block' : 'none';
  bindUpload();
}
function sect(title, inner){
  return `<div class="card" style="background:var(--paper);border:0;padding:14px 16px;margin-bottom:12px">
    <div class="lbl" style="margin-bottom:8px">${title}</div>${inner}</div>`;
}
function varRow(i, prefix, label){
  return `<div style="margin-bottom:12px">
    <div class="lbl" style="margin-bottom:6px">${label} {{${i}}}</div>
    <div class="grid3">
      <label><span class="lbl">Source</span>
        <select name="${prefix}_source[${i}]" onchange="toggleVar(this,'${prefix}',${i})">
          <option value="static">Fixed text</option>
          <option value="name">Contact name</option>
          <option value="attribute">Contact attribute</option>
        </select>
      </label>
      <label id="${prefix}v-${i}"><span class="lbl">Value</span><input type="text" name="${prefix}_value[${i}]" placeholder="e.g. 20%"></label>
      <label id="${prefix}f-${i}" style="display:none"><span class="lbl">Fallback if empty</span><input type="text" name="${prefix}_fallback[${i}]" placeholder="e.g. Customer"></label>
    </div>
  </div>`;
}
function toggleVar(sel,prefix,i){
  const vv=document.getElementById(prefix+'v-'+i), vf=document.getElementById(prefix+'f-'+i);
  const lbl=vv.querySelector('.lbl'), inp=vv.querySelector('input');
  if(sel.value==='static'){ vv.style.display=''; lbl.textContent='Value'; inp.placeholder='e.g. 20%'; vf.style.display='none'; }
  else if(sel.value==='name'){ vv.style.display='none'; vf.style.display=''; }
  else { vv.style.display=''; lbl.textContent='Attribute key'; inp.placeholder='e.g. city'; vf.style.display=''; }
}
function bindUpload(){
  const f=document.getElementById('hm-file'); if(!f) return;
  f.addEventListener('change', async ()=>{
    if(!f.files || !f.files[0]) return;
    const st=document.getElementById('hm-status'); st.textContent='Uploading…';
    const fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('media',f.files[0]);
    try{
      const r=await fetch('upload_media.php',{method:'POST',body:fd}); const d=await r.json();
      if(d.ok){ document.getElementById('hm-url').value=d.url; st.textContent='✓ Uploaded'; }
      else st.textContent='⚠ '+(d.error||'Upload failed');
    }catch(e){ st.textContent='⚠ Upload failed'; }
  });
}
/* Personal channel: the campaign's own image (no template, so it has its own uploader). */
(function bindPersonalUpload(){
  const f=document.getElementById('pm-file'); if(!f) return;
  f.addEventListener('change', async ()=>{
    if(!f.files || !f.files[0]) return;
    const st=document.getElementById('pm-status'); st.textContent='Uploading…';
    const fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('media',f.files[0]);
    try{
      const r=await fetch('upload_media.php',{method:'POST',body:fd}); const d=await r.json();
      if(d.ok){ document.getElementById('pm-url').value=d.url; st.textContent='✓ Uploaded'; }
      else st.textContent='⚠ '+(d.error||'Upload failed');
    }catch(e){ st.textContent='⚠ Upload failed'; }
  });
})();
async function onList(){
  const lid = document.getElementById('list_id').value;
  if(!lid){ reach=0; updateCost(); document.getElementById('reach-hint').textContent=''; return; }
  const r = await fetch('campaign_new.php?count='+lid);
  const d = await r.json();
  reach = d.count||0;
  document.getElementById('reach-hint').textContent = reach+' opted-in contact(s) will receive this.';
  updateCost();
}
/* A personal number has no 24-hour window, so the warning below only concerns Cloud API
   accounts. Sent from PHP so the two never disagree about which channel this client is on. */
const ON_CLOUD = <?= $PERSONAL ? 'false' : 'true' ?>;

function onFollow(){
  const box = document.getElementById('follow-box');
  if(!box) return;
  const on = (document.querySelector('input[name=has_follow]:checked')||{}).value === '1';
  box.style.display = on ? 'block' : 'none';
  // Not chosen → send no id at all, so the campaign saves with no follow-up.
  document.getElementById('follow_flow_id').disabled = !on;

  const trig = document.getElementById('follow_trigger').value;
  const delay = document.getElementById('follow_delay');

  // For "haven't replied" the wait IS the rule, so it must not be zero.
  const isNoReply = trig === 'no_reply';
  document.getElementById('delay-label').textContent = isNoReply ? 'Wait this long for a reply (minutes)' : 'Wait first (minutes)';
  if (isNoReply && Number(delay.value) === 0) delay.value = 1440;
  delay.min = isNoReply ? 1 : 0;

  // Only "when they reply" opens the 24-hour window; everything else starts before it.
  const beforeReply = on && ON_CLOUD && trig !== 'replied';
  document.getElementById('follow-window-warn').style.display = beforeReply ? 'block' : 'none';
}

function onWhen(){
  const later = document.querySelector('input[name=when]:checked').value==='later';
  const s=document.getElementById('sched'); s.style.display = later?'block':'none'; if(later) s.required=true; else s.required=false;
  document.getElementById('submit-btn').textContent = later ? 'Schedule Campaign' : 'Create Campaign';
}
function updateCost(){
  document.getElementById('cost').textContent = reach ? (reach+' credits') : '—';
  document.getElementById('cost-warn').style.display = (reach>BALANCE)?'block':'none';
}
/* ── Duplicating: put the copied template fields back ───────────────────────────────
   The header, variable and button inputs don't exist until a template is picked — they are
   built by onTemplate() from the template's own spec. So the copy has to build them first
   and then fill them, rather than rendering values into markup that isn't there yet. */
const PREFILL = <?= json_encode($copy['fields'] ?? null, JSON_UNESCAPED_UNICODE) ?>;
(function applyCopy(){
  if(!PREFILL) return;
  onTemplate();                                  // Cloud only; returns immediately on a personal number
  const put = (sel, v) => { const el = document.querySelector(sel); if(el && v != null && v !== '') el.value = v; };

  // Body and header variables: the source drives which inputs are visible, so set it first.
  const vars = (prefix, rows) => Object.entries(rows || {}).forEach(([i, v]) => {
    const sel = document.querySelector(`select[name="${prefix}_source[${i}]"]`);
    if(sel){ sel.value = v.source || 'static'; toggleVar(sel, prefix, i); }
    put(`input[name="${prefix}_value[${i}]"]`, v.value);
    put(`input[name="${prefix}_fallback[${i}]"]`, v.fallback);
  });
  vars('var',  PREFILL.vars);
  vars('hvar', PREFILL.header_vars);

  put('#hm-url', PREFILL.header_media);
  const loc = PREFILL.header_loc || {};
  put('input[name="header_lat"]', loc.lat);
  put('input[name="header_lng"]', loc.lng);
  put('input[name="header_loc_name"]', loc.name);
  put('input[name="header_address"]', loc.address);
  Object.entries(PREFILL.buttons || {}).forEach(([i, v]) => put(`input[name="btn_value[${i}]"]`, v));

  onList();                                      // show the audience size straight away
})();

// Set the follow-up controls to their correct initial state — in particular the flow select
// starts disabled, so a campaign saved without choosing one posts no id at all.
onFollow();
</script>

<?php layout_footer(); ?>
