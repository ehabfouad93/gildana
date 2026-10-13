<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/** Company details printed on contracts, payment-plan defaults, contract terms. */
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pct = (string) ($_POST['down_pct'] ?? '');
    $months = array_filter(array_map('trim', explode(',', (string) ($_POST['month_options'] ?? ''))), fn($m) => ctype_digit($m) && (int) $m > 0 && (int) $m <= 120);
    if (!is_numeric($pct) || (float) $pct < 0 || (float) $pct >= 100) {
        flash(t('contract.err.pct'), 'error');
    } elseif (!$months) {
        flash(t('settings.err_months'), 'error');
    } else {
        foreach (array_keys(setting_defaults()) as $k) {
            if (!array_key_exists($k, $_POST)) continue;
            $v = trim((string) $_POST[$k]);
            if ($k === 'month_options') $v = implode(',', $months);
            save_setting($k, $v);
        }
        flash(t('ui.saved'));
    }
    redirect('settings.php');
}

$f = fn(string $k) => e(setting($k));
layout_header(t('nav.settings'), 'settings');
page_head(t('nav.settings'), t('settings.sub'));
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="grid2">
    <div class="card">
      <h2><?= e(t('settings.company')) ?></h2>
      <div class="field"><span class="lbl"><?= e(t('settings.company_name')) ?></span><input type="text" name="company_name" value="<?= $f('company_name') ?>"></div>
      <div class="field"><span class="lbl"><?= e(t('settings.company_address')) ?></span><input type="text" name="company_address" value="<?= $f('company_address') ?>"></div>
      <div class="field"><span class="lbl"><?= e(t('settings.company_phone')) ?></span><input type="text" name="company_phone" value="<?= $f('company_phone') ?>" dir="ltr"></div>
      <div class="field"><span class="lbl"><?= e(t('doc.reg')) ?></span><input type="text" name="company_reg" value="<?= $f('company_reg') ?>"></div>
    </div>
    <div class="card">
      <h2><?= e(t('settings.plan')) ?></h2>
      <div class="grid2">
        <div class="field"><span class="lbl"><?= e(t('settings.currency')) ?></span><input type="text" name="currency" value="<?= $f('currency') ?>" maxlength="8"></div>
        <div class="field"><span class="lbl"><?= e(t('contract.down_pct')) ?></span><input type="number" step="0.01" name="down_pct" value="<?= $f('down_pct') ?>"></div>
        <div class="field"><span class="lbl"><?= e(t('settings.month_options')) ?></span><input type="text" name="month_options" value="<?= $f('month_options') ?>" dir="ltr">
          <span class="hint"><?= e(t('settings.month_hint')) ?></span></div>
        <div class="field"><span class="lbl"><?= e(t('settings.prefix')) ?></span><input type="text" name="contract_prefix" value="<?= $f('contract_prefix') ?>" dir="ltr"></div>
      </div>
      <div class="field"><span class="lbl"><?= e(t('settings.places')) ?></span><input type="text" name="meeting_places" value="<?= $f('meeting_places') ?>">
        <span class="hint"><?= e(t('settings.comma_hint')) ?></span></div>
      <div class="field"><span class="lbl"><?= e(t('settings.sources')) ?></span><input type="text" name="lead_sources" value="<?= $f('lead_sources') ?>">
        <span class="hint"><?= e(t('settings.comma_hint')) ?></span></div>
    </div>
  </div>
  <div class="card">
    <h2><?= e(t('doc.terms')) ?></h2>
    <p class="hint-line"><?= e(t('settings.terms_hint')) ?></p>
    <div class="grid2">
      <div class="field"><span class="lbl">English</span><textarea name="terms_en" rows="10" dir="ltr"><?= $f('terms_en') ?></textarea></div>
      <div class="field"><span class="lbl">العربية</span><textarea name="terms_ar" rows="10" dir="rtl"><?= $f('terms_ar') ?></textarea></div>
    </div>
  </div>
  <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
</form>
<?php layout_footer();
