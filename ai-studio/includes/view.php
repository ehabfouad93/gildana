<?php
declare(strict_types=1);

/** Shared page chrome: topbar + sidebar + flash toasts. */

function nav_items(): array
{
    $items = [
        'dashboard' => ['label' => 'Dashboard',     'url' => 'index.php',    'icon' => 'grid'],
        'studio'    => ['label' => 'New Campaign',   'url' => 'studio.php',   'icon' => 'spark'],
        'library'   => ['label' => 'Asset Library',  'url' => 'library.php',  'icon' => 'image'],
    ];
    if (is_admin()) {
        $items['keys'] = ['label' => 'Provider Keys', 'url' => 'keys.php', 'icon' => 'key'];
        $items['team'] = ['label' => 'Team',          'url' => 'team.php', 'icon' => 'team'];
    }
    return $items;
}

function nav_icon(string $key): string
{
    $s = 'width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';
    $icons = [
        'grid'  => '<path d="M2 2h5v5H2zM9 2h5v5H9zM2 9h5v5H2zM9 9h5v5H9z"/>',
        'spark' => '<path d="M8 1.5l1.6 4.4L14 7.5l-4.4 1.6L8 13.5 6.4 9.1 2 7.5l4.4-1.6z"/>',
        'image' => '<rect x="2" y="3" width="12" height="10" rx="1.5"/><circle cx="6" cy="6.5" r="1.2"/><path d="M3 12l3.5-3.5 2.5 2.5 2-2L14 12"/>',
        'key'   => '<circle cx="5" cy="8" r="3"/><path d="M8 8h6M12 8v2.5M14 8v2"/>',
        'team'  => '<circle cx="5.5" cy="6" r="2"/><circle cx="11" cy="6" r="2"/><path d="M1.5 13c0-2 1.8-3.2 4-3.2s4 1.2 4 3.2M10 10c2 0 4.5 1 4.5 3"/>',
        'pen'   => '<path d="M11 2.5l2.5 2.5L6 12.5 3 13l.5-3z"/>',
        'film'  => '<rect x="2" y="3" width="12" height="10" rx="1.5"/><path d="M2 6h12M2 10h12M5.5 3v10M10.5 3v10"/>',
        'send'  => '<path d="M14 2L7 9M14 2l-4.5 12-2.5-5-5-2.5L14 2z"/>',
    ];
    return "<svg $s>" . ($icons[$key] ?? $icons['grid']) . '</svg>';
}

function layout_header(string $title, string $active): void
{
    $appName = (string) config('app_name', 'AI Studio');
    $items   = nav_items();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> — <?= e($appName) ?></title>
<link rel="stylesheet" href="../assets/studio.css?v=<?= @filemtime(__DIR__ . '/../assets/studio.css') ?: '1' ?>">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">
    <span class="brand-mark"><?= e($appName) ?></span>
    <span class="topbar-sub">Creative Orchestrator</span>
  </div>
  <nav class="topbar-nav">
    <span class="topbar-email"><?= e(current_user()['email'] ?? '') ?></span>
    <a class="btn-top" href="../logout.php">Logout</a>
  </nav>
</header>
<div class="shell">
  <aside class="sidebar">
    <nav class="sb-nav">
      <?php foreach ($items as $key => $item): ?>
        <a class="sb-link <?= $key === $active ? 'active' : '' ?>" href="<?= e($item['url']) ?>">
          <?= nav_icon($item['icon']) ?> <span><?= e($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sb-foot">Signed in as<br><strong><?= e(current_user()['name'] ?: current_user()['email']) ?></strong></div>
  </aside>
  <main class="content">
    <?php foreach (take_flashes() as $f): ?>
      <div class="alert <?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
    <?php
}

function layout_footer(): void
{
    ?>
  </main>
</div>
<div class="toast" id="toast"></div>
<script src="../assets/studio.js?v=<?= @filemtime(__DIR__ . '/../assets/studio.js') ?: '1' ?>"></script>
</body>
</html>
    <?php
}

function page_head(string $title, string $sub = '', string $actionHtml = ''): void
{
    echo '<div class="page-head"><div><h1>' . e($title) . '</h1>'
       . ($sub !== '' ? '<p class="page-sub">' . e($sub) . '</p>' : '')
       . '</div>'
       . ($actionHtml !== '' ? '<div class="page-actions">' . $actionHtml . '</div>' : '')
       . '</div>';
}

/** Vendor readiness pill. */
function vendor_pill(string $vendor): string
{
    $ok = studio_vendor_ready($vendor);
    return '<span class="pill ' . ($ok ? 'green' : 'gray') . '">' . ($ok ? 'Connected' : 'Not set') . '</span>';
}
