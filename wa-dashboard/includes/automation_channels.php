<?php
declare(strict_types=1);

/**
 * Automations across channels: one workflow, many triggers, on WhatsApp, Messenger and Instagram.
 *
 *   triggers   A flow keeps its own WhatsApp trigger (flows.trigger_type) and can have any number of
 *              extra ones in flow_triggers — a Messenger keyword, an Instagram comment on a reel,
 *              a Meta lead form, a CRM stage — so one conversation design serves every channel.
 *   channels   A run remembers the channel it started on; each sending step can stay there or send
 *              on another channel ("Send on"). A person not reachable on that channel takes the
 *              step's "Not reachable" exit, so a flow can ask for a phone number on Instagram and
 *              carry on with a WhatsApp template once it has it.
 *   comments   A run that starts from a comment can reply to it publicly and privately (Meta allows
 *              one private reply per comment), and wait for the answer to that private reply.
 *   export     The "Export data" step sends what the conversation gathered to a Google Sheet, the
 *              CRM lead, another system (signed webhook) and/or email, and keeps every row on the
 *              flow's Data page.
 *
 * Loaded by automation.php. Everything here tolerates migration 062 not having run.
 */

/* ───────────────────────── triggers ───────────────────────── */

/**
 * Every trigger a flow can have: [label, the channel it needs (client_has_channel), the settings it takes].
 * Settings: keywords | ads | posts | forms | stage | ''.
 */
function auto_trigger_kinds(): array
{
    return [
        'wa_keyword'          => ['WhatsApp — a message with a keyword',           'whatsapp',    'keywords'],
        'wa_welcome'          => ['WhatsApp — someone\'s first message',           'whatsapp',    ''],
        'wa_any'              => ['WhatsApp — any message nothing else answers',   'whatsapp',    ''],
        'wa_ad'               => ['WhatsApp — someone arrives from an ad',         'whatsapp',    'ads'],
        'messenger_keyword'   => ['Messenger — a message with a keyword',          'messenger',   'keywords'],
        'messenger_first'     => ['Messenger — someone\'s first message',          'messenger',   ''],
        'messenger_any'       => ['Messenger — any message nothing else answers',  'messenger',   ''],
        'messenger_ad'        => ['Messenger — someone arrives from an ad',        'messenger',   'ads'],
        'instagram_keyword'   => ['Instagram — a message with a keyword',          'instagram',   'keywords'],
        'instagram_first'     => ['Instagram — someone\'s first message',          'instagram',   ''],
        'instagram_any'       => ['Instagram — any message nothing else answers',  'instagram',   ''],
        'instagram_story_reply'   => ['Instagram — a reply to your story',          'instagram',   'keywords'],
        'instagram_story_mention' => ['Instagram — someone mentions you in a story','instagram',   ''],
        'instagram_ad'        => ['Instagram — someone arrives from an ad',        'instagram',   'ads'],
        'fb_comment'          => ['Facebook — a comment on your post',             'fb_comments', 'posts'],
        'ig_comment'          => ['Instagram — a comment on your post or reel',    'ig_comments', 'posts'],
        'lead_form'           => ['Meta — a lead form is submitted',               '',            'forms'],
        'crm_stage'           => ['CRM — a lead reaches a stage',                  '',            'stage'],
    ];
}

/** The kinds this client can use (its channels; lead forms and stages need the CRM). */
function auto_trigger_kinds_for(array $client): array
{
    $out = [];
    foreach (auto_trigger_kinds() as $k => $t) {
        if ($t[1] !== '' && function_exists('client_has_channel') && !client_has_channel($client, $t[1])) continue;
        if (in_array($k, ['lead_form', 'crm_stage'], true) && function_exists('crm_enabled') && !crm_enabled($client)) continue;
        $out[$k] = $t;
    }
    return $out;
}

function auto_flow_triggers(int $flowId): array
{
    try {
        return array_map(fn($r) => ['kind' => $r['kind'], 'config' => json_decode((string) $r['config'], true) ?: [], 'active' => (int) $r['active']],
                         db_all("SELECT * FROM flow_triggers WHERE flow_id=? ORDER BY sort, id", [$flowId]));
    } catch (Throwable $e) { return []; }
}

/** Replace a flow's extra triggers with what the editor sent, keeping only what this client may use. */
function auto_save_triggers(array $client, int $flowId, array $rows): void
{
    if (!db_has_column('flow_runs', 'channel')) return;
    $kinds = auto_trigger_kinds_for($client);
    db_run("DELETE FROM flow_triggers WHERE flow_id=?", [$flowId]);
    $sort = 0;
    foreach (array_slice($rows, 0, 20) as $r) {
        $k = (string) ($r['kind'] ?? '');
        if (!isset($kinds[$k])) continue;
        $c = (array) ($r['config'] ?? []);
        $list = fn($v) => array_values(array_filter(array_map('trim', is_array($v) ? array_map('strval', $v) : preg_split('/[\n,]+/', (string) $v)), 'strlen'));
        $cfg = match ($kinds[$k][2]) {
            'keywords' => ['keywords' => $list($c['keywords'] ?? []), 'match_type' => in_array($c['match_type'] ?? '', ['exact', 'starts', 'contains'], true) ? $c['match_type'] : 'contains'],
            'ads'      => ['ad_ids' => $list($c['ad_ids'] ?? [])],
            'posts'    => ['posts' => $list($c['posts'] ?? []), 'keywords' => $list($c['keywords'] ?? []),
                           'match_type' => in_array($c['match_type'] ?? '', ['exact', 'starts', 'contains'], true) ? $c['match_type'] : 'contains'],
            'forms'    => ['forms' => $list($c['forms'] ?? [])],
            'stage'    => ['stage_id' => (int) ($c['stage_id'] ?? 0)],
            default    => [],
        };
        db_insert("INSERT INTO flow_triggers (flow_id,client_id,kind,config,active,sort) VALUES (?,?,?,?,?,?)",
                  [$flowId, (int) $client['id'], $k, json_encode($cfg, JSON_UNESCAPED_UNICODE), isset($r['active']) && !$r['active'] ? 0 : 1, $sort++]);
    }
}

/** Does $text match a keyword list? An empty list matches anything. */
function auto_keywords_match(array $cfg, string $text): bool
{
    $kws = array_filter(array_map(fn($k) => mb_strtolower(trim((string) $k)), (array) ($cfg['keywords'] ?? [])), 'strlen');
    if (!$kws) return true;
    $t = mb_strtolower(trim($text));
    if ($t === '') return false;
    $m = (string) ($cfg['match_type'] ?? 'contains');
    foreach ($kws as $kw) {
        if (($m === 'exact' && $t === $kw) || ($m === 'starts' && str_starts_with($t, $kw)) || ($m === 'contains' && str_contains($t, $kw))) return true;
    }
    return false;
}

/**
 * The first active flow with a trigger of one of $kinds that accepts this event.
 * Agents first, then the oldest flow — the same rule as the WhatsApp triggers.
 */
function auto_trigger_flow(int $clientId, array $kinds, callable $accepts): ?array
{
    if (!$kinds || !db_has_column('flow_runs', 'channel')) return null;
    $ph = implode(',', array_fill(0, count($kinds), '?'));
    try {
        $rows = db_all("SELECT f.*, t.kind trig_kind, t.config trig_config FROM flow_triggers t JOIN flows f ON f.id=t.flow_id
                         WHERE t.client_id=? AND t.active=1 AND f.status='active' AND t.kind IN ($ph)
                         ORDER BY FIELD(t.kind, $ph), (f.kind='agent') DESC, f.id", array_merge([$clientId], $kinds, $kinds));
    } catch (Throwable $e) { return null; }
    foreach ($rows as $f) {
        if ($accepts((string) $f['trig_kind'], json_decode((string) $f['trig_config'], true) ?: [])) return $f;
    }
    return null;
}

/**
 * Which flow answers a Messenger / Instagram message: keyword > ad > story > first message > anything.
 * Keyword triggers with no keywords are skipped here — "any message" is what those mean, and it has its own rung.
 */
function auto_match_social(int $clientId, string $channel, string $text, string $trigger, string $adId, bool $first): ?array
{
    $p = $channel === 'instagram' ? 'instagram' : 'messenger';
    $kw = fn($k, $c) => !empty($c['keywords']) && auto_keywords_match($c, $text);
    $ladder = [[$p . '_keyword'], [$p . '_ad'], [], [], [$p . '_any']];
    if ($trigger === 'story_reply')   $ladder[2] = ['instagram_story_reply'];
    if ($trigger === 'story_mention') $ladder[2] = ['instagram_story_mention'];
    if ($first)                       $ladder[3] = [$p . '_first'];
    foreach ($ladder as $i => $kinds) {
        if (!$kinds) continue;
        $f = auto_trigger_flow($clientId, $kinds, function ($k, $c) use ($i, $kw, $trigger, $adId, $text) {
            if ($i === 0) return $kw($k, $c);
            if ($i === 1) return $trigger === 'ad' && (empty($c['ad_ids']) || in_array($adId, (array) $c['ad_ids'], true));
            if ($i === 2) return auto_keywords_match($c, $text);
            return true;
        });
        if ($f) return $f;
    }
    return null;
}

/* ───────────────────────── where a step sends ───────────────────────── */

/** Steps that send a message to the person (and so need to reach them on a channel). */
function auto_sending_types(): array
{
    return ['text', 'image', 'template', 'buttons', 'question', 'ai_chat', 'list_msg', 'ai_branch'];
}

/**
 * Point the sender at the right channel for this step. null = WhatsApp, an array = routed to
 * Messenger / Instagram, false = this person cannot be reached where the step wants to send.
 */
function auto_route_for(array $client, array $run, array $cfg, array $contact)
{
    $want = (string) ($cfg['send_on'] ?? '');
    $ch = ($want === '' || $want === 'origin') ? (string) ($run['channel'] ?? 'whatsapp') : $want;
    if (!in_array($ch, ['whatsapp', 'messenger', 'instagram'], true)) $ch = 'whatsapp';
    if ($ch === 'whatsapp') {
        channel_route(null, true);
        return trim((string) ($contact['phone_e164'] ?? '')) === '' ? false : null;
    }
    $id = (string) ($contact[$ch === 'instagram' ? 'ig_sid' : 'fb_psid'] ?? '');
    if ($id === '' || (function_exists('client_has_channel') && !client_has_channel($client, $ch))) { channel_route(null, true); return false; }
    $r = ['channel' => $ch, 'contact' => $contact];
    channel_route($r);
    return $r;
}

/* ───────────────────────── starting from Messenger / Instagram ───────────────────────── */

/** A Messenger / Instagram message, after social.php has logged it: answer it the way WhatsApp messages are answered. */
function automation_handle_social(array $client, array $contact, string $platform, array $m): void
{
    auto_replying(true);
    try {
        $sentSql = "SELECT COUNT(*) FROM messages WHERE contact_id=? AND direction='out' AND COALESCE(status,'') <> 'failed'";
        $before = (int) db_val($sentSql, [(int) $contact['id']]);
        $reason = auto_inbound_decide($client, $contact, ['text' => (string) ($m['text'] ?? ''), 'button_id' => (string) ($m['button_id'] ?? '')],
                                      $platform, (string) ($m['trigger'] ?? 'message'), (string) (($m['referral'] ?? [])['ad_id'] ?? ''));
        $after = (int) db_val($sentSql, [(int) $contact['id']]);
        [$decision, $detail] = array_pad(explode('|', $reason, 2), 2, '');
        if ($after > $before) { $detail = $decision . ($detail !== '' ? ' ' . $detail : ''); $decision = 'replied'; }
        auto_note_inbound($client, $contact, (string) ($m['text'] ?? ''), $decision, social_channel_label($platform) . ': ' . $detail);
    } finally {
        channel_route(null, true);
    }
}

/**
 * A new comment, after social.php has stored and moderated it: start the flow whose comment trigger
 * accepts it. The run talks on the matching DM channel, and remembers the comment it answers.
 */
function automation_handle_comment(array $client, array $comment, array $contact): bool
{
    $kind = $comment['platform'] === 'ig' ? 'ig_comment' : 'fb_comment';
    $flow = auto_trigger_flow((int) $client['id'], [$kind], function ($k, $c) use ($comment) {
        if (!empty($c['posts']) && !in_array((string) $comment['post_id'], (array) $c['posts'], true)) return false;
        return auto_keywords_match($c, (string) $comment['body']);
    });
    if (!$flow) return false;
    auto_replying(true);
    try {
        auto_start($client, $contact, $flow, (string) $comment['body'], ['comment' => (string) $comment['body'], 'post_link' => (string) ($comment['post_link'] ?? '')],
                   $comment['platform'] === 'ig' ? 'instagram' : 'messenger', ['trigger_kind' => $kind, 'comment_id' => (string) $comment['comment_id']]);
    } finally {
        channel_route(null, true);
    }
    return true;
}

/** A Meta lead form came in, or a lead reached a stage: start the flows that wait for that. */
function automation_handle_crm_event(array $client, int $contactId, string $kind, array $data): int
{
    if (!db_has_column('flow_runs', 'channel')) return 0;
    $contact = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$contactId, (int) $client['id']]);
    if (!$contact) return 0;
    $n = 0;
    try {
        $rows = db_all("SELECT f.*, t.config trig_config FROM flow_triggers t JOIN flows f ON f.id=t.flow_id
                         WHERE t.client_id=? AND t.active=1 AND f.status='active' AND t.kind=? ORDER BY f.id", [(int) $client['id'], $kind]);
    } catch (Throwable $e) { return 0; }
    foreach ($rows as $f) {
        $c = json_decode((string) $f['trig_config'], true) ?: [];
        if ($kind === 'lead_form' && !empty($c['forms']) && !in_array((string) ($data['form_id'] ?? ''), (array) $c['forms'], true)) continue;
        if ($kind === 'crm_stage' && (int) ($c['stage_id'] ?? 0) !== (int) ($data['stage_id'] ?? 0)) continue;
        // A person reached on WhatsApp when they have a number, else where they last wrote to us.
        $ch = !empty($contact['phone_e164']) ? 'whatsapp' : (!empty($contact['ig_sid']) ? 'instagram' : (!empty($contact['fb_psid']) ? 'messenger' : 'whatsapp'));
        auto_start($client, $contact, $f, '', [], $ch, ['trigger_kind' => $kind]);
        channel_route(null, true);
        $n++;
    }
    return $n;
}

/* ───────────────────────── comment steps ───────────────────────── */

/** The comment a run answers, when it started from one. */
function auto_run_comment(array $run): ?array
{
    $id = (string) ($run['comment_id'] ?? '');
    if ($id === '') return null;
    try { return db_row("SELECT * FROM social_comments WHERE client_id=? AND comment_id=?", [(int) $run['client_id'], $id]) ?: null; }
    catch (Throwable $e) { return null; }
}

/* ───────────────────────── export data ───────────────────────── */

/** What the Export data step can send, as key => label. Captured answers are added as f:<key>. */
function auto_export_fields(): array
{
    return [
        'date' => 'Date', 'name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'channel' => 'Channel',
        'ig_username' => 'Instagram username', 'last_reply' => 'Last reply', 'transcript' => 'Whole conversation',
        'comment' => 'Comment', 'post_link' => 'Post link', 'score' => 'Score', 'grade' => 'Grade',
        'lead_code' => 'Lead code', 'stage' => 'CRM stage', 'owner' => 'Salesperson', 'flow' => 'Automation', 'trigger' => 'Started by',
        'all_answers' => 'Every captured answer',
    ];
}

/** The values for one export, in the order chosen: label => value. */
function auto_export_values(array $client, array $flow, array $run, array $contact, array $ctx, array $cfg): array
{
    $fields = array_values(array_filter(array_map('strval', (array) ($cfg['fields'] ?? []))));
    if (!$fields) $fields = ['date', 'name', 'phone', 'channel', 'last_reply', 'all_answers'];
    $labels = auto_export_fields();
    $last = '';
    foreach (array_reverse((array) ($ctx['transcript'] ?? [])) as $t) if (($t['role'] ?? '') === 'user') { $last = (string) ($t['text'] ?? ''); break; }
    $comment = auto_run_comment($run);
    $answers = array_filter((array) ($ctx['fields'] ?? []), fn($v, $k) => !str_starts_with((string) $k, '_') && is_scalar($v), ARRAY_FILTER_USE_BOTH);
    $out = [];
    foreach ($fields as $f) {
        if (str_starts_with($f, 'f:')) { $k = substr($f, 2); $out[$k] = (string) ($answers[$k] ?? ''); continue; }
        if ($f === 'all_answers') { foreach ($answers as $k => $v) $out[(string) $k] ??= (string) $v; continue; }
        $out[$labels[$f] ?? $f] = match ($f) {
            'date'        => date('Y-m-d H:i'),
            'name'        => (string) ($contact['name'] ?? ''),
            'phone'       => !empty($contact['phone_e164']) ? '+' . $contact['phone_e164'] : '',
            'email'       => (string) ($contact['email'] ?? ''),
            'channel'     => social_channel_label((string) ($run['channel'] ?? 'whatsapp')),
            'ig_username' => (string) ($contact['ig_username'] ?? ''),
            'last_reply'  => $last,
            'transcript'  => implode("\n", array_map(fn($t) => (($t['role'] ?? '') === 'user' ? 'Them: ' : 'Us: ') . ($t['text'] ?? ''), (array) ($ctx['transcript'] ?? []))),
            'comment'     => $comment ? (string) $comment['body'] : (string) ($ctx['fields']['comment'] ?? ''),
            'post_link'   => $comment ? (string) ($comment['post_link'] ?? '') : (string) ($ctx['fields']['post_link'] ?? ''),
            'score'       => (string) (int) $run['score'],
            'grade'       => (string) ($run['grade'] ?? ''),
            'lead_code'   => (string) ($contact['code'] ?? ''),
            'stage'       => !empty($contact['stage_id']) ? (string) db_val("SELECT name FROM crm_stages WHERE id=?", [(int) $contact['stage_id']]) : '',
            'owner'       => !empty($contact['owner_user_id']) && function_exists('crm_user_name') ? crm_user_name((int) $contact['owner_user_id']) : '',
            'flow'        => (string) $flow['name'],
            'trigger'     => (string) (auto_trigger_kinds()[(string) ($run['trigger_kind'] ?? '')][0] ?? ($run['trigger_kind'] ?? 'WhatsApp')),
            default       => '',
        };
    }
    return $out;
}

/**
 * The Export data step. Never fatal: a sheet that refuses or a webhook that is down must not end
 * the conversation — the row is kept on the flow's Data page either way, with how each send went.
 */
function auto_export_data(array $client, array $flow, array $run, array $step, array $contact, array &$ctx, array $cfg): void
{
    if (auto_dry_run()) return;
    // A phone number they gave in the conversation goes on the contact first, so it is in the export.
    $answers0 = (array) ($ctx['fields'] ?? []);
    if (!empty($cfg['to_crm']) && !empty($answers0['phone']) && empty($contact['phone_e164']) && function_exists('social_attach_phone')) {
        $j = social_attach_phone($client, (int) $contact['id'], (string) $answers0['phone']);
        if ($j['ok']) $contact = db_row("SELECT * FROM contacts WHERE id=?", [(int) $j['contact_id']]) ?: $contact;
    }
    $data = auto_export_values($client, $flow, $run, $contact, $ctx, $cfg);
    $done = [];

    if (!empty($cfg['to_sheet']) && trim((string) ($cfg['sheet_id'] ?? '')) !== '') {
        require_once __DIR__ . '/google.php';
        $r = google_connected($client)
            ? google_sheet_append($client, trim((string) $cfg['sheet_id']), (string) ($cfg['sheet_tab'] ?? ''), array_keys($data), [array_values($data)])
            : ['ok' => false, 'error' => 'Google is not connected'];
        $done[] = 'sheet:' . ($r['ok'] ? 'ok' : 'failed');
        if (!$r['ok']) $ctx['export_error'] = 'Sheet: ' . ($r['error'] ?? '');
    }
    if (!empty($cfg['to_crm']) && function_exists('crm_enabled') && crm_enabled($client)) {
        if ($contact['stage_id'] === null) {
            $src = (string) ($run['channel'] ?? 'whatsapp') === 'instagram' ? 'instagram_dm' : ((string) ($run['channel'] ?? '') === 'messenger' ? 'messenger' : ((string) ($contact['source'] ?: 'inbound')));
            crm_add_lead($client, (int) $contact['id'], $src, 'auto');
        }
        // Captured answers that are lead fields or the account's own fields fill them; everything goes in a note.
        $answers = (array) ($ctx['fields'] ?? []);
        foreach (['email' => 'email', 'budget' => 'budget', 'unit_type' => 'unit_type'] as $k => $col) {
            if (!empty($answers[$k]) && db_has_column('contacts', $col)) db_run("UPDATE contacts SET `$col`=COALESCE(NULLIF(`$col`,''), ?) WHERE id=?", [mb_substr((string) $answers[$k], 0, 160), (int) $contact['id']]);
        }
        if (!empty($answers['name']) && trim((string) $contact['name']) === '') db_run("UPDATE contacts SET name=? WHERE id=?", [mb_substr((string) $answers['name'], 0, 160), (int) $contact['id']]);
        if (function_exists('crm_custom_save')) {
            $cf = [];
            foreach (crm_fields((int) $client['id']) as $f) if (isset($answers[$f['fkey']])) $cf[$f['fkey']] = (string) $answers[$f['fkey']];
            if ($cf) crm_custom_save((int) $client['id'], (int) $contact['id'], $cf);
        }
        if (function_exists('crm_add_note')) {
            crm_add_note($client, (int) $contact['id'], 'From the automation "' . $flow['name'] . "\":\n"
                . implode("\n", array_map(fn($k, $v) => $k . ': ' . $v, array_keys($data), $data)));
        }
        $done[] = 'crm:ok';
    }
    if (($url = trim((string) ($cfg['webhook_url'] ?? ''))) !== '') {
        $body = json_encode(['event' => 'automation.export', 'automation' => ['id' => (int) $flow['id'], 'name' => $flow['name']],
                             'contact_id' => (int) $contact['id'], 'channel' => (string) ($run['channel'] ?? 'whatsapp'), 'data' => $data,
                             'sent_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, (string) ($cfg['secret'] ?? ''));
        $r = safe_http_request('POST', $url, $body, ['Content-Type: application/json', 'X-Revenect-Timestamp: ' . $ts, 'X-Revenect-Signature: ' . $sig]);
        $done[] = 'webhook:' . (!empty($r['ok']) ? 'ok' : 'failed');
    }
    $emails = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($cfg['emails'] ?? ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    if ($emails) {
        $appName = function_exists('brand_name') ? brand_name() : 'Revenect';
        $text = implode("\n", array_map(fn($k, $v) => $k . ': ' . $v, array_keys($data), $data));
        foreach (array_slice($emails, 0, 5) as $to) {
            @mail($to, $appName . ' — ' . $flow['name'], $text, "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: {$appName} <noreply@gildana.net>\r\n");
        }
        $done[] = 'email:' . count($emails);
    }
    try {
        db_insert("INSERT INTO flow_exports (client_id,flow_id,step_id,run_id,contact_id,channel,data,sent_to,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())",
                  [(int) $client['id'], (int) $flow['id'], (int) $step['id'], (int) $run['id'], (int) $contact['id'], (string) ($run['channel'] ?? 'whatsapp'),
                   json_encode($data, JSON_UNESCAPED_UNICODE), mb_substr(implode(',', $done), 0, 120)]);
    } catch (Throwable $e) { error_log('flow export not kept: ' . $e->getMessage()); }
}
require_once __DIR__ . '/social.php';      // routed sends (Messenger / Instagram) need it wherever a run resumes
