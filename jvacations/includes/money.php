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
