<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Every pipeline hand-off goes through here. Each action checks both the
 * capability and the stage, so a client can only move forward from the step it
 * is on. After a hand-off is saved, the matching WhatsApp automations fire.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('clients.php');
verify_csrf();

$id     = (int) ($_POST['id'] ?? 0);
$c      = client_or_403($id, $ME);
$action = (string) ($_POST['action'] ?? '');
$note   = post_str('note');
$back   = 'client.php?id=' . $id;
$admin  = $ME['role'] === 'admin';

function fail(string $msg, string $back): void
{
    flash($msg, 'error');
    redirect($back);
}

/** A sales rep id from the form: null when none chosen; must be an active sales account. */
function sales_input(string $back, bool $required = false): ?int
{
    $sid = (int) ($_POST['sales_id'] ?? 0);
    if (!$sid) {
        if ($required) fail(t('err.need_sales'), $back);
        return null;
    }
    if (!db_val("SELECT 1 FROM users WHERE id = ? AND role = 'sales' AND status = 'active'", [$sid])) fail(t('err.bad_sales'), $back);
    return $sid;
}

/** Meeting date/time/place from the form, validated. */
function meeting_input(string $back): array
{
    $date = valid_date($_POST['meeting_date'] ?? '');
    $time = valid_time($_POST['meeting_time'] ?? '');
    if (!$date || !$time) fail(t('err.meeting_dt'), $back);
    return [$date, $time . ':00', mb_substr(post_str('meeting_place'), 0, 150) ?: null];
}

/** After a hand-off: go back to the queue if this user can no longer see the client. */
function done(int $id, string $back): void
{
    $c = load_client($id);
    redirect($c && can_view_client(current_user(), $c) ? $back : 'clients.php');
}

switch ($action) {
    case 'book': // booker: new → booked (or re-book a booked client). Sales rep optional.
        require_cap('clients.book');
        if (!in_array($c['stage'], ['new', 'booked'], true)) fail(t('err.wrong_stage'), $back);
        [$date, $time, $place] = meeting_input($back);
        $sid = sales_input($back);
        db_run("UPDATE clients SET stage='booked', booker_id=?, booked_at=NOW(), meeting_date=?, meeting_time=?, meeting_place=?, sales_id=?, updated_at=NOW() WHERE id=?",
            [$ME['id'], $date, $time, $place, $sid, $id]);
        log_event($id, $c['stage'] === 'new' ? 'booked' : 'rebooked', trim(fmt_date($date) . ' ' . fmt_time($time) . ($note !== '' ? ' — ' . $note : '')));
        flash(t('flash.booked'));
        wa_fire('booked', $id);
        done($id, $back);

    case 'confirm': // communicator: booked → confirmed. Sales rep optional — the sales manager assigns on arrival.
        require_cap('clients.confirm');
        if (!in_array($c['stage'], ['booked', 'confirmed'], true)) fail(t('err.wrong_stage'), $back);
        [$date, $time, $place] = meeting_input($back);
        $sid = sales_input($back);
        db_run("UPDATE clients SET stage='confirmed', communicator_id=?, confirmed_at=NOW(), meeting_date=?, meeting_time=?, meeting_place=?, sales_id=?, updated_at=NOW() WHERE id=?",
            [$ME['id'], $date, $time, $place ?? $c['meeting_place'], $sid, $id]);
        log_event($id, 'confirmed', trim(fmt_date($date) . ' ' . fmt_time($time) . ($note !== '' ? ' — ' . $note : '')));
        flash(t('flash.confirmed'));
        wa_fire('confirmed', $id);
        done($id, $back);

    case 'no_answer': // communicator: logged attempt, stays in the queue
        require_cap('clients.confirm');
        log_event($id, 'no_answer', $note);
        flash(t('flash.logged'));
        redirect($back);

    case 'rebook': // communicator / sales manager: client needs another date → back to the booker
        require_cap('clients.confirm', 'clients.arrive');
        if (!in_array($c['stage'], ['booked', 'confirmed'], true)) fail(t('err.wrong_stage'), $back);
        $isManager = !can('clients.confirm') || ($_POST['as'] ?? '') === 'manager';
        db_run("UPDATE clients SET stage='new', " . ($isManager ? 'manager_id' : 'communicator_id') . "=?, updated_at=NOW() WHERE id=?", [$ME['id'], $id]);
        log_event($id, $isManager ? 'no_show' : 'sent_back', $note);
        flash(t('flash.sent_back'));
        wa_fire('sent_back', $id);
        done($id, $back);

    case 'cancel': // communicator: client is not coming
        require_cap('clients.confirm', 'clients.arrive');
        if (!in_array($c['stage'], ['new', 'booked', 'confirmed'], true)) fail(t('err.wrong_stage'), $back);
        db_run("UPDATE clients SET stage='cancelled', communicator_id=COALESCE(communicator_id, ?), closed_at=NOW(), lost_reason=?, updated_at=NOW() WHERE id=?",
            [$ME['id'], $note ?: null, $id]);
        log_event($id, 'cancelled', $note);
        flash(t('flash.cancelled'));
        wa_fire('cancelled', $id);
        done($id, $back);

    case 'arrive': // sales manager: the client is at the office → hand to a sales rep
        require_cap('clients.arrive');
        if (!in_array($c['stage'], ['booked', 'confirmed'], true)) fail(t('err.wrong_stage'), $back);
        $sid = sales_input($back, true);
        db_run("UPDATE clients SET stage='arrived', manager_id=?, arrived_at=NOW(), sales_id=?, updated_at=NOW() WHERE id=?", [$ME['id'], $sid, $id]);
        $rep = (string) db_val("SELECT name FROM users WHERE id = ?", [$sid]);
        log_event($id, 'arrived', $rep . ($note !== '' ? ' — ' . $note : ''));
        flash(t('flash.arrived', ['name' => $rep]));
        wa_fire('arrived', $id);
        done($id, $back);

    case 'assign': // sales manager: change the rep on a client already at the office
        require_cap('clients.arrive');
        if ($c['stage'] !== 'arrived') fail(t('err.wrong_stage'), $back);
        $sid = sales_input($back, true);
        db_run("UPDATE clients SET sales_id=?, manager_id=?, updated_at=NOW() WHERE id=?", [$sid, $ME['id'], $id]);
        log_event($id, 'sales_assigned', (string) db_val("SELECT name FROM users WHERE id = ?", [$sid]) . ($note !== '' ? ' — ' . $note : ''));
        flash(t('ui.saved'));
        done($id, $back);

    case 'lost': // sales: the meeting did not close
        require_cap('clients.close');
        if (!$admin && (int) $c['sales_id'] !== $ME['id']) fail(t('err.forbidden'), $back);
        if ($c['stage'] !== 'arrived') fail(t('err.wrong_stage'), $back);
        if ($note === '') fail(t('err.need_reason'), $back);
        db_run("UPDATE clients SET stage='lost', closed_at=NOW(), lost_reason=?, updated_at=NOW() WHERE id=?", [mb_substr($note, 0, 255), $id]);
        log_event($id, 'lost', $note);
        flash(t('flash.lost'));
        wa_fire('lost', $id);
        redirect($back);

    case 'reopen': // admin: give a lost/cancelled client another try
        if (!$admin) fail(t('err.forbidden'), $back);
        if (!in_array($c['stage'], ['lost', 'cancelled'], true)) fail(t('err.wrong_stage'), $back);
        db_run("UPDATE clients SET stage='new', closed_at=NULL, lost_reason=NULL, updated_at=NOW() WHERE id=?", [$id]);
        log_event($id, 'reopened', $note);
        flash(t('ui.saved'));
        redirect($back);

    case 'wa_send': // send one of the configured WhatsApp messages by hand
        require_cap('whatsapp.send');
        $auto = db_row("SELECT * FROM wa_automations WHERE id = ?", [(int) ($_POST['automation_id'] ?? 0)]);
        if (!$auto) fail(t('wa.pick'), $back);
        [$ok, $res] = wa_send_to_client($id, $auto, [], 'manual');
        log_event($id, 'whatsapp', $auto['template_name'] . ($ok ? '' : ' — ' . $res));
        flash($ok ? t('wa.flash_sent', ['n' => '1']) : t('wa.send_failed', ['err' => (string) $res]), $ok ? 'success' : 'error');
        redirect($back . '#timeline');

    case 'note': // anyone who can see the client
        if ($note === '') fail(t('err.empty_note'), $back);
        log_event($id, 'note', $note);
        flash(t('flash.logged'));
        redirect($back . '#timeline');
}

fail(t('err.unknown_action'), $back);
