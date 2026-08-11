<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../includes/functions.php';

$error      = '';
$pw_error   = '';
$pw_success = '';

/* ── logout ── */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

/* ── export newsletter CSV ── */
if (isset($_GET['action']) && $_GET['action'] === 'export_newsletter' && admin_logged_in()) {
    $rows = array_reverse(read_json_list(NEWSLETTER_DATA_FILE));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="newsletter-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
    echo "Email,Date\n";
    foreach ($rows as $r) {
        echo '"' . str_replace('"', '""', (string) ($r['email'] ?? '')) . '",'
           . '"' . str_replace('"', '""', (string) ($r['created_at'] ?? '')) . '"' . "\n";
    }
    exit;
}

/* ── login ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (check_password((string) ($_POST['password'] ?? ''))) {
        $_SESSION['gildana_admin'] = true;
        csrf_token();
        header('Location: index.php');
        exit;
    }
    $error = 'Wrong password. Please try again.';
}

/* ── require auth ── */
if (!admin_logged_in()):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gildana CMS — Login</title>
<link rel="stylesheet" href="admin.css">
</head>
<body class="login-screen">
  <form class="login-card" method="post">
    <input type="hidden" name="action" value="login">
    <div class="login-brand">GILDANA</div>
    <h1>CMS Admin</h1>
    <?php if ($error): ?>
      <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>
    <label><span class="lbl">Password</span><input type="password" name="password" required autofocus></label>
    <button type="submit" class="btn-login">Sign In</button>
    <p class="login-note">Default password: <strong>admin123</strong><br>Change it in the Settings panel after logging in.</p>
  </form>
</body>
</html>
<?php
exit;
endif;

/* ══════════════════════════════════════════════
   AUTHENTICATED — HANDLE POST ACTIONS
══════════════════════════════════════════════ */
$action = (string) ($_POST['action'] ?? '');

/* ── AJAX-friendly delete contact ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_contact') {
    verify_csrf();
    $idx = (int) ($_POST['index'] ?? -1);
    $ok  = $idx >= 0 && delete_contact($idx);
    if (!empty($_POST['fetch'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok]);
        exit;
    }
    header('Location: index.php#messages');
    exit;
}

/* ── AJAX-friendly delete newsletter ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_newsletter') {
    verify_csrf();
    $email = (string) ($_POST['email'] ?? '');
    $ok    = $email !== '' && delete_newsletter_entry($email);
    if (!empty($_POST['fetch'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok]);
        exit;
    }
    header('Location: index.php#messages');
    exit;
}

/* ── change password ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'change_password') {
    verify_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!check_password($current)) {
        $pw_error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $pw_error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $pw_error = 'Passwords do not match.';
    } else {
        change_password($new);
        $pw_success = 'Password changed successfully.';
    }
}

/* ── save site content ── */
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    verify_csrf();
    $data = site_data();

    $data['meta'] = [
        'title'       => trim((string) ($_POST['meta_title'] ?? '')),
        'description' => trim((string) ($_POST['meta_description'] ?? '')),
    ];
    $data['brand'] = [
        'name'    => trim((string) ($_POST['brand_name'] ?? '')),
        'tagline' => trim((string) ($_POST['brand_tagline'] ?? '')),
    ];

    /* nav */
    $data['nav'] = [];
    foreach (($_POST['nav_label'] ?? []) as $i => $label) {
        $label = trim((string) $label);
        $url   = trim((string) ($_POST['nav_url'][$i] ?? ''));
        if ($label !== '' || $url !== '') {
            $data['nav'][] = ['label' => $label, 'url' => $url ?: '#'];
        }
    }

    /* hero */
    $heroTags     = array_values(array_filter(array_map('trim', explode("\n", (string) ($_POST['hero_tags'] ?? '')))));
    $data['hero'] = [
        'eyebrow'         => trim((string) ($_POST['hero_eyebrow'] ?? '')),
        'title'           => trim((string) ($_POST['hero_title'] ?? '')),
        'title_gold'      => trim((string) ($_POST['hero_title_gold'] ?? '')),
        'lede'            => trim((string) ($_POST['hero_lede'] ?? '')),
        'primary_label'   => trim((string) ($_POST['hero_primary_label'] ?? '')),
        'primary_url'     => trim((string) ($_POST['hero_primary_url'] ?? '')),
        'secondary_label' => trim((string) ($_POST['hero_secondary_label'] ?? '')),
        'secondary_url'   => trim((string) ($_POST['hero_secondary_url'] ?? '')),
        'image'           => upload_image('hero_image', (string) ($_POST['hero_image_current'] ?? '')),
        'image_alt'       => trim((string) ($_POST['hero_image_alt'] ?? '')),
        'tags'            => $heroTags,
    ];

    /* stats */
    $data['stats'] = [
        'eyebrow'   => trim((string) ($_POST['stats_eyebrow'] ?? '')),
        'cta_label' => trim((string) ($_POST['stats_cta_label'] ?? '')),
        'cta_url'   => trim((string) ($_POST['stats_cta_url'] ?? '')),
        'items'     => [],
    ];
    foreach (($_POST['stat_number'] ?? []) as $i => $number) {
        $label = trim((string) ($_POST['stat_label'][$i] ?? ''));
        if (trim((string) $number) !== '' || $label !== '') {
            $data['stats']['items'][] = [
                'number' => trim((string) $number),
                'suffix' => trim((string) ($_POST['stat_suffix'][$i] ?? '')),
                'label'  => $label,
                'sub'    => trim((string) ($_POST['stat_sub'][$i] ?? '')),
            ];
        }
    }

    /* services */
    $data['services'] = [
        'eyebrow'    => trim((string) ($_POST['services_eyebrow'] ?? '')),
        'title'      => trim((string) ($_POST['services_title'] ?? '')),
        'title_gold' => trim((string) ($_POST['services_title_gold'] ?? '')),
        'items'      => [],
    ];
    foreach (($_POST['service_name'] ?? []) as $i => $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $data['services']['items'][] = [
                'icon' => trim((string) ($_POST['service_icon'][$i] ?? 'strategy')),
                'name' => $name,
                'desc' => trim((string) ($_POST['service_desc'][$i] ?? '')),
            ];
        }
    }

    /* portfolio */
    $data['portfolio'] = [
        'eyebrow'       => trim((string) ($_POST['portfolio_eyebrow'] ?? '')),
        'title'         => trim((string) ($_POST['portfolio_title'] ?? '')),
        'view_all_label'=> trim((string) ($_POST['portfolio_view_all_label'] ?? '')),
        'view_all_url'  => trim((string) ($_POST['portfolio_view_all_url'] ?? '')),
        'items'         => [],
    ];
    foreach (($_POST['case_title'] ?? []) as $i => $title) {
        $title = trim((string) $title);
        if ($title !== '') {
            $current = (string) ($_POST['case_image_current'][$i] ?? '');
            $data['portfolio']['items'][] = [
                'image' => upload_image('case_image_' . $i, $current),
                'cat'   => trim((string) ($_POST['case_cat'][$i] ?? '')),
                'title' => $title,
                'pct'   => trim((string) ($_POST['case_pct'][$i] ?? '')),
                'label' => trim((string) ($_POST['case_label'][$i] ?? '')),
            ];
        }
    }

    /* testimonials */
    $data['testimonials'] = [
        'eyebrow' => trim((string) ($_POST['testimonials_eyebrow'] ?? '')),
        'items'   => [],
    ];
    foreach (($_POST['testimonial_quote'] ?? []) as $i => $quote) {
        $quote = trim((string) $quote);
        if ($quote !== '') {
            $data['testimonials']['items'][] = [
                'quote' => $quote,
                'who'   => trim((string) ($_POST['testimonial_who'][$i] ?? '')),
                'role'  => trim((string) ($_POST['testimonial_role'][$i] ?? '')),
            ];
        }
    }

    /* clients */
    $data['clients'] = [
        'eyebrow' => trim((string) ($_POST['clients_eyebrow'] ?? '')),
        'items'   => [],
    ];
    foreach (($_POST['client_alt'] ?? []) as $i => $alt) {
        $alt     = trim((string) $alt);
        $current = (string) ($_POST['client_image_current'][$i] ?? '');
        $image   = upload_image('client_image_' . $i, $current);
        if ($alt !== '' || $image !== '') {
            $data['clients']['items'][] = ['image' => $image, 'alt' => $alt];
        }
    }

    /* contact */
    $data['contact'] = [
        'eyebrow' => trim((string) ($_POST['contact_eyebrow'] ?? '')),
        'title'   => trim((string) ($_POST['contact_title'] ?? '')),
        'lede'    => trim((string) ($_POST['contact_lede'] ?? '')),
        'success' => trim((string) ($_POST['contact_success'] ?? '')),
        'info'    => [],
    ];
    foreach (($_POST['contact_text'] ?? []) as $i => $text) {
        $text = trim((string) $text);
        if ($text !== '') {
            $data['contact']['info'][] = [
                'icon' => trim((string) ($_POST['contact_icon'][$i] ?? 'phone')),
                'text' => $text,
            ];
        }
    }

    /* footer */
    $data['footer'] = [
        'copyright'        => trim((string) ($_POST['footer_copyright'] ?? '')),
        'follow_title'     => trim((string) ($_POST['footer_follow_title'] ?? '')),
        'newsletter_title' => trim((string) ($_POST['footer_newsletter_title'] ?? '')),
        'newsletter_text'  => trim((string) ($_POST['footer_newsletter_text'] ?? '')),
        'privacy_label'    => trim((string) ($_POST['footer_privacy_label'] ?? '')),
        'privacy_url'      => trim((string) ($_POST['footer_privacy_url'] ?? '')),
        'terms_label'      => trim((string) ($_POST['footer_terms_label'] ?? '')),
        'terms_url'        => trim((string) ($_POST['footer_terms_url'] ?? '')),
        'socials'          => [],
    ];
    foreach (($_POST['social_label'] ?? []) as $i => $label) {
        $label = trim((string) $label);
        if ($label !== '') {
            $data['footer']['socials'][] = [
                'type'  => trim((string) ($_POST['social_type'][$i] ?? 'instagram')),
                'label' => $label,
                'url'   => trim((string) ($_POST['social_url'][$i] ?? '#')),
            ];
        }
    }

    save_site_data($data);
    header('Location: index.php?saved=1');
    exit;
}

/* ══════════════════════════════════════════════
   DATA FOR RENDERING
══════════════════════════════════════════════ */
$data        = site_data();
$contacts    = array_reverse(read_json_list(CONTACT_DATA_FILE));
$newsletter  = array_reverse(read_json_list(NEWSLETTER_DATA_FILE));
$saved       = isset($_GET['saved']);

$iconOptions    = ['strategy', 'branding', 'megaphone', 'box', 'chart', 'code'];
$contactIcons   = ['phone', 'mail', 'pin'];
$socialTypes    = ['linkedin', 'instagram', 'facebook', 'behance'];

$navItems       = $data['nav'] ?? [];
$statItems      = $data['stats']['items'] ?? [];
$serviceItems   = $data['services']['items'] ?? [];
$portfolioItems = $data['portfolio']['items'] ?? [];
$testimonials   = $data['testimonials']['items'] ?? [];
$clientItems    = $data['clients']['items'] ?? [];
$contactInfo    = $data['contact']['info'] ?? [];
$socials        = $data['footer']['socials'] ?? [];

/* helper: option list */
function option_list(array $opts, string $sel): void
{
    foreach ($opts as $o) {
        echo '<option value="' . e($o) . '"' . ($sel === $o ? ' selected' : '') . '>' . e($o) . '</option>';
    }
}

/* helper: panel header */
function panel_header(string $icon_svg, string $title, string $count = ''): void
{
    echo '<div class="panel-header" onclick="togglePanel(this)">'
        . '<span class="panel-icon">' . $icon_svg . '</span>'
        . '<span class="panel-title">' . e($title) . '</span>'
        . ($count !== '' ? '<span class="panel-count">(' . e($count) . ')</span>' : '')
        . '<svg class="panel-chevron" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M2 4 L6 8 L10 4"/></svg>'
        . '</div>';
}

/* icon SVGs for sidebar / panel headers — explicit 13×13 so they stay small */
$s = 'width="13" height="13"';
$ico = [
    'site'     => "<svg $s viewBox=\"0 0 16 16\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\"><circle cx=\"8\" cy=\"8\" r=\"6\"/><path d=\"M8 2v12M2 8h12\"/><path d=\"M3.5 4.5C5 6 7 7 8 8M12.5 4.5C11 6 9 7 8 8\"/></svg>",
    'nav'      => "<svg $s viewBox=\"0 0 16 16\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.6\" stroke-linecap=\"round\"><path d=\"M2 4h12M2 8h12M2 12h8\"/></svg>",
    'hero'     => "<svg $s viewBox=\"0 0 16 16\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\"><path d=\"M8 2L9.8 6.2H14.4L10.6 9L12 13.3L8 10.6L4 13.3L5.4 9L1.6 6.2H6.2Z\"/></svg>",
    'stats'    => "<svg $s viewBox=\"0 0 16 16\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\"><path d=\"M2 14h12\"/><rect x=\"3\" y=\"9\" width=\"2.5\" height=\"5\"/><rect x=\"6.75\" y=\"6\" width=\"2.5\" height=\"8\"/><rect x=\"10.5\" y=\"3\" width=\"2.5\" height=\"11\"/></svg>",
    'services' => "<svg $s viewBox=\"0 0 16 16\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\"><rect x=\"2\" y=\"2\" width=\"5\" height=\"5\" rx=\"1\"/><rect x=\"9\" y=\"2\" width=\"5\" height=\"5\" rx=\"1\"/><rect x=\"2\" y=\"9\" width=\"5\" height=\"5\" rx=\"1\"/><rect x=\"9\" y=\"9\" width=\"5\" height=\"5\" rx=\"1\"/></svg>",
    'portfolio'=> "<svg $s viewBox=\"0 0 16 16\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\"><rect x=\"2\" y=\"3\" width=\"12\" height=\"10\" rx=\"1\"/><circle cx=\"5.5\" cy=\"7\" r=\"1.2\"/><path d=\"M2 12l3-3 2.5 2.5L11 8l3 4\"/></svg>",
    'test'     => "<svg $s viewBox=\"0 0 16 16\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\"><path d=\"M3 5.5h1.5M3 8h5M3 10.5h3\"/><path d=\"M2 3h12v8a2 2 0 01-2 2H4a2 2 0 01-2-2V3z\"/></svg>",
    'clients'  => "<svg $s viewBox=\"0 0 16 16\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\"><circle cx=\"6\" cy=\"5\" r=\"2.5\"/><path d=\"M1 13c0-2.5 2.2-4 5-4s5 1.5 5 4\"/><circle cx=\"12\" cy=\"5\" r=\"1.8\"/><path d=\"M14.5 12c0-1.6-1.2-2.7-2.5-3\"/></svg>",
    'contact'  => "<svg $s viewBox=\"0 0 16 16\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\"><rect x=\"2\" y=\"3\" width=\"12\" height=\"10\" rx=\"1\"/><path d=\"M2 4l6 5 6-5\"/></svg>",
    'footer'   => "<svg $s viewBox=\"0 0 16 16\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\"><rect x=\"2\" y=\"2\" width=\"12\" height=\"12\" rx=\"1\"/><path d=\"M2 11h12\"/><path d=\"M5 14v-3M11 14v-3\"/></svg>",
    'messages' => "<svg $s viewBox=\"0 0 16 16\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\"><path d=\"M2 13V5a1 1 0 011-1h10a1 1 0 011 1v6a1 1 0 01-1 1H5L2 13z\"/><path d=\"M5 7h6M5 9.5h3\"/></svg>",
    'settings' => "<svg $s viewBox=\"0 0 16 16\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\"><rect x=\"5.5\" y=\"2\" width=\"5\" height=\"3\" rx=\"1\"/><rect x=\"5.5\" y=\"11\" width=\"5\" height=\"3\" rx=\"1\"/><path d=\"M8 5v6\"/><path d=\"M4.5 8H2\"/><path d=\"M14 8h-2.5\"/><circle cx=\"8\" cy=\"8\" r=\"2\"/></svg>",
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gildana CMS</title>
<link rel="stylesheet" href="admin.css">
</head>
<body>

<!-- ── TOPBAR ── -->
<header class="topbar">
  <div class="topbar-brand">
    <span class="brand-mark">GILDANA</span>
    <span class="topbar-sub">CMS</span>
  </div>
  <nav class="topbar-nav">
    <a class="btn-top" href="../index.php" target="_blank">
      <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M5 2H2a1 1 0 00-1 1v7a1 1 0 001 1h7a1 1 0 001-1V7"/><path d="M8 1h3v3"/><path d="M5 7L11 1"/></svg>
      View Site
    </a>
    <a class="btn-top gold" href="index.php?logout=1">Logout</a>
  </nav>
</header>

<!-- ── DASHBOARD ── -->
<div class="dashboard">
  <div class="dash-card">
    <span class="dash-lbl">Contacts</span>
    <span class="dash-val <?= count($contacts) > 0 ? 'accent' : '' ?>"><?= count($contacts) ?></span>
    <span class="dash-sub">unread messages</span>
  </div>
  <div class="dash-card">
    <span class="dash-lbl">Newsletter</span>
    <span class="dash-val"><?= count($newsletter) ?></span>
    <span class="dash-sub">subscribers</span>
  </div>
  <div class="dash-card">
    <span class="dash-lbl">Portfolio</span>
    <span class="dash-val"><?= count($portfolioItems) ?></span>
    <span class="dash-sub">case studies</span>
  </div>
  <div class="dash-card">
    <span class="dash-lbl">Services</span>
    <span class="dash-val"><?= count($serviceItems) ?></span>
    <span class="dash-sub">listed</span>
  </div>
  <div class="dash-card">
    <span class="dash-lbl">Last Saved</span>
    <span class="dash-val" style="font-size:15px;padding-top:4px"><?= e(site_last_saved()) ?></span>
    <span class="dash-sub">site content</span>
  </div>
</div>

<?php if ($saved): ?>
  <div style="padding:0 22px;max-width:1600px;margin:16px auto 0">
    <div class="alert success">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 8l3.5 3.5L13 5"/></svg>
      Changes saved successfully.
    </div>
  </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════
     LAYOUT WRAPPER  (not a form — sidebar save button targets #cms-form via form= attribute)
═══════════════════════════════════════════ -->
<div class="cms-layout">

  <!-- ── SIDEBAR ── -->
  <aside class="sidebar">
    <div class="sb-group">
      <span class="sb-lbl">Content</span>
      <a class="sb-link" href="#site"><?= $ico['site'] ?> Site Settings</a>
      <a class="sb-link" href="#nav"><?= $ico['nav'] ?> Navigation</a>
      <a class="sb-link" href="#hero"><?= $ico['hero'] ?> Hero</a>
      <a class="sb-link" href="#stats"><?= $ico['stats'] ?> Stats</a>
      <a class="sb-link" href="#services"><?= $ico['services'] ?> Services</a>
      <a class="sb-link" href="#portfolio"><?= $ico['portfolio'] ?> Portfolio</a>
      <a class="sb-link" href="#testimonials"><?= $ico['test'] ?> Testimonials</a>
      <a class="sb-link" href="#clients"><?= $ico['clients'] ?> Clients</a>
      <a class="sb-link" href="#contact"><?= $ico['contact'] ?> Contact</a>
      <a class="sb-link" href="#footer"><?= $ico['footer'] ?> Footer</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-group">
      <span class="sb-lbl">Admin</span>
      <a class="sb-link" href="#messages">
        <?= $ico['messages'] ?> Messages
        <?php if (count($contacts) > 0): ?>
          <span class="badge red"><?= count($contacts) ?></span>
        <?php endif; ?>
      </a>
      <a class="sb-link" href="#settings"><?= $ico['settings'] ?> Settings</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-save">
      <button type="submit" form="cms-form" class="btn-save" id="save-btn">Save All Changes</button>
    </div>
  </aside>

  <!-- ── CONTENT ── -->
  <main class="cms-content">

    <!-- ══ CMS CONTENT FORM (standalone — no nesting) ══ -->
    <form id="cms-form" method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <!-- SITE SETTINGS -->
    <div class="panel" id="site">
      <?php panel_header($ico['site'], 'Site Settings'); ?>
      <div class="panel-body">
        <div class="grid g2">
          <?php text_field('meta_title', 'Browser Title', (string) ($data['meta']['title'] ?? '')); ?>
          <?php text_field('brand_name', 'Brand Name', (string) ($data['brand']['name'] ?? '')); ?>
          <?php text_field('brand_tagline', 'Brand Tagline', (string) ($data['brand']['tagline'] ?? '')); ?>
        </div>
        <div class="mt14">
          <label>
            <span class="lbl">Meta Description <span id="meta-desc-count" class="char-count">0/160</span></span>
            <textarea name="meta_description" id="meta_description" rows="3" oninput="countChars(this,'meta-desc-count',160)"><?= e((string) ($data['meta']['description'] ?? '')) ?></textarea>
          </label>
        </div>
      </div>
    </div>

    <!-- NAVIGATION -->
    <?php $navNext = count($navItems); ?>
    <div class="panel" id="nav">
      <?php panel_header($ico['nav'], 'Navigation', count($navItems) . ' items'); ?>
      <div class="panel-body">
        <div class="rows-wrap" id="nav-rows" data-next-idx="<?= $navNext ?>">
          <?php foreach ($navItems as $i => $item): ?>
            <div class="row-item rw-nav" data-row>
              <div class="row-hd">
                <span class="row-num">Item <?= $i + 1 ?></span>
                <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
              </div>
              <?php text_field("nav_label[$i]", 'Label', (string) ($item['label'] ?? '')); ?>
              <?php text_field("nav_url[$i]", 'URL', (string) ($item['url'] ?? '')); ?>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn-add-row" onclick="addNavRow()">+ Add Nav Item</button>
      </div>
    </div>

    <!-- HERO -->
    <div class="panel" id="hero">
      <?php panel_header($ico['hero'], 'Hero Section'); ?>
      <div class="panel-body">
        <div class="grid g2">
          <?php text_field('hero_eyebrow', 'Eyebrow Label', (string) ($data['hero']['eyebrow'] ?? '')); ?>
          <?php text_field('hero_title', 'Headline', (string) ($data['hero']['title'] ?? '')); ?>
          <?php text_field('hero_title_gold', 'Gold Headline (2nd line)', (string) ($data['hero']['title_gold'] ?? '')); ?>
          <?php text_field('hero_image_alt', 'Image Alt Text', (string) ($data['hero']['image_alt'] ?? '')); ?>
          <?php text_field('hero_primary_label', 'Primary Button Label', (string) ($data['hero']['primary_label'] ?? '')); ?>
          <?php text_field('hero_primary_url', 'Primary Button URL', (string) ($data['hero']['primary_url'] ?? '')); ?>
          <?php text_field('hero_secondary_label', 'Secondary Button Label', (string) ($data['hero']['secondary_label'] ?? '')); ?>
          <?php text_field('hero_secondary_url', 'Secondary Button URL', (string) ($data['hero']['secondary_url'] ?? '')); ?>
        </div>
        <div class="mt14">
          <?php textarea_field('hero_lede', 'Hero Paragraph', (string) ($data['hero']['lede'] ?? '')); ?>
        </div>
        <div class="mt14">
          <?php textarea_field('hero_tags', 'Strategy Card Tags — one per line', implode("\n", $data['hero']['tags'] ?? [])); ?>
        </div>
        <div class="mt14">
          <input type="hidden" name="hero_image_current" value="<?= e((string) ($data['hero']['image'] ?? '')) ?>">
          <?php image_field('hero_image', 'Hero Image', (string) ($data['hero']['image'] ?? '')); ?>
        </div>
      </div>
    </div>

    <!-- STATS -->
    <?php $statNext = count($statItems); ?>
    <div class="panel" id="stats">
      <?php panel_header($ico['stats'], 'Stats Section', count($statItems) . ' stats'); ?>
      <div class="panel-body">
        <div class="grid g3">
          <?php text_field('stats_eyebrow', 'Eyebrow', (string) ($data['stats']['eyebrow'] ?? '')); ?>
          <?php text_field('stats_cta_label', 'CTA Label', (string) ($data['stats']['cta_label'] ?? '')); ?>
          <?php text_field('stats_cta_url', 'CTA URL', (string) ($data['stats']['cta_url'] ?? '')); ?>
        </div>
        <div class="rows-wrap mt14" id="stats-rows" data-next-idx="<?= $statNext ?>">
          <?php foreach ($statItems as $i => $item): ?>
            <div class="row-item rw-stat" data-row>
              <div class="row-hd" style="grid-column:1/-1">
                <span class="row-num">Stat <?= $i + 1 ?></span>
                <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
              </div>
              <?php text_field("stat_number[$i]", 'Number', (string) ($item['number'] ?? '')); ?>
              <?php text_field("stat_suffix[$i]", 'Suffix', (string) ($item['suffix'] ?? '')); ?>
              <?php text_field("stat_label[$i]", 'Label', (string) ($item['label'] ?? '')); ?>
              <?php text_field("stat_sub[$i]", 'Sub-text', (string) ($item['sub'] ?? '')); ?>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn-add-row" onclick="addStatRow()">+ Add Stat</button>
      </div>
    </div>

    <!-- SERVICES -->
    <?php $svcNext = count($serviceItems); ?>
    <div class="panel" id="services">
      <?php panel_header($ico['services'], 'Services', count($serviceItems) . ' services'); ?>
      <div class="panel-body">
        <div class="grid g3">
          <?php text_field('services_eyebrow', 'Eyebrow', (string) ($data['services']['eyebrow'] ?? '')); ?>
          <?php text_field('services_title', 'Title', (string) ($data['services']['title'] ?? '')); ?>
          <?php text_field('services_title_gold', 'Gold Title', (string) ($data['services']['title_gold'] ?? '')); ?>
        </div>
        <div class="rows-wrap mt14" id="services-rows" data-next-idx="<?= $svcNext ?>">
          <?php foreach ($serviceItems as $i => $item): ?>
            <div class="row-item rw-svc" data-row>
              <div class="row-hd" style="grid-column:1/-1">
                <span class="row-num">Service <?= $i + 1 ?></span>
                <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
              </div>
              <label>
                <span class="lbl">Icon</span>
                <select name="service_icon[<?= $i ?>]">
                  <?php option_list($iconOptions, (string) ($item['icon'] ?? 'strategy')); ?>
                </select>
              </label>
              <?php text_field("service_name[$i]", 'Name', (string) ($item['name'] ?? '')); ?>
              <?php textarea_field("service_desc[$i]", 'Description', (string) ($item['desc'] ?? ''), 2); ?>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn-add-row" onclick="addServiceRow()">+ Add Service</button>
      </div>
    </div>

    <!-- PORTFOLIO -->
    <?php $caseNext = count($portfolioItems); ?>
    <div class="panel" id="portfolio">
      <?php panel_header($ico['portfolio'], 'Portfolio / Case Studies', count($portfolioItems) . ' cases'); ?>
      <div class="panel-body">
        <div class="grid g4">
          <?php text_field('portfolio_eyebrow', 'Eyebrow', (string) ($data['portfolio']['eyebrow'] ?? '')); ?>
          <?php text_field('portfolio_title', 'Section Title', (string) ($data['portfolio']['title'] ?? '')); ?>
          <?php text_field('portfolio_view_all_label', 'View All Label', (string) ($data['portfolio']['view_all_label'] ?? '')); ?>
          <?php text_field('portfolio_view_all_url', 'View All URL', (string) ($data['portfolio']['view_all_url'] ?? '')); ?>
        </div>
        <div class="rows-wrap mt14" id="portfolio-rows" data-next-idx="<?= $caseNext ?>">
          <?php foreach ($portfolioItems as $i => $item): ?>
            <div class="row-item rw-case" data-row>
              <div class="row-hd" style="grid-column:1/-1">
                <span class="row-num">Case Study <?= $i + 1 ?></span>
                <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
              </div>
              <div>
                <input type="hidden" name="case_image_current[<?= $i ?>]" value="<?= e((string) ($item['image'] ?? '')) ?>">
                <?php image_field('case_image_' . $i, 'Image', (string) ($item['image'] ?? '')); ?>
              </div>
              <?php text_field("case_cat[$i]", 'Category', (string) ($item['cat'] ?? '')); ?>
              <?php text_field("case_title[$i]", 'Title', (string) ($item['title'] ?? '')); ?>
              <?php text_field("case_pct[$i]", 'Metric %', (string) ($item['pct'] ?? '')); ?>
              <?php text_field("case_label[$i]", 'Metric Label', (string) ($item['label'] ?? '')); ?>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn-add-row" onclick="addCaseRow()">+ Add Case Study</button>
      </div>
    </div>

    <!-- TESTIMONIALS -->
    <?php $testNext = count($testimonials); ?>
    <div class="panel" id="testimonials">
      <?php panel_header($ico['test'], 'Testimonials', count($testimonials) . ' items'); ?>
      <div class="panel-body">
        <?php text_field('testimonials_eyebrow', 'Eyebrow', (string) ($data['testimonials']['eyebrow'] ?? '')); ?>
        <div class="rows-wrap mt14" id="testimonials-rows" data-next-idx="<?= $testNext ?>">
          <?php foreach ($testimonials as $i => $item): ?>
            <div class="row-item rw-test" data-row>
              <div class="row-hd" style="grid-column:1/-1">
                <span class="row-num">Testimonial <?= $i + 1 ?></span>
                <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
              </div>
              <?php textarea_field("testimonial_quote[$i]", 'Quote', (string) ($item['quote'] ?? ''), 3); ?>
              <?php text_field("testimonial_who[$i]", 'Name', (string) ($item['who'] ?? '')); ?>
              <?php text_field("testimonial_role[$i]", 'Role / Company', (string) ($item['role'] ?? '')); ?>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn-add-row" onclick="addTestimonialRow()">+ Add Testimonial</button>
      </div>
    </div>

    <!-- CLIENTS -->
    <?php $cliNext = count($clientItems); ?>
    <div class="panel" id="clients">
      <?php panel_header($ico['clients'], 'Client Logos', count($clientItems) . ' clients'); ?>
      <div class="panel-body">
        <?php text_field('clients_eyebrow', 'Eyebrow', (string) ($data['clients']['eyebrow'] ?? '')); ?>
        <div class="rows-wrap mt14" id="clients-rows" data-next-idx="<?= $cliNext ?>">
          <?php foreach ($clientItems as $i => $item): ?>
            <div class="row-item rw-cli" data-row>
              <div class="row-hd" style="grid-column:1/-1">
                <span class="row-num">Client <?= $i + 1 ?></span>
                <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
              </div>
              <div>
                <input type="hidden" name="client_image_current[<?= $i ?>]" value="<?= e((string) ($item['image'] ?? '')) ?>">
                <?php image_field('client_image_' . $i, 'Logo', (string) ($item['image'] ?? '')); ?>
              </div>
              <?php text_field("client_alt[$i]", 'Client Name / Alt Text', (string) ($item['alt'] ?? '')); ?>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn-add-row" onclick="addClientRow()">+ Add Client</button>
      </div>
    </div>

    <!-- CONTACT -->
    <?php $ciNext = count($contactInfo); ?>
    <div class="panel" id="contact">
      <?php panel_header($ico['contact'], 'Contact Section'); ?>
      <div class="panel-body">
        <div class="grid g2">
          <?php text_field('contact_eyebrow', 'Eyebrow', (string) ($data['contact']['eyebrow'] ?? '')); ?>
          <?php textarea_field('contact_title', 'Title', (string) ($data['contact']['title'] ?? ''), 2); ?>
          <?php textarea_field('contact_lede', 'Paragraph', (string) ($data['contact']['lede'] ?? ''), 2); ?>
          <?php textarea_field('contact_success', 'Success Message', (string) ($data['contact']['success'] ?? ''), 2); ?>
        </div>
        <div class="rows-wrap mt14" id="contact-info-rows" data-next-idx="<?= $ciNext ?>">
          <?php foreach ($contactInfo as $i => $item): ?>
            <div class="row-item rw-ci" data-row>
              <div class="row-hd" style="grid-column:1/-1">
                <span class="row-num">Info Item <?= $i + 1 ?></span>
                <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
              </div>
              <label>
                <span class="lbl">Icon</span>
                <select name="contact_icon[<?= $i ?>]">
                  <?php option_list($contactIcons, (string) ($item['icon'] ?? 'phone')); ?>
                </select>
              </label>
              <?php text_field("contact_text[$i]", 'Text', (string) ($item['text'] ?? '')); ?>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn-add-row" onclick="addContactInfoRow()">+ Add Contact Info</button>
      </div>
    </div>

    <!-- FOOTER -->
    <?php $socNext = count($socials); ?>
    <div class="panel" id="footer">
      <?php panel_header($ico['footer'], 'Footer'); ?>
      <div class="panel-body">
        <div class="grid g2">
          <?php text_field('footer_copyright', 'Copyright Text', (string) ($data['footer']['copyright'] ?? '')); ?>
          <?php text_field('footer_follow_title', 'Follow Us Title', (string) ($data['footer']['follow_title'] ?? '')); ?>
          <?php text_field('footer_newsletter_title', 'Newsletter Title', (string) ($data['footer']['newsletter_title'] ?? '')); ?>
          <?php textarea_field('footer_newsletter_text', 'Newsletter Sub-text', (string) ($data['footer']['newsletter_text'] ?? ''), 2); ?>
          <?php text_field('footer_privacy_label', 'Privacy Policy Label', (string) ($data['footer']['privacy_label'] ?? '')); ?>
          <?php text_field('footer_privacy_url', 'Privacy Policy URL', (string) ($data['footer']['privacy_url'] ?? '')); ?>
          <?php text_field('footer_terms_label', 'Terms Label', (string) ($data['footer']['terms_label'] ?? '')); ?>
          <?php text_field('footer_terms_url', 'Terms URL', (string) ($data['footer']['terms_url'] ?? '')); ?>
        </div>
        <div class="rows-wrap mt14" id="socials-rows" data-next-idx="<?= $socNext ?>">
          <?php foreach ($socials as $i => $item): ?>
            <div class="row-item rw-soc" data-row>
              <div class="row-hd" style="grid-column:1/-1">
                <span class="row-num">Social <?= $i + 1 ?></span>
                <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
              </div>
              <label>
                <span class="lbl">Platform</span>
                <select name="social_type[<?= $i ?>]">
                  <?php option_list($socialTypes, (string) ($item['type'] ?? 'instagram')); ?>
                </select>
              </label>
              <?php text_field("social_label[$i]", 'Label', (string) ($item['label'] ?? '')); ?>
              <?php text_field("social_url[$i]", 'URL', (string) ($item['url'] ?? '')); ?>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn-add-row" onclick="addSocialRow()">+ Add Social Link</button>
      </div>
    </div>

    </form><!-- /cms-form -->

    <!-- ══ MESSAGES (AJAX — outside cms-form) ══ -->
    <div class="panel" id="messages">
      <?php panel_header($ico['messages'], 'Messages', count($contacts) . ' contacts · ' . count($newsletter) . ' subscribers'); ?>
      <div class="panel-body">
        <div class="msg-layout">

          <!-- Contacts -->
          <div>
            <div class="msg-col-head">
              <span>Contact Requests (<?= count($contacts) ?>)</span>
            </div>
            <?php if (!$contacts): ?>
              <div class="empty">No contact messages yet.</div>
            <?php endif; ?>
            <?php foreach (array_slice($contacts, 0, 30) as $idx => $row): ?>
              <div class="message-card" id="contact-<?= $idx ?>">
                <div class="mc-top">
                  <div class="mc-meta">
                    <span class="mc-name"><?= e($row['name'] ?? '') ?></span>
                    <span class="mc-detail"><?= e($row['email'] ?? '') ?><?php if (!empty($row['phone'])): ?> &middot; <?= e($row['phone']) ?><?php endif; ?></span>
                    <span class="mc-detail"><?= e($row['created_at'] ?? '') ?></span>
                    <?php if (!empty($row['company'])): ?>
                      <span class="mc-tag"><?= e($row['company']) ?></span>
                    <?php endif; ?>
                  </div>
                  <button type="button" class="btn-del-msg" title="Delete"
                    onclick="deleteContact(this,<?= $idx ?>)">&#x2715;</button>
                </div>
                <?php if (!empty($row['message'])): ?>
                  <div class="mc-body"><?= nl2br(e($row['message'])) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Newsletter -->
          <div>
            <div class="msg-col-head">
              <span>Newsletter (<?= count($newsletter) ?>)</span>
              <?php if ($newsletter): ?>
                <a class="btn-export" href="index.php?action=export_newsletter">
                  <svg width="11" height="11" viewBox="0 0 11 11" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M5.5 1v6M2.5 5l3 3 3-3"/><path d="M1 9h9"/></svg>
                  CSV
                </a>
              <?php endif; ?>
            </div>
            <?php if (!$newsletter): ?>
              <div class="empty">No newsletter signups yet.</div>
            <?php endif; ?>
            <?php foreach (array_slice($newsletter, 0, 50) as $idx => $row): ?>
              <div class="nl-item" id="nl-<?= $idx ?>">
                <div>
                  <div class="nl-email"><?= e($row['email'] ?? '') ?></div>
                  <div class="nl-date"><?= e($row['created_at'] ?? '') ?></div>
                </div>
                <button type="button" class="btn-del-msg" title="Remove"
                  onclick="deleteNewsletter(this,'<?= e(addslashes($row['email'] ?? '')) ?>')">&#x2715;</button>
              </div>
            <?php endforeach; ?>
          </div>

        </div>
      </div>
    </div>

    <!-- ══ SETTINGS ══ -->
    <div class="panel" id="settings">
      <?php panel_header($ico['settings'], 'Admin Settings'); ?>
      <div class="panel-body">
        <?php if ($pw_error): ?>
          <div class="alert error"><?= e($pw_error) ?></div>
        <?php endif; ?>
        <?php if ($pw_success): ?>
          <div class="alert success"><?= e($pw_success) ?></div>
        <?php endif; ?>
        <p class="muted text-sm" style="margin-bottom:16px">Change the admin panel password. Minimum 8 characters.</p>
        <form class="pw-form" method="post">
          <input type="hidden" name="action" value="change_password">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <?php text_field('current_password', 'Current Password', '', 'password'); ?>
          <?php text_field('new_password', 'New Password (min 8 chars)', '', 'password'); ?>
          <?php text_field('confirm_password', 'Confirm New Password', '', 'password'); ?>
          <div>
            <button type="submit" class="btn-save" style="max-width:220px">Change Password</button>
          </div>
        </form>
      </div>
    </div>

  </main>
</div><!-- /cms-layout -->

<!-- ── TOAST ── -->
<div class="toast" id="toast"></div>

<!-- ══════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════ -->
<script>
const CSRF = <?= json_encode(csrf_token()) ?>;

/* ── unsaved changes ── */
let unsaved = false;
function markUnsaved() {
  unsaved = true;
  document.getElementById('save-btn').classList.add('unsaved');
}
document.getElementById('cms-form').addEventListener('input', markUnsaved);
document.getElementById('cms-form').addEventListener('change', markUnsaved);
document.getElementById('cms-form').addEventListener('submit', () => { unsaved = false; });
window.addEventListener('beforeunload', e => {
  if (unsaved) { e.preventDefault(); e.returnValue = ''; }
});

/* ── toast ── */
function showToast(msg, err) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show' + (err ? ' err' : '');
  clearTimeout(t._tid);
  t._tid = setTimeout(() => t.classList.remove('show'), 3200);
}

<?php if ($saved): ?>showToast('Changes saved successfully.');<?php endif; ?>

/* ── panel collapse ── */
function togglePanel(header) {
  const panel = header.closest('.panel');
  panel.classList.toggle('collapsed');
  const id = panel.id;
  if (id) sessionStorage.setItem('p:' + id, panel.classList.contains('collapsed') ? '1' : '0');
}
document.querySelectorAll('.panel[id]').forEach(p => {
  if (sessionStorage.getItem('p:' + p.id) === '1') p.classList.add('collapsed');
});

/* ── sidebar active link on scroll ── */
const panels = document.querySelectorAll('.panel[id]');
const sbLinks = document.querySelectorAll('.sb-link[href^="#"]');
const io = new IntersectionObserver(entries => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      sbLinks.forEach(l => l.classList.toggle('active', l.getAttribute('href') === '#' + e.target.id));
    }
  });
}, { rootMargin: '-20% 0px -70% 0px' });
panels.forEach(p => io.observe(p));

/* ── character counter ── */
function countChars(el, counterId, max) {
  const n = el.value.length;
  const span = document.getElementById(counterId);
  if (!span) return;
  span.textContent = n + '/' + max;
  span.className = 'char-count' + (n > max ? ' over' : n > max * 0.85 ? ' warn' : '');
}
/* init counter for meta description */
(function(){
  const el = document.getElementById('meta_description');
  if (el) countChars(el, 'meta-desc-count', 160);
})();

/* ── instant image preview ── */
function previewImage(input) {
  const wrap = input.closest('label').nextElementSibling;
  if (!wrap || !wrap.classList.contains('image-preview')) return;
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = ev => {
    let img = wrap.querySelector('img');
    if (!img) { img = document.createElement('img'); wrap.innerHTML = ''; wrap.appendChild(img); }
    img.src = ev.target.result;
    let sm = wrap.querySelector('.img-path');
    if (!sm) { sm = document.createElement('small'); sm.className = 'img-path'; wrap.appendChild(sm); }
    sm.textContent = file.name;
  };
  reader.readAsDataURL(file);
  markUnsaved();
}

/* ── remove row ── */
function removeRow(btn) {
  if (!confirm('Remove this item?')) return;
  btn.closest('[data-row]').remove();
  markUnsaved();
}

/* ── add row helpers ── */
function getNextIdx(containerId) {
  const c = document.getElementById(containerId);
  const idx = parseInt(c.dataset.nextIdx || '0', 10);
  c.dataset.nextIdx = idx + 1;
  return idx;
}
function appendRow(containerId, html) {
  document.getElementById(containerId).insertAdjacentHTML('beforeend', html);
  markUnsaved();
}

/* ── row templates ── */
function addNavRow() {
  const i = getNextIdx('nav-rows');
  appendRow('nav-rows', `
    <div class="row-item rw-nav" data-row>
      <div class="row-hd">
        <span class="row-num">Nav Item</span>
        <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
      </div>
      <label><span class="lbl">Label</span><input type="text" name="nav_label[${i}]" value=""></label>
      <label><span class="lbl">URL</span><input type="text" name="nav_url[${i}]" value=""></label>
    </div>`);
}

function addStatRow() {
  const i = getNextIdx('stats-rows');
  appendRow('stats-rows', `
    <div class="row-item rw-stat" data-row>
      <div class="row-hd" style="grid-column:1/-1">
        <span class="row-num">Stat</span>
        <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
      </div>
      <label><span class="lbl">Number</span><input type="text" name="stat_number[${i}]" value=""></label>
      <label><span class="lbl">Suffix</span><input type="text" name="stat_suffix[${i}]" value=""></label>
      <label><span class="lbl">Label</span><input type="text" name="stat_label[${i}]" value=""></label>
      <label><span class="lbl">Sub-text</span><input type="text" name="stat_sub[${i}]" value=""></label>
    </div>`);
}

function addServiceRow() {
  const i = getNextIdx('services-rows');
  appendRow('services-rows', `
    <div class="row-item rw-svc" data-row>
      <div class="row-hd" style="grid-column:1/-1">
        <span class="row-num">Service</span>
        <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
      </div>
      <label><span class="lbl">Icon</span>
        <select name="service_icon[${i}]">
          <option value="strategy">strategy</option>
          <option value="branding">branding</option>
          <option value="megaphone">megaphone</option>
          <option value="box">box</option>
          <option value="chart">chart</option>
          <option value="code">code</option>
        </select>
      </label>
      <label><span class="lbl">Name</span><input type="text" name="service_name[${i}]" value=""></label>
      <label><span class="lbl">Description</span><textarea name="service_desc[${i}]" rows="2"></textarea></label>
    </div>`);
}

function addCaseRow() {
  const i = getNextIdx('portfolio-rows');
  appendRow('portfolio-rows', `
    <div class="row-item rw-case" data-row>
      <div class="row-hd" style="grid-column:1/-1">
        <span class="row-num">Case Study</span>
        <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
      </div>
      <div>
        <input type="hidden" name="case_image_current[${i}]" value="">
        <label class="file-label"><span class="lbl">Image</span>
          <input type="file" name="case_image_${i}" accept="image/*" onchange="previewImage(this)">
        </label>
        <div class="image-preview"></div>
      </div>
      <label><span class="lbl">Category</span><input type="text" name="case_cat[${i}]" value=""></label>
      <label><span class="lbl">Title</span><input type="text" name="case_title[${i}]" value=""></label>
      <label><span class="lbl">Metric %</span><input type="text" name="case_pct[${i}]" value=""></label>
      <label><span class="lbl">Metric Label</span><input type="text" name="case_label[${i}]" value=""></label>
    </div>`);
}

function addTestimonialRow() {
  const i = getNextIdx('testimonials-rows');
  appendRow('testimonials-rows', `
    <div class="row-item rw-test" data-row>
      <div class="row-hd" style="grid-column:1/-1">
        <span class="row-num">Testimonial</span>
        <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
      </div>
      <label><span class="lbl">Quote</span><textarea name="testimonial_quote[${i}]" rows="3"></textarea></label>
      <label><span class="lbl">Name</span><input type="text" name="testimonial_who[${i}]" value=""></label>
      <label><span class="lbl">Role / Company</span><input type="text" name="testimonial_role[${i}]" value=""></label>
    </div>`);
}

function addClientRow() {
  const i = getNextIdx('clients-rows');
  appendRow('clients-rows', `
    <div class="row-item rw-cli" data-row>
      <div class="row-hd" style="grid-column:1/-1">
        <span class="row-num">Client</span>
        <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
      </div>
      <div>
        <input type="hidden" name="client_image_current[${i}]" value="">
        <label class="file-label"><span class="lbl">Logo</span>
          <input type="file" name="client_image_${i}" accept="image/*" onchange="previewImage(this)">
        </label>
        <div class="image-preview"></div>
      </div>
      <label><span class="lbl">Client Name / Alt Text</span><input type="text" name="client_alt[${i}]" value=""></label>
    </div>`);
}

function addContactInfoRow() {
  const i = getNextIdx('contact-info-rows');
  appendRow('contact-info-rows', `
    <div class="row-item rw-ci" data-row>
      <div class="row-hd" style="grid-column:1/-1">
        <span class="row-num">Info Item</span>
        <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
      </div>
      <label><span class="lbl">Icon</span>
        <select name="contact_icon[${i}]">
          <option value="phone">phone</option>
          <option value="mail">mail</option>
          <option value="pin">pin</option>
        </select>
      </label>
      <label><span class="lbl">Text</span><input type="text" name="contact_text[${i}]" value=""></label>
    </div>`);
}

function addSocialRow() {
  const i = getNextIdx('socials-rows');
  appendRow('socials-rows', `
    <div class="row-item rw-soc" data-row>
      <div class="row-hd" style="grid-column:1/-1">
        <span class="row-num">Social</span>
        <button type="button" class="btn-remove" onclick="removeRow(this)">&#x2715; Remove</button>
      </div>
      <label><span class="lbl">Platform</span>
        <select name="social_type[${i}]">
          <option value="linkedin">linkedin</option>
          <option value="instagram">instagram</option>
          <option value="facebook">facebook</option>
          <option value="behance">behance</option>
        </select>
      </label>
      <label><span class="lbl">Label</span><input type="text" name="social_label[${i}]" value=""></label>
      <label><span class="lbl">URL</span><input type="text" name="social_url[${i}]" value=""></label>
    </div>`);
}

/* ── delete messages (AJAX) ── */
async function deleteContact(btn, index) {
  if (!confirm('Delete this contact message?')) return;
  const fd = new FormData();
  fd.append('action', 'delete_contact');
  fd.append('index', index);
  fd.append('fetch', '1');
  fd.append('csrf_token', CSRF);
  try {
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) {
      btn.closest('.message-card').remove();
      showToast('Contact deleted.');
    } else {
      showToast('Could not delete.', true);
    }
  } catch(e) { showToast('Network error.', true); }
}

async function deleteNewsletter(btn, email) {
  if (!confirm('Remove ' + email + ' from newsletter?')) return;
  const fd = new FormData();
  fd.append('action', 'delete_newsletter');
  fd.append('email', email);
  fd.append('fetch', '1');
  fd.append('csrf_token', CSRF);
  try {
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) {
      btn.closest('.nl-item').remove();
      showToast('Subscriber removed.');
    } else {
      showToast('Could not remove.', true);
    }
  } catch(e) { showToast('Network error.', true); }
}
</script>
</body>
</html>
