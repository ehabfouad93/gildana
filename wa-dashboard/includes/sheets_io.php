<?php
declare(strict_types=1);
/**
 * Spreadsheets in and out, for every place that takes a list of people: a link (Google Sheets,
 * a Google Drive file, Dropbox, or any public https link to a CSV or Excel file) or an uploaded
 * file — .csv or .xlsx either way — and a small .xlsx writer for sample files and downloads.
 *
 * Reading reuses includes/import.php (the CRM importer's CSV and .xlsx readers), so there is one
 * parser for all of them. A link is fetched over https only, each redirect hop checked against
 * private addresses (safe_http_url_ok), so a pasted link cannot reach the server's own network.
 */

require_once __DIR__ . '/import.php';

const SHEET_MAX_BYTES = 20971520;   // 20 MB

/** A share link → the address that returns the file itself. */
function sheet_link_normalize(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    // Google Sheets: the sheet as CSV (a published /d/e/ link or an explicit csv one is used as it is).
    if (preg_match('~docs\.google\.com/spreadsheets/d/(?!e/)([a-zA-Z0-9_-]+)~', $url, $m) && stripos($url, 'output=csv') === false && stripos($url, 'format=csv') === false) {
        $gid = preg_match('~[#?&]gid=([0-9]+)~', $url, $g) ? $g[1] : '0';
        return "https://docs.google.com/spreadsheets/d/{$m[1]}/export?format=csv&gid={$gid}";
    }
    // A file in Google Drive (an uploaded .xlsx or .csv): its download address.
    if (preg_match('~drive\.google\.com/(?:file/d/|open\?id=|uc\?(?:[^#]*&)?id=)([a-zA-Z0-9_-]+)~', $url, $m)) {
        return 'https://drive.google.com/uc?export=download&id=' . $m[1];
    }
    // Dropbox: the file, not its preview page.
    if (preg_match('~^https://(www\.)?dropbox\.com/~i', $url)) {
        $url = preg_replace('~([?&])dl=0~', '$1dl=1', $url);
        if (!preg_match('~[?&]dl=1~', $url)) $url .= (str_contains($url, '?') ? '&' : '?') . 'dl=1';
    }
    return $url;
}

/**
 * Fetch a pasted link. Redirects are followed by hand (Google and Dropbox always redirect),
 * every hop checked the same way as the first.
 *
 * @return array{ok:bool, bytes:string, error:string}
 */
function sheet_fetch_link(string $url): array
{
    $url = sheet_link_normalize($url);
    if ($url === '') return ['ok' => false, 'bytes' => '', 'error' => 'Paste the link first.'];
    for ($hop = 0; $hop < 6; $hop++) {
        if (!safe_http_url_ok($url)) return ['ok' => false, 'bytes' => '', 'error' => 'That link cannot be opened — use a public https:// link.'];
        $ch = curl_init($url);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'RevenectWA/1.0',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => fn($c, $dt, $dn) => ($dt > SHEET_MAX_BYTES || $dn > SHEET_MAX_BYTES) ? 1 : 0,
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$headers) { $p = explode(':', $h, 2); if (count($p) === 2) $headers[strtolower(trim($p[0]))] = trim($p[1]); return strlen($h); },
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) return ['ok' => false, 'bytes' => '', 'error' => 'Could not download it' . ($err !== '' ? ' (' . $err . ')' : '') . '. Files up to 20 MB.'];
        if ($code >= 300 && $code < 400 && !empty($headers['location'])) {
            $loc = $headers['location'];
            if (!preg_match('~^https?://~i', $loc)) {   // a relative redirect
                $p = parse_url($url);
                $loc = 'https://' . $p['host'] . (str_starts_with($loc, '/') ? $loc : '/' . $loc);
            }
            $url = $loc;
            continue;
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'bytes' => '', 'error' => $code === 404 ? 'Nothing is at that link (404).'
                   : ($code === 401 || $code === 403 ? 'That link is private. Share it with "Anyone with the link" (Viewer) and try again.' : 'The link answered with an error (HTTP ' . $code . ').')];
        }
        if (preg_match('~^(?:\xEF\xBB\xBF)?\s*<(?:!doctype|html|\?xml(?![^>]*spreadsheet))~i', (string) $body)) {
            return ['ok' => false, 'bytes' => '', 'error' => 'The link opens a web page, not a file. In Google Drive / Sheets: Share → "Anyone with the link" (Viewer); for Dropbox and others, use a direct download link.'];
        }
        return ['ok' => true, 'bytes' => (string) $body, 'error' => ''];
    }
    return ['ok' => false, 'bytes' => '', 'error' => 'The link redirected too many times.'];
}

/** A spreadsheet's bytes (CSV or .xlsx) → header and rows. */
function sheet_parse_bytes(string $bytes, string $nameHint = ''): array
{
    $isXlsx = str_starts_with($bytes, "PK\x03\x04") || str_ends_with(strtolower($nameHint), '.xlsx');
    $tmp = tempnam(sys_get_temp_dir(), 'sh');
    file_put_contents($tmp, $bytes);
    try {
        $r = import_read($tmp, $isXlsx ? 'sheet.xlsx' : 'sheet.csv');
    } finally { @unlink($tmp); }
    return $r;
}

/** Header + rows → CSV text (for the importers that take CSV). */
function sheet_rows_to_csv(array $header, array $rows): string
{
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $header);
    foreach ($rows as $r) fputcsv($fh, $r);
    rewind($fh);
    $s = (string) stream_get_contents($fh);
    fclose($fh);
    return $s;
}

/** Whatever was uploaded or downloaded (CSV or .xlsx) → CSV text, or an error. */
function sheet_to_csv(string $bytes, string $nameHint = ''): array
{
    if (trim($bytes) === '') return ['ok' => false, 'csv' => '', 'error' => 'The file is empty.'];
    if (str_starts_with($bytes, "\xD0\xCF\x11\xE0")) return ['ok' => false, 'csv' => '', 'error' => 'That is an old-style .xls file. In Excel choose File → Save As → "Excel Workbook (.xlsx)" or "CSV", then use that.'];
    $r = sheet_parse_bytes($bytes, $nameHint);
    if (empty($r['ok'])) return ['ok' => false, 'csv' => '', 'error' => (string) ($r['error'] ?? 'The file could not be read. Use a .csv or .xlsx file.')];
    return ['ok' => true, 'csv' => sheet_rows_to_csv((array) $r['header'], (array) $r['rows']), 'error' => ''];
}

/**
 * A small .xlsx (one sheet): the first row bold, every cell text — so a phone number keeps its
 * leading zero or + and Excel never turns it into 2.01E+11.
 */
function xlsx_build(array $rows, array $widths = [], string $sheet = 'Sheet1'): string
{
    $x = fn($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
    $data = '';
    foreach (array_values($rows) as $r => $row) {
        $data .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $v) {
            $data .= '<c r="' . $col($c) . ($r + 1) . '" t="inlineStr" s="' . ($r === 0 ? 1 : 2) . '"><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
        }
        $data .= '</row>';
    }
    $cols = '';
    foreach (array_values($widths) as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $x(mb_substr($sheet, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF6D28D9"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '') . '<sheetData>' . $data . '</sheetData></worksheet>',
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'xl');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $n => $c) $zip->addFromString($n, $c);
    $zip->close();
    $bin = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

/** Send an .xlsx download and stop. */
function xlsx_download(string $filename, array $rows, array $widths = []): void
{
    $bin = xlsx_build($rows, $widths);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}
