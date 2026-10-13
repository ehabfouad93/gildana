<?php
declare(strict_types=1);

/**
 * WhatsApp automations over the Meta WhatsApp Cloud API.
 *
 * Businesses may only start a conversation with an *approved template*, so an
 * automation is: "when a client reaches <trigger>, send template <name> in
 * <language>, filling {{1}}, {{2}}… with these variables". The admin picks the
 * template from the list Meta returns for the WhatsApp Business Account.
 *
 * Sending never blocks the pipeline: a failure is logged (Admin → WhatsApp →
 * Log, with a resend button) and the hand-off carries on.
 */

/** Events that can send a message. key => label key. */
function wa_triggers(): array
{
    return [
        'created'        => 'wa.trg.created',
        'booked'         => 'wa.trg.booked',
        'confirmed'      => 'wa.trg.confirmed',
        'sent_back'      => 'wa.trg.sent_back',
        'arrived'        => 'wa.trg.arrived',
        'contracted'     => 'wa.trg.contracted',
        'lost'           => 'wa.trg.lost',
        'cancelled'      => 'wa.trg.cancelled',
        'payment'        => 'wa.trg.payment',
        'stay_booked'    => 'wa.trg.stay_booked',
    ];
}

/** Variables a template parameter can be filled with. */
function wa_var_keys(): array
{
    return ['name', 'client_id', 'phone', 'meeting_date', 'meeting_time', 'meeting_place', 'sales_name', 'sales_phone',
            'contract_no', 'official_name', 'project', 'total', 'down_payment', 'monthly', 'months', 'first_due',
            'next_due', 'next_amount', 'amount', 'item', 'check_in', 'check_out', 'stay_project', 'weeks',
            'company', 'company_phone'];
}

function wa_ready(): bool
{
    return setting('wa_enabled') === '1' && setting('wa_token') !== '' && setting('wa_phone_id') !== '';
}

/** Local number → international digits, e.g. 01001234567 → 201001234567. */
function wa_phone(?string $raw): string
{
    $d = preg_replace('/\D/', '', (string) $raw) ?? '';
    if ($d === '') return '';
    if (str_starts_with($d, '00')) return substr($d, 2);
    $cc = preg_replace('/\D/', '', setting('wa_country')) ?: '20';
    if (str_starts_with($d, $cc) && strlen($d) > 10) return $d;
    if (str_starts_with($d, '0')) return $cc . substr($d, 1);
    return strlen($d) <= 10 ? $cc . $d : $d;
}

/** Every variable for one client, plus event-specific extras (payment amount, stay dates…). */
function wa_vars(int $clientId, array $extra = []): array
{
    $c  = load_client($clientId) ?? [];
    $ct = db_row("SELECT ct.*, p.name AS project_name FROM contracts ct JOIN projects p ON p.id = ct.project_id WHERE ct.client_id = ?", [$clientId]);
    $salesPhone = $c && $c['sales_id'] ? (string) db_val("SELECT phone FROM users WHERE id = ?", [$c['sales_id']]) : '';
    $next = $ct ? db_row("SELECT due_date, amount - paid_amount AS left_amt FROM instalments WHERE contract_id = ? AND status <> 'paid' ORDER BY seq LIMIT 1", [$ct['id']]) : null;
    $cur  = $ct['currency'] ?? setting('currency');

    return array_merge([
        'name'          => (string) ($c['full_name'] ?? ''),
        'client_id'     => (string) $clientId,
        'phone'         => (string) ($c['phone'] ?? ''),
        'meeting_date'  => !empty($c['meeting_date']) ? fmt_date($c['meeting_date']) : '',
        'meeting_time'  => !empty($c['meeting_time']) ? fmt_time($c['meeting_time']) : '',
        'meeting_place' => (string) ($c['meeting_place'] ?? ''),
        'sales_name'    => (string) ($c['sales_name'] ?? ''),
        'sales_phone'   => $salesPhone,
        'contract_no'   => (string) ($ct['contract_no'] ?? ''),
        'official_name' => (string) ($ct['official_name'] ?? ($c['full_name'] ?? '')),
        'project'       => (string) ($ct['project_name'] ?? ''),
        'total'         => $ct ? money($ct['total_amount'], $cur) : '',
        'down_payment'  => $ct ? money($ct['down_amount'], $cur) : '',
        'monthly'       => $ct ? money($ct['monthly_amount'], $cur) : '',
        'months'        => (string) ($ct['months'] ?? ''),
        'first_due'     => $ct ? fmt_date($ct['first_due_date']) : '',
        'next_due'      => $next ? fmt_date($next['due_date']) : '',
        'next_amount'   => $next ? money($next['left_amt'], $cur) : '',
        'company'       => setting('company_name'),
        'company_phone' => setting('company_phone'),
    ], $extra);
}

/** The ordered parameter values for an automation. Meta rejects empty strings, so blanks become "-". */
function wa_params(array $keys, array $vars): array
{
    $out = [];
    foreach ($keys as $k) {
        $k = trim($k);
        if ($k === '') continue;
        $v = trim((string) ($vars[$k] ?? ''));
        $out[] = $v === '' ? '-' : mb_substr($v, 0, 1000);
    }
    return $out;
}

function wa_param_keys(?string $stored): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $stored) ?: []), 'strlen'));
}

/** One Graph API call. Returns [ok, decoded body or error text]. */
function wa_graph(string $method, string $path, ?array $body = null): array
{
    // config.php may point at a stand-in server for testing; production always uses Meta.
    $base = rtrim((string) config('wa_base_url', 'https://graph.facebook.com'), '/');
    $url  = $base . '/' . rawurlencode(setting('wa_graph') ?: 'v21.0') . '/' . ltrim($path, '/');
    $ch  = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . setting('wa_token'), 'Content-Type: application/json'],
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($body !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opt);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return [false, 'Network: ' . $err];
    $data = json_decode((string) $raw, true);
    if ($code >= 200 && $code < 300 && is_array($data)) return [true, $data];
    $msg = is_array($data) ? (string) ($data['error']['error_user_msg'] ?? $data['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
    return [false, $msg];
}

/** Send one template message. Returns [ok, message id or error]. */
function wa_send_template(string $to, string $template, string $lang, array $bodyParams, ?string $headerText = null): array
{
    $components = [];
    if ($headerText !== null) {
        $components[] = ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => $headerText]]];
    }
    if ($bodyParams) {
        $components[] = ['type' => 'body', 'parameters' => array_map(fn($p) => ['type' => 'text', 'text' => $p], $bodyParams)];
    }
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => ['name' => $template, 'language' => ['code' => $lang]] + ($components ? ['components' => $components] : []),
    ];
    [$ok, $res] = wa_graph('POST', rawurlencode(setting('wa_phone_id')) . '/messages', $payload);
    if (!$ok) return [false, (string) $res];
    return [true, (string) ($res['messages'][0]['id'] ?? '')];
}

/** Send an automation (or a manual pick) to one client and log the result. */
function wa_send_to_client(int $clientId, array $auto, array $extra = [], ?string $trigger = null): array
{
    $vars   = wa_vars($clientId, $extra);
    $to     = wa_phone($vars['phone']);
    $params = wa_params(wa_param_keys($auto['params'] ?? ''), $vars);
    $header = !empty($auto['header_param']) ? (wa_params([$auto['header_param']], $vars)[0] ?? null) : null;
    $u      = current_user();

    if (!wa_ready())      { $ok = false; $res = t('wa.not_ready'); $status = 'skipped'; }
    elseif ($to === '')   { $ok = false; $res = t('wa.no_phone');  $status = 'skipped'; }
    else {
        [$ok, $res] = wa_send_template($to, (string) $auto['template_name'], (string) $auto['language'], $params, $header);
        $status = $ok ? 'sent' : 'failed';
    }
    db_insert("INSERT INTO wa_messages (client_id, automation_id, trigger_key, to_phone, template_name, language, params_json, status, wa_message_id, error, sent_by, created_at)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())",
        [$clientId, $auto['id'] ?? null, $trigger, $to ?: '-', $auto['template_name'], $auto['language'],
         json_encode(['body' => $params, 'header' => $header], JSON_UNESCAPED_UNICODE), $status,
         $ok ? $res : null, $ok ? null : mb_substr((string) $res, 0, 500), $u['id'] ?? null]);
    return [$ok, $res];
}

/**
 * Fire every active automation for $trigger. Called after the hand-off is saved;
 * never throws, so a WhatsApp outage cannot undo or block pipeline work.
 * Returns [sent, failed].
 */
function wa_fire(string $trigger, int $clientId, array $extra = []): array
{
    $sent = $failed = 0;
    try {
        foreach (db_all("SELECT * FROM wa_automations WHERE trigger_key = ? AND active = 1 ORDER BY id", [$trigger]) as $a) {
            [$ok] = wa_send_to_client($clientId, $a, $extra, $trigger);
            $ok ? $sent++ : $failed++;
        }
    } catch (Throwable $e) {
        error_log('wa_fire(' . $trigger . '): ' . $e->getMessage());
        $failed++;
    }
    if ($sent || $failed) {
        flash($failed ? t('wa.flash_failed', ['n' => (string) $failed]) : t('wa.flash_sent', ['n' => (string) $sent]), $failed ? 'warn' : 'success');
    }
    return [$sent, $failed];
}

/**
 * Approved templates from Meta for the configured WhatsApp Business Account,
 * with how many {{n}} variables each body and header expects.
 * Returns [ok, list|error].
 */
function wa_fetch_templates(): array
{
    if (setting('wa_waba_id') === '' || setting('wa_token') === '') return [false, t('wa.need_waba')];
    [$ok, $res] = wa_graph('GET', rawurlencode(setting('wa_waba_id')) . '/message_templates?fields=name,language,status,category,components&limit=250');
    if (!$ok) return [false, (string) $res];

    $out = [];
    foreach ((array) ($res['data'] ?? []) as $t) {
        if (($t['status'] ?? '') !== 'APPROVED') continue;
        $body = ''; $bodyVars = 0; $headerVars = 0;
        foreach ((array) ($t['components'] ?? []) as $c) {
            $txt = (string) ($c['text'] ?? '');
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $txt, $m);
            $n = $m[1] ? max(array_map('intval', $m[1])) : 0;
            if (($c['type'] ?? '') === 'BODY')   { $body = $txt; $bodyVars = $n; }
            if (($c['type'] ?? '') === 'HEADER' && ($c['format'] ?? '') === 'TEXT') $headerVars = $n;
        }
        $out[] = ['name' => (string) $t['name'], 'language' => (string) $t['language'], 'category' => (string) ($t['category'] ?? ''),
                  'body' => $body, 'body_vars' => $bodyVars, 'header_vars' => $headerVars];
    }
    usort($out, fn($a, $b) => [$a['name'], $a['language']] <=> [$b['name'], $b['language']]);
    save_setting('wa_templates_cache', json_encode($out, JSON_UNESCAPED_UNICODE));
    return [true, $out];
}

function wa_cached_templates(): array
{
    $t = json_decode(setting('wa_templates_cache'), true);
    return is_array($t) ? $t : [];
}
