<?php
declare(strict_types=1);

/* ── PHP 7.4 polyfills for PHP 8 string helpers ──
   Lets the app run on hosts still using PHP 7.4 (common on shared hosting). */
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

/* ── escaping ── */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* ── redirect + exit ── */
function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/* ── JSON response + exit ── */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── flash messages (one-shot, session-backed) ── */
function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ── CSRF ── */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }
}

/* ── phone normalization to E.164-ish digits ──
   Returns '' if it cannot produce a plausible international number.
   Keeps a leading +, strips spaces / punctuation. Does not validate country. */
/**
 * Detect an existing ITU country calling code at the start of a bare-digit number.
 * Matches the longest known prefix whose remaining national part is a plausible length.
 * Returns the code (e.g. '20', '966', '1') or null if none looks present.
 */
function phone_detect_cc(string $digits): ?string
{
    static $codes = null;
    if ($codes === null) {
        $list = [
            1, 7, 20, 27, 30, 31, 32, 33, 34, 36, 39, 40, 41, 43, 44, 45, 46, 47, 48, 49,
            51, 52, 53, 54, 55, 56, 57, 58, 60, 61, 62, 63, 64, 65, 66, 81, 82, 84, 86, 90,
            91, 92, 93, 94, 95, 98,
            211, 212, 213, 216, 218, 220, 221, 222, 223, 224, 225, 226, 227, 228, 229, 230,
            231, 232, 233, 234, 235, 236, 237, 238, 239, 240, 241, 242, 243, 244, 245, 246,
            248, 249, 250, 251, 252, 253, 254, 255, 256, 257, 258, 260, 261, 262, 263, 264,
            265, 266, 267, 268, 269, 290, 291, 297, 298, 299, 350, 351, 352, 353, 354, 355,
            356, 357, 358, 359, 370, 371, 372, 373, 374, 375, 376, 377, 378, 380, 381, 382,
            383, 385, 386, 387, 389, 420, 421, 423, 500, 501, 502, 503, 504, 505, 506, 507,
            508, 509, 590, 591, 592, 593, 594, 595, 596, 597, 598, 599, 670, 672, 673, 674,
            675, 676, 677, 678, 679, 680, 681, 682, 683, 685, 686, 687, 688, 689, 690, 691,
            692, 850, 852, 853, 855, 856, 880, 886, 960, 961, 962, 963, 964, 965, 966, 967,
            968, 970, 971, 972, 973, 974, 975, 976, 977, 992, 993, 994, 995, 996, 998,
        ];
        $codes = array_flip(array_map('strval', $list));
    }
    $len = strlen($digits);
    for ($n = 3; $n >= 1; $n--) {
        if ($len <= $n) continue;
        $cc = substr($digits, 0, $n);
        if (!isset($codes[$cc])) continue;
        $nat = $len - $n;                       // national digits after the code
        if ($n === 1) {                          // 1-digit codes (+1 NANP, +7) → full length only
            if ($nat === 10) return $cc;
            continue;
        }
        if ($nat >= 6 && $nat <= 12) return $cc; // 2–3 digit codes: plausible national length
    }
    return null;
}

/** Countries for the import country selectors: dialing code => label. Gulf/MENA first. */
function country_dialing_list(): array
{
    return [
        '20'  => 'Egypt (+20)',
        '966' => 'Saudi Arabia (+966)',
        '971' => 'UAE (+971)',
        '965' => 'Kuwait (+965)',
        '974' => 'Qatar (+974)',
        '973' => 'Bahrain (+973)',
        '968' => 'Oman (+968)',
        '962' => 'Jordan (+962)',
        '961' => 'Lebanon (+961)',
        '963' => 'Syria (+963)',
        '964' => 'Iraq (+964)',
        '970' => 'Palestine (+970)',
        '967' => 'Yemen (+967)',
        '249' => 'Sudan (+249)',
        '218' => 'Libya (+218)',
        '216' => 'Tunisia (+216)',
        '213' => 'Algeria (+213)',
        '212' => 'Morocco (+212)',
        '90'  => 'Turkey (+90)',
        '44'  => 'United Kingdom (+44)',
        '1'   => 'USA / Canada (+1)',
        '33'  => 'France (+33)',
        '49'  => 'Germany (+49)',
        '39'  => 'Italy (+39)',
        '34'  => 'Spain (+34)',
        '91'  => 'India (+91)',
        '92'  => 'Pakistan (+92)',
        '234' => 'Nigeria (+234)',
        '27'  => 'South Africa (+27)',
        '254' => 'Kenya (+254)',
    ];
}

/**
 * Reusable "Auto-detect / Choose a country" picker for import forms.
 * Renders a mode <select> plus a hidden full-country <select name="$name"> shown only when
 * "Choose a country" is picked. Self-contained (inline JS, unique id per call) and safe even
 * if country_dialing_list() is somehow unavailable (falls back to a short list — so a stale
 * helpers.php can never blank the page).
 */
function country_picker_html(string $name = 'import_country', string $current = ''): string
{
    static $seq = 0; $seq++;
    $uid  = 'ccp' . $seq;
    $list = function_exists('country_dialing_list') ? country_dialing_list()
          : ['20' => 'Egypt (+20)', '966' => 'Saudi Arabia (+966)', '971' => 'UAE (+971)',
             '965' => 'Kuwait (+965)', '974' => 'Qatar (+974)', '973' => 'Bahrain (+973)',
             '968' => 'Oman (+968)', '962' => 'Jordan (+962)', '1' => 'USA / Canada (+1)',
             '44' => 'United Kingdom (+44)'];
    $current = preg_replace('/\D+/', '', $current);
    $manual  = $current !== '';

    $opts = '<option value="">— select country —</option>';
    foreach ($list as $cc => $label) {
        $opts .= '<option value="' . e((string) $cc) . '"' . ($current === (string) $cc ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $autoSel = $manual ? '' : ' selected';
    $manSel  = $manual ? ' selected' : '';
    $hidden  = $manual ? '' : ' style="display:none;margin-top:8px"';
    $shown   = $manual ? ' style="margin-top:8px"' : '';

    return '<select class="cc-mode" onchange="var w=document.getElementById(\'' . $uid . '\');'
         . 'var m=this.value===\'manual\';w.style.display=m?\'block\':\'none\';w.style.marginTop=\'8px\';'
         . 'if(!m)w.querySelector(\'select\').value=\'\';">'
         . '<option value="auto"' . $autoSel . '>Auto-detect (recommended)</option>'
         . '<option value="manual"' . $manSel . '>Choose a country</option>'
         . '</select>'
         . '<div id="' . $uid . '"' . ($manual ? $shown : $hidden) . '>'
         . '<select name="' . e($name) . '" class="cc-list">' . $opts . '</select>'
         . '</div>';
}

function normalize_phone(string $raw, string $defaultCountry = ''): string
{
    $raw = trim($raw);
    if ($raw === '') return '';

    $hasPlus = str_starts_with($raw, '+') || str_starts_with($raw, '00');
    $digits  = preg_replace('/\D+/', '', $raw);
    if ($digits === '') return '';

    // 00-prefixed international → drop the 00, treat as already international.
    if (str_starts_with($digits, '00')) {
        $digits  = substr($digits, 2);
        $hasPlus = true;
    }

    if (!$hasPlus) {
        $cc = preg_replace('/\D+/', '', $defaultCountry);
        if (str_starts_with($digits, '0')) {
            // Leading trunk zero → definitely a local number → complete with default code.
            $local  = ltrim($digits, '0');
            $digits = $cc !== '' ? $cc . $local : $local;
        } elseif ($cc !== '' && str_starts_with($digits, $cc) && strlen($digits) >= strlen($cc) + 8) {
            // Already begins with the account's own country code → leave as-is.
        } elseif (phone_detect_cc($digits) !== null) {
            // Already carries some (other) country's code → respect it, don't prepend.
        } elseif ($cc !== '') {
            // Bare local number → complete with the account default code.
            $digits = $cc . $digits;
        }
    }

    // Plausible E.164: 8–15 digits.
    if (strlen($digits) < 8 || strlen($digits) > 15) return '';

    return $digits; // stored without '+', WhatsApp API accepts bare digits
}

/* ── format bytes / small utils ── */
function old(string $key, string $default = ''): string
{
    return e((string) ($_POST[$key] ?? $default));
}

/* ── CAPTCHA ──
   Enabled only when configured AND GD is available (so a host without GD
   degrades gracefully instead of blocking every login with a broken image). */
function captcha_enabled(): bool
{
    return (bool) config('captcha_enabled', true) && function_exists('imagecreatetruecolor');
}

/** One-time check of the submitted CAPTCHA against the session code. */
function check_captcha(string $input): bool
{
    $code = (string) ($_SESSION['captcha_code'] ?? '');
    unset($_SESSION['captcha_code']); // consume regardless, to prevent reuse
    return $code !== '' && strtoupper(trim($input)) === strtoupper($code);
}

/**
 * Fire-and-forget kick of the background worker so sending starts immediately
 * (instead of waiting up to a minute for the cron). Safe to call from any page:
 * it opens a very short-timeout request to cron/dispatch.php and returns at once;
 * the worker keeps running server-side (ignore_user_abort). The cron remains the
 * reliable heartbeat for scheduled campaigns + automations.
 */
/**
 * The app's public base URL, with no trailing slash — e.g. https://app.gildana.net.
 * Prefers config('base_url'); otherwise derives it from the request, stripping a trailing
 * /client or /admin so we always land on the app root.
 */
/**
 * Send the response now and keep working after the caller has hung up.
 *
 * Webhook senders time out on a slow response and retry, and a retry means handling the same
 * message twice. Answering an inbound can take seconds — an AI reply plus a send — so the
 * response has to be released before that work starts.
 *
 * fastcgi_finish_request() does this on PHP-FPM, but this app runs on Apache with mod_php,
 * where that function does not exist: the `if (function_exists(...))` guard around it silently
 * did nothing and the gateway waited for the whole AI round trip. On Apache the connection is
 * closed by declaring the length and finishing the buffer instead.
 */
function respond_and_continue(string $body = 'ok', string $contentType = 'text/plain'): void
{
    ignore_user_abort(true);                 // keep running once the caller disconnects
    if (!headers_sent()) {
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
    }
    // Drop any buffering that would otherwise hold the bytes back (mod_deflate included).
    while (ob_get_level() > 0) @ob_end_clean();
    echo $body;

    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); return; }
    @ob_flush();
    @flush();
}

/**
 * Fetch a URL supplied by a client, safely.
 *
 * Campaign header media is a URL the tenant types in, and the server fetches it. Without
 * these guards that turns the server into a proxy for anything it can reach: cloud metadata
 * endpoints (169.254.169.254), the gateway on the internal Docker network, a database admin
 * port on localhost. A redirect is enough to reach them even if the typed URL looks public,
 * which is why redirects are followed manually with a fresh check at every hop.
 *
 * Returns the body, or null if the URL is not safe to fetch or the fetch failed.
 */
function safe_http_get(string $url, int $maxBytes = 33554432, int $maxRedirects = 3): ?string
{
    for ($hop = 0; $hop <= $maxRedirects; $hop++) {
        if (!safe_http_url_ok($url)) return null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,   // every hop is re-checked above, never blindly followed
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'RevenectWA/1.0',
            // Stop the transfer as soon as it exceeds the cap, rather than buffering a
            // multi-gigabyte body into memory first.
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) use ($maxBytes) {
                return ($dlTotal > $maxBytes || $dlNow > $maxBytes) ? 1 : 0;
            },
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $loc  = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($http >= 300 && $http < 400 && $loc !== '') { $url = $loc; continue; }
        if ($body === false || $http < 200 || $http >= 300) return null;
        if (strlen((string) $body) > $maxBytes) return null;

        // Media only. An HTML error page is not something we should be uploading to Meta.
        $mime = strtolower(trim(explode(';', $type)[0] ?? ''));
        if ($mime !== '' && !preg_match('~^(image|video|audio|application/pdf)~', $mime)) return null;

        return (string) $body;
    }
    return null;   // too many redirects
}

/** True when a URL is a public HTTPS address we are willing to fetch. */
function safe_http_url_ok(string $url): bool
{
    $p = parse_url($url);
    if (!$p || strtolower((string) ($p['scheme'] ?? '')) !== 'https') return false;

    $host = (string) ($p['host'] ?? '');
    if ($host === '') return false;

    // Resolve first: a public-looking hostname can still point at 127.0.0.1.
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        foreach ([DNS_A, DNS_AAAA] as $t) {
            foreach (@dns_get_record($host, $t) ?: [] as $rec) {
                $ips[] = (string) ($rec['ip'] ?? $rec['ipv6'] ?? '');
            }
        }
        // Fall back to the resolver gethostbyname uses if dns_get_record found nothing.
        if (!$ips) { $r = gethostbyname($host); if ($r !== $host) $ips = [$r]; }
    }
    if (!$ips) return false;

    foreach ($ips as $ip) {
        if ($ip === '') continue;
        // NO_PRIV_RANGE covers 10/8, 172.16/12, 192.168/16, fc00::/7;
        // NO_RES_RANGE covers loopback, link-local (incl. 169.254.169.254) and 0.0.0.0/8.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // Carrier-grade NAT, which the filter above does not treat as private.
        if (preg_match('~^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.~', $ip)) return false;
    }
    return true;
}

function app_base_url(): string
{
    $base = rtrim((string) config('base_url', ''), '/');
    if ($base !== '') return $base;

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir  = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $root = (string) preg_replace('#/(client|admin)$#', '', $dir);
    // dirname() returns '/' at the web root, which would otherwise yield a double slash.
    if ($root === '/' || $root === '.' || $root === '\\') $root = '';
    return ($https ? 'https' : 'http') . '://' . $host . rtrim($root, '/');
}

function trigger_worker(): void
{
    /* Must use the CONFIGURED base url, never one derived from the request.
       app_base_url() falls back to the Host header when base_url is unset, and this call
       carries the cron token — so a forged Host would post the token straight to an
       attacker's server. Skip the kick rather than take that risk; the cron still runs the
       work a minute later. */
    $base = rtrim((string) config('base_url', ''), '/');
    if ($base === '') {
        error_log('trigger_worker: base_url is not set in config.php — skipping the instant send kick.');
        return;
    }

    try {
        $ch = curl_init($base . '/cron/dispatch.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_NOSIGNAL          => true,
            CURLOPT_TIMEOUT_MS        => 400,   // fire and forget
            CURLOPT_CONNECTTIMEOUT_MS => 400,
            // In a header rather than the query string: URLs end up in access logs, proxy
            // logs and Referer headers, and this token starts the worker for everyone.
            CURLOPT_HTTPHEADER        => ['X-Cron-Token: ' . (string) config('webhook_verify_token')],
        ]);
        @curl_exec($ch);
        @curl_close($ch);
    } catch (Throwable $e) { /* non-fatal — cron will still run it */ }
}

/* ── CSV cell: escape quotes AND neutralize spreadsheet formula injection ──
   A leading =, +, -, @, tab or CR is prefixed with a single quote so Excel/Sheets
   treats it as text, not a formula. */
function csv_cell(?string $value): string
{
    $v = (string) $value;
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $v = "'" . $v;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}

/**
 * Extract plain text from an uploaded knowledge document.
 * Supports .txt/.csv (raw) and .docx (Word XML via ZipArchive). Returns '' for anything else.
 * Pure PHP — safe on shared hosting; no external binaries.
 */
function extract_text_from_upload(string $tmpPath, string $name): string
{
    if ($tmpPath === '' || !is_readable($tmpPath)) return '';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($ext === 'txt' || $ext === 'csv' || $ext === 'md') {
        return trim((string) file_get_contents($tmpPath));
    }

    if ($ext === 'docx') {
        if (!class_exists('ZipArchive')) return '';
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) return '';
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || $xml === '') return '';
        // Paragraphs → newlines, tabs → tabs, then strip the rest of the XML.
        $xml = preg_replace('/<w:p\b[^>]*>/', "\n", $xml);
        $xml = preg_replace('/<w:tab\b[^>]*\/?>/', "\t", $xml);
        $txt = strip_tags($xml);
        $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse excess blank lines.
        $txt = preg_replace("/\n{3,}/", "\n\n", $txt);
        return trim((string) $txt);
    }

    return '';
}

/**
 * PWA <head> tags: manifest, theme colour, icons and iOS standalone hints.
 * $base is the path prefix back to the app root ('./' from the root, '../' from client/
 * and admin/). Emitted by every page so the app is installable from wherever the user
 * happens to land.
 */
function pwa_head(string $base = './'): string
{
    $b = rtrim($base, '/') . '/';
    $v = @filemtime(__DIR__ . '/../manifest.webmanifest') ?: '1';
    return
        '<link rel="manifest" href="' . $b . 'manifest.webmanifest?v=' . $v . '">' . "\n"
      . '<meta name="theme-color" content="#0D1321">' . "\n"
      . '<link rel="icon" type="image/png" href="' . $b . 'assets/icons/favicon.png">' . "\n"
      . '<link rel="apple-touch-icon" href="' . $b . 'assets/icons/apple-touch-icon.png">' . "\n"
      // iOS ignores the manifest: these are what make an added-to-home-screen app run
      // full-screen with the right status bar.
      . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
      . '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n"
      . '<meta name="apple-mobile-web-app-title" content="Revenect">' . "\n"
      . '<meta name="mobile-web-app-capable" content="yes">';
}

/** Registers the service worker. $base as per pwa_head(). */
function pwa_script(string $base = './'): string
{
    $b = rtrim($base, '/') . '/';
    return '<script>if("serviceWorker" in navigator){window.addEventListener("load",function(){'
         . 'navigator.serviceWorker.register("' . $b . 'sw.js").catch(function(e){console.warn("SW failed",e);});});}</script>';
}
