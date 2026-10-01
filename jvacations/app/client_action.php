<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Every pipeline hand-off goes through here. Each action checks both the role
 * and the stage, so a client can only move forward from the step it is on.
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

/** Meeting date/time/sales from the form, validated. */
function meeting_input(string $back): array
{
    $date = valid_date($_POST['meeting_date'] ?? '');
    $time = valid_time($_POST['meeting_time'] ?? '');
    $sid  = (int) ($_POST['sales_id'] ?? 0);
    if (!$date || !$time) fail(t('err.meeting_dt'), $back);
    if ($sid && !db_val("SELECT 1 FROM users WHERE id = ? AND role = 'sales' AND status = 'active'", [$sid])) {
        fail(t('err.bad_sales'), $back);
    }
    return [$date, $time . ':00', $sid ?: null, mb_substr(post_str('meeting_place'), 0, 150) ?: null];
}

switch ($action) {
    case 'book': // booker: new → booked (or re-book a booked client)
        if (!has_role('booker')) fail(t('err.forbidden'), $back);
        if (!in_array($c['stage'], ['new', 'booked'], true)) fail(t('err.wrong_stage'), $back);
        [$date, $time, $sid, $place] = meeting_input($back);
        db_run("UPDATE clients SET stage='booked', booker_id=?, booked_at=NOW(), meeting_date=?, meeting_time=?, meeting_place=?, sales_id=?, updated_at=NOW() WHERE id=?",
            [$ME['id'], $date, $time, $place, $sid, $id]);
        log_event($id, $c['stage'] === 'new' ? 'booked' : 'rebooked', trim(fmt_date($date) . ' ' . fmt_time($time) . ($note !== '' ? ' — ' . $note : '')));
        flash(t('flash.booked'));
        redirect($ME['role'] === 'booker' ? 'clients.php' : $back);

    case 'confirm': // communicator: booked → confirmed (may adjust the time while on the phone)
        if (!has_role('communicator')) fail(t('err.forbidden'), $back);
        if (!in_array($c['stage'], ['booked', 'confirmed'], true)) fail(t('err.wrong_stage'), $back);
        [$date, $time, $sid, $place] = meeting_input($back);
        $sid = $sid ?? ($c['sales_id'] ? (int) $c['sales_id'] : null);
        if (!$sid) fail(t('err.need_sales'), $back);
        db_run("UPDATE clients SET stage='confirmed', communicator_id=?, confirmed_at=NOW(), meeting_date=?, meeting_time=?, meeting_place=?, sales_id=?, updated_at=NOW() WHERE id=?",
            [$ME['id'], $date, $time, $place ?? $c['meeting_place'], $sid, $id]);
        log_event($id, 'confirmed', trim(fmt_date($date) . ' ' . fmt_time($time) . ($note !== '' ? ' — ' . $note : '')));
        flash(t('flash.confirmed'));
        redirect($ME['role'] === 'communicator' ? 'clients.php' : $back);

    case 'no_answer': // communicator: logged attempt, stays in the queue
        if (!has_role('communicator')) fail(t('err.forbidden'), $back);
        log_event($id, 'no_answer', $note);
        flash(t('flash.logged'));
        redirect($back);

    case 'rebook': // communicator: client wants another date → back to the booker
        if (!has_role('communicator')) fail(t('err.forbidden'), $back);
        if (!in_array($c['stage'], ['booked', 'confirmed'], true)) fail(t('err.wrong_stage'), $back);
        db_run("UPDATE clients SET stage='new', communicator_id=?, updated_at=NOW() WHERE id=?", [$ME['id'], $id]);
        log_event($id, 'sent_back', $note);
        flash(t('flash.sent_back'));
        redirect($ME['role'] === 'communicator' ? 'clients.php' : $back);

    case 'cancel': // communicator: client is not coming
        if (!has_role('communicator')) fail(t('err.forbidden'), $back);
        if (!in_array($c['stage'], ['new', 'booked', 'confirmed'], true)) fail(t('err.wrong_stage'), $back);
        db_run("UPDATE clients SET stage='cancelled', communicator_id=?, closed_at=NOW(), lost_reason=?, updated_at=NOW() WHERE id=?",
            [$ME['id'], $note ?: null, $id]);
        log_event($id, 'cancelled', $note);
        flash(t('flash.cancelled'));
        redirect($back);

    case 'lost': // sales: the meeting did not close
        if (!has_role('sales') || (!$admin && (int) $c['sales_id'] !== $ME['id'])) fail(t('err.forbidden'), $back);
        if ($c['stage'] !== 'confirmed') fail(t('err.wrong_stage'), $back);
        if ($note === '') fail(t('err.need_reason'), $back);
        db_run("UPDATE clients SET stage='lost', closed_at=NOW(), lost_reason=?, updated_at=NOW() WHERE id=?", [mb_substr($note, 0, 255), $id]);
        log_event($id, 'lost', $note);
        flash(t('flash.lost'));
        redirect($back);

    case 'reopen': // admin: give a lost/cancelled client another try
        if (!$admin) fail(t('err.forbidden'), $back);
        if (!in_array($c['stage'], ['lost', 'cancelled'], true)) fail(t('err.wrong_stage'), $back);
        db_run("UPDATE clients SET stage='new', closed_at=NULL, lost_reason=NULL, updated_at=NOW() WHERE id=?", [$id]);
        log_event($id, 'reopened', $note);
        flash(t('ui.saved'));
        redirect($back);

    case 'note': // anyone who can see the client
        if ($note === '') fail(t('err.empty_note'), $back);
        log_event($id, 'note', $note);
        flash(t('flash.logged'));
        redirect($back . '#timeline');
}

fail(t('err.unknown_action'), $back);
