<?php
declare(strict_types=1);

/**
 * AI on the lead page.
 *
 *   summary      what this lead wants and where things stand, in a few lines — so whoever opens
 *                the lead does not have to scroll a month of WhatsApp
 *   filled in    project, unit type, budget and how they will pay, read from what they wrote —
 *                only into empty fields, never over what a person entered
 *   reply        a suggested next message, in the lead's own language, for the salesperson to
 *                check and send
 *
 * Uses the account's AI (its own key, or the plan's allowance) through ai_complete(). The
 * summary is kept on the lead and redone only when new messages have arrived since.
 */

require_once __DIR__ . '/ai.php';

/** The conversation and recent activity, as plain lines for the prompt. */
function crm_ai_transcript(array $lead, int $maxMessages = 40): array
{
    $names = [];
    $msgs = array_reverse(db_all("SELECT id, direction, body, created_at, sent_by_user_id, source FROM messages WHERE contact_id=? ORDER BY id DESC LIMIT " . (int) $maxMessages, [(int) $lead['id']]));
    $lines = []; $lastId = 0; $inbound = 0;
    foreach ($msgs as $m) {
        $lastId = max($lastId, (int) $m['id']);
        $body = trim(preg_replace('/\s+/u', ' ', (string) $m['body']));
        if ($body === '') continue;
        if ($m['direction'] === 'in') { $inbound++; $who = 'Lead'; }
        else {
            $uid = (int) ($m['sent_by_user_id'] ?? 0);
            if ($uid) $names[$uid] ??= crm_user_name($uid);
            $who = $uid ? 'Us (' . $names[$uid] . ')' : (($m['source'] ?? '') === 'crm_auto' ? 'Us (automatic)' : 'Us');
        }
        $lines[] = '[' . date('j M H:i', strtotime((string) $m['created_at'])) . '] ' . $who . ': ' . mb_substr($body, 0, 600);
    }
    $acts = [];
    if (db_has_column('crm_notes', 'kind')) {
        $kinds = crm_activity_kinds(); $outs = crm_activity_outcomes();
        foreach (array_reverse(db_all("SELECT kind, outcome, body, created_at FROM crm_notes WHERE contact_id=? ORDER BY id DESC LIMIT 10", [(int) $lead['id']])) as $n) {
            $acts[] = '[' . date('j M', strtotime((string) $n['created_at'])) . '] ' . ($kinds[$n['kind']] ?? 'Note')
                    . ($n['outcome'] ? ' — ' . ($outs[$n['outcome']] ?? $n['outcome']) : '') . (trim((string) $n['body']) !== '' ? ': ' . mb_substr(trim((string) $n['body']), 0, 300) : '');
        }
    }
    $text = implode("\n", $lines);
    if (mb_strlen($text) > 9000) $text = '…' . mb_substr($text, -9000);
    return ['text' => $text, 'activity' => implode("\n", $acts), 'last_id' => $lastId, 'inbound' => $inbound];
}

/** Does the summary need doing again? Only when the lead has written, and something is new since. */
function crm_ai_stale(array $lead): bool
{
    $last = (int) db_val("SELECT COALESCE(MAX(id),0) FROM messages WHERE contact_id=?", [(int) $lead['id']]);
    $in = (int) db_val("SELECT COUNT(*) FROM messages WHERE contact_id=? AND direction='in'", [(int) $lead['id']]);
    return $in > 0 && $last > (int) ($lead['ai_msg_id'] ?? 0);
}

/**
 * Summarise the conversation and read the lead's wishes out of it. Fills only empty fields.
 *
 * @return array{ok:bool, error?:string, summary?:string, facts?:array, filled?:array}
 */
function crm_ai_summarize(array $client, array $lead, ?int $by = null): array
{
    if (!ai_configured($client)) return ['ok' => false, 'error' => 'AI is not switched on for this account.'];
    $cid = (int) $client['id'];
    $t = crm_ai_transcript($lead);
    if ($t['inbound'] === 0) return ['ok' => false, 'error' => 'Nothing to summarise yet — the lead has not written.'];

    $projects = array_values(array_column(crm_projects($cid, true), 'name'));
    $units = crm_options($cid, 'unit_type');
    $form = json_decode((string) ($lead['attributes'] ?? ''), true) ?: [];
    $system = "You help a real-estate sales team in Egypt keep track of leads who message them on WhatsApp.\n"
        . "Read the conversation and output JSON only, with these keys:\n"
        . "  \"summary\": 3 to 5 short lines — who they are, what they want, what was offered, where things stand. "
        . "Write it in the language the lead writes in (Arabic if they write Arabic).\n"
        . "  \"project\": one of " . json_encode($projects, JSON_UNESCAPED_UNICODE) . " if they clearly want one of these, else null\n"
        . "  \"unit_type\": one of " . json_encode($units, JSON_UNESCAPED_UNICODE) . " if they said, else null\n"
        . "  \"budget\": their budget in their words, e.g. \"5–7M EGP\", or null\n"
        . "  \"payment\": \"cash\" or \"installments\" if they said, else null\n"
        . "  \"language\": \"ar\" or \"en\" — the language the lead writes in\n"
        . "  \"interest\": \"hot\", \"warm\" or \"cold\" — how ready they seem to buy\n"
        . "  \"next_step\": one short sentence — the most useful thing the salesperson should do next\n"
        . "Only use what is actually in the conversation. Never invent prices, dates or facts.";
    $user = "Lead: " . ($lead['name'] ?: 'unknown name') . "\n"
        . ($form ? "Their lead-form answers: " . json_encode($form, JSON_UNESCAPED_UNICODE) . "\n" : '')
        . ($t['activity'] !== '' ? "Salesperson's notes and calls:\n" . $t['activity'] . "\n" : '')
        . "\nConversation:\n" . $t['text'];
    ai_usage_source('crm_summary');
    $r = ai_complete($client, $system, [['role' => 'user', 'content' => $user]], 700);
    if (!$r['ok']) return ['ok' => false, 'error' => 'The AI did not answer: ' . $r['error']];
    $j = ai_extract_json($r['text']);
    if (!$j || trim((string) ($j['summary'] ?? '')) === '') return ['ok' => false, 'error' => 'The AI answer could not be read. Try again.'];

    $facts = [];
    foreach (['project', 'unit_type', 'budget', 'payment', 'language', 'interest', 'next_step'] as $k) {
        $v = $j[$k] ?? null;
        if (is_string($v) && trim($v) !== '' && strtolower(trim($v)) !== 'null') $facts[$k] = mb_substr(trim($v), 0, 200);
    }
    // Fill empty fields only. What a person entered always wins over what the AI read.
    $filled = []; $set = []; $p = [];
    if (!empty($facts['project']) && empty($lead['project_id'])) {
        foreach (crm_projects($cid, true) as $pj) {
            if (mb_strtolower($pj['name']) === mb_strtolower($facts['project'])) { $set[] = 'project_id=?'; $p[] = (int) $pj['id']; $filled[] = 'project'; break; }
        }
    }
    if (!empty($facts['unit_type']) && empty($lead['unit_type'])) {
        $match = null;
        foreach ($units as $u) if (mb_strtolower($u) === mb_strtolower($facts['unit_type'])) $match = $u;
        $set[] = 'unit_type=?'; $p[] = mb_substr($match ?? $facts['unit_type'], 0, 80); $filled[] = 'unit type';
    }
    if (!empty($facts['budget']) && empty($lead['budget'])) { $set[] = 'budget=?'; $p[] = mb_substr($facts['budget'], 0, 80); $filled[] = 'budget'; }
    if (!empty($facts['payment']) && in_array($facts['payment'], ['cash', 'installments'], true) && empty($lead['payment_pref'])) {
        $set[] = 'payment_pref=?'; $p[] = $facts['payment']; $filled[] = 'payment';
    }
    $set = array_merge($set, ['ai_summary=?', 'ai_facts=?', 'ai_summary_at=NOW()', 'ai_msg_id=?']);
    $p = array_merge($p, [mb_substr(trim((string) $j['summary']), 0, 3000), json_encode($facts, JSON_UNESCAPED_UNICODE), $t['last_id'], (int) $lead['id']]);
    db_run("UPDATE contacts SET " . implode(', ', $set) . " WHERE id=?", $p);
    if ($filled) {
        crm_log($cid, (int) $lead['id'], 'ai_fill', null, mb_substr(implode(', ', $filled), 0, 64), $by);
        crm_rescore((int) $lead['id']);
    }
    return ['ok' => true, 'summary' => trim((string) $j['summary']), 'facts' => $facts, 'filled' => $filled];
}

/**
 * A suggested next WhatsApp message, as the salesperson, in the lead's language.
 *
 * @return array{ok:bool, error?:string, text?:string}
 */
function crm_ai_reply(array $client, array $lead, string $salesperson, string $hint = ''): array
{
    if (!ai_configured($client)) return ['ok' => false, 'error' => 'AI is not switched on for this account.'];
    $cid = (int) $client['id'];
    $t = crm_ai_transcript($lead, 30);
    $facts = json_decode((string) ($lead['ai_facts'] ?? ''), true) ?: [];
    $project = !empty($lead['project_id']) ? (string) db_val("SELECT CONCAT(name, IF(address IS NULL OR address='', '', CONCAT(' (', address, ')'))) FROM crm_projects WHERE id=?", [(int) $lead['project_id']]) : '';
    $visit = db_row("SELECT starts_at, place FROM crm_visits WHERE contact_id=? AND status='scheduled' AND starts_at > NOW() ORDER BY starts_at LIMIT 1", [(int) $lead['id']]);
    $lang = ($facts['language'] ?? '') === 'ar' ? 'Egyptian Arabic, as they write' : (($facts['language'] ?? '') === 'en' ? 'English' : 'the language the lead writes in (Egyptian Arabic if they write Arabic)');
    $system = "You are {$salesperson}, a friendly, professional real-estate salesperson in Egypt, writing one WhatsApp message to a lead.\n"
        . "Write in {$lang}. Keep it short: at most 3 sentences, no lists, no hashtags, at most one emoji.\n"
        . "Answer what they last asked if you can, and move gently toward the next step — usually booking a site visit or a call.\n"
        . "Never invent prices, availability, payment plans, dates or offers that are not in the information given. If they asked something you "
        . "cannot answer from it, say you will check and get back to them.\n"
        . "Output only the message text — nothing before or after it.";
    $user = "Lead: " . ($lead['name'] ?: 'unknown name') . "\n"
        . ($project !== '' ? "Project they are interested in: {$project}\n" : '')
        . (!empty($lead['unit_type']) ? "Unit type: {$lead['unit_type']}\n" : '')
        . (!empty($lead['budget']) ? "Budget: {$lead['budget']}\n" : '')
        . ($visit ? "A site visit is booked for " . date('l j F, g:i A', strtotime((string) $visit['starts_at'])) . ($visit['place'] ? " at {$visit['place']}" : '') . ".\n" : '')
        . (!empty($lead['ai_summary']) ? "Summary so far: {$lead['ai_summary']}\n" : '')
        . ($t['activity'] !== '' ? "Recent calls and notes:\n{$t['activity']}\n" : '')
        . ($hint !== '' ? "What the salesperson wants to say: " . mb_substr($hint, 0, 300) . "\n" : '')
        . "\nConversation:\n" . ($t['text'] !== '' ? $t['text'] : '(no messages yet — this would be the first)');
    ai_usage_source('crm_reply');
    $r = ai_complete($client, $system, [['role' => 'user', 'content' => $user]], 400);
    if (!$r['ok']) return ['ok' => false, 'error' => 'The AI did not answer: ' . $r['error']];
    $text = trim(preg_replace('/^["“]|["”]$/u', '', trim($r['text'])));
    return $text !== '' ? ['ok' => true, 'text' => $text] : ['ok' => false, 'error' => 'The AI gave an empty answer. Try again.'];
}
