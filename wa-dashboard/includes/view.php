<?php
declare(strict_types=1);

/**
 * Shared page chrome: topbar + role-aware sidebar + flash toasts.
 * Call layout_header(...) then echo page body, then layout_footer().
 */

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
            'settings'  => ['label' => 'Settings',  'url' => 'settings.php',  'icon' => 'gear'],
        ];
    }
    return [
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

function layout_header(string $title, string $role, string $active, array $opts = []): void
{
    $appName = (string) config('app_name', 'WhatsApp Dashboard');
    $items   = nav_items($role);
    $badge   = $role === 'admin' ? 'ADMIN' : 'CLIENT';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> — <?= e($appName) ?></title>
<link rel="stylesheet" href="../assets/dashboard.css?v=<?= @filemtime(__DIR__ . '/../assets/dashboard.css') ?: '7' ?>">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">
    <span class="brand-mark">GILDANA</span>
    <span class="topbar-sub">WhatsApp · <?= e($badge) ?></span>
  </div>
  <nav class="topbar-nav">
    <?php if (!empty($opts['credits_html'])): ?><?= $opts['credits_html'] ?><?php endif; ?>
    <span class="topbar-email"><?= e(current_user()['email'] ?? '') ?></span>
    <a class="btn-top gold" href="../logout.php">Logout</a>
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
<script>
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
