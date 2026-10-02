<?php
declare(strict_types=1);

/**
 * Page chrome: topbar, role-aware sidebar, flashes.
 * Call layout_header(...), echo the page body, then layout_footer().
 */

function nav_items(string $role): array
{
    $admin = $role === 'admin';
    $all = [
        'dashboard'    => ['label' => t('nav.dashboard'),    'url' => 'dashboard.php',    'icon' => 'grid',   'show' => true],
        'search'       => ['label' => t('nav.search'),       'url' => 'search.php',       'icon' => 'search', 'show' => true],
        'client_new'   => ['label' => t('nav.client_new'),   'url' => 'client_new.php',   'icon' => 'plus',   'show' => can('clients.add')],
        'clients'      => ['label' => t('nav.clients.' . $role), 'url' => 'clients.php',  'icon' => 'users',
                           'show' => can_any('clients.add', 'clients.book', 'clients.confirm', 'clients.arrive', 'clients.close', 'clients.view_all')],
        'reservations' => ['label' => t('nav.reservations'), 'url' => 'reservations.php', 'icon' => 'doc',    'show' => can_any('contracts.view', 'payments.record')],
        'instalments'  => ['label' => t('nav.instalments'),  'url' => 'instalments.php',  'icon' => 'cash',   'show' => can_any('contracts.view', 'payments.record')],
        'stays'        => ['label' => t('nav.stays'),        'url' => 'stays.php',        'icon' => 'cal',    'show' => can('stays.manage')],
        'projects'     => ['label' => t('nav.projects'),     'url' => 'projects.php',     'icon' => 'pin',    'show' => can('projects.manage')],
        'whatsapp'     => ['label' => t('nav.whatsapp'),     'url' => 'whatsapp.php',     'icon' => 'chat',   'show' => $admin],
        'templates'    => ['label' => t('nav.templates'),    'url' => 'contract_templates.php', 'icon' => 'doc', 'show' => $admin],
        'users'        => ['label' => t('nav.users'),        'url' => 'users.php',        'icon' => 'team',   'show' => $admin],
        'roles'        => ['label' => t('nav.roles'),        'url' => 'roles.php',        'icon' => 'key',    'show' => $admin],
        'settings'     => ['label' => t('nav.settings'),     'url' => 'settings.php',     'icon' => 'gear',   'show' => $admin],
    ];
    return array_filter($all, fn($i) => $i['show']);
}

function nav_icon(string $key): string
{
    $s = 'width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';
    $icons = [
        'grid'  => '<path d="M2 2h5v5H2zM9 2h5v5H9zM2 9h5v5H2zM9 9h5v5H9z"/>',
        'plus'  => '<circle cx="8" cy="8" r="6"/><path d="M8 5v6M5 8h6"/>',
        'users' => '<circle cx="6" cy="5" r="2.5"/><path d="M1.5 13.5c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4"/><circle cx="12" cy="5.5" r="1.8"/><path d="M14.5 12.5c0-1.8-1.2-3-2.7-3.3"/>',
        'doc'   => '<path d="M4 1.5h5.5L12.5 4.5v10h-8.5z"/><path d="M9.5 1.5v3h3M6 8h4.5M6 10.5h4.5"/>',
        'cash'  => '<rect x="1.5" y="4" width="13" height="8" rx="1"/><circle cx="8" cy="8" r="1.8"/><path d="M4 6.5v3M12 6.5v3"/>',
        'cal'   => '<rect x="2" y="3" width="12" height="11" rx="1"/><path d="M2 6.5h12M5 1.5v3M11 1.5v3"/>',
        'pin'   => '<path d="M8 14.5s-4.5-4.2-4.5-7.5a4.5 4.5 0 019 0c0 3.3-4.5 7.5-4.5 7.5z"/><circle cx="8" cy="7" r="1.6"/>',
        'team'  => '<circle cx="5.5" cy="6" r="2"/><circle cx="11" cy="6" r="2"/><path d="M1.5 13c0-2 1.8-3.2 4-3.2s4 1.2 4 3.2M10 10c2 0 4.5 1 4.5 3"/>',
        'search'=> '<circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5l3.5 3.5"/>',
        'chat'  => '<path d="M2.5 3.5h11a1 1 0 011 1v6a1 1 0 01-1 1H6l-3 2.5V11.5H2.5a1 1 0 01-1-1v-6a1 1 0 011-1z"/>',
        'key'   => '<circle cx="5" cy="8" r="3"/><path d="M8 8h6.5M12 8v2.5M14 8v2"/>',
        'gear'  => '<circle cx="8" cy="8" r="2.2"/><path d="M8 1.5v2M8 12.5v2M1.5 8h2M12.5 8h2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M12.6 3.4l-1.4 1.4M4.8 11.2l-1.4 1.4"/>',
    ];
    return "<svg $s>" . ($icons[$key] ?? $icons['grid']) . '</svg>';
}

function locale_toggle(): string
{
    $cur    = locale();
    $return = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $out    = '<span class="lang-switch">';
    foreach (locales() as $code => $meta) {
        $active = $code === $cur ? ' class="on"' : '';
        $out .= '<a href="' . e(app_url('set_lang.php')) . '?to=' . e($code)
              . '&amp;return=' . e(urlencode($return)) . '"' . $active . '>'
              . e($code === 'ar' ? 'ع' : 'EN') . '</a>';
    }
    return $out . '</span>';
}

function asset(string $file): string
{
    return '../assets/' . $file . '?v=' . (@filemtime(dirname(__DIR__) . '/assets/' . $file) ?: '1');
}

function layout_header(string $title, string $active): void
{
    $u = current_user();
    ?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" dir="<?= e(locale_dir()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> — <?= e(setting('company_name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('jv.css')) ?>">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">
    <span class="brand-mark"><?= e(setting('company_name')) ?></span>
    <span class="topbar-sub"><?= e(t('role.' . $u['role'])) ?></span>
  </div>
  <form class="top-search" method="get" action="search.php" role="search">
    <input type="search" name="q" placeholder="<?= e(t('search.top')) ?>" aria-label="<?= e(t('nav.search')) ?>">
  </form>
  <nav class="topbar-nav">
    <?= locale_toggle() ?>
    <span class="topbar-email"><?= e($u['name']) ?></span>
    <a class="btn-top" href="../logout.php"><?= e(t('auth.logout')) ?></a>
  </nav>
</header>
<div class="shell">
  <aside class="sidebar">
    <nav class="sb-nav">
      <?php foreach (nav_items($u['role']) as $key => $item): ?>
        <a class="sb-link <?= $key === $active ? 'active' : '' ?>" href="<?= e($item['url']) ?>">
          <?= nav_icon($item['icon']) ?> <span><?= e($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sb-foot"><?= e(t('auth.signed_in_as')) ?><br>
      <strong><?= e($u['name']) ?></strong><br><?= e(t('role.' . $u['role'])) ?></div>
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
<script src="<?= e(asset('jv.js')) ?>"></script>
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

/** <option> list. $options is value => label. */
function options(array $options, $selected, bool $blank = true): string
{
    $out = $blank ? '<option value="">—</option>' : '';
    foreach ($options as $v => $label) {
        $sel = (string) $v === (string) $selected ? ' selected' : '';
        $out .= '<option value="' . e($v) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $out;
}

/** Tabs as links; $tabs is key => label (+ optional count). */
function tabs(array $tabs, string $active, string $param = 'tab', array $keep = []): string
{
    $out = '<div class="tabs">';
    foreach ($tabs as $key => $tab) {
        $q = http_build_query(array_merge($keep, [$param => $key]));
        $count = isset($tab['count']) ? ' <span class="tab-count">' . (int) $tab['count'] . '</span>' : '';
        $out .= '<a class="tab' . ($key === $active ? ' on' : '') . '" href="?' . e($q) . '">' . e($tab['label']) . $count . '</a>';
    }
    return $out . '</div>';
}

/** One label/value row in a definition grid. */
function dl_row(string $label, ?string $value, bool $raw = false): string
{
    $v = ($value === null || $value === '') ? '<span class="text-muted">—</span>' : ($raw ? $value : e($value));
    return '<div class="dl-row"><dt>' . e($label) . '</dt><dd>' . $v . '</dd></div>';
}
