<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';
require_once __DIR__ . '/../includes/inbox.php';

/**
 * One lead: who they are, where they stand, what happens next, and everything that has happened —
 * calls, meetings, comments, messages, stage moves — in one timeline, so whoever picks the lead up
 * next does not have to piece the story together from three screens.
 */
$cid  = (int) $CLIENT['id'];
$me   = (int) ($PERM_USER['id'] ?? 0);
$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$lead = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$id, $cid]);

// Not found and not theirs look the same, so guessing ids reveals nothing.
if (!$lead || !crm_can_see($lead)) {
    http_response_code(404);
    client_header('Lead not found', 'crm', $CLIENT);
    echo '<div class="card" style="max-width:560px"><h2 style="margin-top:0">Lead not found</h2>'
       . '<p class="text-muted">It may have been removed, or it is assigned to someone else.</p>'
       . '<a class="btn btn-ghost" href="crm.php">&larr; Back to the CRM</a></div>';
    layout_footer(); exit;
}

$isAdmin = is_client_admin();
$stages  = crm_stages($cid);
$people  = crm_assignable_users($cid);
$kinds   = crm_activity_kinds();
$outcomes = crm_activity_outcomes();
$err = '';
$back = fn(string $anchor = '') => redirect('crm_lead.php?id=' . $id . $anchor);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');

    if ($a === 'save') {
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'That email address does not look right.';
        } else {
            $val = trim((string) ($_POST['value'] ?? ''));
            db_run("UPDATE contacts SET name=?, email=?, deal_value=? WHERE id=? AND client_id=?",
                [trim((string) ($_POST['name'] ?? '')), $email !== '' ? $email : null,
                 $val !== '' ? (float) preg_replace('/[^\d.]/', '', $val) : null, $id, $cid]);
            if ($isAdmin && isset($_POST['owner'])) {
                $o = (string) $_POST['owner'];
                crm_assign($CLIENT, $id, $o === 'none' ? null : ($o === 'auto' ? crm_assign_next($CLIENT) : (int) $o), $me);
            }
            flash('Saved.');
            $back();
        }
    }

    // Log what happened, and in the same breath say what happens next.
    if ($a === 'activity') {
        $kind    = (string) ($_POST['kind'] ?? 'note');
        $outcome = (string) ($_POST['outcome'] ?? '') ?: null;
        $body    = (string) ($_POST['body'] ?? '');
        $next    = trim((string) ($_POST['next_at'] ?? ''));
        if ($kind === 'note' && trim($body) === '') {
            $err = 'Write the comment first.';
        } elseif ($next !== '' && !strtotime($next)) {
            $err = 'That follow-up date does not look right.';
        } else {
            crm_log_activity($CLIENT, $id, $kind, $kind === 'note' ? null : $outcome, $body, $me);
            if ($next !== '') crm_set_followup($CLIENT, $id, $next, (string) ($_POST['next_note'] ?? ''), $me);
            elseif (!empty($_POST['clear_followup'])) crm_set_followup($CLIENT, $id, null, '', $me);
            $st = (int) ($_POST['stage_id'] ?? 0);
            if ($st && $st !== (int) $lead['stage_id']) crm_set_stage($CLIENT, $id, $st, $me);
            flash(($kinds[$kind] ?? 'Activity') . ' logged' . ($next !== '' ? ', next follow-up ' . date('D j M, H:i', strtotime($next)) : '') . '.');
            $back('#timeline');
        }
    }
    if ($a === 'followup') {
        $when = trim((string) ($_POST['next_at'] ?? ''));
        if ($when === '' || !strtotime($when)) { $err = 'Choose a date and time for the follow-up.'; }
        else { crm_set_followup($CLIENT, $id, $when, (string) ($_POST['next_note'] ?? ''), $me); flash('Follow-up set for ' . date('D j M, H:i', strtotime($when)) . '.'); $back(); }
    }
    if ($a === 'followup_done') {
        crm_log_activity($CLIENT, $id, 'note', null, 'Follow-up done' . (!empty($lead['followup_note']) ? ': ' . $lead['followup_note'] : '') . '.', $me);
        crm_set_followup($CLIENT, $id, null, '', $me);
        flash('Follow-up marked done. Set the next one when you know it.');
        $back();
    }
    if ($a === 'stage') {
        if ((int) ($_POST['stage_id'] ?? 0)) crm_set_stage($CLIENT, $id, (int) $_POST['stage_id'], $me);
        $back();
    }
    if ($a === 'send') {
        // Out through whatever this person's admin chose for them — see sender_for().
        $r = inbox_send($CLIENT, $id, (string) ($_POST['message'] ?? ''));
        if ($r['ok']) { flash('Sent.'); $back('#timeline'); }
        $err = $r['error'] ?: 'The message could not be sent.';
    }
    if ($a === 'add_to_crm') {
        crm_add_lead($CLIENT, $id, '', is_sales() ? $me : 'auto', null, $me);
        flash('Added to the pipeline.');
        $back();
    }
    if ($a === 'remove' && $isAdmin) {
        db_run("UPDATE contacts SET stage_id=NULL WHERE id=? AND client_id=?", [$id, $cid]);
        crm_log($cid, $id, 'removed', (string) $lead['stage_id'], null, $me);
        flash('Removed from the pipeline. The contact and its conversation are kept.');
        redirect('crm.php');
    }
}

$lead = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$id, $cid]);
$stageMap = crm_stage_map($cid);
$names = [];
foreach (db_all("SELECT id, COALESCE(NULLIF(name,''), email) n FROM users WHERE client_id=?", [$cid]) as $u) $names[(int) $u['id']] = $u['n'];
$who = fn($uid) => $uid ? ($names[(int) $uid] ?? 'Someone') : 'Automatically';
$hasKinds = db_has_column('crm_notes', 'kind');

/* One timeline, newest first. Each item carries its group, so the filter chips can narrow it. */
$feed = [];
foreach (db_all("SELECT direction, body, status, created_at FROM messages WHERE contact_id=? ORDER BY id DESC LIMIT 80", [$id]) as $m) {
    $feed[] = ['t' => $m['created_at'], 'group' => 'messages', 'kind' => 'msg', 'dir' => $m['direction'], 'body' => $m['body'], 'status' => $m['status']];
}
foreach (db_all("SELECT * FROM crm_notes WHERE contact_id=? ORDER BY id DESC LIMIT 150", [$id]) as $n) {
    $k = $hasKinds ? (string) $n['kind'] : 'note';
    $feed[] = ['t' => $n['created_at'], 'group' => 'activity', 'kind' => 'act', 'act' => $k, 'outcome' => $n['outcome'] ?? null,
               'body' => $n['body'], 'by' => $who($n['user_id'])];
}
foreach (db_all("SELECT * FROM crm_events WHERE contact_id=? ORDER BY id DESC LIMIT 150", [$id]) as $ev) {
    $sn = fn($v) => $v !== null ? (string) ($stageMap[(int) $v]['name'] ?? 'a removed stage') : '—';
    $un = fn($v) => $v !== null ? ($names[(int) $v] ?? 'someone') : 'nobody';
    $text = match ($ev['kind']) {
        'added'    => 'Added to the pipeline in ' . $sn($ev['to_val']),
        'stage'    => 'Moved from ' . $sn($ev['from_val']) . ' to ' . $sn($ev['to_val']),
        'assigned' => $ev['from_val'] === null ? 'Assigned to ' . $un($ev['to_val'])
                     : ($ev['to_val'] === null ? 'Unassigned from ' . $un($ev['from_val'])
                     : 'Reassigned from ' . $un($ev['from_val']) . ' to ' . $un($ev['to_val'])),
        'followup' => $ev['to_val'] ? 'Follow-up set for ' . date('D j M, H:i', strtotime((string) $ev['to_val'])) : 'Follow-up cleared',
        'removed'  => 'Removed from the pipeline',
        default    => $ev['kind'],
    };
    $feed[] = ['t' => $ev['created_at'], 'group' => 'history', 'kind' => 'event', 'body' => $text, 'by' => $who($ev['user_id'])];
}
usort($feed, fn($a, $b) => strcmp((string) $b['t'], (string) $a['t']));
$counts = array_count_values(array_column($feed, 'group'));

/* What the send box can do for this person, worked out before drawing it. */
$snd = sender_for($CLIENT, sending_user());
$sendOpen = $snd['ok'] && inbox_window_open($lead, $snd['client']);
$viaText = ['api' => 'the WhatsApp Business API number', 'company_personal' => 'the company\'s phone',
            'own' => 'your own WhatsApp'][$snd['via']] ?? 'the account\'s number';

$due  = $lead['next_followup_at'] ? strtotime((string) $lead['next_followup_at']) : 0;
$dueState = !$due ? '' : ($due < time() ? 'late' : (date('Y-m-d', $due) === date('Y-m-d') ? 'today' : 'later'));
$curStage = $lead['stage_id'] !== null ? ($stageMap[(int) $lead['stage_id']] ?? null) : null;
$phone = (string) $lead['phone_e164'];
$canW  = can_write();

$title = (string) ($lead['name'] ?: '+' . $phone);
client_header($title, 'crm', $CLIENT);
page_head($title, '<a class="btn btn-ghost btn-sm" href="crm.php">&larr; CRM</a>');
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<?php if ($lead['stage_id'] === null): ?>
  <div class="alert info">This contact is not in the pipeline.
    <?php if ($canW): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="add_to_crm">
        <button class="btn btn-sm btn-primary" style="margin-left:8px">Add to the CRM</button></form>
    <?php endif; ?></div>
<?php endif; ?>

<!-- Who, how to reach them, where they stand -->
<div class="card lead-hero">
  <div class="lead-hero-top">
    <div>
      <div class="lead-contact">
        <a href="tel:+<?= e($phone) ?>" class="lead-phone">+<?= e($phone) ?></a>
        <?php if (!empty($lead['email'])): ?><a href="mailto:<?= e((string) $lead['email']) ?>" class="text-muted"><?= e((string) $lead['email']) ?></a><?php endif; ?>
      </div>
      <div class="lead-meta">
        <span><span class="text-muted">Owner</span> <?= e(crm_user_name($lead['owner_user_id'] !== null ? (int) $lead['owner_user_id'] : null)) ?></span>
        <span><span class="text-muted">Came from</span> <?= e(crm_source_label($lead['source'])) ?></span>
        <?php if ($lead['deal_value'] !== null): ?><span><span class="text-muted">Value</span> <?= e(number_format((float) $lead['deal_value'])) ?></span><?php endif; ?>
        <span><span class="text-muted">Added</span> <?= e(date('j M Y', strtotime((string) $lead['created_at']))) ?></span>
      </div>
    </div>
    <div class="lead-actions">
      <a class="btn btn-ghost btn-sm" href="tel:+<?= e($phone) ?>">Call</a>
      <?php if (can_use('inbox')): ?><a class="btn btn-ghost btn-sm" href="inbox.php?contact=<?= $id ?>">Conversation</a><?php endif; ?>
      <a class="btn btn-ghost btn-sm" href="https://wa.me/<?= e($phone) ?>" target="_blank" rel="noopener">WhatsApp app</a>
    </div>
  </div>

  <?php if ($lead['stage_id'] !== null): ?>
  <form method="post" class="lead-stages" aria-label="Stage">
    <?= csrf_field() ?><input type="hidden" name="action" value="stage"><input type="hidden" name="id" value="<?= $id ?>">
    <?php $passed = true; foreach ($stages as $s):
      $isCur = (int) $s['id'] === (int) $lead['stage_id'];
      $cls = $isCur ? 'cur ' . $s['kind'] : ($passed && $s['kind'] === 'open' ? 'done' : '');
      if ($isCur) $passed = false; ?>
      <button name="stage_id" value="<?= (int) $s['id'] ?>" class="lead-stage <?= $cls ?>" <?= $canW ? '' : 'disabled' ?>
              title="<?= $isCur ? 'Current stage' : 'Move to ' . e($s['name']) ?>"><?= e($s['name']) ?></button>
    <?php endforeach; ?>
  </form>
  <?php endif; ?>
</div>

<div class="lead-grid">
  <div class="lead-col">

    <!-- What happens next -->
    <div class="card lead-next <?= $dueState ?>" id="next">
      <h2>Next follow-up</h2>
      <?php if ($due): ?>
        <div class="lead-next-when">
          <strong><?= e(date('D j M, H:i', $due)) ?></strong>
          <span class="pill <?= $dueState === 'late' ? 'red' : ($dueState === 'today' ? 'gold' : 'gray') ?>">
            <?= $dueState === 'late' ? 'Overdue' : ($dueState === 'today' ? 'Today' : 'Upcoming') ?></span>
        </div>
        <?php if (!empty($lead['followup_note'])): ?><p class="lead-next-note"><?= e((string) $lead['followup_note']) ?></p><?php endif; ?>
        <?php if ($canW): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap" class="mt10">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="followup_done"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-primary btn-sm">Mark done</button></form>
          <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('fu-edit').hidden=false;this.hidden=true">Change</button>
        </div>
        <?php endif; ?>
      <?php else: ?>
        <p class="text-muted" style="margin:0 0 8px">Nothing planned. Leads with a next step get followed up; leads without one get forgotten.</p>
      <?php endif; ?>
      <?php if ($canW): ?>
      <form method="post" id="fu-edit" class="mt10" <?= $due ? 'hidden' : '' ?>>
        <?= csrf_field() ?><input type="hidden" name="action" value="followup"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="lead-quick" data-for="fu-at"></div>
        <div class="grid2">
          <div class="field"><span class="lbl">Date and time</span><input type="datetime-local" name="next_at" id="fu-at" required></div>
          <div class="field"><span class="lbl">What for</span><input type="text" name="next_note" maxlength="255" placeholder="Send the payment plan"></div>
        </div>
        <button class="btn btn-primary btn-sm">Set follow-up</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Log what happened -->
    <?php if ($canW): ?>
    <div class="card" id="log">
      <h2>Log activity</h2>
      <form method="post" id="act-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="activity"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="act-kinds" role="radiogroup" aria-label="What did you do">
          <?php foreach ($kinds as $k => $label): ?>
            <label class="act-kind"><input type="radio" name="kind" value="<?= $k ?>" <?= $k === 'call' ? 'checked' : '' ?>>
              <span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="field" id="act-outcome-wrap"><span class="lbl">How did it go</span>
          <select name="outcome"><option value="">—</option>
            <?php foreach ($outcomes as $k => $label): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><span class="lbl" id="act-body-lbl">Comment</span>
          <textarea name="body" rows="3" placeholder="What was said, what they want, objections…"></textarea></div>
        <details class="act-more" <?= $due ? '' : 'open' ?>>
          <summary>Next follow-up and stage</summary>
          <div class="lead-quick" data-for="act-next"></div>
          <div class="grid2">
            <div class="field"><span class="lbl">Next follow-up</span><input type="datetime-local" name="next_at" id="act-next"></div>
            <div class="field"><span class="lbl">What for</span><input type="text" name="next_note" maxlength="255" placeholder="Call back with prices"></div>
          </div>
          <?php if ($due): ?><label class="mod-all" style="font-size:13px"><input type="checkbox" name="clear_followup" value="1"> The current follow-up is done — clear it</label><?php endif; ?>
          <?php if ($lead['stage_id'] !== null): ?>
          <div class="field"><span class="lbl">Move to stage</span><select name="stage_id">
            <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === (int) $lead['stage_id'] ? 'selected' : '' ?>><?= e($s['name']) ?><?= (int) $s['id'] === (int) $lead['stage_id'] ? ' (current)' : '' ?></option><?php endforeach; ?>
          </select></div>
          <?php endif; ?>
        </details>
        <button class="btn btn-primary mt10">Save activity</button>
      </form>
    </div>
    <?php endif; ?>

    <!-- Send on WhatsApp -->
    <?php if ($canW && can_use('inbox')): ?>
    <div class="card lead-send">
      <h2>Send on WhatsApp</h2>
      <?php if (!$snd['ok']): ?>
        <div class="note warn" id="lead-nosend"><?= e($snd['error']) ?>
          <?php if ($snd['via'] === 'own'): ?> <a href="my_whatsapp.php">Open My WhatsApp</a><?php endif; ?></div>
      <?php elseif (!$sendOpen): ?>
        <div class="note warn" id="lead-nosend">It has been more than 24 hours since this lead last wrote, so WhatsApp only
          allows an approved template from <?= e($viaText) ?>.
          <a href="inbox.php?contact=<?= $id ?>">Send a template from the conversation</a></div>
      <?php else: ?>
        <form method="post" id="lead-send">
          <?= csrf_field() ?><input type="hidden" name="action" value="send"><input type="hidden" name="id" value="<?= $id ?>">
          <textarea name="message" rows="2" placeholder="Message on WhatsApp…" required></textarea>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap" class="mt10">
            <button class="btn btn-primary btn-sm">Send on WhatsApp</button>
            <span class="text-muted" style="font-size:12px">Goes out from <?= e($viaText) ?></span>
          </div>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- The lead's details -->
    <details class="card lead-details" <?= $err && ($_POST['action'] ?? '') === 'save' ? 'open' : '' ?>>
      <summary><h2 style="display:inline;border:0;margin:0;padding:0">Details</h2></summary>
      <form method="post" class="mt10">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $id ?>">
        <fieldset <?= $canW ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
        <div class="grid2">
          <div class="field"><span class="lbl">Name</span><input name="name" value="<?= e((string) $lead['name']) ?>"></div>
          <div class="field"><span class="lbl">Email</span><input name="email" type="email" value="<?= e((string) ($lead['email'] ?? '')) ?>"></div>
          <div class="field"><span class="lbl">Deal value</span><input name="value" inputmode="decimal"
               value="<?= $lead['deal_value'] !== null ? e(number_format((float) $lead['deal_value'], 0, '.', '')) : '' ?>"></div>
          <div class="field"><span class="lbl">Owner</span>
            <?php if ($isAdmin): ?>
              <select name="owner"><option value="none" <?= $lead['owner_user_id'] === null ? 'selected' : '' ?>>Unassigned</option>
                <option value="auto">Next in rotation</option>
                <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) $lead['owner_user_id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
              </select>
            <?php else: ?>
              <input value="<?= e(crm_user_name($lead['owner_user_id'] !== null ? (int) $lead['owner_user_id'] : null)) ?>" disabled>
            <?php endif; ?></div>
        </div>
        <?php if ($canW): ?><button class="btn btn-primary btn-sm">Save details</button><?php endif; ?>
        </fieldset>
      </form>
      <?php $attrs = json_decode((string) ($lead['attributes'] ?? ''), true) ?: []; if ($attrs): ?>
        <h3 style="margin:16px 0 6px;font-size:13px">Answers and extra details</h3>
        <table class="data"><tbody>
          <?php foreach ($attrs as $k => $v): ?><tr><td class="text-muted" style="width:40%"><?= e((string) $k) ?></td><td><?= e(is_scalar($v) ? (string) $v : json_encode($v)) ?></td></tr><?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
      <?php if ($isAdmin && $lead['stage_id'] !== null): ?>
        <form method="post" class="mt10" onsubmit="return confirm('Take this lead out of the pipeline? The contact and its messages are kept.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn-link" style="color:var(--danger)">Remove from pipeline</button></form>
      <?php endif; ?>
    </details>
  </div>

  <!-- Everything that happened -->
  <div class="card" id="timeline">
    <div class="row-between" style="flex-wrap:wrap;gap:8px">
      <h2 style="margin:0;border:0;padding:0">Timeline</h2>
      <div class="lead-filter" role="tablist">
        <button type="button" class="on" data-f="all">All</button>
        <button type="button" data-f="activity">Activities <?= !empty($counts['activity']) ? '(' . $counts['activity'] . ')' : '' ?></button>
        <button type="button" data-f="messages">Messages <?= !empty($counts['messages']) ? '(' . $counts['messages'] . ')' : '' ?></button>
        <button type="button" data-f="history">History</button>
      </div>
    </div>
    <div class="lead-feed mt10">
      <?php if (!$feed): ?><p class="text-muted">Nothing yet.</p><?php endif; ?>
      <?php foreach ($feed as $it): $when = date('j M, H:i', strtotime((string) $it['t'])); ?>
        <?php if ($it['kind'] === 'msg'): ?>
          <div class="lead-item lead-msg <?= $it['dir'] === 'out' ? 'out' : 'in' ?>" data-g="messages"><?= nl2br(e((string) $it['body'])) ?>
            <span class="when"><?= e($when) ?><?= $it['status'] === 'failed' ? ' · not delivered' : '' ?></span></div>
        <?php elseif ($it['kind'] === 'act'): $k = $it['act']; ?>
          <div class="lead-item act act-<?= e($k) ?>" data-g="activity">
            <div class="act-head"><strong><?= e($kinds[$k] ?? 'Comment') ?></strong>
              <?php if ($it['outcome']): ?><span class="pill gray"><?= e($outcomes[$it['outcome']] ?? $it['outcome']) ?></span><?php endif; ?></div>
            <?php if (trim((string) $it['body']) !== ''): ?><div class="act-body"><?= nl2br(e((string) $it['body'])) ?></div><?php endif; ?>
            <span class="when"><?= e($it['by']) ?> · <?= e($when) ?></span></div>
        <?php else: ?>
          <div class="lead-item event" data-g="history"><?= e($it['body']) ?> · <?= e($it['by']) ?> · <?= e($when) ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
(function(){
  /* Quick picks for a follow-up: the times salespeople actually choose. */
  const pad = n => String(n).padStart(2, '0');
  const local = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  const at = (days, h, m) => { const d = new Date(); d.setDate(d.getDate() + days); d.setHours(h, m || 0, 0, 0); return d; };
  const picks = [['In 1 hour', () => { const d = new Date(Date.now() + 3600e3); d.setMinutes(Math.ceil(d.getMinutes() / 15) * 15, 0, 0); return d; }],
                 ['Tomorrow 10:00', () => at(1, 10)], ['Tomorrow 17:00', () => at(1, 17)],
                 ['In 3 days', () => at(3, 11)], ['Next week', () => at(7, 11)]];
  document.querySelectorAll('.lead-quick').forEach(box => {
    const input = document.getElementById(box.dataset.for);
    picks.forEach(([label, fn]) => {
      const b = document.createElement('button'); b.type = 'button'; b.textContent = label;
      b.onclick = () => { input.value = local(fn()); box.querySelectorAll('button').forEach(x => x.classList.remove('on')); b.classList.add('on'); };
      box.appendChild(b);
    });
  });

  /* A comment has no "how did it go"; everything else does. */
  const form = document.getElementById('act-form');
  if (form) {
    const sync = () => {
      const k = form.querySelector('input[name=kind]:checked').value;
      document.getElementById('act-outcome-wrap').hidden = k === 'note';
      form.querySelector('textarea[name=body]').required = k === 'note';
    };
    form.querySelectorAll('input[name=kind]').forEach(r => r.addEventListener('change', sync)); sync();
  }

  /* Timeline filter. */
  document.querySelectorAll('.lead-filter button').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.lead-filter button').forEach(x => x.classList.toggle('on', x === b));
    document.querySelectorAll('.lead-feed [data-g]').forEach(it => it.hidden = b.dataset.f !== 'all' && it.dataset.g !== b.dataset.f);
  }));
})();
</script>
<?php layout_footer();
