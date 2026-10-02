<?php
declare(strict_types=1);

/**
 * Business rules: the sales pipeline, who may see which client, the money maths
 * for contracts and instalments, and the yearly week allowance.
 *
 * Pipeline (each step is one department):
 *   new        advisor added the lead
 *   booked     booker set a meeting date/time and the sales rep
 *   confirmed  communicator confirmed the meeting with the client
 *   arrived    the client is at the office; the sales manager assigned a rep
 *   contracted sales closed the deal and entered the official data
 *   lost       sales: the meeting did not close
 *   cancelled  communicator: the client cancelled
 */

require_once __DIR__ . '/money.php';

const STAGES = ['new', 'booked', 'confirmed', 'arrived', 'contracted', 'lost', 'cancelled'];

/* ── settings (key/value, admin-editable) ── */

function setting_defaults(): array
{
    return [
        'company_name'    => 'J Vacations',
        'company_address' => '',
        'company_phone'   => '',
        'company_reg'     => '',
        'currency'        => 'EGP',
        'down_pct'        => '25',
        'month_options'   => '24,36',
        'contract_prefix' => 'JV',
        'meeting_places'  => '',
        'lead_sources'    => 'Facebook,Instagram,Referral,Walk-in,Call center,Event',
        'terms_en'        => '',
        'terms_ar'        => '',
        'role_caps'       => '',
        'wa_enabled'      => '0',
        'wa_token'        => '',
        'wa_phone_id'     => '',
        'wa_waba_id'      => '',
        'wa_graph'        => 'v21.0',
        'wa_country'      => '20',
    ];
}

function setting(string $key): string
{
    if (!isset($GLOBALS['__jv_settings'])) {
        $cache = setting_defaults();
        try {
            foreach (db_all("SELECT k, v FROM settings") as $r) {
                $cache[$r['k']] = (string) $r['v'];
            }
        } catch (Throwable $e) { /* before migrations: defaults only */ }
        $GLOBALS['__jv_settings'] = $cache;
    }
    return $GLOBALS['__jv_settings'][$key] ?? '';
}

function save_setting(string $key, string $value): void
{
    db_run("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$key, $value]);
    if (isset($GLOBALS['__jv_settings'])) $GLOBALS['__jv_settings'][$key] = $value;
}

/** Comma-separated setting → clean list. */
function setting_list(string $key): array
{
    return array_values(array_filter(array_map('trim', explode(',', setting($key))), 'strlen'));
}

/** Instalment plans offered on the contract form (24 / 36 by default). */
function month_options(): array
{
    $out = [];
    foreach (setting_list('month_options') as $m) {
        if (ctype_digit($m) && (int) $m > 0 && (int) $m <= 120) $out[] = (int) $m;
    }
    return $out ?: [24, 36];
}

/* ── access ── */

/**
 * May $u open this client's profile? Visibility follows capabilities: each
 * department sees its own queue plus the clients it has handled, so nobody
 * downstream loses sight of what they did.
 */
function can_view_client(array $u, array $c): bool
{
    $me = (int) $u['id'];
    if (can('clients.view_all', $u)) return true;
    if (can('contracts.view', $u) && $c['stage'] === 'contracted') return true;
    if (can('clients.add', $u) && (int) $c['created_by'] === $me) return true;
    if (can('clients.book', $u) && ($c['stage'] === 'new' || (int) $c['booker_id'] === $me)) return true;
    if (can('clients.confirm', $u) && (in_array($c['stage'], ['booked', 'confirmed'], true) || (int) $c['communicator_id'] === $me)) return true;
    if (can('clients.arrive', $u) && (in_array($c['stage'], ['booked', 'confirmed', 'arrived'], true) || (int) $c['manager_id'] === $me)) return true;
    if (can('clients.close', $u) && (int) $c['sales_id'] === $me) return true;
    return false;
}

/** Contract amounts: the assigned rep, plus whoever may view contracts. */
function can_view_contract(array $u, array $c): bool
{
    if (can('contracts.view', $u)) return true;
    return can('clients.close', $u) && (int) $c['sales_id'] === (int) $u['id'];
}

function load_client(int $id): ?array
{
    return db_row(
        "SELECT c.*, a.name AS advisor_name, b.name AS booker_name, m.name AS communicator_name, s.name AS sales_name,
                g.name AS manager_name
           FROM clients c
           LEFT JOIN users g ON g.id = c.manager_id
           LEFT JOIN users a ON a.id = c.created_by
           LEFT JOIN users b ON b.id = c.booker_id
           LEFT JOIN users m ON m.id = c.communicator_id
           LEFT JOIN users s ON s.id = c.sales_id
          WHERE c.id = ?", [$id]);
}

/** Load a client the current user may see, or stop with 404/403. */
function client_or_403(int $id, array $u): array
{
    $c = load_client($id);
    if (!$c) { http_response_code(404); exit(t('err.not_found')); }
    if (!can_view_client($u, $c)) { http_response_code(403); exit(t('err.forbidden')); }
    return $c;
}

function log_event(int $clientId, string $action, string $note = ''): void
{
    $u = current_user();
    db_run("INSERT INTO client_events (client_id, user_id, action, note, created_at) VALUES (?, ?, ?, ?, NOW())",
        [$clientId, $u ? $u['id'] : null, $action, $note !== '' ? $note : null]);
}

function users_by_role(string $role): array
{
    return db_all("SELECT id, name FROM users WHERE role = ? AND status = 'active' ORDER BY name", [$role]);
}

/* ── money display ── */

function money($amount, ?string $currency = null): string
{
    $n = number_format((float) $amount, 2, '.', ',');
    if (substr($n, -3) === '.00') $n = substr($n, 0, -3);
    return $n . ' ' . ($currency ?? setting('currency'));
}

/** Totals for one contract's statement. Amounts as floats for display only. */
function contract_totals(int $contractId): array
{
    $r = db_row(
        "SELECT COALESCE(SUM(amount),0) AS total,
                COALESCE(SUM(paid_amount),0) AS paid,
                COALESCE(SUM(CASE WHEN status <> 'paid' AND due_date < CURDATE() THEN amount - paid_amount ELSE 0 END),0) AS overdue,
                SUM(CASE WHEN status <> 'paid' AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                COUNT(*) AS n,
                MIN(CASE WHEN status <> 'paid' THEN due_date END) AS next_due
           FROM instalments WHERE contract_id = ?", [$contractId]) ?? [];
    $r['remaining'] = (float) $r['total'] - (float) $r['paid'];
    return $r;
}

function next_contract_no(): string
{
    $prefix = preg_replace('/[^A-Za-z0-9-]/', '', setting('contract_prefix')) ?: 'JV';
    $year   = date('Y');
    $n      = (int) db_val("SELECT COUNT(*) FROM contracts WHERE contract_no LIKE ?", [$prefix . '-' . $year . '-%']) + 1;
    do {
        $no = sprintf('%s-%s-%04d', $prefix, $year, $n++);
    } while (db_val("SELECT 1 FROM contracts WHERE contract_no = ?", [$no]));
    return $no;
}

/* ── weeks allowance ── */

/** Contract years as [year => [allowed, used]] across the membership term. */
function week_allowance(array $contract): array
{
    $used = [];
    foreach (db_all("SELECT YEAR(check_in) AS y, SUM(weeks) AS w FROM stays
                      WHERE contract_id = ? AND status = 'confirmed' GROUP BY YEAR(check_in)", [$contract['id']]) as $r) {
        $used[(int) $r['y']] = (int) $r['w'];
    }
    $out   = [];
    $start = (int) $contract['start_year'];
    for ($y = $start; $y < $start + (int) $contract['duration_years']; $y++) {
        $out[$y] = ['allowed' => (int) $contract['weeks_per_year'], 'used' => $used[$y] ?? 0];
    }
    return $out;
}

/* ── display helpers ── */

function stage_pill(string $stage): string
{
    $cls = [
        'new' => 'gray', 'booked' => 'blue', 'confirmed' => 'gold', 'arrived' => 'purple',
        'contracted' => 'green', 'lost' => 'red', 'cancelled' => 'red',
    ][$stage] ?? 'gray';
    return '<span class="pill ' . $cls . ' dot">' . e(t('stage.' . $stage)) . '</span>';
}

function pay_pill(array $inst): string
{
    if ($inst['status'] === 'paid')    return '<span class="pill green">' . e(t('pay.paid')) . '</span>';
    $over = $inst['due_date'] < date('Y-m-d');
    if ($inst['status'] === 'partial') return '<span class="pill ' . ($over ? 'red' : 'gold') . '">' . e(t('pay.partial')) . '</span>';
    return $over ? '<span class="pill red">' . e(t('pay.overdue')) . '</span>'
                 : '<span class="pill gray">' . e(t('pay.unpaid')) . '</span>';
}

/**
 * One search box that understands what was typed. Needs `clients c` and
 * `LEFT JOIN contracts ct` in the query. Returns [sql, params].
 *   #12 / 12        → client number (the same number on every stage and the contract)
 *   JV-2026-0001    → contract number
 *   01001234567     → any phone on the client or contract, in any format (+20, spaces…)
 *   29001011234567  → national ID
 *   text            → client name or official contract name (every word must match)
 */
function client_search_sql(string $q): array
{
    $q = trim($q);
    $digitsOf = fn(string $col) => "REPLACE(REPLACE(REPLACE(REPLACE($col,' ',''),'-',''),'+',''),'(','')";

    if (preg_match('/^#\s*(\d+)$/', $q, $m)) {
        return ["c.id = ?", [(int) $m[1]]];
    }
    $digits = preg_replace('/\D/', '', $q) ?? '';
    if ($digits !== '' && preg_match('/^[\d\s+\-()]+$/', $q)) {
        $or = []; $p = [];
        if (strlen($digits) <= 7) { $or[] = "c.id = ?"; $p[] = (int) $digits; }
        if (strlen($digits) >= 6) {
            // Compare on the last 10 digits so 01001234567, 1001234567 and +201001234567 all match.
            $tail = '%' . substr(ltrim($digits, '0'), -10);
            foreach (['c.phone', 'c.phone2', 'ct.phone'] as $col) { $or[] = $digitsOf($col) . " LIKE ?"; $p[] = $tail; }
            $or[] = "ct.national_id LIKE ?"; $p[] = $digits . '%';
        }
        $or[] = "ct.contract_no LIKE ?"; $p[] = '%' . $digits . '%';
        return ['(' . implode(' OR ', $or) . ')', $p];
    }
    $and = []; $p = [];
    foreach (preg_split('/\s+/u', $q) ?: [] as $w) {
        if ($w === '') continue;
        $and[] = "(c.full_name LIKE ? OR ct.official_name LIKE ? OR ct.contract_no LIKE ? OR c.email LIKE ? OR ct.national_id LIKE ?)";
        array_push($p, "%$w%", "%$w%", "%$w%", "%$w%", "%$w%");
    }
    return $and ? ['(' . implode(' AND ', $and) . ')', $p] : ['1=1', []];
}

/** The client number shown everywhere — profile, lists, contract, statement, search. */
function client_code(int $id): string
{
    return '#' . $id;
}

function wa_status_pill(string $status): string
{
    $cls = ['sent' => 'green', 'failed' => 'red', 'skipped' => 'gray'][$status] ?? 'gray';
    return '<span class="pill ' . $cls . '">' . e(t('wa.status.' . $status)) . '</span>';
}

function seq_label(int $seq): string
{
    return $seq === 0 ? t('pay.down') : t('pay.inst_n', ['n' => (string) $seq]);
}
