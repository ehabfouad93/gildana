<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';
require_once __DIR__ . '/../includes/inbox.php';
require_once __DIR__ . '/../includes/crm_ai.php';

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
$projects = crm_projects($cid);
$pnames   = array_column($projects, 'name', 'id');
$units    = crm_options($cid, 'unit_type');
$reasons  = crm_options($cid, 'lost_reason');
$err = '';
/** A move to a lost stage carries its reason, or is refused when the account asks for one. */
$stageMove = function (int $stageId) use ($CLIENT, $cid, $id, $me, &$err): bool {
    $reason = trim((string) ($_POST['lost_reason'] ?? ''));
    if ($reason === '__other') $reason = trim((string) ($_POST['lost_other'] ?? ''));
    if (crm_needs_lost_reason($cid, $stageId) && $reason === '') { $err = 'Say why this lead was lost — choose a reason.'; return false; }
    return crm_set_stage($CLIENT, $id, $stageId, $me, $reason !== '' ? $reason : null, (string) ($_POST['lost_note'] ?? ''));
};
$back = fn(string $anchor = '') => redirect('crm_lead.php?id=' . $id . $anchor);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');

    // AI, asked for by the page itself: JSON back, no reload.
    if ($a === 'ai_summary') {
        $r = crm_ai_summarize($CLIENT, $lead, $me);
        json_out($r + ['filled_words' => !empty($r['filled']) ? 'Filled in from the chat: ' . implode(', ', $r['filled']) . '.' : '']);
    }
    if ($a === 'ai_reply') {
        $fresh = db_row("SELECT * FROM contacts WHERE id=?", [$id]) ?: $lead;
        json_out(crm_ai_reply($CLIENT, $fresh, (string) (($PERM_USER['name'] ?? '') ?: 'the salesperson'), (string) ($_POST['hint'] ?? '')));
    }

    if ($a === 'save') {
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'That email address does not look right.';
        } else {
            $val = (string) ($_POST['deal_value'] ?? $_POST['value'] ?? '');
            db_run("UPDATE contacts SET name=?, email=?, deal_value=? WHERE id=? AND client_id=?",
                [trim((string) ($_POST['name'] ?? '')), $email !== '' ? $email : null,
                 crm_deal_value($val, (string) $lead['phone_e164']), $id, $cid]);
            if (db_has_column('contacts', 'project_id')) {
                $pj = (int) ($_POST['project_id'] ?? 0);
                $pay = (string) ($_POST['payment_pref'] ?? '');
                db_run("UPDATE contacts SET project_id=?, unit_type=?, budget=?, payment_pref=? WHERE id=? AND client_id=?",
                       [isset($pnames[$pj]) ? $pj : null, mb_substr(trim((string) ($_POST['unit_type'] ?? '')), 0, 80) ?: null,
                        mb_substr(trim((string) ($_POST['budget'] ?? '')), 0, 80) ?: null,
                        in_array($pay, ['cash', 'installments'], true) ? $pay : null, $id, $cid]);
                crm_rescore($id);
            }
            if (db_has_column('contacts', 'qualification')) {
                $ql = (string) ($_POST['qualification'] ?? '');
                $ql = isset(crm_qualifications()[$ql]) ? $ql : null;
                if ($ql !== ($lead['qualification'] ?? null)) {
                    db_run("UPDATE contacts SET qualification=? WHERE id=? AND client_id=?", [$ql, $id, $cid]);
                    crm_log($cid, $id, 'qualified', $lead['qualification'] ?? null, $ql, $me);
                    crm_rescore($id);
                }
                if ($isAdmin && isset($_POST['data_type']) && isset(crm_data_types()[$_POST['data_type']])) {
                    db_run("UPDATE contacts SET data_type=? WHERE id=? AND client_id=?", [(string) $_POST['data_type'], $id, $cid]);
                }
            }
            if (($ce = crm_custom_save($cid, $id, (array) ($_POST['cf'] ?? []))) !== '') { $err = $ce; }
            if ($err === '' && $isAdmin && isset($_POST['owner'])) {
                $o = (string) $_POST['owner'];
                crm_assign($CLIENT, $id, $o === 'none' ? null : ($o === 'auto' ? crm_assign_next($CLIENT) : (int) $o), $me);
            }
            if ($err === '') { flash('Saved.'); $back(); }
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
            $st = (int) ($_POST['stage_id'] ?? 0);
            $moving = $st && $st !== (int) $lead['stage_id'];
            if ($moving && crm_needs_lost_reason($cid, $st) && trim((string) ($_POST['lost_reason'] ?? '')) === '') {
                $err = 'Say why this lead was lost — choose a reason.';
            } else {
            crm_log_activity($CLIENT, $id, $kind, $kind === 'note' ? null : $outcome, $body, $me);
            if (!$moving && isset($_POST['substatus'])) crm_set_substatus($CLIENT, $id, (string) $_POST['substatus'], $me);
            if ($next !== '') crm_set_followup($CLIENT, $id, $next, (string) ($_POST['next_note'] ?? ''), $me);
            elseif (!empty($_POST['clear_followup'])) crm_set_followup($CLIENT, $id, null, '', $me);
            if ($moving && $stageMove($st) && !empty($_POST['substatus_new'])) crm_set_substatus($CLIENT, $id, (string) $_POST['substatus_new'], $me);
            flash(($kinds[$kind] ?? 'Activity') . ' logged' . ($next !== '' ? ', next follow-up ' . date('D j M, H:i', strtotime($next)) : '') . '.');
            $back('#timeline');
            }
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
        if ((int) ($_POST['stage_id'] ?? 0) && $stageMove((int) $_POST['stage_id'])) $back();
    }
    if ($a === 'substatus' && can_write()) {
        crm_set_substatus($CLIENT, $id, (string) ($_POST['substatus'] ?? ''), $me);
        $back();
    }
    if ($a === 'visit_book' && can_write() && can_crm('visits')) {
        $d = trim((string) ($_POST['visit_date'] ?? '')); $t = trim((string) ($_POST['visit_time'] ?? ''));
        $r = crm_visit_book($CLIENT, $id, ['starts_at' => $d . ' ' . $t, 'place' => (string) ($_POST['place'] ?? ''),
                 'project_id' => (int) ($_POST['project_id'] ?? 0) ?: null, 'notes' => (string) ($_POST['notes'] ?? ''),
                 'user_id' => $isAdmin ? ((int) ($_POST['host'] ?? 0) ?: null) : null, 'confirm' => !empty($_POST['confirm'])], $me);
        if ($r['ok']) { flash('Visit booked for ' . date('D j M, H:i', strtotime($d . ' ' . $t)) . '.'); $back('#visits'); }
        $err = $r['error'];
    }
    if (in_array($a, ['visit_done', 'visit_no_show', 'visit_cancel'], true) && can_write()) {
        $vid = (int) ($_POST['visit'] ?? 0);
        if (db_val("SELECT COUNT(*) FROM crm_visits WHERE id=? AND contact_id=?", [$vid, $id])) {
            crm_visit_outcome($CLIENT, $vid, ['visit_done' => 'done', 'visit_no_show' => 'no_show', 'visit_cancel' => 'cancelled'][$a], $me);
            flash(['visit_done' => 'Marked as came.', 'visit_no_show' => 'Marked as didn\'t come.', 'visit_cancel' => 'Visit cancelled. Its reminder will not go out.'][$a]);
        }
        $back('#visits');
    }
    if ($a === 'visit_move' && can_write()) {
        $vid = (int) ($_POST['visit'] ?? 0);
        if (db_val("SELECT COUNT(*) FROM crm_visits WHERE id=? AND contact_id=?", [$vid, $id])) {
            $r = crm_visit_reschedule($CLIENT, $vid, trim((string) ($_POST['visit_date'] ?? '')) . ' ' . trim((string) ($_POST['visit_time'] ?? '')), $me);
            if ($r['ok']) { flash('Visit moved.'); $back('#visits'); }
            $err = $r['error'];
        }
    }
    if ($a === 'seq_stop' && $isAdmin) {
        crm_seq_stop((int) ($_POST['seq'] ?? 0), $id, 'Stopped by ' . crm_user_name($me) . '.');
        flash('Stopped. No more messages from that sequence.');
        $back();
    }
    if ($a === 'merge' && $isAdmin) {
        $other = (int) ($_POST['other'] ?? 0);
        if (crm_merge($CLIENT, $id, $other, $me)) { flash('Merged. Everything from the other contact is now on this lead.'); $back(); }
        $err = 'Those two could not be merged.';
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

$visits = [];
try { $visits = db_all("SELECT v.*, COALESCE(NULLIF(u.name,''), u.email) host FROM crm_visits v LEFT JOIN users u ON u.id=v.user_id
                         WHERE v.contact_id=? ORDER BY v.starts_at DESC LIMIT 10", [$id]); } catch (Throwable $e) {}
$vs = crm_settings($cid);
$projAddr = [];
foreach ($projects as $p_) $projAddr[(int) $p_['id']] = (string) ($p_['address'] ?? '');
$seqNames = [];
try { $seqNames = array_column(db_all("SELECT id, name FROM crm_sequences WHERE client_id=?", [$cid]), 'name', 'id'); } catch (Throwable $e) {}
$runs = [];
try {
    $runs = db_all("SELECT r.*, q.name, (SELECT COUNT(*) FROM crm_seq_steps st WHERE st.sequence_id=r.sequence_id) AS steps
                      FROM crm_seq_runs r JOIN crm_sequences q ON q.id=r.sequence_id WHERE r.contact_id=? ORDER BY r.id DESC LIMIT 5", [$id]);
} catch (Throwable $e) {}

/* One timeline, newest first. Each item carries its group, so the filter chips can narrow it. */
$feed = [];
$hasPhone = !empty($CLIENT['personal_instance']);
foreach (db_all("SELECT * FROM messages WHERE contact_id=? ORDER BY id DESC LIMIT 80", [$id]) as $m) {
    $mk = inbox_media_kind($m);
    $feed[] = ['t' => $m['created_at'], 'group' => 'messages', 'kind' => 'msg', 'dir' => $m['direction'],
               'body' => $mk && preg_match('/^\[\w+\]$/', (string) $m['body']) ? '' : $m['body'], 'status' => $m['status'],
               'media' => $mk, 'mid' => (int) $m['id'], 'gone' => $mk && !inbox_media_fetchable($m, $hasPhone)];
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
        'lost'     => 'Lost — ' . $ev['to_val'],
        'reclaimed'=> 'Not contacted in time — passed from ' . $un($ev['from_val']) . ' to ' . $un($ev['to_val']),
        'sla'      => 'Not contacted within ' . (int) $ev['to_val'] . ' minutes — owner alerted',
        'resubmitted' => 'Came in again (' . crm_source_label($ev['to_val']) . ')',
        'merged'   => 'Merged with the duplicate contact ' . $ev['to_val'],
        'ai_fill'  => 'AI filled in from the chat: ' . $ev['to_val'],
        'substatus'=> $ev['to_val'] !== null ? 'Sub-status: ' . $ev['to_val'] . ($ev['from_val'] !== null ? ' (was ' . $ev['from_val'] . ')' : '') : 'Sub-status cleared',
        'qualified'=> 'Marked ' . (crm_qualifications()[(string) $ev['to_val']] ?? 'not set') ,
        'visit'    => 'Site visit booked for ' . date('D j M, H:i', strtotime((string) $ev['to_val'])),
        'visit_moved' => 'Site visit moved from ' . date('D j M, H:i', strtotime((string) $ev['from_val'])) . ' to ' . date('D j M, H:i', strtotime((string) $ev['to_val'])),
        'seq_start'=> 'Joined the follow-up sequence "' . ($seqNames[(int) $ev['to_val']] ?? 'a removed sequence') . '"',
        'seq_stop' => 'Left the sequence "' . ($seqNames[(int) $ev['from_val']] ?? 'a removed sequence') . '" — ' . $ev['to_val'],
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

// Heat is about open leads; a won or lost one has its answer already.
$isOpen = $lead['stage_id'] !== null && crm_stage_kind($cid, (int) $lead['stage_id']) === 'open';
[$score, $why] = $isOpen ? crm_score_compute($lead) : [null, []];
[$heatCls, $heatWord] = crm_heat($score);
$dups = crm_duplicates($lead);
$lostIds = array_map('intval', array_keys(array_filter(crm_stage_map($cid), fn($st) => $st['kind'] === 'lost')));
$askLost = (int) crm_settings($cid)['require_lost_reason'] === 1;
$subs = (int) ($lead['submissions'] ?? 1);
$subList = $lead['stage_id'] !== null ? crm_substatus_list($cid, (int) $lead['stage_id']) : [];
$subsByStage = [];
foreach ($stages as $s_) $subsByStage[(int) $s_['id']] = $s_['kind'] === 'lost' ? [] : crm_substatus_list($cid, (int) $s_['id']);
$cfields = crm_fields($cid, false);
$custom = crm_custom_get($lead);

$aiOn = ai_configured($CLIENT);
$aiFacts = json_decode((string) ($lead['ai_facts'] ?? ''), true) ?: [];
$aiStale = $aiOn && $canW && crm_ai_stale($lead);

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
        <?php if (!empty($lead['code'])): ?><button type="button" class="lead-code" title="Copy the lead's code" onclick="navigator.clipboard&&navigator.clipboard.writeText('#<?= e((string) $lead['code']) ?>');this.classList.add('copied')">#<?= e((string) $lead['code']) ?></button><?php endif; ?>
        <a href="tel:+<?= e($phone) ?>" class="lead-phone">+<?= e($phone) ?></a>
        <?php if ($heatCls): ?>
          <details class="lead-heat"><summary><span class="heat <?= $heatCls ?>" id="lead-score"><?= e($heatWord) ?> · <?= (int) $score ?></span></summary>
            <div class="lead-heat-why"><strong>Why <?= (int) $score ?></strong>
              <?php foreach ($why as [$label, $pts]): ?><div><span><?= e($label) ?></span><span class="num"><?= $pts > 0 && $label !== 'Starting point' ? '+' : '' ?><?= (int) $pts ?></span></div><?php endforeach; ?>
            </div></details>
        <?php endif; ?>
        <?php if ($subs > 1): ?><span class="crm-flag" title="Filled in a form or came in again">Came in <?= $subs ?>×</span><?php endif; ?>
        <?php if (!empty($lead['email'])): ?><a href="mailto:<?= e((string) $lead['email']) ?>" class="text-muted"><?= e((string) $lead['email']) ?></a><?php endif; ?>
      </div>
      <div class="lead-meta">
        <span><span class="text-muted">Owner</span> <?= e(crm_user_name($lead['owner_user_id'] !== null ? (int) $lead['owner_user_id'] : null)) ?></span>
        <span><span class="text-muted">Came from</span> <?= e(crm_source_label($lead['source'])) ?><?= !empty($lead['platform']) && crm_platform_label($lead['platform']) !== crm_source_label($lead['source']) ? ' · ' . e(crm_platform_label($lead['platform'])) : '' ?></span>
        <?php if (!empty($lead['campaign'])): ?><span><span class="text-muted">Campaign</span> <?= e((string) $lead['campaign']) ?></span><?php endif; ?>
        <?php if (!empty($lead['data_type'])): ?><span class="pill <?= $lead['data_type'] === 'fresh' ? 'green' : 'gray' ?>"><?= e(crm_data_types()[$lead['data_type']] ?? '') ?></span><?php endif; ?>
        <?php if (!empty($lead['qualification'])): ?><span class="pill <?= $lead['qualification'] === 'qualified' ? 'blue' : 'red' ?>"><?= e(crm_qualifications()[$lead['qualification']] ?? '') ?></span><?php endif; ?>
        <?php if (!empty($lead['project_id'])): ?><span><span class="text-muted">Project</span> <?= e((string) ($pnames[(int) $lead['project_id']] ?? '—')) ?></span><?php endif; ?>
        <?php if (!empty($lead['unit_type'])): ?><span><span class="text-muted">Wants</span> <?= e((string) $lead['unit_type']) ?></span><?php endif; ?>
        <?php if (!empty($lead['budget'])): ?><span><span class="text-muted">Budget</span> <?= e((string) $lead['budget']) ?></span><?php endif; ?>
        <?php if (!empty($lead['payment_pref'])): ?><span><span class="text-muted">Pays</span> <?= $lead['payment_pref'] === 'cash' ? 'Cash' : 'Instalments' ?></span><?php endif; ?>
        <?php if ($lead['deal_value'] !== null): ?><span><span class="text-muted">Value</span> <?= e(crm_money_fmt($lead['deal_value'], $cid)) ?></span><?php endif; ?>
        <?php foreach ($cfields as $f_): $v_ = $custom[$f_['fkey']] ?? ''; if ($v_ === '' || !(int) $f_['active']) continue; ?><span><span class="text-muted"><?= e((string) $f_['label']) ?></span> <?= e(crm_custom_show($f_, $v_)) ?></span><?php endforeach; ?>
        <span><span class="text-muted">Added</span> <?= e(date('j M Y', strtotime((string) $lead['created_at']))) ?></span>
      </div>
    </div>
    <div class="lead-actions">
      <a class="btn btn-ghost btn-sm" href="tel:+<?= e($phone) ?>">Call</a>
      <?php if (can_use('inbox')): ?><a class="btn btn-ghost btn-sm" href="inbox.php?contact=<?= $id ?>">Conversation</a><?php endif; ?>
      <a class="btn btn-ghost btn-sm" href="https://wa.me/<?= e($phone) ?>" target="_blank" rel="noopener">WhatsApp app</a>
    </div>
  </div>

  <?php if (!empty($lead['lost_reason']) && in_array((int) $lead['stage_id'], $lostIds, true)): ?>
    <p class="lead-lost">Lost: <strong><?= e((string) $lead['lost_reason']) ?></strong><?= !empty($lead['lost_note']) ? ' — ' . e((string) $lead['lost_note']) : '' ?></p>
  <?php endif; ?>
  <?php if ($lead['stage_id'] !== null): ?>
  <form method="post" class="lead-stages" aria-label="Stage">
    <?= csrf_field() ?><input type="hidden" name="action" value="stage"><input type="hidden" name="id" value="<?= $id ?>">
    <?php $passed = true; foreach ($stages as $s):
      $isCur = (int) $s['id'] === (int) $lead['stage_id'];
      $cls = $isCur ? 'cur ' . $s['kind'] : ($passed && $s['kind'] === 'open' ? 'done' : '');
      if ($isCur) $passed = false; ?>
      <button name="stage_id" value="<?= (int) $s['id'] ?>" class="lead-stage <?= $cls ?>" <?= $canW ? '' : 'disabled' ?>
              <?= $askLost && !$isCur && $s['kind'] === 'lost' ? 'type="button" data-lost="' . (int) $s['id'] . '"' : '' ?>
              title="<?= $isCur ? 'Current stage' : 'Move to ' . e($s['name']) ?>"><?= e($s['name']) ?></button>
    <?php endforeach; ?>
  </form>
  <?php if ($subList): ?>
  <form method="post" class="lead-subs" aria-label="Sub-status">
    <?= csrf_field() ?><input type="hidden" name="action" value="substatus"><input type="hidden" name="id" value="<?= $id ?>">
    <span class="text-muted">Sub-status</span>
    <?php if ($curStage && $curStage['kind'] === 'lost'): ?>
      <span class="pill red"><?= e((string) ($lead['substatus'] ?: ($lead['lost_reason'] ?? '—'))) ?></span>
    <?php else: foreach ($subList as $sl): $on = (string) ($lead['substatus'] ?? '') === $sl; ?>
      <button name="substatus" value="<?= $on ? '' : e($sl) ?>" class="lead-sub <?= $on ? 'on' : '' ?>" <?= $canW ? '' : 'disabled' ?>
              title="<?= $on ? 'Clear' : 'Set' ?>"><?= e($sl) ?></button>
    <?php endforeach; endif; ?>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</div>

<div class="lead-grid">
  <div class="lead-col">

    <!-- The conversation in a few lines -->
    <?php if ($aiOn || !empty($lead['ai_summary'])): ?>
    <div class="card ai-card" id="ai">
      <div class="row-between" style="flex-wrap:wrap;gap:8px"><h2 style="margin:0;border:0;padding:0">Summary</h2>
        <?php if ($aiOn && $canW): ?><button type="button" class="btn-link" id="ai-refresh">Read the chat again</button><?php endif; ?></div>
      <div id="ai-body">
        <?php if (!empty($lead['ai_summary'])): ?>
          <p class="ai-summary" id="ai-summary"><?= nl2br(e((string) $lead['ai_summary'])) ?></p>
        <?php elseif (!$aiStale): ?>
          <p class="text-muted" style="font-size:13px;margin:8px 0 0">A summary appears once the lead has written to you.</p>
        <?php endif; ?>
      </div>
      <div class="ai-facts" id="ai-facts">
        <?php foreach (['interest' => 'Interest', 'next_step' => 'Next step', 'budget' => 'Budget', 'unit_type' => 'Wants', 'payment' => 'Pays'] as $k => $l):
          if (empty($aiFacts[$k])) continue; ?>
          <span class="ai-fact <?= $k === 'next_step' ? 'wide' : '' ?>"><span class="text-muted"><?= $l ?></span> <?= e($k === 'payment' ? ($aiFacts[$k] === 'cash' ? 'Cash' : 'Instalments') : ucfirst((string) $aiFacts[$k])) ?></span>
        <?php endforeach; ?>
      </div>
      <p class="text-muted ai-when" id="ai-when"><?= !empty($lead['ai_summary_at']) ? 'Read by AI ' . e(date('j M, H:i', strtotime((string) $lead['ai_summary_at']))) . ' — check anything important in the chat.' : '' ?></p>
    </div>
    <?php endif; ?>

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

    <!-- Site visits -->
    <?php if (can_crm('visits')): ?>
    <div class="card" id="visits">
      <div class="row-between" style="flex-wrap:wrap;gap:8px"><h2 style="margin:0;border:0;padding:0">Site visits</h2>
        <?php if ($canW): ?><button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('visit-form').hidden=false;this.hidden=true">+ Book a visit</button><?php endif; ?></div>
      <?php if (!$visits): ?><p class="text-muted" style="margin:8px 0 0;font-size:13px">No visits yet.</p><?php endif; ?>
      <?php foreach ($visits as $v): $past = strtotime((string) $v['starts_at']) < time(); ?>
        <div class="visit-row <?= e((string) $v['status']) ?>">
          <div><strong><?= e(date('D j M, H:i', strtotime((string) $v['starts_at']))) ?></strong>
            <span class="pill <?= ['scheduled' => $past ? 'gold' : 'blue', 'done' => 'green', 'no_show' => 'red', 'cancelled' => 'gray'][$v['status']] ?>">
              <?= e($v['status'] === 'scheduled' && $past ? 'How did it go?' : crm_visit_statuses()[$v['status']]) ?></span>
            <span class="text-muted" style="display:block;font-size:12.5px"><?= e(implode(' · ', array_filter([
                $v['project_id'] ? ($pnames[(int) $v['project_id']] ?? '') : '', (string) ($v['place'] ?? ''), $v['host'] ? 'with ' . $v['host'] : '']))) ?></span>
            <?php if (!empty($v['notes'])): ?><span style="display:block;font-size:12.5px"><?= e((string) $v['notes']) ?></span><?php endif; ?></div>
          <?php if ($canW && $v['status'] === 'scheduled'): ?>
          <div class="visit-acts">
            <?php foreach (($past ? ['visit_done' => 'Came', 'visit_no_show' => 'Didn\'t come'] : []) + ['visit_cancel' => 'Cancel'] as $act => $lbl): ?>
              <form method="post" <?= $act === 'visit_cancel' ? 'onsubmit="return confirm(\'Cancel this visit?\')"' : '' ?>><?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="visit" value="<?= (int) $v['id'] ?>">
                <button class="<?= $act === 'visit_done' ? 'btn btn-primary btn-sm' : ($act === 'visit_cancel' ? 'btn-link' : 'btn btn-ghost btn-sm') ?>"><?= $lbl ?></button></form>
            <?php endforeach; ?>
            <?php if (!$past): ?>
              <details class="visit-move"><summary class="btn-link">Move</summary>
                <form method="post" class="visit-move-form"><?= csrf_field() ?><input type="hidden" name="action" value="visit_move"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="visit" value="<?= (int) $v['id'] ?>">
                  <input type="date" name="visit_date" value="<?= e(date('Y-m-d', strtotime((string) $v['starts_at']))) ?>" required>
                  <input type="time" name="visit_time" value="<?= e(date('H:i', strtotime((string) $v['starts_at']))) ?>" step="900" required>
                  <button class="btn btn-ghost btn-sm">Save</button></form></details>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($canW): ?>
      <form method="post" id="visit-form" class="mt10" hidden>
        <?= csrf_field() ?><input type="hidden" name="action" value="visit_book"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="grid2">
          <div class="field"><span class="lbl">Date</span><input type="date" name="visit_date" min="<?= date('Y-m-d') ?>" required></div>
          <div class="field"><span class="lbl">Time</span><input type="time" name="visit_time" step="900" value="11:00" required></div>
          <div class="field"><span class="lbl">Project</span><select name="project_id" id="visit-project"><option value="0">—</option>
            <?php foreach ($projects as $p_): if (!(int) $p_['active']) continue; ?><option value="<?= (int) $p_['id'] ?>" data-addr="<?= e((string) ($p_['address'] ?? '')) ?>" <?= (int) $p_['id'] === (int) ($lead['project_id'] ?? 0) ? 'selected' : '' ?>><?= e($p_['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><span class="lbl">Where</span><input type="text" name="place" id="visit-place" maxlength="255"
               value="<?= e($projAddr[(int) ($lead['project_id'] ?? 0)] ?? '') ?>" placeholder="Sales office, or a map link"></div>
          <?php if ($isAdmin): ?>
          <div class="field"><span class="lbl">Who meets them</span><select name="host"><option value="0">The lead's owner</option>
            <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
          <?php endif; ?>
          <div class="field"><span class="lbl">Note</span><input type="text" name="notes" maxlength="500" placeholder="Wants to see the 3-bed model"></div>
        </div>
        <?php if ((int) $vs['visit_confirm_tpl']): ?>
          <label class="mod-all"><input type="checkbox" name="confirm" value="1" checked> Send them a WhatsApp confirmation</label>
        <?php elseif ($isAdmin): ?>
          <p class="text-muted" style="font-size:12px">To confirm visits on WhatsApp automatically, choose the templates in <a href="crm_messages.php#visit-msgs">Automatic messages</a>.</p>
        <?php endif; ?>
        <button class="btn btn-primary btn-sm mt10">Book visit</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

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
        <?php if ($subList && !($curStage && $curStage['kind'] === 'lost')): ?>
        <div class="field"><span class="lbl">Sub-status</span><select name="substatus" id="act-sub">
          <?php foreach (array_merge([''], $subList) as $sl): ?><option value="<?= e($sl) ?>" <?= (string) ($lead['substatus'] ?? '') === $sl ? 'selected' : '' ?>><?= $sl === '' ? '—' : e($sl) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
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
          <div class="field" id="act-sub-new" hidden><span class="lbl">Sub-status in the new stage</span><select name="substatus_new"></select></div>
          <div class="grid2" id="act-lost" hidden>
            <div class="field"><span class="lbl">Why was it lost?</span><select name="lost_reason"><option value="">Choose a reason…</option>
              <?php foreach ($reasons as $r): ?><option><?= e($r) ?></option><?php endforeach; ?></select></div>
            <div class="field"><span class="lbl">Anything to add</span><input name="lost_note" maxlength="255" placeholder="Bought in another compound"></div>
          </div>
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
          <textarea name="message" id="send-text" rows="3" placeholder="Message on WhatsApp…" required></textarea>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap" class="mt10">
            <button class="btn btn-primary btn-sm">Send on WhatsApp</button>
            <?php if ($aiOn): ?><button type="button" class="btn btn-ghost btn-sm" id="ai-suggest" title="Write a reply from the conversation — check it before sending">Suggest a reply</button><?php endif; ?>
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
          <div class="field"><span class="lbl">Deal value</span><input name="deal_value" inputmode="decimal" autocomplete="off"
               value="<?= $lead['deal_value'] !== null ? e(number_format((float) $lead['deal_value'], 0, '.', '')) : '' ?>"></div>
          <div class="field"><span class="lbl">Project</span><select name="project_id"><option value="0">—</option>
            <?php foreach ($projects as $p): if (!(int) $p['active'] && (int) $p['id'] !== (int) ($lead['project_id'] ?? 0)) continue; ?>
              <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) ($lead['project_id'] ?? 0) ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
            <?php if (!$projects && $isAdmin): ?><span class="text-muted" style="font-size:12px"><a href="crm_setup.php">Add your projects</a></span><?php endif; ?></div>
          <div class="field"><span class="lbl">Unit type</span><select name="unit_type"><option value="">—</option>
            <?php $ut = (string) ($lead['unit_type'] ?? ''); foreach (array_unique(array_merge($units, $ut !== '' ? [$ut] : [])) as $u): ?>
              <option <?= $u === $ut ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select></div>
          <div class="field"><span class="lbl">Budget</span><input name="budget" maxlength="80" value="<?= e((string) ($lead['budget'] ?? '')) ?>" placeholder="5–7M"></div>
          <div class="field"><span class="lbl">Paying</span><select name="payment_pref"><option value="">—</option>
            <option value="cash" <?= ($lead['payment_pref'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
            <option value="installments" <?= ($lead['payment_pref'] ?? '') === 'installments' ? 'selected' : '' ?>>Instalments</option></select></div>
          <div class="field"><span class="lbl">Qualification</span><select name="qualification"><option value="">Not decided</option>
            <?php foreach (crm_qualifications() as $k => $l): ?><option value="<?= $k ?>" <?= ($lead['qualification'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <?php if ($isAdmin): ?>
          <div class="field"><span class="lbl">Data</span><select name="data_type">
            <?php foreach (crm_data_types() as $k => $l): ?><option value="<?= $k ?>" <?= ($lead['data_type'] ?? 'fresh') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <?php endif; ?>
          <?php foreach ($cfields as $f_): if (!(int) $f_['active']) continue; $v_ = (string) ($custom[$f_['fkey']] ?? ''); $nm = 'cf[' . e((string) $f_['fkey']) . ']'; ?>
          <div class="field"><span class="lbl"><?= e((string) $f_['label']) ?></span>
            <?php if ($f_['type'] === 'list'): ?><select name="<?= $nm ?>"><option value="">—</option>
              <?php foreach (array_unique(array_merge($f_['choices'], $v_ !== '' ? [$v_] : [])) as $ch): ?><option <?= $ch === $v_ ? 'selected' : '' ?>><?= e($ch) ?></option><?php endforeach; ?></select>
            <?php elseif ($f_['type'] === 'date'): ?><input type="date" name="<?= $nm ?>" value="<?= e($v_) ?>">
            <?php elseif ($f_['type'] === 'number'): ?><input name="<?= $nm ?>" inputmode="decimal" autocomplete="off" value="<?= e($v_) ?>">
            <?php else: ?><input name="<?= $nm ?>" maxlength="255" value="<?= e($v_) ?>"><?php endif; ?></div>
          <?php endforeach; ?>
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

    <?php if ($runs && $isAdmin): ?>
    <!-- Automatic follow-ups this lead is in — a manager's view; salespeople do not see or stop them -->
    <div class="card" id="seq-runs">
      <h2>Automatic follow-up</h2>
      <?php foreach ($runs as $r): ?>
        <div class="dup-row">
          <div><strong><?= e((string) $r['name']) ?></strong>
            <span class="text-muted" style="display:block;font-size:12px">
              <?php if ($r['status'] === 'active'): ?>Sent <?= (int) $r['step_idx'] ?> of <?= (int) $r['steps'] ?> · next <?= e(date('D j M, H:i', strtotime((string) $r['next_at']))) ?>
              <?php elseif ($r['status'] === 'done'): ?>All <?= (int) $r['steps'] ?> sent
              <?php else: ?>Stopped — <?= e((string) $r['stop_reason']) ?><?php endif; ?></span></div>
          <?php if ($r['status'] === 'active'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="seq_stop"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="seq" value="<?= (int) $r['sequence_id'] ?>">
            <button class="btn btn-ghost btn-sm">Stop</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($dups): ?>
    <!-- Probably the same person -->
    <div class="card" id="dups">
      <h2>Possible duplicates</h2>
      <p class="text-muted" style="font-size:12.5px;margin-top:-4px">Same name, email, or the same number written another way.
        <?= $isAdmin ? 'Merging moves their messages, notes and answers onto this lead and removes the other one.' : 'An Admin can merge them.' ?></p>
      <?php foreach ($dups as $d): ?>
        <div class="dup-row">
          <div><a href="crm_lead.php?id=<?= (int) $d['id'] ?>"><strong><?= e((string) ($d['name'] ?: '+' . $d['phone_e164'])) ?></strong></a>
            <span class="text-muted" style="display:block;font-size:12px">+<?= e((string) $d['phone_e164']) ?><?= $d['email'] ? ' · ' . e((string) $d['email']) : '' ?>
              · <?= e($d['stage_name'] ?: 'Not in the pipeline') ?> · <?= e(crm_user_name($d['owner_user_id'] !== null ? (int) $d['owner_user_id'] : null)) ?></span></div>
          <?php if ($isAdmin): ?>
          <form method="post" onsubmit="return confirm('Merge this contact into the lead you are looking at? The other one will be removed.')">
            <?= csrf_field() ?><input type="hidden" name="action" value="merge"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="other" value="<?= (int) $d['id'] ?>">
            <button class="btn btn-ghost btn-sm">Merge into this lead</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
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
          <div class="lead-item lead-msg <?= $it['dir'] === 'out' ? 'out' : 'in' ?>" data-g="messages">
            <?php if (!empty($it['media'])): $u = 'media.php?m=' . $it['mid']; ?>
              <div class="ib-media<?= $it['gone'] ? ' gone' : '' ?>">
                <?php if ($it['gone']): ?><span class="ib-media-gone">This <?= $it['media'] === 'audio' ? 'voice note' : ($it['media'] === 'image' ? 'picture' : 'file') ?> arrived before files were kept, so it cannot be played here.</span>
                <?php elseif ($it['media'] === 'audio'): ?><audio controls preload="none" src="<?= $u ?>"></audio><a class="ib-media-dl" href="<?= $u ?>&download=1">Download</a>
                <?php elseif (in_array($it['media'], ['image', 'sticker'], true)): ?><a href="<?= $u ?>" target="_blank" rel="noopener"><img src="<?= $u ?>" alt="Picture" loading="lazy"></a>
                <?php elseif ($it['media'] === 'video'): ?><video controls preload="metadata" src="<?= $u ?>"></video>
                <?php else: ?><a class="ib-file" href="<?= $u ?>&download=1">📎 Download the file</a><?php endif; ?>
              </div>
            <?php endif; ?>
            <?= nl2br(e((string) $it['body'])) ?>
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

<?php if ($canW && $askLost && $lostIds): ?>
<dialog id="lost-dlg" class="lost-dlg" aria-labelledby="lost-title">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="stage"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="stage_id" id="lost-stage">
    <h2 id="lost-title" style="margin-top:0">Why was this lead lost?</h2>
    <div class="lost-reasons">
      <?php foreach ($reasons as $r): ?><label class="act-kind"><input type="radio" name="lost_reason" value="<?= e($r) ?>" required><span><?= e($r) ?></span></label><?php endforeach; ?>
      <label class="act-kind"><input type="radio" name="lost_reason" value="__other"><span>Something else</span></label>
    </div>
    <div class="field" id="lost-other-wrap" hidden><span class="lbl">What happened</span><input name="lost_other" maxlength="80"></div>
    <div class="field"><span class="lbl">Anything to add (optional)</span><input name="lost_note" maxlength="255" placeholder="Bought in another compound"></div>
    <div style="display:flex;gap:8px"><button class="btn btn-primary">Mark as lost</button>
      <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button></div>
  </form>
</dialog>
<?php endif; ?>
<script>
(function(){
  /* AI: summary and suggested reply, asked for in the background. */
  const CSRF = <?= json_encode(csrf_token()) ?>;
  const aiPost = async (action, extra) => {
    const fd = new FormData(); fd.append('action', action); fd.append('id', '<?= $id ?>'); fd.append('csrf_token', CSRF);
    for (const [k, v] of Object.entries(extra || {})) fd.append(k, v);
    return (await fetch('crm_lead.php?id=<?= $id ?>', {method: 'POST', body: fd})).json().catch(() => ({ok: false, error: 'Something went wrong.'}));
  };
  const escH = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  async function aiRead(){
    const body = document.getElementById('ai-body'); if (!body) return;
    body.innerHTML = '<p class="text-muted ai-reading" style="font-size:13px;margin:8px 0 0">Reading the conversation…</p>';
    const d = await aiPost('ai_summary');
    if (!d.ok) { body.innerHTML = '<p class="text-muted" style="font-size:13px;margin:8px 0 0">' + escH(d.error) + '</p>'; return; }
    body.innerHTML = '<p class="ai-summary" id="ai-summary">' + escH(d.summary).replace(/\n/g, '<br>') + '</p>';
    const f = d.facts || {}, words = {interest: 'Interest', next_step: 'Next step', budget: 'Budget', unit_type: 'Wants', payment: 'Pays'};
    document.getElementById('ai-facts').innerHTML = Object.entries(words).filter(([k]) => f[k]).map(([k, l]) =>
      `<span class="ai-fact ${k === 'next_step' ? 'wide' : ''}"><span class="text-muted">${l}</span> ${escH(k === 'payment' ? (f[k] === 'cash' ? 'Cash' : 'Instalments') : f[k].charAt(0).toUpperCase() + f[k].slice(1))}</span>`).join('');
    document.getElementById('ai-when').textContent = 'Read by AI just now — check anything important in the chat.' + (d.filled_words ? ' ' + d.filled_words : '');
  }
  document.getElementById('ai-refresh')?.addEventListener('click', aiRead);
  <?php if ($aiStale): ?>aiRead();<?php endif; ?>
  document.getElementById('ai-suggest')?.addEventListener('click', async e => {
    const btn = e.target, box = document.getElementById('send-text');
    btn.disabled = true; btn.textContent = 'Writing…';
    const d = await aiPost('ai_reply', {hint: box.value});
    btn.disabled = false; btn.textContent = 'Suggest another';
    if (d.ok) { box.value = d.text; box.focus(); } else alert(d.error || 'Could not write a reply.');
  });

  /* A project's address fills "Where", unless something was typed there. */
  const vp = document.getElementById('visit-project'), vpl = document.getElementById('visit-place');
  if (vp) vp.addEventListener('change', () => { const a = vp.selectedOptions[0].dataset.addr || '';
    if (!vpl.dataset.typed) vpl.value = a; });
  if (vpl) vpl.addEventListener('input', () => { vpl.dataset.typed = '1'; });
  /* Lost needs a reason: the stage bar opens the question, and the activity form shows it. */
  const LOST = <?= json_encode($askLost ? $lostIds : []) ?>;
  const dlg = document.getElementById('lost-dlg');
  document.querySelectorAll('[data-lost]').forEach(b => b.addEventListener('click', () => {
    document.getElementById('lost-stage').value = b.dataset.lost; dlg.showModal();
  }));
  if (dlg) dlg.querySelectorAll('input[name=lost_reason]').forEach(r => r.addEventListener('change', () => {
    const other = dlg.querySelector('input[value=__other]').checked;
    document.getElementById('lost-other-wrap').hidden = !other;
    dlg.querySelector('input[name=lost_other]').required = other;
  }));
  const actStage = document.querySelector('#act-form select[name=stage_id]');
  if (actStage) {
    const syncLost = () => {
      const lost = LOST.includes(parseInt(actStage.value, 10)) && !actStage.selectedOptions[0].text.includes('(current)');
      document.getElementById('act-lost').hidden = !lost;
      document.querySelector('#act-lost select').required = lost;
    };
    actStage.addEventListener('change', syncLost); syncLost();
    /* Moving stage: the sub-status list is the new stage's, and the current one no longer applies. */
    const SUBS = <?= json_encode($subsByStage, JSON_UNESCAPED_UNICODE) ?>;
    const subWrap = document.getElementById('act-sub-new'), subCur = document.getElementById('act-sub');
    const syncSub = () => {
      const moving = !actStage.selectedOptions[0].text.includes('(current)');
      const list = SUBS[actStage.value] || [];
      if (subCur) subCur.closest('.field').hidden = moving;
      subWrap.hidden = !moving || !list.length;
      const sel = subWrap.querySelector('select');
      sel.innerHTML = '<option value="">—</option>' + list.map(l => `<option>${l.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}</option>`).join('');
    };
    actStage.addEventListener('change', syncSub); syncSub();
  }

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
