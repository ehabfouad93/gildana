<?php
declare(strict_types=1);

/**
 * Shared page chrome: topbar + role-aware sidebar + flash toasts.
 * Call layout_header(...), echo the page body, then layout_footer().
 */

function nav_items(string $role): array
{
    if ($role === 'admin') {
        return [
            'overview' => ['label' => t('nav.overview'), 'url' => 'index.php',    'icon' => 'grid'],
            'clients'  => ['label' => t('nav.clients'),  'url' => 'clients.php',  'icon' => 'users'],
            'mentions' => ['label' => t('nav.mentions'), 'url' => 'mentions.php', 'icon' => 'chat'],
            'health'   => ['label' => t('nav.health'),   'url' => 'health.php',   'icon' => 'pulse'],
            'team'     => ['label' => t('nav.team'),     'url' => 'team.php',     'icon' => 'team'],
            'settings' => ['label' => t('nav.settings'), 'url' => 'settings.php', 'icon' => 'gear'],
        ];
    }
    return [
        'dashboard' => ['label' => t('nav.dashboard'), 'url' => 'index.php',    'icon' => 'grid'],
        'mentions'  => ['label' => t('nav.mentions'),  'url' => 'mentions.php', 'icon' => 'chat'],
        'keywords'  => ['label' => t('nav.keywords'),  'url' => 'keywords.php', 'icon' => 'target'],
        'sources'   => ['label' => t('nav.sources'),   'url' => 'sources.php',  'icon' => 'list'],
        'alerts'    => ['label' => t('nav.alerts'),    'url' => 'alerts.php',   'icon' => 'bell'],
        'reports'   => ['label' => t('nav.reports'),   'url' => 'reports.php',  'icon' => 'chart'],
        'settings'  => ['label' => t('nav.settings'),  'url' => 'settings.php', 'icon' => 'gear'],
    ];
}

function nav_icon(string $key): string
{
    $s = 'width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';
    $icons = [
        'grid'   => '<path d="M2 2h5v5H2zM9 2h5v5H9zM2 9h5v5H2zM9 9h5v5H9z"/>',
        'users'  => '<circle cx="6" cy="5" r="2.5"/><path d="M1.5 13.5c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4"/><circle cx="12" cy="5.5" r="1.8"/><path d="M14.5 12.5c0-1.8-1.2-3-2.7-3.3"/>',
        'chat'   => '<path d="M2.5 3.5h11a1 1 0 011 1v6a1 1 0 01-1 1H6l-3 2.5V11.5H2.5a1 1 0 01-1-1v-6a1 1 0 011-1z"/><path d="M5 6.5h6M5 9h4"/>',
        'target' => '<circle cx="8" cy="8" r="6"/><circle cx="8" cy="8" r="3"/><circle cx="8" cy="8" r=".6" fill="currentColor"/>',
        'list'   => '<path d="M5 4h9M5 8h9M5 12h9M2 4h.01M2 8h.01M2 12h.01"/>',
        'bell'   => '<path d="M8 2a4 4 0 014 4v3l1.2 2H2.8L4 9V6a4 4 0 014-4z"/><path d="M6.5 13a1.5 1.5 0 003 0"/>',
        'chart'  => '<path d="M2 14h12"/><rect x="3" y="8" width="2.5" height="6"/><rect x="7" y="5" width="2.5" height="9"/><rect x="11" y="3" width="2.5" height="11"/>',
        'gear'   => '<circle cx="8" cy="8" r="2.2"/><path d="M8 1.5v2M8 12.5v2M1.5 8h2M12.5 8h2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M12.6 3.4l-1.4 1.4M4.8 11.2l-1.4 1.4"/>',
        'team'   => '<circle cx="5.5" cy="6" r="2"/><circle cx="11" cy="6" r="2"/><path d="M1.5 13c0-2 1.8-3.2 4-3.2s4 1.2 4 3.2M10 10c2 0 4.5 1 4.5 3"/>',
        'pulse'  => '<path d="M1.5 8h3l2-5 3 10 2-5h3"/>',
    ];
    return "<svg $s>" . ($icons[$key] ?? $icons['grid']) . '</svg>';
}

/** Coloured pill for a sentiment value. */
function sentiment_pill(string $sentiment, string $method = '', float $confidence = 0.0): string
{
    $map = [
        'positive' => ['green', t('sent.positive')],
        'negative' => ['red',   t('sent.negative')],
        'neutral'  => ['gray',  t('sent.neutral')],
        'unknown'  => ['gray',  t('sent.unknown')],
    ];
    [$cls, $label] = $map[$sentiment] ?? $map['unknown'];

    $sup = '';
    if ($method !== '' && $method !== 'none') {
        $sup = '<span class="pill-sup">' . e(t('sent.method.' . $method)) . '</span>';
    }
    $title = $confidence > 0 ? ' title="' . e(t('sent.confidence', ['n' => (string) (int) round($confidence * 100)])) . '"' : '';
    return '<span class="pill ' . $cls . '"' . $title . '>' . e($label) . $sup . '</span>';
}

/** Generic status pill for sources and rules. */
function status_pill(string $status): string
{
    $map = [
        'active'    => ['green', t('ui.active')],
        'ok'        => ['green', 'OK'],
        'ready'     => ['green', t('src.ready')],
        'paused'    => ['gray',  t('ui.paused')],
        'never'     => ['gray',  t('ui.never')],
        'empty'     => ['gray',  '—'],
        'error'     => ['red',   t('ui.error')],
        'throttled' => ['gold',  'Throttled'],
    ];
    [$cls, $label] = $map[$status] ?? ['gray', $status];
    return '<span class="pill ' . $cls . ' dot">' . e($label) . '</span>';
}

/** The EN/AR switch shown in the topbar. */
function locale_toggle(): string
{
    $cur    = locale();
    $return = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $out    = '<span class="lang-switch">';
    foreach (locales() as $code => $meta) {
        $active = $code === $cur ? ' class="on"' : '';
        $out .= '<a href="../set_lang.php?to=' . e($code)
              . '&amp;return=' . e(urlencode($return)) . '"' . $active . '>'
              . e($code === 'ar' ? 'ع' : 'EN') . '</a>';
    }
    return $out . '</span>';
}

function layout_header(string $title, string $role, string $active, array $opts = []): void
{
    $appName = t('app.name');
    $items   = nav_items($role);
    $sub     = $role === 'admin' ? 'ADMIN' : 'CLIENT';
    ?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" dir="<?= e(locale_dir()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> — <?= e($appName) ?></title>
<link rel="stylesheet" href="../assets/listen.css?v=<?= @filemtime(__DIR__ . '/../assets/listen.css') ?: '1' ?>">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">
    <span class="brand-mark">GILDANA</span>
    <span class="topbar-sub"><?= e(t('app.tagline')) ?> · <?= e($sub) ?></span>
  </div>
  <nav class="topbar-nav">
    <?= $opts['extra_html'] ?? '' ?>
    <?= locale_toggle() ?>
    <span class="topbar-email"><?= e(current_user()['email'] ?? '') ?></span>
    <a class="btn-top" href="../logout.php"><?= e(t('auth.logout')) ?></a>
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
    <div class="sb-foot"><?= e(t('auth.signed_in_as')) ?><br>
      <strong><?= e(current_user()['name'] ?: (current_user()['email'] ?? '')) ?></strong></div>
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
<script src="../assets/listen.js?v=<?= @filemtime(__DIR__ . '/../assets/listen.js') ?: '1' ?>"></script>
</body>
</html>
    <?php
}

/**
 * The 7/30/90-day range picker used by the dashboard and the report.
 *
 * Note the (string) casts on the keys: PHP turns numeric-string array keys into
 * integers, so comparing $range['days'] against a raw key silently never matches
 * and the current selection is never marked.
 */
function range_selector(int $days, string $name = 'range'): string
{
    $options = ['7' => t('ui.last_7'), '30' => t('ui.last_30'), '90' => t('ui.last_90')];
    $out = '<form method="get" class="filter-row"><select name="' . e($name) . '" data-autosubmit>';
    foreach ($options as $value => $label) {
        $value = (string) $value;
        $sel   = (string) $days === $value ? ' selected' : '';
        $out  .= '<option value="' . e($value) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $out . '</select></form>';
}

function page_head(string $title, string $sub = '', string $actionHtml = ''): void
{
    echo '<div class="page-head"><div><h1>' . e($title) . '</h1>'
       . ($sub !== '' ? '<p class="page-sub">' . e($sub) . '</p>' : '')
       . '</div>'
       . ($actionHtml !== '' ? '<div class="page-actions">' . $actionHtml . '</div>' : '')
       . '</div>';
}
