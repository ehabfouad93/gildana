<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * One holiday reservation as a branded confirmation the owner can keep. Same
 * three outputs as the contract: on-screen preview, print (→ Save as PDF) and
 * a Word download. ?lang=ar|en picks the document language.
 */
$id = (int) ($_GET['id'] ?? 0);
$s  = db_row("SELECT s.*, p.name AS project_name, p.location AS project_location,
                     ct.contract_no, ct.official_name, ct.national_id, ct.phone AS contract_phone, ct.client_id,
                     ct.weeks_per_year, ct.duration_years, ct.start_year, ct.season, ct.unit_type AS contract_unit,
                     c.phone AS client_phone, u.name AS booked_by
                FROM stays s JOIN projects p ON p.id = s.project_id JOIN contracts ct ON ct.id = s.contract_id
                JOIN clients c ON c.id = ct.client_id LEFT JOIN users u ON u.id = s.created_by
               WHERE s.id = ?", [$id]);
if (!$s) { http_response_code(404); exit(t('err.not_found')); }
$c = client_or_403((int) $s['client_id'], $ME);
if (!can_view_stays($ME, $c)) { http_response_code(403); exit(t('err.forbidden')); }

$lang = in_array($_GET['lang'] ?? '', ['ar', 'en'], true) ? $_GET['lang'] : locale();
$uiLocale = $_SESSION['locale'] ?? null;
$_SESSION['locale'] = $lang;
$L = fn(string $k, array $v = []) => t($k, $v);

$year   = (int) substr($s['check_in'], 0, 4);
$usedY  = (int) db_val("SELECT COALESCE(SUM(weeks),0) FROM stays WHERE contract_id = ? AND status = 'confirmed' AND YEAR(check_in) = ?", [$s['contract_id'], $year]);
$nights = (int) round((strtotime($s['check_out']) - strtotime($s['check_in'])) / 86400);
$cancelled = $s['status'] === 'cancelled';

$logoFile = dirname(__DIR__) . '/assets/brand/logo-mark.png';
$logoSrc  = (($_GET['download'] ?? '') === 'doc' && is_file($logoFile))
    ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoFile))
    : '../assets/brand/logo-mark.png';

ob_start();
?>
<div class="contract-doc voucher<?= $cancelled ? ' is-cancelled' : '' ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>" lang="<?= e($lang) ?>">
  <div class="cd-head">
    <div class="cd-brand">
      <img class="cd-logo" src="<?= $logoSrc ?>" alt="">
      <div>
        <div class="cd-company"><?= e(setting('company_name')) ?></div>
        <div class="cd-small"><?= e(setting('company_address')) ?><?= setting('company_phone') !== '' ? ' · ' . e(setting('company_phone')) : '' ?></div>
      </div>
    </div>
    <div class="cd-meta">
      <div><?= e($L('voucher.no')) ?>: <strong><?= e(stay_code($id)) ?></strong></div>
      <div><?= e($L('voucher.issued')) ?>: <strong><?= e(date('Y-m-d')) ?></strong></div>
      <div><?= e($L('search.client_no')) ?>: <strong><?= e(client_code((int) $s['client_id'])) ?></strong></div>
    </div>
  </div>

  <h1 class="cd-title"><?= e($L('voucher.title')) ?></h1>
  <?php if ($cancelled): ?><p class="voucher-cancelled"><?= e($L('voucher.cancelled')) ?></p><?php endif; ?>
  <p class="cd-intro"><?= e($L('voucher.intro', ['name' => $s['official_name']])) ?></p>

  <div class="voucher-dates">
    <div><span><?= e($L('stay.check_in')) ?></span><strong><?= e(fmt_date($s['check_in'])) ?></strong><em><?= e(fmt_time($s['check_in_time'])) ?></em></div>
    <div class="arrow"><?= $lang === 'ar' ? '←' : '→' ?></div>
    <div><span><?= e($L('stay.check_out')) ?></span><strong><?= e(fmt_date($s['check_out'])) ?></strong><em><?= e($L('voucher.nights', ['n' => (string) $nights])) ?></em></div>
  </div>

  <h3><?= e($L('voucher.stay')) ?></h3>
  <table class="cd-table">
    <tr><th><?= e($L('contract.project')) ?></th><td><?= e($s['project_name']) ?><?= $s['project_location'] ? ' — ' . e($s['project_location']) : '' ?></td>
        <th><?= e($L('contract.unit_type')) ?></th><td><?= e($s['unit_type'] ?: ($s['contract_unit'] ?: '—')) ?></td></tr>
    <tr><th><?= e($L('stay.weeks')) ?></th><td><?= (int) $s['weeks'] ?></td>
        <th><?= e($L('stay.guests')) ?></th><td><?= $s['guests'] ? (int) $s['guests'] : '—' ?></td></tr>
    <?php if ($s['notes']): ?><tr><th><?= e($L('ui.note')) ?></th><td colspan="3"><?= e($s['notes']) ?></td></tr><?php endif; ?>
  </table>

  <h3><?= e($L('voucher.owner')) ?></h3>
  <table class="cd-table">
    <tr><th><?= e($L('contract.official_name')) ?></th><td><?= e($s['official_name']) ?></td>
        <th><?= e($L('contract.no')) ?></th><td dir="ltr"><?= e($s['contract_no']) ?></td></tr>
    <tr><th><?= e($L('client.phone')) ?></th><td dir="ltr"><?= e($s['contract_phone'] ?: $s['client_phone']) ?></td>
        <th><?= e($L('contract.national_id')) ?></th><td dir="ltr"><?= e($s['national_id']) ?></td></tr>
    <tr><th><?= e($L('voucher.allowance')) ?></th>
        <td colspan="3"><?= e($L('voucher.allowance_line', ['year' => (string) $year, 'used' => (string) $usedY, 'allowed' => (string) $s['weeks_per_year']])) ?></td></tr>
  </table>

  <h3><?= e($L('voucher.terms')) ?></h3>
  <ol class="cd-terms">
    <?php for ($n = 1; $n <= 4; $n++): ?><li><?= e($L('voucher.term' . $n, ['time' => fmt_time($s['check_in_time'])])) ?></li><?php endfor; ?>
  </ol>

  <div class="cd-foot"><?= e($L('voucher.booked_by', ['name' => $s['booked_by'] ?? '—', 'date' => fmt_dt($s['created_at'])])) ?></div>
</div>
<?php
$doc    = (string) ob_get_clean();
$docCss = (string) @file_get_contents(dirname(__DIR__) . '/assets/contract.css');
if ($uiLocale === null) unset($_SESSION['locale']); else $_SESSION['locale'] = $uiLocale;

$fileBase = 'holiday-' . stay_code($id) . '-' . $s['check_in'];
if (($_GET['download'] ?? '') === 'doc') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9-]/', '', $fileBase) . '.doc"');
    echo "\xEF\xBB\xBF<html xmlns:o=\"urn:schemas-microsoft-com:office:office\" xmlns:w=\"urn:schemas-microsoft-com:office:word\">"
       . '<head><meta charset="UTF-8"><title>' . e(stay_code($id)) . '</title><style>' . $docCss . '</style></head><body>' . $doc . '</body></html>';
    exit;
}
if (($_GET['print'] ?? '') === '1') {
    ?><!DOCTYPE html><html lang="<?= e($lang) ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>"><head><meta charset="UTF-8">
<title><?= e($fileBase) ?></title><?= brand_head('../') ?><style><?= $docCss ?></style></head>
<body class="print-body" onload="window.print()"><?= $doc ?></body></html><?php
    exit;
}

$q = fn(array $x) => '?' . http_build_query(array_merge(['id' => $id, 'lang' => $lang], $x));
$actions = '<a class="btn btn-primary" href="' . e($q(['print' => '1'])) . '" target="_blank">' . e(t('doc.print')) . '</a>'
         . '<a class="btn" href="' . e($q(['download' => 'doc'])) . '">' . e(t('doc.download')) . '</a>'
         . '<a class="btn btn-ghost" href="' . e($q(['lang' => $lang === 'ar' ? 'en' : 'ar'])) . '">' . e($lang === 'ar' ? 'English' : 'العربية') . '</a>'
         . '<a class="btn btn-ghost" href="client.php?id=' . (int) $s['client_id'] . '">' . e(t('client.profile')) . '</a>';

layout_header(t('voucher.title') . ' ' . stay_code($id), 'calendar');
page_head(t('voucher.title'), stay_code($id) . ' · ' . $s['official_name'], $actions);
?>
<p class="hint-line"><?= e(t('doc.pdf_hint')) ?></p>
<style><?= $docCss ?></style>
<div class="doc-frame"><?= $doc ?></div>
<?php layout_footer();
