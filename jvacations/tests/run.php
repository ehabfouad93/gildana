<?php
declare(strict_types=1);

/**
 * Pure-logic tests — no database, no web server.
 *   php tests/run.php
 */
require dirname(__DIR__) . '/includes/money.php';

$fails = 0;
function check(string $name, $got, $want): void
{
    global $fails;
    if ($got === $want) { echo "  ok   $name\n"; return; }
    $fails++;
    echo "  FAIL $name\n       got:  " . var_export($got, true) . "\n       want: " . var_export($want, true) . "\n";
}

echo "money\n";
check('plain',          to_cents('250000'), 25000000);
check('commas',         to_cents('250,000.5'), 25000050);
check('two decimals',   to_cents('10.05'), 1005);
check('rejects text',   to_cents('12a'), -1);
check('rejects 3 dp',   to_cents('1.234'), -1);
check('from_cents',     from_cents(25000050), '250000.50');

echo "months\n";
check('31 Jan + 1',     add_months('2026-01-31', 1), '2026-02-28');
check('31 Jan + 1 leap', add_months('2028-01-31', 1), '2028-02-29');
check('31 Jan + 2',     add_months('2026-01-31', 2), '2026-03-31');
check('year roll',      add_months('2026-11-15', 3), '2027-02-15');
check('+35',            add_months('2026-01-31', 35), '2028-12-31');

echo "schedule\n";
$p = build_schedule(25000000, 25, 36, '2026-01-31', '2026-02-28');
check('37 rows',        count($p['rows']), 37);
check('down 25%',       $p['down'], 6250000);
check('monthly whole',  $p['monthly'], 520800);
check('last absorbs',   end($p['rows'])['amount'], 522000);
check('sums to total',  array_sum(array_column($p['rows'], 'amount')), 25000000);
check('down due on contract date', $p['rows'][0]['due'], '2026-01-31');
check('first inst',     $p['rows'][1]['due'], '2026-02-28');

$p = build_schedule(18000000, 25, 24, '2026-10-01', '2026-11-01');
check('even split',     $p['monthly'], 562500);
check('even last',      end($p['rows'])['amount'], 562500);
check('24 months end',  end($p['rows'])['due'], '2028-10-01');

$p = build_schedule(1001, 25, 36, '2026-01-01', '2026-02-01');
check('tiny total sums', array_sum(array_column($p['rows'], 'amount')), 1001);

echo $fails ? "\n$fails failure(s)\n" : "\nall passed\n";
exit($fails ? 1 : 0);
