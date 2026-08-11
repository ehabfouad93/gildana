<?php
declare(strict_types=1);

const SITE_DATA_FILE       = __DIR__ . '/../data/site.json';
const CONTACT_DATA_FILE    = __DIR__ . '/../data/contacts.json';
const NEWSLETTER_DATA_FILE = __DIR__ . '/../data/newsletter.json';
const CONFIG_FILE          = __DIR__ . '/../data/config.json';
const ADMIN_PASSWORD       = 'admin123';

/* ── escaping ── */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* ── site data ── */
function site_data(): array
{
    if (!file_exists(SITE_DATA_FILE)) return [];
    $data = json_decode((string) file_get_contents(SITE_DATA_FILE), true);
    return is_array($data) ? $data : [];
}

function save_site_data(array $data): bool
{
    return (bool) file_put_contents(
        SITE_DATA_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

function site_last_saved(): string
{
    return file_exists(SITE_DATA_FILE)
        ? date('d M Y, H:i', (int) filemtime(SITE_DATA_FILE))
        : 'Never';
}

/* ── generic json list helpers ── */
function read_json_list(string $file): array
{
    if (!file_exists($file)) return [];
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function write_json_list(string $file, array $rows): bool
{
    return (bool) file_put_contents(
        $file,
        json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

/* ── config (for hashed admin password) ── */
function read_config(): array
{
    if (!file_exists(CONFIG_FILE)) return [];
    $data = json_decode((string) file_get_contents(CONFIG_FILE), true);
    return is_array($data) ? $data : [];
}

function save_config(array $data): bool
{
    return (bool) file_put_contents(
        CONFIG_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

function check_password(string $input): bool
{
    $config = read_config();
    $hash   = $config['password_hash'] ?? '';
    return $hash !== '' ? password_verify($input, $hash) : hash_equals(ADMIN_PASSWORD, $input);
}

function change_password(string $newPassword): bool
{
    $config                  = read_config();
    $config['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    return save_config($config);
}

/* ── message management ── */
function delete_contact(int $index): bool
{
    $rows = read_json_list(CONTACT_DATA_FILE);
    if (!isset($rows[$index])) return false;
    array_splice($rows, $index, 1);
    return write_json_list(CONTACT_DATA_FILE, $rows);
}

function delete_newsletter_entry(string $email): bool
{
    $rows = read_json_list(NEWSLETTER_DATA_FILE);
    $rows = array_values(array_filter($rows, fn($r) => ($r['email'] ?? '') !== $email));
    return write_json_list(NEWSLETTER_DATA_FILE, $rows);
}

/* ── asset path ── */
function asset_path(?string $path): string
{
    $path = trim((string) $path);
    return $path !== '' ? e($path) : '';
}

/* ── image upload ── */
function upload_image(string $field, string $current = ''): string
{
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $current;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $name    = (string) $_FILES[$field]['name'];
    $ext     = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return $current;

    $dir = __DIR__ . '/../uploads/cms';
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $safe     = preg_replace('/[^a-z0-9-]+/', '-', strtolower(pathinfo($name, PATHINFO_FILENAME)));
    $filename = trim((string) $safe, '-') . '-' . time() . '-' . random_int(1000, 9999) . '.' . $ext;

    if (!move_uploaded_file((string) $_FILES[$field]['tmp_name'], $dir . '/' . $filename)) {
        return $current;
    }

    return 'uploads/cms/' . $filename;
}

/* ── auth ── */
function admin_logged_in(): bool
{
    return !empty($_SESSION['gildana_admin']);
}

function require_admin(): void
{
    if (!admin_logged_in()) { header('Location: index.php'); exit; }
}

/* ── csrf ── */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid security token.');
    }
}

/* ── form field helpers ── */
function text_field(string $name, string $label, string $value, string $type = 'text'): void
{
    echo '<label><span class="lbl">' . e($label) . '</span>'
        . '<input type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '"></label>';
}

function textarea_field(string $name, string $label, string $value, int $rows = 3): void
{
    echo '<label><span class="lbl">' . e($label) . '</span>'
        . '<textarea name="' . e($name) . '" rows="' . $rows . '">' . e($value) . '</textarea></label>';
}

function image_field(string $name, string $label, string $value): void
{
    echo '<label class="file-label"><span class="lbl">' . e($label) . '</span>'
        . '<input type="file" name="' . e($name) . '" accept="image/*" onchange="previewImage(this)"></label>';
    echo '<div class="image-preview" id="preview-' . e($name) . '">';
    if ($value !== '') {
        echo '<img src="../' . asset_path($value) . '" alt="">'
            . '<small class="img-path">' . e($value) . '</small>';
    }
    echo '</div>';
}

/* ── SVG icons ── */
function svg_arrow_right(int $size = 14): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 7 L12 7"/><path d="M8 3 L12 7 L8 11"/></svg>';
}

function svg_arrow_left(int $size = 14): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7 L2 7"/><path d="M6 3 L2 7 L6 11"/></svg>';
}

function service_icon(string $key): string
{
    $icons = [
        'strategy' => '<svg width="36" height="36" viewBox="0 0 36 36" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="18" r="13"/><circle cx="18" cy="18" r="8"/><circle cx="18" cy="18" r="3"/><path d="M18 5 V2 M18 34 V31 M5 18 H2 M34 18 H31"/></svg>',
        'branding'  => '<svg width="36" height="36" viewBox="0 0 36 36" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 30 L9 26 L26 9 L30 13 L13 30 L5 30 Z"/><path d="M22 9 L26 13"/><path d="M5 30 L8 27"/></svg>',
        'megaphone' => '<svg width="36" height="36" viewBox="0 0 36 36" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 14 L23 8 V28 L5 22 Z"/><path d="M5 14 V22"/><path d="M23 12 H28 A4 4 0 0 1 28 24 H23"/><path d="M9 22 L11 30"/></svg>',
        'box'       => '<svg width="36" height="36" viewBox="0 0 36 36" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 5 L31 11 V25 L18 31 L5 25 V11 Z"/><path d="M5 11 L18 17 L31 11"/><path d="M18 17 V31"/><path d="M11.5 8 L24.5 14" stroke-dasharray="2 2"/></svg>',
        'chart'     => '<svg width="36" height="36" viewBox="0 0 36 36" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 30 H31"/><rect x="8" y="20" width="4" height="10"/><rect x="16" y="14" width="4" height="16"/><rect x="24" y="8" width="4" height="22"/></svg>',
        'code'      => '<svg width="36" height="36" viewBox="0 0 36 36" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 11 L5 18 L12 25"/><path d="M24 11 L31 18 L24 25"/><path d="M21 7 L15 29"/></svg>',
    ];
    return $icons[$key] ?? $icons['strategy'];
}

function contact_icon(string $key): string
{
    $icons = [
        'phone' => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2 H5 L6 5 L4.5 6 A6 6 0 0 0 8 9.5 L9 8 L12 9 V11 A1 1 0 0 1 11 12 A9 9 0 0 1 2 3 A1 1 0 0 1 3 2 Z"/></svg>',
        'mail'  => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="10" height="8" rx="1"/><path d="M2 4 L7 8 L12 4"/></svg>',
        'pin'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 12.5 C4 9 2.5 7 2.5 5 A4.5 4.5 0 0 1 11.5 5 C11.5 7 10 9 7 12.5 Z"/><circle cx="7" cy="5" r="1.5"/></svg>',
        'chat'  => '<svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16 L3 6 A2 2 0 0 1 5 4 H17 A2 2 0 0 1 19 6 V14 A2 2 0 0 1 17 16 H7 L3 19 Z"/></svg>',
    ];
    return $icons[$key] ?? '';
}

function social_icon(string $key): string
{
    $icons = [
        'linkedin'  => '<svg width="14" height="14" viewBox="0 0 14 14" fill="currentColor"><rect x="2" y="5" width="2" height="7"/><circle cx="3" cy="3" r="1.2"/><path d="M5.5 5 H7.5 V6 C8 5.4 8.8 5 9.7 5 C11.4 5 12 6.2 12 7.8 V12 H10 V8.3 C10 7.5 9.7 6.9 8.9 6.9 C8 6.9 7.5 7.5 7.5 8.4 V12 H5.5 Z"/></svg>',
        'instagram' => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="2" y="2" width="10" height="10" rx="3"/><circle cx="7" cy="7" r="2.4"/><circle cx="10.2" cy="3.8" r="0.5" fill="currentColor"/></svg>',
        'facebook'  => '<svg width="14" height="14" viewBox="0 0 14 14" fill="currentColor"><path d="M8.5 12.5 V8 H10.2 L10.5 6 H8.5 V4.8 C8.5 4.2 8.7 3.8 9.6 3.8 H10.6 V2 C10.4 2 9.7 1.9 9 1.9 C7.5 1.9 6.5 2.8 6.5 4.5 V6 H4.8 V8 H6.5 V12.5 H8.5 Z"/></svg>',
        'behance'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M1.5 4 H5 A1.5 1.5 0 0 1 5 7 H1.5 Z"/><path d="M1.5 7 H5.4 A1.7 1.7 0 0 1 5.4 10.4 H1.5 Z"/><path d="M8 6 H12.5"/><path d="M8.2 8 A2.2 2 0 1 0 12.3 8.5 H8.2 A2.2 2 0 1 0 10.3 11"/></svg>',
    ];
    return $icons[$key] ?? $icons['instagram'];
}
