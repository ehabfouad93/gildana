<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$stages = studio_stages();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') { flash('Give your campaign a name.', 'error'); }
    else {
        $picks = [];
        foreach ($stages as $stage => $meta) {
            if ($meta['modality'] === 'publish') continue;
            $mid = (string) ($_POST['pick'][$stage]['module'] ?? '');
            if ($mid === '' || $mid === 'none') continue;
            if (!studio_module($mid)) continue;
            $picks[$stage] = [
                'module_id' => $mid,
                'model'     => (string) ($_POST['pick'][$stage]['model'][$mid] ?? ''),
                'note'      => trim((string) ($_POST['pick'][$stage]['note'] ?? '')),
            ];
        }
        $cid = db_insert(
            "INSERT INTO campaigns (user_id,name,product,audience,goal,key_message,tone,cta,language,picks_json,status,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'draft', NOW(), NOW())",
            [
                $USER['id'], $name,
                trim((string) ($_POST['product'] ?? '')),
                trim((string) ($_POST['audience'] ?? '')),
                trim((string) ($_POST['goal'] ?? '')),
                trim((string) ($_POST['key_message'] ?? '')),
                trim((string) ($_POST['tone'] ?? '')),
                trim((string) ($_POST['cta'] ?? '')),
                trim((string) ($_POST['language'] ?? 'English')) ?: 'English',
                json_encode($picks, JSON_UNESCAPED_UNICODE),
            ]
        );
        flash('Campaign created. Generate your assets below.');
        redirect('campaign.php?id=' . $cid);
    }
}

layout_header('New Campaign', 'studio');
page_head('New Campaign', 'Describe the campaign, then choose the best AI for each creative step.');
?>
<form method="post" class="wizard">
  <?= csrf_field() ?>

  <section class="panel">
    <h2 class="sec-title">1 · Campaign brief</h2>
    <div class="grid2">
      <label class="fld"><span>Campaign name *</span><input name="name" required value="<?= old('name') ?>" placeholder="Summer Sale 2026"></label>
      <label class="fld"><span>Language</span><input name="language" value="<?= old('language', 'English') ?>" placeholder="English / Arabic"></label>
      <label class="fld"><span>Product / service</span><input name="product" value="<?= old('product') ?>" placeholder="Handmade leather bags"></label>
      <label class="fld"><span>Target audience</span><input name="audience" value="<?= old('audience') ?>" placeholder="Women 25–40, fashion-conscious"></label>
      <label class="fld"><span>Campaign goal</span><input name="goal" value="<?= old('goal') ?>" placeholder="Drive sales for the summer collection"></label>
      <label class="fld"><span>Tone / style</span><input name="tone" value="<?= old('tone') ?>" placeholder="Warm, premium, playful"></label>
      <label class="fld"><span>Call to action</span><input name="cta" value="<?= old('cta') ?>" placeholder="Shop now — 20% off"></label>
      <label class="fld"><span>Key message</span><input name="key_message" value="<?= old('key_message') ?>" placeholder="Crafted to last a lifetime"></label>
    </div>
  </section>

  <?php $step = 2; foreach ($stages as $stage => $meta):
      if ($meta['modality'] === 'publish') continue;
      $mods = studio_modules_for_stage($stage); ?>
  <section class="panel">
    <h2 class="sec-title"><?= $step++ ?> · <?= e($meta['label']) ?>
      <span class="sec-note"><?= e($meta['desc']) ?></span></h2>

    <div class="pick-grid">
      <label class="pick skip">
        <input type="radio" name="pick[<?= e($stage) ?>][module]" value="none" checked>
        <div class="pick-inner"><strong>Skip this step</strong><p>Don't generate <?= e(strtolower($meta['label'])) ?>.</p></div>
      </label>

      <?php foreach ($mods as $id => $m): $ready = studio_vendor_ready($m['vendor']); ?>
      <label class="pick <?= $ready ? '' : 'disabled' ?>">
        <input type="radio" name="pick[<?= e($stage) ?>][module]" value="<?= e($id) ?>" <?= $ready ? '' : 'disabled' ?>>
        <div class="pick-inner">
          <div class="pick-top">
            <div><strong><?= e($m['name']) ?></strong><span class="pick-sub"><?= e($m['sub']) ?></span></div>
            <?php if ($m['badge']): ?><span class="badge"><?= e($m['badge']) ?></span><?php endif; ?>
          </div>
          <p class="pick-why"><?= e($m['why']) ?></p>
          <div class="chips">
            <?php foreach ($m['best_for'] as $t): ?><span class="chip sm"><?= e($t) ?></span><?php endforeach; ?>
          </div>
          <?php if (!empty($m['models'])): ?>
          <div class="pick-model">
            <span>Model</span>
            <select name="pick[<?= e($stage) ?>][model][<?= e($id) ?>]" onclick="event.preventDefault()">
              <?php foreach ($m['models'] as $mv => $ml): ?><option value="<?= e($mv) ?>"><?= e($ml) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <?php if (!$ready): ?><div class="pick-lock">🔒 Add the <?= e(ucfirst($m['vendor'])) ?> key to use this<?= is_admin() ? ' — <a href="keys.php">Provider Keys</a>' : '' ?></div><?php endif; ?>
        </div>
      </label>
      <?php endforeach; ?>
    </div>

    <label class="fld note-fld"><span>Optional direction for this step</span>
      <input name="pick[<?= e($stage) ?>][note]" placeholder="e.g. focus on the new blue model, minimalist background"></label>
  </section>
  <?php endforeach; ?>

  <div class="wizard-foot">
    <button class="btn primary lg" type="submit">Create campaign →</button>
    <span class="muted">You'll generate each asset on the next screen. Publishing happens per-asset once generated.</span>
  </div>
</form>
<?php layout_footer();
