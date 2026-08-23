<?php
declare(strict_types=1);

/**
 * AI-driven automation flow engine.
 *
 * Entry points:
 *   automation_handle_inbound($client,$contact,$message)  — from webhook.php
 *   automation_tick()                                      — from cron (resume timer waits)
 *   automation_enqueue_lead($client,$flow,$contact)        — from cron/leads.php (Google Sheet)
 *
 * Node types: text | image | template | buttons | ai_branch | question |
 *             ai_score | score | wait | tag | list_add | notify | collect
 *
 * Depends on: db, whatsapp, credits, ai, notify, helpers.
 */

require_once __DIR__ . '/inbox.php';    // unified message log (msg_log)
require_once __DIR__ . '/channel.php';  // cloud vs personal-number dispatch

const AUTO_MAX_STEPS = 60; // safety cap per run invocation

/* ── loaders ── */
function auto_flow(int $flowId): ?array   { return db_row("SELECT * FROM flows WHERE id=?", [$flowId]); }
function auto_step(int $stepId): ?array    { return $stepId ? db_row("SELECT * FROM flow_steps WHERE id=?", [$stepId]) : null; }
function auto_cfg(array $step): array       { $c = json_decode((string) ($step['config'] ?? ''), true); return is_array($c) ? $c : []; }

/** Most recent non-terminal run for a contact. */
function auto_active_run(int $contactId): ?array
{
    return db_row(
        "SELECT * FROM flow_runs WHERE contact_id=? AND status IN ('active','waiting_input','waiting_timer')
         ORDER BY id DESC LIMIT 1", [$contactId]
    );
}

function auto_ctx(array $run): array
{
    $c = json_decode((string) ($run['context'] ?? ''), true);
    if (!is_array($c)) $c = [];
    $c['fields']     = $c['fields'] ?? [];
    $c['transcript'] = $c['transcript'] ?? [];
    return $c;
}

function auto_save_run(array $run, array $ctx): void
{
    db_run(
        "UPDATE flow_runs SET status=?, current_step_id=?, wait_until=?, score=?, grade=?, context=?, updated_at=NOW() WHERE id=?",
        [
            $run['status'], $run['current_step_id'], $run['wait_until'],
            (int) $run['score'], $run['grade'],
            json_encode($ctx, JSON_UNESCAPED_UNICODE), (int) $run['id'],
        ]
    );
}

/** Within the 24h customer-service window? */
function auto_in_window(array $contact, array $client = []): bool
{
    // The 24-hour customer-service window is a Meta Cloud API rule. A personal number
    // sends ordinary messages and has no such window, so it is never gated by it.
    if ($client && function_exists('channel_is_personal') && channel_is_personal($client)) return true;
    $last = $contact['last_inbound_at'] ?? null;
    return $last && strtotime((string) $last) > time() - 86400;
}

/* ── send + log + credit ── */
function auto_send(array $client, array $run, array $step, array $contact, string $kind, callable $sender, string $body = ''): bool
{
    // A personal number sends in paced slots shared with campaigns and qualifier outreach.
    // Out of budget → leave the run waiting; the next worker run picks it up rather than
    // burning the slot or dropping the message.
    if (channel_is_personal($client) && slot_budget($client) <= 0) {
        $run['status'] = 'waiting_timer';
        $run['wait_until'] = date('Y-m-d H:i:s', time() + max(1, (int) ($client['slot_pause_sec'] ?: 180)));
        return false;
    }

    // Reserve 1 credit.
    $bal = credits_adjust((int) $client['id'], -1, 'automation', null);
    if ($bal === null) {
        $run['status'] = 'blocked';
        return false;
    }
    $res = $sender();  // ['ok','wamid','error_title']
    slot_consume($client, 1);
    $status = $res['ok'] ? 'sent' : 'failed';
    if (!$res['ok']) credits_adjust((int) $client['id'], 1, 'automation_refund', null);
    db_run(
        "INSERT INTO flow_messages (flow_id,step_id,run_id,client_id,contact_id,wa_message_id,status,error_title,created_at)
         VALUES (?,?,?,?,?,?,?,?,NOW())",
        [(int) $run['flow_id'], (int) $step['id'], (int) $run['id'], (int) $client['id'],
         (int) $contact['id'], $res['wamid'] ?? null, $status, $res['error_title'] ?? null]
    );
    // Mirror into the unified Inbox log.
    if (function_exists('msg_log')) {
        msg_log((int) $client['id'], (int) $contact['id'], 'out', $body, [
            'type' => $kind, 'source' => 'automation', 'status' => $status,
            'wamid' => $res['wamid'] ?? null, 'error' => $res['error_title'] ?? null,
        ]);
    }
    return (bool) $res['ok'];
}

/** Append to transcript. */
function auto_log(array &$ctx, string $role, string $text): void
{
    $ctx['transcript'][] = ['role' => $role, 'text' => $text];
}

/* ── grade + finalize ── */
function auto_finalize(array &$run, array $flow, array $ctx = []): void
{
    if (!empty($ctx['not_interested']) || $run['grade'] === 'not_interested') {
        $run['grade'] = 'not_interested';       // AI (or a manual mark) flagged disinterest
    } else {
        $score = (int) $run['score'];
        $run['grade'] = $score >= (int) $flow['hot_min'] ? 'hot'
                      : ($score >= (int) $flow['warm_min'] ? 'warm' : 'cold');
    }
    $run['status'] = 'completed';
    $run['current_step_id'] = null;
}

/**
 * One knowledge-grounded chat turn (shared by Lead Qualifier ai_chat + AI Chat Agent):
 * generate a reply to $latest, send it, capture fields, flag disinterest, count the turn.
 * Mutates $run (grade) and $ctx (transcript/fields/chat). Returns true when the conversation
 * should ADVANCE to the next node (AI said done, lead not interested, or turn cap hit).
 */
function auto_chat_turn(array $client, array &$run, array $step, array $contact, array &$ctx, array $cfg, string $latest): bool
{
    $to       = (string) $contact['phone_e164'];
    $turns    = (int) ($ctx['chat']['turns'] ?? 0) + 1;
    $maxTurns = max(1, (int) ($cfg['max_turns'] ?? 6));
    // The client's qualifying questions become conversational goals so the AI asks them
    // naturally and skips any the lead already answered (instead of rigid one-by-one sends).
    $goals = (array) ($cfg['goals'] ?? []);
    foreach ((array) ($cfg['questions'] ?? []) as $q) {
        $q = trim((string) $q);
        if ($q !== '') $goals[] = 'Ask the customer and find out: ' . $q;
    }
    $r = ai_chat_reply($client, $ctx['transcript'], $latest,
            (string) ($cfg['knowledge'] ?? ''), (string) ($cfg['persona'] ?? ''),
            $goals, (array) ($cfg['captures'] ?? []), (string) ($cfg['instructions'] ?? ''));
    if ($r['ok'] && $r['reply'] !== '') {
        auto_send($client, $run, $step, $contact, 'text', fn() => channel_send_text($client, $to, $r['reply']), $r['reply']);
        auto_log($ctx, 'assistant', $r['reply']);
    }
    foreach ((array) ($r['captured'] ?? []) as $k => $v) $ctx['fields'][(string) $k] = $v;
    $ctx['chat']['intro_sent'] = 1;
    $ctx['chat']['turns']      = $turns;
    if (empty($r['interested'])) {
        $ctx['not_interested'] = ['reason' => (string) ($r['reason'] ?? '')];
        $ctx['fields']['not_interested_reason'] = (string) ($r['reason'] ?? '');
        $run['grade'] = 'not_interested';
    }
    return !empty($r['done']) || $turns >= $maxTurns;
}

/* ── variable resolution for text bodies: {{name}}, {{phone}}, {{field}} ── */
function auto_render(string $body, array $contact, array $ctx): string
{
    $map = [
        'name'  => (string) ($contact['name'] ?? ''),
        'phone' => (string) ($contact['phone_e164'] ?? ''),
    ];
    foreach ($ctx['fields'] as $k => $v) $map[strtolower((string) $k)] = (string) $v;
    return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($map) {
        return $map[strtolower($m[1])] ?? '';
    }, $body);
}

/**
 * Execute steps starting at $run['current_step_id'] until await / blocked / end.
 * $run is mutated + saved. $reply is the latest inbound text (for ai_score context).
 */
function automation_run_steps(array $client, array $contact, array $run, array $ctx): void
{
    $flow = auto_flow((int) $run['flow_id']);
    if (!$flow) return;

    for ($i = 0; $i < AUTO_MAX_STEPS; $i++) {
        $stepId = (int) ($run['current_step_id'] ?? 0);
        if (!$stepId) { auto_finalize($run, $flow, $ctx); break; }
        $step = auto_step($stepId);
        if (!$step) { auto_finalize($run, $flow, $ctx); break; }
        $cfg  = auto_cfg($step);
        $type = (string) $step['type'];
        $to   = (string) $contact['phone_e164'];

        // Free-form sends require the 24h window; templates are always allowed.
        $freeForm = in_array($type, ['text', 'image', 'buttons', 'question', 'ai_chat'], true)
                 || ($type === 'ai_branch' && trim((string) ($cfg['prompt'] ?? '')) !== '');
        if ($freeForm && !auto_in_window($contact, $client)) {
            $run['status'] = 'blocked';
            break;
        }

        if ($type === 'text') {
            $body = auto_render((string) ($cfg['body'] ?? ''), $contact, $ctx);
            if (!auto_send($client, $run, $step, $contact, 'text', fn() => channel_send_text($client, $to, $body), $body)) break;
            auto_log($ctx, 'assistant', $body);
            $run['current_step_id'] = $step['next_step_id'];
        } elseif ($type === 'image') {
            $link = (string) ($cfg['link'] ?? '');
            $cap  = auto_render((string) ($cfg['caption'] ?? ''), $contact, $ctx);
            if (!auto_send($client, $run, $step, $contact, 'image', fn() => channel_send_image($client, $to, $link, $cap), '🖼️ ' . ($cap !== '' ? $cap : 'image'))) break;
            $run['current_step_id'] = $step['next_step_id'];
        } elseif ($type === 'template') {
            $tpl = db_row("SELECT wa_name, language, components, body_text FROM templates WHERE id=? AND client_id=?",
                [(int) ($cfg['template_id'] ?? 0), (int) $client['id']]);
            if (!$tpl) { $run['status'] = 'blocked'; break; }
            // channel_send_template() builds the FULL Cloud payload (header media/text, body
            // vars, dynamic buttons — sending [] used to break image headers with #132012),
            // or renders the template to plain text for a personal number, which has no
            // approved templates.
            if (!auto_send($client, $run, $step, $contact, 'template',
                fn() => channel_send_template($client, $to, $tpl, $cfg, $contact),
                '📄 Template: ' . $tpl['wa_name'])) break;
            // A template is a message that needs a reply to continue (cold outreach /
            // re-engagement). Pause here; the lead's reply opens the 24h window and
            // resumes at this node's next step.
            $run['status'] = 'waiting_input';
            break;
        } elseif ($type === 'buttons') {
            $body = auto_render((string) ($cfg['body'] ?? ''), $contact, $ctx);
            $btns = [];
            foreach (array_slice((array) ($cfg['buttons'] ?? []), 0, 3) as $bi => $b) {
                $btns[] = ['id' => 'b' . $bi, 'title' => (string) ($b['title'] ?? ('Option ' . ($bi + 1)))];
            }
            if (!auto_send($client, $run, $step, $contact, 'buttons', fn() => channel_send_buttons($client, $to, $body, $btns), $body)) break;
            auto_log($ctx, 'assistant', $body);
            $run['status'] = 'waiting_input';   // await tap; stay on this step
            break;
        } elseif ($type === 'question') {
            $body = auto_render((string) ($cfg['body'] ?? ''), $contact, $ctx);
            if (!auto_send($client, $run, $step, $contact, 'question', fn() => channel_send_text($client, $to, $body), $body)) break;
            auto_log($ctx, 'assistant', $body);
            $run['status'] = 'waiting_input';
            break;
        } elseif ($type === 'ai_branch') {
            $prompt = auto_render((string) ($cfg['prompt'] ?? ''), $contact, $ctx);
            if ($prompt !== '') {
                if (!auto_send($client, $run, $step, $contact, 'text', fn() => channel_send_text($client, $to, $prompt), $prompt)) break;
                auto_log($ctx, 'assistant', $prompt);
            }
            $run['status'] = 'waiting_input';   // classify on next reply
            break;
        } elseif ($type === 'ai_chat') {
            // Knowledge-grounded human-like conversation.
            $last = $ctx['transcript'] ? end($ctx['transcript']) : null;
            $pendingReply = is_array($last) && ($last['role'] ?? '') === 'user';
            // Smart start: if the lead already replied, answer their message directly — skip the
            // canned intro and don't re-ask what they've already brought up.
            if ($pendingReply) {
                if (empty($ctx['chat']['intro_sent'])) $ctx['chat'] = ['intro_sent' => 1, 'turns' => (int) ($ctx['chat']['turns'] ?? 0)];
                if (auto_chat_turn($client, $run, $step, $contact, $ctx, $cfg, (string) ($last['text'] ?? ''))) {
                    $run['current_step_id'] = $step['next_step_id'];   // done → advance to scoring
                    continue;
                }
                $run['status'] = 'waiting_input';
                break;
            }
            // No reply yet → optionally send a bridging intro, then wait.
            if (empty($ctx['chat']['intro_sent'])) {
                $intro = auto_render((string) ($cfg['intro'] ?? ''), $contact, $ctx);
                if ($intro !== '') {
                    if (!auto_send($client, $run, $step, $contact, 'text', fn() => channel_send_text($client, $to, $intro), $intro)) break;
                    auto_log($ctx, 'assistant', $intro);
                }
                $ctx['chat'] = ['intro_sent' => 1, 'turns' => 0];
            }
            $run['status'] = 'waiting_input';
            break;
        } elseif ($type === 'ai_score') {
            $pts = ai_score_reply($client, $ctx['transcript'], (string) ($cfg['criterion'] ?? ''), (int) ($cfg['max_points'] ?? 0));
            $run['score'] = (int) $run['score'] + $pts;
            $run['current_step_id'] = $step['next_step_id'];
        } elseif ($type === 'score') {
            $run['score'] = (int) $run['score'] + (int) ($cfg['points'] ?? 0);
            $run['current_step_id'] = $step['next_step_id'];
        } elseif ($type === 'wait') {
            $secs = max(1, (int) ($cfg['seconds'] ?? 60));
            $run['wait_until'] = date('Y-m-d H:i:s', time() + $secs);
            $run['current_step_id'] = $step['next_step_id'];  // resume here when timer fires
            $run['status'] = 'waiting_timer';
            break;
        } elseif ($type === 'tag') {
            auto_add_tag($contact, (string) ($cfg['tag'] ?? ''));
            $run['current_step_id'] = $step['next_step_id'];
        } elseif ($type === 'list_add') {
            $lid = (int) ($cfg['list_id'] ?? 0);
            if ($lid) db_run("INSERT IGNORE INTO contact_list_members (list_id,contact_id) VALUES (?,?)", [$lid, (int) $contact['id']]);
            $run['current_step_id'] = $step['next_step_id'];
        } elseif ($type === 'notify') {
            $msg = auto_render((string) ($cfg['message'] ?? ''), $contact, $ctx);
            notify_admin('Automation: ' . $flow['name'],
                "Contact +{$contact['phone_e164']} ({$contact['name']})\nScore: {$run['score']}\n\n{$msg}");
            $run['current_step_id'] = $step['next_step_id'];
        } elseif ($type === 'collect') {
            auto_collect($client, $run, $step, $contact, $ctx, $cfg);
            $run['current_step_id'] = $step['next_step_id'];
        } else {
            // unknown → skip to next
            $run['current_step_id'] = $step['next_step_id'];
        }
    }

    // Broke out of the loop while still 'active' → a send failed or the step cap
    // was hit; mark blocked rather than completing. Natural End sets 'completed'
    // via the top-of-loop finalize.
    if ($run['status'] === 'active') $run['status'] = 'blocked';
    auto_save_run($run, $ctx);
}

function auto_add_tag(array $contact, string $tag): void
{
    $tag = trim($tag);
    if ($tag === '') return;
    $cur = array_filter(array_map('trim', explode(',', (string) ($contact['tags'] ?? ''))));
    if (!in_array($tag, $cur, true)) {
        $cur[] = $tag;
        db_run("UPDATE contacts SET tags=? WHERE id=?", [implode(',', $cur), (int) $contact['id']]);
    }
}

function auto_collect(array $client, array $run, array $step, array $contact, array $ctx, array $cfg): void
{
    $fields  = (array) ($cfg['fields'] ?? ['phone', 'name', 'last_reply', 'score', 'tags']);
    $lastReply = '';
    for ($k = count($ctx['transcript']) - 1; $k >= 0; $k--) {
        if (($ctx['transcript'][$k]['role'] ?? '') === 'user') { $lastReply = (string) $ctx['transcript'][$k]['text']; break; }
    }
    $tags = (string) (db_val("SELECT tags FROM contacts WHERE id=?", [(int) $contact['id']]) ?? '');
    // Provisional grade from the running score (grade is only finalized at End).
    $grade = $run['grade'];
    if ($grade === null) {
        $flow = auto_flow((int) $run['flow_id']);
        if ($flow) {
            $s = (int) $run['score'];
            $grade = $s >= (int) $flow['hot_min'] ? 'hot' : ($s >= (int) $flow['warm_min'] ? 'warm' : 'cold');
        }
    }
    db_run(
        "INSERT INTO flow_collected (client_id,flow_id,step_id,run_id,contact_id,sheet_name,phone_e164,name,last_reply,score,grade,tags,created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
        [
            (int) $client['id'], (int) $run['flow_id'], (int) $step['id'], (int) $run['id'], (int) $contact['id'],
            (string) ($cfg['sheet_name'] ?? 'Leads'),
            in_array('phone', $fields, true) ? (string) $contact['phone_e164'] : null,
            in_array('name', $fields, true) ? (string) $contact['name'] : null,
            in_array('last_reply', $fields, true) ? $lastReply : null,
            in_array('score', $fields, true) ? (int) $run['score'] : null,
            $grade,
            in_array('tags', $fields, true) ? $tags : null,
        ]
    );
}

/* ── trigger matching ── */
function auto_match_keyword(int $clientId, string $text): ?array
{
    $text = mb_strtolower(trim($text));
    if ($text === '') return null;
    $flows = db_all("SELECT * FROM flows WHERE client_id=? AND status='active' AND trigger_type='keyword'", [$clientId]);
    foreach ($flows as $f) {
        $tc = json_decode((string) $f['trigger_config'], true) ?: [];
        $match = strtolower((string) ($tc['match_type'] ?? 'contains'));
        foreach ((array) ($tc['keywords'] ?? []) as $kw) {
            $kw = mb_strtolower(trim((string) $kw));
            if ($kw === '') continue;
            if (($match === 'exact'    && $text === $kw)
             || ($match === 'starts'   && str_starts_with($text, $kw))
             || ($match === 'contains' && str_contains($text, $kw))) {
                return $f;
            }
        }
    }
    return null;
}

function auto_welcome_flow(int $clientId): ?array
{
    // An AI Chat Agent welcome flow takes precedence over a chatbot welcome flow.
    return db_row("SELECT * FROM flows WHERE client_id=? AND status='active' AND trigger_type='welcome'
                   ORDER BY (kind='agent') DESC, id LIMIT 1", [$clientId]);
}

/** Catch-all flow: runs when no keyword matched and welcome doesn't apply. */
function auto_default_flow(int $clientId): ?array
{
    return db_row("SELECT * FROM flows WHERE client_id=? AND status='active' AND trigger_type='default'
                   ORDER BY (kind='agent') DESC, id LIMIT 1", [$clientId]);
}

/**
 * Has a human taken this conversation over? While paused the bot stays silent so it can't
 * talk over an agent replying by hand in the Inbox. Tolerates the column not existing yet
 * (migration 009 not applied).
 */
function auto_bot_paused(array $contact): bool
{
    $until = $contact['bot_paused_until'] ?? null;
    return $until !== null && strtotime((string) $until) > time();
}

function auto_start(array $client, array $contact, array $flow, string $inboundText = ''): void
{
    $ctx = ['fields' => [], 'transcript' => []];
    if ($inboundText !== '') auto_log($ctx, 'user', $inboundText);
    $runId = db_insert(
        "INSERT INTO flow_runs (flow_id,client_id,contact_id,current_step_id,status,score,context,created_at)
         VALUES (?,?,?,?, 'active', 0, ?, NOW())",
        [(int) $flow['id'], (int) $client['id'], (int) $contact['id'], $flow['first_step_id'],
         json_encode($ctx, JSON_UNESCAPED_UNICODE)]
    );
    db_run("UPDATE flows SET runs_count=runs_count+1 WHERE id=?", [(int) $flow['id']]);
    $run = db_row("SELECT * FROM flow_runs WHERE id=?", [$runId]);
    automation_run_steps($client, $contact, $run, $ctx);
}

/* ── inbound (from webhook) ── */
function automation_handle_inbound(array $client, array $contact, array $message): void
{
    $text     = (string) ($message['text'] ?? '');
    $buttonId = (string) ($message['button_id'] ?? '');

    // A human is handling this chat (they replied from the Inbox) — stay out of the way.
    // The inbound is still logged by webhook.php, so nothing is lost.
    if (auto_bot_paused($contact)) return;

    $run = auto_active_run((int) $contact['id']);
    if ($run && $run['status'] === 'waiting_input') {
        $ctx  = auto_ctx($run);
        auto_log($ctx, 'user', $text !== '' ? $text : $buttonId);
        $step = auto_step((int) $run['current_step_id']);
        if (!$step) { $run['status'] = 'stopped'; auto_save_run($run, $ctx); return; }
        $cfg  = auto_cfg($step);
        $type = (string) $step['type'];
        $next = $step['next_step_id'];

        // Knowledge-grounded conversation: answer from the KB, keep chatting until the AI
        // says we're done or the turn cap is reached, then hand off to the next node (scoring).
        if ($type === 'ai_chat') {
            $latest = $text !== '' ? $text : $buttonId;
            if (auto_chat_turn($client, $run, $step, $contact, $ctx, $cfg, $latest)) {
                $run['status'] = 'active';
                $run['current_step_id'] = $next;     // done → advance to scoring / collect
                automation_run_steps($client, $contact, $run, $ctx);
            } else {
                $run['status'] = 'waiting_input';     // keep chatting on this node
                auto_save_run($run, $ctx);
            }
            return;
        }

        if ($type === 'question') {
            $saveAs = trim((string) ($cfg['save_as'] ?? ''));
            if ($saveAs !== '') $ctx['fields'][$saveAs] = $text;
        } elseif ($type === 'buttons') {
            $btns = (array) ($cfg['buttons'] ?? []);
            $idx  = ($buttonId !== '' && preg_match('/^b(\d+)$/', $buttonId, $m)) ? (int) $m[1] : -1;
            if ($idx < 0) { // free-text fallback: match by title
                foreach ($btns as $bi => $b) {
                    if (mb_strtolower(trim((string) ($b['title'] ?? ''))) === mb_strtolower(trim($text))) { $idx = $bi; break; }
                }
            }
            if ($idx < 0 && preg_match('/^\s*([1-9])\b/u', $text, $nm)) {
                // A personal number can't send interactive buttons, so they go out as a
                // numbered list — "2" means the second option. Harmless on Cloud too,
                // where someone may type the number instead of tapping.
                $n = (int) $nm[1] - 1;
                if (isset($btns[$n])) $idx = $n;
            }
            if ($idx >= 0 && isset($btns[$idx])) {
                $run['score'] = (int) $run['score'] + (int) ($btns[$idx]['points'] ?? 0);
                $next = $btns[$idx]['next_step_id'] ?? $next;
            }
        } elseif ($type === 'ai_branch') {
            $branches = (array) ($cfg['branches'] ?? []);
            $idx = ai_classify($client, $ctx['transcript'], $branches);
            if ($idx >= 0 && isset($branches[$idx])) {
                $run['score'] = (int) $run['score'] + (int) ($branches[$idx]['points'] ?? 0);
                $next = $branches[$idx]['next_step_id'] ?? ($cfg['fallback_next'] ?? $next);
            } else {
                $next = $cfg['fallback_next'] ?? $next;
            }
        }

        $run['status'] = 'active';
        $run['current_step_id'] = $next;
        automation_run_steps($client, $contact, $run, $ctx);
        return;
    }

    // No waiting run → try to start one (text triggers only).
    if ($text === '') return;
    $flow = auto_match_keyword((int) $client['id'], $text);
    if (!$flow) {
        // welcome: first inbound and no prior run for this contact
        $prior = (int) db_val("SELECT COUNT(*) FROM flow_runs WHERE contact_id=?", [(int) $contact['id']]);
        if ($prior === 0) $flow = auto_welcome_flow((int) $client['id']);
        // Catch-all: nothing matched and they're not new → answer anyway (e.g. an AI agent
        // acting as an always-on FAQ) instead of leaving the message unanswered.
        if (!$flow) $flow = auto_default_flow((int) $client['id']);
    }
    if ($flow) auto_start($client, $contact, $flow, $text);
}

/* ── cron: resume due timer waits ── */
function automation_tick(int $limit = 100): int
{
    $due = db_all(
        "SELECT * FROM flow_runs WHERE status='waiting_timer' AND wait_until IS NOT NULL AND wait_until <= NOW()
         ORDER BY id ASC LIMIT " . (int) $limit
    );
    $n = 0;
    foreach ($due as $run) {
        $client  = db_row("SELECT * FROM clients WHERE id=?", [(int) $run['client_id']]);
        $contact = db_row("SELECT * FROM contacts WHERE id=?", [(int) $run['contact_id']]);
        if (!$client || !$contact) { db_run("UPDATE flow_runs SET status='stopped' WHERE id=?", [(int) $run['id']]); continue; }
        $run['status'] = 'active';
        $run['wait_until'] = null;
        automation_run_steps($client, $contact, $run, auto_ctx($run));
        $n++;
    }
    return $n;
}

/**
 * Mark cold-outreach runs that never got a reply as 'no_answer'. A run counts as "no answer"
 * when it is still waiting on the first inbound (its transcript has no 'user' line — the
 * outreach template was sent but the lead never replied) and it was created more than
 * $hours ago. Returns the number of runs marked.
 */
function automation_sweep_no_answer(int $hours = 24): int
{
    $hours = max(0, $hours);
    $rows = db_all(
        "SELECT id, context FROM flow_runs
          WHERE status='waiting_input' AND grade IS NULL
            AND created_at < (NOW() - INTERVAL " . (int) $hours . " HOUR)"
    );
    $n = 0;
    foreach ($rows as $row) {
        $ctx = json_decode((string) $row['context'], true) ?: [];
        $replied = false;
        foreach ((array) ($ctx['transcript'] ?? []) as $m) {
            if (($m['role'] ?? '') === 'user') { $replied = true; break; }
        }
        if ($replied) continue; // they did reply → not a "no answer"
        db_run("UPDATE flow_runs SET grade='no_answer', status='stopped', updated_at=NOW() WHERE id=?", [(int) $row['id']]);
        $n++;
    }
    return $n;
}

/* ── Lead entry (sheet/upload): QUEUE the outreach; the background worker sends it in
      throttled parallel batches (see automation_send_outreach). Never sends synchronously
      here — that made bulk imports hang and hit WhatsApp rate limits. ── */
function automation_enqueue_lead(array $client, array $flow, array $contact): void
{
    $ctx = ['fields' => [], 'transcript' => []];
    db_insert(
        "INSERT INTO flow_runs (flow_id,client_id,contact_id,current_step_id,status,score,context,created_at)
         VALUES (?,?,?,?, 'queued', 0, ?, NOW())",
        [(int) $flow['id'], (int) $client['id'], (int) $contact['id'], $flow['first_step_id'],
         json_encode($ctx, JSON_UNESCAPED_UNICODE)]
    );
    db_run("UPDATE flows SET runs_count=runs_count+1 WHERE id=?", [(int) $flow['id']]);
}

/** Resolve one template variable spec ({source:name|static, value, fallback}) to a text value. */
function auto_resolve_var(array $spec, array $contact): string
{
    $src = (string) ($spec['source'] ?? 'static');
    $val = $src === 'name' ? trim((string) ($contact['name'] ?? '')) : (string) ($spec['value'] ?? '');
    if ($val === '') $val = (string) ($spec['fallback'] ?? '');
    if ($val === '') $val = '-';   // WhatsApp rejects empty params
    return (string) preg_replace('/[\r\n\t]+/', ' ', $val);
}

/**
 * Build the full template `components` payload for a flow step.
 * Thin alias over the shared builder in whatsapp.php (which Campaigns and the Qualifier
 * also use, so all three send identical payloads). Passing $client lets the header media
 * be uploaded once and sent by media id instead of a per-message link.
 */
function auto_build_components(array $tplComponents, array $cfg, array $contact, array $client = []): array
{
    return wa_build_components($tplComponents, $cfg, $contact, $client);
}

/** Mark a queued run as failed with a reason shown on the leads page. */
function auto_mark_run_error(int $runId, string $err): void
{
    $row = db_row("SELECT context FROM flow_runs WHERE id=?", [$runId]);
    $ctx = $row ? (json_decode((string) $row['context'], true) ?: []) : [];
    $ctx['send_error'] = $err;
    db_run("UPDATE flow_runs SET status='blocked', context=?, updated_at=NOW() WHERE id=?",
        [json_encode($ctx, JSON_UNESCAPED_UNICODE), $runId]);
}

/**
 * Send the outreach template for QUEUED qualifier runs in throttled parallel batches
 * (reuses wa_send_template_batch, like campaigns). Success → waiting_input (awaits the reply);
 * failure → blocked with the Meta error stored in context.send_error. Returns messages sent.
 */
function automation_send_outreach(int $maxPerRun = 0, int $onlyFlowId = 0): int
{
    $cap      = $maxPerRun > 0 ? $maxPerRun : (int) config('send_batch_per_run', 300);
    $parallel = max(1, (int) config('send_parallel', 30));

    // Fair scheduling: give every qualifier with queued leads a slice each run, so a big
    // backlog in one qualifier can't starve a small one. (Optionally target a single flow.)
    $fwhere = $onlyFlowId > 0 ? " AND r.flow_id = " . (int) $onlyFlowId : '';
    $flowsQ = db_all(
        "SELECT r.flow_id, COUNT(*) c FROM flow_runs r
           JOIN flows f ON f.id = r.flow_id AND f.status NOT IN ('archived','paused')
          WHERE r.status='queued'{$fwhere}
          GROUP BY r.flow_id"
    );
    if (!$flowsQ) return 0;
    $perFlow = max(20, intdiv($cap, count($flowsQ)));
    $ids = [];
    foreach ($flowsQ as $fq) {
        $rows = db_all("SELECT id FROM flow_runs WHERE flow_id=? AND status='queued' ORDER BY id ASC LIMIT " . (int) $perFlow, [(int) $fq['flow_id']]);
        foreach ($rows as $rr) $ids[] = (int) $rr['id'];
        if (count($ids) >= $cap) break;
    }
    $ids = array_slice($ids, 0, $cap);
    if (!$ids) return 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $runs = db_all(
        "SELECT r.id, r.flow_id, r.client_id, r.contact_id, r.context, c.phone_e164, c.name AS contact_name
           FROM flow_runs r JOIN contacts c ON c.id = r.contact_id
          WHERE r.id IN ($ph)
          ORDER BY r.id ASC", $ids
    );
    if (!$runs) return 0;

    $byClient = [];
    foreach ($runs as $r) $byClient[(int) $r['client_id']][] = $r;

    $sent = 0;
    foreach ($byClient as $cid => $cruns) {
        $client = db_row("SELECT * FROM clients WHERE id=?", [$cid]);
        if (!$client || ($client['status'] ?? '') !== 'active') continue;

        // Personal numbers share ONE paced slot across every module, and campaigns have
        // already taken their share this run — send only what is left, or nothing while
        // the client is cooling down.
        $budget = slot_budget($client);
        if ($budget <= 0) continue;
        $cruns  = array_slice($cruns, 0, $budget);

        $tplCache = [];  // flowId => ['name','lang','varCount','vars','stepId'] | null
        $items = [];     // runId => item for wa_send_template_batch
        $meta  = [];     // runId => ['flow_id','contact_id','stepId']
        foreach ($cruns as $r) {
            $fid = (int) $r['flow_id'];
            if (!array_key_exists($fid, $tplCache)) {
                $step = db_row("SELECT * FROM flow_steps WHERE flow_id=? AND type='template' ORDER BY sort, id LIMIT 1", [$fid]);
                if (!$step) { $tplCache[$fid] = null; }
                else {
                    $cfg = json_decode((string) $step['config'], true) ?: [];
                    $tpl = db_row("SELECT wa_name, language, variable_count, components, body_text FROM templates WHERE id=? AND client_id=?",
                        [(int) ($cfg['template_id'] ?? 0), $cid]);
                    $tplCache[$fid] = $tpl
                        ? ['name' => (string) $tpl['wa_name'], 'lang' => (string) $tpl['language'],
                           'components' => json_decode((string) $tpl['components'], true) ?: [],
                           'body_text' => (string) ($tpl['body_text'] ?? ''),
                           'cfg' => $cfg, 'stepId' => (int) $step['id']]
                        : null;
                }
            }
            $t = $tplCache[$fid];
            if (!$t) { auto_mark_run_error((int) $r['id'], 'No outreach template configured.'); continue; }

            if (credits_adjust($cid, -1, 'automation', null) === null) {
                auto_mark_run_error((int) $r['id'], 'Insufficient credits.'); continue;
            }
            $items[(int) $r['id']] = [
                'to'   => (string) $r['phone_e164'], 'name' => $t['name'], 'lang' => $t['lang'],
                // $client → header media is uploaded once and reused as a media id across
                // the whole batch (wa_resolve_media caches on the file hash).
                'components' => auto_build_components($t['components'], $t['cfg'], ['name' => $r['contact_name']], $client),
                // Used only by the personal channel, which renders the template to text.
                'tpl' => ['wa_name' => $t['name'], 'language' => $t['lang'],
                          'components' => json_encode($t['components']), 'body_text' => (string) ($t['body_text'] ?? '')],
                'cfg' => $t['cfg'],
                'contact_row' => ['name' => (string) $r['contact_name'], 'phone_e164' => (string) $r['phone_e164']],
            ];
            $meta[(int) $r['id']] = ['flow_id' => $fid, 'contact_id' => (int) $r['contact_id'], 'stepId' => $t['stepId'], 'context' => $r['context']];
        }

        $isPersonal = channel_is_personal($client);
        $chunkSize  = $isPersonal ? 1 : $parallel;
        $firstChunk = true;
        foreach (array_chunk($items, $chunkSize, true) as $chunk) {
            if ($isPersonal) {
                if (!$firstChunk) slot_pace_sleep();
                $firstChunk = false;
                $res = [];
                foreach ($chunk as $runId => $it) {
                    $res[$runId] = channel_send_template(
                        $client, (string) $it['to'], $it['tpl'], $it['cfg'], $it['contact_row']
                    );
                    slot_consume($client, 1);
                }
            } else {
                $res = wa_send_template_batch($client, $chunk);
            }
            foreach ($res as $runId => $rr) {
                $m = $meta[$runId];
                if (!empty($rr['ok'])) {
                    db_run("UPDATE flow_runs SET status='waiting_input', current_step_id=?, updated_at=NOW() WHERE id=?", [$m['stepId'], $runId]);
                    db_run("INSERT INTO flow_messages (flow_id,step_id,run_id,client_id,contact_id,wa_message_id,status,created_at) VALUES (?,?,?,?,?,?, 'sent', NOW())",
                        [$m['flow_id'], $m['stepId'], $runId, $cid, $m['contact_id'], $rr['wamid'] ?? null]);
                    if (function_exists('msg_log')) msg_log($cid, $m['contact_id'], 'out', '📄 Template: ' . $chunk[$runId]['name'], ['type' => 'template', 'source' => 'qualifier', 'status' => 'sent', 'wamid' => $rr['wamid'] ?? null]);
                    $sent++;
                } else {
                    credits_adjust($cid, 1, 'automation_refund', null);
                    $err = (string) ($rr['error_title'] ?? 'Send failed');
                    $ctx = json_decode((string) $m['context'], true) ?: [];
                    $ctx['send_error'] = $err;
                    db_run("UPDATE flow_runs SET status='blocked', context=?, updated_at=NOW() WHERE id=?", [json_encode($ctx, JSON_UNESCAPED_UNICODE), $runId]);
                    db_run("INSERT INTO flow_messages (flow_id,step_id,run_id,client_id,contact_id,wa_message_id,status,error_title,created_at) VALUES (?,?,?,?,?,?, 'failed', ?, NOW())",
                        [$m['flow_id'], $m['stepId'], $runId, $cid, $m['contact_id'], $rr['wamid'] ?? null, substr($err, 0, 255)]);
                    if (function_exists('msg_log')) msg_log($cid, $m['contact_id'], 'out', '📄 Template: ' . $chunk[$runId]['name'], ['type' => 'template', 'source' => 'qualifier', 'status' => 'failed', 'error' => $err]);
                }
            }
        }
    }
    return $sent;
}

/**
 * Turn any Google Sheets link the user pastes into a direct CSV URL.
 * - "…/spreadsheets/d/<ID>/edit#gid=0"  → "…/spreadsheets/d/<ID>/export?format=csv&gid=0"
 * - already a CSV/publish-to-web link → left untouched.
 * Works for sheets shared "Anyone with the link → Viewer" (no Publish-to-web needed).
 */
function auto_normalize_sheet_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return $url;
    // already CSV, or a Publish-to-web link (/d/e/…): leave as-is.
    if (stripos($url, 'output=csv') !== false || stripos($url, 'format=csv') !== false) return $url;
    if (strpos($url, '/spreadsheets/d/e/') !== false) return $url;
    if (preg_match('~docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)~', $url, $m)) {
        $id  = $m[1];
        $gid = '0';
        if (preg_match('~[#?&]gid=([0-9]+)~', $url, $g)) $gid = $g[1];
        return "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv&gid={$gid}";
    }
    return $url;
}

function auto_fetch_url(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'RevenectWA/1.0',
    ]);
    $r = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($r !== false && $code >= 200 && $code < 300) ? (string) $r : null;
}

function auto_col_index(array $cols, array $map, string $key, array $aliases): ?int
{
    if (!empty($map[$key])) {
        $target = strtolower(trim((string) $map[$key]));
        $i = array_search($target, $cols, true);
        if ($i !== false) return (int) $i;
        if (is_numeric($map[$key])) return (int) $map[$key];
    }
    foreach ($cols as $i => $h) if (in_array($h, $aliases, true)) return (int) $i;
    return null;
}

/**
 * Import one qualifier flow's leads from its published CSV and start a run each
 * (whose first step is the outreach template). Returns a result array:
 *   ['imported'=>int, 'skipped'=>int, 'rows'=>int, 'error'=>string]
 * Idempotent: a contact that already exists is skipped. Ignores the fetch interval
 * (the caller decides when to run — used by both the cron and the "Import now" button).
 */
function automation_ingest_flow(array $client, array $flow, int $maxRows = 500): array
{
    $res = ['imported' => 0, 'skipped' => 0, 'invalid' => 0, 'rows' => 0, 'error' => ''];
    $sc  = json_decode((string) $flow['source_config'], true) ?: [];
    $url = trim((string) ($sc['csv_url'] ?? ''));
    if ($url === '') { $res['error'] = 'No Google Sheet CSV URL set.'; return $res; }

    $csv = auto_fetch_url(auto_normalize_sheet_url($url));
    $sc['last_fetched_at'] = date('Y-m-d H:i:s');
    db_run("UPDATE flows SET source_config=? WHERE id=?", [json_encode($sc, JSON_UNESCAPED_UNICODE), (int) $flow['id']]);
    if ($csv === null) { $res['error'] = "Couldn't read the sheet. Make sure it's Published to web as CSV and accessible to anyone with the link."; return $res; }
    // Google returned an HTML page instead of CSV → the sheet isn't publicly readable.
    if (preg_match('~^(?:\xEF\xBB\xBF)?\s*<(?:!doctype|html|\?xml)~i', $csv)) {
        $res['error'] = "The sheet isn't shared publicly. In Google Sheets → Share → set \"Anyone with the link\" to Viewer, then paste the link again.";
        return $res;
    }
    // Per-qualifier country overrides the account default; empty = auto-detect / account default.
    $country = trim((string) ($sc['country'] ?? '')) ?: (string) $client['default_country'];
    return automation_ingest_rows($client, $flow, $csv, $maxRows, $country);
}

/**
 * Parse a CSV string and import its rows as leads into $flow. Shared by the Google-Sheet
 * import and the manual "Upload CSV" button. Dedupe is per-flow (a number already known to
 * the client but not yet a lead in THIS qualifier still imports). Returns
 *   ['imported'=>int, 'skipped'=>int (already in this qualifier), 'invalid'=>int (bad phone),
 *    'rows'=>int, 'error'=>string].
 */
function automation_ingest_rows(array $client, array $flow, string $csv, int $maxRows = 500, string $country = ''): array
{
    $res = ['imported' => 0, 'skipped' => 0, 'invalid' => 0, 'rows' => 0, 'error' => ''];
    $sc  = json_decode((string) $flow['source_config'], true) ?: [];

    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    if (!$lines || count($lines) < 2) { $res['error'] = 'The file has no data rows.'; return $res; }
    $header = str_getcsv((string) array_shift($lines));
    $cols   = array_map(fn($h) => strtolower(trim((string) $h)), $header);
    $map    = (array) ($sc['column_map'] ?? []);
    $phoneIdx = auto_col_index($cols, $map, 'phone', ['phone', 'number', 'mobile', 'msisdn', 'whatsapp', 'tel']);
    $nameIdx  = auto_col_index($cols, $map, 'name',  ['name', 'full_name', 'fullname', 'contact']);
    if ($phoneIdx === null) $phoneIdx = 0;
    if ($country === '') $country = (string) $client['default_country'];
    $cid = (int) $client['id'];

    foreach ($lines as $line) {
        if (trim((string) $line) === '') continue;
        if ($res['imported'] >= $maxRows) break;
        $res['rows']++;
        $row   = str_getcsv((string) $line);
        $phone = normalize_phone((string) ($row[$phoneIdx] ?? ''), $country);
        if ($phone === '') { $res['invalid']++; continue; }
        $name  = $nameIdx !== null ? trim((string) ($row[$nameIdx] ?? '')) : '';

        // Find-or-create the contact (don't skip just because it exists elsewhere).
        $contact = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $phone]);
        if (!$contact) {
            db_run("INSERT INTO contacts (client_id,phone_e164,name,opt_in_status,source,created_at) VALUES (?,?,?, 'in','sheet',NOW())",
                [$cid, $phone, $name]);
            $contact = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $phone]);
        }
        if (!$contact) { $res['invalid']++; continue; }

        // Per-flow dedupe: already a lead in THIS qualifier → skip.
        if (db_row("SELECT id FROM flow_runs WHERE flow_id=? AND contact_id=?", [(int) $flow['id'], (int) $contact['id']])) {
            $res['skipped']++; continue;
        }
        automation_enqueue_lead($client, $flow, $contact);
        $res['imported']++;
    }
    return $res;
}

/**
 * "Send now" for one qualifier: import any new leads from the sheet (which sends their
 * outreach immediately) AND retry every run that got stuck (blocked = outreach send failed
 * earlier, e.g. out of credits, or a free-form step outside the 24h window). Returns:
 *   ['imported'=>int,'skipped'=>int,'rows'=>int,'retried'=>int,'error'=>string]
 */
function automation_send_now(array $client, array $flow): array
{
    $r = automation_ingest_flow($client, $flow);
    // Re-queue stuck outreach (blocked before the lead ever replied, e.g. failed send /
    // no credits earlier) so the background worker resends it in throttled batches.
    $r['retried'] = (int) db_val(
        "SELECT COUNT(*) FROM flow_runs WHERE flow_id=? AND status='blocked' AND grade IS NULL", [(int) $flow['id']]
    );
    db_run("UPDATE flow_runs SET status='queued', updated_at=NOW() WHERE flow_id=? AND status='blocked' AND grade IS NULL",
        [(int) $flow['id']]);
    trigger_worker();   // start sending immediately in the background
    return $r;
}

/**
 * Poll all active Google-Sheet qualifiers whose fetch interval has elapsed.
 * Returns total leads started.
 */
function automation_ingest_sheets(int $maxRowsPerFlow = 500): int
{
    $flows = db_all("SELECT * FROM flows WHERE status='active' AND trigger_type='google_sheet'");
    $total = 0;
    foreach ($flows as $flow) {
        $sc = json_decode((string) $flow['source_config'], true) ?: [];
        if (trim((string) ($sc['csv_url'] ?? '')) === '') continue;
        $interval = max(1, (int) ($sc['fetch_interval_min'] ?? 15));
        if (!empty($sc['last_fetched_at']) && strtotime((string) $sc['last_fetched_at']) > time() - $interval * 60) continue;
        $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $flow['client_id']]);
        if (!$client || $client['status'] !== 'active') continue;
        $r = automation_ingest_flow($client, $flow, $maxRowsPerFlow);
        $total += $r['imported'];
    }
    return $total;
}
