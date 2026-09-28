<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';
require_once __DIR__ . '/../includes/inbox.php';

/**
 * One lead: who they are, where they stand, and everything that has happened with them —
 * messages, notes, stage moves and reassignments in one timeline, so whoever picks the lead up
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
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');

    if ($a === 'save') {
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'That email address does not look right.';
        } else {
            $val = trim((string) ($_POST['value'] ?? ''));
            $fu  = trim((string) ($_POST['followup'] ?? ''));
            db_run("UPDATE contacts SET name=?, email=?, deal_value=?, next_followup_at=? WHERE id=? AND client_id=?",
                [trim((string) ($_POST['name'] ?? '')), $email !== '' ? $email : null,
                 $val !== '' ? (float) preg_replace('/[^\d.]/', '', $val) : null,
                 $fu !== '' ? date('Y-m-d H:i:s', strtotime($fu)) : null, $id, $cid]);
            if ((int) ($_POST['stage_id'] ?? 0)) crm_set_stage($CLIENT, $id, (int) $_POST['stage_id'], $me);
            if ($isAdmin && isset($_POST['owner'])) {
                $o = (string) $_POST['owner'];
                crm_assign($CLIENT, $id, $o === 'none' ? null : ($o === 'auto' ? crm_assign_next($CLIENT) : (int) $o), $me);
            }
            flash('Saved.');
            redirect('crm_lead.php?id=' . $id);
        }
    }
    if ($a === 'note') {
        crm_add_note($CLIENT, $id, (string) ($_POST['body'] ?? ''), $me);
        redirect('crm_lead.php?id=' . $id . '#activity');
    }
    if ($a === 'send') {
        // Out through whatever this person's admin chose for them — see sender_for().
        $r = inbox_send($CLIENT, $id, (string) ($_POST['message'] ?? ''));
        if ($r['ok']) { flash('Sent.'); redirect('crm_lead.php?id=' . $id . '#activity'); }
        $err = $r['error'] ?: 'The message could not be sent.';
    }
    if ($a === 'add_to_crm') {
        crm_add_lead($CLIENT, $id, '', is_sales() ? $me : 'auto', null, $me);
        flash('Added to the pipeline.');
        redirect('crm_lead.php?id=' . $id);
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

/* One timeline: messages, notes and history, newest first. */
$feed = [];
foreach (db_all("SELECT direction, body, status, created_at FROM messages WHERE contact_id=? ORDER BY id DESC LIMIT 60", [$id]) as $m) {
    $feed[] = ['t' => $m['created_at'], 'kind' => 'msg', 'dir' => $m['direction'], 'body' => $m['body'], 'status' => $m['status']];
}
foreach (db_all("SELECT * FROM crm_notes WHERE contact_id=? ORDER BY id DESC LIMIT 100", [$id]) as $n) {
    $feed[] = ['t' => $n['created_at'], 'kind' => 'note', 'body' => $n['body'], 'by' => $who($n['user_id'])];
}
foreach (db_all("SELECT * FROM crm_events WHERE contact_id=? ORDER BY id DESC LIMIT 100", [$id]) as $ev) {
    $sn = fn($v) => $v !== null ? (string) ($stageMap[(int) $v]['name'] ?? 'a removed stage') : '—';
    $un = fn($v) => $v !== null ? ($names[(int) $v] ?? 'someone') : 'nobody';
    $text = match ($ev['kind']) {
        'added'    => 'Added to the pipeline in ' . $sn($ev['to_val']),
        'stage'    => 'Moved from ' . $sn($ev['from_val']) . ' to ' . $sn($ev['to_val']),
        'assigned' => $ev['from_val'] === null ? 'Assigned to ' . $un($ev['to_val'])
                     : ($ev['to_val'] === null ? 'Unassigned from ' . $un($ev['from_val'])
                     : 'Reassigned from ' . $un($ev['from_val']) . ' to ' . $un($ev['to_val'])),
        'removed'  => 'Removed from the pipeline',
        default    => $ev['kind'],
    };
    $feed[] = ['t' => $ev['created_at'], 'kind' => 'event', 'body' => $text, 'by' => $who($ev['user_id'])];
}
usort($feed, fn($a, $b) => strcmp((string) $b['t'], (string) $a['t']));

/* What the send box can do for this person, worked out before drawing it. */
$snd = sender_for($CLIENT, sending_user());
$sendOpen = $snd['ok'] && inbox_window_open($lead, $snd['client']);
$viaText = ['api' => 'the WhatsApp Business API number', 'company_personal' => 'the company\'s phone',
            'own' => 'your own WhatsApp'][$snd['via']] ?? 'the account\'s number';

$title = (string) ($lead['name'] ?: '+' . $lead['phone_e164']);
client_header($title, 'crm', $CLIENT);
page_head($title, '<a class="btn btn-ghost btn-sm" href="crm.php">&larr; CRM</a>'
    . (can_use('inbox') ? ' <a class="btn btn-primary btn-sm" href="inbox.php?contact=' . $id . '">Open conversation</a>' : ''));
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<?php if ($lead['stage_id'] === null): ?>
  <div class="alert info">This contact is not in the pipeline.
    <?php if (can_write()): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="add_to_crm">
        <button class="btn btn-sm btn-primary" style="margin-left:8px">Add to the CRM</button></form>
    <?php endif; ?></div>
<?php endif; ?>

<div class="lead-grid">
  <div class="card">
    <h2>Details</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $id ?>">
      <fieldset <?= can_write() ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
      <div class="grid2">
        <div class="field"><span class="lbl">Name</span><input name="name" value="<?= e((string) $lead['name']) ?>"></div>
        <div class="field"><span class="lbl">Phone</span><input value="+<?= e((string) $lead['phone_e164']) ?>" disabled></div>
        <div class="field"><span class="lbl">Email</span><input name="email" type="email" value="<?= e((string) ($lead['email'] ?? '')) ?>"></div>
        <div class="field"><span class="lbl">Deal value</span><input name="value" inputmode="decimal"
             value="<?= $lead['deal_value'] !== null ? e(number_format((float) $lead['deal_value'], 0, '.', '')) : '' ?>"></div>
        <?php if ($lead['stage_id'] !== null): ?>
        <div class="field"><span class="lbl">Stage</span><select name="stage_id">
          <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === (int) $lead['stage_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select></div>
        <?php endif; ?>
        <div class="field"><span class="lbl">Owner</span>
          <?php if ($isAdmin): ?>
            <select name="owner"><option value="none" <?= $lead['owner_user_id'] === null ? 'selected' : '' ?>>Unassigned</option>
              <option value="auto">Next in rotation</option>
              <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) $lead['owner_user_id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
            </select>
          <?php else: ?>
            <input value="<?= e(crm_user_name($lead['owner_user_id'] !== null ? (int) $lead['owner_user_id'] : null)) ?>" disabled>
          <?php endif; ?></div>
        <div class="field"><span class="lbl">Next follow-up</span><input name="followup" type="datetime-local"
             value="<?= $lead['next_followup_at'] ? e(date('Y-m-d\TH:i', strtotime((string) $lead['next_followup_at']))) : '' ?>"></div>
        <div class="field"><span class="lbl">Came from</span><input value="<?= e(crm_source_label($lead['source'])) ?>" disabled></div>
      </div>
      <?php if (can_write()): ?><button class="btn btn-primary mt10">Save</button><?php endif; ?>
      </fieldset>
    </form>
    <?php $attrs = json_decode((string) ($lead['attributes'] ?? ''), true) ?: []; if ($attrs): ?>
      <h2 style="margin-top:18px">More</h2>
      <table class="data"><tbody>
        <?php foreach ($attrs as $k => $v): ?><tr><td class="text-muted" style="width:40%"><?= e((string) $k) ?></td><td><?= e(is_scalar($v) ? (string) $v : json_encode($v)) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
    <?php if ($isAdmin && $lead['stage_id'] !== null): ?>
      <form method="post" class="mt10" onsubmit="return confirm('Take this lead out of the pipeline? The contact and its messages are kept.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn-link" style="color:var(--danger)">Remove from pipeline</button></form>
    <?php endif; ?>
  </div>

  <div class="card" id="activity">
    <h2>Activity</h2>
    <?php if (can_write() && can_use('inbox')): ?>
      <div class="lead-send" style="margin-bottom:14px">
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
    <?php if (can_write()): ?>
      <form method="post" style="margin-bottom:12px">
        <?= csrf_field() ?><input type="hidden" name="action" value="note"><input type="hidden" name="id" value="<?= $id ?>">
        <textarea name="body" rows="2" placeholder="Add a note — what was said, what happens next…" required></textarea>
        <button class="btn btn-ghost btn-sm mt10">Add note</button>
      </form>
    <?php endif; ?>
    <div class="lead-feed">
      <?php if (!$feed): ?><p class="text-muted">Nothing yet.</p><?php endif; ?>
      <?php foreach ($feed as $it): ?>
        <?php if ($it['kind'] === 'msg'): ?>
          <div class="lead-item lead-msg <?= $it['dir'] === 'out' ? 'out' : 'in' ?>"><?= nl2br(e((string) $it['body'])) ?>
            <span class="when"><?= e(date('j M, H:i', strtotime((string) $it['t']))) ?><?= $it['status'] === 'failed' ? ' · not delivered' : '' ?></span></div>
        <?php elseif ($it['kind'] === 'note'): ?>
          <div class="lead-item note"><?= nl2br(e((string) $it['body'])) ?>
            <span class="when"><?= e($it['by']) ?> · <?= e(date('j M, H:i', strtotime((string) $it['t']))) ?></span></div>
        <?php else: ?>
          <div class="lead-item event"><?= e($it['body']) ?> · <?= e($it['by']) ?> · <?= e(date('j M, H:i', strtotime((string) $it['t']))) ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php layout_footer();
