<?php
declare(strict_types=1);

/** Renders a single generated asset as a card (shared by campaign view + AJAX). */
function asset_card(array $a): string
{
    $id     = (int) $a['id'];
    $kind   = (string) $a['kind'];
    $status = (string) $a['status'];
    $mod    = studio_module((string) $a['module_id']);
    $modName = $mod['name'] ?? (string) $a['vendor'];
    $title  = (string) ($a['title'] ?? '');

    ob_start();
    ?>
    <div class="asset" data-asset-id="<?= $id ?>" data-status="<?= e($status) ?>" data-kind="<?= e($kind) ?>" data-mime="<?= e((string) ($a['mime'] ?? '')) ?>" data-url="<?= e((string) ($a['url'] ?? '')) ?>">
      <div class="asset-head">
        <span class="asset-mod"><?= e($modName) ?></span>
        <span class="asset-kind <?= e($kind) ?>"><?= e(ucfirst($kind)) ?></span>
      </div>
      <div class="asset-body">
      <?php if ($status === 'failed'): ?>
        <div class="asset-fail">⚠ <?= e($a['error'] ?: 'Generation failed') ?></div>
      <?php elseif ($status === 'pending'): ?>
        <div class="asset-pending"><span class="spinner"></span> Rendering… this can take a minute.</div>
      <?php elseif ($kind === 'text'): ?>
        <pre class="asset-text"><?= e((string) ($a['text_content'] ?? '')) ?></pre>
      <?php elseif ($kind === 'image'): ?>
        <img class="asset-img" src="<?= e((string) $a['url']) ?>" alt="" loading="lazy">
      <?php elseif ($kind === 'design'): ?>
        <iframe class="asset-frame" src="<?= e((string) $a['url']) ?>" title="Design" loading="lazy"></iframe>
        <div class="asset-note">Editable HTML/SVG poster — open to tweak text & colors.</div>
      <?php elseif ($kind === 'video'): ?>
        <video class="asset-vid" src="<?= e((string) $a['url']) ?>" controls preload="metadata"></video>
      <?php endif; ?>
      </div>
      <?php if ($status === 'ready'): ?>
      <div class="asset-actions">
        <?php if ($kind === 'text'): ?>
          <button class="btn tiny" onclick="copyText(this)">Copy</button>
        <?php endif; ?>
        <a class="btn tiny" href="download.php?asset=<?= $id ?>">Download</a>
        <?php if (in_array($kind, ['image', 'video'], true) && studio_vendor_ready('meta')): ?>
          <?php $c = studio_credentials('meta'); ?>
          <?php if (!empty($c['facebook_page_id'])): ?>
            <button class="btn tiny gold" onclick="publishAsset(<?= $id ?>,'meta_facebook',this)">Post → Facebook</button>
          <?php endif; ?>
          <?php if (!empty($c['instagram_user_id'])): ?>
            <button class="btn tiny gold" onclick="publishAsset(<?= $id ?>,'meta_instagram',this)">Post → Instagram</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
