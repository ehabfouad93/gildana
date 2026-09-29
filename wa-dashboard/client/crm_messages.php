<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';

/**
 * Automatic messages: what the CRM sends by itself when a lead reaches a stage, and the
 * sequences that follow up with leads who go quiet. Plus the log of everything it sent, because
 * "did the customer get the location?" is the question this page will be opened to answer.
 */
$cid = (int) $CLIENT['id'];
if (!is_client_admin()) {
    http_response_code(403);
    client_header('Automatic messages', 'crm', $CLIENT);
    echo '<div class="card" style="max-width:560px"><h2 style="margin-top:0">For managers</h2><p class="text-muted">Only an Admin can set up automatic messages.</p></div>';
    layout_footer(); exit;
}
$stages = crm_stages($cid);
$stageMap = crm_stage_map($cid);
$tpls = crm_tpl_choices($cid);
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');

    if ($a === 'stage_msg') {
        $sid = (int) ($_POST['stage_id'] ?? 0);
        $tid = (int) ($_POST['template_id'] ?? 0);
        if (!isset($stageMap[$sid])) $err = 'Choose a stage.';
        elseif (!isset($tpls[$tid])) $err = 'Choose an approved template.';
        elseif ($tpls[$tid]['media'] && trim((string) ($_POST['media'] ?? '')) === '') $err = 'This template needs a ' . $tpls[$tid]['media'] . ' — paste its link.';
        else {
            db_run("INSERT INTO crm_stage_msgs (client_id,stage_id,template_id,vars,header_media,delay_minutes,on_arrival,active,created_at)
                    VALUES (?,?,?,?,?,?,?,1,NOW())
                    ON DUPLICATE KEY UPDATE template_id=VALUES(template_id), vars=VALUES(vars), header_media=VALUES(header_media),
                                            delay_minutes=VALUES(delay_minutes), on_arrival=VALUES(on_arrival), active=1",
                   [$cid, $sid, $tid, json_encode(crm_tokens_from_post('vars', $_POST), JSON_UNESCAPED_UNICODE),
                    trim((string) ($_POST['media'] ?? '')) ?: null, max(0, min(10080, (int) ($_POST['delay'] ?? 0))),
                    !empty($_POST['on_arrival']) ? 1 : 0]);
            flash('Saved. Leads moved to ' . $stageMap[$sid]['name'] . ' will get it.');
            redirect('crm_messages.php');
        }
    }
    if ($a === 'visit_msgs') {
        $ct = (int) ($_POST['confirm_tpl'] ?? 0); $rt = (int) ($_POST['remind_tpl'] ?? 0);
        $bs = (int) ($_POST['booked_stage'] ?? 0); $ds = (int) ($_POST['done_stage'] ?? 0);
        crm_settings_set($cid, [
            'visit_confirm_tpl' => isset($tpls[$ct]) ? $ct : null, 'visit_confirm_vars' => json_encode(crm_tokens_from_post('cvars', $_POST), JSON_UNESCAPED_UNICODE),
            'visit_remind_tpl'  => isset($tpls[$rt]) ? $rt : null, 'visit_remind_vars'  => json_encode(crm_tokens_from_post('rvars', $_POST), JSON_UNESCAPED_UNICODE),
            'visit_remind_hour' => max(0, min(23, (int) ($_POST['remind_hour'] ?? 18))),
            'visit_staff_minutes' => max(10, min(1440, (int) ($_POST['staff_minutes'] ?? 60))),
            'visit_booked_stage' => isset($stageMap[$bs]) ? $bs : null, 'visit_done_stage' => isset($stageMap[$ds]) ? $ds : null,
        ]);
        if (db_has_column('crm_settings', 'meet_soon_tpl')) {
            $mt = (int) ($_POST['soon_tpl'] ?? 0);
            crm_settings_set($cid, ['meet_soon_tpl' => isset($tpls[$mt]) ? $mt : null,
                'meet_soon_vars' => json_encode(crm_tokens_from_post('mvars', $_POST), JSON_UNESCAPED_UNICODE),
                'meet_soon_minutes' => max(5, min(240, (int) ($_POST['soon_minutes'] ?? 30)))]);
        }
        flash('Visit messages saved.');
        redirect('crm_messages.php#visit-msgs');
    }
    if ($a === 'stage_msg_toggle') {
        db_run("UPDATE crm_stage_msgs SET active=1-active WHERE id=? AND client_id=?", [(int) $_POST['id'], $cid]);
        redirect('crm_messages.php');
    }
    if ($a === 'stage_msg_delete') {
        db_run("DELETE FROM crm_stage_msgs WHERE id=? AND client_id=?", [(int) $_POST['id'], $cid]);
        flash('Removed.'); redirect('crm_messages.php');
    }

    if ($a === 'sequence') {
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $trig = (string) ($_POST['trigger_kind'] ?? '');
        $n    = (int) ($_POST['trigger_n'] ?? 0);
        $steps = [];
        foreach ((array) ($_POST['steps'] ?? []) as $st) {
            $tid = (int) ($st['template'] ?? 0);
            if (!$tid) continue;
            $delay = max(0, (int) ($st['delay'] ?? 0)) * (($st['unit'] ?? 'h') === 'd' ? 24 : 1);
            $steps[] = ['template_id' => $tid, 'delay_hours' => min(24 * 90, $delay),
                        'vars' => crm_tokens_from_post('vars', $st), 'media' => trim((string) ($st['media'] ?? ''))];
        }
        if ($name === '') $err = 'Give the sequence a name, like "No answer follow-up".';
        elseif (!isset(crm_seq_triggers()[$trig])) $err = 'Choose when a lead joins.';
        elseif (in_array($trig, ['no_answer', 'stale'], true) && $n < 1) $err = 'Say how many ' . ($trig === 'stale' ? 'days' : 'no-answers') . '.';
        elseif ($trig === 'stage' && !isset($stageMap[$n])) $err = 'Choose the stage.';
        elseif (!$steps) $err = 'Add at least one message.';
        elseif ($bad = array_filter($steps, fn($s) => !isset($tpls[$s['template_id']]))) $err = 'Every message needs an approved template.';
        elseif ($bad = array_filter($steps, fn($s) => $tpls[$s['template_id']]['media'] && $s['media'] === '')) $err = 'A template with a picture or file needs its link.';
        else {
            if ($id && db_val("SELECT COUNT(*) FROM crm_sequences WHERE id=? AND client_id=?", [$id, $cid])) {
                db_run("UPDATE crm_sequences SET name=?, trigger_kind=?, trigger_n=? WHERE id=?", [mb_substr($name, 0, 120), $trig, $n, $id]);
                db_run("DELETE FROM crm_seq_steps WHERE sequence_id=?", [$id]);
            } else {
                $id = db_insert("INSERT INTO crm_sequences (client_id,name,trigger_kind,trigger_n,active,created_at) VALUES (?,?,?,?,1,NOW())",
                                [$cid, mb_substr($name, 0, 120), $trig, $n]);
            }
            foreach ($steps as $i => $s) {
                db_insert("INSERT INTO crm_seq_steps (sequence_id,sort,delay_hours,template_id,vars,header_media) VALUES (?,?,?,?,?,?)",
                          [$id, $i, $s['delay_hours'], $s['template_id'], json_encode($s['vars'], JSON_UNESCAPED_UNICODE), $s['media'] ?: null]);
            }
            flash('Sequence saved.');
            redirect('crm_messages.php#sequences');
        }
    }
    if ($a === 'seq_toggle') {
        db_run("UPDATE crm_sequences SET active=1-active WHERE id=? AND client_id=?", [(int) $_POST['id'], $cid]);
        redirect('crm_messages.php#sequences');
    }
    if ($a === 'seq_delete') {
        db_run("DELETE FROM crm_sequences WHERE id=? AND client_id=?", [(int) $_POST['id'], $cid]);
        flash('Sequence deleted. Messages it had queued will not be sent.');
        db_run("UPDATE crm_msg_queue SET status='cancelled', error='The sequence was deleted.' WHERE client_id=? AND status='queued' AND reason LIKE ?",
               [$cid, 'seq:' . (int) $_POST['id'] . ':%']);
        redirect('crm_messages.php#sequences');
    }
}

$stageMsgs = [];
foreach (db_all("SELECT * FROM crm_stage_msgs WHERE client_id=?", [$cid]) as $m) $stageMsgs[(int) $m['stage_id']] = $m;
$seqs = db_all("SELECT q.*, (SELECT COUNT(*) FROM crm_seq_runs r WHERE r.sequence_id=q.id AND r.status='active') AS running,
                       (SELECT COUNT(*) FROM crm_seq_runs r WHERE r.sequence_id=q.id) AS total
                  FROM crm_sequences q WHERE q.client_id=? ORDER BY q.id", [$cid]);
$editSeq = isset($_GET['seq']) ? db_row("SELECT * FROM crm_sequences WHERE id=? AND client_id=?", [(int) $_GET['seq'], $cid]) : null;
$editSteps = $editSeq ? db_all("SELECT * FROM crm_seq_steps WHERE sequence_id=? ORDER BY sort, id", [(int) $editSeq['id']]) : [];
$log = db_all("SELECT q.*, c.name, c.phone_e164, t.wa_name FROM crm_msg_queue q JOIN contacts c ON c.id=q.contact_id
                LEFT JOIN templates t ON t.id=q.template_id WHERE q.client_id=? ORDER BY q.id DESC LIMIT 40", [$cid]);
$seqNames = array_column($seqs, 'name', 'id');
$reasonText = function (string $r) use ($stageMap, $seqNames): string {
    if (preg_match('/^stage:(\d+)/', $r, $m)) return 'Reached ' . ($stageMap[(int) $m[1]]['name'] ?? 'a stage');
    if (preg_match('/^seq:(\d+):(\d+)/', $r, $m)) return ($seqNames[(int) $m[1]] ?? 'A sequence') . ' — message ' . $m[2];
    if (str_starts_with($r, 'visit_confirm')) return 'Visit confirmation';
    if (str_starts_with($r, 'visit_remind')) return 'Visit reminder';
    return $r;
};
$tokenWord = fn(string $t) => str_starts_with($t, 'text:') ? '"' . substr($t, 5) . '"' : (crm_tpl_tokens()[$t] ?? $t);
$trigWords = function (array $q) use ($stageMap): string {
    return match ($q['trigger_kind']) {
        'no_answer' => 'After ' . (int) $q['trigger_n'] . ' "No answer" call' . ((int) $q['trigger_n'] === 1 ? '' : 's'),
        'stale'     => 'After ' . (int) $q['trigger_n'] . ' day' . ((int) $q['trigger_n'] === 1 ? '' : 's') . ' with no activity',
        'stage'     => 'When a lead reaches ' . ($stageMap[(int) $q['trigger_n']]['name'] ?? 'a removed stage'),
        'new_lead'  => 'When a new lead arrives',
        default     => $q['trigger_kind'],
    };
};

client_header('Automatic messages', 'crm', $CLIENT);
page_head('Automatic messages');
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
<?php if (!crm_auto_api_ready($CLIENT)): ?>
  <div class="alert error" style="font-size:13px" id="no-api">Automatic messages are sent from your <strong>WhatsApp Business API number</strong>,
    which is not connected yet — nothing set up here will go out until it is. <a href="settings.php">Connect it in Settings</a>.</div>
<?php endif; ?>
<?php if (!$tpls): ?>
  <div class="alert warn" style="font-size:13px">You have no approved WhatsApp templates yet. Automatic messages use templates, because
    WhatsApp allows a business to start a conversation only with one. <a href="templates.php">Create or sync templates</a>.</div>
<?php endif; ?>
<p class="text-muted" style="font-size:12.5px;margin-top:-6px">Set by managers only, and sent from the company's
  <strong>WhatsApp Business API number</strong> — never from a salesperson's phone. Messages wait for working hours if you set them
  (Assignment rules → Timers), and never go to anyone who opted out.</p>

<div class="card card-flush" id="stage-msgs">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">When a lead reaches a stage</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">For example: moved to <strong>Viewing</strong> → send the location and confirm the visit.</p></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Stage</th><th>Sends</th><th></th></tr></thead><tbody>
    <?php foreach ($stages as $st): $m = $stageMsgs[(int) $st['id']] ?? null; $t = $m ? ($tpls[(int) $m['template_id']] ?? null) : null; ?>
      <tr class="<?= $m && !(int) $m['active'] ? 'muted-row' : '' ?>">
        <td><strong><?= e($st['name']) ?></strong></td>
        <td><?php if ($m): ?>
            <?= e($t ? $t['name'] : 'A template that is no longer approved') ?>
            <span class="text-muted" style="display:block;font-size:12px"><?= (int) $m['delay_minutes'] ? 'After ' . (int) $m['delay_minutes'] . ' minutes' : 'Straight away' ?>
              <?= (int) $m['on_arrival'] ? ' · also for new leads arriving here' : '' ?>
              <?php $vv = json_decode((string) $m['vars'], true) ?: []; if ($vv): ?> · <?= e(implode(', ', array_map(fn($i, $v) => '{{' . ($i + 1) . '}} ' . $tokenWord($v), array_keys($vv), $vv))) ?><?php endif; ?>
              <?= !(int) $m['active'] ? ' · <strong>off</strong>' : '' ?></span>
          <?php else: ?><span class="text-muted">Nothing</span><?php endif; ?></td>
        <td style="text-align: end;white-space:nowrap">
          <button type="button" class="btn btn-ghost btn-sm" onclick='smEdit(<?= json_encode(['stage' => (int) $st['id'], 'name' => $st['name'],
             'template' => $m ? (int) $m['template_id'] : 0, 'vars' => $m ? (json_decode((string) $m['vars'], true) ?: []) : [],
             'media' => $m['header_media'] ?? '', 'delay' => $m ? (int) $m['delay_minutes'] : 0, 'arrival' => $m ? (int) $m['on_arrival'] : 0], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><?= $m ? 'Edit' : 'Set a message' ?></button>
          <?php if ($m): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="stage_msg_toggle"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button class="btn-link"><?= (int) $m['active'] ? 'Turn off' : 'Turn on' ?></button></form>
            <form method="post" style="display:inline" onsubmit="return confirm('Remove this stage message?')"><?= csrf_field() ?><input type="hidden" name="action" value="stage_msg_delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <button class="btn-link" style="color:var(--danger)">Remove</button></form>
          <?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>

<div class="card" id="sm-form" hidden>
  <h2 id="sm-title">Message for a stage</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="stage_msg"><input type="hidden" name="stage_id" id="sm-stage">
    <div class="field" style="max-width:480px"><span class="lbl">Template</span>
      <select name="template_id" id="sm-tpl" class="tpl-pick" data-vars="sm-vars" data-media="sm-media-wrap"><option value="">Choose…</option>
        <?php foreach ($tpls as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name'] . ' (' . $t['lang'] . ')') ?></option><?php endforeach; ?></select>
      <div class="tpl-preview text-muted" id="sm-tpl-prev"></div></div>
    <div class="tpl-vars" id="sm-vars" data-prefix="vars"></div>
    <div class="field" id="sm-media-wrap" hidden style="max-width:480px"><span class="lbl">Picture or file link</span><input type="url" name="media" id="sm-media" placeholder="https://…"></div>
    <div class="grid2" style="max-width:640px">
      <div class="field"><span class="lbl">Send</span><select name="delay" id="sm-delay">
        <option value="0">Straight away</option><option value="5">After 5 minutes</option><option value="30">After 30 minutes</option>
        <option value="60">After 1 hour</option><option value="180">After 3 hours</option><option value="1440">The next day</option></select></div>
      <label class="mod-all" style="align-self:end;margin-bottom:12px"><input type="checkbox" name="on_arrival" value="1" id="sm-arrival"> Also for new leads that arrive straight into this stage</label>
    </div>
    <div style="display:flex;gap:8px"><button class="btn btn-primary">Save</button>
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('sm-form').hidden=true">Cancel</button></div>
  </form>
</div>

<?php $vs = crm_settings($cid); ?>
<div class="card" id="visit-msgs">
  <h2>Site visits and online meetings</h2>
  <p class="text-muted" style="font-size:12.5px;margin-top:-4px">When a visit or meeting is booked on a lead, and the day before it. Useful fields:
    Visit date, Visit time, Visit place, Project, Salesperson's name and phone. For an online meeting, Visit place and
    Online meeting link are the meeting's link.</p>
  <form method="post" id="visit-f">
    <?= csrf_field() ?><input type="hidden" name="action" value="visit_msgs">
    <div class="grid2">
      <div>
        <div class="field"><span class="lbl">Confirmation, when a visit is booked</span>
          <select name="confirm_tpl" id="vc-tpl"><option value="0">Don't send</option>
            <?php foreach ($tpls as $t): ?><option value="<?= $t['id'] ?>" <?= (int) $vs['visit_confirm_tpl'] === $t['id'] ? 'selected' : '' ?>><?= e($t['name'] . ' (' . $t['lang'] . ')') ?></option><?php endforeach; ?></select>
          <div class="tpl-preview text-muted" id="vc-prev"></div></div>
        <div class="tpl-vars" id="vc-vars"></div>
      </div>
      <div>
        <div class="field"><span class="lbl">Reminder, the day before</span>
          <select name="remind_tpl" id="vr-tpl"><option value="0">Don't send</option>
            <?php foreach ($tpls as $t): ?><option value="<?= $t['id'] ?>" <?= (int) $vs['visit_remind_tpl'] === $t['id'] ? 'selected' : '' ?>><?= e($t['name'] . ' (' . $t['lang'] . ')') ?></option><?php endforeach; ?></select>
          <div class="tpl-preview text-muted" id="vr-prev"></div></div>
        <div class="tpl-vars" id="vr-vars"></div>
        <div class="field"><span class="lbl">Send the reminder at</span><select name="remind_hour">
          <?php for ($h = 8; $h <= 22; $h++): ?><option value="<?= $h ?>" <?= (int) $vs['visit_remind_hour'] === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?> the day before</option><?php endfor; ?></select></div>
      </div>
    </div>
    <div class="grid2">
      <div class="field"><span class="lbl">Remind the salesperson</span><select name="staff_minutes">
        <?php foreach ([30 => '30 minutes before', 60 => '1 hour before', 120 => '2 hours before', 1440 => 'The day before'] as $m => $l): ?>
          <option value="<?= $m ?>" <?= (int) $vs['visit_staff_minutes'] === $m ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div></div>
      <div class="field"><span class="lbl">When a visit is booked, move the lead to</span><select name="booked_stage"><option value="0">Leave the stage as it is</option>
        <?php foreach ($stages as $st): ?><option value="<?= (int) $st['id'] ?>" <?= (int) $vs['visit_booked_stage'] === (int) $st['id'] ? 'selected' : '' ?>><?= e($st['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><span class="lbl">When they came, move the lead to</span><select name="done_stage"><option value="0">Leave the stage as it is</option>
        <?php foreach ($stages as $st): ?><option value="<?= (int) $st['id'] ?>" <?= (int) $vs['visit_done_stage'] === (int) $st['id'] ? 'selected' : '' ?>><?= e($st['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <?php if (db_has_column('crm_settings', 'meet_soon_tpl')): ?>
    <div class="grid2">
      <div>
        <div class="field"><span class="lbl">Online meetings: "starting soon", with the link</span>
          <select name="soon_tpl" id="ms-tpl"><option value="0">Don't send</option>
            <?php foreach ($tpls as $t): ?><option value="<?= $t['id'] ?>" <?= (int) ($vs['meet_soon_tpl'] ?? 0) === $t['id'] ? 'selected' : '' ?>><?= e($t['name'] . ' (' . $t['lang'] . ')') ?></option><?php endforeach; ?></select>
          <div class="tpl-preview text-muted" id="ms-prev"></div></div>
        <div class="tpl-vars" id="ms-vars"></div>
      </div>
      <div class="field"><span class="lbl">Send it</span><select name="soon_minutes">
        <?php foreach ([10 => '10 minutes before', 15 => '15 minutes before', 30 => '30 minutes before', 60 => '1 hour before', 120 => '2 hours before'] as $m => $l): ?>
          <option value="<?= $m ?>" <?= (int) ($vs['meet_soon_minutes'] ?? 30) === $m ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <?php endif; ?>
    <button class="btn btn-primary">Save visit messages</button>
  </form>
</div>

<div class="card card-flush" id="sequences">
  <div style="padding:14px 18px" class="row-between">
    <div><h2 style="border:0;padding:0;margin:0">Follow-up sequences</h2>
      <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">A few messages spaced out over days, for leads who go quiet. A lead leaves the sequence
        the moment they reply, or when the lead is won or lost — a person should take it from there.</p></div>
    <a class="btn btn-primary btn-sm" href="?seq=0#seq-form">+ New sequence</a>
  </div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Sequence</th><th>Starts</th><th class="num">Running now</th><th class="num">Leads so far</th><th></th></tr></thead><tbody>
    <?php if (!$seqs): ?><tr><td colspan="5"><div class="empty">No sequences yet. A good first one: after 3 "No answer" calls, send
      "We tried to reach you", then an offer 3 days later.</div></td></tr><?php endif; ?>
    <?php foreach ($seqs as $q): $nSteps = (int) db_val("SELECT COUNT(*) FROM crm_seq_steps WHERE sequence_id=?", [(int) $q['id']]); ?>
      <tr class="<?= (int) $q['active'] ? '' : 'muted-row' ?>"><td><strong><?= e((string) $q['name']) ?></strong>
          <span class="text-muted" style="display:block;font-size:12px"><?= $nSteps ?> message<?= $nSteps === 1 ? '' : 's' ?><?= (int) $q['active'] ? '' : ' · off' ?></span></td>
        <td style="font-size:13px"><?= e($trigWords($q)) ?></td>
        <td class="num"><?= (int) $q['running'] ?></td><td class="num"><?= (int) $q['total'] ?></td>
        <td style="text-align: end;white-space:nowrap">
          <a class="btn btn-ghost btn-sm" href="?seq=<?= (int) $q['id'] ?>#seq-form">Edit</a>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="seq_toggle"><input type="hidden" name="id" value="<?= (int) $q['id'] ?>">
            <button class="btn-link"><?= (int) $q['active'] ? 'Pause' : 'Start again' ?></button></form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this sequence? Leads in it get no more messages from it.')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="seq_delete"><input type="hidden" name="id" value="<?= (int) $q['id'] ?>">
            <button class="btn-link" style="color:var(--danger)">Delete</button></form></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>

<?php if (isset($_GET['seq']) || ($err && ($_POST['action'] ?? '') === 'sequence')):
  $sv = ($err && ($_POST['action'] ?? '') === 'sequence') ? $_POST : ($editSeq ?: []); ?>
<div class="card" id="seq-form">
  <h2><?= $editSeq ? 'Edit sequence' : 'New sequence' ?></h2>
  <form method="post" id="seq-f">
    <?= csrf_field() ?><input type="hidden" name="action" value="sequence"><input type="hidden" name="id" value="<?= (int) ($editSeq['id'] ?? 0) ?>">
    <div class="grid2" style="max-width:760px">
      <div class="field"><span class="lbl">Name</span><input type="text" name="name" maxlength="120" value="<?= e((string) ($sv['name'] ?? '')) ?>" placeholder="No answer follow-up"></div>
      <div class="field"><span class="lbl">A lead joins</span><select name="trigger_kind" id="seq-trig">
        <?php foreach (crm_seq_triggers() as $k => $l): ?><option value="<?= $k ?>" <?= ($sv['trigger_kind'] ?? 'no_answer') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field" id="seq-n-wrap"><span class="lbl" id="seq-n-lbl">How many</span><input type="number" name="trigger_n" id="seq-n" min="1" value="<?= (int) ($sv['trigger_n'] ?? 3) ?>"></div>
      <div class="field" id="seq-stage-wrap" hidden><span class="lbl">Stage</span><select id="seq-stage">
        <?php foreach ($stages as $st): ?><option value="<?= (int) $st['id'] ?>" <?= (int) ($sv['trigger_n'] ?? 0) === (int) $st['id'] ? 'selected' : '' ?>><?= e($st['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <h3 style="font-size:14px;margin:14px 0 8px">Messages</h3>
    <div id="seq-steps"></div>
    <button type="button" class="btn btn-ghost btn-sm" onclick="seqStep()">+ Add a message</button>
    <div style="display:flex;gap:8px" class="mt10"><button class="btn btn-primary">Save sequence</button>
      <a class="btn btn-ghost" href="crm_messages.php#sequences">Cancel</a></div>
  </form>
</div>
<?php endif; ?>

<div class="card card-flush" id="auto-log">
  <div style="padding:14px 18px"><h2 style="border:0;padding:0;margin:0">Sent automatically</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">The last 40, newest first, and what became of each.</p></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>When</th><th>Lead</th><th>Why</th><th>Template</th><th>Result</th></tr></thead><tbody>
    <?php if (!$log): ?><tr><td colspan="5"><div class="empty">Nothing sent yet.</div></td></tr><?php endif; ?>
    <?php foreach ($log as $l): ?>
      <tr><td class="text-muted" style="white-space:nowrap"><?= e(date('j M, H:i', strtotime((string) ($l['sent_at'] ?: $l['due_at'])))) ?></td>
        <td><a href="crm_lead.php?id=<?= (int) $l['contact_id'] ?>"><?= e((string) ($l['name'] ?: '+' . $l['phone_e164'])) ?></a></td>
        <td style="font-size:13px"><?= e($reasonText((string) $l['reason'])) ?></td>
        <td class="text-muted" style="font-size:12.5px"><?= e((string) ($l['wa_name'] ?? '—')) ?></td>
        <td><span class="pill <?= ['sent' => 'green', 'failed' => 'red', 'queued' => 'blue'][$l['status']] ?? 'gray' ?>"><?= e(['queued' => 'Waiting', 'sending' => 'Sending'][$l['status']] ?? ucfirst((string) $l['status'])) ?></span>
          <?php if ($l['error']): ?><span class="text-muted" style="display:block;font-size:12px"><?= e((string) $l['error']) ?></span><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>

<script>
const TPLS = <?= json_encode($tpls, JSON_UNESCAPED_UNICODE) ?>;
const TOKENS = <?= json_encode(crm_tpl_tokens(), JSON_UNESCAPED_UNICODE) ?>;
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const $ = id => document.getElementById(id);
/* One picker per {{n}}: choose what fills it, or type fixed text. */
function renderVars(box, tplId, tokens, prefix){
  const t = TPLS[tplId]; box.innerHTML = '';
  if (!t) return;
  const n = t.body + t.header_vars;
  // "vars" → "vars_text"; "steps[0][vars]" → "steps[0][vars_text]"
  const textName = prefix.endsWith(']') ? prefix.slice(0, -1) + '_text]' : prefix + '_text';
  const guess = ['first_name', 'project', 'owner_name', 'owner_phone', 'visit_date', 'visit_time', 'visit_place'];
  for (let i = 0; i < n; i++) {
    const cur = tokens[i] || guess[i] || 'name';
    const isText = cur.startsWith('text:');
    const row = document.createElement('div'); row.className = 'tpl-var';
    row.innerHTML = `<span class="tpl-var-n">${i < t.body ? '{{' + (i + 1) + '}}' : 'Header {{' + (i - t.body + 1) + '}}'}</span>
      <select name="${prefix}[${i}]">${Object.entries(TOKENS).map(([k, l]) => `<option value="${k}" ${(isText ? 'text' : cur) === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>
       <input type="text" name="${textName}[${i}]" value="${isText ? esc(cur.slice(5)) : ''}" placeholder="Type the text" ${isText ? '' : 'hidden'}>`;
    row.querySelector('select').onchange = e => { row.querySelector('input').hidden = e.target.value !== 'text'; };
    box.appendChild(row);
  }
}
function tplPicked(sel, varsBox, mediaWrap, prev, tokens, prefix){
  const t = TPLS[sel.value];
  renderVars(varsBox, sel.value, tokens || [], prefix);
  if (mediaWrap) mediaWrap.hidden = !(t && t.media);
  if (prev) prev.textContent = t ? t.text : '';
}
function smEdit(d){
  $('sm-form').hidden = false; $('sm-title').textContent = 'Message when a lead reaches ' + d.name;
  $('sm-stage').value = d.stage; $('sm-tpl').value = d.template || ''; $('sm-media').value = d.media || '';
  $('sm-delay').value = String(d.delay); if (!$('sm-delay').value) $('sm-delay').value = '0';
  $('sm-arrival').checked = !!d.arrival;
  tplPicked($('sm-tpl'), $('sm-vars'), $('sm-media-wrap'), $('sm-tpl-prev'), d.vars, 'vars');
  $('sm-form').scrollIntoView({behavior: 'smooth'});
}
$('sm-tpl').onchange = e => tplPicked(e.target, $('sm-vars'), $('sm-media-wrap'), $('sm-tpl-prev'), [], 'vars');
/* Visit messages default to the visit's own details. */
const VISIT_DEFAULT = ['first_name', 'visit_date', 'visit_time', 'visit_place', 'owner_name', 'owner_phone'];
[['vc', 'cvars', <?= json_encode(json_decode((string) ($vs['visit_confirm_vars'] ?? ''), true) ?: []) ?>],
 ['vr', 'rvars', <?= json_encode(json_decode((string) ($vs['visit_remind_vars'] ?? ''), true) ?: []) ?>],
 ['ms', 'mvars', <?= json_encode(json_decode((string) ($vs['meet_soon_vars'] ?? ''), true) ?: []) ?>]].forEach(([k, prefix, saved]) => {
  const sel = $(k + '-tpl'); if (!sel) return;
  const draw = tok => tplPicked(sel, $(k + '-vars'), null, $(k + '-prev'), tok.length ? tok : VISIT_DEFAULT, prefix);
  sel.onchange = () => draw([]);
  if (sel.value !== '0') draw(saved);
});

/* Sequence steps. */
let stepN = 0;
function seqStep(s){
  s = s || {}; const i = stepN++;
  const hours = s.delay_hours ?? (i === 0 ? 0 : 72), days = hours && hours % 24 === 0;
  const div = document.createElement('div'); div.className = 'seq-step';
  div.innerHTML = `<div class="seq-step-head"><strong>Message</strong>
      <span>sent <input type="number" min="0" name="steps[${i}][delay]" value="${days ? hours / 24 : hours}" style="width:70px">
      <select name="steps[${i}][unit]"><option value="h" ${days ? '' : 'selected'}>hours</option><option value="d" ${days ? 'selected' : ''}>days</option></select>
      <span class="seq-after">after ${i === 0 ? 'joining' : 'the message before'}</span></span>
      <button type="button" class="btn-link" style="color:var(--danger)" onclick="this.closest('.seq-step').remove(); seqLabels()">Remove</button></div>
    <select name="steps[${i}][template]" class="seq-tpl"><option value="">Choose a template…</option>
      ${Object.values(TPLS).map(t => `<option value="${t.id}" ${s.template_id == t.id ? 'selected' : ''}>${esc(t.name + ' (' + t.lang + ')')}</option>`).join('')}</select>
    <div class="tpl-preview text-muted"></div>
    <div class="tpl-vars"></div>
    <div class="field" hidden><span class="lbl">Picture or file link</span><input type="url" name="steps[${i}][media]" value="${esc(s.header_media || '')}"></div>`;
  $('seq-steps').appendChild(div);
  const sel = div.querySelector('.seq-tpl'), pick = tok => tplPicked(sel, div.querySelector('.tpl-vars'), div.querySelector('.field'), div.querySelector('.tpl-preview'), tok, `steps[${i}][vars]`);
  sel.onchange = () => pick([]);
  if (s.template_id) pick(s.vars || []);
  seqLabels();
}
function seqLabels(){ document.querySelectorAll('#seq-steps .seq-after').forEach((el, k) => el.textContent = 'after ' + (k === 0 ? 'joining' : 'the message before')); }
function seqTrig(){
  const k = $('seq-trig').value;
  $('seq-n-wrap').hidden = !['no_answer', 'stale'].includes(k); $('seq-stage-wrap').hidden = k !== 'stage';
  $('seq-n-lbl').textContent = k === 'stale' ? 'Days with no activity' : 'Number of "No answer" calls';
}
if ($('seq-f')) {
  $('seq-trig').onchange = seqTrig; seqTrig();
  $('seq-f').addEventListener('submit', () => { if ($('seq-trig').value === 'stage') $('seq-n').value = $('seq-stage').value; if ($('seq-trig').value === 'new_lead') $('seq-n').value = 0; });
  const existing = <?= json_encode(array_map(fn($st) => ['template_id' => (int) $st['template_id'], 'delay_hours' => (int) $st['delay_hours'],
                     'vars' => json_decode((string) $st['vars'], true) ?: [], 'header_media' => $st['header_media']], $editSteps)) ?>;
  existing.length ? existing.forEach(seqStep) : seqStep();
}
</script>
<?php layout_footer();
