<?php
declare(strict_types=1);

/**
 * Page chrome: topbar, role-aware sidebar, flashes.
 * Call layout_header(...), echo the page body, then layout_footer().
 */

/**
 * The menu, grouped by department as in the system proposal. Each entry shows
 * only to people whose permissions let them use it; empty groups disappear.
 * Returns [group label|null => [key => item]].
 */
function nav_groups(string $role): array
{
    $admin = $role === 'admin';
    $C = fn(string $view) => 'clients.php?view=' . $view;
    $groups = [
        '' => [
            'dashboard' => [t('nav.dashboard'), 'dashboard.php', 'grid', true],
            'search'    => [t('nav.search'),    'search.php',    'search', true],
        ],
        'nav.grp.marketing' => [
            'clients:invitation' => [t('nav.invitation'),     $C('invitation'), 'plus',  can('clients.add')],
            'clients:booking'    => [t('nav.booking'),        $C('booking'),    'cal',   can_any('clients.book', 'clients.confirm')],
            'clients:attendance' => [t('nav.attendance_mkt'), $C('attendance'), 'door',  can('clients.arrive')],
        ],
        'nav.grp.sales' => [
            'clients:sales_attendance' => [t('nav.attendance_sales'), $C('sales_attendance'), 'users', can('clients.assign_sales')],
            'clients:deals'            => [t('nav.deals'),            $C('deals'),            'doc',   can_any('clients.close', 'deals.all')],
        ],
        'nav.grp.owners' => [
            'welcome'     => [t('nav.welcome'),     'welcome.php',     'phone', can('owners.welcome')],
            'calendar'    => [t('nav.calendar'),    'calendar.php',    'cal',   can('stays.manage')],
            'history'     => [t('nav.history'),     'history.php',     'clock', can_any('owners.welcome', 'stays.manage')],
            'collection'  => [t('nav.collection'),  'instalments.php', 'cash',  can('payments.record')],
            'clients:all' => [t('nav.all_clients'), $C('all'),         'users', can('clients.view_all')],
        ],
        'nav.grp.finance' => [
            'review'       => [t('nav.review'),     'review.php',       'check', can('contracts.review')],
            'reservations' => [t('nav.statements'), 'reservations.php', 'doc',   can('contracts.view')],
            'collection_f' => [t('nav.collection'), 'instalments.php',  'cash',  can('contracts.view') && !can('payments.record')],
        ],
        'nav.grp.admin' => [
            'projects'  => [t('nav.projects'),  'projects.php',           'pin',  can('projects.manage')],
            'whatsapp'  => [t('nav.whatsapp'),  'whatsapp.php',           'chat', $admin],
            'templates' => [t('nav.templates'), 'contract_templates.php', 'doc',  $admin],
            'users'     => [t('nav.users'),     'users.php',              'team', $admin],
            'roles'     => [t('nav.roles'),     'roles.php',              'key',  $admin],
            'settings'  => [t('nav.settings'),  'settings.php',           'gear', $admin],
        ],
    ];
    $out = [];
    foreach ($groups as $g => $items) {
        $items = array_filter($items, fn($i) => $i[3]);
        if ($items) $out[$g] = $items;
    }
    return $out;
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
        'door'  => '<path d="M3 14.5h10M4.5 14.5V2h7v12.5"/><circle cx="9.5" cy="8.5" r=".7" fill="currentColor"/>',
        'phone' => '<path d="M4 2h2.5l1 3-1.5 1a8 8 0 004 4l1-1.5 3 1V12a2 2 0 01-2 2A10 10 0 012 4a2 2 0 012-2z"/>',
        'clock' => '<circle cx="8" cy="8" r="6"/><path d="M8 4.5V8l2.5 1.5"/>',
        'check' => '<circle cx="8" cy="8" r="6"/><path d="M5.5 8.2l1.7 1.7 3.3-3.6"/>',
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

/** Favicon, theme colour and the brand serif. $base is the path to the app root. */
function brand_head(string $base = ''): string
{
    return '<link rel="icon" type="image/png" href="' . $base . 'assets/brand/favicon.png">'
         . '<link rel="apple-touch-icon" href="' . $base . 'assets/brand/apple-touch-icon.png">'
         . '<meta name="theme-color" content="#13343b">'
         . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
         . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&amp;display=swap">';
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
<?= brand_head('../') ?>
<link rel="stylesheet" href="<?= e(asset('jv.css')) ?>">
</head>
<body>
<header class="topbar">
  <a class="topbar-brand" href="dashboard.php">
    <img class="brand-logo" src="../assets/brand/logo-mark.png" alt="">
    <span class="brand-mark"><?= e(setting('company_name')) ?></span>
    <span class="topbar-sub"><?= e(t('role.' . $u['role'])) ?></span>
  </a>
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
      <?php foreach (nav_groups($u['role']) as $group => $items): ?>
        <?php if ($group !== ''): ?><div class="sb-group"><?= e(t($group)) ?></div><?php endif; ?>
        <?php foreach ($items as $key => [$label, $url, $icon]): ?>
          <a class="sb-link <?= $key === $active || ($active === 'collection' && $key === 'collection_f') ? 'active' : '' ?>" href="<?= e($url) ?>">
            <?= nav_icon($icon) ?> <span><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
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

/**
 * "Holiday reservations" card for one contract: every stay with its own
 * View / Print / Word buttons. Shared by the client profile and client history.
 */
function stays_card(int $contractId): string
{
    $stays = db_all("SELECT s.*, p.name AS project_name FROM stays s JOIN projects p ON p.id = s.project_id
                      WHERE s.contract_id = ? ORDER BY s.check_in DESC", [$contractId]);
    $head = '<div class="card-head"><h2>' . e(t('voucher.list')) . ' <span class="tab-count">' . count($stays) . '</span></h2>'
          . (can('stays.manage') ? '<a class="btn btn-sm btn-primary" href="stays.php?contract=' . $contractId . '">' . e(t('stay.new')) . '</a>' : '')
          . '</div>';
    if (!$stays) return '<div class="card card-flush" id="holidays">' . $head . '<div class="empty">' . e(t('stay.empty')) . '</div></div>';

    $rows = '';
    foreach ($stays as $s) {
        $status = $s['status'] === 'confirmed'
            ? '<span class="pill green">' . e(t('stay.confirmed')) . '</span>'
            : '<span class="pill gray">' . e(t('stay.cancelled_lbl')) . '</span>';
        $rows .= '<tr class="' . ($s['status'] === 'cancelled' ? 'row-muted' : '') . '">'
               . '<td class="mono nowrap">' . e(stay_code((int) $s['id'])) . '</td>'
               . '<td class="nowrap"><strong>' . e(fmt_date($s['check_in'])) . '</strong> → ' . e(fmt_date($s['check_out']))
               . '<div class="small text-muted">' . e(fmt_time($s['check_in_time'])) . ' · ' . e(t('stay.weeks_n', ['n' => (string) $s['weeks']])) . '</div></td>'
               . '<td>' . e($s['project_name']) . ($s['unit_type'] ? '<div class="small text-muted">' . e($s['unit_type']) . '</div>' : '') . '</td>'
               . '<td>' . $status . '</td>'
               . '<td class="nowrap">' . stay_doc_buttons((int) $s['id']) . '</td></tr>';
    }
    return '<div class="card card-flush" id="holidays">' . $head . '<div class="table-wrap"><table class="data"><tbody>' . $rows . '</tbody></table></div></div>';
}
