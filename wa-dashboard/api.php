<?php
declare(strict_types=1);
/**
 * The CRM's API, for another CRM or any system to read and write leads.
 *
 *   Authorization: Bearer <key from CRM → Integrations>
 *
 *   GET    /api.php/v1/me                         the key and its account
 *   GET    /api.php/v1/leads                      ?updated_since=&created_since=&stage=&owner_email=&phone=&external_id=&page=&per_page=
 *   GET    /api.php/v1/leads/{ref}                ref = id, lead code (A1B2C3, or code:482913), or ext:<their id>
 *   POST   /api.php/v1/leads                      create — or update the lead with the same external_id / phone
 *   PATCH  /api.php/v1/leads/{ref}                change only the fields sent
 *   GET    /api.php/v1/leads/{ref}/activities
 *   POST   /api.php/v1/leads/{ref}/activities     {kind: call|whatsapp|meeting|visit|email|note, outcome, body}
 *   GET    /api.php/v1/stages | /users | /projects | /fields
 *
 *   SMS (keys with the SMS scope, accounts with the SMS module):
 *   POST   /api.php/v1/sms                        {to: "+20…" | [..≤1000], text, sender?, schedule_at?, reference?, callback_url?}
 *   GET    /api.php/v1/sms/{id}                   one message's status
 *   GET    /api.php/v1/sms                        ?reference=&status=&since=&page=&per_page=
 *   GET    /api.php/v1/sms/balance | /sms/senders
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
    $byCode = fn(string $code) => db_row("SELECT id FROM contacts WHERE client_id=? AND code=?", [$cid, strtoupper(ltrim($code, '#'))]);
    if (str_starts_with($ref, 'ext:')) $c = db_row("SELECT id FROM contacts WHERE client_id=? AND external_id=?", [$cid, urldecode(substr($ref, 4))]);
    elseif (str_starts_with($ref, 'code:')) $c = $byCode(substr($ref, 5));
    // A lead code can be all digits (482913), so a number that is not one of our ids is tried as a code.
    elseif (ctype_digit($ref)) $c = db_row("SELECT id FROM contacts WHERE client_id=? AND id=?", [$cid, (int) $ref]) ?: $byCode($ref);
    else $c = $byCode($ref);
    if (!$c || !($l = crm_int_lead((int) $c['id'])) || ($l['stage_id'] === null)) $out(404, 'No lead ' . $ref . '.');
    return $l;
};

$res = $parts[0] ?? '';
// What the key may do: the CRM, SMS, or both (keys made before SMS existed are CRM keys).
$scopes = array_filter(explode(',', (string) ($K['scopes'] ?? 'crm')));
if ($res === 'sms') {
    if (!in_array('sms', $scopes, true)) $out(403, 'This key cannot send SMS. Make a key with SMS access on the SMS → API page.');
    if (!in_array('sms', client_modules($CLIENT), true)) $out(403, 'SMS is not part of this account\'s plan.');
    require_once __DIR__ . '/includes/sms.php';
    require_once __DIR__ . '/includes/crm_automation.php';
    if (!sms_ready()) $out(503, 'SMS is not installed yet (migration 064).');
} elseif ($res !== 'me' && !in_array('crm', $scopes, true)) {
    $out(403, 'This key is for SMS only. Make a key with CRM access on CRM → Integrations.');
}
switch (true) {
    case $res === 'sms' && ($parts[1] ?? '') === 'balance' && $method === 'GET':
        $out(200, ['credits' => (int) db_val("SELECT credits_balance FROM clients WHERE id=?", [$cid]), 'credits_per_part' => sms_rate($CLIENT),
                   'part_size' => ['english' => 160, 'english_split' => 153, 'unicode' => 70, 'unicode_split' => 67]]);

    case $res === 'sms' && ($parts[1] ?? '') === 'senders' && $method === 'GET':
        $out(200, sms_client_senders($CLIENT));

    case $res === 'sms' && isset($parts[1]) && ctype_digit((string) $parts[1]) && $method === 'GET':
        $m = db_row("SELECT * FROM sms_messages WHERE id=? AND client_id=?", [(int) $parts[1], $cid]);
        if (!$m) $out(404, 'No SMS ' . $parts[1] . '.');
        $out(200, sms_public($m));

    case $res === 'sms' && !isset($parts[1]) && $method === 'GET':
        $w = "client_id=?"; $p = [$cid];
        if (($v = trim((string) ($_GET['reference'] ?? ''))) !== '') { $w .= " AND reference=?"; $p[] = $v; }
        if (($v = trim((string) ($_GET['status'] ?? ''))) !== '') { $w .= " AND status=?"; $p[] = $v; }
        if (($v = trim((string) ($_GET['since'] ?? ''))) !== '') { if (!strtotime($v)) $out(400, 'since is not a date.'); $w .= " AND created_at >= ?"; $p[] = date('Y-m-d H:i:s', strtotime($v)); }
        if (($v = trim((string) ($_GET['to'] ?? ''))) !== '') { $w .= " AND to_e164=?"; $p[] = normalize_phone($v, (string) ($CLIENT['default_country'] ?? '')); }
        $per = max(1, min(500, (int) ($_GET['per_page'] ?? 100))); $page = max(1, (int) ($_GET['page'] ?? 1));
        $total = (int) db_val("SELECT COUNT(*) FROM sms_messages WHERE $w", $p);
        $out(200, array_map('sms_public', db_all("SELECT * FROM sms_messages WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p)),
             ['page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => (int) ceil($total / $per)]);

    case $res === 'sms' && !isset($parts[1]) && $method === 'POST':
        $to = $body['to'] ?? null;
        $list = is_array($to) ? array_values($to) : (is_string($to) && trim($to) !== '' ? [$to] : []);
        if (!$list) $out(422, '"to" is required: a number, or a list of up to 1,000 numbers.');
        if (count($list) > 1000) $out(422, 'Up to 1,000 numbers per request — split the list.');
        $text = trim((string) ($body['text'] ?? ''));
        if ($text === '') $out(422, '"text" is required.');
        if (mb_strlen($text) > 1530) $out(422, '"text" is too long (at most 1,530 characters, 10 parts).');
        $ref = trim((string) ($body['reference'] ?? ''));
        // The same reference within 24 hours: answer with what was already accepted, send nothing again.
        if ($ref !== '') {
            $prev = db_all("SELECT * FROM sms_messages WHERE client_id=? AND reference=? AND created_at > NOW() - INTERVAL 1 DAY ORDER BY id", [$cid, mb_substr($ref, 0, 120)]);
            if ($prev) $out(200, ['accepted' => count($prev), 'credits' => array_sum(array_column($prev, 'credits')), 'messages' => array_map('sms_public', $prev), 'skipped' => []], ['duplicate' => true]);
        }
        $sched = null;
        if (($v = trim((string) ($body['schedule_at'] ?? ''))) !== '') {
            if (!strtotime($v)) $out(422, '"schedule_at" is not a date.');
            $sched = date('Y-m-d H:i:s', strtotime($v));
            if (strtotime($sched) < time() - 60) $out(422, '"schedule_at" is in the past.');
        }
        $cb = trim((string) ($body['callback_url'] ?? ''));
        if ($cb !== '' && !preg_match('~^https?://~i', $cb)) $out(422, '"callback_url" must be an http(s) address.');
        $q = sms_queue($CLIENT, array_map(fn($n) => ['phone' => (string) $n], $list), $text, [
            'sender' => (string) ($body['sender'] ?? ''), 'source' => 'api', 'api_key_id' => (int) $K['id'], 'reference' => $ref,
            'callback_url' => $cb, 'scheduled_at' => $sched]);
        if (!$q['ok']) $out(422, $q['error']);
        if ($q['credits'] > (int) db_val("SELECT credits_balance FROM clients WHERE id=?", [$cid])) {
            db_run("UPDATE sms_messages SET status='skipped', error_title='Not enough credits' WHERE id IN (" . (implode(',', array_column($q['queued'], 'id')) ?: '0') . ")");
            $out(402, 'Not enough credits: this needs ' . $q['credits'] . '.');
        }
        // One number now is sent while you wait; a batch (or a scheduled message) goes out from the queue in seconds.
        if (count($q['queued']) === 1 && !$sched) {
            sms_send_row($CLIENT, db_row("SELECT * FROM sms_messages WHERE id=?", [$q['queued'][0]['id']]));
        } elseif ($q['queued'] && !$sched && function_exists('trigger_worker')) {
            trigger_worker();
        }
        $ids = array_column($q['queued'], 'id');
        $msgs = $ids ? db_all("SELECT * FROM sms_messages WHERE id IN (" . implode(',', array_map('intval', $ids)) . ") ORDER BY id") : [];
        $out(201, ['accepted' => count($msgs), 'credits' => $q['credits'], 'messages' => array_map('sms_public', $msgs),
                   'skipped' => array_map(fn($x) => ['to' => (string) $x[0], 'reason' => $x[1]], $q['skipped'])]);

    case $res === 'me' && $method === 'GET':
        $out(200, ['key' => ['name' => $K['name'], 'prefix' => $K['prefix'], 'access' => $K['access'], 'system' => $K['system'], 'scopes' => array_values($scopes)],
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
