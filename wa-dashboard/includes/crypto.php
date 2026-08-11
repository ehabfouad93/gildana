<?php
declare(strict_types=1);

/**
 * Authenticated symmetric encryption for stored credentials
 * (WhatsApp access tokens, app secrets). AES-256-GCM.
 *
 * Stored format: base64( iv[12] . tag[16] . ciphertext )
 */

function crypto_key(): string
{
    static $key = null;
    if ($key === null) {
        $raw = (string) config('encryption_key');
        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            // Fall back to a hash so the app still runs, but warn in logs.
            error_log('crypto: encryption_key is not valid base64 32 bytes; deriving via hash.');
            $decoded = hash('sha256', $raw, true);
        }
        $key = $decoded;
    }
    return $key;
}

function encrypt_secret(string $plaintext): string
{
    if ($plaintext === '') return '';
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return base64_encode($iv . $tag . $ct);
}

function decrypt_secret(string $stored): string
{
    if ($stored === '') return '';
    $bin = base64_decode($stored, true);
    if ($bin === false || strlen($bin) < 29) return '';
    $iv  = substr($bin, 0, 12);
    $tag = substr($bin, 12, 16);
    $ct  = substr($bin, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? '' : $pt;
}

/** Mask a secret for display, e.g. "EAAB…9fZ" or "•••• set". */
function mask_secret(string $stored): string
{
    return $stored === '' ? '' : '•••• set';
}
