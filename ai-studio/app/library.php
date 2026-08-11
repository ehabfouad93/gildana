<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$filter = (string) ($_GET['kind'] ?? '');
$where = "c.user_id = ?";
$params = [$USER['id']];
if (is_admin() && ($_GET['all'] ?? '') === '1') { $where = '1=1'; $params = []; }
if (in_array($filter, ['text', 'image', 'video', 'design'], true)) { $where .= " AND a.kind = ?"; $params[] = $filter; }

$assets = db_all(
    "SELECT a.* FROM assets a JOIN campaigns c ON c.id=a.campaign_id
     WHERE $where AND a.status='ready' ORDER BY a.id DESC LIMIT 200", $params);

layout_header('Asset Library', 'library');
page_head('Asset Library', 'Everything you have generated. Download or publish.');
?>
<div class="filter-row">
  <a class="chip <?= $filter === '' ? 'on' : '' ?>" href="library.php">All</a>
  <a class="chip <?= $filter === 'text' ? 'on' : '' ?>" href="library.php?kind=text">Copy</a>
  <a class="chip <?= $filter === 'image' ? 'on' : '' ?>" href="library.php?kind=image">Images</a>
  <a class="chip <?= $filter === 'design' ? 'on' : '' ?>" href="library.php?kind=design">Designs</a>
  <a class="chip <?= $filter === 'video' ? 'on' : '' ?>" href="library.php?kind=video">Video</a>
</div>
<?php if (!$assets): ?>
  <div class="empty"><p>Nothing generated yet.</p><a class="btn primary" href="studio.php">Create a campaign</a></div>
<?php else: ?>
  <div class="asset-grid">
    <?php foreach ($assets as $a) echo asset_card($a); ?>
  </div>
<?php endif; ?>
<?php layout_footer();
