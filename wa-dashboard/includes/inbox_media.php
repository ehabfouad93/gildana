<?php
declare(strict_types=1);

/**
 * Pictures, voice notes, videos and files that people send.
 *
 * The webhook records where each one can be fetched (media_ref); the first time someone opens it
 * we fetch it once, keep a copy, and serve that copy from then on:
 *
 *   Business API   GET /{media id} gives a short-lived URL, which needs the account's token.
 *                  Meta keeps the file 30 days, so a copy is kept for good once opened.
 *   linked phone   the gateway hands the file over by the message's id.
 *
 * The copy is served only through client/media.php, to signed-in people allowed to see the
 * conversation. Its file name is random, so it cannot be guessed from the web either.
 */

require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/personal_wa.php';
require_once __DIR__ . '/sending.php';         // user_channel_client(): a salesperson's own phone

const INBOX_MEDIA_TYPES = ['image', 'audio', 'voice', 'video', 'document', 'sticker'];
const INBOX_MEDIA_MAX   = 25 * 1024 * 1024;      // bigger than WhatsApp lets anyone send

function inbox_media_dir(): string
{
    $dir = dirname(__DIR__) . '/uploads/inbound';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    // Unlike the rest of uploads/ (pictures WhatsApp must fetch for a campaign), nothing here is
    // for the public: Apache refuses it outright, so only client/media.php can hand it out.
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

/** What the Business API webhook says about a media message: [ref, mime, file name, caption]. */
function inbox_media_from_cloud(array $in): array
{
    $t = (string) ($in['type'] ?? '');
    $m = is_array($in[$t] ?? null) ? $in[$t] : [];
    if (!in_array($t, INBOX_MEDIA_TYPES, true) || empty($m['id'])) return [null, null, null, ''];
    return [(string) $m['id'], (string) ($m['mime_type'] ?? '') ?: null, isset($m['filename']) ? (string) $m['filename'] : null,
            trim((string) ($m['caption'] ?? ''))];
}

/** The same, for the linked phone's gateway payload. */
function inbox_media_from_gateway(array $d): array
{
    $m = (array) ($d['message'] ?? []);
    foreach (['imageMessage', 'audioMessage', 'videoMessage', 'documentMessage', 'stickerMessage', 'documentWithCaptionMessage'] as $k) {
        if (!isset($m[$k])) continue;
        $x = $k === 'documentWithCaptionMessage' ? (array) ($m[$k]['message']['documentMessage'] ?? []) : (array) $m[$k];
        return ['pw', (string) ($x['mimetype'] ?? '') ?: null, isset($x['fileName']) ? (string) $x['fileName'] : null];
    }
    return [null, null, null];
}

function inbox_media_ext(string $mime, ?string $name): string
{
    $fromName = $name ? strtolower(pathinfo($name, PATHINFO_EXTENSION)) : '';
    if (preg_match('/^[a-z0-9]{1,5}$/', $fromName)) return $fromName;
    $mime = strtolower(trim(explode(';', $mime)[0]));
    return ['audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/amr' => 'amr',
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'application/pdf' => 'pdf'][$mime] ?? 'bin';
}

/**
 * Our copy of a message's media, fetching it the first time.
 *
 * @return array{ok:bool, path?:string, mime?:string, name?:string, error?:string}
 */
function inbox_media_get(array $msg): array
{
    $mime = (string) ($msg['media_mime'] ?? '');
    if (!empty($msg['media_path']) && is_file($p = dirname(__DIR__) . '/' . $msg['media_path'])) {
        return ['ok' => true, 'path' => $p, 'mime' => $mime ?: (mime_content_type($p) ?: 'application/octet-stream'), 'name' => (string) ($msg['media_name'] ?? '')];
    }
    $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $msg['client_id']]);
    if (!$client) return ['ok' => false, 'error' => 'Account not found.'];
    $ref = (string) ($msg['media_ref'] ?? '');
    $bytes = null;

    if (str_starts_with($ref, 'url:')) {
        // Messenger / Instagram: Meta hands over a link to the file that works for a while. Fetched
        // with the same guard as any outside URL — public addresses only, size-capped, few redirects.
        $bytes = function_exists('safe_http_get') ? safe_http_get(substr($ref, 4), INBOX_MEDIA_MAX + 1, 3) : null;
        if ($bytes === null || $bytes === '') return ['ok' => false, 'error' => 'Facebook no longer has this file.'];
        if ($mime === '' && function_exists('finfo_buffer')) $mime = (string) (finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $bytes) ?: '');
    } elseif ($ref !== '' && $ref !== 'pw') {
        // Business API: the id gives a short-lived link, which only opens with the account's token.
        $token = wa_token($client);
        $meta = wa_request('GET', wa_graph_base() . '/' . rawurlencode($ref), $token);
        $url = (string) ($meta['json']['url'] ?? '');
        if ($url === '') return ['ok' => false, 'error' => 'WhatsApp no longer has this file (it keeps them for 30 days).'];
        $mime = (string) ($meta['json']['mime_type'] ?? $mime);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60,
                                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        $bytes = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($bytes === false || $http >= 400 || $bytes === '') return ['ok' => false, 'error' => 'Could not download the file from WhatsApp.'];
    } elseif ($ref === 'pw' || inbox_media_fetchable($msg, !empty($client['personal_instance']))) {
        // Linked phone: the gateway finds the file by the message's id — also for messages that
        // arrived before files were saved. A salesperson's own phone holds its own messages.
        $row = $client;
        if (($msg['via'] ?? '') === 'own') {
            $owner = (int) db_val("SELECT owner_user_id FROM contacts WHERE id=?", [(int) $msg['contact_id']]);
            $uc = $owner ? user_channel_for_media($owner) : null;
            if ($uc) $row = user_channel_client($client, $uc);
        }
        $res = pw_request('POST', '/chat/getBase64FromMediaMessage/' . rawurlencode(pw_instance($row)),
                          ['message' => ['key' => ['id' => (string) $msg['wa_message_id']]], 'convertToMp4' => false], 60);
        $b64 = (string) ($res['json']['base64'] ?? '');
        if ($res['error'] !== '' || $b64 === '') return ['ok' => false, 'error' => 'The phone no longer has this file.'];
        $bytes = base64_decode(preg_replace('/^data:[^,]*,/', '', $b64), true);
        $mime = (string) ($res['json']['mimetype'] ?? $mime);
        if ($bytes === false || $bytes === '') return ['ok' => false, 'error' => 'The phone sent an unreadable file.'];
    } else {
        return ['ok' => false, 'error' => 'This file arrived before files were saved, and cannot be fetched now.'];
    }
    if (strlen($bytes) > INBOX_MEDIA_MAX) return ['ok' => false, 'error' => 'The file is too large.'];

    $mime = $mime !== '' ? $mime : 'application/octet-stream';
    $rel = 'uploads/inbound/' . bin2hex(random_bytes(16)) . '.' . inbox_media_ext($mime, $msg['media_name'] ?? null);
    inbox_media_dir();
    if (file_put_contents(dirname(__DIR__) . '/' . $rel, $bytes) === false) return ['ok' => false, 'error' => 'Could not save the file.'];
    db_run("UPDATE messages SET media_path=?, media_mime=? WHERE id=?", [$rel, $mime, (int) $msg['id']]);
    return ['ok' => true, 'path' => dirname(__DIR__) . '/' . $rel, 'mime' => $mime, 'name' => (string) ($msg['media_name'] ?? '')];
}

/** A salesperson's own linked phone, for fetching what arrived on it. */
function user_channel_for_media(int $userId): ?array
{
    try { return db_row("SELECT * FROM user_channels WHERE user_id=?", [$userId]) ?: null; }
    catch (Throwable $e) { return null; }
}

/**
 * Can this message's file be had at all? False for Business-API ones from before files were
 * kept (no media id was stored), so the thread says so instead of offering a dead player.
 */
function inbox_media_fetchable(array $m, bool $clientHasPhone): bool
{
    if (!empty($m['media_path']) || !empty($m['media_ref'])) return true;
    return !empty($m['wa_message_id']) && (!empty($m['via']) || $clientHasPhone) && !str_starts_with((string) $m['wa_message_id'], 'wamid.');
}

/** Is this message one with a picture, voice note, video or file? */
function inbox_media_kind(array $m): ?string
{
    $t = (string) ($m['type'] ?? '');
    if (!in_array($t, INBOX_MEDIA_TYPES, true)) return null;
    return $t === 'voice' ? 'audio' : $t;
}
