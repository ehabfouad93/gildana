<?php
declare(strict_types=1);

/**
 * The leads list: one set of filters shared by the table, the board, the number tiles, the
 * saved views and the export — so "Overdue" means the same leads wherever it is clicked, and an
 * export can never hold more (or other) leads than the screen showed.
 *
 * Filters travel as plain query-string keys, so a view is just a saved query string and any
 * filtered list can be bookmarked or sent to a colleague.
 */

/** Every filter the list understands, with what an empty one looks like. */
function crm_list_filter_keys(): array
{
    return ['q', 'stage', 'sub', 'state', 'owner', 'team', 'source', 'platform', 'campaign', 'project', 'unit', 'dtype', 'qual',
            'heat', 'status', 'due', 'added', 'from', 'to', 'idle', 'noans', 'mfrom', 'mto', 'mcamp', 'mad', 'ceq', 'sort', 'dir'];
}

/** The filters in a request, cleaned. Custom-field filters arrive as cf_<key>. */
function crm_list_filters(array $get): array
{
    $f = [];
    foreach (crm_list_filter_keys() as $k) {
        $v = $get[$k] ?? '';
        $f[$k] = is_array($v) ? '' : trim((string) $v);
    }
    foreach ($get as $k => $v) {
        if (is_string($k) && str_starts_with($k, 'cf_') && !is_array($v) && trim((string) $v) !== '') $f[$k] = trim((string) $v);
    }
    return $f;
}

/** Only the filters that are set — for links, saved views and "clear" buttons. */
function crm_list_active(array $f, array $except = ['sort', 'dir']): array
{
    return array_filter($f, fn($v, $k) => $v !== '' && $v !== null && !in_array($k, $except, true), ARRAY_FILTER_USE_BOTH);
}

/**
 * WHERE clause (after "FROM contacts c JOIN crm_stages s ON s.id=c.stage_id") for these filters,
 * with the viewer's scope already applied.
 *
 * @return array{0:string, 1:array}
 */
function crm_list_where(int $clientId, array $f): array
{
    $w = "c.client_id = ? AND c.stage_id IS NOT NULL";
    $p = [$clientId];
    [$scope, $sp] = crm_scope('c');
    $w .= $scope; $p = array_merge($p, $sp);
    $has = fn(string $col) => db_has_column('contacts', $col);
    $g = fn(string $k) => (string) ($f[$k] ?? '');

    if (($q = $g('q')) !== '') {
        $digits = preg_replace('/\D+/', '', $q);
        $or = ["c.name LIKE ?", "c.phone_e164 LIKE ?", "c.email LIKE ?"];
        array_push($p, "%$q%", "%$q%", "%$q%");
        if (strlen($digits) >= 6) { $or[] = "c.phone_e164 LIKE ?"; $p[] = '%' . ltrim($digits, '0') . '%'; }
        if (($code = crm_code_from_search($q)) !== '' && $has('code')) { $or[] = "c.code = ?"; $p[] = $code; }
        $w .= " AND (" . implode(' OR ', $or) . ")";
    }
    if ($g('stage') !== '')  { $w .= " AND c.stage_id = ?"; $p[] = (int) $g('stage'); }
    if ($g('state') !== '' && in_array($g('state'), ['open', 'won', 'lost'], true)) { $w .= " AND s.kind = ?"; $p[] = $g('state'); }
    if ($g('sub') !== '' && $has('substatus')) {
        if ($g('sub') === '__none') $w .= " AND (c.substatus IS NULL OR c.substatus = '')";
        else { $w .= " AND c.substatus = ?"; $p[] = $g('sub'); }
    }
    if ($g('owner') === 'none')   $w .= " AND c.owner_user_id IS NULL";
    elseif ($g('owner') !== '')   { $w .= " AND c.owner_user_id = ?"; $p[] = (int) $g('owner'); }
    if ($g('team') !== '' && db_has_column('users', 'team_id')) {
        $ids = crm_team_member_ids((int) $g('team'));
        $w .= $ids ? " AND c.owner_user_id IN (" . implode(',', array_map('intval', $ids)) . ")" : " AND 1=0";
    }
    if ($g('source') !== '')      { $w .= " AND c.source = ?"; $p[] = $g('source'); }
    if ($g('platform') !== '' && $has('platform')) { $w .= " AND c.platform = ?"; $p[] = $g('platform'); }
    if ($g('campaign') !== '' && $has('campaign')) {
        if ($g('campaign') === '__none') $w .= " AND (c.campaign IS NULL OR c.campaign = '')";
        else { $w .= " AND c.campaign LIKE ?"; $p[] = '%' . $g('campaign') . '%'; }
    }
    // A Meta campaign or ad, exactly (from the return-on-ad-spend report).
    // A campaign by its exact name (reports' drill-downs; "Camp 1" must not open "Camp 10").
    if ($g('ceq') !== '' && $has('campaign')) {
        if ($g('ceq') === '__none') $w .= " AND (c.campaign IS NULL OR c.campaign = '')";
        else { $w .= " AND c.campaign = ?"; $p[] = $g('ceq'); }
    }
    if ($g('mcamp') !== '' && $has('meta_campaign_id')) { $w .= " AND c.meta_campaign_id = ?"; $p[] = $g('mcamp'); }
    if ($g('mad') !== '' && $has('meta_ad_id'))         { $w .= " AND c.meta_ad_id = ?"; $p[] = $g('mad'); }
    if ($g('project') !== '' && $has('project_id')) {
        if ($g('project') === 'none') $w .= " AND c.project_id IS NULL";
        else { $w .= " AND c.project_id = ?"; $p[] = (int) $g('project'); }
    }
    if ($g('unit') !== '' && $has('unit_type')) { $w .= " AND c.unit_type = ?"; $p[] = $g('unit'); }
    if ($g('dtype') !== '' && $has('data_type') && isset(crm_data_types()[$g('dtype')])) { $w .= " AND c.data_type = ?"; $p[] = $g('dtype'); }
    if ($g('qual') !== '' && $has('qualification')) {
        if ($g('qual') === 'none') $w .= " AND c.qualification IS NULL";
        elseif (isset(crm_qualifications()[$g('qual')])) { $w .= " AND c.qualification = ?"; $p[] = $g('qual'); }
    }
    if ($has('score')) {
        $heat = $g('heat');
        if ($heat === 'hot')  $w .= " AND c.score >= 70 AND s.kind = 'open'";
        if ($heat === 'warm') $w .= " AND c.score >= 40 AND c.score < 70 AND s.kind = 'open'";
        if ($heat === 'cold') $w .= " AND c.score < 40 AND s.kind = 'open'";
        $st = $g('status');
        if ($st === 'not_contacted') $w .= " AND c.first_response_at IS NULL AND s.kind = 'open'";
        if ($st === 'late') {
            // Not contacted within the account's response time (an hour if it has none set).
            $w .= " AND c.first_response_at IS NULL AND s.kind = 'open' AND c.assigned_at < NOW() - INTERVAL ? MINUTE";
            $p[] = (int) (crm_settings($clientId)['first_contact_minutes'] ?? 0) ?: 60;
        }
        if ($st === 'again')         $w .= " AND c.submissions > 1";
        if ($st === 'no_followup')   $w .= " AND c.next_followup_at IS NULL AND s.kind = 'open'";
    }
    switch ($g('due')) {
        case 'today':   $w .= " AND DATE(c.next_followup_at) = CURDATE() AND s.kind = 'open'"; break;
        case 'overdue': $w .= " AND c.next_followup_at < NOW() AND s.kind = 'open'"; break;
        case 'week':    $w .= " AND c.next_followup_at >= CURDATE() AND c.next_followup_at < CURDATE() + INTERVAL 7 DAY AND s.kind = 'open'"; break;
        case 'none':    $w .= " AND c.next_followup_at IS NULL AND s.kind = 'open'"; break;
    }
    // When they became a lead.
    $added = "COALESCE(c.crm_added_at, c.created_at)";
    switch ($g('added')) {
        case 'today': $w .= " AND $added >= CURDATE()"; break;
        case 'week':  $w .= " AND $added >= CURDATE() - INTERVAL 6 DAY"; break;
        case 'month': $w .= " AND $added >= CURDATE() - INTERVAL 29 DAY"; break;
    }
    if ($g('from') !== '' && strtotime($g('from'))) { $w .= " AND $added >= ?"; $p[] = date('Y-m-d 00:00:00', strtotime($g('from'))); }
    if ($g('to') !== '' && strtotime($g('to')))     { $w .= " AND $added <= ?"; $p[] = date('Y-m-d 23:59:59', strtotime($g('to'))); }
    // Moved into the stage they are in now between these dates — "won in September" from a report.
    if ($g('mfrom') !== '' || $g('mto') !== '') {
        $w .= " AND EXISTS (SELECT 1 FROM crm_events me WHERE me.contact_id = c.id AND me.kind IN ('stage','added')
                             AND me.to_val = CAST(c.stage_id AS CHAR) AND me.created_at BETWEEN ? AND ?)";
        $p[] = $g('mfrom') !== '' && strtotime($g('mfrom')) ? date('Y-m-d 00:00:00', strtotime($g('mfrom'))) : '2000-01-01 00:00:00';
        $p[] = $g('mto') !== '' && strtotime($g('mto')) ? date('Y-m-d 23:59:59', strtotime($g('mto'))) : '2100-01-01 00:00:00';
    }
    // Nothing done with them for N days: no activity, no message either way.
    if (($idle = (int) $g('idle')) > 0) {
        $w .= " AND s.kind = 'open' AND GREATEST(COALESCE(c.last_touch_at, '2000-01-01'), COALESCE(c.last_inbound_at, '2000-01-01'),
                    COALESCE(c.crm_added_at, c.created_at)) < NOW() - INTERVAL ? DAY";
        $p[] = $idle;
    }
    if (($na = (int) $g('noans')) > 0 && db_has_column('crm_notes', 'outcome')) {
        $w .= " AND (SELECT COUNT(*) FROM crm_notes n WHERE n.contact_id = c.id AND n.outcome = 'no_answer') >= ?";
        $p[] = $na;
    }
    // The account's own fields: a list or date matches exactly, text and numbers contain.
    if ($has('custom')) {
        $fields = array_column(crm_fields($clientId, false), null, 'fkey');
        foreach ($f as $k => $v) {
            if (!str_starts_with((string) $k, 'cf_') || $v === '') continue;
            $key = substr((string) $k, 3);
            if (!isset($fields[$key]) || !preg_match('/^[a-z0-9_]+$/', $key)) continue;
            $expr = "JSON_UNQUOTE(JSON_EXTRACT(c.custom, '$.\"$key\"'))";
            if (in_array($fields[$key]['type'], ['list', 'date'], true)) { $w .= " AND $expr = ?"; $p[] = $v; }
            else { $w .= " AND LOWER($expr) LIKE ?"; $p[] = '%' . mb_strtolower($v) . '%'; }   // JSON text compares byte-for-byte
        }
    }
    return [$w, $p];
}

/**
 * The columns a person can show, in the order they appear.
 * key => [label, ORDER BY expression or '' when it does not sort, shown by default]
 */
function crm_list_columns(int $clientId): array
{
    $cols = [
        'lead'      => ['Lead',            'c.name',                      true],
        'phone'     => ['Phone',           'c.phone_e164',                false],
        'stage'     => ['Stage',           's.sort',                      true],
        'heat'      => ['Heat',            'c.score',                     true],
        'owner'     => ['Owner',           'owner_name',                  true],
        'team'      => ['Team',            '',                            false],
        'project'   => ['Project',         'c.project_id',                true],
        'unit'      => ['Unit type',       'c.unit_type',                 false],
        'budget'    => ['Budget',          'c.budget',                    false],
        'value'     => ['Value',           'c.deal_value',                true],
        'followup'  => ['Follow-up',       'c.next_followup_at',          true],
        'effort'    => ['Calls · visits',  '',                            true],
        'rotations' => ['Moved',           '',                            false],
        'source'    => ['Source',          'c.source',                    false],
        'platform'  => ['Platform',        'c.platform',                  false],
        'campaign'  => ['Campaign',        'c.campaign',                  false],
        'dtype'     => ['Fresh / cold',    'c.data_type',                 false],
        'qual'      => ['Qualified',       'c.qualification',             false],
        'last'      => ['Last activity',   'last_activity',               true],
        'added'     => ['Added',           'added_at',                    false],
    ];
    foreach (crm_fields($clientId) as $cf) $cols['cf_' . $cf['fkey']] = [$cf['label'], '', false];
    return $cols;
}

/** The columns this person shows: their choice, or the defaults. Lead always comes first. */
function crm_list_user_columns(int $clientId, ?array $user): array
{
    $all = crm_list_columns($clientId);
    $mine = null;
    if ($user && isset($user['crm_columns']) && trim((string) $user['crm_columns']) !== '') {
        $mine = array_values(array_intersect(array_map('trim', explode(',', (string) $user['crm_columns'])), array_keys($all)));
    }
    if (!$mine) $mine = array_keys(array_filter($all, fn($c) => $c[2]));
    return array_values(array_unique(array_merge(['lead'], $mine)));
}

/**
 * One page of leads, with the effort counters for just those rows.
 *
 * @return array{rows:array, total:int, page:int, pages:int}
 */
function crm_list_page(int $clientId, array $f, int $page = 1, int $per = 50): array
{
    [$w, $p] = crm_list_where($clientId, $f);
    $total = (int) db_val("SELECT COUNT(*) FROM contacts c JOIN crm_stages s ON s.id = c.stage_id WHERE $w", $p);
    $per = max(10, min(500, $per));
    $pages = max(1, (int) ceil($total / $per));
    $page = max(1, min($pages, $page));
    $cols = crm_list_columns($clientId);
    $sortKey = (string) ($f['sort'] ?? '');
    $dir = strtolower((string) ($f['dir'] ?? '')) === 'asc' ? 'ASC' : 'DESC';
    $order = match (true) {
        $sortKey === 'hot'    => "c.score IS NULL, c.score DESC, c.id DESC",
        $sortKey === 'newest' => "c.id DESC",
        $sortKey === 'value' && ($f['dir'] ?? '') === '' => "c.deal_value IS NULL, c.deal_value DESC, c.id DESC",
        isset($cols[$sortKey]) && $cols[$sortKey][1] !== '' => $cols[$sortKey][1] . " IS NULL, " . $cols[$sortKey][1] . " $dir, c.id DESC",
        default               => "c.next_followup_at IS NULL, c.next_followup_at, c.id DESC",
    };
    $rows = db_all(
        "SELECT c.*, s.name AS stage_name, s.kind AS stage_kind, s.sort AS stage_sort,
                COALESCE(NULLIF(u.name,''), u.email) AS owner_name,
                COALESCE(c.crm_added_at, c.created_at) AS added_at,
                GREATEST(COALESCE(c.last_touch_at, '2000-01-01'), COALESCE(c.last_inbound_at, '2000-01-01')) AS last_activity
           FROM contacts c JOIN crm_stages s ON s.id = c.stage_id LEFT JOIN users u ON u.id = c.owner_user_id
          WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per), $p);
    $rows = crm_list_counters($rows);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per];
}

/** Calls, answered, no answer, visits and how often each lead changed hands — for these rows only. */
function crm_list_counters(array $rows): array
{
    if (!$rows) return $rows;
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $in = implode(',', $ids);
    $c = [];
    if (db_has_column('crm_notes', 'outcome')) {
        foreach (db_all("SELECT contact_id,
                                SUM(kind = 'call') calls,
                                SUM(outcome IN ('answered','interested','not_interested','booked','busy','sent_info')) answered,
                                SUM(outcome = 'no_answer') no_answer,
                                SUM(outcome = 'visited' OR kind = 'visit') visits
                           FROM crm_notes WHERE contact_id IN ($in) GROUP BY contact_id") as $r) $c[(int) $r['contact_id']] = $r;
    }
    $rot = [];
    foreach (db_all("SELECT contact_id, COUNT(*) n FROM crm_events WHERE contact_id IN ($in) AND kind IN ('assigned','reclaimed')
                       AND from_val IS NOT NULL GROUP BY contact_id") as $r) $rot[(int) $r['contact_id']] = (int) $r['n'];
    $came = [];
    try {
        foreach (db_all("SELECT contact_id, COUNT(*) n FROM crm_visits WHERE contact_id IN ($in) AND status='done' GROUP BY contact_id") as $r)
            $came[(int) $r['contact_id']] = (int) $r['n'];
    } catch (Throwable $e) {}
    foreach ($rows as &$r) {
        $x = $c[(int) $r['id']] ?? [];
        $r['n_calls'] = (int) ($x['calls'] ?? 0);
        $r['n_answered'] = (int) ($x['answered'] ?? 0);
        $r['n_noanswer'] = (int) ($x['no_answer'] ?? 0);
        $r['n_visits'] = max((int) ($x['visits'] ?? 0), $came[(int) $r['id']] ?? 0);
        $r['n_moved'] = $rot[(int) $r['id']] ?? 0;
    }
    return $rows;
}

/** The numbers above the list, for whatever this person can see. Each opens its own filter. */
function crm_list_tiles(int $clientId): array
{
    $count = function (array $f) use ($clientId): int {
        [$w, $p] = crm_list_where($clientId, $f);
        return (int) db_val("SELECT COUNT(*) FROM contacts c JOIN crm_stages s ON s.id = c.stage_id WHERE $w", $p);
    };
    return [
        ['Total leads',        $count([]),                          []],
        ['New this week',      $count(['added' => 'week']),         ['added' => 'week']],
        ['Not contacted',      $count(['status' => 'not_contacted']), ['status' => 'not_contacted']],
        ['Due today',          $count(['due' => 'today']),          ['due' => 'today']],
        ['Overdue',            $count(['due' => 'overdue']),        ['due' => 'overdue']],
    ];
}

/* ───────────────────────── saved views ───────────────────────── */

/** Views everybody has, before saving any of their own. */
function crm_list_builtin_views(): array
{
    return [
        'b_open'     => ['Open leads',              ['state' => 'open']],
        'b_new'      => ['Not contacted yet',       ['status' => 'not_contacted', 'sort' => 'newest']],
        'b_today'    => ['Follow-ups due today',    ['due' => 'today']],
        'b_overdue'  => ['Overdue follow-ups',      ['due' => 'overdue']],
        'b_hot'      => ['Hot leads',               ['heat' => 'hot', 'sort' => 'hot']],
        'b_noans'    => ['No answer 3+ times',      ['noans' => '3', 'state' => 'open']],
        'b_fresh'    => ['Fresh this week',         ['dtype' => 'fresh', 'added' => 'week', 'sort' => 'newest']],
        'b_idle'     => ['Quiet for 7+ days',       ['idle' => '7']],
        'b_nofu'     => ['No follow-up planned',    ['status' => 'no_followup']],
    ];
}

/** Saved views this person can use: their own, and the ones an Admin shared with everyone. */
function crm_list_views(int $clientId, int $userId): array
{
    try {
        return db_all("SELECT * FROM crm_views WHERE client_id=? AND (user_id IS NULL OR user_id=?) ORDER BY user_id IS NULL DESC, sort, name",
                      [$clientId, $userId]);
    } catch (Throwable $e) { return []; }
}

/* ───────────────────────── export ───────────────────────── */

/** Every lead matching the filters (up to a limit), for export — the same WHERE as the screen. */
function crm_list_all(int $clientId, array $f, int $max = 50000): array
{
    [$w, $p] = crm_list_where($clientId, $f);
    $rows = db_all("SELECT c.*, s.name AS stage_name, s.kind AS stage_kind, COALESCE(NULLIF(u.name,''), u.email) AS owner_name,
                           COALESCE(c.crm_added_at, c.created_at) AS added_at,
                           GREATEST(COALESCE(c.last_touch_at, '2000-01-01'), COALESCE(c.last_inbound_at, '2000-01-01')) AS last_activity
                      FROM contacts c JOIN crm_stages s ON s.id = c.stage_id LEFT JOIN users u ON u.id = c.owner_user_id
                     WHERE $w ORDER BY c.id DESC LIMIT " . (int) $max, $p);
    $out = [];
    foreach (array_chunk($rows, 500) as $chunk) $out = array_merge($out, crm_list_counters($chunk));
    return $out;
}

/** The export's header and rows: every column, whatever is shown on screen. */
function crm_list_export_table(int $clientId, array $rows, bool $withPhone = true): array
{
    $pn = crm_project_names($clientId);
    $teams = crm_team_names($clientId);
    $userTeam = [];
    if (db_has_column('users', 'team_id')) foreach (db_all("SELECT id, team_id FROM users WHERE client_id=?", [$clientId]) as $u) $userTeam[(int) $u['id']] = (int) $u['team_id'];
    $fields = crm_fields($clientId);
    $head = ['Code', 'Name'];
    if ($withPhone) $head[] = 'Phone';
    $head = array_merge($head, ['Email', 'Stage', 'Sub-status', 'Owner', 'Team', 'Project', 'Unit type', 'Budget', 'Deal value',
             'Score', 'Next follow-up', 'Source', 'Platform', 'Campaign', 'Ad set', 'Ad', 'Fresh / cold', 'Qualified',
             'Calls', 'Answered', 'No answer', 'Visits', 'Times moved', 'Lost reason', 'Added', 'Last activity']);
    foreach ($fields as $cf) $head[] = $cf['label'];
    $out = [];
    foreach ($rows as $r) {
        $custom = crm_custom_get($r);
        $line = [(string) ($r['code'] ?? ''), (string) $r['name']];
        if ($withPhone) $line[] = '+' . $r['phone_e164'];
        $line = array_merge($line, [
            (string) ($r['email'] ?? ''), (string) $r['stage_name'], (string) ($r['substatus'] ?? ''), (string) ($r['owner_name'] ?? ''),
            (string) ($teams[$userTeam[(int) ($r['owner_user_id'] ?? 0)] ?? 0] ?? ''),
            (string) ($pn[(int) ($r['project_id'] ?? 0)] ?? ''), (string) ($r['unit_type'] ?? ''), (string) ($r['budget'] ?? ''),
            $r['deal_value'] !== null ? (float) $r['deal_value'] : '', $r['score'] !== null ? (int) $r['score'] : '',
            (string) ($r['next_followup_at'] ?? ''), crm_source_label($r['source']), !empty($r['platform']) ? crm_platform_label($r['platform']) : '',
            (string) ($r['campaign'] ?? ''), (string) ($r['adset'] ?? ''), (string) ($r['ad_name'] ?? ''),
            crm_data_types()[(string) ($r['data_type'] ?? '')] ?? '', crm_qualifications()[(string) ($r['qualification'] ?? '')] ?? '',
            $r['n_calls'], $r['n_answered'], $r['n_noanswer'], $r['n_visits'], $r['n_moved'], (string) ($r['lost_reason'] ?? ''),
            (string) $r['added_at'], substr((string) $r['last_activity'], 0, 4) === '2000' ? '' : (string) $r['last_activity'],
        ]);
        foreach ($fields as $cf) $line[] = crm_custom_show($cf, $custom[$cf['fkey']] ?? '');
        $out[] = $line;
    }
    return [$head, $out];
}

/**
 * A real .xlsx (opens in Excel with numbers as numbers, Arabic intact, no "convert this CSV"
 * prompt). Written with ZipArchive, the same way the importer reads one — nothing to install.
 */
function crm_xlsx(array $head, array $rows, string $sheet = 'Leads'): string
{
    $esc = fn(string $s) => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $colName = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
    $xmlRows = '';
    foreach (array_merge([$head], $rows) as $ri => $row) {
        $cells = '';
        foreach (array_values($row) as $ci => $v) {
            $ref = $colName($ci) . ($ri + 1);
            if ($v === '' || $v === null) continue;
            if ((is_int($v) || is_float($v)) && $ri > 0) $cells .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            else $cells .= '<c r="' . $ref . '" t="inlineStr"' . ($ri === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $esc((string) $v) . '</t></is></c>';
        }
        $xmlRows .= '<row r="' . ($ri + 1) . '">' . $cells . '</row>';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $esc(mb_substr($sheet, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
    $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>' . $xmlRows . '</sheetData></worksheet>');
    $z->close();
    $bytes = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}

/** Keep a record of every export: who took which leads out, and how many. */
function crm_log_export(int $clientId, int $userId, int $rows, array $filters): void
{
    crm_audit($clientId, $userId ?: null, 'export', null, http_build_query(crm_list_active($filters, [])) ?: null, $rows);
}
