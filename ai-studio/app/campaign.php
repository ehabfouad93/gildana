<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/** Load a campaign the current user may view (owner or admin). */
function load_campaign(int $id, array $user): array
{
    $c = db_row("SELECT * FROM campaigns WHERE id = ?", [$id]);
    if (!$c) { http_response_code(404); exit('Campaign not found.'); }
    if ((int) $c['user_id'] !== $user['id'] && ($user['role'] ?? '') !== 'admin') redirect('index.php');
    return $c;
}

$cid = (int) ($_GET['id'] ?? 0);
$camp = load_campaign($cid, $USER);
$picks = json_decode((string) ($camp['picks_json'] ?? '[]'), true) ?: [];
$stages = studio_stages();

// Group existing assets by stage.
$assetsByStage = [];
foreach (db_all("SELECT * FROM assets WHERE campaign_id = ? ORDER BY id ASC", [$cid]) as $a) {
    $assetsByStage[$a['stage']][] = $a;
}
$hasPending = (bool) db_val("SELECT COUNT(*) FROM assets WHERE campaign_id=? AND status='pending'", [$cid]);

layout_header($camp['name'], 'studio');
page_head(e($camp['name']), $camp['product'] ?: $camp['goal'],
    '<a class="btn ghost" href="studio.php">+ New</a> <a class="btn ghost" href="library.php">Library</a>');
?>
<div class="brief-bar">
  <?php foreach (['product' => 'Product', 'audience' => 'Audience', 'goal' => 'Goal', 'tone' => 'Tone', 'cta' => 'CTA', 'language' => 'Language'] as $k => $lbl):
      if (trim((string) $camp[$k]) === '') continue; ?>
    <div class="brief-item"><span><?= e($lbl) ?></span><?= e((string) $camp[$k]) ?></div>
  <?php endforeach; ?>
</div>

<?php if (!$picks): ?>
  <div class="empty"><p>No creative steps were chosen for this campaign.</p><a class="btn primary" href="studio.php">Start a new campaign</a></div>
<?php else: ?>
<div id="campaign" data-id="<?= $cid ?>" data-pending="<?= $hasPending ? 1 : 0 ?>">
<?php foreach ($picks as $stage => $pick):
    $meta = $stages[$stage] ?? null; if (!$meta) continue;
    $m = studio_module((string) $pick['module_id']); if (!$m) continue;
    $ready = studio_vendor_ready($m['vendor']);
    $existing = $assetsByStage[$stage] ?? []; ?>
  <section class="stage-block" data-stage="<?= e($stage) ?>">
    <div class="stage-head">
      <div>
        <h2><?= nav_icon($meta['icon']) ?> <?= e($meta['label']) ?></h2>
        <p class="stage-why"><strong><?= e($m['name']) ?></strong> — <?= e($m['why']) ?></p>
      </div>
      <button class="btn primary gen-btn" data-stage="<?= e($stage) ?>" <?= $ready ? '' : 'disabled' ?>>
        <?= $existing ? 'Generate again' : 'Generate' ?>
      </button>
    </div>
    <?php if (!$ready): ?><div class="alert warn"><?= e(ucfirst($m['vendor'])) ?> is not connected. <?= is_admin() ? '<a href="keys.php">Add key</a>' : 'Ask an admin.' ?></div><?php endif; ?>
    <div class="asset-grid" data-stage-assets="<?= e($stage) ?>">
      <?php foreach ($existing as $a) echo asset_card($a); ?>
    </div>
  </section>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php layout_footer();
