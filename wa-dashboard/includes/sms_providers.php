<?php
declare(strict_types=1);

/**
 * SMS providers: one function per company, behind sms_provider_send().
 *
 *   smsmisr      SMS Misr (Egypt)
 *   victorylink  Victory Link (Egypt)
 *   twilio       Twilio (international)
 *   mshastra     mShastra / Mobishastra
 *   http         any provider with an HTTP API, described by its fields (no code needed)
 *
 * Each returns ['ok' => bool, 'id' => provider message id, 'code' => provider code, 'error' => words,
 * 'retry' => bool (a temporary problem worth trying again)]. Endpoints and fields follow each
 * provider's published API; a gateway's "Test send" confirms them against the real account, and the
 * base URLs can be overridden per gateway (also how the test rig points them at its mock).
 */

/** What each provider needs on its setup form: key => [label, type (text|secret|select|textarea), options/help]. */
function sms_provider_catalog(): array
{
    return [
        'smsmisr' => ['label' => 'SMS Misr', 'fields' => [
            'username'    => ['Username', 'text', ''],
            'password'    => ['Password', 'secret', ''],
            'sender'      => ['Sender token', 'secret', 'The sender token SMS Misr gives for your approved sender name'],
            'environment' => ['Environment', 'select', ['1' => 'Live', '2' => 'Test (not delivered)']],
            'base_url'    => ['API address', 'text', 'Leave empty for https://smsmisr.com/api'],
        ]],
        'victorylink' => ['label' => 'Victory Link', 'fields' => [
            'username' => ['Username', 'text', ''],
            'password' => ['Password', 'secret', ''],
            'base_url' => ['API address', 'text', 'Leave empty for https://smsvas.vlserv.com/VLSMSPlatformResellerAPI/NewSendingAPI/api'],
        ]],
        'twilio' => ['label' => 'Twilio', 'fields' => [
            'account_sid' => ['Account SID', 'text', ''],
            'auth_token'  => ['Auth token', 'secret', ''],
            'messaging_service_sid' => ['Messaging Service SID', 'text', 'Optional — used instead of the sender name when set'],
            'base_url'    => ['API address', 'text', 'Leave empty for https://api.twilio.com'],
        ]],
        'mshastra' => ['label' => 'mShastra (Mobishastra)', 'fields' => [
            'user'     => ['Username', 'text', ''],
            'password' => ['Password', 'secret', ''],
            'country'  => ['Country code', 'text', 'ALL, or a country like 20 — as mShastra set up your account'],
            'base_url' => ['API address', 'text', 'Leave empty for https://mshastra.com'],
        ]],
        'http' => ['label' => 'Any provider (HTTP API)', 'fields' => [
            'url'          => ['Send URL', 'text', 'The full address the provider gives for sending an SMS'],
            'method'       => ['Method', 'select', ['POST' => 'POST', 'GET' => 'GET']],
            'body'         => ['Send the fields as', 'select', ['form' => 'Form fields', 'json' => 'JSON body', 'query' => 'In the URL']],
            'auth'         => ['Sign in with', 'select', ['none' => 'Fields only (username/password below)', 'basic' => 'Basic auth', 'bearer' => 'Bearer token', 'header' => 'A header']],
            'auth_user'    => ['Username / header name', 'text', ''],
            'auth_secret'  => ['Password / token / header value', 'secret', ''],
            'f_to'         => ['Field: phone number', 'text', 'e.g. to, mobile, msisdn'],
            'f_text'       => ['Field: message', 'text', 'e.g. text, message, body'],
            'f_sender'     => ['Field: sender name', 'text', 'e.g. from, sender, senderid'],
            'f_ref'        => ['Field: your reference (optional)', 'text', ''],
            'f_unicode'    => ['Field: Arabic/unicode flag (optional)', 'text', 'Sent as 1 for Arabic messages, 0 otherwise'],
            'extra'        => ['Extra fixed fields', 'textarea', 'One per line: name=value (e.g. username=abc, type=0)'],
            'number'       => ['Number format', 'select', ['plus' => '+201001234567', 'digits' => '201001234567', 'local' => '01001234567']],
            'ok_contains'  => ['Success if the answer contains', 'text', 'Optional, e.g. "success" or "1901" — otherwise any 2xx answer'],
            'ok_json'      => ['Or success when JSON field = value', 'text', 'Optional, e.g. status=OK or code=0'],
            'id_json'      => ['Message id JSON field', 'text', 'Optional, e.g. message_id or data.id'],
        ]],
    ];
}

function sms_provider_label(string $p): string
{
    return sms_provider_catalog()[$p]['label'] ?? $p;
}

/** '' when a provider address is fine for a client's own gateway, else why not (private / local addresses). */
function sms_url_guard(string $url): string
{
    if ($url === '') return '';
    if (!preg_match('~^https?://~i', $url)) return 'The provider address must start with https://';
    if (config('webhook_allow_private', false)) return '';            // test rigs and on-premise setups
    $host = (string) parse_url($url, PHP_URL_HOST);
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return 'The provider address could not be found.';
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return 'The provider address is on a private network.';
    return '';
}

/** One HTTP call to a provider. Returns [http code, body, error]. */
function sms_http(string $method, string $url, $body = null, array $headers = [], int $timeout = 20): array
{
    if (!preg_match('~^https?://~i', $url)) return [0, '', 'The provider address is not valid.'];
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
             CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => $headers,
             CURLOPT_USERAGENT => 'Revenect-SMS/1.0'];
    if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $body ?? ''; }
    curl_setopt_array($ch, $opts);
    $out = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $out === false ? curl_error($ch) : '';
    curl_close($ch);
    return [$code, $out === false ? '' : (string) $out, $err];
}

/** A value from decoded JSON by a dotted path (data.id, messages.0.id). */
function sms_json_path($json, string $path)
{
    foreach (array_filter(explode('.', $path), 'strlen') as $k) {
        if (is_array($json) && array_key_exists($k, $json)) $json = $json[$k];
        else return null;
    }
    return $json;
}

/** The number the way a provider wants it. */
function sms_number(string $e164, string $format): string
{
    $d = ltrim($e164, '+');
    return match ($format) { 'plus' => '+' . $d, 'local' => str_starts_with($d, '20') ? '0' . substr($d, 2) : $d, default => $d };
}

/**
 * Send one SMS. $gw = gateway row with 'config' (decrypted array). $unicode = Arabic/other non-GSM text.
 * $dlrUrl = where the provider should report delivery (when it supports that).
 */
function sms_provider_send(array $gw, string $to, string $text, string $sender, string $ref, bool $unicode, string $dlrUrl = ''): array
{
    $c = (array) ($gw['config'] ?? []);
    // A client's own gateway may only call public addresses — never this server's internal network.
    if (($gw['client_id'] ?? null) !== null && ($bad = sms_url_guard((string) ($c['url'] ?? ($c['base_url'] ?? '')))) !== '') {
        return ['ok' => false, 'id' => null, 'code' => 'blocked', 'error' => $bad, 'retry' => false];
    }
    $fail = fn(string $err, string $code = '', bool $retry = false) => ['ok' => false, 'id' => null, 'code' => $code, 'error' => $err, 'retry' => $retry];
    $transient = fn(int $http, string $err) => $err !== '' || $http === 0 || $http === 429 || $http >= 500;

    switch ($gw['provider']) {
        case 'smsmisr': {
            $base = rtrim((string) ($c['base_url'] ?? '') ?: 'https://smsmisr.com/api', '/');
            $q = ['environment' => (string) ($c['environment'] ?? '1'), 'username' => (string) ($c['username'] ?? ''), 'password' => (string) ($c['password'] ?? ''),
                  'sender' => (string) ($c['sender'] ?? ''), 'mobile' => sms_number($to, 'digits'), 'language' => $unicode ? '2' : '1', 'message' => $text];
            [$http, $body, $err] = sms_http('POST', $base . '/SMS/?' . http_build_query($q));
            if ($transient($http, $err)) return $fail($err ?: 'SMS Misr did not answer (HTTP ' . $http . ').', (string) $http, true);
            $j = json_decode($body, true) ?: [];
            $code = (string) ($j['code'] ?? '');
            if ($code === '1901') return ['ok' => true, 'id' => (string) ($j['SMSID'] ?? ($j['smsid'] ?? '')) ?: null, 'code' => $code, 'error' => '', 'retry' => false];
            $words = ['1902' => 'Invalid request', '1903' => 'Invalid username or password', '1904' => 'Invalid sender', '1905' => 'Invalid mobile number',
                      '1906' => 'Not enough balance on the SMS Misr account', '1907' => 'The server is updating — try again', '1908' => 'Invalid date',
                      '1909' => 'Invalid message', '1910' => 'Invalid language', '1911' => 'The message is too long', '1912' => 'Invalid environment'];
            return $fail('SMS Misr: ' . ($words[$code] ?? ($j['message'] ?? 'refused')), $code, $code === '1907');
        }
        case 'victorylink': {
            $base = rtrim((string) ($c['base_url'] ?? '') ?: 'https://smsvas.vlserv.com/VLSMSPlatformResellerAPI/NewSendingAPI/api', '/');
            $id = $ref !== '' ? $ref : bin2hex(random_bytes(8));
            $payload = json_encode(['UserName' => (string) ($c['username'] ?? ''), 'Password' => (string) ($c['password'] ?? ''), 'SMSText' => $text,
                                    'SMSLang' => $unicode ? 'a' : 'e', 'SMSSender' => $sender, 'SMSReceiver' => sms_number($to, 'local'), 'SMSID' => $id], JSON_UNESCAPED_UNICODE);
            [$http, $body, $err] = sms_http('POST', $base . '/SMSSender/SendSMS', $payload, ['Content-Type: application/json']);
            if ($transient($http, $err)) return $fail($err ?: 'Victory Link did not answer (HTTP ' . $http . ').', (string) $http, true);
            $code = trim(trim($body), '"');
            if ($code === '0') return ['ok' => true, 'id' => $id, 'code' => '0', 'error' => '', 'retry' => false];
            $words = ['-1' => 'User is not subscribed', '-5' => 'Not enough credit on the Victory Link account', '-10' => 'Queued and will be retried',
                      '-11' => 'Invalid language', '-12' => 'The message is empty', '-13' => 'Invalid fake sender exceeded 12 characters',
                      '-25' => 'Sending rate greater than the receiving rate', '-100' => 'Other error'];
            return $fail('Victory Link: ' . ($words[$code] ?? ('refused (' . mb_substr($code, 0, 40) . ')')), $code, in_array($code, ['-10', '-25', '-100'], true));
        }
        case 'twilio': {
            $sid = (string) ($c['account_sid'] ?? '');
            $base = rtrim((string) ($c['base_url'] ?? '') ?: 'https://api.twilio.com', '/');
            $form = ['To' => sms_number($to, 'plus'), 'Body' => $text];
            if (!empty($c['messaging_service_sid'])) $form['MessagingServiceSid'] = (string) $c['messaging_service_sid']; else $form['From'] = $sender;
            if ($dlrUrl !== '') $form['StatusCallback'] = $dlrUrl;
            [$http, $body, $err] = sms_http('POST', $base . '/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json', http_build_query($form),
                                            ['Authorization: Basic ' . base64_encode($sid . ':' . (string) ($c['auth_token'] ?? '')), 'Content-Type: application/x-www-form-urlencoded']);
            $j = json_decode($body, true) ?: [];
            if ($http >= 200 && $http < 300 && !empty($j['sid'])) return ['ok' => true, 'id' => (string) $j['sid'], 'code' => (string) ($j['status'] ?? ''), 'error' => '', 'retry' => false];
            if ($transient($http, $err)) return $fail($err ?: 'Twilio did not answer (HTTP ' . $http . ').', (string) $http, true);
            return $fail('Twilio: ' . (string) ($j['message'] ?? 'refused'), (string) ($j['code'] ?? $http));
        }
        case 'mshastra': {
            $base = rtrim((string) ($c['base_url'] ?? '') ?: 'https://mshastra.com', '/');
            $q = ['user' => (string) ($c['user'] ?? ''), 'pwd' => (string) ($c['password'] ?? ''), 'senderid' => $sender, 'mobileno' => sms_number($to, 'digits'),
                  'msgtext' => $text, 'priority' => 'High', 'CountryCode' => (string) ($c['country'] ?? '') ?: 'ALL'];
            [$http, $body, $err] = sms_http('GET', $base . '/sendurl.aspx?' . http_build_query($q));
            if ($transient($http, $err)) return $fail($err ?: 'mShastra did not answer (HTTP ' . $http . ').', (string) $http, true);
            $t = trim(strip_tags($body));
            if (stripos($t, 'Send Successful') !== false) {
                return ['ok' => true, 'id' => preg_match('/(\d{6,})/', $t, $m) ? $m[1] : null, 'code' => 'ok', 'error' => '', 'retry' => false];
            }
            return $fail('mShastra: ' . (mb_substr($t, 0, 120) ?: 'refused'), 'refused', stripos($t, 'try again') !== false);
        }
        case 'http': {
            $url = (string) ($c['url'] ?? '');
            $f = [];
            foreach (preg_split('/\r?\n/', (string) ($c['extra'] ?? '')) as $line) {
                if (str_contains($line, '=')) { [$k, $v] = array_map('trim', explode('=', $line, 2)); if ($k !== '') $f[$k] = $v; }
            }
            if (!empty($c['f_to']))     $f[(string) $c['f_to']] = sms_number($to, (string) ($c['number'] ?? 'digits'));
            if (!empty($c['f_text']))   $f[(string) $c['f_text']] = $text;
            if (!empty($c['f_sender'])) $f[(string) $c['f_sender']] = $sender;
            if (!empty($c['f_ref']) && $ref !== '') $f[(string) $c['f_ref']] = $ref;
            if (!empty($c['f_unicode'])) $f[(string) $c['f_unicode']] = $unicode ? '1' : '0';
            $headers = [];
            $auth = (string) ($c['auth'] ?? 'none');
            if ($auth === 'basic')  $headers[] = 'Authorization: Basic ' . base64_encode((string) ($c['auth_user'] ?? '') . ':' . (string) ($c['auth_secret'] ?? ''));
            if ($auth === 'bearer') $headers[] = 'Authorization: Bearer ' . (string) ($c['auth_secret'] ?? '');
            if ($auth === 'header' && !empty($c['auth_user'])) $headers[] = preg_replace('/[\r\n:]+/', '', (string) $c['auth_user']) . ': ' . preg_replace('/[\r\n]+/', '', (string) ($c['auth_secret'] ?? ''));
            $method = ($c['method'] ?? 'POST') === 'GET' ? 'GET' : 'POST';
            $mode = (string) ($c['body'] ?? 'form');
            if ($method === 'GET' || $mode === 'query') {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($f);
                $payload = $method === 'POST' ? '' : null;
            } elseif ($mode === 'json') {
                $payload = json_encode($f, JSON_UNESCAPED_UNICODE); $headers[] = 'Content-Type: application/json';
            } else {
                $payload = http_build_query($f); $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
            [$http, $body, $err] = sms_http($method, $url, $payload, $headers);
            if ($transient($http, $err)) return $fail($err ?: 'The provider did not answer (HTTP ' . $http . ').', (string) $http, true);
            $j = json_decode($body, true);
            $ok = $http >= 200 && $http < 300;
            if ($ok && trim((string) ($c['ok_contains'] ?? '')) !== '') $ok = stripos($body, trim((string) $c['ok_contains'])) !== false;
            if ($ok && str_contains((string) ($c['ok_json'] ?? ''), '=')) {
                [$p, $want] = array_map('trim', explode('=', (string) $c['ok_json'], 2));
                $ok = (string) sms_json_path($j, $p) === $want;
            }
            $id = !empty($c['id_json']) && is_array($j) ? sms_json_path($j, (string) $c['id_json']) : null;
            if ($ok) return ['ok' => true, 'id' => $id !== null ? (string) $id : null, 'code' => (string) $http, 'error' => '', 'retry' => false];
            return $fail('The provider refused it: ' . mb_substr(trim(strip_tags($body)), 0, 160), (string) $http);
        }
    }
    return $fail('Unknown SMS provider.');
}

/** Credit left on the provider account, where the provider says. Null when unknown. */
function sms_provider_balance(array $gw): ?string
{
    $c = (array) ($gw['config'] ?? []);
    if ($gw['provider'] === 'twilio') {
        $base = rtrim((string) ($c['base_url'] ?? '') ?: 'https://api.twilio.com', '/');
        $sid = (string) ($c['account_sid'] ?? '');
        [$http, $body] = sms_http('GET', $base . '/2010-04-01/Accounts/' . rawurlencode($sid) . '/Balance.json', null,
                                  ['Authorization: Basic ' . base64_encode($sid . ':' . (string) ($c['auth_token'] ?? ''))], 10);
        $j = json_decode($body, true) ?: [];
        return $http === 200 && isset($j['balance']) ? $j['balance'] . ' ' . ($j['currency'] ?? '') : null;
    }
    if ($gw['provider'] === 'mshastra') {
        $base = rtrim((string) ($c['base_url'] ?? '') ?: 'https://mshastra.com', '/');
        [$http, $body] = sms_http('GET', $base . '/balance.aspx?' . http_build_query(['user' => (string) ($c['user'] ?? ''), 'pwd' => (string) ($c['password'] ?? '')]), null, [], 10);
        $t = trim(strip_tags($body));
        return $http === 200 && $t !== '' && mb_strlen($t) < 80 ? $t : null;
    }
    return null;
}

/**
 * A delivery report from a provider, in our words: [provider message id, status (delivered|undelivered|sent|failed), error].
 * Null when the request is not one we understand.
 */
function sms_provider_dlr(array $gw, array $in): ?array
{
    $map = function (string $s): string {
        $s = strtolower($s);
        if (in_array($s, ['delivered', 'delivrd', 'success', '1', 'ok'], true)) return 'delivered';
        if (in_array($s, ['undelivered', 'undeliv', 'expired', 'rejected', 'rejectd', 'failed', 'deleted', '2', '16'], true)) return 'undelivered';
        return 'sent';
    };
    if ($gw['provider'] === 'twilio') {
        if (empty($in['MessageSid']) || empty($in['MessageStatus'])) return null;
        $st = (string) $in['MessageStatus'];
        if (in_array($st, ['queued', 'sending', 'sent', 'accepted', 'scheduled'], true)) return [(string) $in['MessageSid'], 'sent', ''];
        return [(string) $in['MessageSid'], $map($st), (string) ($in['ErrorCode'] ?? '') !== '' ? 'Twilio error ' . $in['ErrorCode'] : ''];
    }
    // Everyone else: an id and a status under the usual names (configurable for the HTTP provider).
    $c = (array) ($gw['config'] ?? []);
    $idKeys = array_filter([(string) ($c['dlr_id'] ?? ''), 'id', 'msgid', 'message_id', 'MessageId', 'smsid', 'SMSID', 'sid']);
    $stKeys = array_filter([(string) ($c['dlr_status'] ?? ''), 'status', 'Status', 'dlr', 'state', 'DeliveryStatus']);
    $id = null; $st = null;
    foreach ($idKeys as $k) if (isset($in[$k]) && $in[$k] !== '') { $id = (string) $in[$k]; break; }
    foreach ($stKeys as $k) if (isset($in[$k]) && $in[$k] !== '') { $st = (string) $in[$k]; break; }
    return $id !== null && $st !== null ? [$id, $map($st), ''] : null;
}
