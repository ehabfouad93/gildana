<?php
declare(strict_types=1);

/**
 * Create, edit and delete WhatsApp message templates from Revenect (Templates → Create template),
 * submitted to Meta for approval through the Cloud API — the same thing WhatsApp Manager does.
 *
 * The page (client/template_new.php) posts a plain description of the template; tb_build() checks
 * it against Meta's rules (so a mistake is explained here, not as a rejection hours later) and turns
 * it into Meta's `components`. Kinds:
 *   standard  — header (none / text / image / video / document / location), body, footer, buttons
 *               (quick reply, website, call, copy offer code)
 *   carousel  — a message and 2–10 swipeable cards (image or video, text, 1–2 buttons each)
 *   lto       — limited-time offer: an offer line with an optional countdown, a code and a link
 *   auth      — one-time passcode (copy code, one-tap or zero-tap autofill)
 *
 * Media samples: Meta needs an example of every image / video / document. The file is uploaded
 * with the Resumable Upload API (wa_upload_sample) and Meta keeps it with the template. Revenect
 * also keeps its own copy (`_media` in the stored components) so the same picture can be sent
 * later without asking for it again — see wa_build_components().
 */

require_once __DIR__ . '/whatsapp.php';

const TB_LANGS = [
    'ar' => 'Arabic', 'en' => 'English', 'en_US' => 'English (US)', 'en_GB' => 'English (UK)', 'fr' => 'French',
    'de' => 'German', 'es' => 'Spanish', 'it' => 'Italian', 'pt_BR' => 'Portuguese (Brazil)', 'tr' => 'Turkish',
    'ru' => 'Russian', 'hi' => 'Hindi', 'ur' => 'Urdu', 'id' => 'Indonesian', 'fa' => 'Persian', 'zh_CN' => 'Chinese (simplified)',
];

/** {{1}}, {{2}}… in a text, as numbers in order of appearance. */
function tb_vars(string $text): array
{
    preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $m);
    return array_map('intval', $m[1]);
}

/** Variables must be {{1}}…{{n}} with none missing, not at the very start or end; samples for each. */
function tb_check_vars(string $what, string $text, array $samples, bool $edgesOk = false): ?string
{
    $v = tb_vars($text);
    if (!$v) return null;
    $u = array_values(array_unique($v)); sort($u);
    if ($u !== range(1, count($u))) return $what . ': number the variables {{1}}, {{2}}, {{3}}… with none missing.';
    if (!$edgesOk && (preg_match('/^\s*\{\{\s*\d+\s*\}\}/', $text) || preg_match('/\{\{\s*\d+\s*\}\}[\s.!?،؟]*$/u', $text))) {
        return $what . ': Meta does not accept a variable at the very start or end. Add a word before or after it.';
    }
    for ($i = 1; $i <= count($u); $i++) {
        if (trim((string) ($samples[$i - 1] ?? '')) === '') return $what . ': write a sample for {{' . $i . '}} — Meta reviews the message with it.';
    }
    return null;
}

/** A file this account uploaded (…/uploads/<client>/…) — never anything else on the server. */
function tb_local_media(int $clientId, string $url): ?string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    if (!preg_match('#/uploads/' . $clientId . '/[^/]+$#', $path)) return null;
    return wa_local_path_for_url($url);
}

/**
 * Turn the page's description into Meta's template payload.
 * $upload: fn(string $localUrl, string $kind): array{ok:bool, handle:string, error:string} — gives
 * Meta's handle for a sample file (tests and the edit path pass their own).
 * @return array{ok:bool, error:string, payload:array, stored:array}
 */
function tb_build(array $in, array $client, callable $upload): array
{
    $fail = fn(string $e) => ['ok' => false, 'error' => $e, 'payload' => [], 'stored' => []];
    $kind = (string) ($in['kind'] ?? 'standard');
    if (!in_array($kind, ['standard', 'carousel', 'lto', 'auth'], true)) return $fail('Choose the type of template.');
    $name = (string) ($in['name'] ?? '');
    if (!preg_match('/^[a-z0-9_]{1,512}$/', $name)) return $fail('Name: use lowercase letters, numbers and _ only (for example summer_offer_2026).');
    $lang = (string) ($in['language'] ?? '');
    if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $lang)) return $fail('Choose the language.');
    $cat = strtoupper((string) ($in['category'] ?? ''));
    if ($kind === 'auth') $cat = 'AUTHENTICATION';
    if (!in_array($cat, ['MARKETING', 'UTILITY', 'AUTHENTICATION'], true)) return $fail('Choose a category.');
    if ($cat === 'AUTHENTICATION' && $kind !== 'auth') return $fail('An authentication template is a one-time passcode — choose that type.');
    if (in_array($kind, ['carousel', 'lto'], true) && $cat !== 'MARKETING') return $fail('Carousels and limited-time offers are Marketing templates.');

    $cid = (int) $client['id'];
    $mediaSample = function (string $url, string $fmt) use ($cid, $upload): array {
        if (trim($url) === '') return ['ok' => false, 'error' => 'Add a sample ' . strtolower($fmt) . ' — Meta reviews the template with it.'];
        if (!tb_local_media($cid, $url)) return ['ok' => false, 'error' => 'Upload the sample ' . strtolower($fmt) . ' here (a link from elsewhere cannot be used).'];
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $okExt = ['IMAGE' => ['jpg', 'jpeg', 'png'], 'VIDEO' => ['mp4'], 'DOCUMENT' => ['pdf']][$fmt] ?? [];
        if (!in_array($ext, $okExt, true)) return ['ok' => false, 'error' => 'the sample must be ' . implode(' or ', array_map('strtoupper', $okExt)) . '.'];
        $r = $upload($url, strtolower($fmt));
        return !empty($r['ok']) ? ['ok' => true, 'handle' => (string) $r['handle']] : ['ok' => false, 'error' => 'Meta did not take the sample ' . strtolower($fmt) . ': ' . ($r['error'] ?? 'unknown error')];
    };
    $components = [];     // what goes to Meta
    $stored = [];         // what Revenect keeps: the same, plus our own copy of each sample file (_media)

    /* ── authentication: Meta writes the text itself ── */
    if ($kind === 'auth') {
        $a = (array) ($in['auth'] ?? []);
        $otp = strtoupper((string) ($a['otp_type'] ?? 'COPY_CODE'));
        if (!in_array($otp, ['COPY_CODE', 'ONE_TAP', 'ZERO_TAP'], true)) return $fail('Choose how the code is filled in.');
        $body = ['type' => 'BODY', 'add_security_recommendation' => !empty($a['security'])];
        $components[] = $body;
        $exp = (int) ($a['expiry'] ?? 0);
        if ($exp > 0) {
            if ($exp > 90) return $fail('The code can expire after 1 to 90 minutes.');
            $components[] = ['type' => 'FOOTER', 'code_expiration_minutes' => $exp];
        }
        $btn = ['type' => 'OTP', 'otp_type' => $otp, 'text' => mb_substr(trim((string) ($a['copy_text'] ?? '')) ?: 'Copy code', 0, 25)];
        if ($otp !== 'COPY_CODE') {
            $pkg = trim((string) ($a['package'] ?? '')); $sig = trim((string) ($a['signature'] ?? ''));
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)+$/', $pkg)) return $fail('Autofill: write your Android app\'s package name (for example com.example.app).');
            if (!preg_match('/^[A-Za-z0-9+\/]{11}$/', $sig)) return $fail('Autofill: the app signature hash is 11 characters.');
            $btn['supported_apps'] = [['package_name' => $pkg, 'signature_hash' => $sig]];
            if (trim((string) ($a['autofill_text'] ?? '')) !== '') $btn['autofill_text'] = mb_substr(trim((string) $a['autofill_text']), 0, 25);
            if ($otp === 'ZERO_TAP') {
                if (empty($a['zero_tap_terms'])) return $fail('Zero-tap: accept WhatsApp\'s zero-tap terms.');
                $btn['zero_tap_terms_accepted'] = true;
            }
        }
        $components[] = ['type' => 'BUTTONS', 'buttons' => [$btn]];
        return ['ok' => true, 'error' => '', 'payload' => ['name' => $name, 'language' => $lang, 'category' => $cat, 'components' => $components], 'stored' => $components];
    }

    /* ── header (standard, lto) ── */
    $h = (array) ($in['header'] ?? []);
    $hf = strtoupper((string) ($h['format'] ?? 'NONE'));
    if ($kind === 'lto' && !in_array($hf, ['NONE', 'IMAGE', 'VIDEO'], true)) return $fail('A limited-time offer can have an image or a video at the top, or nothing.');
    if ($kind !== 'carousel' && $hf !== 'NONE') {
        if ($hf === 'TEXT') {
            $t = trim((string) ($h['text'] ?? ''));
            if ($t === '') return $fail('Header: write the header text, or choose no header.');
            if (mb_strlen($t) > 60) return $fail('Header: at most 60 characters.');
            if (count(tb_vars($t)) > 1) return $fail('Header: at most one variable.');
            if ($e = tb_check_vars('Header', $t, (array) ($h['samples'] ?? []), true)) return $fail($e);
            $c = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $t];
            if (tb_vars($t)) $c['example'] = ['header_text' => [trim((string) $h['samples'][0])]];
            $components[] = $c; $stored[] = $c;
        } elseif (in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            $s = $mediaSample((string) ($h['media'] ?? ''), $hf);
            if (!$s['ok']) return $fail('Header: ' . $s['error']);
            $c = ['type' => 'HEADER', 'format' => $hf, 'example' => ['header_handle' => [$s['handle']]]];
            $components[] = $c; $stored[] = $c + ['_media' => (string) $h['media']];
        } elseif ($hf === 'LOCATION') {
            $c = ['type' => 'HEADER', 'format' => 'LOCATION'];
            $components[] = $c; $stored[] = $c;
        } else return $fail('Header: choose text, image, video, document, location or none.');
    }

    /* ── limited-time offer line ── */
    if ($kind === 'lto') {
        $o = (array) ($in['lto'] ?? []);
        $t = trim((string) ($o['text'] ?? ''));
        if ($t === '' || mb_strlen($t) > 16) return $fail('Offer: write the offer line, at most 16 characters (for example "Ends soon!").');
        $c = ['type' => 'LIMITED_TIME_OFFER', 'limited_time_offer' => ['text' => $t, 'has_expiration' => !empty($o['has_expiration'])]];
        $components[] = $c; $stored[] = $c;
    }

    /* ── body ── */
    $b = (array) ($in['body'] ?? []);
    $bt = trim((string) ($b['text'] ?? ''));
    $max = $kind === 'lto' ? 600 : 1024;
    if ($bt === '') return $fail('Message: write the message text.');
    if (mb_strlen($bt) > $max) return $fail('Message: at most ' . $max . ' characters.');
    if ($e = tb_check_vars('Message', $bt, (array) ($b['samples'] ?? []))) return $fail($e);
    $c = ['type' => 'BODY', 'text' => $bt];
    if ($n = count(array_unique(tb_vars($bt)))) $c['example'] = ['body_text' => [array_map(fn($x) => trim((string) $x), array_slice(array_values((array) $b['samples']), 0, $n))]];
    $components[] = $c; $stored[] = $c;

    /* ── footer ── */
    $ft = trim((string) ($in['footer'] ?? ''));
    if ($ft !== '') {
        if (in_array($kind, ['carousel', 'lto'], true)) return $fail('This type of template has no footer.');
        if (mb_strlen($ft) > 60) return $fail('Footer: at most 60 characters.');
        if (tb_vars($ft)) return $fail('Footer: variables are not allowed here.');
        $components[] = ['type' => 'FOOTER', 'text' => $ft]; $stored[] = ['type' => 'FOOTER', 'text' => $ft];
    }

    /* ── buttons ── */
    $btns = tb_buttons((array) ($in['buttons'] ?? []), $kind === 'lto' ? 'lto' : 'standard');
    if (is_string($btns)) return $fail($btns);
    if ($kind === 'carousel' && $btns) return $fail('In a carousel the buttons go on the cards.');
    if ($btns) { $components[] = ['type' => 'BUTTONS', 'buttons' => $btns]; $stored[] = ['type' => 'BUTTONS', 'buttons' => $btns]; }

    /* ── carousel cards ── */
    if ($kind === 'carousel') {
        $cards = array_values((array) ($in['cards'] ?? []));
        if (count($cards) < 2 || count($cards) > 10) return $fail('A carousel has 2 to 10 cards.');
        $fmt0 = strtoupper((string) ($cards[0]['format'] ?? 'IMAGE'));
        if (!in_array($fmt0, ['IMAGE', 'VIDEO'], true)) return $fail('Cards show an image or a video.');
        $shape = null; $mCards = []; $sCards = [];
        foreach ($cards as $i => $cd) {
            $n = 'Card ' . ($i + 1);
            if (strtoupper((string) ($cd['format'] ?? $fmt0)) !== $fmt0) return $fail('All cards show the same kind of media (all images or all videos).');
            $s = $mediaSample((string) ($cd['media'] ?? ''), $fmt0);
            if (!$s['ok']) return $fail($n . ': ' . $s['error']);
            $ct = trim((string) ($cd['text'] ?? ''));
            if ($ct === '') return $fail($n . ': write the card text.');
            if (mb_strlen($ct) > 160) return $fail($n . ': at most 160 characters.');
            if ($e = tb_check_vars($n, $ct, (array) ($cd['samples'] ?? []), true)) return $fail($e);
            $cb = tb_buttons((array) ($cd['buttons'] ?? []), 'card');
            if (is_string($cb)) return $fail($n . ': ' . $cb);
            if (!$cb) return $fail($n . ': add one or two buttons.');
            $sh = implode(',', array_map(fn($x) => $x['type'], $cb));
            if ($shape !== null && $sh !== $shape) return $fail('Every card needs the same buttons, in the same order (for example a website button then a quick reply on each).');
            $shape = $sh;
            $head = ['type' => 'HEADER', 'format' => $fmt0, 'example' => ['header_handle' => [$s['handle']]]];
            $body = ['type' => 'BODY', 'text' => $ct];
            if ($k = count(array_unique(tb_vars($ct)))) $body['example'] = ['body_text' => [array_map(fn($x) => trim((string) $x), array_slice(array_values((array) $cd['samples']), 0, $k))]];
            $mCards[] = ['components' => [$head, $body, ['type' => 'BUTTONS', 'buttons' => $cb]]];
            $sCards[] = ['components' => [$head + ['_media' => (string) $cd['media']], $body, ['type' => 'BUTTONS', 'buttons' => $cb]]];
        }
        $components[] = ['type' => 'CAROUSEL', 'cards' => $mCards];
        $stored[] = ['type' => 'CAROUSEL', 'cards' => $sCards];
    }

    return ['ok' => true, 'error' => '', 'payload' => ['name' => $name, 'language' => $lang, 'category' => $cat, 'components' => $components], 'stored' => $stored];
}

/**
 * Check and shape a list of buttons. $where: standard | lto | card. Returns Meta's buttons or an error.
 * Quick replies are kept together (Meta refuses them mixed in between the other buttons).
 */
function tb_buttons(array $list, string $where)
{
    $out = []; $n = ['QUICK_REPLY' => 0, 'URL' => 0, 'PHONE_NUMBER' => 0, 'COPY_CODE' => 0];
    foreach (array_values($list) as $i => $b) {
        $t = strtoupper((string) ($b['type'] ?? ''));
        $text = trim((string) ($b['text'] ?? ''));
        $label = 'Button ' . ($i + 1);
        if (!isset($n[$t])) return $label . ': choose its type.';
        $n[$t]++;
        if ($t !== 'COPY_CODE') {
            if ($text === '') return $label . ': write the text on the button.';
            if (mb_strlen($text) > 25) return $label . ': at most 25 characters.';
            if (tb_vars($text)) return $label . ': no variables in the button text.';
        }
        if ($t === 'QUICK_REPLY') {
            $out[] = ['type' => 'QUICK_REPLY', 'text' => $text];
        } elseif ($t === 'URL') {
            $url = trim((string) ($b['url'] ?? ''));
            if (!preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $url)) return $label . ': write the full web address, starting with https://';
            $v = tb_vars($url);
            if ($v && ($v !== [1] || !preg_match('/\{\{\s*1\s*\}\}$/', $url))) return $label . ': a link can have one variable, {{1}}, at the very end (for example https://shop.com/order/{{1}}).';
            $btn = ['type' => 'URL', 'text' => $text, 'url' => $url];
            if ($v) {
                $ex = trim((string) ($b['url_sample'] ?? ''));
                if ($ex === '') return $label . ': write a sample for the end of the link.';
                $btn['example'] = [preg_replace('/\{\{\s*1\s*\}\}$/', rawurlencode($ex), $url)];
            }
            $out[] = $btn;
        } elseif ($t === 'PHONE_NUMBER') {
            if ($where === 'lto') return $label . ': a limited-time offer has a code and a link, not a call button.';
            $ph = preg_replace('/[^\d+]/', '', (string) ($b['phone'] ?? ''));
            if (!preg_match('/^\+\d{8,15}$/', $ph)) return $label . ': write the phone number with its country code (for example +201001234567).';
            $out[] = ['type' => 'PHONE_NUMBER', 'text' => $text, 'phone_number' => $ph];
        } else {
            if ($where === 'card') return $label . ': cards cannot have an offer code.';
            $code = trim((string) ($b['code'] ?? ''));
            if ($code === '' || mb_strlen($code) > 15 || preg_match('/\s/', $code)) return $label . ': write a sample code, up to 15 characters with no spaces.';
            $out[] = ['type' => 'COPY_CODE', 'example' => $code];
        }
    }
    $total = count($out);
    if ($where === 'card' && $total > 2) return 'at most 2 buttons per card.';
    if ($total > 10) return 'At most 10 buttons.';
    if ($n['URL'] > 2) return 'At most 2 website buttons.';
    if ($n['PHONE_NUMBER'] > 1) return 'At most 1 call button.';
    if ($n['COPY_CODE'] > 1) return 'At most 1 offer-code button.';
    if ($where === 'lto' && !$n['URL']) return 'A limited-time offer needs a website button (where the offer is used).';
    if ($where === 'lto' && $n['QUICK_REPLY']) return 'A limited-time offer has a code and a link, not quick replies.';
    // Quick replies together, then the rest, each group in the order written.
    $qr = array_values(array_filter($out, fn($x) => $x['type'] === 'QUICK_REPLY'));
    $other = array_values(array_filter($out, fn($x) => $x['type'] !== 'QUICK_REPLY'));
    return $where === 'card' ? $out : array_merge($qr, $other);
}

/* ── talking to Meta ── */

/** The Meta app the account's token belongs to — needed to upload sample media. */
function wa_app_id(array $client): string
{
    $own = trim((string) ($client['app_id'] ?? ''));
    if ($own !== '') return $own;
    $token = wa_token($client);
    if ($token !== '') {
        $r = wa_request('GET', wa_graph_base() . '/app', $token);
        if (!empty($r['json']['id'])) return (string) $r['json']['id'];
    }
    return function_exists('setting_get') ? trim((string) (setting_get('meta_app_id', '') ?? '')) : '';
}

/**
 * Upload a sample file for a template (Resumable Upload API) and return Meta's handle for it.
 * @return array{ok:bool, handle:string, error:string}
 */
function wa_upload_sample(array $client, string $path, string $mime = ''): array
{
    $token = wa_token($client);
    $app = wa_app_id($client);
    if ($token === '') return ['ok' => false, 'handle' => '', 'error' => 'The WhatsApp access token is missing.'];
    if ($app === '') return ['ok' => false, 'handle' => '', 'error' => 'The Meta app id is not known — add it under Admin → Settings (Meta app).'];
    if (!is_file($path)) return ['ok' => false, 'handle' => '', 'error' => 'The file is gone; upload it again.'];
    $mime = $mime !== '' ? $mime : wa_guess_mime($path);
    $size = (int) filesize($path);
    $q = http_build_query(['file_name' => basename($path), 'file_length' => $size, 'file_type' => $mime]);
    $start = wa_request('POST', wa_graph_base() . '/' . rawurlencode($app) . '/uploads?' . $q, $token);
    $sid = (string) ($start['json']['id'] ?? '');
    if ($sid === '') return ['ok' => false, 'handle' => '', 'error' => (string) ($start['json']['error']['message'] ?? $start['error'] ?: 'Could not start the upload.')];
    $ch = curl_init(wa_graph_base() . '/' . $sid);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => (string) file_get_contents($path),
        CURLOPT_HTTPHEADER => ['Authorization: OAuth ' . $token, 'file_offset: 0', 'Content-Type: application/octet-stream'],
        CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $raw = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string) $raw, true);
    if ($http >= 200 && $http < 300 && !empty($j['h'])) return ['ok' => true, 'handle' => (string) $j['h'], 'error' => ''];
    return ['ok' => false, 'handle' => '', 'error' => (string) ($j['error']['message'] ?? ('HTTP ' . $http))];
}

/** Meta's own words for a refusal, with the detail it puts in error_user_msg when there is one. */
function wa_meta_error(array $res): string
{
    $e = (array) ($res['json']['error'] ?? []);
    $msg = trim((string) ($e['error_user_msg'] ?? '')) ?: trim((string) ($e['message'] ?? '')) ?: ($res['error'] ?: 'HTTP ' . $res['http']);
    $title = trim((string) ($e['error_user_title'] ?? ''));
    return ($title !== '' && stripos($msg, $title) === false ? $title . ': ' : '') . $msg;
}

/** Submit a new template. @return array{ok:bool, id:string, status:string, category:string, error:string} */
function wa_create_template(array $client, string $waba, array $payload): array
{
    $res = wa_request('POST', wa_graph_base() . '/' . rawurlencode($waba) . '/message_templates', wa_token($client), $payload);
    if ($res['http'] >= 200 && $res['http'] < 300 && !empty($res['json']['id'])) {
        return ['ok' => true, 'id' => (string) $res['json']['id'], 'status' => strtoupper((string) ($res['json']['status'] ?? 'PENDING')),
                'category' => strtoupper((string) ($res['json']['category'] ?? $payload['category'])), 'error' => ''];
    }
    return ['ok' => false, 'id' => '', 'status' => '', 'category' => '', 'error' => wa_meta_error($res)];
}

/** Change a template that exists (it goes back to review). @return array{ok:bool, error:string} */
function wa_edit_template(array $client, string $metaId, array $payload): array
{
    $body = ['components' => $payload['components']];
    if (!empty($payload['category'])) $body['category'] = $payload['category'];
    $res = wa_request('POST', wa_graph_base() . '/' . rawurlencode($metaId), wa_token($client), $body);
    return ($res['http'] >= 200 && $res['http'] < 300 && !empty($res['json']['success'])) ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => wa_meta_error($res)];
}

/** Delete a template (one language when Meta's id is known, otherwise every language of the name). */
function wa_delete_template(array $client, string $waba, string $name, string $metaId = ''): array
{
    $q = ['name' => $name]; if ($metaId !== '') $q['hsm_id'] = $metaId;
    $res = wa_request('DELETE', wa_graph_base() . '/' . rawurlencode($waba) . '/message_templates?' . http_build_query($q), wa_token($client));
    return ($res['http'] >= 200 && $res['http'] < 300) ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => wa_meta_error($res)];
}

/** Keep Revenect's own copies of sample files (_media) when Meta's version of a template is synced in. */
function tb_keep_media(array $fresh, array $old): array
{
    $byType = [];
    foreach ($old as $c) {
        $t = strtoupper((string) ($c['type'] ?? ''));
        if ($t === 'HEADER' && !empty($c['_media'])) $byType['HEADER'] = $c['_media'];
        if ($t === 'CAROUSEL') foreach ((array) ($c['cards'] ?? []) as $i => $cd) foreach ((array) ($cd['components'] ?? []) as $cc) {
            if (strtoupper((string) ($cc['type'] ?? '')) === 'HEADER' && !empty($cc['_media'])) $byType['CARD' . $i] = $cc['_media'];
        }
    }
    if (!$byType) return $fresh;
    foreach ($fresh as &$c) {
        $t = strtoupper((string) ($c['type'] ?? ''));
        if ($t === 'HEADER' && isset($byType['HEADER'])) $c['_media'] = $byType['HEADER'];
        if ($t === 'CAROUSEL') foreach ($c['cards'] as $i => &$cd) foreach ($cd['components'] as &$cc) {
            if (strtoupper((string) ($cc['type'] ?? '')) === 'HEADER' && isset($byType['CARD' . $i])) $cc['_media'] = $byType['CARD' . $i];
        }
        unset($cd, $cc);
    }
    unset($c);
    return $fresh;
}

/**
 * Meta's components back into the page's description — to edit a template or start a new one from
 * it. Samples are taken from Meta's examples; media from Revenect's copy where it has one.
 */
function tb_describe(array $tpl): array
{
    $comps = json_decode((string) ($tpl['components'] ?? ''), true) ?: [];
    $d = ['name' => (string) $tpl['wa_name'], 'language' => (string) $tpl['language'], 'category' => strtoupper((string) $tpl['category']),
          'kind' => 'standard', 'header' => ['format' => 'NONE'], 'body' => ['text' => '', 'samples' => []], 'footer' => '', 'buttons' => [], 'cards' => [], 'lto' => [], 'auth' => []];
    $btnsIn = function (array $list): array {
        $o = [];
        foreach ($list as $b) {
            $t = strtoupper((string) ($b['type'] ?? ''));
            if ($t === 'URL') {
                $u = (string) ($b['url'] ?? ''); $ex = (string) (((array) ($b['example'] ?? []))[0] ?? '');
                $pre = preg_replace('/\{\{\s*1\s*\}\}$/', '', $u);
                $o[] = ['type' => 'URL', 'text' => (string) ($b['text'] ?? ''), 'url' => $u, 'url_sample' => $ex !== '' && str_starts_with($ex, $pre) ? rawurldecode(substr($ex, strlen($pre))) : ''];
            } elseif ($t === 'PHONE_NUMBER') $o[] = ['type' => $t, 'text' => (string) ($b['text'] ?? ''), 'phone' => (string) ($b['phone_number'] ?? '')];
            elseif ($t === 'COPY_CODE') $o[] = ['type' => $t, 'code' => (string) (is_array($b['example'] ?? null) ? ($b['example'][0] ?? '') : ($b['example'] ?? ''))];
            elseif ($t === 'QUICK_REPLY') $o[] = ['type' => $t, 'text' => (string) ($b['text'] ?? '')];
        }
        return $o;
    };
    foreach ($comps as $c) {
        $t = strtoupper((string) ($c['type'] ?? ''));
        if ($t === 'HEADER') {
            $f = strtoupper((string) ($c['format'] ?? 'TEXT'));
            $d['header'] = ['format' => $f, 'text' => (string) ($c['text'] ?? ''), 'samples' => (array) ($c['example']['header_text'] ?? []),
                            'media' => (string) ($c['_media'] ?? '')];
        } elseif ($t === 'BODY') {
            if ($d['category'] === 'AUTHENTICATION') { $d['kind'] = 'auth'; $d['auth']['security'] = !empty($c['add_security_recommendation']); continue; }
            $d['body'] = ['text' => (string) ($c['text'] ?? ''), 'samples' => (array) ($c['example']['body_text'][0] ?? [])];
        } elseif ($t === 'FOOTER') {
            if (isset($c['code_expiration_minutes'])) $d['auth']['expiry'] = (int) $c['code_expiration_minutes'];
            else $d['footer'] = (string) ($c['text'] ?? '');
        } elseif ($t === 'BUTTONS') {
            if ($d['category'] === 'AUTHENTICATION') {
                $b = (array) ($c['buttons'][0] ?? []);
                $d['auth'] += ['otp_type' => strtoupper((string) ($b['otp_type'] ?? 'COPY_CODE')), 'copy_text' => (string) ($b['text'] ?? ''),
                               'package' => (string) ($b['supported_apps'][0]['package_name'] ?? $b['package_name'] ?? ''),
                               'signature' => (string) ($b['supported_apps'][0]['signature_hash'] ?? $b['signature_hash'] ?? ''), 'autofill_text' => (string) ($b['autofill_text'] ?? '')];
                $d['kind'] = 'auth';
                continue;
            }
            $d['buttons'] = $btnsIn((array) ($c['buttons'] ?? []));
        } elseif ($t === 'LIMITED_TIME_OFFER') {
            $d['kind'] = 'lto';
            $d['lto'] = ['text' => (string) ($c['limited_time_offer']['text'] ?? ''), 'has_expiration' => !empty($c['limited_time_offer']['has_expiration'])];
        } elseif ($t === 'CAROUSEL') {
            $d['kind'] = 'carousel';
            foreach ((array) ($c['cards'] ?? []) as $cd) {
                $card = ['format' => 'IMAGE', 'media' => '', 'text' => '', 'samples' => [], 'buttons' => []];
                foreach ((array) ($cd['components'] ?? []) as $cc) {
                    $ct = strtoupper((string) ($cc['type'] ?? ''));
                    if ($ct === 'HEADER') { $card['format'] = strtoupper((string) ($cc['format'] ?? 'IMAGE')); $card['media'] = (string) ($cc['_media'] ?? ''); }
                    elseif ($ct === 'BODY') { $card['text'] = (string) ($cc['text'] ?? ''); $card['samples'] = (array) ($cc['example']['body_text'][0] ?? []); }
                    elseif ($ct === 'BUTTONS') $card['buttons'] = $btnsIn((array) ($cc['buttons'] ?? []));
                }
                $d['cards'][] = $card;
            }
        }
    }
    return $d;
}

/**
 * The WhatsApp Business Accounts this account can make templates in: the main one, and the account
 * of each extra Business API number that is in a different one (Settings → WhatsApp numbers).
 * [waba => ['waba' => id, 'client' => client row to call Meta with, 'label' => name]]
 */
function tb_sources(array $client): array
{
    $out = [];
    if (!empty($client['waba_id']) && wa_token($client) !== '') {
        $out[(string) $client['waba_id']] = ['waba' => (string) $client['waba_id'], 'client' => $client, 'label' => 'Main Business API number', 'key' => ''];
    }
    if (function_exists('wa_lines_ready') && wa_lines_ready()) {
        foreach (wa_lines($client) as $k => $l) {
            if ($k[0] !== 'n' || $l['kind'] !== 'cloud' || $l['waba'] === '' || isset($out[$l['waba']])) continue;
            if ($lc = wa_line_client($client, $k)) $out[$l['waba']] = ['waba' => $l['waba'], 'client' => $lc, 'label' => $l['label'] . ($l['phone'] !== '' ? ' (' . $l['phone'] . ')' : ''), 'key' => $l['waba']];
        }
    }
    return $out;
}

/** The account a stored template lives in ('' = the main one). */
function tb_source(array $client, string $waba): ?array
{
    $all = tb_sources($client);
    if ($waba === '') return $all[(string) ($client['waba_id'] ?? '')] ?? null;
    return $all[$waba] ?? null;
}
