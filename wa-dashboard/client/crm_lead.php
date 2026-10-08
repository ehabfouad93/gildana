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

    // A hidden number, opened to call or WhatsApp: handed over, and recorded.
    if ($a === 'reveal') {
        $ph = crm_phone_reveal($CLIENT, $id, $me, ($_POST['how'] ?? '') === 'whatsapp' ? 'whatsapp' : 'call');
        json_out($ph ? ['ok' => true, 'phone' => $ph] : ['ok' => false, 'error' => 'Lead not found.']);
    }
    if ($a === 'delete' && ($isAdmin || can_crm_action('delete'))) {
        if (crm_delete_lead($CLIENT, $id, $me)) { flash('Deleted. It is in the recycle bin for 30 days if you need it back.'); redirect('crm.php'); }
        $err = 'This lead is not in the pipeline.';
    }
    if ($a === 'restore' && $isAdmin) {
        if (crm_restore_lead($CLIENT, $id, $me)) flash('Brought back into the pipeline.');
        $back();
    }
    if ($a === 'delete_request' && can_write()) {
        $r = crm_request_delete($CLIENT, $id, $me, (string) ($_POST['reason'] ?? ''));
        if ($r['ok']) { flash('Sent to a manager. You will be told when they decide.'); $back(); }
        $err = $r['error'];
    }

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
        $before = crm_field_snapshot($cid, $lead);
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
            crm_log_changes($cid, $id, $before, $me);
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
                 'user_id' => $isAdmin ? ((int) ($_POST['host'] ?? 0) ?: null) : null, 'confirm' => !empty($_POST['confirm']),
                 'kind' => ($_POST['kind'] ?? '') === 'online' ? 'online' : 'site', 'meet_url' => (string) ($_POST['meet_url'] ?? '')], $me);
        if ($r['ok']) {
            $when = date('D j M, H:i', strtotime($d . ' ' . $t));
            flash(!empty($r['meet_url']) ? 'Online meeting booked for ' . $when . '. Link: ' . $r['meet_url']
                  . (!empty($r['warning']) ? ' (Google Meet was not used: ' . $r['warning'] . ')' : '') : 'Visit booked for ' . $when . '.');
            $back('#visits');
        }
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
    if ($a === 'send_sms' && can_write() && crm_sms_available($CLIENT)) {
        require_once __DIR__ . '/../includes/sms.php';
        require_once __DIR__ . '/../includes/crm_automation.php';
        $text = trim((string) ($_POST['message'] ?? ''));
        if ($text === '') $err = 'Write the SMS first.';
        elseif (empty($lead['phone_e164'])) $err = 'This lead has no phone number.';
        elseif (!empty($lead['sms_opt_out_at'])) $err = 'This person asked not to receive SMS.';
        else {
            $r = sms_send_now($CLIENT, (string) $lead['phone_e164'], sms_render($text, $lead), ['source' => 'crm', 'render' => false, 'user_id' => $me], $id);
            if ($r['ok']) { crm_log_activity($CLIENT, $id, 'sms', null, $text, $me); flash('SMS sent.'); $back('#timeline'); }
            $err = 'The SMS was not sent: ' . $r['error'];
        }
    }
    if ($a === 'sms_optout' && can_write()) {
        require_once __DIR__ . '/../includes/sms.php';
        db_run("UPDATE contacts SET sms_opt_out_at=" . (!empty($_POST['out']) ? 'NOW()' : 'NULL') . " WHERE id=? AND client_id=?", [$id, $cid]);
        flash(!empty($_POST['out']) ? 'They will not receive SMS.' : 'SMS allowed again.');
        $back();
    }
    if ($a === 'send') {
        // Out through whatever this person's admin chose for them — see sender_for().
        $r = inbox_send($CLIENT, $id, (string) ($_POST['message'] ?? ''));
        if ($r['ok']) { flash('Sent.'); $back('#timeline'); }
        $err = $r['error'] ?: 'The message could not be sent.';
    }
    // Transfer: an Admin to anyone, a team leader within their team — the same rule as the leads list.
    if ($a === 'transfer') {
        $leader = !$isAdmin && crm_is_team_leader();
        $to = (string) ($_POST['to'] ?? '');
        $vis = crm_visible_owner_ids() ?? [];
        if (!$isAdmin && !($leader && ctype_digit($to) && in_array((int) $to, $vis, true))) { $err = 'Only an Admin or a team leader can transfer a lead.'; }
        elseif (ctype_digit($to)) {
            require_once __DIR__ . '/../includes/crm_transfer.php';
            $r = crm_bulk_transfer($CLIENT, [$id], ['users' => [['id' => (int) $to]], 'data_type' => (string) ($_POST['data_type'] ?? ''),
                    'reason' => (string) ($_POST['reason'] ?? ''), 'notes' => (string) ($_POST['notes'] ?? ''), 'keep_followup' => !empty($_POST['keep_followup']),
                    'history' => (string) ($_POST['history'] ?? ''), 'fresh_start' => !empty($_POST['fresh_start']), 'show_status' => !empty($_POST['show_status'])], $me);
            if ($r['ok'] && $r['moved']) { flash('Transferred to ' . crm_user_name((int) $to) . '.'); $back(); }
            $err = $r['error'] ?? 'It already belongs to that person.';
        } else {
            crm_assign($CLIENT, $id, $to === 'none' ? null : crm_assign_next($CLIENT, $lead), $me);
            flash('Transferred.'); $back();
        }
    }
    // The deal is done: straight to the first "won" stage.
    if ($a === 'won' && can_write()) {
        $wonId = 0; foreach ($stages as $s_) if ($s_['kind'] === 'won') { $wonId = (int) $s_['id']; break; }
        if ($wonId && $stageMove($wonId)) { flash('Marked as won.'); $back(); }
    }
    if ($a === 'event_add' && can_write() && can_crm('visits')) {
        require_once __DIR__ . '/../includes/crm_sales_events.php';
        $n = sev_ready() ? sev_add_guests($CLIENT, (int) ($_POST['event_id'] ?? 0), [$id], $me) : 0;
        flash($n ? 'Added to the event\'s guest list.' : 'Already on that event\'s list, or the event is closed.', $n ? 'success' : 'error');
        $back();
    }
    if ($a === 'add_to_crm') {
        crm_add_lead($CLIENT, $id, '', is_sales() ? $me : 'auto', null, $me);
        flash('Added to the pipeline.');
        $back();
    }
    if ($a === 'remove' && $isAdmin && false) {   // replaced by delete (to the recycle bin)
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
        'field'    => ($ev['field'] ?? 'A detail') . ': ' . ($ev['from_val'] !== null ? $ev['from_val'] . ' → ' : '') . ($ev['to_val'] ?? '(cleared)'),
        'deleted'  => 'Deleted (to the recycle bin)',
        'restored' => 'Brought back from the recycle bin into ' . $sn($ev['to_val']),
        'del_request' => 'Asked a manager to delete it — ' . $ev['to_val'],
        'substatus'=> $ev['to_val'] !== null ? 'Sub-status: ' . $ev['to_val'] . ($ev['from_val'] !== null ? ' (was ' . $ev['from_val'] . ')' : '') : 'Sub-status cleared',
        'qualified'=> 'Marked ' . (crm_qualifications()[(string) $ev['to_val']] ?? 'not set') ,
        'visit'    => 'Site visit booked for ' . date('D j M, H:i', strtotime((string) $ev['to_val'])),
        'meeting'  => 'Online meeting booked for ' . date('D j M, H:i', strtotime((string) $ev['to_val'])),
        'event'    => (string) $ev['to_val'],
        'transfer_note' => 'Transfer note: ' . $ev['to_val'],
        'visit_moved' => 'Site visit moved from ' . date('D j M, H:i', strtotime((string) $ev['from_val'])) . ' to ' . date('D j M, H:i', strtotime((string) $ev['to_val'])),
        'seq_start'=> 'Joined the follow-up sequence "' . ($seqNames[(int) $ev['to_val']] ?? 'a removed sequence') . '"',
        'seq_stop' => 'Left the sequence "' . ($seqNames[(int) $ev['from_val']] ?? 'a removed sequence') . '" — ' . $ev['to_val'],
        default    => $ev['kind'],
    };
    $feed[] = ['t' => $ev['created_at'], 'group' => 'history', 'kind' => 'event', 'body' => $text, 'by' => $who($ev['user_id'])];
}
usort($feed, fn($a, $b) => strcmp((string) $b['t'], (string) $a['t']));
// After a transfer that hid the earlier history, this viewer sees only what happened since.
require_once __DIR__ . '/../includes/crm_transfer.php';
$hideBefore = crm_history_hidden_for($lead);
if ($hideBefore !== null) {
    $feed = array_values(array_filter($feed, fn($it) => (string) $it['t'] >= $hideBefore));
    $visits = array_values(array_filter($visits, fn($v) => (string) $v['created_at'] >= $hideBefore));
}
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
$hidePh = crm_phone_hidden();
$canDel = $isAdmin || can_crm_action('delete');
$pendingDel = !$canDel && (int) (function () use ($cid, $id) { try { return db_val("SELECT COUNT(*) FROM crm_requests WHERE client_id=? AND kind='delete' AND contact_id=? AND status='pending'", [$cid, $id]); } catch (Throwable $e) { return 0; } })();
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
if ($hideBefore !== null) { $aiOn = false; $aiStale = false; $lead['ai_summary'] = null; $aiFacts = []; }   // the summary would tell the hidden story

/* The numbers down the side: what has been done with this lead, and how it went. */
$good = ['answered', 'interested', 'booked', 'sent_info', 'visited'];
$bad  = ['no_answer', 'busy', 'not_interested', 'wrong_number', 'no_show'];
$st = ['acts' => 0, 'good' => 0, 'bad' => 0, 'visits' => 0, 'msg_in' => 0, 'msg_out' => 0, 'last' => null];
foreach ($feed as $it) {
    if ($it['kind'] === 'act' && $it['act'] !== 'note') {
        $st['acts']++;
        if (in_array($it['outcome'], $good, true)) $st['good']++;
        if (in_array($it['outcome'], $bad, true)) $st['bad']++;
        $st['last'] = max((string) $st['last'], (string) $it['t']);
    }
    if ($it['kind'] === 'msg') { $st[$it['dir'] === 'out' ? 'msg_out' : 'msg_in']++; if ($it['dir'] === 'out') $st['last'] = max((string) $st['last'], (string) $it['t']); }
}
$st['visits'] = count(array_filter($visits, fn($v) => $v['status'] === 'done'));
$age = !empty($lead['crm_added_at']) ? max(0, (int) floor((time() - strtotime((string) $lead['crm_added_at'])) / 86400)) : null;
$contacted = !empty($lead['first_response_at']) || $st['acts'] > 0 || $st['msg_out'] > 0;

// Who had it, and every move between people and stages.
$moves = db_all("SELECT * FROM crm_events WHERE contact_id=? AND kind IN ('assigned','reclaimed') ORDER BY id DESC LIMIT 50", [$id]);
$rotations = count(array_filter($moves, fn($m_) => $m_['kind'] === 'reclaimed' || $m_['from_val'] !== null));
$stageMoves = db_all("SELECT * FROM crm_events WHERE contact_id=? AND kind IN ('stage','added') ORDER BY id DESC LIMIT 50", [$id]);
$createdBy = db_row("SELECT user_id, created_at FROM crm_events WHERE contact_id=? AND kind='added' ORDER BY id LIMIT 1", [$id]);
$myEvents = []; $openEvents = [];
if (can_crm('visits')) {
    require_once __DIR__ . '/../includes/crm_sales_events.php';
    try {
        $myEvents = db_all("SELECT e.id, e.name, e.starts_at, g.status FROM sales_event_guests g JOIN sales_events e ON e.id=g.event_id WHERE g.contact_id=? ORDER BY e.starts_at DESC", [$id]);
        $openEvents = db_all("SELECT id, name, starts_at FROM sales_events WHERE client_id=? AND status='active' AND starts_at > NOW() ORDER BY starts_at LIMIT 30", [$cid]);
    } catch (Throwable $e) {}
}
$canTransfer = $isAdmin || crm_is_team_leader();
$transferTo = $isAdmin ? $people : array_values(array_filter($people, fn($u) => in_array((int) $u['id'], crm_visible_owner_ids() ?? [], true)));
$wonStage = null; foreach ($stages as $s_) if ($s_['kind'] === 'won') { $wonStage = $s_; break; }
$lostStage = null; foreach ($stages as $s_) if ($s_['kind'] === 'lost') { $lostStage = $s_; break; }
$initials = function (string $n): string {
    $w = preg_split('/\s+/u', trim($n)) ?: [];
    $i = mb_strtoupper(mb_substr($w[0] ?? '', 0, 1) . mb_substr($w[1] ?? '', 0, 1));
    return $i !== '' ? $i : '#';
};
$attrs = json_decode((string) ($lead['attributes'] ?? ''), true) ?: [];
$ago = function (?string $t): string {
    if (!$t) return 'Never';
    $d = time() - strtotime($t);
    if ($d < 3600) return max(1, (int) ($d / 60)) . ' min ago';
    if ($d < 86400) return (int) ($d / 3600) . ' h ago';
    return (int) ($d / 86400) . ' days ago';
};

$title = (string) ($lead['name'] ?: ($phone !== '' ? '+' . $phone : (!empty($lead['ig_username']) ? '@' . $lead['ig_username'] : 'Contact #' . $id)));
// Messenger / Instagram people have no number until they give one: no Call or WhatsApp buttons for them.
$socialOnly = $phone === '';
client_header($title, 'crm', $CLIENT);
$ownerName = crm_user_name($lead['owner_user_id'] !== null ? (int) $lead['owner_user_id'] : null);
?>
<a class="lead-back" href="crm.php">&larr; Back to leads</a>
<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<?php if ($lead['stage_id'] === null && !empty($lead['deleted_at'])): ?>
  <div class="alert warn">This lead was deleted <?= e(date('j M', strtotime((string) $lead['deleted_at']))) ?> and is in the recycle bin.
    <?php if ($isAdmin): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="restore">
        <button class="btn btn-sm btn-primary" style="margin-inline-start:8px">Bring it back</button></form>
    <?php endif; ?></div>
<?php elseif ($lead['stage_id'] === null): ?>
  <div class="alert info">This contact is not in the pipeline.
    <?php if ($canW): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="add_to_crm">
        <button class="btn btn-sm btn-primary" style="margin-inline-start:8px">Add to the CRM</button></form>
    <?php endif; ?></div>
<?php endif; ?>

<div class="lead-layout">
<div class="lead-main">

  <!-- ═══ The lead: who, how to reach them, the details ═══ -->
  <div class="card lead-hero">
    <div class="lead-id">
      <span class="lead-avatar" aria-hidden="true"><?= e($initials($title)) ?></span>
      <div class="lead-id-text">
        <?php page_head($title); ?>
        <div class="lead-id-line">
          <?php if (!empty($lead['code'])): ?><span class="text-muted">ID:</span><button type="button" class="lead-code" title="Copy the lead's code" onclick="navigator.clipboard&&navigator.clipboard.writeText('#<?= e((string) $lead['code']) ?>');this.classList.add('copied')">#<?= e((string) $lead['code']) ?></button><?php endif; ?>
          <span class="lead-chip" title="How many times the lead passed from one person to another">⇄ <?= e(t('Transfers / rotations')) ?>: <?= (int) $rotations ?></span>
          <?php if ($subs > 1): ?><span class="lead-chip" title="Filled in a form or came in again">Came in <?= $subs ?>×</span><?php endif; ?>
          <?php if ($heatCls): ?>
            <details class="lead-heat"><summary><span class="heat <?= $heatCls ?>" id="lead-score"><?= e($heatWord) ?> · <?= (int) $score ?></span></summary>
              <div class="lead-heat-why"><strong>Why <?= (int) $score ?></strong>
                <?php foreach ($why as [$label, $pts]): ?><div><span><?= e($label) ?></span><span class="num"><?= $pts > 0 && $label !== 'Starting point' ? '+' : '' ?><?= (int) $pts ?></span></div><?php endforeach; ?>
              </div></details>
          <?php endif; ?>
        </div>
        <div class="lead-id-line">
          <span class="pill <?= ($lead['qualification'] ?? '') === 'qualified' ? 'blue' : (($lead['qualification'] ?? '') === 'not_qualified' ? 'red' : 'gray') ?>"><?= e(crm_qualifications()[$lead['qualification'] ?? ''] ?? 'Not set') ?></span>
          <?php if (!empty($lead['data_type'])): ?><span class="pill <?= $lead['data_type'] === 'fresh' ? 'green' : 'gray' ?>"><?= e(crm_data_types()[$lead['data_type']] ?? '') ?></span><?php endif; ?>
          <?php if ($curStage): ?><span class="pill <?= ['won' => 'blue', 'lost' => 'red'][$curStage['kind']] ?? 'gold' ?>"><?= e((string) $curStage['name']) ?><?= !empty($lead['substatus']) ? ' · ' . e((string) $lead['substatus']) : '' ?></span><?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($hideBefore !== null): ?>
      <p class="lead-hidden-note">Passed to you <?= e(date('j M Y', strtotime($hideBefore))) ?>. What happened before is not shown.
        <?php if (!empty($lead['prev_status'])): ?> Last status before: <strong><?= e((string) $lead['prev_status']) ?></strong>.<?php endif; ?></p>
    <?php endif; ?>
    <?php if (!empty($lead['lost_reason']) && in_array((int) $lead['stage_id'], $lostIds, true)): ?>
      <p class="lead-lost"><?= e(t('Lost')) ?>: <strong><?= e((string) $lead['lost_reason']) ?></strong><?= !empty($lead['lost_note']) ? ' — ' . e((string) $lead['lost_note']) : '' ?></p>
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
    <?php if ($subList && !($curStage && $curStage['kind'] === 'lost')): ?>
    <form method="post" class="lead-subs" aria-label="Sub-status">
      <?= csrf_field() ?><input type="hidden" name="action" value="substatus"><input type="hidden" name="id" value="<?= $id ?>">
      <span class="text-muted">Sub-status</span>
      <?php foreach ($subList as $sl): $on = (string) ($lead['substatus'] ?? '') === $sl; ?>
        <button name="substatus" value="<?= $on ? '' : e($sl) ?>" class="lead-sub <?= $on ? 'on' : '' ?>" <?= $canW ? '' : 'disabled' ?>
                title="<?= $on ? 'Clear' : 'Set' ?>"><?= e($sl) ?></button>
      <?php endforeach; ?>
    </form>
    <?php endif; ?>
    <?php endif; ?>

    <div class="lead-info">
      <section>
        <h3 class="lead-sec">Contact information</h3>
        <dl class="lead-dl">
          <dt>Phone</dt><dd><?php if ($socialOnly): ?><span class="text-muted"><?= !empty($lead['fb_psid']) ? 'Messenger' : '' ?><?= !empty($lead['fb_psid']) && !empty($lead['ig_sid']) ? ' · ' : '' ?><?= !empty($lead['ig_sid']) ? 'Instagram' . (!empty($lead['ig_username']) ? ' @' . e((string) $lead['ig_username']) : '') : '' ?> — no number yet</span>
            <?php elseif ($hidePh): ?><button type="button" class="btn-link ltr lead-phone" data-reveal="call" title="Call — opening the number is recorded"><?= e(crm_phone_mask($phone)) ?></button>
            <?php else: ?><a class="ltr lead-phone" href="tel:+<?= e($phone) ?>">+<?= e($phone) ?></a><?php endif; ?></dd>
          <dt>Email</dt><dd><?= !empty($lead['email']) ? '<a href="mailto:' . e((string) $lead['email']) . '">' . e((string) $lead['email']) . '</a>' : '<span class="text-muted">—</span>' ?></dd>
          <dt>Contact status</dt><dd><span class="pill <?= $contacted ? 'green' : 'gold' ?>"><?= $contacted ? 'Contacted' : 'Not contacted' ?></span></dd>
          <dt>Last contact</dt><dd><?= e($ago($st['last'])) ?></dd>
          <dt>Next follow-up</dt><dd><?= $due ? '<span class="' . ($dueState === 'late' ? 'text-danger' : '') . '">' . e(date('D j M, H:i', $due)) . '</span>' : '<span class="text-muted">Nothing planned</span>' ?></dd>
          <dt>Notes</dt><dd><?= !empty($lead['followup_note']) ? e((string) $lead['followup_note']) : '<em class="text-muted">No notes</em>' ?></dd>
        </dl>
      </section>
      <section>
        <h3 class="lead-sec">Lead details</h3>
        <dl class="lead-dl lead-meta">
          <dt>Assigned to</dt><dd><span class="lead-tag"><?= e($ownerName) ?></span></dd>
          <dt>Lead source</dt><dd><span class="lead-tag"><?= e(crm_source_label($lead['source'])) ?></span><?= crm_origin_visible() && crm_is_meta_direct($lead['source'] ?? null) ? ' <span class="pill blue">Meta direct</span>' : '' ?></dd>
          <?php if (crm_origin_visible()): ?><dt>Came</dt><dd><?= e(crm_arrival_label($lead)) ?></dd><?php endif; ?>
          <dt>Data</dt><dd><?= e(crm_data_types()[$lead['data_type'] ?? ''] ?? '—') ?></dd>
          <dt>Qualification</dt><dd><?= e(crm_qualifications()[$lead['qualification'] ?? ''] ?? 'Not set') ?></dd>
          <?php if (!empty($lead['platform'])): ?><dt>Platform</dt><dd><?= e(crm_platform_label($lead['platform'])) ?></dd><?php endif; ?>
          <?php if (crm_origin_visible() && !empty($lead['campaign'])): ?><dt>Campaign</dt><dd><span class="lead-box"><?= e((string) $lead['campaign']) ?></span></dd><?php endif; ?>
          <?php if (crm_origin_visible() && !empty($lead['adset'])): ?><dt>Ad set</dt><dd><?= e((string) $lead['adset']) ?></dd><?php endif; ?>
          <?php if (crm_origin_visible() && !empty($lead['ad_name'])): ?><dt>Ad</dt><dd><?= e((string) $lead['ad_name']) ?></dd><?php endif; ?>
          <dt>Preferred project</dt><dd><?= !empty($lead['project_id']) ? e((string) ($pnames[(int) $lead['project_id']] ?? '—')) : '<span class="text-muted">—</span>' ?></dd>
          <dt>Preferred type</dt><dd><?= !empty($lead['unit_type']) ? e((string) $lead['unit_type']) : '<span class="text-muted">—</span>' ?></dd>
          <dt>Budget</dt><dd><?= !empty($lead['budget']) ? e((string) $lead['budget']) : '<span class="text-muted">—</span>' ?></dd>
          <?php if (!empty($lead['payment_pref'])): ?><dt>Pays</dt><dd><?= $lead['payment_pref'] === 'cash' ? 'Cash' : 'Instalments' ?></dd><?php endif; ?>
          <?php if ($lead['deal_value'] !== null): ?><dt>Deal value</dt><dd><?= e(crm_money_fmt($lead['deal_value'], $cid)) ?></dd><?php endif; ?>
          <?php foreach ($cfields as $f_): $v_ = $custom[$f_['fkey']] ?? ''; if ($v_ === '' || !(int) $f_['active']) continue; ?>
            <dt><?= e((string) $f_['label']) ?></dt><dd><?= e(crm_custom_show($f_, $v_)) ?></dd><?php endforeach; ?>
        </dl>
      </section>
    </div>

    <h3 class="lead-sec">Timeline</h3>
    <dl class="lead-dl lead-dl-inline">
      <dt>Created</dt><dd><?= e(date('M j, Y, g:i A', strtotime((string) ($lead['crm_added_at'] ?: $lead['created_at'])))) ?></dd>
      <dt>Created by</dt><dd><?= e($createdBy && $createdBy['user_id'] ? $who($createdBy['user_id']) : crm_source_label($lead['source'])) ?></dd>
      <?php if (!empty($lead['assigned_at'])): ?><dt>Assigned</dt><dd><?= e(date('M j, Y, g:i A', strtotime((string) $lead['assigned_at']))) ?></dd><?php endif; ?>
    </dl>
  </div>

  <!-- ═══ The conversation in a few lines ═══ -->
  <?php if ($aiOn || !empty($lead['ai_summary'])): ?>
  <div class="card ai-card" id="ai">
    <div class="row-between" style="flex-wrap:wrap;gap:8px"><h2 style="margin:0;border:0;padding:0">AI summary</h2>
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

  <!-- ═══ Activity & chat history ═══ -->
  <div class="card" id="timeline">
    <h2 class="lead-card-h"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 10h3.5l2.5-6 4 12 2.5-6H18"/></svg> Activity &amp; chat history</h2>
    <div class="lead-tabs lead-filter" role="tablist">
      <button type="button" class="on" data-f="all"><?= e(t('All')) ?> (<?= count($feed) ?>)</button>
      <button type="button" data-f="activity"><?= e(t('Activities')) ?> (<?= (int) ($counts['activity'] ?? 0) ?>)</button>
      <button type="button" data-f="messages"><?= e(t('Messages')) ?> (<?= (int) ($counts['messages'] ?? 0) ?>)</button>
      <button type="button" data-f="history"><?= e(t('Changes')) ?> (<?= (int) ($counts['history'] ?? 0) ?>)</button>
    </div>
    <div class="lead-feed mt10">
      <p class="lead-empty" <?= $feed ? 'hidden' : '' ?>><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><br>No activities recorded for this lead</p>
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

  <!-- ═══ Online meetings and site visits ═══ -->
  <?php if (can_crm('visits')): ?>
  <div class="card" id="visits">
    <div class="row-between" style="flex-wrap:wrap;gap:8px">
      <h2 class="lead-card-h"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="11" height="10" rx="2"/><path d="M13 9l5-3v8l-5-3z"/></svg> Meetings &amp; visits</h2>
      <?php if ($canW): ?><div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="btn btn-dark btn-sm" data-book="online">+ Online meeting</button>
        <button type="button" class="btn btn-ghost btn-sm" data-book="site">+ Book a visit</button></div><?php endif; ?>
    </div>
    <?php if (!$visits): ?><p class="lead-empty"><svg width="34" height="34" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="11" height="10" rx="2"/><path d="M13 9l5-3v8l-5-3z"/></svg><br>No meetings or visits yet</p><?php endif; ?>
    <?php foreach ($visits as $v): $past = strtotime((string) $v['starts_at']) < time(); ?>
      <div class="visit-row <?= e((string) $v['status']) ?>">
        <div><strong><?= e(date('D j M, H:i', strtotime((string) $v['starts_at']))) ?></strong>
          <?php $isOnline = ($v['kind'] ?? 'site') === 'online'; ?><span class="pill <?= $isOnline ? 'blue' : 'gray' ?>"><?= $isOnline ? 'Online' : 'Site visit' ?></span>
          <span class="pill <?= ['scheduled' => $past ? 'gold' : 'blue', 'done' => 'green', 'no_show' => 'red', 'cancelled' => 'gray'][$v['status']] ?>">
            <?= e($v['status'] === 'scheduled' && $past ? 'How did it go?' : crm_visit_statuses()[$v['status']]) ?></span>
          <span class="text-muted" style="display:block;font-size:12.5px"><?= e(implode(' · ', array_filter([
              $v['project_id'] ? ($pnames[(int) $v['project_id']] ?? '') : '', $isOnline ? '' : (string) ($v['place'] ?? ''), $v['host'] ? 'with ' . $v['host'] : '']))) ?></span>
          <?php if ($isOnline && !empty($v['meet_url'])): ?><a class="meet-link" href="<?= e((string) $v['meet_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $v['meet_url']) ?></a><?php endif; ?>
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
      <div class="lead-quick" style="margin-bottom:8px">
        <label class="act-kind"><input type="radio" name="kind" value="site" checked onchange="visitKind()"><span>Site visit</span></label>
        <label class="act-kind"><input type="radio" name="kind" value="online" onchange="visitKind()"><span>Online meeting</span></label>
      </div>
      <div class="grid2">
        <div class="field"><span class="lbl">Date</span><input type="date" name="visit_date" min="<?= date('Y-m-d') ?>" required></div>
        <div class="field"><span class="lbl">Time</span><input type="time" name="visit_time" step="900" value="11:00" required></div>
        <div class="field"><span class="lbl">Project</span><select name="project_id" id="visit-project"><option value="0">—</option>
          <?php foreach ($projects as $p_): if (!(int) $p_['active']) continue; ?><option value="<?= (int) $p_['id'] ?>" data-addr="<?= e((string) ($p_['address'] ?? '')) ?>" <?= (int) $p_['id'] === (int) ($lead['project_id'] ?? 0) ? 'selected' : '' ?>><?= e($p_['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field" id="visit-where"><span class="lbl">Where</span><input type="text" name="place" id="visit-place" maxlength="255"
             value="<?= e($projAddr[(int) ($lead['project_id'] ?? 0)] ?? '') ?>" placeholder="Sales office, or a map link"></div>
        <div class="field" id="visit-meet" hidden><span class="lbl">Meeting link</span><input type="url" name="meet_url" maxlength="500"
             placeholder="Leave empty to create one"></div>
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
      <button class="btn btn-primary btn-sm mt10" id="visit-go">Book visit</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ═══════════ The side: what you can do, and the numbers ═══════════ -->
<aside class="lead-aside">
  <div class="card lead-actions-card">
    <h2 class="lead-card-h">Actions</h2>
    <div class="lead-actions">
      <?php if ($canW): ?>
        <button type="button" class="la la-orange" data-dlg="act-dlg" data-kind="call"><span aria-hidden="true">＋</span> New activity</button>
        <button type="button" class="la la-outline" data-dlg="act-dlg" data-kind="note">Add note</button>
      <?php endif; ?>
      <?php if ($socialOnly): ?>
      <?php elseif ($hidePh): ?><button type="button" class="la la-outline" data-reveal="call">Call</button>
      <?php else: ?><a class="la la-outline" href="tel:+<?= e($phone) ?>">Call</a><?php endif; ?>
      <?php if ($socialOnly): ?>
      <?php elseif ($hidePh): ?><button type="button" class="la la-green" data-reveal="whatsapp">WhatsApp app</button>
      <?php else: ?><a class="la la-green" href="https://wa.me/<?= e($phone) ?>" target="_blank" rel="noopener">WhatsApp app</a><?php endif; ?>
      <?php if ($canW && can_use('inbox')): ?><button type="button" class="la la-teal" data-dlg="send-dlg">Send message</button><?php endif; ?>
      <?php if ($canW && !empty($lead['phone_e164']) && crm_sms_available($CLIENT)): ?><button type="button" class="la" data-dlg="sms-dlg">Send SMS</button><?php endif; ?>
      <?php if (can_use('inbox')): ?><a class="la la-outline" href="inbox.php?contact=<?= $id ?>">Conversation</a><?php endif; ?>
      <?php if ($canW): ?><button type="button" class="la la-cyan" data-dlg="fu-dlg">Follow-up</button><?php endif; ?>
      <?php if ($canW && can_crm('visits')): ?>
        <button type="button" class="la la-purple" data-book="site">Insert visit</button>
        <button type="button" class="la la-blue" data-book="online">Online meeting</button>
        <?php if ($openEvents): ?><button type="button" class="la la-indigo" data-dlg="ev-dlg">Add to event</button><?php endif; ?>
      <?php endif; ?>
      <?php if ($canTransfer && $lead['stage_id'] !== null): ?><button type="button" class="la la-violet" data-dlg="tr-dlg">⇄ Transfer lead</button><?php endif; ?>
      <?php if ($canW && $lead['stage_id'] !== null && $isOpen): ?>
        <?php if ($lostStage): ?>
          <?php if ($askLost): ?><button type="button" class="la la-yellow" data-lost="<?= (int) $lostStage['id'] ?>">Close lead</button>
          <?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="stage"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="la la-yellow" name="stage_id" value="<?= (int) $lostStage['id'] ?>" onclick="return confirm('Close this lead as lost?')">Close lead</button></form><?php endif; ?>
        <?php endif; ?>
        <?php if ($wonStage): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="won"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="la la-emerald" onclick="return confirm('Mark this deal as done (won)?')">Done deal</button></form><?php endif; ?>
      <?php endif; ?>
      <button type="button" class="la la-plain" data-dlg="edit-dlg">Edit lead</button>
    </div>
    <?php if ($lead['stage_id'] !== null && $canW): ?>
      <div class="lead-del">
      <?php if ($canDel): ?>
        <form method="post" onsubmit="return confirm('Delete this lead? It goes to the recycle bin and can be brought back for 30 days.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn-link" style="color:var(--danger)">Delete lead</button></form>
      <?php elseif ($pendingDel): ?>
        <p class="text-muted" style="font-size:12.5px;margin:0">You asked a manager to delete this lead — waiting for their answer.</p>
      <?php else: ?>
        <details><summary class="btn-link" style="color:var(--danger)">Ask to delete</summary>
          <form method="post" class="mt10"><?= csrf_field() ?><input type="hidden" name="action" value="delete_request"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="field"><span class="lbl">Why should it go?</span><input name="reason" maxlength="255" required placeholder="Duplicate of another lead / test / wrong number"></div>
            <button class="btn btn-ghost btn-sm">Send to a manager</button></form></details>
      <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- What happens next -->
  <div class="card lead-next <?= $dueState ?>" id="next">
    <h2 class="lead-card-h">Next follow-up</h2>
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
        <button type="button" class="btn btn-ghost btn-sm" data-dlg="fu-dlg">Change</button>
      </div>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-muted" style="margin:0">Nothing planned. Leads with a next step get followed up; leads without one get forgotten.</p>
      <?php if ($canW): ?><button type="button" class="btn btn-ghost btn-sm mt10" data-dlg="fu-dlg">Set follow-up</button><?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="card" id="stats">
    <h2 class="lead-card-h"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 17h14M5 14V9M9 14V5M13 14v-3M17 14V7"/></svg> Lead statistics</h2>
    <div class="lead-stats">
      <div class="ls"><span>Activities</span><b><?= (int) $st['acts'] ?></b></div>
      <div class="ls ls-good"><span>Positive</span><b><?= (int) $st['good'] ?></b></div>
      <div class="ls ls-bad"><span>Negative</span><b><?= (int) $st['bad'] ?></b></div>
      <div class="ls ls-blue"><span>Visits</span><b><?= (int) $st['visits'] ?></b></div>
    </div>
    <dl class="lead-dl lead-dl-stats">
      <dt>Last contact</dt><dd><?= e($ago($st['last'])) ?></dd>
      <dt>Lead age</dt><dd><?= $age !== null ? $age . ' day' . ($age === 1 ? '' : 's') : '—' ?></dd>
      <dt>Messages</dt><dd><?= (int) $st['msg_in'] ?> in · <?= (int) $st['msg_out'] ?> out</dd>
      <?php if ($score !== null): ?><dt>Heat score</dt><dd><?= (int) $score ?></dd><?php endif; ?>
    </dl>
  </div>

  <?php if ($hideBefore === null): ?>
  <details class="card lead-fold">
    <summary><h2 class="lead-card-h">Stage history</h2></summary>
    <?php if (!$stageMoves): ?><p class="text-muted" style="font-size:13px">No moves yet.</p><?php endif; ?>
    <?php foreach ($stageMoves as $m_): ?>
      <div class="fold-row"><span><?= $m_['kind'] === 'added' ? 'Added in ' . e((string) ($stageMap[(int) $m_['to_val']]['name'] ?? '—'))
            : e((string) ($stageMap[(int) $m_['from_val']]['name'] ?? '—')) . ' → ' . e((string) ($stageMap[(int) $m_['to_val']]['name'] ?? '—')) ?></span>
        <span class="text-muted"><?= e($who($m_['user_id'])) ?> · <?= e(date('j M, H:i', strtotime((string) $m_['created_at']))) ?></span></div>
    <?php endforeach; ?>
  </details>

  <details class="card lead-fold" id="transfers">
    <summary><h2 class="lead-card-h">⇄ Transfer history</h2></summary>
    <?php if (!$moves): ?><p class="text-muted" style="font-size:13px">Never assigned.</p><?php endif; ?>
    <?php foreach ($moves as $m_): $un_ = fn($v_) => $v_ !== null ? ($names[(int) $v_] ?? 'someone') : 'nobody'; ?>
      <div class="fold-row"><span><?= $m_['kind'] === 'reclaimed' ? 'Not contacted in time: ' . e($un_($m_['from_val'])) . ' → ' . e($un_($m_['to_val']))
            : ($m_['from_val'] === null ? 'Assigned to ' . e($un_($m_['to_val'])) : e($un_($m_['from_val'])) . ' → ' . e($un_($m_['to_val']))) ?></span>
        <span class="text-muted"><?= e($who($m_['user_id'])) ?> · <?= e(date('j M, H:i', strtotime((string) $m_['created_at']))) ?></span></div>
    <?php endforeach; ?>
  </details>
  <?php endif; ?>

  <?php if ($myEvents): ?>
  <details class="card lead-fold" open>
    <summary><h2 class="lead-card-h">Events</h2></summary>
    <?php foreach ($myEvents as $ev_): ?>
      <div class="fold-row"><a href="crm_events.php?id=<?= (int) $ev_['id'] ?>"><?= e((string) $ev_['name']) ?></a>
        <span class="text-muted"><?= e(date('j M', strtotime((string) $ev_['starts_at']))) ?> · <?= e(sev_statuses()[$ev_['status']] ?? $ev_['status']) ?></span></div>
    <?php endforeach; ?>
  </details>
  <?php endif; ?>

  <?php if ($runs && $isAdmin): ?>
  <!-- Automatic follow-ups this lead is in — a manager's view; salespeople do not see or stop them -->
  <details class="card lead-fold" id="seq-runs" open>
    <summary><h2 class="lead-card-h">Automatic follow-up</h2></summary>
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
  </details>
  <?php endif; ?>

  <?php if ($dups): ?>
  <!-- Probably the same person -->
  <details class="card lead-fold" id="dups" open>
    <summary><h2 class="lead-card-h">Possible duplicates</h2></summary>
    <p class="text-muted" style="font-size:12.5px">Same name, email, or the same number written another way.
      <?= $isAdmin ? 'Merging moves their messages, notes and answers onto this lead and removes the other one.' : 'An Admin can merge them.' ?></p>
    <?php foreach ($dups as $d): ?>
      <div class="dup-row">
        <div><a href="crm_lead.php?id=<?= (int) $d['id'] ?>"><strong><?= e((string) ($d['name'] ?: crm_phone_show((string) $d['phone_e164']))) ?></strong></a>
          <span class="text-muted" style="display:block;font-size:12px"><?= e(crm_phone_show((string) $d['phone_e164'])) ?><?= $d['email'] ? ' · ' . e((string) $d['email']) : '' ?>
            · <?= e($d['stage_name'] ?: 'Not in the pipeline') ?> · <?= e(crm_user_name($d['owner_user_id'] !== null ? (int) $d['owner_user_id'] : null)) ?></span></div>
        <?php if ($isAdmin): ?>
        <form method="post" onsubmit="return confirm('Merge this contact into the lead you are looking at? The other one will be removed.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="merge"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="other" value="<?= (int) $d['id'] ?>">
          <button class="btn btn-ghost btn-sm">Merge into this lead</button></form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </details>
  <?php endif; ?>

  <?php if ($attrs): ?>
  <details class="card lead-fold">
    <summary><h2 class="lead-card-h">Form answers</h2></summary>
    <dl class="lead-dl lead-dl-stats">
      <?php foreach ($attrs as $k => $v): ?><dt><?= e((string) $k) ?></dt><dd><?= e(is_scalar($v) ? (string) $v : json_encode($v)) ?></dd><?php endforeach; ?>
    </dl>
  </details>
  <?php endif; ?>
</aside>
</div>

<!-- ═══════════ Dialogs the action buttons open ═══════════ -->
<?php if ($canW): ?>
<dialog class="lead-dlg" id="act-dlg" aria-labelledby="act-title">
  <form method="post" id="act-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="activity"><input type="hidden" name="id" value="<?= $id ?>">
    <h2 id="act-title" style="margin-top:0">New activity</h2>
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
    <div class="dlg-btns"><button class="btn btn-primary">Save activity</button>
      <button type="button" class="btn btn-ghost" data-close>Cancel</button></div>
  </form>
</dialog>

<dialog class="lead-dlg" id="fu-dlg" aria-labelledby="fu-title">
  <form method="post" id="fu-edit">
    <?= csrf_field() ?><input type="hidden" name="action" value="followup"><input type="hidden" name="id" value="<?= $id ?>">
    <h2 id="fu-title" style="margin-top:0">Next follow-up</h2>
    <div class="lead-quick" data-for="fu-at"></div>
    <div class="grid2">
      <div class="field"><span class="lbl">Date and time</span><input type="datetime-local" name="next_at" id="fu-at" required></div>
      <div class="field"><span class="lbl">What for</span><input type="text" name="next_note" maxlength="255" placeholder="Send the payment plan"></div>
    </div>
    <div class="dlg-btns"><button class="btn btn-primary">Set follow-up</button>
      <button type="button" class="btn btn-ghost" data-close>Cancel</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($canW && can_use('inbox')): ?>
<dialog class="lead-dlg lead-send" id="send-dlg" aria-labelledby="send-title">
  <h2 id="send-title" style="margin-top:0">Send on WhatsApp</h2>
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
      <textarea name="message" id="send-text" rows="4" placeholder="Message on WhatsApp…" required></textarea>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap" class="mt10">
        <button class="btn btn-primary btn-sm">Send on WhatsApp</button>
        <?php if ($aiOn): ?><button type="button" class="btn btn-ghost btn-sm" id="ai-suggest" title="Write a reply from the conversation — check it before sending">Suggest a reply</button><?php endif; ?>
        <span class="text-muted" style="font-size:12px">Goes out from <?= e($viaText) ?></span>
      </div>
    </form>
  <?php endif; ?>
  <div class="dlg-btns"><button type="button" class="btn btn-ghost" data-close>Close</button></div>
</dialog>
<?php endif; ?>

<?php if ($canW && !empty($lead['phone_e164']) && crm_sms_available($CLIENT)): require_once __DIR__ . '/../includes/sms.php'; ?>
<dialog class="lead-dlg lead-send" id="sms-dlg" aria-labelledby="sms-title" <?= $err && ($_POST['action'] ?? '') === 'send_sms' ? 'open' : '' ?>>
  <h2 id="sms-title" style="margin-top:0">Send SMS</h2>
  <?php if (!empty($lead['sms_opt_out_at'])): ?>
    <div class="note warn">This person asked not to receive SMS (since <?= e(date('j M Y', strtotime((string) $lead['sms_opt_out_at']))) ?>).</div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sms_optout"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-ghost btn-sm">Allow SMS again</button></form>
  <?php else: ?>
  <form method="post" id="lead-sms">
    <?= csrf_field() ?><input type="hidden" name="action" value="send_sms"><input type="hidden" name="id" value="<?= $id ?>">
    <textarea name="message" id="sms-msg" rows="4" maxlength="1530" required placeholder="SMS to <?= e((string) ($lead['name'] ?: 'this lead')) ?>… — {{first_name}}, {{owner_name}} work here too"></textarea>
    <div class="sms-counter"><span><b id="lsms-n">0</b> characters</span><span><b id="lsms-p">1</b> part(s)</span><span><?= sms_rate($CLIENT) ?> credit(s) per part</span></div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap" class="mt10"><button class="btn btn-primary btn-sm">Send SMS</button>
      <span class="text-muted" style="font-size:12px">To +<?= e(crm_phone_show((string) $lead['phone_e164'])) ?><?php $snds = sms_client_senders($CLIENT); ?><?= $snds ? ' from ' . e($snds[0]) : '' ?></span></div>
  </form>
  <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="sms_optout"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="out" value="1">
    <button class="btn-link" style="font-size:12px">They asked not to get SMS</button></form>
  <script>(function(){var t=document.getElementById('sms-msg');t.addEventListener('input',function(){var u=/[^\x00-\x7F€£¥èéùìòÇØøÅåÄÖÑÜäöñüà§¿¡ΔΦΓΛΩΠΨΣΘΞÆæßÉ]/.test(t.value),n=Array.from(t.value).length,one=u?70:160,many=u?67:153;
    document.getElementById('lsms-n').textContent=n;document.getElementById('lsms-p').textContent=n<=one?1:Math.ceil(n/many);});})();</script>
  <?php endif; ?>
  <div class="dlg-btns"><button type="button" class="btn btn-ghost" data-close>Close</button></div>
</dialog>
<?php endif; ?>

<dialog class="lead-dlg lead-details" id="edit-dlg" aria-labelledby="edit-title" <?= $err && ($_POST['action'] ?? '') === 'save' ? 'open' : '' ?>>
  <h2 id="edit-title" style="margin-top:0">Edit lead</h2>
  <form method="post">
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
          <input value="<?= e($ownerName) ?>" disabled>
        <?php endif; ?></div>
    </div>
    <div class="dlg-btns"><?php if ($canW): ?><button class="btn btn-primary btn-sm">Save details</button><?php endif; ?>
      <button type="button" class="btn btn-ghost btn-sm" data-close>Close</button></div>
    </fieldset>
  </form>
</dialog>

<?php if ($canTransfer && $lead['stage_id'] !== null): ?>
<dialog class="lead-dlg" id="tr-dlg" aria-labelledby="tr-title">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="transfer"><input type="hidden" name="id" value="<?= $id ?>">
    <h2 id="tr-title" style="margin-top:0">Transfer lead</h2>
    <p class="text-muted" style="font-size:13px">Now with <strong><?= e($ownerName) ?></strong>. The move is recorded in the transfer history.</p>
    <div class="field"><span class="lbl">Transfer to</span><select name="to" required>
      <?php if ($isAdmin): ?><option value="auto">Next in rotation (assignment rules)</option><option value="none">Nobody (unassigned)</option><?php endif; ?>
      <?php foreach ($transferTo as $u): if ((int) $u['id'] === (int) $lead['owner_user_id']) continue; ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?>
    </select></div>
    <div class="grid2">
      <div class="field"><span class="lbl">Lead type after transfer</span><select name="data_type"><option value="">Keep the current type</option>
        <?php foreach (crm_data_types() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><span class="lbl">History visibility</span><select name="history" id="trl-history">
        <?php require_once __DIR__ . '/../includes/crm_transfer.php'; foreach (crm_transfer_policies() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><span class="lbl">Reason <span class="text-muted">(optional)</span></span><input name="reason" maxlength="255"></div>
    <div class="field"><span class="lbl">Notes <span class="text-muted">(optional)</span></span><textarea name="notes" rows="2" maxlength="1000"></textarea></div>
    <label class="mod-all"><input type="checkbox" name="keep_followup" value="1"> Keep the follow-up date</label>
    <label class="mod-all"><input type="checkbox" name="fresh_start" value="1" id="trl-fresh" disabled> Start again as a new lead (needs the history hidden)</label>
    <label class="mod-all"><input type="checkbox" name="show_status" value="1"> Show the last status when the history is hidden</label>
    <script>document.getElementById('trl-history').addEventListener('change', e => { const f = document.getElementById('trl-fresh'); f.disabled = !e.target.value; if (f.disabled) f.checked = false; });</script>
    <div class="dlg-btns"><button class="btn btn-primary">Transfer</button><button type="button" class="btn btn-ghost" data-close>Cancel</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($openEvents && $canW): ?>
<dialog class="lead-dlg" id="ev-dlg" aria-labelledby="evd-title">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="event_add"><input type="hidden" name="id" value="<?= $id ?>">
    <h2 id="evd-title" style="margin-top:0">Add to an event</h2>
    <div class="field"><span class="lbl">Event</span><select name="event_id" required>
      <?php foreach ($openEvents as $oe): ?><option value="<?= (int) $oe['id'] ?>"><?= e($oe['name'] . ' — ' . date('j M', strtotime((string) $oe['starts_at']))) ?></option><?php endforeach; ?></select></div>
    <p class="text-muted" style="font-size:12.5px">The invitation is sent from the event's page.</p>
    <div class="dlg-btns"><button class="btn btn-primary">Add</button><button type="button" class="btn btn-ghost" data-close>Cancel</button></div>
  </form>
</dialog>
<?php endif; ?>

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
/* The action buttons: each opens its dialog; the two booking buttons open the form below the meetings card. */
(function(){
  document.querySelectorAll('[data-dlg]').forEach(b => b.addEventListener('click', () => {
    const d = document.getElementById(b.dataset.dlg); if (!d) return;
    if (b.dataset.kind) { const r = d.querySelector('input[name=kind][value="' + b.dataset.kind + '"]'); if (r) { r.checked = true; r.dispatchEvent(new Event('change')); } }
    d.showModal(); const f = d.querySelector('textarea, input:not([type=hidden]):not([type=radio]), select'); if (f && b.dataset.kind === 'note') f.focus();
  }));
  // The heat reasons close like any pop-up: Escape, or a click elsewhere.
  const heat = document.querySelector('.lead-heat');
  if (heat) {
    document.addEventListener('click', e => { if (heat.open && !heat.contains(e.target)) heat.open = false; });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') heat.open = false; });
  }
  document.querySelectorAll('.lead-dlg [data-close]').forEach(b => b.addEventListener('click', () => b.closest('dialog').close()));
  document.querySelectorAll('.lead-dlg').forEach(d => d.addEventListener('mousedown', e => { if (e.target === d) d.close(); }));
  document.querySelectorAll('[data-book]').forEach(b => b.addEventListener('click', () => {
    const f = document.getElementById('visit-form'); if (!f) return;
    f.hidden = false;
    const r = f.querySelector('input[name=kind][value="' + b.dataset.book + '"]'); r.checked = true; visitKind();
    f.scrollIntoView({behavior: 'smooth', block: 'center'});
  }));
})();
function visitKind(){
  const on = document.querySelector('#visit-form input[name=kind][value=online]').checked;
  document.getElementById('visit-where').hidden = on; document.getElementById('visit-meet').hidden = !on;
  document.getElementById('visit-go').textContent = on ? 'Book meeting' : 'Book visit';
}
</script>
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
  /* A hidden number: pressing Call or WhatsApp opens it — and the opening is recorded. */
  document.querySelectorAll('[data-reveal]').forEach(b => b.addEventListener('click', async () => {
    const fd = new FormData(); fd.append('csrf_token', <?= json_encode(csrf_token()) ?>); fd.append('action', 'reveal'); fd.append('how', b.dataset.reveal); fd.append('id', '<?= $id ?>');
    const d = await fetch('crm_lead.php?id=<?= $id ?>', {method:'POST', body:fd}).then(r => r.json()).catch(() => ({}));
    if (!d.phone) { alert(d.error || 'Could not open the number.'); return; }
    if (b.dataset.reveal === 'whatsapp') window.open('https://wa.me/' + d.phone, '_blank', 'noopener'); else location.href = 'tel:+' + d.phone;
  }));
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
  document.querySelectorAll('.lead-quick[data-for]').forEach(box => {
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
