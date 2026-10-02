<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * The contract, filled automatically from the reservation. Three outputs from
 * one template: on-screen preview, browser print (→ Save as PDF), and a Word
 * download (.doc — HTML that Word opens and edits natively).
 * ?lang=ar|en picks the contract language independently of the UI language.
 */
$id = (int) ($_GET['id'] ?? 0);
$ct = db_row("SELECT ct.*, p.name AS project_name, p.location AS project_location, u.name AS sales_name
                FROM contracts ct JOIN projects p ON p.id = ct.project_id LEFT JOIN users u ON u.id = ct.created_by
               WHERE ct.id = ?", [$id]);
if (!$ct) { http_response_code(404); exit(t('err.not_found')); }
$c = client_or_403((int) $ct['client_id'], $ME);
if (!can_view_contract($ME, $c)) { http_response_code(403); exit(t('err.forbidden')); }

$lang = in_array($_GET['lang'] ?? '', ['ar', 'en'], true) ? $_GET['lang'] : locale();
$uiLocale = $_SESSION['locale'] ?? null;
$_SESSION['locale'] = $lang;              // render the document strings in the contract language
$L = fn(string $k, array $v = []) => t($k, $v);

$inst  = db_all("SELECT * FROM instalments WHERE contract_id = ? ORDER BY seq", [$id]);
$cur   = $ct['currency'];
$pct   = rtrim(rtrim($ct['down_pct'], '0'), '.');
$terms = trim(setting('terms_' . $lang));
$seasonLbl = $ct['season'] ? $L('season.' . $ct['season']) : '—';

// The Word download is a standalone file, so it carries the logo inline.
$logoFile = dirname(__DIR__) . '/assets/brand/logo-mark.png';
$logoSrc  = (($_GET['download'] ?? '') === 'doc' && is_file($logoFile))
    ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoFile))
    : '../assets/brand/logo-mark.png';

ob_start();
?>
<div class="contract-doc" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>" lang="<?= e($lang) ?>">
  <div class="cd-head">
    <div class="cd-brand">
      <img class="cd-logo" src="<?= $logoSrc ?>" alt="">
      <div>
      <div class="cd-company"><?= e(setting('company_name')) ?></div>
      <div class="cd-small"><?= e(setting('company_address')) ?><?= setting('company_phone') !== '' ? ' · ' . e(setting('company_phone')) : '' ?></div>
      <?php if (setting('company_reg') !== ''): ?><div class="cd-small"><?= e($L('doc.reg')) ?>: <?= e(setting('company_reg')) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="cd-meta">
      <div><?= e($L('contract.no')) ?>: <strong><?= e($ct['contract_no']) ?></strong></div>
      <div><?= e($L('contract.date')) ?>: <strong><?= e(fmt_date($ct['contract_date'])) ?></strong></div>
      <div><?= e($L('search.client_no')) ?>: <strong><?= e(client_code((int) $ct['client_id'])) ?></strong></div>
    </div>
  </div>

  <h1 class="cd-title"><?= e($L('doc.title')) ?></h1>
  <p class="cd-intro"><?= e($L('doc.intro', ['date' => fmt_date($ct['contract_date'])])) ?></p>

  <h3><?= e($L('doc.first_party')) ?></h3>
  <p><?= e(setting('company_address') !== ''
        ? $L('doc.first_party_text', ['company' => setting('company_name'), 'address' => setting('company_address')])
        : $L('doc.first_party_short', ['company' => setting('company_name')])) ?></p>

  <h3><?= e($L('doc.second_party')) ?></h3>
  <table class="cd-table">
    <tr><th><?= e($L('contract.official_name')) ?></th><td><?= e($ct['official_name']) ?></td>
        <th><?= e($L('contract.national_id')) ?></th><td dir="ltr"><?= e($ct['national_id']) ?></td></tr>
    <tr><th><?= e($L('contract.nationality')) ?></th><td><?= e($ct['nationality'] ?? '—') ?></td>
        <th><?= e($L('contract.birth_date')) ?></th><td><?= e(fmt_date($ct['birth_date'])) ?></td></tr>
    <tr><th><?= e($L('client.phone')) ?></th><td dir="ltr"><?= e($ct['phone'] ?? '—') ?></td>
        <th><?= e($L('client.email')) ?></th><td dir="ltr"><?= e($ct['email'] ?? '—') ?></td></tr>
    <tr><th><?= e($L('contract.address')) ?></th><td colspan="3"><?= e($ct['address'] ?? '—') ?></td></tr>
    <?php if ($ct['second_party']): ?>
    <tr><th><?= e($L('contract.second_party')) ?></th><td colspan="3"><?= e($ct['second_party']) ?></td></tr>
    <?php endif; ?>
  </table>

  <h3><?= e($L('doc.subject')) ?></h3>
  <table class="cd-table">
    <tr><th><?= e($L('contract.project')) ?></th><td><?= e($ct['project_name']) ?><?= $ct['project_location'] ? ' — ' . e($ct['project_location']) : '' ?></td>
        <th><?= e($L('contract.unit_type')) ?></th><td><?= e($ct['unit_type'] ?? '—') ?></td></tr>
    <tr><th><?= e($L('contract.season')) ?></th><td><?= e($seasonLbl) ?></td>
        <th><?= e($L('contract.weeks_per_year')) ?></th><td><?= (int) $ct['weeks_per_year'] ?></td></tr>
    <tr><th><?= e($L('contract.duration_years')) ?></th><td><?= (int) $ct['duration_years'] ?></td>
        <th><?= e($L('doc.period')) ?></th><td><?= (int) $ct['start_year'] ?> – <?= (int) $ct['start_year'] + (int) $ct['duration_years'] - 1 ?></td></tr>
  </table>
  <p><?= e($L('doc.subject_text', ['weeks' => (string) $ct['weeks_per_year'], 'years' => (string) $ct['duration_years'], 'project' => $ct['project_name']])) ?></p>

  <h3><?= e($L('doc.price')) ?></h3>
  <table class="cd-table">
    <tr><th><?= e($L('contract.total')) ?></th><td colspan="3"><strong><?= e(money($ct['total_amount'], $cur)) ?></strong></td></tr>
    <tr><th><?= e($L('pay.down')) ?> (<?= e($pct) ?>%)</th><td><?= e(money($ct['down_amount'], $cur)) ?></td>
        <th><?= e($L('doc.due_on')) ?></th><td><?= e(fmt_date($ct['contract_date'])) ?></td></tr>
    <tr><th><?= e($L('res.remaining')) ?></th><td><?= e(money((float) $ct['total_amount'] - (float) $ct['down_amount'], $cur)) ?></td>
        <th><?= e($L('contract.months')) ?></th><td><?= e($L('contract.months_n', ['n' => (string) $ct['months']])) ?></td></tr>
    <tr><th><?= e($L('contract.monthly')) ?></th><td><?= e(money($ct['monthly_amount'], $cur)) ?></td>
        <th><?= e($L('contract.first_due')) ?></th><td><?= e(fmt_date($ct['first_due_date'])) ?></td></tr>
  </table>
  <p><?= e($L('doc.price_text', ['total' => money($ct['total_amount'], $cur), 'pct' => $pct, 'down' => money($ct['down_amount'], $cur), 'months' => (string) $ct['months'], 'monthly' => money($ct['monthly_amount'], $cur), 'first' => fmt_date($ct['first_due_date'])])) ?></p>

  <h3><?= e($L('doc.schedule')) ?></h3>
  <table class="cd-table cd-schedule">
    <thead><tr><th>#</th><th><?= e($L('pay.item')) ?></th><th><?= e($L('pay.due_date')) ?></th><th><?= e($L('pay.amount')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($inst as $i): ?>
      <tr><td><?= (int) $i['seq'] ?></td><td><?= e(seq_label((int) $i['seq'])) ?></td><td><?= e(fmt_date($i['due_date'])) ?></td><td><?= e(money($i['amount'], $cur)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h3><?= e($L('doc.terms')) ?></h3>
  <ol class="cd-terms">
    <?php
    $lines = $terms !== '' ? preg_split('/\R+/u', $terms) : array_map(fn($n) => $L('doc.term' . $n), range(1, 7));
    foreach ($lines as $line): if (trim($line) === '') continue; ?>
      <li><?= e(trim($line)) ?></li>
    <?php endforeach; ?>
  </ol>
  <?php if ($ct['notes']): ?><p><strong><?= e($L('contract.notes')) ?>:</strong> <?= nl2br(e($ct['notes'])) ?></p><?php endif; ?>

  <div class="cd-sign">
    <div><div class="cd-sign-lbl"><?= e($L('doc.first_party')) ?></div><div><?= e(setting('company_name')) ?></div>
      <div class="cd-line"><?= e($L('doc.signature')) ?></div></div>
    <div><div class="cd-sign-lbl"><?= e($L('doc.second_party')) ?></div><div><?= e($ct['official_name']) ?></div>
      <div class="cd-line"><?= e($L('doc.signature')) ?></div></div>
  </div>
  <div class="cd-foot"><?= e($L('doc.rep')) ?>: <?= e($ct['sales_name'] ?? '—') ?></div>
</div>
<?php
$doc = (string) ob_get_clean();
$docCss = (string) @file_get_contents(dirname(__DIR__) . '/assets/contract.css');

if ($uiLocale === null) unset($_SESSION['locale']); else $_SESSION['locale'] = $uiLocale;

if (($_GET['download'] ?? '') === 'doc') {
    $file = 'contract-' . preg_replace('/[^A-Za-z0-9-]/', '', $ct['contract_no']) . '.doc';
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    echo "\xEF\xBB\xBF<html xmlns:o=\"urn:schemas-microsoft-com:office:office\" xmlns:w=\"urn:schemas-microsoft-com:office:word\">"
       . '<head><meta charset="UTF-8"><title>' . e($ct['contract_no']) . '</title><style>' . $docCss . '</style></head><body>'
       . $doc . '</body></html>';
    exit;
}

if (($_GET['print'] ?? '') === '1') {
    ?><!DOCTYPE html><html lang="<?= e($lang) ?>" dir="<?= $lang === 'ar' ? 'rtl' : 'ltr' ?>"><head><meta charset="UTF-8">
<title><?= e($ct['contract_no']) ?></title><?= brand_head('../') ?><style><?= $docCss ?></style></head>
<body class="print-body" onload="window.print()"><?= $doc ?></body></html><?php
    exit;
}

$q = fn(array $extra) => '?' . http_build_query(array_merge(['id' => $id, 'lang' => $lang], $extra));
$tpls = db_all("SELECT id, name FROM contract_templates WHERE active = 1 AND (project_id IS NULL OR project_id = ?) ORDER BY name", [$ct['project_id']]);
$actions = '';
foreach ($tpls as $tp) {
    $actions .= '<a class="btn btn-dark" href="contract_docx.php?id=' . $id . '&amp;tpl=' . (int) $tp['id'] . '">⬇ ' . e($tp['name']) . '</a>';
}
$actions .= '<a class="btn btn-primary" href="' . e($q(['print' => '1'])) . '" target="_blank">' . e(t('doc.print')) . '</a>'
         . '<a class="btn" href="' . e($q(['download' => 'doc'])) . '">' . e(t('doc.download')) . '</a>'
         . '<a class="btn btn-ghost" href="' . e($q(['lang' => $lang === 'ar' ? 'en' : 'ar'])) . '">' . e($lang === 'ar' ? 'English' : 'العربية') . '</a>'
         . '<a class="btn btn-ghost" href="client.php?id=' . (int) $c['id'] . '">' . e(t('ui.back')) . '</a>';

layout_header(t('contract.view') . ' ' . $ct['contract_no'], 'clients');
page_head(t('contract.view'), $ct['contract_no'] . ' · ' . $c['full_name'], $actions);
?>
<p class="hint-line"><?= e(t('doc.pdf_hint')) ?></p>
<style><?= $docCss ?></style>
<div class="doc-frame"><?= $doc ?></div>
<?php layout_footer();
