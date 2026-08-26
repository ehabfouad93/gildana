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
        return [
            'overview'  => ['label' => 'Overview',  'url' => 'index.php',     'icon' => 'grid'],
            'clients'   => ['label' => 'Clients',   'url' => 'clients.php',   'icon' => 'users'],
            'inbox'     => ['label' => 'Inbox',      'url' => 'inbox.php',     'icon' => 'chat'],
            'campaigns' => ['label' => 'Campaigns', 'url' => 'campaigns.php', 'icon' => 'send'],
            'contacts'  => ['label' => 'Contacts',  'url' => 'contacts.php',  'icon' => 'book'],
            'templates' => ['label' => 'Templates', 'url' => 'templates.php', 'icon' => 'doc'],
            'reports'   => ['label' => 'Reports',   'url' => 'reports.php',   'icon' => 'chart'],
            'team'      => ['label' => 'Team',       'url' => 'team.php',      'icon' => 'team'],
            'help'      => ['label' => 'Help Content', 'url' => 'help_admin.php', 'icon' => 'book'],
            'settings'  => ['label' => 'Settings',  'url' => 'settings.php',  'icon' => 'gear'],
        ];
    }
    $nav = [
        'dashboard' => ['label' => 'Dashboard', 'url' => 'index.php',     'icon' => 'grid'],
        'inbox'     => ['label' => 'Inbox',     'url' => 'inbox.php',     'icon' => 'chat'],
        'contacts'  => ['label' => 'Contacts',  'url' => 'contacts.php',  'icon' => 'users'],
        'lists'     => ['label' => 'Lists',     'url' => 'lists.php',     'icon' => 'list'],
        'templates'   => ['label' => 'Templates',   'url' => 'templates.php',   'icon' => 'doc'],
        'campaigns'   => ['label' => 'Campaigns',   'url' => 'campaigns.php',   'icon' => 'send'],
        'automations' => ['label' => 'Automations',    'url' => 'automations.php', 'icon' => 'bot'],
        'qualifier'   => ['label' => 'Lead Qualifier', 'url' => 'qualifiers.php',  'icon' => 'target'],
        'agents'      => ['label' => 'AI Chat Agent',  'url' => 'agents.php',       'icon' => 'bot'],
        'reports'     => ['label' => 'Reports',        'url' => 'reports.php',      'icon' => 'chart'],
        'settings'  => ['label' => 'Settings',  'url' => 'settings.php',  'icon' => 'gear'],
    ];

    // Only appears when something is actually waiting — a permanent zero is just noise.
    if (($n = nav_attention_count()) > 0) {
        $item = ['label' => 'Needs attention', 'url' => 'failed.php', 'icon' => 'alert', 'badge' => $n];
        $nav = array_slice($nav, 0, 6, true) + ['attention' => $item] + array_slice($nav, 6, null, true);
    }
    return $nav;
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
        : ['dashboard', 'inbox', 'campaigns', 'contacts'];
}

function nav_icon(string $key): string
{
    $s = 'width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';
    $icons = [
        'grid'  => "<path d=\"M2 2h5v5H2zM9 2h5v5H9zM2 9h5v5H2zM9 9h5v5H9z\"/>",
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
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
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

    <a class="topbar-bell" href="<?= e($navBase) ?>inbox.php" aria-label="<?= $unread ? $unread . ' unread message(s)' : 'Inbox' ?>" title="Inbox">
      <svg width="19" height="19" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M10 2.5a4.5 4.5 0 00-4.5 4.5c0 3.5-1.5 4.5-1.5 4.5h12s-1.5-1-1.5-4.5A4.5 4.5 0 0010 2.5z"/><path d="M8.6 15a1.6 1.6 0 002.8 0"/>
      </svg>
      <?php if ($unread > 0): ?><span class="bell-dot"><?= $unread > 99 ? '99+' : (int) $unread ?></span><?php endif; ?>
    </a>

    <a class="topbar-user" href="<?= e($navBase) ?>settings.php" title="<?= e((string) ($me['email'] ?? '')) ?>">
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
      <?php foreach ($items as $key => $item): ?>
        <a class="sb-link <?= $key === $active ? 'active' : '' ?>" href="<?= e($navBase . $item['url']) ?>">
          <?= nav_icon($item['icon']) ?> <span><?= e($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
      <span class="sb-sep"></span>
      <span class="sb-who"><?= e(current_user()['email'] ?? '') ?></span>
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
  <?php foreach (nav_primary($tabRole) as $key): if (empty($tabItems[$key])) continue; $it = $tabItems[$key]; ?>
    <a class="tab <?= $key === $tabActive ? 'active' : '' ?>" href="<?= e($tabBase . $it['url']) ?>">
      <?= nav_icon($it['icon']) ?><span><?= e($it['label']) ?></span>
    </a>
  <?php endforeach; ?>
</nav>
<div class="toast" id="toast"></div>
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
function page_head(string $title, string $actionHtml = ''): void
{
    echo '<div class="page-head"><h1>' . e($title) . '</h1>'
       . ($actionHtml !== '' ? '<div class="page-actions">' . $actionHtml . '</div>' : '')
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
