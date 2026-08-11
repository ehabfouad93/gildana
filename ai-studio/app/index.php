<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$campaigns = db_all(
    "SELECT c.*,
        (SELECT COUNT(*) FROM assets a WHERE a.campaign_id = c.id AND a.status='ready') AS ready_assets
     FROM campaigns c WHERE c.user_id = ? ORDER BY c.id DESC LIMIT 12",
    [$USER['id']]
);

// Vendor readiness for the "connect providers" nudge.
$vendors = ['openai' => 'ChatGPT', 'anthropic' => 'Claude', 'stability' => 'Stable Diffusion', 'kling' => 'Kling', 'meta' => 'Meta'];
$ready = array_filter(array_keys($vendors), 'studio_vendor_ready');

layout_header('Dashboard', 'dashboard');
page_head('Dashboard', 'Build a campaign, pick the best AI for each step, generate and publish.',
    '<a class="btn primary" href="studio.php">+ New Campaign</a>');
?>

<?php if (count($ready) === 0): ?>
  <div class="alert warn">No AI providers connected yet.
    <?php if (is_admin()): ?><a href="keys.php">Add your API keys →</a><?php else: ?>Ask an admin to add API keys.<?php endif; ?>
  </div>
<?php endif; ?>

<div class="stat-row">
  <div class="stat"><div class="stat-n"><?= count($campaigns) ?></div><div class="stat-l">Recent campaigns</div></div>
  <div class="stat"><div class="stat-n"><?= count($ready) ?>/<?= count($vendors) ?></div><div class="stat-l">Providers connected</div></div>
  <div class="stat">
    <div class="stat-l" style="margin-bottom:6px">Providers</div>
    <div class="chips">
      <?php foreach ($vendors as $v => $name): ?>
        <span class="chip <?= studio_vendor_ready($v) ? 'on' : '' ?>"><?= e($name) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<h2 class="sec-title">Recent campaigns</h2>
<?php if (!$campaigns): ?>
  <div class="empty">
    <p>No campaigns yet.</p>
    <a class="btn primary" href="studio.php">Create your first campaign</a>
  </div>
<?php else: ?>
  <div class="card-grid">
    <?php foreach ($campaigns as $c): ?>
      <a class="camp-card" href="campaign.php?id=<?= (int) $c['id'] ?>">
        <div class="camp-top">
          <strong><?= e($c['name']) ?></strong>
          <span class="pill <?= $c['status'] === 'ready' ? 'green' : ($c['status'] === 'generating' ? 'gold' : 'gray') ?>"><?= e(ucfirst($c['status'])) ?></span>
        </div>
        <p class="camp-desc"><?= e($c['product'] ?: $c['goal'] ?: '—') ?></p>
        <div class="camp-foot">
          <span><?= (int) $c['ready_assets'] ?> asset<?= (int) $c['ready_assets'] === 1 ? '' : 's' ?></span>
          <span><?= e(date('M j', strtotime((string) $c['created_at']))) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php layout_footer();
