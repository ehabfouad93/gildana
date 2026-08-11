<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/../includes/ai.php';

$cid = (int) $CLIENT['id'];

/* ── AJAX live tests ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    if ($a === 'test_wa') {
        $fresh = db_row("SELECT * FROM clients WHERE id=?", [$cid]);
        $res = wa_fetch_templates($fresh ?: []);
        if ($res['ok']) {
            $approved = count(array_filter($res['templates'], fn($t) => strtoupper((string) ($t['status'] ?? '')) === 'APPROVED'));
            json_out(['ok' => true, 'msg' => count($res['templates']) . ' templates (' . $approved . ' approved)']);
        }
        json_out(['ok' => false, 'msg' => $res['error']]);
    }
    if ($a === 'test_ai') {
        $fresh = db_row("SELECT * FROM clients WHERE id=?", [$cid]);
        $res = ai_test_key($fresh ?: []);
        json_out(['ok' => $res['ok'], 'msg' => $res['ok'] ? 'AI key works' : $res['error']]);
    }
    json_out(['ok' => false]);
}

/* ── gather checks ── */
$checks = [];
$add = function (string $state, string $title, string $detail, string $fix = '') use (&$checks) {
    $checks[] = compact('state', 'title', 'detail', 'fix');
};

// 1. WhatsApp credentials
if ($CLIENT['access_token_enc'] && $CLIENT['phone_number_id']) {
    $add('ok', 'WhatsApp connected', 'Phone Number ID + access token are set.', '');
} else {
    $add('fail', 'WhatsApp not connected', 'Missing phone number ID or access token — nothing can send.', 'Contact Gildana to add your WhatsApp API credentials.');
}

// 2. Credits
$bal = (int) $CLIENT['credits_balance'];
$add($bal > 0 ? 'ok' : 'fail', 'Credits', $bal . ' credits available.', $bal > 0 ? '' : 'Ask Gildana to top up — every bot/lead message costs 1 credit.');

// 3. AI key (needed for AI branch / lead scoring)
if ($CLIENT['ai_provider'] && $CLIENT['ai_api_key_enc']) {
    $add('ok', 'AI engine', 'Provider: ' . e((string) $CLIENT['ai_provider']) . '. Use "Test AI key".', '');
} else {
    $add('warn', 'AI engine not set', 'AI branch and lead scoring won\'t work without a key.', 'Settings → AI Engine → add your Claude/OpenAI key.');
}

// 4. Webhook receiving (global signal — any client's inbound proves Meta reaches webhook.php)
$lastWh = db_val("SELECT MAX(received_at) FROM webhook_events");
if ($lastWh) {
    $mins = (int) round((time() - strtotime((string) $lastWh)) / 60);
    $add('ok', 'Webhook receiving', 'Meta last reached your webhook ' . ($mins <= 1 ? 'just now' : $mins . ' min ago') . '.', '');
} else {
    $add('fail', 'Webhook never received anything', 'Meta has NOT contacted webhook.php. Chatbot replies and lead conversations cannot work.',
        'In Meta → WhatsApp → Configuration: set Callback URL to https://' . e((string) ($_SERVER['HTTP_HOST'] ?? 'your-domain')) . dirname(dirname((string) $_SERVER['SCRIPT_NAME'])) . '/webhook.php, use your verify token, and SUBSCRIBE to the "messages" field.');
}

// 5. Cron running (heartbeat)
$hb = @filemtime(__DIR__ . '/../cron/.heartbeat');
if ($hb && $hb > time() - 180) {
    $add('ok', 'Background worker (cron)', 'Ran ' . max(0, (int) round((time() - $hb) / 60)) . ' min ago.', '');
} elseif ($hb) {
    $add('fail', 'Cron stalled', 'The worker last ran ' . (int) round((time() - $hb) / 60) . ' min ago (should be every minute).', 'Check your cron job is still enabled in cPanel.');
} else {
    $add('fail', 'Cron never ran', 'The background worker has not run. Lead import & timers won\'t work.', 'Add the per-minute cron for cron/dispatch.php (or the curl URL) in cPanel → Cron Jobs.');
}

// 6. Chatbot automations
$bots = db_all("SELECT * FROM flows WHERE client_id=? AND kind='bot'", [$cid]);
$activeBots = array_filter($bots, fn($f) => $f['status'] === 'active');
if (!$bots) {
    $add('warn', 'Chatbot automations', 'None created yet.', 'Create one in Automations.');
} elseif (!$activeBots) {
    $add('warn', 'Chatbot automations', count($bots) . ' exist but none are Active.', 'Toggle an automation to Active in the Automations list.');
} else {
    $problems = [];
    foreach ($activeBots as $f) {
        if (!$f['first_step_id']) $problems[] = '"' . $f['name'] . '" has no starting step (connect the Trigger node to a step).';
        elseif ((int) db_val("SELECT COUNT(*) FROM flow_steps WHERE flow_id=?", [(int) $f['id']]) === 0) $problems[] = '"' . $f['name'] . '" has no steps.';
    }
    if ($problems) $add('fail', 'Chatbot automations', count($activeBots) . ' active, but some are misconfigured:', implode(' ', $problems));
    else $add('ok', 'Chatbot automations', count($activeBots) . ' active and wired correctly.', '');
}

// 7. Lead qualifiers
$quals = db_all("SELECT * FROM flows WHERE client_id=? AND kind='qualifier'", [$cid]);
$activeQ = array_filter($quals, fn($f) => $f['status'] === 'active');
if (!$quals) {
    $add('warn', 'Lead qualifiers', 'None created yet.', 'Create one in Lead Qualifier.');
} elseif (!$activeQ) {
    $add('warn', 'Lead qualifiers', count($quals) . ' exist but none are Active.', 'Toggle a qualifier to Active.');
} else {
    $problems = [];
    foreach ($activeQ as $f) {
        $sc = json_decode((string) $f['source_config'], true) ?: [];
        if (empty($sc['csv_url'])) $problems[] = '"' . $f['name'] . '" has no Google Sheet CSV URL.';
        if (!$f['first_step_id']) $problems[] = '"' . $f['name'] . '" has no outreach template / steps configured.';
    }
    if (!($CLIENT['ai_provider'] && $CLIENT['ai_api_key_enc'])) $problems[] = 'No AI key set — leads will score 0.';
    if ($problems) $add('fail', 'Lead qualifiers', count($activeQ) . ' active, but:', implode(' ', $problems));
    else $add('ok', 'Lead qualifiers', count($activeQ) . ' active and configured.', '');
}

// 8. Recent activity
$runs24 = (int) db_val("SELECT COUNT(*) FROM flow_runs WHERE client_id=? AND created_at > (NOW() - INTERVAL 24 HOUR)", [$cid]);
$msgs24 = (int) db_val("SELECT COUNT(*) FROM flow_messages WHERE client_id=? AND created_at > (NOW() - INTERVAL 24 HOUR)", [$cid]);
$add($runs24 || $msgs24 ? 'ok' : 'warn', 'Recent automation activity (24h)', "$runs24 flow runs · $msgs24 messages sent by automations.", $runs24 || $msgs24 ? '' : 'No activity yet — expected if nobody has messaged the bot / no leads imported.');

$fails = count(array_filter($checks, fn($c) => $c['state'] === 'fail'));

client_header('Health Check', 'automations', $CLIENT);
page_head('Automation Health Check');
?>
<div class="alert <?= $fails ? 'error' : 'success' ?>">
  <?= $fails ? "❌ $fails problem(s) found below — fix the red items." : '✅ Everything needed for automations & lead qualifiers looks good.' ?>
</div>

<div class="card card-flush">
  <div class="table-wrap"><table class="data">
    <tbody>
    <?php foreach ($checks as $c):
      $pill = ['ok' => 'green', 'warn' => 'gold', 'fail' => 'red'][$c['state']];
      $icon = ['ok' => '✓', 'warn' => '!', 'fail' => '✕'][$c['state']];
    ?>
      <tr>
        <td style="width:90px"><span class="pill <?= $pill ?>"><?= $icon ?> <?= ucfirst($c['state']) ?></span></td>
        <td>
          <strong><?= e($c['title']) ?></strong><br>
          <span class="text-muted" style="font-size:12.5px"><?= e($c['detail']) ?></span>
          <?php if ($c['fix']): ?><br><span style="font-size:12.5px;color:var(--info)">→ <?= e($c['fix']) ?></span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card">
  <h2>Live tests</h2>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <button class="btn btn-ghost btn-sm" id="btn-wa">Test WhatsApp connection</button>
    <button class="btn btn-ghost btn-sm" id="btn-ai">Test AI key</button>
  </div>
  <div id="test-out" class="mt10"></div>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
async function runTest(action, btn){
  const out=document.getElementById('test-out'); btn.disabled=true; const t=btn.textContent; btn.textContent='Testing…';
  try{ const fd=new FormData(); fd.append('action',action); fd.append('csrf_token',CSRF);
    const r=await fetch('',{method:'POST',body:fd}); const d=await r.json();
    out.innerHTML='<div class="alert '+(d.ok?'success':'error')+'">'+(d.ok?'✓ ':'✕ ')+(d.msg||'')+'</div>';
  }catch(e){ out.innerHTML='<div class="alert error">Network error.</div>'; }
  btn.disabled=false; btn.textContent=t;
}
document.getElementById('btn-wa').onclick=e=>runTest('test_wa',e.target);
document.getElementById('btn-ai').onclick=e=>runTest('test_ai',e.target);
</script>

<?php layout_footer(); ?>
