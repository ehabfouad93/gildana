<?php
declare(strict_types=1);

/**
 * Word contract templates. The admin uploads their own .docx with placeholders
 * such as {official_name} or {total}; a placeholder alone in its paragraph,
 * {schedule_table}, becomes the full payment table. ZIP handling is plain PHP
 * (zip.php), so no extra PHP extension is needed on the server.
 */

require_once __DIR__ . '/zip.php';

/** Placeholder => value for one contract, in the template's language. */
function contract_vars(array $ct): array
{
    $cur  = (string) $ct['currency'];
    $last = db_val("SELECT MAX(due_date) FROM instalments WHERE contract_id = ?", [$ct['id']]);
    $season = $ct['season'] ? t('season.' . $ct['season']) : '';
    return [
        'contract_no'      => (string) $ct['contract_no'],
        'contract_date'    => fmt_date($ct['contract_date']),
        'client_id'        => client_code((int) $ct['client_id']),
        'official_name'    => (string) $ct['official_name'],
        'national_id'      => (string) $ct['national_id'],
        'nationality'      => (string) ($ct['nationality'] ?? ''),
        'birth_date'       => $ct['birth_date'] ? fmt_date($ct['birth_date']) : '',
        'address'          => (string) ($ct['address'] ?? ''),
        'phone'            => (string) ($ct['phone'] ?? ''),
        'email'            => (string) ($ct['email'] ?? ''),
        'second_party'     => (string) ($ct['second_party'] ?? ''),
        'project'          => (string) ($ct['project_name'] ?? ''),
        'project_location' => (string) ($ct['project_location'] ?? ''),
        'unit_type'        => (string) ($ct['unit_type'] ?? ''),
        'season'           => $season,
        'weeks_per_year'   => (string) $ct['weeks_per_year'],
        'duration_years'   => (string) $ct['duration_years'],
        'start_year'       => (string) $ct['start_year'],
        'end_year'         => (string) ((int) $ct['start_year'] + (int) $ct['duration_years'] - 1),
        'total'            => money($ct['total_amount'], $cur),
        'down_pct'         => rtrim(rtrim((string) $ct['down_pct'], '0'), '.') . '%',
        'down_payment'     => money($ct['down_amount'], $cur),
        'remaining'        => money((float) $ct['total_amount'] - (float) $ct['down_amount'], $cur),
        'months'           => (string) $ct['months'],
        'monthly'          => money($ct['monthly_amount'], $cur),
        'first_due'        => fmt_date($ct['first_due_date']),
        'last_due'         => $last ? fmt_date((string) $last) : '',
        'notes'            => (string) ($ct['notes'] ?? ''),
        'sales_name'       => (string) ($ct['sales_name'] ?? ''),
        'company_name'     => setting('company_name'),
        'company_address'  => setting('company_address'),
        'company_phone'    => setting('company_phone'),
        'company_reg'      => setting('company_reg'),
        'today'            => date('Y-m-d'),
    ];
}

/** Keys shown on the templates page, in a sensible reading order. */
function contract_var_keys(): array
{
    return ['contract_no', 'contract_date', 'client_id', 'official_name', 'national_id', 'nationality', 'birth_date', 'address',
            'phone', 'email', 'second_party', 'project', 'project_location', 'unit_type', 'season', 'weeks_per_year',
            'duration_years', 'start_year', 'end_year', 'total', 'down_pct', 'down_payment', 'remaining', 'months', 'monthly',
            'first_due', 'last_due', 'notes', 'sales_name', 'company_name', 'company_address', 'company_phone', 'company_reg',
            'today', 'schedule_table'];
}

function docx_x(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Word often splits "{official_name}" across several runs (spell-check, an edit
 * in the middle). Glue each placeholder back into one run so it can be found:
 * drop the run markup *between* the braces, but only when what is left is a
 * plain placeholder name and the split never crosses a paragraph.
 */
function docx_join_placeholders(string $xml): string
{
    return preg_replace_callback('/\{((?:[^{}<]|<[^>]*>){1,400}?)\}/u', function ($m) {
        $inner = $m[1];
        if (!str_contains($inner, '<')) return $m[0];
        if (str_contains($inner, '<w:p ') || str_contains($inner, '<w:p>') || str_contains($inner, '</w:p>')) return $m[0];
        $text = preg_replace('/<[^>]*>/', '', $inner) ?? '';
        return preg_match('/^\s*[a-z_0-9]+\s*$/', $text) ? '{' . trim($text) . '}' : $m[0];
    }, $xml) ?? $xml;
}

/** The payment schedule as a Word table. */
function docx_schedule_table(array $rows, string $cur, bool $rtl): string
{
    $cell = function (string $text, bool $head = false) use ($rtl): string {
        $rPr = '<w:rPr>' . ($head ? '<w:b/><w:bCs/>' : '') . ($rtl ? '<w:rtl/>' : '') . '</w:rPr>';
        return '<w:tc><w:tcPr><w:tcBorders><w:top w:val="single" w:sz="4" w:space="0" w:color="999999"/><w:left w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
             . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="999999"/><w:right w:val="single" w:sz="4" w:space="0" w:color="999999"/></w:tcBorders>'
             . ($head ? '<w:shd w:val="clear" w:color="auto" w:fill="F3EBDA"/>' : '') . '</w:tcPr>'
             . '<w:p><w:pPr>' . ($rtl ? '<w:bidi/>' : '') . '<w:spacing w:before="40" w:after="40"/></w:pPr><w:r>' . $rPr . '<w:t xml:space="preserve">' . docx_x($text) . '</w:t></w:r></w:p></w:tc>';
    };
    $xml = '<w:tbl><w:tblPr>' . ($rtl ? '<w:bidiVisual/>' : '') . '<w:tblW w:w="5000" w:type="pct"/><w:tblLayout w:type="autofit"/></w:tblPr><w:tblGrid><w:gridCol w:w="800"/><w:gridCol w:w="3000"/><w:gridCol w:w="2900"/><w:gridCol w:w="2938"/></w:tblGrid>';
    $xml .= '<w:tr>' . $cell('#', true) . $cell(t('pay.item'), true) . $cell(t('pay.due_date'), true) . $cell(t('pay.amount'), true) . '</w:tr>';
    foreach ($rows as $r) {
        $xml .= '<w:tr>' . $cell((string) $r['seq']) . $cell(seq_label((int) $r['seq'])) . $cell(fmt_date($r['due_date'])) . $cell(money($r['amount'], $cur)) . '</w:tr>';
    }
    return $xml . '</w:tbl>';
}

/** Fill a template; returns the filled .docx bytes. */
function docx_fill(string $templatePath, array $vars, string $scheduleXml): string
{
    $files = jv_zip_read((string) file_get_contents($templatePath));
    foreach ($files as $name => $xml) {
        if (!preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name)) continue;
        $xml = docx_join_placeholders($xml);

        // {schedule_table} alone in a paragraph → the whole paragraph becomes the table.
        $xml = preg_replace_callback('#<w:p[ >](?:(?!</w:p>).)*?\{schedule_table\}(?:(?!</w:p>).)*?</w:p>#s',
            fn() => $scheduleXml, $xml) ?? $xml;

        $files[$name] = preg_replace_callback('/\{([a-z_0-9]+)\}/', fn($m) => array_key_exists($m[1], $vars) ? docx_x((string) $vars[$m[1]]) : $m[0], $xml) ?? $xml;
    }
    return jv_zip_write($files);
}

/** True when the file is a .docx Word can open (a ZIP with word/document.xml). */
function docx_valid(string $path): bool
{
    try {
        return isset(jv_zip_read((string) file_get_contents($path))['word/document.xml']);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * A ready-to-edit starter contract containing every placeholder, so the admin
 * can open it in Word, restyle and reword it, and upload it back.
 */
function docx_starter(string $lang): string
{
    $rtl = $lang === 'ar';
    $prevLocale = $_SESSION['locale'] ?? null;
    $_SESSION['locale'] = $lang;

    $p = function (string $text, bool $bold = false, int $size = 22, string $align = '') use ($rtl): string {
        $jc = $align !== '' ? '<w:jc w:val="' . $align . '"/>' : '';
        // pPr children must follow the schema order: bidi, spacing, jc.
        return '<w:p><w:pPr>' . ($rtl ? '<w:bidi/>' : '') . '<w:spacing w:after="120"/>' . $jc . '</w:pPr><w:r><w:rPr>'
             . ($bold ? '<w:b/><w:bCs/>' : '') . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>' . ($rtl ? '<w:rtl/>' : '')
             . '</w:rPr><w:t xml:space="preserve">' . docx_x($text) . '</w:t></w:r></w:p>';
    };
    $body  = $p('{company_name}', true, 32, 'center');
    $body .= $p('{company_address} · {company_phone}', false, 18, 'center');
    $body .= $p(t('doc.title'), true, 30, 'center');
    $body .= $p(t('contract.no') . ': {contract_no}    ' . t('contract.date') . ': {contract_date}    ' . t('search.client_no') . ': {client_id}');
    $body .= $p(t('doc.first_party'), true, 24);
    $body .= $p(t('doc.first_party_text', ['company' => '{company_name}', 'address' => '{company_address}']));
    $body .= $p(t('doc.second_party'), true, 24);
    $body .= $p(t('contract.official_name') . ': {official_name}');
    $body .= $p(t('contract.national_id') . ': {national_id}    ' . t('contract.nationality') . ': {nationality}');
    $body .= $p(t('contract.address') . ': {address}    ' . t('client.phone') . ': {phone}');
    $body .= $p(t('doc.subject'), true, 24);
    $body .= $p(t('doc.subject_text', ['weeks' => '{weeks_per_year}', 'years' => '{duration_years}', 'project' => '{project}']));
    $body .= $p(t('contract.unit_type') . ': {unit_type}    ' . t('contract.season') . ': {season}    ' . t('doc.period') . ': {start_year} – {end_year}');
    $body .= $p(t('doc.price'), true, 24);
    $body .= $p(t('doc.price_text', ['total' => '{total}', 'pct' => '{down_pct}', 'down' => '{down_payment}', 'months' => '{months}', 'monthly' => '{monthly}', 'first' => '{first_due}']));
    $body .= $p(t('doc.schedule'), true, 24);
    $body .= $p('{schedule_table}');
    $body .= $p(t('doc.terms'), true, 24);
    for ($n = 1; $n <= 7; $n++) $body .= $p($n . '. ' . t('doc.term' . $n));
    $body .= $p(' ');
    $body .= $p(t('doc.first_party') . ': {company_name}                    ' . t('doc.second_party') . ': {official_name}', true);
    $body .= $p(t('doc.signature') . ': ____________________                    ' . t('doc.signature') . ': ____________________');

    if ($prevLocale === null) unset($_SESSION['locale']); else $_SESSION['locale'] = $prevLocale;

    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body
         . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/>'
         . ($rtl ? '<w:bidi/>' : '') . '</w:sectPr></w:body></w:document>';
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
           . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
           . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>';
    $rels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
           . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';

    return jv_zip_write(['[Content_Types].xml' => $types, '_rels/.rels' => $rels, 'word/document.xml' => $doc]);
}

function templates_dir(): string
{
    return dirname(__DIR__) . '/storage/templates';
}
