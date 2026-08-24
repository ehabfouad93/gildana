<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/push.php';
require_once __DIR__ . '/../includes/channel.php';

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

// 1. WhatsApp connection — depends on which channel this account sends through.
if (channel_is_personal($CLIENT)) {
    $st = (string) ($CLIENT['personal_status'] ?? 'disconnected');
    if ($st === 'connected') {
        $num = $CLIENT['personal_msisdn'] ? ' (+' . $CLIENT['personal_msisdn'] . ')' : '';
        $add('ok', 'Your WhatsApp number is linked', 'Sending from your own number' . $num . '.', '');
    } else {
        $add('fail', 'Your WhatsApp number is not linked',
            'Nothing can send until you scan the QR code.',
            'Go to Settings → My WhatsApp Number and tap "Connect my WhatsApp".');
    }
    // Pacing: explain the current state rather than showing it as a problem.
    $size  = (int) ($CLIENT['slot_size'] ?: 15);
    $pause = (int) ($CLIENT['slot_pause_sec'] ?: 180);
    if (slot_cooling($CLIENT)) {
        $wait = max(0, strtotime((string) $CLIENT['next_slot_at']) - time());
        $add('warn', 'Sending is paused between batches',
            "Waiting {$wait}s before the next batch of {$size}.",
            'This is normal — it protects your number from being banned for sending too fast.');
    } else {
        $add('ok', 'Sending pace',
            "Up to {$size} messages at a time, then a " . round($pause / 60, 1) . " minute pause.", '');
    }
} elseif ($CLIENT['access_token_enc'] && $CLIENT['phone_number_id']) {
    $add('ok', 'WhatsApp connected', 'Phone Number ID + access token are set.', '');
} else {
    $add('fail', 'WhatsApp not connected', 'Missing phone number ID or access token — nothing can send.', 'Contact support to add your WhatsApp API credentials.');
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

// 4. Inbound receiving — scoped to THIS client's own messages, not a global signal, so it
//    actually tells a personal-channel client whether their replies are arriving.
$lastIn = db_val("SELECT MAX(created_at) FROM messages WHERE client_id=? AND direction='in'", [$cid]);
if (channel_is_personal($CLIENT)) {
    if ($lastIn) {
        $mins = (int) round((time() - strtotime((string) $lastIn)) / 60);
        $add('ok', 'Receiving replies', 'Your last inbound message arrived ' . ($mins <= 1 ? 'just now' : $mins . ' min ago') . '.', '');
    } elseif (($CLIENT['personal_status'] ?? '') === 'connected') {
        $add('warn', 'No inbound messages yet',
            'Your number is linked but no reply has come in yet — either nobody has messaged it, or the gateway\'s webhook needs resyncing.',
            'Ask a friend to message your number as a test, or use "Resync connection" in Settings → My WhatsApp Number.');
    } else {
        $add('warn', 'Not linked yet', 'Nothing can arrive until you connect your number.', 'Go to Settings → My WhatsApp Number.');
    }
} elseif ($lastIn) {
    $mins = (int) round((time() - strtotime((string) $lastIn)) / 60);
    $add('ok', 'Webhook receiving', 'Meta last reached your webhook ' . ($mins <= 1 ? 'just now' : $mins . ' min ago') . '.', '');
} else {
    $add('fail', 'Webhook never received anything', 'Meta has NOT contacted webhook.php. Chatbot replies and lead conversations cannot work.',
        'In Meta → WhatsApp → Configuration: set Callback URL to ' . e(app_base_url()) . '/webhook.php, use your verify token, and SUBSCRIBE to the "messages" field.');
}

// 4b. Push notifications
$vk = push_vapid_keys(false);
$nSubs = 0;
try { $nSubs = (int) db_val("SELECT COUNT(*) FROM push_subscriptions WHERE client_id=?", [$cid]); } catch (Throwable $e) {}
if (!$vk) {
    $add('warn', 'Push notifications not set up', 'Nobody can be alerted on their phone when a customer replies.',
         'Ask Gildana to open Admin → Settings → Push Notifications and click "Generate keys" (one time).');
} elseif ($nSubs === 0) {
    $add('warn', 'No devices subscribed', 'Push is ready, but no phone has turned notifications on yet.',
         'On your phone: install the app to the Home Screen, then Settings → Notifications → Enable.');
} else {
    $add('ok', 'Push notifications', $nSubs . ' device' . ($nSubs === 1 ? '' : 's') . ' will be alerted on a new reply.', '');
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

<?php
/* Why each recent incoming message did or did not get an answer. Silence has several
   legitimate causes and they are indistinguishable from a fault without this. */
$inbound = [];
try {
    $inbound = db_all("SELECT * FROM inbound_log WHERE client_id=? ORDER BY id DESC LIMIT 25", [$cid]);
} catch (Throwable $e) { /* migration 014 not applied yet */ }
$LABEL = [
    'replied'     => ['green', 'Replied'],
    'no_send'     => ['red',   'Tried but nothing sent'],
    'no_flow'     => ['amber', 'No automation matched'],
    'bot_paused'  => ['gray',  'Bot paused — human handling'],
    'no_text'     => ['gray',  'No text to match'],
    'flow_broken' => ['red',   'Flow step missing'],
];
?>
<div class="card">
  <h2>Why incoming messages were or weren't answered</h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">
    The last 25 messages your number received, and what the bot decided for each one.
    <strong>Not every silence is a fault</strong> — but this says which is which.
  </p>
  <?php if (!$inbound): ?>
    <div class="alert info" style="font-size:12.5px">Nothing recorded yet. Send your number a message and refresh this page.</div>
  <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>When</th><th>Message</th><th>Outcome</th><th>Why</th></tr></thead>
      <tbody>
      <?php foreach ($inbound as $r):
        [$cls, $lbl] = $LABEL[(string) $r['decision']] ?? ['gray', (string) $r['decision']]; ?>
        <tr>
          <td class="text-muted" style="white-space:nowrap"><?= e(date('d M H:i', strtotime((string) $r['created_at']))) ?></td>
          <td><?= e(mb_substr((string) $r['body'], 0, 60)) ?: '<span class="text-muted">—</span>' ?></td>
          <td><span class="pill <?= $cls ?>"><?= e($lbl) ?></span></td>
          <td class="text-muted" style="font-size:12px"><?= e((string) $r['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="hint" style="margin-top:10px">
      <strong>No automation matched</strong> is the usual reason a follow-up message goes unanswered:
      a new contact's first message starts your <em>welcome</em> flow, but everything after it only
      gets a reply if a keyword matches or you have a <em>default</em> catch-all flow switched on.
      Set one in <a href="automations.php">Automations</a> or use the <a href="agents.php">AI Chat Agent</a>.
    </div>
  <?php endif; ?>
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
