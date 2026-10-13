<?php
declare(strict_types=1);

/**
 * Pure-logic tests — no database, no web server.
 *   php tests/run.php
 */
require dirname(__DIR__) . '/includes/money.php';
require dirname(__DIR__) . '/includes/zip.php';

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

echo "reschedule\n";
// 120,000: down 30,000 paid, inst 1 paid 3,750, inst 2 partial 1,000 of 3,750, 22 unpaid left.
$rows = [['id' => 1, 'seq' => 0, 'amount' => 3000000, 'paid' => 3000000, 'status' => 'paid'],
         ['id' => 2, 'seq' => 1, 'amount' => 375000,  'paid' => 375000,  'status' => 'paid'],
         ['id' => 3, 'seq' => 2, 'amount' => 375000,  'paid' => 100000,  'status' => 'partial']];
for ($i = 3; $i <= 24; $i++) $rows[] = ['id' => $i + 1, 'seq' => $i, 'amount' => 375000, 'paid' => 0, 'status' => 'unpaid'];
$r = reschedule_plan($rows, 12000000, 36, '2027-01-15');
check('partial closed at paid', $r['close'], [3 => 100000]);
check('unpaid deleted',   count($r['delete']), 22);
check('kept',             $r['kept'], 3475000);
check('36 new rows',      count($r['rows']), 36);
check('seq continues',    $r['rows'][0]['seq'], 3);
check('new plan sums',    $r['kept'] + array_sum(array_column($r['rows'], 'amount')), 12000000);
check('first new due',    $r['rows'][0]['due'], '2027-01-15');
$threw = false;
try { reschedule_plan($rows, 1000000, 12, '2027-01-01'); } catch (InvalidArgumentException $e) { $threw = true; }
check('below kept rejected', $threw, true);
$r = reschedule_plan([['id' => 1, 'seq' => 0, 'amount' => 500, 'paid' => 0, 'status' => 'unpaid']], 2000, 3, '2027-01-01');
check('unpaid down kept', [$r['kept'], count($r['delete'])], [500, 0]);

echo "zip\n";
$files = ['[Content_Types].xml' => '<Types/>', 'word/document.xml' => str_repeat('<w:p>مرحبا {name}</w:p>', 500), 'empty.txt' => ''];
$back  = jv_zip_read(jv_zip_write($files));
check('round trip', $back, $files);
if (class_exists('ZipArchive')) {
    // A file written by a real ZIP library (as Word would) reads back identically.
    $tmp = tempnam(sys_get_temp_dir(), 'zt');
    $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $n => $d) $z->addFromString($n, $d);
    $z->setCompressionName('[Content_Types].xml', ZipArchive::CM_STORE);
    $z->close();
    check('reads ZipArchive output', jv_zip_read((string) file_get_contents($tmp)), $files);
    // And what we write opens in ZipArchive.
    file_put_contents($tmp, jv_zip_write($files));
    $z = new ZipArchive();
    check('ZipArchive opens ours', $z->open($tmp) === true && $z->getFromName('word/document.xml') === $files['word/document.xml'], true);
    $z->close(); @unlink($tmp);
}
$bad = false;
try { jv_zip_read('<?php echo 1;'); } catch (RuntimeException $e) { $bad = true; }
check('rejects non-zip', $bad, true);
$corrupt = jv_zip_write($files); $corrupt[60] = chr(ord($corrupt[60]) ^ 0xFF);
$bad = false;
try { jv_zip_read($corrupt); } catch (RuntimeException $e) { $bad = true; }
check('rejects corrupted data', $bad, true);

echo $fails ? "\n$fails failure(s)\n" : "\nall passed\n";
exit($fails ? 1 : 0);
