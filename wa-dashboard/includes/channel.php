<?php
declare(strict_types=1);

/**
 * Sending-channel dispatcher.
 *
 * A client sends either through Meta's Cloud API ('cloud', the default) or through their
 * own PERSONAL WhatsApp number on a gateway ('personal'). Every module calls the
 * channel_* functions here instead of wa_send_* directly, so neither the automation
 * engine, the Inbox nor the campaign worker has to know which channel it is talking to.
 *
 * Contract: every channel_send_* returns the SAME array shape as wa_send_*
 *   ['ok'=>bool, 'wamid'=>?string, 'error_code'=>?string, 'error_title'=>?string]
 * so auto_send(), msg_log(), credit reserve/refund and delivery-status handling all keep
 * working unchanged.
 */

require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/personal_wa.php';

/** 'cloud' | 'personal' */
function channel_of(array $client): string
{
    return ($client['channel'] ?? 'cloud') === 'personal' ? 'personal' : 'cloud';
}

function channel_is_personal(array $client): bool
{
    return channel_of($client) === 'personal';
}

/** Human label for the UI. */
function channel_label(array $client): string
{
    return channel_is_personal($client) ? 'Personal number' : 'WhatsApp Cloud API';
}

/**
 * Can this client send at all right now? Returns '' when ready, else the reason.
 * (Pacing is handled separately by slot_budget(); this is about credentials/session.)
 */
function channel_blocked_reason(array $client): string
{
    if (channel_is_personal($client)) {
        return ($client['personal_status'] ?? '') === 'connected'
            ? '' : 'WhatsApp number is not connected — scan the QR in Settings.';
    }
    return ($client['access_token_enc'] && $client['phone_number_id'])
        ? '' : 'Missing WhatsApp access token or phone number ID.';
}

/* ── sends ── */

function channel_send_text(array $client, string $to, string $body): array
{
    return channel_is_personal($client)
        ? pw_send_text($client, $to, $body)
        : wa_send_text($client, $to, $body);
}

function channel_send_image(array $client, string $to, string $link, string $caption = ''): array
{
    return channel_is_personal($client)
        ? pw_send_image($client, $to, $link, $caption)
        : wa_send_image($client, $to, $link, $caption);
}

/**
 * Reply buttons. A personal-number session has no interactive-button API, so the buttons
 * degrade to a numbered list in the text and the engine's existing free-text matching
 * (see automation_handle_inbound) resolves the customer's answer.
 */
function channel_send_buttons(array $client, string $to, string $body, array $buttons): array
{
    if (!channel_is_personal($client)) {
        return wa_send_buttons($client, $to, $body, $buttons);
    }
    $lines = [];
    foreach (array_slice(array_values($buttons), 0, 3) as $i => $b) {
        $lines[] = ($i + 1) . '. ' . (string) ($b['title'] ?? ('Option ' . ($i + 1)));
    }
    $text = $body . ($lines ? "\n\n" . implode("\n", $lines) : '');
    return pw_send_text($client, $to, $text);
}

/**
 * A tappable list of up to 10 options.
 *
 * On the Cloud API this is a real WhatsApp list message. A personal number has no interactive
 * messages at all — WhatsApp does not offer them to unofficial clients — so it degrades to a
 * numbered list, which the engine's free-text matching already resolves. Same node either way.
 */
function channel_send_list(array $client, string $to, string $body, string $buttonText, array $rows, string $header = ''): array
{
    if (!channel_is_personal($client)) {
        return wa_send_list($client, $to, $body, $buttonText, $rows, $header);
    }
    $lines = [];
    foreach (array_slice(array_values($rows), 0, 10) as $i => $r) {
        $t = (string) ($r['title'] ?? ('Option ' . ($i + 1)));
        $d = trim((string) ($r['description'] ?? ''));
        $lines[] = ($i + 1) . '. ' . $t . ($d !== '' ? ' — ' . $d : '');
    }
    return pw_send_text($client, $to, $body . ($lines ? "\n\n" . implode("\n", $lines) : ''));
}

/**
 * Send an approved template.
 *
 * Cloud: the real template message, with the full component payload.
 * Personal: there are no approved templates and no 24-hour window, so the template's BODY
 * text is rendered with its variables filled in and sent as an ordinary message. A media
 * header becomes an image send with that text as the caption — which is why campaigns and
 * the Lead Qualifier keep working on this channel with no UI changes.
 *
 * @param array $tpl      templates row (needs wa_name, language, components, body_text)
 * @param array $cfg      saved field config (vars / header_media / buttons …)
 * @param array $contact  contact row, for name/attribute-sourced variables
 * @param callable|null $resolver overrides variable resolution (campaigns pass their own)
 */
function channel_send_template(array $client, string $to, array $tpl, array $cfg, array $contact, ?callable $resolver = null): array
{
    $components = json_decode((string) ($tpl['components'] ?? ''), true) ?: [];

    if (!channel_is_personal($client)) {
        $built = wa_build_components($components, $cfg, $contact, $client, $resolver);
        return wa_send_template($client, $to, (string) $tpl['wa_name'], (string) $tpl['language'], $built);
    }

    $text = channel_render_template_text($components, $cfg, $contact, $resolver, (string) ($tpl['body_text'] ?? ''));

    // A media header still carries the image; the body rides along as the caption.
    $spec = wa_template_spec($components);
    $hf   = strtoupper((string) ($spec['header']['format'] ?? ''));
    $media = trim((string) ($cfg['header_media'] ?? ''));
    if ($media !== '' && in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
        return pw_send_image($client, $to, $media, $text);
    }
    return pw_send_text($client, $to, $text);
}

/**
 * Render a template to plain text for the personal channel: header text + body, with
 * {{1}}, {{2}} … substituted using the same resolvers the Cloud path uses, so a contact
 * sees identical wording on either channel.
 */
function channel_render_template_text(array $components, array $cfg, array $contact, ?callable $resolver = null, string $fallbackBody = ''): string
{
    if ($resolver === null) {
        $resolver = function (array $s, array $c): string {
            return function_exists('auto_resolve_var') ? auto_resolve_var($s, $c) : (string) ($s['value'] ?? '');
        };
    }
    $pick = function ($bag, $i) {
        if (!is_array($bag)) return [];
        return (array) ($bag[(string) $i] ?? $bag[$i] ?? []);
    };
    $fill = function (string $text, array $bag) use ($resolver, $contact, $pick): string {
        return (string) preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', function ($m) use ($resolver, $contact, $bag, $pick) {
            $v = $resolver($pick($bag, (int) $m[1]), $contact);
            return $v === '-' ? '' : $v;   // '-' is the Cloud API's empty-param filler
        }, $text);
    };

    $headerText = '';
    $bodyText   = $fallbackBody;
    foreach ($components as $c) {
        $type = strtoupper((string) ($c['type'] ?? ''));
        if ($type === 'HEADER' && strtoupper((string) ($c['format'] ?? 'TEXT')) === 'TEXT') {
            $headerText = $fill((string) ($c['text'] ?? ''), (array) ($cfg['header_vars'] ?? []));
        } elseif ($type === 'BODY') {
            $bodyText = (string) ($c['text'] ?? $fallbackBody);
        } elseif ($type === 'FOOTER') {
            // footers are decoration on Cloud; skip rather than clutter a plain message
        }
    }
    $bodyText = $fill($bodyText, (array) ($cfg['vars'] ?? []));

    $out = trim($headerText) !== '' ? trim($headerText) . "\n\n" . trim($bodyText) : trim($bodyText);
    return trim($out);
}

/* ─────────────────────────────────────────────
   Slot pacing (personal channel only)

   A personal number gets banned for sending fast and uniformly, so this channel sends in
   SLOTS: at most slot_size messages (default 15), then a slot_pause_sec cooldown (default
   180s) before the next slot.

   Two rules make it actually work:
     1. ONE budget per client per run, shared by campaigns, qualifier outreach and
        automation steps — otherwise three modules would each send a full slot.
     2. The pause is state-based (clients.next_slot_at), never sleep(): the worker holds
        GET_LOCK('wa_dispatch'), so sleeping would stall every other client's sending.
───────────────────────────────────────────── */

/** In-process budget, keyed by client id — one slot per worker run. */
function &slot_state(): array
{
    static $state = [];
    return $state;
}

/**
 * How many messages this client may still send in this run.
 * Cloud clients are unlimited here (their throughput is governed by send_batch_* caps).
 */
function slot_budget(array $client): int
{
    if (!channel_is_personal($client)) return PHP_INT_MAX;

    $state = &slot_state();
    $cid   = (int) $client['id'];
    if (!array_key_exists($cid, $state)) {
        $next = $client['next_slot_at'] ?? null;
        $cooling = $next !== null && strtotime((string) $next) > time();
        $state[$cid] = [
            'left' => $cooling ? 0 : max(1, (int) ($client['slot_size'] ?: 15)),
            'used' => 0,
        ];
    }
    return (int) $state[$cid]['left'];
}

/** Record that $n messages were sent, decrementing this run's budget. */
function slot_consume(array $client, int $n = 1): void
{
    if (!channel_is_personal($client) || $n < 1) return;
    $state = &slot_state();
    $cid   = (int) $client['id'];
    slot_budget($client);                       // ensure initialised
    $state[$cid]['left'] = max(0, $state[$cid]['left'] - $n);
    $state[$cid]['used'] += $n;
}

/**
 * Close the slot: if anything was sent this run, start the cooldown so the worker skips
 * this client until it elapses. Call once at the end of a worker run.
 */
function slot_close(array $client): void
{
    if (!channel_is_personal($client)) return;
    $state = &slot_state();
    $cid   = (int) $client['id'];
    if (empty($state[$cid]['used'])) return;
    $pause = max(1, (int) ($client['slot_pause_sec'] ?: 180));
    db_run("UPDATE clients SET next_slot_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?", [$pause, $cid]);
    $state[$cid]['used'] = 0;
    $state[$cid]['left'] = 0;
}

/** Is this client in a cooldown right now? (for the UI / diagnostics) */
function slot_cooling(array $client): bool
{
    $next = $client['next_slot_at'] ?? null;
    return channel_is_personal($client) && $next !== null && strtotime((string) $next) > time();
}

/**
 * Human-paced gap between two sends on a personal number. A uniform burst is the pattern
 * that gets numbers flagged, so the delay is randomised rather than fixed.
 * Configurable (slot_gap_min_ms / slot_gap_max_ms) to tune pacing per deployment — and so
 * the test suite can run without waiting minutes.
 */
function slot_pace_sleep(): void
{
    $min = max(0, (int) config('slot_gap_min_ms', 2000));
    $max = max($min, (int) config('slot_gap_max_ms', 6000));
    if ($max <= 0) return;
    usleep(random_int($min, $max) * 1000);
}
