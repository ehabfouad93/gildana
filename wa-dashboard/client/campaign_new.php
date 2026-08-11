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
$err = '';

/* ── Create campaign ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    verify_csrf();
    $name       = trim((string) ($_POST['name'] ?? ''));
    $templateId = (int) ($_POST['template_id'] ?? 0);
    $listId     = (int) ($_POST['list_id'] ?? 0);
    $when       = (string) ($_POST['when'] ?? 'now');
    $schedRaw   = trim((string) ($_POST['scheduled_at'] ?? ''));

    $template = db_row("SELECT * FROM templates WHERE id=? AND client_id=? AND status='APPROVED'", [$templateId, $cid]);
    $list     = db_row("SELECT * FROM contact_lists WHERE id=? AND client_id=?", [$listId, $cid]);

    $scheduledAt = null;
    if ($when === 'later') {
        $ts = strtotime($schedRaw);
        if ($ts === false || $ts < time() - 60) $err = 'Choose a valid future date & time to schedule.';
        else $scheduledAt = date('Y-m-d H:i:s', $ts);
    }

    if ($name === '')          $err = $err ?: 'Enter a campaign name.';
    elseif (!$template)        $err = $err ?: 'Choose an approved template.';
    elseif (!$list)            $err = $err ?: 'Choose an audience list.';

    // Build variable map from POST
    $varCount = (int) ($template['variable_count'] ?? 0);
    $varMap = [];
    for ($i = 1; $i <= $varCount; $i++) {
        $varMap[(string) $i] = [
            'source'   => (string) ($_POST['var_source'][$i] ?? 'static'),
            'value'    => (string) ($_POST['var_value'][$i] ?? ''),
            'fallback' => (string) ($_POST['var_fallback'][$i] ?? ''),
        ];
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
                    "INSERT INTO campaigns (client_id,name,template_id,list_id,variable_map,status,scheduled_at,total_count,created_at,started_at)
                     VALUES (?,?,?,?,?,?,?,?,NOW(),?)",
                    [$cid, $name, $templateId, $listId, json_encode($varMap, JSON_UNESCAPED_UNICODE),
                     $status, $scheduledAt, count($recipients), $scheduledAt ? null : date('Y-m-d H:i:s')]
                );

                $ins = $pdo->prepare(
                    "INSERT INTO campaign_messages (campaign_id,client_id,contact_id,phone_e164,rendered_components,status,updated_at)
                     VALUES (?,?,?,?,?, 'queued', NOW())"
                );
                foreach ($recipients as $c) {
                    $components = campaign_components($varMap, $varCount, $c);
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

client_header('New Campaign', 'campaigns', $CLIENT);
page_head('New Campaign');

if (!client_ready($CLIENT)): ?>
  <div class="alert error">Your WhatsApp account isn't connected yet. Contact Gildana before sending campaigns.</div>
  <?php layout_footer(); exit;
endif;

if (!$templates): ?>
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
    <h2>1 · Campaign &amp; Template</h2>
    <div class="field"><span class="lbl">Campaign Name</span><input type="text" name="name" value="<?= old('name') ?>" placeholder="e.g. July Promo" required></div>
    <div class="field">
      <span class="lbl">Template</span>
      <select name="template_id" id="template_id" required onchange="onTemplate()">
        <option value="">— choose an approved template —</option>
        <?php foreach ($templates as $t): ?>
          <option value="<?= (int) $t['id'] ?>" data-vars="<?= (int) $t['variable_count'] ?>"
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
  </div>

  <div class="card" id="vars-card" style="display:none">
    <h2>2 · Personalize Variables</h2>
    <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">Set what each <code>{{n}}</code> becomes. Use a fixed value, or pull from each contact's name / attribute (with a fallback if empty).</p>
    <div id="vars-wrap"></div>
  </div>

  <div class="card">
    <h2>3 · Audience &amp; Timing</h2>
    <div class="field">
      <span class="lbl">Send to List</span>
      <select name="list_id" id="list_id" required onchange="onList()">
        <option value="">— choose a list —</option>
        <?php foreach ($lists as $l): ?>
          <option value="<?= (int) $l['id'] ?>"><?= e((string) $l['name']) ?> (<?= (int) $l['members'] ?> members)</option>
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
let reach = 0;

function onTemplate(){
  const opt = document.querySelector('#template_id option:checked');
  const vars = parseInt(opt?.dataset.vars || '0', 10);
  const body = opt?.dataset.body || '';
  const prev = document.getElementById('tpl-preview');
  if (opt && opt.value){ prev.style.display='block'; document.getElementById('tpl-preview-body').textContent = body || '(no body text)'; }
  else prev.style.display='none';

  const wrap = document.getElementById('vars-wrap');
  const card = document.getElementById('vars-card');
  wrap.innerHTML = '';
  if (vars > 0){
    card.style.display='block';
    for (let i=1;i<=vars;i++){
      wrap.insertAdjacentHTML('beforeend', varRow(i));
    }
  } else card.style.display='none';
}
function varRow(i){
  return `<div class="card" style="background:var(--paper);border:0;padding:14px 16px;margin-bottom:12px">
    <div class="lbl" style="margin-bottom:8px">Variable {{${i}}}</div>
    <div class="grid3">
      <label><span class="lbl">Source</span>
        <select name="var_source[${i}]" onchange="toggleVar(this,${i})">
          <option value="static">Fixed text</option>
          <option value="name">Contact name</option>
          <option value="attribute">Contact attribute</option>
        </select>
      </label>
      <label id="vv-${i}"><span class="lbl">Value</span><input type="text" name="var_value[${i}]" placeholder="e.g. 20%"></label>
      <label id="vf-${i}" style="display:none"><span class="lbl">Fallback if empty</span><input type="text" name="var_fallback[${i}]" placeholder="e.g. Customer"></label>
    </div>
  </div>`;
}
function toggleVar(sel,i){
  const vv=document.getElementById('vv-'+i), vf=document.getElementById('vf-'+i);
  const lbl=vv.querySelector('.lbl'), inp=vv.querySelector('input');
  if(sel.value==='static'){ vv.style.display=''; lbl.textContent='Value'; inp.placeholder='e.g. 20%'; vf.style.display='none'; }
  else if(sel.value==='name'){ vv.style.display='none'; vf.style.display=''; }
  else { vv.style.display=''; lbl.textContent='Attribute key'; inp.placeholder='e.g. city'; vf.style.display=''; }
}
async function onList(){
  const lid = document.getElementById('list_id').value;
  if(!lid){ reach=0; updateCost(); document.getElementById('reach-hint').textContent=''; return; }
  const r = await fetch('campaign_new.php?count='+lid);
  const d = await r.json();
  reach = d.count||0;
  document.getElementById('reach-hint').textContent = reach+' opted-in contact(s) will receive this.';
  updateCost();
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
</script>

<?php layout_footer(); ?>
