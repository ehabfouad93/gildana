<?php
/**
 * Why did a campaign's follow-up automation not start for this person? Read-only.
 *
 *   docker exec revenect php deploy/docker/followup-check.php 201001234567
 *
 * Follows the chain for that phone number: the campaign message it got, the follow-up set on
 * the campaign, the button they tapped, whether that tap was matched to the campaign, what the
 * automation engine decided, and the automation runs — and says at which link it broke.
 * Changes nothing.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }

$root = dirname(__DIR__, 2);
chdir($root);
require "$root/includes/config_loader.php";
require "$root/includes/helpers.php";
require "$root/includes/crypto.php";
require "$root/includes/db.php";

$phone = preg_replace('/\D+/', '', (string) ($argv[1] ?? ''));
if (strlen($phone) < 6) exit("Give the phone number that tapped the button, e.g.  php deploy/docker/followup-check.php 201001234567\n");

$say  = fn(string $s) => print($s . "\n");
$bad  = fn(string $s) => print("  ✗ " . $s . "\n");
$good = fn(string $s) => print("  ✓ " . $s . "\n");
$has  = function (string $table, string $col = ''): bool {
    try { return $col === '' ? (bool) db_val("SHOW TABLES LIKE " . db()->quote($table)) : db_has_column($table, $col); } catch (Throwable $e) { return false; }
};

$say("Follow-up check for +$phone\n");
$say("Database:");
$need = ['reply_events' => '072', 'campaigns.follow_button' => '073', 'campaigns.follow_buttons' => '074'];
$ok = true;
foreach ($need as $what => $mig) {
    $okThis = str_contains($what, '.') ? $has(...explode('.', $what)) : $has($what);
    $okThis ? $good("$what (migration $mig)") : $bad("$what is missing — run migrate.php (migration $mig)");
    $ok = $ok && $okThis;
}
if (!$ok) exit("\nDeploy and migrate first, then try again.\n");

$contacts = db_all("SELECT * FROM contacts WHERE phone_e164 LIKE ? ORDER BY id DESC LIMIT 5", ['%' . substr($phone, -9)]);
if (!$contacts) exit("\nNo contact with a number ending in " . substr($phone, -9) . ".\n");

foreach ($contacts as $ct) {
    $cid = (int) $ct['client_id'];
    $cl  = db_row("SELECT id, name FROM clients WHERE id=?", [$cid]);
    $say("\n=== " . ($ct['name'] ?: 'Contact') . " (+{$ct['phone_e164']}) — account: " . ($cl['name'] ?? $cid) . " ===");
    if (($ct['opt_in_status'] ?? '') === 'out') $bad("This contact is opted out (no automation is ever started for them).");
    if (!empty($ct['bot_paused_until']) && strtotime((string) $ct['bot_paused_until']) > time()) $say("  • Bots paused for this contact until {$ct['bot_paused_until']} (someone wrote from the Inbox).");

    // 1. The campaign message
    $cm = db_row("SELECT cm.*, c.name camp_name, c.follow_flow_id, c.follow_trigger, c.follow_button, c.follow_buttons, c.follow_delay_minutes, c.follow_audience, c.template_id
                    FROM campaign_messages cm JOIN campaigns c ON c.id=cm.campaign_id
                   WHERE cm.contact_id=? ORDER BY cm.id DESC LIMIT 1", [(int) $ct['id']]);
    if (!$cm) { $bad("No campaign message was sent to this contact."); continue; }
    $say("\n1. Latest campaign: \"{$cm['camp_name']}\" (id {$cm['campaign_id']}) — status {$cm['status']}, sent {$cm['sent_at']}");
    $cm['wa_message_id'] ? $good("WhatsApp id of the message: {$cm['wa_message_id']}") : $bad("The message has no WhatsApp id, so a button tap cannot be matched to it.");

    // 2. The follow-up set on it
    $map = json_decode((string) ($cm['follow_buttons'] ?? ''), true) ?: [];
    $say("\n2. Follow-up on the campaign:");
    if (!$cm['follow_flow_id']) { $bad("This campaign has no follow-up automation (\"Do nothing\" was chosen)."); continue; }
    $say("  • Starts: {$cm['follow_trigger']}" . ((int) $cm['follow_delay_minutes'] ? " after {$cm['follow_delay_minutes']} min" : '') . " · who: {$cm['follow_audience']}");
    $rules = $map ?: [($cm['follow_trigger'] === 'button' ? ((string) $cm['follow_button'] ?: '(any button)') : '(any reply)') => (int) $cm['follow_flow_id']];
    $tplBtns = [];
    if ($cm['template_id']) {
        $comp = json_decode((string) db_val("SELECT components FROM templates WHERE id=?", [(int) $cm['template_id']]), true) ?: [];
        $walk = function ($cs) use (&$walk, &$tplBtns) { foreach ((array) $cs as $c) {
            if (strtoupper((string) ($c['type'] ?? '')) === 'BUTTONS') foreach ((array) ($c['buttons'] ?? []) as $b) if (strtoupper((string) ($b['type'] ?? '')) === 'QUICK_REPLY') $tplBtns[] = (string) $b['text'];
            if (strtoupper((string) ($c['type'] ?? '')) === 'CAROUSEL') foreach ((array) ($c['cards'] ?? []) as $cd) $walk($cd['components'] ?? []);
        } };
        $walk($comp);
        $say("  • Template quick-reply buttons: " . ($tplBtns ? '"' . implode('", "', $tplBtns) . '"' : 'none'));
    }
    foreach ($rules as $btn => $fid) {
        $f = db_row("SELECT id, name, status, first_step_id, trigger_type FROM flows WHERE id=?", [(int) $fid]);
        $line = "  • " . ($cm['follow_trigger'] === 'button' ? "Button \"$btn\"" : $btn) . " → automation " . ($f ? "\"{$f['name']}\" (id {$f['id']}, {$f['status']})" : "#$fid (deleted)");
        $say($line);
        if (!$f) $bad("That automation no longer exists.");
        elseif (in_array($f['status'], ['paused', 'archived'], true)) $bad("That automation is {$f['status']}.");
        elseif (!$f['first_step_id']) $bad("Nothing is connected to that automation's start — it cannot send anything.");
        else {
            $st = db_row("SELECT type, config FROM flow_steps WHERE id=?", [(int) $f['first_step_id']]);
            $good("Its first step: " . ($st['type'] ?? '?') . ' — ' . mb_strimwidth((string) (json_decode((string) ($st['config'] ?? ''), true)['body'] ?? ''), 0, 60, '…'));
        }
        if ($cm['follow_trigger'] === 'button' && $map && $tplBtns && !in_array($btn, $tplBtns, true)) $bad("\"$btn\" is not one of the template's buttons now (the template changed?), so it never matches.");
    }

    // 3. What they sent back
    $say("\n3. What the contact sent after the campaign:");
    $ins = db_all("SELECT id, type, body, created_at FROM messages WHERE contact_id=? AND direction='in' AND created_at >= ? ORDER BY id LIMIT 8", [(int) $ct['id'], (string) $cm['sent_at']]);
    if (!$ins) $bad("Nothing arrived from them after the campaign. If they tapped, the webhook did not reach Revenect (check the WhatsApp webhook in Meta).");
    foreach ($ins as $m) $say("  • {$m['created_at']} [{$m['type']}] " . mb_strimwidth((string) $m['body'], 0, 70, '…') . ($m['type'] === 'button' ? '' : ($m['type'] === 'text' ? '   ← typed, not a button tap' : '')));

    // 4. Matched to the campaign?
    $say("\n4. Matched to the campaign (reply_events):");
    $evs = db_all("SELECT kind, body, campaign_id, campaign_message_id, created_at FROM reply_events WHERE contact_id=? AND created_at >= ? ORDER BY id", [(int) $ct['id'], (string) $cm['sent_at']]);
    if (!$evs && $ins) $bad("Their messages were not matched to the campaign. A tap carries the id of the message it answers; see whether that id is the campaign message's id above.");
    foreach ($evs as $e) {
        $mine = (int) $e['campaign_message_id'] === (int) $cm['id'];
        $line = "{$e['created_at']} {$e['kind']} \"" . mb_strimwidth((string) $e['body'], 0, 50, '…') . "\" → campaign " . ($e['campaign_id'] ?: '—');
        $mine ? $good($line) : $bad($line . '  (matched to another campaign message)');
        if ($e['kind'] === 'button' && $map) {
            $hit = false; foreach ($map as $btn => $fid) if (mb_strtolower(trim((string) $btn)) === mb_strtolower(trim((string) $e['body']))) $hit = true;
            $hit ? $good("this button has an automation") : $bad("no automation is set for the button \"{$e['body']}\" (set: \"" . implode('", "', array_keys($map)) . "\")");
        }
    }

    // 5. What the engine decided
    $say("\n5. What the automation engine decided for each message:");
    $logs = db_all("SELECT decision, detail, body, created_at FROM inbound_log WHERE contact_id=? AND created_at >= ? ORDER BY id LIMIT 10", [(int) $ct['id'], (string) $cm['sent_at']]);
    if (!$logs) $say("  • (nothing logged)");
    foreach ($logs as $l) $say("  • {$l['created_at']} {$l['decision']}: {$l['detail']}");
    $say("  • Campaign message followed up at: " . ($cm['follow_enrolled_at'] ?: 'not yet'));

    // 6. Runs
    $say("\n6. Automation runs for this contact since the campaign:");
    $runs = db_all("SELECT r.id, f.name, r.status, r.created_at FROM flow_runs r JOIN flows f ON f.id=r.flow_id WHERE r.contact_id=? AND r.created_at >= ? ORDER BY r.id", [(int) $ct['id'], (string) $cm['sent_at']]);
    if (!$runs) $say("  • none");
    foreach ($runs as $r) $say("  • #{$r['id']} \"{$r['name']}\" — {$r['status']} ({$r['created_at']})");
    $outs = db_all("SELECT body, status, error_title, created_at FROM messages WHERE contact_id=? AND direction='out' AND source='automation' AND created_at >= ? ORDER BY id LIMIT 5", [(int) $ct['id'], (string) $cm['sent_at']]);
    foreach ($outs as $o) $say("    sent: [{$o['status']}] " . mb_strimwidth((string) $o['body'], 0, 60, '…') . ($o['error_title'] ? "  — {$o['error_title']}" : ''));
}
$say("\nSend this whole output to support if it is not clear what to fix.");
