<?php
declare(strict_types=1);

/**
 * Google account connection and Sheets access.
 *
 * The client presses "Connect Google", approves once, and picks a spreadsheet — no scripts to
 * paste, no service-account JSON, no Cloud project of their own. The operator registers ONE
 * OAuth client for the whole platform (Admin → Settings → Google); every client then rides on
 * it with their own tokens.
 *
 * Scope choice matters. `spreadsheets` would let us list and open anything in the client's
 * Drive, and Google classes it as sensitive: using it means an app-verification review before
 * anyone outside the test list can connect. `drive.file` grants access only to the files the
 * user actively chose through the Google Picker — enough to read and write those sheets, not
 * sensitive, and no review. The cost is that we cannot browse their Drive ourselves, which is
 * why choosing a sheet goes through the Picker rather than a list we build.
 *
 * Endpoints are indirected through google_endpoint() so the test suite can point the whole
 * flow at a local mock, the same way the Graph API and the WhatsApp gateway are tested.
 */

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/helpers.php';

const GOOGLE_SCOPES = 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email';

/* ── platform configuration (set once by the operator) ── */

function google_cfg(): array
{
    $get = function (string $k): string {
        try { return trim((string) db_val("SELECT v FROM app_settings WHERE k=?", [$k])); }
        catch (Throwable $e) { return ''; }
    };
    return [
        'client_id'     => $get('google_client_id'),
        'client_secret' => decrypt_secret($get('google_client_secret')),
        'api_key'       => $get('google_api_key'),      // browser key, for the Picker only
    ];
}

function google_configured(): bool
{
    $c = google_cfg();
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

/** Where this install receives the OAuth redirect. Must match the Cloud console exactly. */
function google_redirect_uri(): string
{
    return rtrim(app_base_url(), '/') . '/google_oauth.php';
}

function google_endpoint(string $which): string
{
    $base = trim((string) config('google_base', ''));   // tests point this at a local mock
    if ($base !== '') return rtrim($base, '/') . '/' . $which;
    return [
        'auth'     => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token'    => 'https://oauth2.googleapis.com/token',
        'userinfo' => 'https://www.googleapis.com/oauth2/v3/userinfo',
        'sheets'   => 'https://sheets.googleapis.com/v4/spreadsheets',
    ][$which] ?? '';
}

/* ── HTTP ── */

function google_http(string $method, string $url, array $opts = []): array
{
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    $o = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => (int) ($opts['timeout'] ?? 25),
        CURLOPT_CONNECTTIMEOUT => 8,
    ];
    if (isset($opts['form'])) {
        $o[CURLOPT_POSTFIELDS] = http_build_query($opts['form']);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif (isset($opts['json'])) {
        $o[CURLOPT_POSTFIELDS] = json_encode($opts['json'], JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }
    if ($headers) $o[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $o);

    $raw  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return ['http' => 0, 'json' => null, 'error' => $cerr ?: 'Could not reach Google'];
    $json = json_decode((string) $raw, true);
    $err  = '';
    if ($http < 200 || $http >= 300) {
        // Google nests the readable part; `error` alone is often just a slug.
        $err = (string) ($json['error']['message'] ?? $json['error_description'] ?? $json['error'] ?? ('HTTP ' . $http));
        error_log('google ' . $method . ' ' . parse_url($url, PHP_URL_PATH) . ' → ' . $http . ' ' . substr((string) $raw, 0, 300));
    }
    return ['http' => $http, 'json' => is_array($json) ? $json : null, 'error' => $err];
}

/* ── the consent round trip ── */

/** Start the flow: a one-time state row plus the URL to send the browser to. */
function google_auth_url(int $clientId, ?int $userId, string $returnTo = ''): string
{
    $state = bin2hex(random_bytes(24));
    db_run("INSERT INTO google_oauth_state (state,client_id,user_id,return_to,created_at) VALUES (?,?,?,?,NOW())",
        [$state, $clientId, $userId, mb_substr($returnTo, 0, 190)]);
    // Old rows are worthless after a few minutes and would otherwise accumulate forever.
    try { db_run("DELETE FROM google_oauth_state WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"); } catch (Throwable $e) {}

    $c = google_cfg();
    return google_endpoint('auth') . '?' . http_build_query([
        'client_id'     => $c['client_id'],
        'redirect_uri'  => google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => GOOGLE_SCOPES,
        // offline + consent is what actually yields a refresh token: without them Google
        // returns one only on the very first authorisation ever, and a client who reconnects
        // ends up with an account that silently stops working an hour later.
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'include_granted_scopes' => 'true',
        'state'         => $state,
    ]);
}

/** Consume a state value exactly once. */
function google_take_state(string $state): ?array
{
    if ($state === '') return null;
    $row = db_row("SELECT * FROM google_oauth_state WHERE state=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$state]);
    if ($row) db_run("DELETE FROM google_oauth_state WHERE state=?", [$state]);
    return $row ?: null;
}

/** Exchange the authorisation code and store the connection. */
function google_finish_connect(int $clientId, string $code): array
{
    $c = google_cfg();
    $r = google_http('POST', google_endpoint('token'), ['form' => [
        'code'          => $code,
        'client_id'     => $c['client_id'],
        'client_secret' => $c['client_secret'],
        'redirect_uri'  => google_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]]);
    if ($r['error'] !== '') return ['ok' => false, 'error' => $r['error']];

    $refresh = (string) ($r['json']['refresh_token'] ?? '');
    $access  = (string) ($r['json']['access_token'] ?? '');
    $expires = (int) ($r['json']['expires_in'] ?? 3600);
    if ($access === '') return ['ok' => false, 'error' => 'Google did not return an access token.'];
    if ($refresh === '') {
        return ['ok' => false, 'error' => 'Google did not return a refresh token. Remove this app at '
              . 'myaccount.google.com → Security → Third-party access, then connect again.'];
    }

    $email = '';
    $u = google_http('GET', google_endpoint('userinfo'), ['headers' => ['Authorization: Bearer ' . $access]]);
    if ($u['error'] === '') $email = (string) ($u['json']['email'] ?? '');

    db_run("UPDATE clients SET google_refresh_enc=?, google_access_enc=?, google_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND),
                   google_email=?, google_connected_at=NOW() WHERE id=?",
        [encrypt_secret($refresh), encrypt_secret($access), max(60, $expires - 60), $email, $clientId]);

    return ['ok' => true, 'email' => $email, 'error' => ''];
}

function google_connected(array $client): bool
{
    return trim((string) ($client['google_refresh_enc'] ?? '')) !== '';
}

function google_disconnect(int $clientId): void
{
    db_run("UPDATE clients SET google_refresh_enc=NULL, google_access_enc=NULL, google_expires_at=NULL,
                   google_email=NULL, google_connected_at=NULL WHERE id=?", [$clientId]);
}

/** A usable access token, refreshed when the cached one has aged out. '' when not connected. */
function google_access_token(array $client): string
{
    $cached = decrypt_secret((string) ($client['google_access_enc'] ?? ''));
    $exp    = $client['google_expires_at'] ?? null;
    if ($cached !== '' && $exp !== null && strtotime((string) $exp) > time()) return $cached;

    $refresh = decrypt_secret((string) ($client['google_refresh_enc'] ?? ''));
    if ($refresh === '') return '';

    $c = google_cfg();
    $r = google_http('POST', google_endpoint('token'), ['form' => [
        'refresh_token' => $refresh,
        'client_id'     => $c['client_id'],
        'client_secret' => $c['client_secret'],
        'grant_type'    => 'refresh_token',
    ]]);
    $access = (string) ($r['json']['access_token'] ?? '');
    if ($access === '') return '';

    $expires = (int) ($r['json']['expires_in'] ?? 3600);
    db_run("UPDATE clients SET google_access_enc=?, google_expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?",
        [encrypt_secret($access), max(60, $expires - 60), (int) $client['id']]);
    return $access;
}

/* ── Sheets ── */

function google_sheets_call(array $client, string $method, string $path, array $opts = []): array
{
    $token = google_access_token($client);
    if ($token === '') return ['http' => 0, 'json' => null, 'error' => 'Google account is not connected.'];
    $opts['headers'] = array_merge($opts['headers'] ?? [], ['Authorization: Bearer ' . $token]);
    return google_http($method, google_endpoint('sheets') . $path, $opts);
}

/** Tab names in a spreadsheet, so the client can choose which one to use. */
function google_sheet_tabs(array $client, string $spreadsheetId): array
{
    $r = google_sheets_call($client, 'GET', '/' . rawurlencode($spreadsheetId) . '?fields=properties.title,sheets.properties.title');
    if ($r['error'] !== '') return ['ok' => false, 'title' => '', 'tabs' => [], 'error' => $r['error']];
    $tabs = [];
    foreach ((array) ($r['json']['sheets'] ?? []) as $s) {
        $t = (string) ($s['properties']['title'] ?? '');
        if ($t !== '') $tabs[] = $t;
    }
    return ['ok' => true, 'title' => (string) ($r['json']['properties']['title'] ?? ''), 'tabs' => $tabs, 'error' => ''];
}

/** Rows from a tab, first row treated as the header. */
function google_sheet_rows(array $client, string $spreadsheetId, string $tab, int $limit = 5000): array
{
    $range = $tab !== '' ? $tab . '!A1:Z' . max(2, $limit) : 'A1:Z' . max(2, $limit);
    $r = google_sheets_call($client, 'GET', '/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range));
    if ($r['error'] !== '') return ['ok' => false, 'header' => [], 'rows' => [], 'error' => $r['error']];

    $values = (array) ($r['json']['values'] ?? []);
    $header = array_map(fn($h) => trim((string) $h), (array) array_shift($values));
    return ['ok' => true, 'header' => $header, 'rows' => $values, 'error' => ''];
}

/**
 * Append rows to a tab, creating the header line when the sheet is still empty.
 * USER_ENTERED so a date or number the client writes behaves like one they typed.
 */
function google_sheet_append(array $client, string $spreadsheetId, string $tab, array $header, array $rows): array
{
    if (!$rows) return ['ok' => true, 'appended' => 0, 'error' => ''];

    $existing = google_sheet_rows($client, $spreadsheetId, $tab, 2);
    if ($existing['ok'] && !$existing['header'] && $header) array_unshift($rows, $header);

    $range = ($tab !== '' ? $tab . '!' : '') . 'A1';
    $r = google_sheets_call($client, 'POST',
        '/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range)
        . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
        ['json' => ['values' => array_values($rows)]]);
    if ($r['error'] !== '') return ['ok' => false, 'appended' => 0, 'error' => $r['error']];
    return ['ok' => true, 'appended' => count($rows), 'error' => ''];
}
