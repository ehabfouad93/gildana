<?php
declare(strict_types=1);

/**
 * Reading contacts and leads from a spreadsheet — CSV or Excel — and writing them.
 *
 * One importer for Contacts and the CRM. The Contacts page had its own loop; the CRM needs the
 * same thing plus column mapping, stages and owners, and two importers would disagree about what
 * counts as a valid phone number within a release.
 *
 * .xlsx is read directly with ZipArchive + SimpleXML (an .xlsx file is a zip of XML), the same
 * approach helpers.php already uses for .docx. No library, nothing to install on the server.
 */

require_once __DIR__ . '/crm.php';

/** Fields a column can be mapped to, and the header words that suggest each. */
function import_fields(): array
{
    return [
        'phone'    => ['label' => 'Phone',            'guess' => ['phone', 'mobile', 'number', 'whatsapp', 'msisdn', 'tel', 'رقم', 'موبايل', 'تليفون', 'هاتف']],
        'name'     => ['label' => 'Name',             'guess' => ['name', 'full name', 'full_name', 'fullname', 'contact', 'client', 'الاسم', 'اسم']],
        'email'    => ['label' => 'Email',            'guess' => ['email', 'e-mail', 'mail', 'البريد']],
        'value'    => ['label' => 'Deal value',       'guess' => ['value', 'budget', 'amount', 'price', 'deal', 'الميزانية', 'القيمة']],
        'stage'    => ['label' => 'Stage',            'guess' => ['stage', 'status', 'pipeline', 'المرحلة', 'الحالة']],
        'owner'    => ['label' => 'Owner (email or name)', 'guess' => ['owner', 'agent', 'sales', 'assigned', 'assignee', 'المسؤول', 'السيلز']],
        'followup' => ['label' => 'Next follow-up',   'guess' => ['follow up', 'followup', 'follow-up', 'next', 'موعد']],
        'note'     => ['label' => 'Note',             'guess' => ['note', 'notes', 'comment', 'comments', 'ملاحظات']],
        'project'  => ['label' => 'Project',          'guess' => ['project', 'compound', 'development', 'المشروع', 'مشروع', 'الكمبوند']],
        'unit_type'=> ['label' => 'Unit type',        'guess' => ['unit type', 'unit_type', 'property type', 'نوع الوحدة', 'الوحدة']],
    ];
}

/**
 * Read a spreadsheet into a header and rows.
 *
 * @return array{ok:bool, error?:string, header?:string[], rows?:array<int,string[]>}
 */
function import_read(string $path, string $filename): array
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'xls') {
        return ['ok' => false, 'error' => 'That is an old-style .xls file. In Excel choose File → Save As → '
                                         . '"Excel Workbook (.xlsx)" or "CSV", then upload that.'];
    }
    $grid = $ext === 'xlsx' ? import_read_xlsx($path) : import_read_csv($path);
    if ($grid === null) return ['ok' => false, 'error' => 'That file could not be read. Upload a .csv or .xlsx file.'];

    // Drop fully blank rows — spreadsheets are full of them at the bottom.
    $grid = array_values(array_filter($grid, fn($r) => implode('', array_map('trim', $r)) !== ''));
    if (count($grid) < 2) return ['ok' => false, 'error' => 'The file needs a header row and at least one row of data.'];

    $header = array_map(fn($h) => trim((string) $h), array_shift($grid));
    $width  = count($header);
    foreach ($grid as &$r) $r = array_slice(array_pad($r, $width, ''), 0, max($width, count($r)));
    unset($r);
    return ['ok' => true, 'header' => $header, 'rows' => $grid];
}

function import_read_csv(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);                       // Excel's UTF-8 BOM
    // Excel in some locales saves "CSV" separated by semicolons; decide from the header line.
    $first = strtok($raw, "\n") ?: '';
    $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw); rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $sep)) !== false) $rows[] = array_map(fn($v) => (string) $v, $r);
    fclose($fh);
    return $rows;
}

/** Column letters → zero-based index: A → 0, Z → 25, AA → 26. */
function import_col_index(string $ref): int
{
    $letters = preg_replace('/\d+/', '', strtoupper($ref));
    $n = 0;
    foreach (str_split($letters) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

/**
 * The first worksheet of an .xlsx as a grid of strings.
 *
 * Handles the three ways a cell stores text (shared string table, inline string, formula result)
 * and gaps — Excel omits empty cells entirely, so a row's cells must be placed by their reference
 * (B2, D2) rather than read in order, or every column after a blank one shifts left.
 */
function import_read_xlsx(string $path): ?array
{
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;

    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sst = @simplexml_load_string($xml);
        if ($sst) {
            foreach ($sst->si as $si) {
                // Rich text splits one string into runs; the text is all of them joined.
                $t = isset($si->t) ? (string) $si->t : '';
                foreach ($si->r as $run) $t .= (string) $run->t;
                $shared[] = $t;
            }
        }
    }

    // The first sheet in workbook order — not necessarily sheet1.xml once sheets are reordered.
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb   = @simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
    $rels = @simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
    if ($wb && $rels && isset($wb->sheets->sheet[0])) {
        $rid = (string) $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $target = ltrim((string) $rel['Target'], '/');
                $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }
    }
    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) return null;
    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData)) return null;

    $grid = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        $pos = 0;
        foreach ($row->c as $c) {
            // r is optional in the format; a cell without it simply follows the previous one.
            $ref  = (string) $c['r'];
            $idx  = $ref !== '' ? import_col_index($ref) : $pos;
            $pos  = $idx + 1;
            $type = (string) $c['t'];
            if ($type === 's')              $v = $shared[(int) $c->v] ?? '';
            elseif ($type === 'inlineStr')  $v = (string) $c->is->t;
            elseif ($type === 'b')          $v = ((string) $c->v) === '1' ? 'TRUE' : 'FALSE';
            else {
                $v = (string) $c->v;
                /* A phone typed into a number cell is stored as a double, and some writers put
                   it in scientific notation (2.01001234567E11). The digits are all there, so
                   expand it — read naively it becomes a different, wrong number. */
                if (preg_match('/^-?\d+(\.\d+)?E[+-]?\d+$/i', $v)) $v = sprintf('%.0f', (float) $v);
                elseif (preg_match('/^\d+\.0+$/', $v))            $v = substr($v, 0, strpos($v, '.'));
            }
            $cells[$idx] = $v;
        }
        if (!$cells) { $grid[] = []; continue; }
        $line = array_fill(0, max(array_keys($cells)) + 1, '');
        foreach ($cells as $i => $v) $line[$i] = $v;
        $grid[] = $line;
    }
    return $grid;
}

/** Best guess of which column holds what, from the header words. field => column index. */
function import_guess_mapping(array $header): array
{
    $map = [];
    foreach (import_fields() as $field => $f) {
        foreach ($header as $i => $h) {
            $h = mb_strtolower(trim((string) $h));
            if ($h === '' || in_array($i, $map, true)) continue;
            foreach ($f['guess'] as $g) {
                if ($h === $g || str_contains($h, $g)) { $map[$field] = $i; continue 3; }
            }
        }
    }
    if (!isset($map['phone'])) $map['phone'] = 0;         // same fallback the old importer used
    return $map;
}

/** An Excel date serial (45560) or a written date, as a DATETIME — or null. */
function import_date(string $v): ?string
{
    $v = trim($v);
    if ($v === '') return null;
    if (preg_match('/^\d{4,5}(\.\d+)?$/', $v)) {                       // Excel serial day number
        return gmdate('Y-m-d H:i:s', (int) round(((float) $v - 25569) * 86400));
    }
    $t = strtotime(str_replace('/', '-', $v));
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

/**
 * Write rows as contacts, and optionally into the CRM pipeline.
 *
 * @param array $map   field => column index (from the mapping screen or import_guess_mapping)
 * @param array $opts  country, crm (bool), owner ('auto'|'none'|user id), stage_id,
 *                     extras (bool: keep unmapped columns as contact details), source
 * @return array{total:int, added:int, updated:int, skipped:int, leads:int, problems:array<int,string>}
 */
function import_contacts(array $client, array $header, array $rows, array $map, array $opts = []): array
{
    $cid     = (int) $client['id'];
    $country = (string) ($opts['country'] ?? $client['default_country'] ?? '');
    $toCrm   = !empty($opts['crm']) && crm_enabled($client);
    $extras  = $opts['extras'] ?? true;
    $source  = (string) ($opts['source'] ?? 'import');
    $by      = crm_actor_id();

    /* A salesperson sees only their own leads, so whatever they import has to be theirs —
       otherwise their own upload would vanish from their screen the moment it finished. */
    $owner = $opts['owner'] ?? 'auto';
    if (function_exists('is_sales') && is_sales()) $owner = $by;

    $stages = crm_stage_map($cid);
    $stageByName = [];
    foreach ($stages as $sid => $s) $stageByName[mb_strtolower(trim((string) $s['name']))] = $sid;
    $projByName = [];
    foreach (crm_projects($cid) as $pj) $projByName[mb_strtolower(trim((string) $pj['name']))] = (int) $pj['id'];
    $users = [];
    foreach (crm_assignable_users($cid) as $u) {
        $users[mb_strtolower((string) $u['email'])] = (int) $u['id'];
        $users[mb_strtolower((string) $u['name'])]  = (int) $u['id'];
    }

    $mapped = array_flip(array_filter($map, fn($v) => $v !== null && $v !== ''));
    $sum = ['total' => 0, 'added' => 0, 'updated' => 0, 'skipped' => 0, 'leads' => 0, 'problems' => []];
    $problem = function (int $line, string $why) use (&$sum) {
        if (count($sum['problems']) < 200) $sum['problems'][] = "Row {$line}: {$why}";
    };
    $col = fn(array $r, string $f) => isset($map[$f]) && $map[$f] !== '' ? trim((string) ($r[(int) $map[$f]] ?? '')) : '';

    foreach ($rows as $n => $r) {
        $line = $n + 2;                                         // +1 for the header, +1 for 1-based
        $sum['total']++;
        $rawPhone = $col($r, 'phone');

        // Excel shortened this number in the CSV (2.01001E+11): the missing digits are gone for
        // good, and guessing them would message a stranger. Say so, so they can fix the column.
        if (preg_match('/^\d(\.\d+)?E\+?\d+$/i', $rawPhone)) {
            $sum['skipped']++;
            $problem($line, "the phone \"{$rawPhone}\" was shortened by Excel. Format that column as Text in Excel and export again.");
            continue;
        }
        $phone = normalize_phone($rawPhone, $country);
        if ($phone === '') {
            $sum['skipped']++;
            $problem($line, $rawPhone === '' ? 'no phone number.' : "\"{$rawPhone}\" is not a phone number.");
            continue;
        }

        $name  = $col($r, 'name');
        $email = $col($r, 'email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $problem($line, "\"{$email}\" is not an email address — imported without it.");
            $email = '';
        }
        $attrs = [];
        if ($extras) {
            foreach ($header as $i => $h) {
                if (isset($mapped[$i]) || trim((string) $h) === '') continue;
                $v = trim((string) ($r[$i] ?? ''));
                if ($v !== '') $attrs[mb_strtolower(trim((string) $h))] = $v;
            }
        }

        $existing = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $phone]);
        if ($existing) {
            // Fill in what we learned; never blank out a name or email we already had.
            $merged = $attrs ? array_merge(json_decode((string) ($existing['attributes'] ?? ''), true) ?: [], $attrs) : null;
            db_run("UPDATE contacts SET name=COALESCE(NULLIF(?,''), name), email=COALESCE(NULLIF(?,''), email),
                           attributes=COALESCE(?, attributes) WHERE id=?",
                   [$name, $email, $merged ? json_encode($merged, JSON_UNESCAPED_UNICODE) : null, (int) $existing['id']]);
            $contactId = (int) $existing['id'];
            $sum['updated']++;
        } else {
            $contactId = db_insert("INSERT INTO contacts (client_id,phone_e164,name,email,attributes,opt_in_status,source,created_at)
                                    VALUES (?,?,?,?,?, 'in', ?, NOW())",
                [$cid, $phone, $name, $email !== '' ? $email : null,
                 $attrs ? json_encode($attrs, JSON_UNESCAPED_UNICODE) : null, $source]);
            $sum['added']++;
        }

        if (!$toCrm) continue;

        // Per-row stage and owner win over the batch defaults, when the sheet has them.
        $stageId = (int) ($opts['stage_id'] ?? 0) ?: null;
        if (($sv = mb_strtolower($col($r, 'stage'))) !== '') {
            if (isset($stageByName[$sv])) $stageId = $stageByName[$sv];
            else $problem($line, "no stage called \"{$col($r, 'stage')}\" — put in the default stage.");
        }
        $rowOwner = $owner;
        if (!(function_exists('is_sales') && is_sales()) && ($ov = mb_strtolower($col($r, 'owner'))) !== '') {
            if (isset($users[$ov])) $rowOwner = $users[$ov];
            else $problem($line, "no teammate called \"{$col($r, 'owner')}\" — assigned by the batch rule instead.");
        }

        // Project and unit type first, so the assignment rules can see them when the lead is dealt.
        if (db_has_column('contacts', 'project_id')) {
            $pv = mb_strtolower($col($r, 'project'));
            $pid = null;
            if ($pv !== '') {
                $pid = $projByName[$pv] ?? null;
                if ($pid === null) $problem($line, "no project called \"{$col($r, 'project')}\" — add it in Projects & lists, or fix the sheet.");
            }
            $uv = $col($r, 'unit_type');
            if ($pid !== null || $uv !== '') {
                db_run("UPDATE contacts SET project_id=COALESCE(?, project_id), unit_type=COALESCE(NULLIF(?,''), unit_type) WHERE id=?",
                       [$pid, mb_substr($uv, 0, 80), $contactId]);
            }
        }
        $isNew = crm_add_lead($client, $contactId, $source, $rowOwner, $stageId, $by);
        if ($isNew) $sum['leads']++;
        elseif ($stageId) crm_set_stage($client, $contactId, $stageId, $by);   // already a lead: honour the sheet's stage

        $value = $col($r, 'value');
        $fu    = import_date($col($r, 'followup'));
        $num   = $value !== '' ? (float) preg_replace('/[^\d.]/', '', $value) : null;
        if ($num !== null || $fu !== null) {
            db_run("UPDATE contacts SET deal_value=COALESCE(?, deal_value), next_followup_at=COALESCE(?, next_followup_at) WHERE id=?",
                   [$num, $fu, $contactId]);
        }
        if (($note = $col($r, 'note')) !== '') crm_add_note($client, $contactId, $note, $by);
    }
    return $sum;
}
