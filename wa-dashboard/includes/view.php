<?php
declare(strict_types=1);

/**
 * Shared page chrome: topbar + role-aware sidebar + flash toasts.
 * Call layout_header(...) then echo page body, then layout_footer().
 */

/**
 * Height of the topbar logo, in px. Uploaded artwork varies wildly in proportion — a wordmark
 * that reads well at 26px can be unreadable when the next one is square — so this is tunable
 * from Admin → Branding rather than being a constant someone has to edit code to change.
 */
function brand_logo_height(): int
{
    static $h = null;
    if ($h === null) {
        // Read app_settings directly rather than through setting_get(): that lives in push.php,
        // which most pages never load, so depending on it silently pinned every logo to the
        // default no matter what was saved.
        try { $h = (int) db_val("SELECT v FROM app_settings WHERE k='logo_height'"); }
        catch (Throwable $e) { $h = 0; }
    }
    return $h > 0 ? max(20, min(120, $h)) : 40;
}

/** Best name to show for a signed-in user; falls back to the local part of their email. */
function user_display_name(array $user): string
{
    $n = trim((string) ($user['name'] ?? ''));
    if ($n !== '') return $n;
    $email = (string) ($user['email'] ?? '');
    $local = trim(explode('@', $email)[0]);
    return $local !== '' ? $local : 'Account';
}

/** Two initials for the avatar fallback — works for Arabic names as well as Latin ones. */
function user_initials(array $user): string
{
    $name = user_display_name($user);
    $parts = preg_split('/[\s._-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) >= 2) return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    return mb_strtoupper(mb_substr($name, 0, 2));
}

/** Avatar image when one is uploaded, otherwise initials on a tinted circle. */
function user_avatar_html(array $user, int $size = 30, string $base = './'): string
{
    $av = trim((string) ($user['avatar'] ?? ''));
    $px = max(16, min(200, $size));
    if ($av !== '' && is_file(dirname(__DIR__) . '/uploads/avatars/' . $av)) {
        return '<img class="avatar" src="' . e($base . 'uploads/avatars/' . $av) . '?v=' . (int) @filemtime(dirname(__DIR__) . '/uploads/avatars/' . $av) . '"'
             . ' width="' . $px . '" height="' . $px . '" alt="">';
    }
    return '<span class="avatar avatar-initials" style="width:' . $px . 'px;height:' . $px . 'px;font-size:' . max(9, (int) round($px * 0.38)) . 'px">'
         . e(user_initials($user)) . '</span>';
}

/**
 * Unread inbound messages for the bell. Scoped to the client for a client login; across every
 * client for an admin, whose Inbox spans all of them.
 */
function topbar_unread(array $user): int
{
    if (!$user) return 0;
    try {
        $sql = "SELECT COUNT(*) FROM messages m JOIN contacts c ON c.id = m.contact_id
                 WHERE m.direction='in' AND m.created_at > COALESCE(c.inbox_read_at,'2000-01-01')";
        if (($user['role'] ?? '') !== 'admin' && !empty($user['client_id'])) {
            return (int) db_val($sql . " AND c.client_id = ?", [(int) $user['client_id']]);
        }
        return (int) db_val($sql);
    } catch (Throwable $e) { return 0; }
}

function nav_items(string $role): array
{
    if ($role === 'admin') {
        require_once __DIR__ . '/access_request.php';
        $req = ['label' => 'Requests', 'url' => 'requests.php', 'icon' => 'inbox'];
        if (($n = access_request_new_count()) > 0) $req['badge'] = $n;   // waiting for a first reply
        return [
            'overview'  => ['label' => 'Overview',  'url' => 'index.php',     'icon' => 'grid'],
            'clients'   => ['label' => 'Clients',   'url' => 'clients.php',   'icon' => 'users'],
            'requests'  => $req,
            'inbox'     => ['label' => 'Inbox',      'url' => 'inbox.php',     'icon' => 'chat'],
            'campaigns' => ['label' => 'Campaigns', 'url' => 'campaigns.php', 'icon' => 'send'],
            'contacts'  => ['label' => 'Contacts',  'url' => 'contacts.php',  'icon' => 'book'],
            'templates' => ['label' => 'Templates', 'url' => 'templates.php', 'icon' => 'doc'],
            'reports'   => ['label' => 'Reports',   'url' => 'reports.php',   'icon' => 'chart'],
            'team'      => ['label' => 'Team',       'url' => 'team.php',      'icon' => 'team'],
            'plans'     => ['label' => 'Plans',      'url' => 'plans.php',     'icon' => 'chart'],
            'rates'     => ['label' => 'Rates',      'url' => 'rates.php',     'icon' => 'doc'],
            'help'      => ['label' => 'Help Content', 'url' => 'help_admin.php', 'icon' => 'book'],
            'seo'       => ['label' => 'SEO',        'url' => 'seo.php',       'icon' => 'globe'],
            'settings'  => ['label' => 'Settings',  'url' => 'settings.php',  'icon' => 'gear'],
        ];
    }
    $nav = [
        'dashboard' => ['label' => 'Dashboard', 'url' => 'index.php',     'icon' => 'grid'],
        'inbox'     => ['label' => 'Inbox',     'url' => 'inbox.php',     'icon' => 'chat'],
        'comments'  => ['label' => 'Comments',  'url' => 'comments.php',  'icon' => 'chat'],
        'crm'       => ['label' => 'CRM',       'url' => 'crm.php',       'icon' => 'pipe', 'children' => 'crm'],
        'contacts'  => ['label' => 'Contacts',  'url' => 'contacts.php',  'icon' => 'users'],
        'lists'     => ['label' => 'Lists',     'url' => 'lists.php',     'icon' => 'list'],
        'templates'   => ['label' => 'Templates',   'url' => 'templates.php',   'icon' => 'doc'],
        'campaigns'   => ['label' => 'Campaigns',   'url' => 'campaigns.php',   'icon' => 'send'],
        'automations' => ['label' => 'Automations',    'url' => 'automations.php', 'icon' => 'bot'],
        'qualifier'   => ['label' => 'Lead Qualifier', 'url' => 'qualifiers.php',  'icon' => 'target'],
        'agents'      => ['label' => 'AI Chat Agent',  'url' => 'agents.php',       'icon' => 'bot'],
        'reports'     => ['label' => 'Reports',        'url' => 'reports.php',      'icon' => 'chart'],
        'billing'     => ['label' => 'Billing',        'url' => 'billing.php',      'icon' => 'doc'],
        'team'        => ['label' => 'Team',           'url' => 'team.php',         'icon' => 'team'],
        'settings'  => ['label' => 'Settings',  'url' => 'settings.php',  'icon' => 'gear'],
    ];

    // Comments only for clients offered Facebook or Instagram comments.
    $cl = $GLOBALS['CLIENT'] ?? null;
    if (!is_array($cl) || !function_exists('client_has_channel') || (!client_has_channel($cl, 'fb_comments') && !client_has_channel($cl, 'ig_comments'))) {
        unset($nav['comments']);
    }

    // Only appears when something is actually waiting — a permanent zero is just noise.
    if (($n = nav_attention_count()) > 0) {
        $item = ['label' => 'Needs attention', 'url' => 'failed.php', 'icon' => 'alert', 'badge' => $n];
        $nav = array_slice($nav, 0, 7, true) + ['attention' => $item] + array_slice($nav, 7, null, true);
    }

    /* Show only what this user can open. The gate in client/_init.php already refuses the rest;
       hiding it here is what keeps the sidebar from being a list of doors that say no. Items
       whose nav key is not a module (the dashboard) always stay. */
    if (function_exists('can_use') && function_exists('perm_modules')) {
        $navToModule = ['attention' => 'campaigns', 'comments' => 'inbox'];
        foreach ($nav as $key => $_) {
            $mod = $navToModule[$key] ?? $key;
            if (isset(perm_modules()[$mod]) && !can_use($mod)) unset($nav[$key]);
        }
    }
    return $nav;
}

/**
 * The sub-menu under a sidebar item, for sections with several pages of their own.
 * Each entry: [label, url, the page that counts as "here"]. Filtered by what this user may do.
 */
function nav_children(string $group): array
{
    if ($group !== 'crm') return [];
    $write = !function_exists('can_write') || can_write();
    $admin = !function_exists('is_client_admin') || is_client_admin();
    // Grouped the way the day goes: the work itself, how it is going, and setting it up.
    // Each entry's sixth slot names its group; the sidebar prints a small heading where it changes.
    $items = [
        ['Pipeline',     'crm.php',                         'crm.php',            0, false, 'Leads & work'],
        ['Follow-ups',   'crm.php?view=table&due=today',    'crm.php?due',        0, false, 'Leads & work'],
        ['Site visits',  'crm_calendar.php',                'crm_calendar.php',   0, false, 'Leads & work'],
        ['Events',       'crm_events.php',                  'crm_events.php',     0, false, 'Leads & work'],
        ['Materials',    'crm_materials.php',               'crm_materials.php',  0, false, 'Leads & work'],
    ];
    if ($write) $items[] = ['Import leads', 'crm_import.php', 'crm_import.php', 0, false, 'Leads & work'];
    $items[] = ['Dashboard',    'crm_dashboard.php', 'crm_dashboard.php', 0, false, 'Results'];
    $items[] = ['Reports',      'crm_reports.php',   'crm_reports.php',   0, false, 'Results'];
    if ($admin) {
        foreach ([['Team & transfer',  'crm_team.php',  'crm_team.php'],
                  ['Assignment rules', 'crm_rules.php', 'crm_rules.php'],
                  ['Automatic messages', 'crm_messages.php', 'crm_messages.php'],
                  ['Facebook & Instagram', 'meta_leads.php', 'meta_leads.php'],
                  ['Projects & lists', 'crm_setup.php', 'crm_setup.php'],
                  ['Integrations',     'crm_integrations.php', 'crm_integrations.php'],
                  ['Stages',           'crm.php?stages=1', 'crm.php?stages'],
                  ['Requests & bin',   'crm_manage.php', 'crm_manage.php']] as $it)
            $items[] = [$it[0], $it[1], $it[2], 0, false, 'Administration'];
    }
    // Only the CRM pages this person was given (Team → under the CRM tick).
    if (function_exists('can_crm')) {
        $items = array_values(array_filter($items, function ($it) {
            $key = crm_page_key(explode('?', $it[1])[0]);
            return $key === null || can_crm($key);
        }));
    }
    // How many follow-ups are due today or late, for this person (or the team, for a manager).
    if (function_exists('crm_alert_counts') && isset($GLOBALS['CLIENT']['id'])) {
        try {
            $a = crm_alert_counts($GLOBALS['CLIENT'], $admin ? null : (function_exists('crm_actor_id') ? crm_actor_id() : null));
            foreach ($items as &$it) if ($it[0] === 'Follow-ups') { $it[3] = $a['due']; $it[4] = $a['overdue'] > 0; }   // shown as a badge
            unset($it);
            if ($admin) {
                $req = 0;
                try { $req = (int) db_val("SELECT COUNT(*) FROM crm_requests WHERE client_id=? AND status='pending'", [(int) $GLOBALS['CLIENT']['id']]); } catch (Throwable $e) {}
                foreach ($items as &$it) if ($it[0] === 'Requests & bin' && $req) { $it[3] = $req; $it[4] = true; }
            }
            unset($it);
        } catch (Throwable $e) {}
    }
    return $items;
}

/** Which sub-menu entry is the page being viewed. */
function nav_child_active(array $child): bool
{
    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    [$file, $q] = array_pad(explode('?', $child[2], 2), 2, '');
    if ($page !== $file) return $page === 'crm_lead.php' && $child[0] === 'Pipeline';
    $hasDue = ($_GET['due'] ?? '') !== ''; $hasStages = !empty($_GET['stages']);
    if ($q === 'due')    return $hasDue;
    if ($q === 'stages') return $hasStages;
    return !$hasDue && !$hasStages;
}

/**
 * How many messages are waiting on a human decision — gave up after repeated errors, or
 * were sent without WhatsApp confirming, so we stopped instead of risking a duplicate.
 * Only shown when there is something to show; a permanent zero in the nav is just noise.
 */
function nav_attention_count(): int
{
    static $n = null;
    if ($n !== null) return $n;
    $n = 0;
    $u = current_user();
    $cid = (int) ($u['client_id'] ?? 0);
    if ($cid > 0 && function_exists('db_val')) {
        try {
            $n = (int) db_val("SELECT COUNT(*) FROM campaign_messages WHERE client_id=? AND status IN ('dead','review')", [$cid]);
        } catch (Throwable $e) { $n = 0; }   // before migration 018 the statuses don't exist
    }
    return $n;
}

/**
 * The handful of destinations that get a bottom tab bar on phones. Everything else stays
 * in the drawer. Keys must exist in nav_items() for the same role.
 */
function nav_primary(string $role): array
{
    return $role === 'admin'
        ? ['overview', 'clients', 'inbox', 'campaigns']
        // A priority list, not a fixed four: the tab bar shows the first four this user can open,
        // so a salesperson gets the CRM where an account owner gets Campaigns.
        : ['dashboard', 'inbox', 'crm', 'campaigns', 'contacts'];
}

function nav_icon(string $key): string
{
    $s = 'width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';
    $icons = [
        'grid'  => "<path d=\"M2 2h5v5H2zM9 2h5v5H9zM2 9h5v5H2zM9 9h5v5H9z\"/>",
        'pipe'  => "<path d=\"M2 2.5h3.2v11H2zM6.4 2.5h3.2v7.5H6.4zM10.8 2.5H14v4.5h-3.2z\"/>",
        'users' => "<circle cx=\"6\" cy=\"5\" r=\"2.5\"/><path d=\"M1.5 13.5c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4\"/><circle cx=\"12\" cy=\"5.5\" r=\"1.8\"/><path d=\"M14.5 12.5c0-1.8-1.2-3-2.7-3.3\"/>",
        'list'  => "<path d=\"M5 4h9M5 8h9M5 12h9M2 4h.01M2 8h.01M2 12h.01\"/>",
        'doc'   => "<path d=\"M4 1.5h5l3 3v9a1 1 0 01-1 1H4a1 1 0 01-1-1v-11a1 1 0 011-1z\"/><path d=\"M9 1.5V5h3M5.5 8.5h5M5.5 11h5\"/>",
        'send'  => "<path d=\"M14 2L7 9M14 2l-4.5 12-2.5-5-5-2.5L14 2z\"/>",
        'chart' => "<path d=\"M2 14h12\"/><rect x=\"3\" y=\"8\" width=\"2.5\" height=\"6\"/><rect x=\"7\" y=\"5\" width=\"2.5\" height=\"9\"/><rect x=\"11\" y=\"3\" width=\"2.5\" height=\"11\"/>",
        'gear'  => "<circle cx=\"8\" cy=\"8\" r=\"2.2\"/><path d=\"M8 1.5v2M8 12.5v2M1.5 8h2M12.5 8h2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M12.6 3.4l-1.4 1.4M4.8 11.2l-1.4 1.4\"/>",
        'book'  => "<path d=\"M3 2.5h7a1.5 1.5 0 011.5 1.5v9.5H4.5A1.5 1.5 0 013 12V2.5z\"/><path d=\"M11.5 13.5H5a1.5 1.5 0 010-3h6.5\"/>",
        'team'  => "<circle cx=\"5.5\" cy=\"6\" r=\"2\"/><circle cx=\"11\" cy=\"6\" r=\"2\"/><path d=\"M1.5 13c0-2 1.8-3.2 4-3.2s4 1.2 4 3.2M10 10c2 0 4.5 1 4.5 3\"/>",
        'bot'   => "<rect x=\"3\" y=\"5\" width=\"10\" height=\"7\" rx=\"2\"/><path d=\"M8 5V2.5M6 2.5h4\"/><circle cx=\"6\" cy=\"8.5\" r=\".8\"/><circle cx=\"10\" cy=\"8.5\" r=\".8\"/><path d=\"M1.5 8v2M14.5 8v2\"/>",
        'target'=> "<circle cx=\"8\" cy=\"8\" r=\"6\"/><circle cx=\"8\" cy=\"8\" r=\"3\"/><circle cx=\"8\" cy=\"8\" r=\".6\" fill=\"currentColor\"/>",
        'inbox' => "<path d=\"M2 9.5l1.6-6a1 1 0 011-.8h6.8a1 1 0 011 .8l1.6 6v3a1 1 0 01-1 1H3a1 1 0 01-1-1v-3z\"/><path d=\"M2 9.5h3.5l1 1.5h3l1-1.5H14\"/>",
        'globe' => "<circle cx=\"8\" cy=\"8\" r=\"6.2\"/><path d=\"M1.8 8h12.4M8 1.8c1.8 1.9 2.6 3.9 2.6 6.2S9.8 12.3 8 14.2C6.2 12.3 5.4 10.3 5.4 8S6.2 3.7 8 1.8z\"/>",
        'chat'  => "<path d=\"M2.5 3.5h11a1 1 0 011 1v6a1 1 0 01-1 1H6l-3 2.5V11.5H2.5a1 1 0 01-1-1v-6a1 1 0 011-1z\"/><path d=\"M5 6.5h6M5 9h4\"/>",
    ];
    return "<svg $s>" . ($icons[$key] ?? $icons['grid']) . "</svg>";
}

/** Remembers the current role/active nav key so layout_footer() can render the tab bar. */
function layout_ctx(?string $role = null, ?string $active = null): array
{
    static $ctx = ['role' => 'client', 'active' => ''];
    if ($role !== null)   $ctx['role'] = $role;
    if ($active !== null) $ctx['active'] = $active;
    return $ctx;
}
function layout_role(): string   { return layout_ctx()['role']; }
function layout_active(): string { return layout_ctx()['active']; }

function layout_header(string $title, string $role, string $active, array $opts = []): void
{
    layout_ctx($role, $active);
    $appName = brand_name();
    $items   = nav_items($role);
    $badge   = $role === 'admin' ? 'ADMIN' : 'CLIENT';

    /* Where this page sits decides how every link in the chrome must be written.
       Pages under admin/ or client/ reach the app root with '../' and their siblings by bare
       filename. A page at the ROOT (help.php) is the other way round — and rendering the same
       relative links there pointed the whole sidebar at files that do not exist, so every nav
       item 404'd. Both prefixes are computed once here rather than assumed. */
    $inSub   = in_array(basename(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), ['admin', 'client'], true);
    $root    = $inSub ? '../' : './';                                  // → the app root
    $navBase = $inSub ? '' : ($role === 'admin' ? 'admin/' : 'client/'); // → this role's pages
    require_once __DIR__ . '/i18n.php';
    i18n_begin();                                   // Arabic: translate everything printed from here on
    $theme = ui_theme();
    ?>
<!DOCTYPE html>
<html lang="<?= ui_lang() ?>" dir="<?= ui_rtl() ? 'rtl' : 'ltr' ?>"<?= $theme !== 'auto' ? ' data-theme="' . $theme . '"' : '' ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — <?= e($appName) ?></title>
<link rel="stylesheet" href="<?= $root ?>assets/dashboard.css?v=<?= @filemtime(__DIR__ . '/../assets/dashboard.css') ?: '7' ?>">
<?php
/* An uploaded logo's natural proportions vary a lot, so the height is a setting rather than
   a constant. The bar grows with it — a taller logo in a fixed-height bar just overflows. */
$logoH  = brand_logo_height();
$barH   = max(58, $logoH + 22);
?>
<style>:root{ --topbar-h: <?= (int) $barH ?>px; }</style>
<?= pwa_head($root) ?>
</head>
<body>
<?php $me = current_user_full() ?: []; $unread = topbar_unread($me); ?>
<header class="topbar">
  <div class="topbar-brand">
    <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Menu" aria-expanded="false" aria-controls="sidebar">
      <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 5h14M3 10h14M3 15h14"/></svg>
    </button>
    <a class="brand-mark" href="<?= e($navBase) ?>index.php"><?= brand_logo('full', $logoH, $root) ?></a>
    <span class="topbar-sub"><?= e($badge) ?></span>
  </div>
  <nav class="topbar-nav">
    <?php if (!empty($opts['credits_html'])): ?><?= $opts['credits_html'] ?><?php endif; ?>

    <?php
      /* The bell opens the Inbox and counts its unread threads, so a user without the Inbox
         should not see either: a door that says "not for your role", with a number on it they
         are not allowed to act on. The profile chip is the person's OWN page, so it goes to
         profile.php for client users rather than to the company's Settings. */
      $clientSide = $role !== 'admin';
      $showBell   = !$clientSide || !function_exists('can_use') || can_use('inbox');
      $meHref     = $clientSide ? 'profile.php' : 'settings.php';
    ?>
    <?php
      /* On the client side the bell also carries the CRM's notices — a lead given to you, a
         follow-up due, a visit coming up — so it shows for anyone with the Inbox OR the CRM, and
         opens a panel instead of going straight to the Inbox. */
      $canInbox = !$clientSide || !function_exists('can_use') || can_use('inbox');
      $canCrm   = $clientSide && function_exists('can_use') && can_use('crm') && function_exists('crm_notices_for') && !empty($me['id']);
      $notices  = $canCrm ? crm_notices_for((int) $me['id'], 12) : [];
      $unseen   = $canCrm ? crm_notices_unread((int) $me['id']) : 0;
      $bellN    = ($canInbox ? $unread : 0) + $unseen;
      $here     = basename((string) ($_SERVER['REQUEST_URI'] ?? 'index.php'));
    ?>
    <?php if ($canCrm): ?>
    <div class="bell-wrap">
      <a class="topbar-bell" id="bell-btn" href="<?= e($navBase) ?><?= $canInbox ? 'inbox.php' : 'crm.php' ?>" aria-haspopup="true" aria-expanded="false"
         aria-label="<?= $bellN ? $bellN . ' new' : 'Notifications' ?>" title="Notifications">
        <svg width="19" height="19" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10 2.5a4.5 4.5 0 00-4.5 4.5c0 3.5-1.5 4.5-1.5 4.5h12s-1.5-1-1.5-4.5A4.5 4.5 0 0010 2.5z"/><path d="M8.6 15a1.6 1.6 0 002.8 0"/>
        </svg>
        <?php if ($bellN > 0): ?><span class="bell-dot"><?= $bellN > 99 ? '99+' : (int) $bellN ?></span><?php endif; ?>
      </a>
      <div class="bell-panel" id="bell-panel" hidden>
        <div class="bell-head"><strong>Notifications</strong>
          <?php if ($unseen): ?><a href="<?= e($navBase) ?>notices.php?all=1&t=<?= e(csrf_token()) ?>&back=<?= e(urlencode($here)) ?>">Mark all read</a><?php endif; ?></div>
        <?php if ($canInbox): ?>
          <a class="bell-item bell-msgs" href="<?= e($navBase) ?>inbox.php"><?= $unread ? '<strong>' . (int) $unread . ' unread message' . ($unread === 1 ? '' : 's') . '</strong>' : 'No unread messages' ?><span>Open Inbox →</span></a>
        <?php endif; ?>
        <?php if (!$notices): ?><p class="bell-empty">Nothing yet. New leads, due follow-ups and visits will show here.</p><?php endif; ?>
        <?php foreach ($notices as $nt): ?>
          <a class="bell-item <?= $nt['read'] ? '' : 'unread' ?>" href="<?= e($navBase) ?>notices.php?id=<?= (int) $nt['id'] ?>">
            <span class="bell-text"><?= e($nt['text']) ?></span>
            <span class="bell-when"><?= e(date('j M, H:i', strtotime($nt['when']))) ?></span></a>
        <?php endforeach; ?>
      </div>
    </div>
    <script>
    (() => {
      const b = document.getElementById('bell-btn'), p = document.getElementById('bell-panel');
      b.addEventListener('click', e => { e.preventDefault(); p.hidden = !p.hidden; b.setAttribute('aria-expanded', String(!p.hidden)); });
      document.addEventListener('click', e => { if (!p.hidden && !e.target.closest('.bell-wrap')) { p.hidden = true; b.setAttribute('aria-expanded', 'false'); } });
      document.addEventListener('keydown', e => { if (e.key === 'Escape' && !p.hidden) { p.hidden = true; b.focus(); } });
    })();
    </script>
    <?php elseif ($showBell): ?>
    <a class="topbar-bell" href="<?= e($navBase) ?>inbox.php" aria-label="<?= $unread ? $unread . ' unread message(s)' : 'Inbox' ?>" title="Inbox">
      <svg width="19" height="19" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M10 2.5a4.5 4.5 0 00-4.5 4.5c0 3.5-1.5 4.5-1.5 4.5h12s-1.5-1-1.5-4.5A4.5 4.5 0 0010 2.5z"/><path d="M8.6 15a1.6 1.6 0 002.8 0"/>
      </svg>
      <?php if ($unread > 0): ?><span class="bell-dot"><?= $unread > 99 ? '99+' : (int) $unread ?></span><?php endif; ?>
    </a>
    <?php endif; ?>

    <?php if (array_key_exists('lang', $me)):
      $back = (string) ($_SERVER['REQUEST_URI'] ?? '');
      $nextTheme = ['auto' => 'dark', 'dark' => 'light', 'light' => 'auto'][$theme];
      ob_start(); ?>
    <form method="post" action="<?= e($root) ?>prefs.php" class="topbar-pref">
      <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
      <input type="hidden" name="action" value="lang"><input type="hidden" name="lang" value="<?= ui_rtl() ? 'en' : 'ar' ?>">
      <button type="submit" class="pref-btn" id="lang-btn" lang="<?= ui_rtl() ? 'en' : 'ar' ?>" title="<?= ui_rtl() ? 'English' : 'العربية' ?>"><?= ui_rtl() ? 'EN' : 'ع' ?></button>
    </form>
    <form method="post" action="<?= e($root) ?>prefs.php" class="topbar-pref">
      <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
      <input type="hidden" name="action" value="theme"><input type="hidden" name="theme" value="<?= $nextTheme ?>">
      <button type="submit" class="pref-btn" id="theme-btn" title="<?= ['auto' => 'Appearance: Automatic', 'dark' => 'Appearance: Dark', 'light' => 'Appearance: Light'][$theme] ?>" aria-label="<?= ['auto' => 'Appearance: Automatic', 'dark' => 'Appearance: Dark', 'light' => 'Appearance: Light'][$theme] ?>">
        <?php if ($theme === 'dark'): ?><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M16.5 12.5A7 7 0 017.5 3.5a7 7 0 109 9z"/></svg>
        <?php elseif ($theme === 'light'): ?><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="10" cy="10" r="3.5"/><path d="M10 1.5v2M10 16.5v2M1.5 10h2M16.5 10h2M4 4l1.4 1.4M14.6 14.6L16 16M16 4l-1.4 1.4M5.4 14.6L4 16"/></svg>
        <?php else: ?><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10" cy="10" r="7"/><path d="M10 3a7 7 0 000 14z" fill="currentColor"/></svg><?php endif; ?>
      </button>
    </form>
    <?php $prefForms = ob_get_clean(); echo $prefForms; endif; ?>

    <a class="topbar-user" href="<?= e($navBase) ?><?= $meHref ?>" title="<?= e((string) ($me['email'] ?? '')) ?>">
      <?= user_avatar_html($me, 30, $root) ?>
      <span class="topbar-user-name"><?= e(user_display_name($me)) ?></span>
    </a>

    <a class="btn-top gold" href="<?= e($root) ?>logout.php">Logout</a>
  </nav>
</header>

<div class="nav-backdrop" id="nav-backdrop" hidden></div>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <nav class="sb-nav">
      <div class="sb-search">
        <input type="search" id="sb-q" placeholder="Search the menu" aria-label="Search the menu" autocomplete="off">
        <button type="button" class="sb-kbd" id="cmdk-open" title="Go to…" aria-label="Go to…"><kbd>Ctrl K</kbd></button>
      </div>
      <?php $favs = array_key_exists('nav_favs', $me) ? nav_favs() : []; if ($favs): ?>
      <div class="sb-favs" id="sb-favs">
        <span class="sb-glabel">Favourites</span>
        <?php foreach ($favs as $fv): ?>
          <a class="sb-link sb-fav <?= nav_here() === $fv['u'] ? 'active' : '' ?>" href="<?= e($navBase . $fv['u']) ?>">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 1.6l1.9 4 4.4.5-3.3 3 .9 4.3L8 11.2l-3.9 2.2.9-4.3-3.3-3 4.4-.5z"/></svg>
            <span><?= e($fv['t']) ?></span></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php foreach ($items as $key => $item):
        $kids = !empty($item['children']) ? nav_children((string) $item['children']) : []; ?>
        <?php if ($kids): $open = $key === $active; ?>
        <div class="sb-group <?= $open ? 'open' : '' ?>">
          <div class="sb-head">
            <a class="sb-link <?= $open ? 'active' : '' ?>" href="<?= e($navBase . $item['url']) ?>">
              <?= nav_icon($item['icon']) ?> <span><?= e($item['label']) ?></span>
            </a>
            <button type="button" class="sb-toggle" aria-label="Show <?= e($item['label']) ?> pages" aria-expanded="<?= $open ? 'true' : 'false' ?>"
                    onclick="const g=this.closest('.sb-group');g.classList.toggle('open');this.setAttribute('aria-expanded',g.classList.contains('open'))">
              <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M3 4.5l3 3 3-3"/></svg>
            </button>
          </div>
          <div class="sb-sub">
            <?php $lastG = null; foreach ($kids as $kid): ?>
              <?php if (($kid[5] ?? null) !== null && $kid[5] !== $lastG): $lastG = $kid[5]; ?><span class="sb-glabel"><?= e($lastG) ?></span><?php endif; ?>
              <a class="sb-sublink <?= $open && nav_child_active($kid) ? 'active' : '' ?>" href="<?= e($navBase . $kid[1]) ?>"><?= e($kid[0]) ?>
                <?php if (!empty($kid[3])): ?><span class="sb-count <?= !empty($kid[4]) ? 'late' : '' ?>"><?= (int) $kid[3] ?></span><?php endif; ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php else: ?>
        <a class="sb-link <?= $key === $active ? 'active' : '' ?>" href="<?= e($navBase . $item['url']) ?>">
          <?= nav_icon($item['icon']) ?> <span><?= e($item['label']) ?></span>
          <?php if (!empty($item['badge'])): ?><span class="sb-count"><?= (int) $item['badge'] ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
      <?php endforeach; ?>
      <span class="sb-sep"></span>
      <span class="sb-who"><?= e(current_user()['email'] ?? '') ?></span>
      <?php if (!empty($prefForms)): ?><div class="sb-prefs"><?= str_replace(['id="lang-btn"', 'id="theme-btn"'], '', $prefForms) ?></div><?php endif; ?>
      <a class="sb-link" href="<?= e($root) ?>logout.php"><span>Log out</span></a>
    </nav>
  </aside>

  <main class="content">
    <div class="install-bar" id="install-bar">
      <span class="grow" id="install-text"></span>
      <button type="button" class="btn btn-primary btn-sm" id="install-go" hidden>Install</button>
      <button type="button" class="x" id="install-x" aria-label="Dismiss">&times;</button>
    </div>
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
<?php
    // Bottom tab bar — phones only (hidden by CSS above 720px).
    $tabRole  = layout_role();
    $tabItems = nav_items($tabRole);
    $tabActive = layout_active();
    // Same reasoning as layout_header(): a page at the app root needs the role folder in front
    // of every nav URL, or the tab bar points at files that aren't there.
    $tabSub  = in_array(basename(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), ['admin', 'client'], true);
    $tabBase = $tabSub ? '' : ($tabRole === 'admin' ? 'admin/' : 'client/');
?>
<nav class="tabbar" aria-label="Primary">
  <?php $tabShown = 0; foreach (nav_primary($tabRole) as $key): if (empty($tabItems[$key]) || $tabShown >= 4) continue; $tabShown++; $it = $tabItems[$key]; ?>
    <a class="tab <?= $key === $tabActive ? 'active' : '' ?>" href="<?= e($tabBase . $it['url']) ?>">
      <?= nav_icon($it['icon']) ?><span><?= e($it['label']) ?></span>
    </a>
  <?php endforeach; ?>
</nav>
<div class="toast" id="toast"></div>
<?php
  $leadSearch = $tabRole !== 'admin' && function_exists('can_use') && can_use('crm');
  $prefsUrl = ($tabSub ? '../' : './') . 'prefs.php';
  $T = fn(string $s) => function_exists('t') ? t($s) : $s;
?>
<dialog class="cmdk" id="cmdk" aria-label="Go to…"
        data-lead-url="<?= $leadSearch ? e($tabBase . 'lead_search.php') : '' ?>" data-base="<?= e($tabBase) ?>">
  <input type="search" id="cmdk-q" placeholder="<?= e($T($leadSearch ? 'Go to a page, or find a lead…' : 'Go to a page…')) ?>" autocomplete="off" aria-controls="cmdk-list">
  <ul id="cmdk-list" role="listbox"></ul>
  <p class="cmdk-foot"><kbd>↑</kbd><kbd>↓</kbd> <?= e($T('to move')) ?> · <kbd>Enter</kbd> <?= e($T('to open')) ?> · <kbd>Esc</kbd> <?= e($T('to close')) ?></p>
</dialog>
<script>
/* ── menu search, favourites star, Ctrl/⌘+K ── */
(function(){
  var norm = function(s){ return (s||'').toLowerCase().replace(/[أإآ]/g,'ا').replace(/ة/g,'ه').replace(/ى/g,'ي').trim(); };
  // Menu search: hide what doesn't match, open a group whose pages do.
  var q = document.getElementById('sb-q');
  if (q) q.addEventListener('input', function(){
    var v = norm(q.value);
    document.querySelectorAll('#sidebar .sb-link, #sidebar .sb-sublink').forEach(function(a){
      if (a.closest('.sb-head')) return;
      a.hidden = v !== '' && norm(a.textContent).indexOf(v) < 0;
    });
    document.querySelectorAll('#sidebar .sb-group').forEach(function(g){
      var head = g.querySelector('.sb-head .sb-link'), anyKid = false;
      g.querySelectorAll('.sb-sublink').forEach(function(k){ if (!k.hidden) anyKid = true; });
      var headHit = v === '' || norm(head.textContent).indexOf(v) >= 0;
      if (headHit && v !== '') g.querySelectorAll('.sb-sublink').forEach(function(k){ k.hidden = false; anyKid = true; });
      g.hidden = !(headHit || anyKid);
      if (v !== '') g.classList.toggle('open', anyKid);
    });
    document.querySelectorAll('#sidebar .sb-glabel').forEach(function(l){ l.hidden = v !== ''; });
  });
  if (q) q.addEventListener('keydown', function(e){
    if (e.key === 'Enter') { var a = Array.prototype.find.call(document.querySelectorAll('#sidebar .sb-link:not([hidden]), #sidebar .sb-sublink:not([hidden])'), function(a){ return !a.closest('[hidden]') && q.value.trim() !== ''; }); if (a) location.href = a.href; }
    if (e.key === 'Escape') { q.value = ''; q.dispatchEvent(new Event('input')); }
  });

  // The star on each page's title.
  var star = document.getElementById('fav-star');
  if (star) star.addEventListener('click', function(){
    var fd = new FormData(); fd.append('action','fav'); fd.append('ajax','1');
    fd.append('url', star.dataset.url); fd.append('title', star.dataset.title);
    var tok = document.querySelector('input[name=csrf_token]'); if (tok) fd.append('csrf_token', tok.value);
    fetch(<?= json_encode($prefsUrl) ?>, {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){ return r.json(); })
      .then(function(j){ if (j.ok) location.reload(); else if (typeof showToast==='function') showToast(j.error||'Could not save', true); });
  });

  // Ctrl/⌘+K: every page in the menu, and leads by name, phone or code.
  var dlg = document.getElementById('cmdk'); if (!dlg || !dlg.showModal) return;
  var inp = document.getElementById('cmdk-q'), list = document.getElementById('cmdk-list'), sel = 0, items = [], timer = null, seq = 0;
  var pages = [], seen = {};
  document.querySelectorAll('#sidebar a[href]').forEach(function(a){
    var t = a.textContent.replace(/\s+\d+\s*$/,'').replace(/\s+/g,' ').trim();
    if (!t || /logout\.php/.test(a.getAttribute('href')) || seen[a.href]) return;
    var grp = a.closest('.sb-group'); var parent = grp ? grp.querySelector('.sb-head .sb-link').textContent.trim() : '';
    seen[a.href] = 1; pages.push({label: t, sub: parent && parent !== t ? parent : '', href: a.href});
  });
  function draw(){
    list.innerHTML = '';
    items.forEach(function(it, i){
      var li = document.createElement('li'); li.setAttribute('role','option'); li.className = i === sel ? 'on' : '';
      var b = document.createElement('strong'); b.textContent = it.label; li.appendChild(b);
      if (it.sub) { var s = document.createElement('span'); s.textContent = it.sub; li.appendChild(s); }
      if (it.kind) { var k = document.createElement('em'); k.textContent = it.kind; li.appendChild(k); }
      li.addEventListener('mousedown', function(e){ e.preventDefault(); location.href = it.href; });
      list.appendChild(li);
    });
    if (!items.length) { var li = document.createElement('li'); li.className = 'cmdk-none'; li.textContent = <?= json_encode($T('Nothing found')) ?>; list.appendChild(li); }
  }
  function run(){
    var v = norm(inp.value);
    items = pages.filter(function(p){ return v === '' || norm(p.label + ' ' + p.sub).indexOf(v) >= 0; }).slice(0, 8);
    sel = 0; draw();
    var url = dlg.dataset.leadUrl; clearTimeout(timer);
    if (url && inp.value.trim().length >= 2) timer = setTimeout(function(){
      var my = ++seq;
      fetch(url + '?q=' + encodeURIComponent(inp.value.trim()), {credentials:'same-origin', headers:{'Accept':'application/json'}})
        .then(function(r){ return r.json(); }).then(function(j){
          if (my !== seq || !j.ok) return;
          j.leads.forEach(function(l){ items.push({label: l.name, sub: l.phone + (l.code ? ' · ' + l.code : ''), kind: l.stage, href: dlg.dataset.base + l.href}); });
          draw();
        }).catch(function(){});
    }, 180);
  }
  function open(){ inp.value = ''; run(); dlg.showModal(); inp.focus(); }
  document.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); dlg.open ? dlg.close() : open(); }
  });
  var btn = document.getElementById('cmdk-open'); if (btn) btn.addEventListener('click', open);
  inp.addEventListener('input', run);
  inp.addEventListener('keydown', function(e){
    if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(items.length - 1, sel + 1); draw(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(0, sel - 1); draw(); }
    else if (e.key === 'Enter' && items[sel]) { e.preventDefault(); location.href = items[sel].href; }
    else if (e.key === 'Escape') { e.preventDefault(); dlg.close(); }   // a search box would only clear itself
  });
  dlg.addEventListener('mousedown', function(e){ if (e.target === dlg) dlg.close(); });
})();
</script>
<?php
  // Help + intro video, on every signed-in page. The path back to the app root differs
  // between admin/, client/ and the root itself, so work it out from the running script.
  require_once __DIR__ . '/help.php';
  $inSub = in_array(basename(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), ['admin', 'client'], true);
  $helpBase = $inSub ? '../' : './';
  echo help_launcher_html($helpBase);
?>
<?= pwa_script($tabSub ? '../' : './') ?>
<script>
/* ── install prompt ──
   Android/Chrome fires beforeinstallprompt, so we can offer a real Install button.
   iOS never fires it and has no programmatic install, so Safari users get the manual
   Share → Add to Home Screen steps instead — without this, almost nobody finds it. */
(function(){
  var bar=document.getElementById('install-bar'), txt=document.getElementById('install-text'),
      go=document.getElementById('install-go'), x=document.getElementById('install-x');
  if(!bar) return;
  var KEY='gildana_install_dismissed';
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if(standalone || localStorage.getItem(KEY)) return;     // already installed, or dismissed
  x.addEventListener('click', function(){ localStorage.setItem(KEY,'1'); bar.classList.remove('show'); });

  var deferred=null;
  window.addEventListener('beforeinstallprompt', function(e){
    e.preventDefault(); deferred=e;
    txt.textContent='Install Revenect on your phone for quick access.';
    go.hidden=false; bar.classList.add('show');
  });
  go.addEventListener('click', async function(){
    if(!deferred) return;
    deferred.prompt();
    try { await deferred.userChoice; } catch(_){}
    deferred=null; bar.classList.remove('show');
  });
  window.addEventListener('appinstalled', function(){ localStorage.setItem(KEY,'1'); bar.classList.remove('show'); });

  var ua=navigator.userAgent;
  var iOS=/iPad|iPhone|iPod/.test(ua) || (navigator.platform==='MacIntel' && navigator.maxTouchPoints>1);
  var safari=/Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
  if(iOS && safari){
    txt.innerHTML='Install this app: tap <strong>Share</strong> \u2191 then <strong>Add to Home Screen</strong>.';
    bar.classList.add('show');
  }
})();

/* ── mobile drawer ── */
(function(){
  var t=document.getElementById('nav-toggle'), sb=document.getElementById('sidebar'), bd=document.getElementById('nav-backdrop');
  if(!t||!sb||!bd) return;
  function set(open){
    sb.classList.toggle('open', open);
    bd.hidden = !open;
    t.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('nav-open', open);
  }
  t.addEventListener('click', function(){ set(!sb.classList.contains('open')); });
  bd.addEventListener('click', function(){ set(false); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') set(false); });
  // Closing on resize avoids a drawer stuck open when rotating to landscape/desktop.
  window.addEventListener('resize', function(){ if(window.innerWidth>900) set(false); });
})();

function showToast(msg, err){
  const t=document.getElementById('toast');
  if(!t) return;
  t.textContent=msg;
  t.className='toast show'+(err?' err':'');
  clearTimeout(t._t); t._t=setTimeout(()=>t.classList.remove('show'),3200);
}
/* Global: every pop-up gets a × close button; click-outside + Escape also close. */
(function(){
  function enhance(){
    document.querySelectorAll('.modal-back > .modal').forEach(function(m){
      if(m.querySelector('.modal-x')) return;
      var b=document.createElement('button');
      b.type='button'; b.className='modal-x'; b.setAttribute('aria-label','Close'); b.innerHTML='&times;';
      b.addEventListener('click',function(){ var mb=m.closest('.modal-back'); if(mb) mb.classList.remove('open'); });
      m.appendChild(b);
    });
    document.querySelectorAll('.modal-back').forEach(function(mb){
      if(mb._xClose) return; mb._xClose=1;
      mb.addEventListener('mousedown',function(e){ if(e.target===mb) mb.classList.remove('open'); });
    });
  }
  document.addEventListener('DOMContentLoaded',enhance);
  document.addEventListener('keydown',function(e){
    if(e.key==='Escape') document.querySelectorAll('.modal-back.open').forEach(function(mb){ mb.classList.remove('open'); });
  });
})();
</script>
</body>
</html>
    <?php
}

/** Small page-title/header block with an optional action button on the right. */
require_once __DIR__ . '/guide.php';

function page_head(string $title, string $actionHtml = ''): void
{
    /* Every page gets its walkthrough here rather than each page remembering to ask for one.
       The nav key the page already declared to layout_header() is enough to find it, so a
       screen earns its guide by existing. */
    $guide = '';
    if (function_exists('guide_html')) $guide = guide_html(layout_active());

    // A star to put this page in the menu's Favourites.
    $star = '';
    $me = function_exists('current_user_full') ? (current_user_full() ?: []) : [];
    if (array_key_exists('nav_favs', $me) && function_exists('nav_favs')) {
        $here = nav_here();
        $on = in_array($here, array_column(nav_favs(), 'u'), true);
        $star = '<button type="button" class="fav-star' . ($on ? ' on' : '') . '" id="fav-star" aria-pressed="' . ($on ? 'true' : 'false') . '"'
              . ' data-url="' . e($here) . '" data-title="' . e($title) . '" title="' . ($on ? 'Remove from favourites' : 'Star this page') . '">'
              . '<svg width="17" height="17" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1.6l1.9 4 4.4.5-3.3 3 .9 4.3L8 11.2l-3.9 2.2.9-4.3-3.3-3 4.4-.5z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg></button>';
    }

    echo '<div class="page-head"><h1>' . e($title) . $star . '</h1>'
       . ($guide !== '' || $actionHtml !== ''
            ? '<div class="page-actions">' . $guide . $actionHtml . '</div>' : '')
       . '</div>';
}

/** Campaign status → colored pill. */
function status_pill(string $status): string
{
    $map = [
        'draft'   => 'gray', 'scheduled' => 'blue', 'queued'    => 'gold',
        'sending' => 'gold', 'paused'    => 'gray', 'completed' => 'green',
        'failed'  => 'red',  'canceled'  => 'gray',
    ];
    $cls = $map[$status] ?? 'gray';
    return '<span class="pill ' . $cls . ' dot">' . e(ucfirst($status)) . '</span>';
}

/** Message status → colored pill. */
function msg_status_pill(string $status): string
{
    $map = [
        'queued' => 'gray', 'sending' => 'gold', 'sent' => 'blue',
        'delivered' => 'green', 'read' => 'green', 'failed' => 'red',
    ];
    $cls = $map[$status] ?? 'gray';
    return '<span class="pill ' . $cls . '">' . e(ucfirst($status)) . '</span>';
}
