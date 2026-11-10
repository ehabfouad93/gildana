<?php
declare(strict_types=1);

/**
 * What people did after a message — the replies and button taps that answer a campaign or an
 * automation step (table reply_events). Read by the campaign report, the automation report and
 * the Inbox's "From" filter.
 *
 * Matching an answer to what it answers:
 *   - WhatsApp says so: a tapped button or a quoted reply carries the id of the message it answers
 *     (context.id) — matched to the campaign message or the automation message with that id.
 *   - Otherwise it answers the last thing we sent: when the newest outgoing message to this person
 *     is a campaign's, sent in the last 3 days, the answer is the campaign's. Once a person or a
 *     bot has written after the campaign, later messages belong to that conversation instead.
 *   - Inside an automation, the engine records the answer on the step that was waiting for it
 *     (reply_record_step, from automation_handle_inbound).
 *
 * WhatsApp does not report taps on website or call buttons — only quick replies come back.
 */

function replies_ready(): bool
{
    static $ok = null;
    if ($ok === null) { try { db_val("SELECT 1 FROM reply_events LIMIT 1"); $ok = true; } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

/** 'button' for a tapped button, 'stop' for an opt-out word, else 'reply'. */
function reply_kind(string $type, string $text): string
{
    if (preg_match('/^(STOP|UNSUBSCRIBE|إلغاء|الغاء|توقف)\b/u', mb_strtoupper(trim($text)))) return 'stop';
    return in_array($type, ['button', 'interactive'], true) ? 'button' : 'reply';
}

/**
 * An inbound message has just been stored: note which campaign it answers, if any.
 * $contextWamid: WhatsApp's id of the message being answered, when it gives one.
 */
function reply_track(int $clientId, int $contactId, int $messageId, string $type, string $text, string $contextWamid = ''): void
{
    if (!replies_ready() || $contactId <= 0) return;
    try {
        $cm = null;
        if ($contextWamid !== '') {
            $cm = db_row("SELECT id, campaign_id FROM campaign_messages WHERE wa_message_id=? AND client_id=? LIMIT 1", [$contextWamid, $clientId]);
        }
        if (!$cm) {
            $last = db_row("SELECT source, source_ref_id, created_at FROM messages WHERE client_id=? AND contact_id=? AND direction='out' AND COALESCE(status,'')<>'failed'
                             ORDER BY id DESC LIMIT 1", [$clientId, $contactId]);
            if ($last && $last['source'] === 'campaign' && (int) $last['source_ref_id'] > 0 && strtotime((string) $last['created_at']) > time() - 3 * 86400) {
                $cm = db_row("SELECT id, campaign_id FROM campaign_messages WHERE id=? AND client_id=?", [(int) $last['source_ref_id'], $clientId]);
            }
        }
        if (!$cm) return;
        db_run("INSERT INTO reply_events (client_id, contact_id, message_id, kind, body, campaign_id, campaign_message_id, created_at) VALUES (?,?,?,?,?,?,?,NOW())",
               [$clientId, $contactId, $messageId ?: null, reply_kind($type, $text), mb_substr($text !== '' ? $text : '[' . $type . ']', 0, 500), (int) $cm['campaign_id'], (int) $cm['id']]);
    } catch (Throwable $e) { error_log('reply_track: ' . $e->getMessage()); }
}

/** The automation was waiting on this step and the person answered it. */
function reply_record_step(array $run, int $stepId, string $text, bool $button): void
{
    if (!replies_ready()) return;
    try {
        db_run("INSERT INTO reply_events (client_id, contact_id, kind, body, flow_id, run_id, step_id, created_at) VALUES (?,?,?,?,?,?,?,NOW())",
               [(int) $run['client_id'], (int) $run['contact_id'], $button ? 'button' : 'reply', mb_substr($text, 0, 500), (int) $run['flow_id'], (int) $run['id'], $stepId]);
    } catch (Throwable $e) { error_log('reply_record_step: ' . $e->getMessage()); }
}
