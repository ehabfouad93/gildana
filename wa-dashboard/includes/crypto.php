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
            /* Refuse to run rather than deriving a key by hashing whatever was there.
               That fallback meant an install left on the sample value encrypted every
               tenant's WhatsApp token with a key anyone could compute from a public
               string — and it did so silently, with only a line in the error log. */
            error_log('crypto: encryption_key is not 32 bytes of base64 — refusing to start.');
            throw new RuntimeException(
                'encryption_key in config.php must be 32 random bytes, base64-encoded. '
              . 'Generate one with: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"'
            );
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
