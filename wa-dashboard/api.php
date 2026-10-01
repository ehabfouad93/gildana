<?php
declare(strict_types=1);
/**
 * The CRM's API, for another CRM or any system to read and write leads.
 *
 *   Authorization: Bearer <key from CRM → Integrations>
 *
 *   GET    /api.php/v1/me                         the key and its account
 *   GET    /api.php/v1/leads                      ?updated_since=&created_since=&stage=&owner_email=&phone=&external_id=&page=&per_page=
 *   GET    /api.php/v1/leads/{ref}                ref = id, lead code (A1B2C3) or ext:<their id>
 *   POST   /api.php/v1/leads                      create — or update the lead with the same external_id / phone
 *   PATCH  /api.php/v1/leads/{ref}                change only the fields sent
 *   GET    /api.php/v1/leads/{ref}/activities
 *   POST   /api.php/v1/leads/{ref}/activities     {kind: call|whatsapp|meeting|visit|email|note, outcome, body}
 *   GET    /api.php/v1/stages | /users | /projects | /fields
 *
 * The path can also be given as ?path=/v1/leads where the server does not pass PATH_INFO.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/crm.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = function (int $status, $data, array $meta = []): void {
    http_response_code($status);
    echo json_encode($status >= 400 ? ['error' => ['status' => $status, 'message' => $data]] : (['data' => $data] + ($meta ? ['meta' => $meta] : [])),
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
};
if (!crm_int_ready()) $out(503, 'The integration tables are not installed yet (migration 058).');

// The key: "Authorization: Bearer …", or X-Api-Key for systems that cannot set Authorization.
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('getallheaders')) foreach (getallheaders() as $h => $v) if (strcasecmp($h, 'Authorization') === 0) $auth = (string) $v;
$key = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
$a = crm_api_key_auth($key);
if (isset($a['error'])) $out($a['status'], $a['error']);
[$K, $CLIENT] = [$a['key'], $a['client']];
$GLOBALS['CLIENT'] = $CLIENT;
$cid = (int) $CLIENT['id'];
$canWrite = $K['access'] === 'write';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = (string) ($_SERVER['PATH_INFO'] ?? ($_GET['path'] ?? ''));
$parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
if (($parts[0] ?? '') !== 'v1') $out(404, 'Use /api.php/v1/… — for example /api.php/v1/leads.');
array_shift($parts);
$body = [];
if (in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
    if (!$canWrite) $out(403, 'This key can only read. Make a read & write key on the Integrations page.');
    $raw = (string) file_get_contents('php://input');
    $body = $raw !== '' ? json_decode($raw, true) : $_POST;
    if (!is_array($body)) $out(400, 'The body must be a JSON object.');
}

/** A lead by id, code or ext:<their id>, in this account and in the pipeline (or the recycle bin, for reading). */
$find = function (string $ref) use ($cid, $out): array {
    if (str_starts_with($ref, 'ext:')) $c = db_row("SELECT id FROM contacts WHERE client_id=? AND external_id=?", [$cid, urldecode(substr($ref, 4))]);
    elseif (ctype_digit($ref)) $c = db_row("SELECT id FROM contacts WHERE client_id=? AND id=?", [$cid, (int) $ref]);
    else $c = db_row("SELECT id FROM contacts WHERE client_id=? AND code=?", [$cid, strtoupper(ltrim($ref, '#'))]);
    if (!$c || !($l = crm_int_lead((int) $c['id'])) || ($l['stage_id'] === null)) $out(404, 'No lead ' . $ref . '.');
    return $l;
};

$res = $parts[0] ?? '';
switch (true) {
    case $res === 'me' && $method === 'GET':
        $out(200, ['key' => ['name' => $K['name'], 'prefix' => $K['prefix'], 'access' => $K['access'], 'system' => $K['system']],
                   'account' => ['id' => $cid, 'name' => $CLIENT['name']]]);

    case $res === 'stages' && $method === 'GET':
        $out(200, array_values(array_map(fn($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'type' => $s['kind'],
                                                     'substatuses' => crm_substatus_list($cid, (int) $s['id'])], crm_stages($cid))));

    case $res === 'users' && $method === 'GET':
        $out(200, array_map(fn($u) => ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['client_role']],
                            db_all("SELECT id, COALESCE(NULLIF(name,''), email) name, email, client_role FROM users WHERE client_id=? AND status='active' ORDER BY name", [$cid])));

    case $res === 'projects' && $method === 'GET':
        $out(200, array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name']], crm_projects($cid)));

    case $res === 'fields' && $method === 'GET':
        $out(200, ['lead' => crm_int_fields(), 'custom' => array_map(fn($f) => ['key' => $f['fkey'], 'label' => $f['label'], 'type' => $f['type'], 'choices' => $f['choices'] ?? []],
                                                                     function_exists('crm_fields') ? crm_fields($cid) : [])]);

    case $res === 'leads' && !isset($parts[1]) && $method === 'GET':
        $w = "c.client_id=? AND c.stage_id IS NOT NULL"; $p = [$cid];
        if (($v = trim((string) ($_GET['updated_since'] ?? ''))) !== '') {
            if (!strtotime($v)) $out(400, 'updated_since is not a date.');
            $w .= " AND (c.crm_added_at >= ? OR c.id IN (SELECT e.contact_id FROM crm_events e WHERE e.client_id=? AND e.created_at >= ?))";
            array_push($p, date('Y-m-d H:i:s', strtotime($v)), $cid, date('Y-m-d H:i:s', strtotime($v)));
        }
        if (($v = trim((string) ($_GET['created_since'] ?? ''))) !== '' && strtotime($v)) { $w .= " AND c.crm_added_at >= ?"; $p[] = date('Y-m-d H:i:s', strtotime($v)); }
        if (($v = trim((string) ($_GET['stage'] ?? ''))) !== '') { $w .= " AND c.stage_id=?"; $p[] = crm_int_stage_id($cid, $v); }
        if (($v = trim((string) ($_GET['owner_email'] ?? ''))) !== '') { $w .= " AND c.owner_user_id=(SELECT id FROM users WHERE client_id=? AND email=?)"; array_push($p, $cid, $v); }
        if (($v = trim((string) ($_GET['external_id'] ?? ''))) !== '') { $w .= " AND c.external_id=?"; $p[] = $v; }
        if (($v = trim((string) ($_GET['phone'] ?? ''))) !== '') { $w .= " AND c.phone_e164=?"; $p[] = normalize_phone($v, (string) ($CLIENT['default_country'] ?? '')); }
        $per = max(1, min(200, (int) ($_GET['per_page'] ?? 50))); $page = max(1, (int) ($_GET['page'] ?? 1));
        $total = (int) db_val("SELECT COUNT(*) FROM contacts c WHERE $w", $p);
        $ids = array_column(db_all("SELECT c.id FROM contacts c WHERE $w ORDER BY c.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p), 'id');
        $out(200, array_map(fn($i) => crm_int_lead((int) $i), $ids), ['page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => (int) ceil($total / $per)]);

    case $res === 'leads' && !isset($parts[1]) && $method === 'POST':
        // A new lead goes to owner_email when given, else through the assignment rules ("assign": "none" leaves it unassigned).
        $r = crm_int_upsert($CLIENT, $body, ['system' => (string) ($K['system'] ?? ''), 'owner' => ($body['assign'] ?? '') === 'none' ? 'none' : 'auto',
                                             'update' => !isset($body['update_existing']) || (bool) $body['update_existing']]);
        if (!$r['ok']) $out(isset($r['id']) ? 409 : 422, $r['error']);
        $out($r['created'] ? 201 : 200, $r['lead'], ['created' => $r['created']]);

    case $res === 'leads' && isset($parts[1]) && !isset($parts[2]) && $method === 'GET':
        $out(200, $find($parts[1]));

    case $res === 'leads' && isset($parts[1]) && !isset($parts[2]) && in_array($method, ['PATCH', 'PUT'], true):
        $l = $find($parts[1]);
        $r = crm_int_upsert($CLIENT, $body, ['system' => (string) ($K['system'] ?? ''), 'id' => $l['id']]);
        if (!$r['ok']) $out(422, $r['error']);
        $out(200, $r['lead']);

    case $res === 'leads' && ($parts[2] ?? '') === 'activities' && $method === 'GET':
        $l = $find($parts[1]);
        $out(200, array_map(fn($n) => ['id' => (int) $n['id'], 'kind' => $n['kind'] ?? 'note', 'outcome' => $n['outcome'] ?? null, 'body' => $n['body'],
                                       'by' => $n['user_id'] ? crm_user_name((int) $n['user_id']) : null, 'created_at' => $n['created_at']],
                            db_all("SELECT * FROM crm_notes WHERE contact_id=? ORDER BY id DESC LIMIT 200", [$l['id']])));

    case $res === 'leads' && ($parts[2] ?? '') === 'activities' && $method === 'POST':
        $l = $find($parts[1]);
        $kind = (string) ($body['kind'] ?? 'note');
        if (!isset(crm_activity_kinds()[$kind])) $out(422, 'kind must be one of: ' . implode(', ', array_keys(crm_activity_kinds())) . '.');
        $outcome = isset($body['outcome']) ? (string) $body['outcome'] : null;
        if ($outcome !== null && !isset(crm_activity_outcomes()[$outcome])) $out(422, 'outcome must be one of: ' . implode(', ', array_keys(crm_activity_outcomes())) . '.');
        $text = trim((string) ($body['body'] ?? ''));
        if ($kind === 'note' && $text === '') $out(422, 'A note needs a body.');
        crm_int_origin('api');
        crm_log_activity($CLIENT, (int) $l['id'], $kind, $outcome, ($K['system'] ? '[' . $K['system'] . '] ' : '') . $text, null);
        $out(201, ['ok' => true, 'lead' => crm_int_lead((int) $l['id'])]);
}
$out(404, 'Nothing at ' . $method . ' /v1/' . implode('/', $parts) . '. See the Integrations page for what the API offers.');
