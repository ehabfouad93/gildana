<?php
declare(strict_types=1);

/**
 * Pure money and date maths for contracts. No database, no session — the
 * tests load this file on its own.
 */

/* Amounts are integer cents internally; never float arithmetic on totals. */

function to_cents($amount): int
{
    $s = str_replace([',', ' '], '', trim((string) $amount));
    if ($s === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $s)) return -1;
    [$whole, $frac] = array_pad(explode('.', $s), 2, '');
    return (int) $whole * 100 + (int) str_pad($frac, 2, '0');
}

function from_cents(int $cents): string
{
    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}

/**
 * Add $n calendar months to a date, clamping the day so 31 Jan + 1 month is
 * 28/29 Feb rather than PHP's default spill into March.
 */
function add_months(string $ymd, int $n): string
{
    [$y, $m, $d] = array_map('intval', explode('-', $ymd));
    $m0 = $m - 1 + $n;
    $y += intdiv($m0, 12);
    $m  = $m0 % 12 + 1;
    $last = (int) date('t', mktime(0, 0, 0, $m, 1, $y));
    return sprintf('%04d-%02d-%02d', $y, $m, min($d, $last));
}

/**
 * The full payment plan for a contract.
 * Down payment = total × pct, due on the contract date. The rest is split over
 * $months monthly instalments starting $firstDue. Instalments are rounded down
 * to whole currency units and the final one absorbs the remainder, so the plan
 * always sums to the contract total exactly.
 *
 * @return array{down:int, monthly:int, rows:list<array{seq:int,due:string,amount:int}>}  amounts in cents
 */
function build_schedule(int $totalCents, float $downPct, int $months, string $contractDate, string $firstDue): array
{
    $down      = (int) round($totalCents * $downPct / 100);
    $remaining = $totalCents - $down;
    $base      = intdiv($remaining, $months * 100) * 100;
    if ($base === 0) $base = intdiv($remaining, $months);

    $rows = [['seq' => 0, 'due' => $contractDate, 'amount' => $down]];
    for ($i = 1; $i <= $months; $i++) {
        $amt = $i < $months ? $base : $remaining - $base * ($months - 1);
        $rows[] = ['seq' => $i, 'due' => add_months($firstDue, $i - 1), 'amount' => $amt];
    }
    return ['down' => $down, 'monthly' => $base, 'rows' => $rows];
}

/**
 * Re-plan what is still owed on a contract without touching recorded payments.
 *
 *   - fully paid instalments stay exactly as they are;
 *   - a partly paid instalment is closed at what was actually paid (its shortfall
 *     moves into the new plan);
 *   - an unpaid down payment stays as it is;
 *   - every other unpaid instalment is replaced by $months new ones from $firstDue,
 *     splitting (new total − kept) the same way build_schedule() does.
 *
 * @param list<array{id:int,seq:int,amount:int,paid:int,status:string}> $rows  amounts in cents
 * @return array{close:array<int,int>, delete:list<int>, kept:int, monthly:int, rows:list<array{seq:int,due:string,amount:int}>}
 * @throws InvalidArgumentException when the new total is below what is already kept
 */
function reschedule_plan(array $rows, int $newTotal, int $months, string $firstDue): array
{
    $close = []; $delete = []; $kept = 0; $maxSeq = 0;
    foreach ($rows as $r) {
        if ($r['status'] === 'paid') {
            $kept += $r['amount'];
        } elseif ($r['paid'] > 0) {
            $close[$r['id']] = $r['paid'];
            $kept += $r['paid'];
        } elseif ($r['seq'] === 0) {
            $kept += $r['amount'];
        } else {
            $delete[] = $r['id'];
            continue;
        }
        $maxSeq = max($maxSeq, $r['seq']);
    }
    $remaining = $newTotal - $kept;
    if ($remaining < 0) throw new InvalidArgumentException('below_kept');

    $out = []; $base = 0;
    if ($remaining > 0) {
        $months = max(1, $months);
        $base = intdiv($remaining, $months * 100) * 100;
        if ($base === 0) $base = intdiv($remaining, $months);
        for ($i = 1; $i <= $months; $i++) {
            $out[] = ['seq' => $maxSeq + $i, 'due' => add_months($firstDue, $i - 1),
                      'amount' => $i < $months ? $base : $remaining - $base * ($months - 1)];
        }
    }
    return ['close' => $close, 'delete' => $delete, 'kept' => $kept, 'monthly' => $base, 'rows' => $out];
}
