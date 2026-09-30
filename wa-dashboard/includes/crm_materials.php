<?php
declare(strict_types=1);

/**
 * Sales material: what the team needs to hand a buyer — brochures and renders, price lists and
 * payment plans, contract templates, the sales pitch. An Admin adds files (or a link) under one of
 * four categories and, optionally, a project; everyone with the CRM can find, preview and download
 * them. Files live under uploads/materials, which the web server refuses; client/material_file.php
 * hands them out to signed-in people of the same account only.
 */

const CRM_MATERIAL_MAX = 30 * 1024 * 1024;

function crm_material_categories(): array
{
    return ['marketing' => 'Marketing material', 'sales' => 'Sales material', 'legal' => 'Legal material', 'financial' => 'Financial material'];
}

/** What may be uploaded: pictures, documents, spreadsheets, presentations, video, archives. */
function crm_material_types(): array
{
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
            'pdf' => 'application/pdf', 'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain', 'csv' => 'text/csv', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'mp3' => 'audio/mpeg', 'zip' => 'application/zip'];
}

function crm_material_dir(int $clientId): string
{
    $base = dirname(__DIR__) . '/uploads/materials';
    if (!is_dir($base)) @mkdir($base, 0775, true);
    if (!is_file($base . '/.htaccess')) {
        @file_put_contents($base . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $dir = $base . '/' . $clientId;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/** The kind of thing, for the icon and whether it previews: image, video, audio, pdf, doc, sheet, slides, file, link. */
function crm_material_kind(array $m): string
{
    if (empty($m['file_path'])) return 'link';
    $mime = (string) $m['mime'];
    if (str_starts_with($mime, 'image/')) return 'image';
    if (str_starts_with($mime, 'video/')) return 'video';
    if (str_starts_with($mime, 'audio/')) return 'audio';
    if ($mime === 'application/pdf') return 'pdf';
    $ext = strtolower(pathinfo((string) $m['file_name'], PATHINFO_EXTENSION));
    return match (true) { in_array($ext, ['doc', 'docx', 'txt'], true) => 'doc', in_array($ext, ['xls', 'xlsx', 'csv'], true) => 'sheet',
                          in_array($ext, ['ppt', 'pptx'], true) => 'slides', default => 'file' };
}

/**
 * Add one piece of material: a file from $_FILES, or a link.
 * @return array{ok:bool, error?:string, id?:int}
 */
function crm_material_add(int $clientId, array $d, ?array $file, ?int $by): array
{
    $cat = (string) ($d['category'] ?? '');
    if (!isset(crm_material_categories()[$cat])) return ['ok' => false, 'error' => 'Choose a category.'];
    $title = mb_substr(trim((string) ($d['title'] ?? '')), 0, 160);
    $link = trim((string) ($d['link_url'] ?? ''));
    $hasFile = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!$hasFile && $link === '') return ['ok' => false, 'error' => 'Choose a file, or paste a link.'];
    $row = ['client_id' => $clientId, 'category' => $cat, 'description' => mb_substr(trim((string) ($d['description'] ?? '')), 0, 500) ?: null,
            'project_id' => (int) ($d['project_id'] ?? 0) ?: null, 'created_by' => $by, 'created_at' => date('Y-m-d H:i:s')];
    if ($row['project_id'] && !db_val("SELECT COUNT(*) FROM crm_projects WHERE id=? AND client_id=?", [$row['project_id'], $clientId])) $row['project_id'] = null;
    if ($hasFile) {
        if ((int) $file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'The file did not upload — it may be larger than the server allows.'];
        if ((int) $file['size'] > CRM_MATERIAL_MAX) return ['ok' => false, 'error' => 'That file is over 30 MB.'];
        $name = basename(str_replace('\\', '/', (string) $file['name']));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $types = crm_material_types();
        if (!isset($types[$ext])) return ['ok' => false, 'error' => 'That kind of file is not accepted. Use pictures, PDF, Word, Excel, PowerPoint, video, audio or ZIP.'];
        $stored = bin2hex(random_bytes(12)) . '.' . $ext;                 // never the uploaded name on disk
        $dir = crm_material_dir($clientId);
        if (!move_uploaded_file((string) $file['tmp_name'], "$dir/$stored")) return ['ok' => false, 'error' => 'Could not save the file.'];
        $row += ['file_path' => $clientId . '/' . $stored, 'file_name' => mb_substr($name, 0, 200), 'mime' => $types[$ext], 'size_bytes' => (int) $file['size']];
        if ($title === '') $title = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 160);
    } else {
        if (!preg_match('~^https?://~i', $link)) $link = 'https://' . $link;
        if (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('~^https?://[^\s<>"]+$~i', $link)) return ['ok' => false, 'error' => 'That link does not look right.'];
        $row['link_url'] = mb_substr($link, 0, 500);
        if ($title === '') return ['ok' => false, 'error' => 'Give the link a title.'];
    }
    $row['title'] = $title;
    $id = db_insert("INSERT INTO crm_materials (" . implode(',', array_keys($row)) . ") VALUES (" . rtrim(str_repeat('?,', count($row)), ',') . ")", array_values($row));
    return ['ok' => true, 'id' => $id];
}

function crm_material_delete(int $clientId, int $id): bool
{
    $m = db_row("SELECT * FROM crm_materials WHERE id=? AND client_id=?", [$id, $clientId]);
    if (!$m) return false;
    if (!empty($m['file_path'])) @unlink(dirname(__DIR__) . '/uploads/materials/' . $m['file_path']);
    db_run("DELETE FROM crm_materials WHERE id=?", [$id]);
    return true;
}

/** Filtered list: category, project, words. */
function crm_materials(int $clientId, array $f = []): array
{
    $sql = "SELECT m.*, p.name AS project_name, COALESCE(NULLIF(u.name,''), u.email) AS by_name
              FROM crm_materials m LEFT JOIN crm_projects p ON p.id=m.project_id LEFT JOIN users u ON u.id=m.created_by
             WHERE m.client_id=?";
    $p = [$clientId];
    if (!empty($f['cat']) && isset(crm_material_categories()[$f['cat']])) { $sql .= " AND m.category=?"; $p[] = $f['cat']; }
    if (!empty($f['project'])) { $sql .= " AND m.project_id=?"; $p[] = (int) $f['project']; }
    if (($q = trim((string) ($f['q'] ?? ''))) !== '') { $sql .= " AND (m.title LIKE ? OR m.description LIKE ? OR m.file_name LIKE ?)"; array_push($p, "%$q%", "%$q%", "%$q%"); }
    return db_all($sql . " ORDER BY m.id DESC LIMIT 500", $p);
}

function crm_material_size(?int $b): string
{
    if (!$b) return '';
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
}
