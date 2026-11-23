<?php
declare(strict_types=1);
/**
 * Website chat — an AI Chat Agent on the client's own website and landing pages.
 *
 * A widget is one embed: its own title, colours, welcome words, quick actions and the agent that
 * answers. One script line puts a chat bubble on a page; an iframe puts the chat inside a section;
 * the JSON API (widget_api.php) lets a developer build their own. A visitor is a contact with a
 * random id kept by their browser (web_vid) and no phone number until they give one.
 *
 * Every message is stored on channel 'web', so the chat shows in the Inbox next to WhatsApp and
 * Messenger, a person can take over from there, and the agent's runs, captured fields and report
 * work as on any channel: the automation engine sends through channel_send_text() as always, and
 * while the route is 'web' that send just stores the message for the visitor's browser to fetch.
 */

require_once __DIR__ . '/inbox.php';
require_once __DIR__ . '/push.php';

const WEBCHAT_MAX_PER_MIN  = 12;   // messages one visitor may send in a minute
const WEBCHAT_NEW_PER_HOUR = 40;   // new visitors one address may start in an hour

function webchat_ready(): bool
{
    static $ok = null;
    if ($ok === null) { try { $ok = db_has_column('contacts', 'web_vid') && (bool) db_val("SHOW TABLES LIKE 'web_widgets'"); } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

/** Everything a widget can be told, with what it uses when nothing is set. */
function webchat_defaults(): array
{
    return [
        'title'        => 'Chat with us',
        'subtitle'     => 'We usually answer right away',
        'avatar'       => '',                 // picture URL; empty = the first letter of the title
        'color'        => '#0F766E',          // header, bubble, the visitor's messages
        'text_color'   => '#FFFFFF',          // on that colour
        'position'     => 'right',            // right | left (for right-to-left pages it is mirrored by the page)
        'dir'          => 'auto',             // auto | rtl | ltr
        'welcome'      => "Hi! 👋 How can we help you today?",   // first message, shown by the widget itself
        'teaser'       => '',                 // the small note next to the bubble before it is opened
        'placeholder'  => 'Type a message…',
        'actions'      => [],                 // quick actions: [{label, send?, url?, image?}]
        'ask_details'  => 'off',              // off | optional | required — name and phone before chatting
        'details_note' => 'Leave your name and number so we can follow up.',
        'add_lead'     => 1,                  // a visitor who gives a phone number becomes a CRM lead
        'auto_open'    => 0,                  // seconds after the page loads; 0 = never by itself
        'branding'     => 1,                  // "Powered by Revenect" under the box
        'offline_note' => 'Thanks! We got your message and will reply here shortly.',
    ];
}

/** Keys a page may change for itself in the embed code (data-* attributes / the iframe URL). */
function webchat_overridable(): array
{
    return ['title', 'subtitle', 'avatar', 'color', 'text_color', 'position', 'dir', 'welcome', 'teaser', 'placeholder', 'auto_open', 'context'];
}

/** Clean one settings array (from the editor or a page's overrides). Unknown keys are dropped. */
function webchat_clean(array $in, bool $partial = false): array
{
    $d = webchat_defaults(); $out = [];
    $str = fn($v, int $max) => mb_substr(trim((string) $v), 0, $max);
    $color = fn($v) => preg_match('/^#[0-9a-fA-F]{6}$/', trim((string) $v)) ? strtoupper(trim((string) $v)) : null;
    $url = function ($v) { $v = trim((string) $v); return preg_match('#^https://[^\s"\'<>]{3,490}$#i', $v) ? $v : ''; };
    foreach ($in as $k => $v) {
        switch ($k) {
            case 'title': case 'subtitle': case 'teaser': case 'placeholder': $out[$k] = $str($v, 120); break;
            case 'welcome': case 'details_note': case 'offline_note': $out[$k] = $str($v, 600); break;
            case 'avatar': $out[$k] = $url($v); break;
            case 'color': case 'text_color': if (($c = $color($v)) !== null) $out[$k] = $c; break;
            case 'position': $out[$k] = $v === 'left' ? 'left' : 'right'; break;
            case 'dir': $out[$k] = in_array($v, ['rtl', 'ltr'], true) ? $v : 'auto'; break;
            case 'ask_details': $out[$k] = in_array($v, ['optional', 'required'], true) ? $v : 'off'; break;
            case 'add_lead': case 'branding': $out[$k] = (int) (bool) $v; break;
            case 'auto_open': $out[$k] = max(0, min(600, (int) $v)); break;
            case 'context': $out[$k] = $str($v, 80); break;
            case 'actions':
                $acts = [];
                foreach (array_slice((array) $v, 0, 8) as $a) {
                    $a = (array) $a;
                    $label = $str($a['label'] ?? '', 40);
                    if ($label === '') continue;
                    $acts[] = array_filter(['label' => $label, 'send' => $str($a['send'] ?? '', 300), 'url' => $url($a['url'] ?? ''), 'image' => $url($a['image'] ?? '')], 'strlen');
                }
                $out[$k] = $acts;
                break;
        }
    }
    return $partial ? $out : array_merge($d, $out);
}

function webchat_settings(array $widget): array
{
    return webchat_clean(json_decode((string) ($widget['settings'] ?? ''), true) ?: []);
}

function webchat_new_key(): string
{
    return 'w' . bin2hex(random_bytes(12));
}

function webchat_widget_by_key(string $key): ?array
{
    if (!preg_match('/^w[a-f0-9]{24}$/', $key) || !webchat_ready()) return null;
    return db_row("SELECT * FROM web_widgets WHERE public_key=?", [$key]);
}

function webchat_widgets(int $clientId, ?int $flowId = null): array
{
    if (!webchat_ready()) return [];
    return $flowId === null
        ? db_all("SELECT * FROM web_widgets WHERE client_id=? ORDER BY id DESC", [$clientId])
        : db_all("SELECT * FROM web_widgets WHERE client_id=? AND flow_id=? ORDER BY id DESC", [$clientId, $flowId]);
}

/** The sites a widget may sit on: hosts, one per line. "*.example.com" takes every sub-domain. */
function webchat_domains(array $widget): array
{
    $out = [];
    foreach (preg_split('/[\s,]+/', strtolower((string) ($widget['allowed_domains'] ?? ''))) as $d) {
        $d = preg_replace('#^https?://#', '', trim($d));
        $d = explode('/', $d)[0];
        if ($d !== '' && (preg_match('/^(\*\.)?([a-z0-9-]+\.)+[a-z0-9-]+(:\d+)?$/', $d) || preg_match('/^localhost(:\d+)?$/', $d))) $out[] = $d;
    }
    return array_values(array_unique($out));
}

/** May a page on $origin (scheme://host[:port]) use this widget? Revenect itself always may (the preview). */
function webchat_origin_ok(array $widget, string $origin): bool
{
    if ($origin === '') return true;                          // not a browser call from another site
    $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $port = parse_url($origin, PHP_URL_PORT);
    if ($host === '') return false;
    $own = strtolower((string) parse_url(app_base_url(), PHP_URL_HOST));
    if ($host === $own) return true;
    $doms = webchat_domains($widget);
    if (!$doms) return true;
    $hp = $host . ($port ? ':' . $port : '');
    foreach ($doms as $d) {
        if ($d === $host || $d === $hp) return true;
        if (str_starts_with($d, '*.') && (str_ends_with($host, substr($d, 1)) || $host === substr($d, 2))) return true;
    }
    return false;
}

/** The frame-ancestors list for the chat page: Revenect plus the widget's sites (any site when none is set). */
function webchat_frame_ancestors(array $widget): string
{
    $doms = webchat_domains($widget);
    if (!$doms) return '*';
    $list = ["'self'"];
    foreach ($doms as $d) { $list[] = 'https://' . $d; $list[] = 'http://' . $d; }
    return implode(' ', $list);
}

/** The visitor's address, for rate limits only (stored hashed). Behind the proxy the real one is in X-Forwarded-For. */
function webchat_ip_hash(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $fwd = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
    if ($fwd !== '' && (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) $ip = $fwd;
    return substr(hash('sha256', 'wc|' . $ip . '|' . (string) config('webhook_verify_token', '')), 0, 24);
}

/** A simple fixed-window counter in app_settings. True while under $max for this window. */
function webchat_bump(string $key, int $max, int $window): bool
{
    $slot = (int) floor(time() / $window);
    $k = 'wrl_' . $key;
    $v = (string) (setting_get($k) ?? '');
    [$s, $n] = array_map('intval', array_pad(explode('|', $v), 2, 0));
    $n = $s === $slot ? $n + 1 : 1;
    try { setting_set($k, $slot . '|' . $n); } catch (Throwable $e) {}
    return $n <= $max;
}

/** Find the visitor, or make them a contact. Returns [contact|null, error]. */
function webchat_contact(array $client, array $widget, string $vid, bool $create, string $page = '', string $label = ''): array
{
    $cid = (int) $client['id'];
    $c = db_row("SELECT * FROM contacts WHERE client_id=? AND web_vid=?", [$cid, $vid]);
    if ($c || !$create) return [$c, ''];
    if (!webchat_bump('new_' . webchat_ip_hash(), WEBCHAT_NEW_PER_HOUR, 3600)) return [null, 'Too many new chats from this connection. Try again later.'];
    try {
        $id = db_insert("INSERT INTO contacts (client_id,phone_e164,web_vid,web_widget_id,web_page,name,opt_in_status,source,platform,created_at)
                         VALUES (?,NULL,?,?,?,?,'in','website','website',NOW())",
                        [$cid, $vid, (int) $widget['id'], $page !== '' ? mb_substr($page, 0, 500) : null, 'Website visitor']);
    } catch (Throwable $e) {
        $c = db_row("SELECT * FROM contacts WHERE client_id=? AND web_vid=?", [$cid, $vid]);
        if ($c) return [$c, ''];
        error_log('webchat contact: ' . $e->getMessage());
        return [null, 'Could not start the chat.'];
    }
    if (db_has_column('contacts', 'code') && function_exists('crm_code')) db_run("UPDATE contacts SET code=COALESCE(code, ?) WHERE id=?", [crm_code($id), $id]);
    // Name them after the page they came from until they say who they are: "Visitor · Porto Golf".
    $label = mb_substr(trim(strip_tags($label)), 0, 80) ?: (string) $widget['name'];
    db_run("UPDATE contacts SET name=? WHERE id=?", [mb_substr('Visitor ' . $id . ' · ' . $label, 0, 160), $id]);
    return [db_row("SELECT * FROM contacts WHERE id=?", [$id]), ''];
}

/** What the visitor's browser shows: the web messages after $after, oldest first. */
function webchat_messages(array $contact, int $after = 0, int $limit = 100): array
{
    $rows = db_all("SELECT m.id, m.direction, m.type, m.body, m.created_at, m.ext_id, m.source
                      FROM messages m
                     WHERE m.client_id=? AND m.contact_id=? AND m.channel='web' AND m.id>? AND COALESCE(m.status,'')<>'failed'
                     ORDER BY m.id LIMIT " . max(1, min(200, $limit)),
                   [(int) $contact['client_id'], (int) $contact['id'], $after]);
    // Choices and pictures sent with a message (looked up by id, not joined: the tables' collations may differ).
    $ext = array_values(array_filter(array_column($rows, 'ext_id')));
    $extras = $ext ? array_column(db_all("SELECT ext_id, data FROM web_message_extras WHERE ext_id IN (" . implode(',', array_fill(0, count($ext), '?')) . ")", $ext), 'data', 'ext_id') : [];
    $out = [];
    foreach ($rows as $r) {
        $x = json_decode((string) ($extras[(string) $r['ext_id']] ?? ''), true) ?: [];
        $out[] = array_filter([
            'id' => (int) $r['id'], 'from' => $r['direction'] === 'in' ? 'visitor' : ($r['source'] === 'manual' ? 'agent' : 'bot'),
            'text' => (string) $r['body'], 'at' => strtotime((string) $r['created_at']) * 1000,
            'choices' => $x['choices'] ?? null, 'image' => $x['image'] ?? null,
        ], fn($v) => $v !== null);
    }
    // Everything the visitor's browser just received has been seen.
    $ids = array_column(array_filter($out, fn($m) => $m['from'] !== 'visitor'), 'id');
    if ($ids) db_run("UPDATE messages SET status='read' WHERE id IN (" . implode(',', array_map('intval', $ids)) . ") AND status IN ('sent','delivered')");
    return $out;
}

/** An automation step (or the agent) "sending" on the website: stored for the browser to fetch. */
function webchat_routed(string $kind, array $args): array
{
    $ext = 'web.' . bin2hex(random_bytes(8));
    $extra = [];
    if ($kind === 'image') $extra['image'] = (string) $args[0];
    if ($kind === 'buttons' && trim((string) ($args[2] ?? '')) !== '') $extra['image'] = (string) $args[2];
    if ($kind === 'buttons' || $kind === 'list') {
        $items = $kind === 'buttons' ? (array) $args[1] : (array) $args[2];
        foreach (array_values($items) as $i => $b) $extra['choices'][] = ['id' => (string) ($b['id'] ?? ('b' . $i)), 'title' => mb_substr((string) ($b['title'] ?? ('Option ' . ($i + 1))), 0, 60)];
    }
    if ($extra) { try { db_run("INSERT INTO web_message_extras (ext_id,data,created_at) VALUES (?,?,NOW())", [$ext, json_encode($extra, JSON_UNESCAPED_UNICODE)]); } catch (Throwable $e) {} }
    return ['ok' => true, 'wamid' => $ext, 'error_code' => '', 'error_title' => null];
}

/** A reply typed in the Inbox to a website visitor. Free: nothing leaves through Meta. */
function inbox_send_web(array $client, int $contactId, string $body): array
{
    $body = trim($body);
    if ($body === '') return ['ok' => false, 'error' => 'Type a message first.'];
    $contact = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$contactId, (int) $client['id']]);
    if (!$contact || empty($contact['web_vid'])) return ['ok' => false, 'error' => 'This person has not chatted on your website.'];
    $who = function_exists('sending_user') ? sending_user() : [];
    $id = msg_log((int) $client['id'], $contactId, 'out', mb_substr($body, 0, 4000), [
        'source' => 'manual', 'status' => 'sent', 'sent_by' => (int) ($who['id'] ?? 0),
        'channel' => 'web', 'ext' => 'web.' . bin2hex(random_bytes(8))]);
    inbox_take_over((int) $client['id'], $contactId);
    return ['ok' => true, 'error' => '', 'id' => $id];
}

/** Name and phone from the widget's form. The phone joins them to the contact with that number, if any. */
function webchat_identify(array $client, array $widget, array $contact, string $name, string $phone): array
{
    $name = mb_substr(trim(strip_tags($name)), 0, 120);
    $cid = (int) $contact['id'];
    if ($name !== '') db_run("UPDATE contacts SET name=? WHERE id=?", [$name, $cid]);
    if (trim($phone) !== '') {
        require_once __DIR__ . '/social.php';
        $j = social_attach_phone($client, $cid, $phone);
        if (!$j['ok']) return ['ok' => false, 'error' => $j['error'] ?? 'Check the number.'];
        $cid = (int) $j['contact_id'];
        if ($name !== '') db_run("UPDATE contacts SET name=? WHERE id=? AND (name IS NULL OR name='' OR name LIKE 'Visitor %')", [$name, $cid]);
        $s = webchat_settings($widget);
        if ($s['add_lead'] && function_exists('crm_enabled') && crm_enabled($client) && function_exists('crm_add_lead')) {
            $c = db_row("SELECT stage_id FROM contacts WHERE id=?", [$cid]);
            if ($c && $c['stage_id'] === null) { try { crm_add_lead($client, $cid, 'website', 'auto'); } catch (Throwable $e) { error_log('webchat lead: ' . $e->getMessage()); } }
        }
    }
    return ['ok' => true, 'contact' => db_row("SELECT * FROM contacts WHERE id=?", [$cid])];
}

/**
 * The visitor wrote. Stored on channel 'web', then answered: the conversation already going on
 * carries on; otherwise the widget's agent starts. A person who took over from the Inbox answers
 * instead, and the agent stays quiet.
 */
function webchat_inbound(array $client, array $widget, array $contact, string $text, string $buttonId = '', string $page = ''): array
{
    $cid = (int) $contact['id'];
    if (!webchat_bump('msg_' . $cid, WEBCHAT_MAX_PER_MIN, 60)) return ['ok' => false, 'error' => 'You are sending messages too fast. Wait a moment.'];
    $text = mb_substr(trim($text), 0, 2000);
    if ($text === '' && $buttonId === '') return ['ok' => false, 'error' => 'Type a message first.'];
    $id = msg_log((int) $client['id'], $cid, 'in', $text !== '' ? $text : $buttonId, ['source' => 'inbound', 'channel' => 'web', 'ext' => 'web.' . bin2hex(random_bytes(8))]);
    db_run("UPDATE contacts SET web_last_in_at=NOW(), web_widget_id=?" . ($page !== '' ? ", web_page=?" : '') . " WHERE id=?",
           array_merge([(int) $widget['id']], $page !== '' ? [mb_substr($page, 0, 500)] : [], [$cid]));
    if (function_exists('push_queue_client')) { try { push_queue_client((int) $client['id']); } catch (Throwable $e) {} }

    $contact = db_row("SELECT * FROM contacts WHERE id=?", [$cid]);
    webchat_answer($client, $widget, $contact, $text, $buttonId);
    return ['ok' => true, 'id' => $id];
}

function webchat_answer(array $client, array $widget, array $contact, string $text, string $buttonId): string
{
    require_once __DIR__ . '/automation.php';
    auto_replying(true);
    try {
        if (($contact['opt_in_status'] ?? '') === 'out') return 'opted_out';
        $run = auto_active_run((int) $contact['id']);
        if (auto_bot_paused($contact) || $run) {
            $reason = auto_inbound_decide($client, $contact, ['text' => $text, 'button_id' => $buttonId], 'web');
        } else {
            $flow = $widget['flow_id'] ? auto_flow((int) $widget['flow_id']) : null;
            if (!$flow || (int) $flow['client_id'] !== (int) $client['id']) $reason = 'no_flow|This website chat has no agent';
            elseif ($flow['status'] !== 'active') $reason = 'no_flow|The agent "' . $flow['name'] . '" is switched off';
            elseif (!$flow['first_step_id']) $reason = 'flow_broken|The agent has no knowledge saved yet';
            elseif (db_val("SELECT 1 FROM flow_runs WHERE contact_id=? AND flow_id=? AND status='completed' AND updated_at > NOW() - INTERVAL 30 MINUTE LIMIT 1", [(int) $contact['id'], (int) $flow['id']])) {
                // The agent finished this chat a moment ago (its reply limit): a person takes it from the Inbox.
                $reason = 'no_flow|The agent already finished this chat — it waits in the Inbox';
            } else {
                auto_start($client, $contact, $flow, $text !== '' ? $text : $buttonId, [], 'web', ['trigger_kind' => 'web:' . (int) $widget['id']]);
                $reason = 'started|Website: ' . $flow['name'];
            }
        }
        [$decision, $detail] = array_pad(explode('|', $reason, 2), 2, '');
        if (function_exists('auto_note_inbound')) auto_note_inbound($client, $contact, $text, $decision, 'Website: ' . $detail);
        return $reason;
    } catch (Throwable $e) {
        error_log('webchat answer: ' . $e->getMessage());
        return 'error|' . $e->getMessage();
    } finally {
        if (function_exists('channel_route')) channel_route(null, true);
        auto_replying(false);
    }
}

/** Website-chat figures for an agent's report: per widget and overall, over $days. */
function webchat_stats(int $clientId, int $flowId, int $days = 30): array
{
    if (!webchat_ready()) return ['widgets' => [], 'total' => []];
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    $per = db_all("SELECT w.id, w.name, w.status,
                          (SELECT COUNT(DISTINCT m.contact_id) FROM messages m JOIN contacts c ON c.id=m.contact_id
                            WHERE m.client_id=w.client_id AND m.channel='web' AND m.direction='in' AND c.web_widget_id=w.id AND m.created_at>=?) visitors,
                          (SELECT COUNT(*) FROM messages m JOIN contacts c ON c.id=m.contact_id
                            WHERE m.client_id=w.client_id AND m.channel='web' AND m.direction='in' AND c.web_widget_id=w.id AND m.created_at>=?) msgs_in,
                          (SELECT COUNT(*) FROM contacts c WHERE c.client_id=w.client_id AND c.web_widget_id=w.id AND c.phone_e164 IS NOT NULL AND c.web_last_in_at>=?) with_phone,
                          (SELECT COUNT(*) FROM flow_runs r WHERE r.client_id=w.client_id AND r.trigger_kind=CONCAT('web:', w.id) AND r.created_at>=?) chats
                     FROM web_widgets w WHERE w.client_id=? AND w.flow_id=? ORDER BY w.id DESC",
                  [$since, $since, $since, $since, $clientId, $flowId]);
    $t = ['visitors' => 0, 'msgs_in' => 0, 'with_phone' => 0, 'chats' => 0];
    foreach ($per as $p) foreach ($t as $k => $_) $t[$k] += (int) $p[$k];
    return ['widgets' => $per, 'total' => $t];
}
