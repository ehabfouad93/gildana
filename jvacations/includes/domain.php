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
 *   contracted sales closed the deal and entered the official data
 *   lost       sales: the meeting did not close
 *   cancelled  communicator: the client cancelled
 */

require_once __DIR__ . '/money.php';

const STAGES = ['new', 'booked', 'confirmed', 'contracted', 'lost', 'cancelled'];

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
    ];
}

function setting(string $key): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = setting_defaults();
        try {
            foreach (db_all("SELECT k, v FROM settings") as $r) {
                $cache[$r['k']] = (string) $r['v'];
            }
        } catch (Throwable $e) { /* before migrations: defaults only */ }
    }
    return $cache[$key] ?? '';
}

function save_setting(string $key, string $value): void
{
    db_run("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$key, $value]);
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
 * May $u open this client's profile? Each department sees its own queue and
 * the clients it has handled; nobody downstream loses sight of what they did.
 */
function can_view_client(array $u, array $c): bool
{
    $me = (int) $u['id'];
    switch ($u['role']) {
        case 'admin':
        case 'owner_services':
            return true;
        case 'accountant':
            return $c['stage'] === 'contracted';
        case 'advisor':
            return (int) $c['created_by'] === $me;
        case 'booker':
            return $c['stage'] === 'new' || (int) $c['booker_id'] === $me;
        case 'communicator':
            return in_array($c['stage'], ['booked', 'confirmed'], true) || (int) $c['communicator_id'] === $me;
        case 'sales':
            return (int) $c['sales_id'] === $me;
    }
    return false;
}

/** Contract amounts are only for the closing rep and the back office. */
function can_view_contract(array $u, array $c): bool
{
    if (in_array($u['role'], ['admin', 'accountant', 'owner_services'], true)) return true;
    return $u['role'] === 'sales' && (int) $c['sales_id'] === (int) $u['id'];
}

function load_client(int $id): ?array
{
    return db_row(
        "SELECT c.*, a.name AS advisor_name, b.name AS booker_name, m.name AS communicator_name, s.name AS sales_name
           FROM clients c
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
        'new' => 'gray', 'booked' => 'blue', 'confirmed' => 'gold',
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

function seq_label(int $seq): string
{
    return $seq === 0 ? t('pay.down') : t('pay.inst_n', ['n' => (string) $seq]);
}
