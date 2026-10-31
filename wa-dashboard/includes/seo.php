<?php
declare(strict_types=1);

/**
 * Being found: by search engines and by AI assistants.
 *
 * Everything here is set in Admin → SEO and ships with defaults worth keeping, so a fresh install
 * is already well described: titles and descriptions, social cards, structured data (who we are,
 * what the product is, the FAQ), a sitemap, a robots.txt that welcomes AI search engines, and an
 * llms.txt that tells AI assistants what the product does in plain words.
 *
 * Public pages call seo_head(); signed-in pages are marked noindex in layout_header().
 */
require_once __DIR__ . '/help.php';

/** The settings, their defaults, and what each is for (shown in Admin → SEO). */
function seo_fields(): array
{
    $b = brand_name();
    return [
        'seo_index'        => ['1', 'Let search engines index the site'],
        'seo_site_url'     => ['', 'Site address'],
        'seo_title'        => [$b . ' — WhatsApp CRM, Campaigns & AI Lead Qualification', 'Title'],
        'seo_description'  => [$b . ' is a WhatsApp CRM for sales teams: bulk campaigns, no-code automations, an AI chat agent and real-estate lead scoring. Official API or your own number.', 'Description'],
        'seo_keywords'     => ['WhatsApp CRM, WhatsApp Business API, WhatsApp marketing, WhatsApp bulk messaging, WhatsApp automation, WhatsApp chatbot, AI sales agent, AI lead qualification, lead scoring, real estate CRM, real estate CRM Egypt, sales pipeline, Meta Lead Ads CRM, CRM Egypt, CRM Saudi Arabia, واتساب CRM, نظام CRM, برنامج إدارة العملاء, تسويق عبر واتساب, رسائل واتساب جماعية, واتساب بيزنس API, CRM عقارات, تأهيل العملاء بالذكاء الاصطناعي', 'Keywords'],
        'seo_og_image'     => ['assets/og-image.png', 'Share image'],
        'seo_twitter'      => ['', 'X (Twitter) handle'],
        'seo_org_name'     => [BRAND_PARENT, 'Company name'],
        'seo_org_logo'     => ['assets/icons/icon-512.png', 'Logo'],
        'seo_same_as'      => ['', 'Social profiles'],
        'seo_email'        => ['', 'Contact email'],
        'seo_phone'        => ['', 'Contact phone'],
        'seo_areas'        => ['Egypt, Saudi Arabia, United Arab Emirates, Kuwait, Qatar, Bahrain, Oman, Jordan', 'Areas served'],
        'seo_google_verify'=> ['', 'Google Search Console'],
        'seo_bing_verify'  => ['', 'Bing Webmaster Tools'],
        'seo_ga4'          => ['', 'Google Analytics 4'],
        'seo_ai_search'    => ['1', 'AI search and answer engines'],
        'seo_ai_training'  => ['1', 'AI model training'],
        'seo_llms_summary' => [$b . ' (by ' . BRAND_PARENT . ') is a WhatsApp CRM and messaging platform for businesses in Egypt and the Middle East. '
                              . 'It sends WhatsApp campaigns, runs no-code automations, answers customers with an AI agent trained only on the business\'s own information, '
                              . 'qualifies and scores leads (built with property developers for real estate), and gives sales teams a full CRM: pipeline, assignment, follow-ups, site visits and reports. '
                              . 'It works with the official WhatsApp Business API or with a business\'s own number by QR code, in English and Arabic.', 'Summary for AI assistants'],
    ];
}

function seo_get(string $k): string
{
    static $cache = [];
    if (!array_key_exists($k, $cache)) {
        $def = seo_fields()[$k][0] ?? '';
        $cache[$k] = help_setting($k, $def);
    }
    return $cache[$k];
}

/** The site's public address, without a trailing slash. Configured values win over the request's Host. */
function seo_site_url(): string
{
    $u = rtrim(trim(help_setting('seo_site_url', '')), '/');
    if ($u !== '' && preg_match('~^https?://~i', $u)) return $u;
    $c = rtrim((string) config('base_url', ''), '/');
    return $c !== '' ? $c : rtrim(app_base_url(), '/');
}

/** A path on this site, or a full URL, as a full URL. */
function seo_abs(string $u): string
{
    if ($u === '') return '';
    return preg_match('~^https?://~i', $u) ? $u : seo_site_url() . '/' . ltrim($u, '/');
}

/** The pages there are to find, for the sitemap and llms.txt. */
function seo_pages(): array
{
    $b = brand_name();
    $faqTime = '';
    try { $faqTime = (string) db_val("SELECT MAX(COALESCE(updated_at, created_at)) FROM faq_items WHERE status='active'"); } catch (Throwable $e) {}
    $m = fn(string $f) => date('Y-m-d', (int) (@filemtime(dirname(__DIR__) . '/' . $f) ?: time()));
    $later = fn(string $a, string $b) => $b !== '' && substr($b, 0, 10) > $a ? substr($b, 0, 10) : $a;
    return [
        ['path' => '',                'file' => 'index.php',       'title' => $b . ' — WhatsApp CRM, campaigns and AI lead qualification', 'note' => 'What the product does, how it works, the two ways to send, pricing and FAQ.', 'priority' => '1.0', 'freq' => 'weekly',  'mod' => $later($m('index.php'), $faqTime)],
        ['path' => 'help-center.php', 'file' => 'help-center.php', 'title' => $b . ' Help Center — questions and answers',                 'note' => 'Every common question about the product, answered in full.',       'priority' => '0.8', 'freq' => 'weekly',  'mod' => $later($m('help-center.php'), $faqTime)],
        ['path' => 'request.php',     'file' => 'request.php',     'title' => 'Get started with ' . $b,                                  'note' => 'Request an account. A person replies the same working day.',       'priority' => '0.7', 'freq' => 'monthly', 'mod' => $m('request.php')],
    ];
}

/** The product's main abilities, for structured data and llms.txt. */
function seo_features(): array
{
    return [
        'WhatsApp bulk campaigns with delivery and read tracking',
        'No-code automation builder with preview and problem checker',
        'AI chat agent that answers only from the business\'s own knowledge',
        'AI lead qualification and hot / warm / cold lead scoring for real estate',
        'Sales CRM: pipeline board and table, round-robin assignment, follow-ups, site visits',
        'Meta (Facebook and Instagram) Lead Ads, Google Sheets and Excel lead import',
        'Shared team inbox with push notifications on phone and desktop',
        'Sales and marketing reports: funnel, response time, return on ad spend',
        'REST API and webhooks to connect another CRM',
        'Official WhatsApp Business API or your own number by QR code',
        'English and Arabic interface',
    ];
}

/** Structured data: who we are, the site, the product — and the FAQ when the page shows it. */
function seo_jsonld(array $o = []): string
{
    $site = seo_site_url();
    $org = ['@type' => 'Organization', '@id' => $site . '/#org', 'name' => seo_get('seo_org_name') ?: BRAND_PARENT, 'url' => $site,
            'logo' => seo_abs(seo_get('seo_org_logo'))];
    $same = array_values(array_filter(array_map('trim', preg_split('~[\r\n,]+~', seo_get('seo_same_as'))), fn($u) => preg_match('~^https?://~i', $u)));
    if ($same) $org['sameAs'] = $same;
    $cp = array_filter(['@type' => 'ContactPoint', 'contactType' => 'sales', 'email' => seo_get('seo_email'), 'telephone' => seo_get('seo_phone'),
                        'availableLanguage' => ['English', 'Arabic']]);
    if (isset($cp['email']) || isset($cp['telephone'])) $org['contactPoint'] = $cp;
    $areas = array_values(array_filter(array_map('trim', explode(',', seo_get('seo_areas')))));
    if ($areas) $org['areaServed'] = array_map(fn($a) => ['@type' => 'Country', 'name' => $a], $areas);

    $app = ['@type' => 'SoftwareApplication', '@id' => $site . '/#app', 'name' => brand_name(), 'url' => $site,
            'applicationCategory' => 'BusinessApplication', 'applicationSubCategory' => 'CRM', 'operatingSystem' => 'Web, Android, iOS',
            'description' => seo_get('seo_description'), 'featureList' => seo_features(), 'inLanguage' => ['en', 'ar'],
            'publisher' => ['@id' => $site . '/#org'], 'image' => seo_abs(seo_get('seo_og_image'))];
    try {
        $p = db_row("SELECT MIN(price_month) lo, MAX(price_month) hi, COUNT(*) n, MAX(currency) cur FROM plans WHERE is_active=1");
        if ($p && (int) $p['n'] > 0) $app['offers'] = ['@type' => 'AggregateOffer', 'lowPrice' => (string) (float) $p['lo'], 'highPrice' => (string) (float) $p['hi'],
                                                         'priceCurrency' => $p['cur'] ?: 'USD', 'offerCount' => (int) $p['n']];
    } catch (Throwable $e) {}

    $graph = [$org, ['@type' => 'WebSite', '@id' => $site . '/#website', 'url' => $site, 'name' => brand_name(),
                     'description' => seo_get('seo_description'), 'publisher' => ['@id' => $site . '/#org'], 'inLanguage' => 'en'], $app];
    if (!empty($o['faq'])) {
        $graph[] = ['@type' => 'FAQPage', '@id' => seo_abs($o['path'] ?? '') . '#faq', 'mainEntity' => array_map(fn($f) => [
            '@type' => 'Question', 'name' => (string) $f['question'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) $f['answer']]], $o['faq'])];
    }
    if (!empty($o['crumbs'])) {
        $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => seo_abs($c[1])],
                                                                                 $o['crumbs'], array_keys($o['crumbs']))];
    }
    $json = json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // "</script>" inside an answer must not end the block early.
    return '<script type="application/ld+json">' . str_replace('</', '<\/', (string) $json) . '</script>';
}

/**
 * Everything a public page needs in its <head> after the charset and viewport.
 * @param array $o title, description, path (canonical, relative to the site), type, noindex, faq (rows), crumbs ([[name, path]…])
 */
function seo_head(array $o = []): string
{
    $title = (string) ($o['title'] ?? seo_get('seo_title'));
    $desc  = (string) ($o['description'] ?? seo_get('seo_description'));
    $url   = seo_abs((string) ($o['path'] ?? '')) ?: seo_site_url() . '/';
    if (($o['path'] ?? '') === '') $url = seo_site_url() . '/';
    $img   = seo_abs(seo_get('seo_og_image'));
    $index = seo_get('seo_index') === '1' && empty($o['noindex']);
    $tw    = ltrim(seo_get('seo_twitter'), '@');

    $h  = '<title>' . e($title) . "</title>\n";
    $h .= '<meta name="description" content="' . e($desc) . "\">\n";
    if (($k = seo_get('seo_keywords')) !== '') $h .= '<meta name="keywords" content="' . e($k) . "\">\n";
    $h .= '<meta name="robots" content="' . ($index ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1' : 'noindex, nofollow') . "\">\n";
    $h .= '<link rel="canonical" href="' . e($url) . "\">\n";
    $h .= '<link rel="alternate" hreflang="en" href="' . e($url) . "\">\n";
    $h .= '<link rel="alternate" hreflang="x-default" href="' . e($url) . "\">\n";
    $h .= '<meta property="og:site_name" content="' . e(brand_name()) . "\">\n";
    $h .= '<meta property="og:type" content="' . e((string) ($o['type'] ?? 'website')) . "\">\n";
    $h .= '<meta property="og:title" content="' . e($title) . "\">\n";
    $h .= '<meta property="og:description" content="' . e($desc) . "\">\n";
    $h .= '<meta property="og:url" content="' . e($url) . "\">\n";
    $h .= '<meta property="og:locale" content="en_US">' . "\n" . '<meta property="og:locale:alternate" content="ar_EG">' . "\n";
    if ($img !== '') {
        $h .= '<meta property="og:image" content="' . e($img) . "\">\n";
        $h .= '<meta property="og:image:width" content="1200">' . "\n" . '<meta property="og:image:height" content="630">' . "\n";
        $h .= '<meta property="og:image:alt" content="' . e(brand_name() . ' — ' . BRAND_TAGLINE) . "\">\n";
    }
    $h .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
    if ($tw !== '') $h .= '<meta name="twitter:site" content="@' . e($tw) . "\">\n";
    $h .= '<meta name="twitter:title" content="' . e($title) . "\">\n";
    $h .= '<meta name="twitter:description" content="' . e($desc) . "\">\n";
    if ($img !== '') $h .= '<meta name="twitter:image" content="' . e($img) . "\">\n";
    if (($v = seo_get('seo_google_verify')) !== '') $h .= '<meta name="google-site-verification" content="' . e($v) . "\">\n";
    if (($v = seo_get('seo_bing_verify')) !== '')   $h .= '<meta name="msvalidate.01" content="' . e($v) . "\">\n";
    $h .= '<link rel="alternate" type="text/plain" title="LLM summary" href="' . e(seo_site_url() . '/llms.txt') . "\">\n";
    $h .= seo_jsonld($o) . "\n";
    if (preg_match('~^G-[A-Z0-9]{4,16}$~', $ga = strtoupper(seo_get('seo_ga4')))) {
        $h .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $ga . '"></script>'
            . "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . $ga . "');</script>\n";
    }
    return $h . seo_tracking('head');
}

/* ── Tracking & custom code ──────────────────────────────────────────────── */

/**
 * Tracking IDs and free code for the page <head> and <body>, set in Admin → SEO. Shown on the
 * public pages (landing, Help Center, request form); inside the signed-in app only when the admin
 * ticks it. Never on the sign-in and first-run pages, where a third-party script could read the
 * password being typed.
 */
function seo_tracking_ids(): array
{
    return [
        'seo_gtm'          => ['Google Tag Manager', 'GTM-XXXXXXX', '~^GTM-[A-Z0-9]{4,12}$~', 'Tag Manager → the ID at the top of the workspace.'],
        'seo_meta_pixel'   => ['Meta (Facebook) Pixel', '123456789012345', '~^\d{10,20}$~', 'Events Manager → your dataset (pixel) → its ID.'],
        'seo_tiktok_pixel' => ['TikTok Pixel', 'CXXXXXXXXXXXXXXXXXXX', '~^[A-Z0-9]{10,30}$~', 'TikTok Ads Manager → Events → Web events → the pixel ID.'],
        'seo_clarity'      => ['Microsoft Clarity', 'abcd1234ef', '~^[a-z0-9]{6,20}$~', 'Clarity → Settings → Overview → Project ID.'],
        'seo_linkedin'     => ['LinkedIn Insight Tag', '1234567', '~^\d{4,12}$~', 'Campaign Manager → Analyze → Insight Tag → partner ID.'],
    ];
}

/** The custom code boxes: where each goes on the page. */
function seo_code_slots(): array
{
    return [
        'code_head'       => ['Inside <head>', 'Verification tags, tracking scripts, fonts, structured data — anything a service says to put in the head.'],
        'code_body_open'  => ['Right after <body> opens', 'The <noscript> part some services give (Tag Manager, pixels), chat widgets that ask to load first.'],
        'code_body_close' => ['Just before </body>', 'Chat widgets, heat-maps and scripts that should load after the page.'],
    ];
}

/** What goes in one place on a page: 'head', 'body_open' or 'body_close'. $app: a signed-in page. */
function seo_tracking(string $slot, bool $app = false): string
{
    if ($app && help_setting('code_in_app', '0') !== '1') return '';
    $id = function (string $k) { $v = trim(help_setting($k, '')); return preg_match(seo_tracking_ids()[$k][2], $v) ? $v : ''; };
    $h = '';
    if ($slot === 'head') {
        if ($g = $id('seo_gtm')) $h .= "<!-- Google Tag Manager --><script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','$g');</script>\n";
        if ($p = $id('seo_meta_pixel')) $h .= "<!-- Meta Pixel --><script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','$p');fbq('track','PageView');</script>\n";
        if ($t = $id('seo_tiktok_pixel')) $h .= "<!-- TikTok Pixel --><script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=i;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};var o=d.createElement('script');o.type='text/javascript';o.async=!0;o.src=i+'?sdkid='+e+'&lib='+t;var a=d.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};ttq.load('$t');ttq.page();}(window,document,'ttq');</script>\n";
        if ($c = $id('seo_clarity')) $h .= "<!-- Microsoft Clarity --><script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,'clarity','script','$c');</script>\n";
        if ($l = $id('seo_linkedin')) $h .= "<!-- LinkedIn Insight --><script>window._linkedin_partner_id='$l';window._linkedin_data_partner_ids=window._linkedin_data_partner_ids||[];window._linkedin_data_partner_ids.push(window._linkedin_partner_id);(function(l){if(!l){window.lintrk=function(a,b){window.lintrk.q.push([a,b])};window.lintrk.q=[]}var s=document.getElementsByTagName('script')[0];var b=document.createElement('script');b.type='text/javascript';b.async=true;b.src='https://snap.licdn.com/li.lms-analytics/insight.min.js';s.parentNode.insertBefore(b,s);})(window.lintrk);</script>\n";
        $h .= help_setting('code_head', '');
    } elseif ($slot === 'body_open') {
        if ($g = $id('seo_gtm')) $h .= '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . $g . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
        if ($p = $id('seo_meta_pixel')) $h .= '<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=' . $p . '&amp;ev=PageView&amp;noscript=1"></noscript>' . "\n";
        $h .= help_setting('code_body_open', '');
    } elseif ($slot === 'body_close') {
        $h .= help_setting('code_body_close', '');
    }
    return $h !== '' ? "\n" . $h . "\n" : '';
}

/* ── Crawlers ─────────────────────────────────────────────────────────────── */

/** AI crawlers by what they do. Search bots fetch pages to answer a person's question and cite them. */
function seo_ai_bots(): array
{
    return [
        'search'   => ['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User',
                       'DuckAssistBot', 'MistralAI-User', 'YouBot', 'Amazonbot', 'Applebot', 'meta-externalfetcher'],
        'training' => ['GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'meta-externalagent', 'Bytespider', 'cohere-ai'],
    ];
}

/** The parts of the site that are never for crawlers: the app, the API, the hooks. */
function seo_private_paths(): array
{
    // Only what a crawler could reach by following links. Internal folders are refused by the server
    // anyway, and naming them here would only advertise them.
    return ['/admin/', '/client/', '/uploads/', '/api.php', '/crm_hook.php',
            '/webhook.php', '/webhook_leads.php', '/webhook_personal.php', '/google_oauth.php', '/meta_oauth.php', '/push_status.php',
            '/push_subscribe.php', '/prefs.php', '/captcha.php', '/setup.php', '/logout.php', '/help.php'];
}

function seo_robots_txt(): string
{
    $site = seo_site_url();
    $rules = fn(bool $allow) => $allow
        ? "Allow: /\n" . implode('', array_map(fn($p) => "Disallow: $p\n", seo_private_paths()))
        : "Disallow: /\n";
    $out = "# " . brand_name() . " — " . $site . "\n";
    if (seo_get('seo_index') !== '1') return $out . "# Indexing is switched off in Admin → SEO.\nUser-agent: *\nDisallow: /\n";

    $bots = seo_ai_bots();
    $out .= "\nUser-agent: *\n" . $rules(true);
    // A crawler that matches a named group ignores the "*" group, so each group repeats the rules.
    $out .= "\n# AI search and answer engines — they fetch pages to answer people and link back.\n"
          . implode('', array_map(fn($b) => "User-agent: $b\n", $bots['search'])) . $rules(seo_get('seo_ai_search') === '1');
    $out .= "\n# AI model training.\n"
          . implode('', array_map(fn($b) => "User-agent: $b\n", $bots['training'])) . $rules(seo_get('seo_ai_training') === '1');
    $out .= "\nSitemap: $site/sitemap.xml\n";
    $out .= "# A plain-language summary for AI assistants: $site/llms.txt\n";
    return $out;
}

function seo_sitemap_xml(): string
{
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    if (seo_get('seo_index') === '1') foreach (seo_pages() as $p) {
        $x .= "  <url><loc>" . htmlspecialchars(seo_site_url() . '/' . $p['path'], ENT_XML1) . "</loc><lastmod>{$p['mod']}</lastmod>"
            . "<changefreq>{$p['freq']}</changefreq><priority>{$p['priority']}</priority></url>\n";
    }
    return $x . "</urlset>\n";
}

/** llms.txt — https://llmstxt.org — and, with $full, every answer written out. */
function seo_llms_txt(bool $full = false): string
{
    $site = seo_site_url();
    $b = brand_name();
    $t  = "# $b\n\n> " . str_replace("\n", ' ', seo_get('seo_llms_summary')) . "\n\n";
    $t .= "- Website: $site/\n- Made by: " . (seo_get('seo_org_name') ?: BRAND_PARENT) . "\n- Languages: English, Arabic (العربية)\n";
    if (($a = seo_get('seo_areas')) !== '') $t .= "- Serves: $a\n";
    $t .= "- Getting an account: $site/request.php — accounts are set up with each business; there is no self-signup.\n";
    if (($e = seo_get('seo_email')) !== '') $t .= "- Contact: $e\n";
    $t .= "\n## What it does\n\n" . implode('', array_map(fn($f) => "- $f\n", seo_features()));
    $t .= "\n## Honest limits\n\n- Sending in bulk from a personal WhatsApp number is against WhatsApp's terms and the number can be banned; the official WhatsApp Business API is the recommended channel.\n"
        . "- With the official API, Meta bills message fees directly to the business at Meta's rates.\n"
        . "- Not affiliated with or endorsed by WhatsApp or Meta.\n";

    try {
        $plans = db_all("SELECT name, price_month, currency, included_credits FROM plans WHERE is_active=1 ORDER BY sort, price_month");
        if ($plans) {
            $t .= "\n## Plans\n\n";
            foreach ($plans as $p) $t .= '- ' . $p['name'] . ': ' . ($p['currency'] ?: 'USD') . ' ' . number_format((float) $p['price_month'], 0)
                                       . ' per month, ' . number_format((int) $p['included_credits']) . " message credits included\n";
        }
    } catch (Throwable $e) {}

    $t .= "\n## Pages\n\n";
    foreach (seo_pages() as $p) $t .= '- [' . $p['title'] . '](' . $site . '/' . $p['path'] . '): ' . $p['note'] . "\n";
    $faqs = faq_live();
    if ($faqs) {
        if ($full) {
            $t .= "\n## Questions and answers\n";
            foreach ($faqs as $f) $t .= "\n### " . trim((string) $f['question']) . "\n\n" . trim((string) $f['answer']) . "\n";
        } else {
            $t .= "\n## Questions people ask\n\n";
            foreach ($faqs as $f) $t .= '- [' . trim((string) $f['question']) . "]($site/help-center.php#q-" . (int) $f['id'] . ")\n";
            $t .= "\n## Optional\n\n- [Every answer in full]($site/llms-full.txt)\n";
        }
    }
    return $t;
}

/* ── Checks for Admin → SEO ──────────────────────────────────────────────── */

/** @return array<int, array{0:string,1:bool,2:string}> [what, ok, detail] */
function seo_health(): array
{
    $tl = mb_strlen(seo_get('seo_title')); $dl = mb_strlen(seo_get('seo_description'));
    $faq = count(faq_live());
    $img = seo_get('seo_og_image');
    $imgOk = $img !== '' && (preg_match('~^https?://~', $img) || is_file(dirname(__DIR__) . '/' . ltrim($img, '/')));
    $https = str_starts_with(seo_site_url(), 'https://');
    return [
        ['Search engines may index the site', seo_get('seo_index') === '1', seo_get('seo_index') === '1' ? 'On.' : 'Off — every page says noindex and robots.txt blocks everything.'],
        ['The site address is https', $https, seo_site_url()],
        ['Title is 30–65 characters', $tl >= 30 && $tl <= 65, $tl . ' characters — Google shows about 60.'],
        ['Description is 70–160 characters', $dl >= 70 && $dl <= 160, $dl . ' characters — Google shows about 155.'],
        ['Share image is set', $imgOk, $imgOk ? 'Shown when the link is shared on WhatsApp, LinkedIn, Facebook and X.' : 'Missing — shared links show no picture.'],
        ['At least 8 questions in the Help Center', $faq >= 8, $faq . ' live. Questions are what AI assistants quote; add the recommended set below.'],
        ['Google Search Console is verified', seo_get('seo_google_verify') !== '', seo_get('seo_google_verify') !== '' ? 'Code is on every public page.' : 'Add the code, then submit the sitemap there.'],
        ['AI search engines may read the site', seo_get('seo_ai_search') === '1', 'ChatGPT search, Claude, Perplexity and others can find and cite the site.'],
        ['Company details for structured data', seo_get('seo_email') !== '' || seo_get('seo_same_as') !== '', 'A contact email or social profiles help Google and AI tools connect the site to the company.'],
    ];
}

/** Questions worth answering on the public Help Center: what people search for and what AI assistants are asked. */
function seo_recommended_faq(): array
{
    $b = brand_name();
    return [
        ["What is $b?", "$b is a WhatsApp CRM and messaging platform by " . BRAND_PARENT . ". It sends WhatsApp campaigns, runs automated conversations, answers customers with an AI agent trained on your own information, scores leads, and gives your sales team a full CRM — pipeline, assignment, follow-ups and reports — in one dashboard."],
        ["Do I need the official WhatsApp Business API to use $b?", "No. You can use the official WhatsApp Business API (recommended: verified sender, reply buttons, no practical sending limit), or link the number you already use by scanning a QR code. The same campaigns, automations, inbox and CRM work with both."],
        ["Is it safe to send bulk messages from my personal WhatsApp number?", "Bulk sending from a personal number is against WhatsApp's terms, and the number can be restricted or banned. $b paces those messages to reduce the risk, but the safe choice for volume is the official WhatsApp Business API. Use a number you can afford to lose and move to the API when you are ready."],
        ["How does AI lead qualification work for real estate?", "Point $b at the Google Sheet or Meta Lead Ads form your campaigns fill. It messages every new lead on WhatsApp, asks about budget, area, unit type, payment and timing, answers questions from your project details only, then scores the whole conversation and marks the lead hot, warm or cold with the reason — so your sales team calls the serious buyers first."],
        ["Can $b import leads from Facebook and Instagram Lead Ads?", "Yes. Connect your Facebook Page and choose the lead forms; new leads arrive in the CRM within seconds, are assigned to a salesperson automatically, and can receive a WhatsApp message straight away. Leads can also come from Google Sheets, Excel or CSV files, website forms, and click-to-WhatsApp ads."],
        ["Does $b include a CRM for my sales team?", "Yes. Leads move through your own stages on a board or a table, are shared out by round-robin or your own rules, and each salesperson sees only their leads. There are follow-up reminders, site visits and online meetings, notes and call outcomes, and reports on response time, conversion and each salesperson's results."],
        ["Can $b connect to another CRM or system?", "Yes. There is a REST API with keys to create, read and update leads, outgoing webhooks that send each new lead, stage change or activity to another system as it happens, and incoming webhook links whose fields you map onto a lead."],
        ["Does $b support Arabic?", "Yes. The whole dashboard works in Arabic, right to left, or English — each person chooses. The AI agent and lead qualifier reply in the customer's language and dialect, with the persona you set."],
        ["How much does $b cost?", "Each plan includes a monthly allowance of message credits; one credit is one outbound message, and replies you receive are free. With the official API, Meta bills its message fees to you directly at its own rates, and if you bring your own AI key the AI costs exactly what your provider charges."],
        ["How long does it take to get started?", "Usually the same day. We set the account up with you: your number connected, your first list imported, and one automation working. With your own number you can send within the hour; the official API needs a Meta business account and template approval, which usually takes minutes to a few hours."],
        ["Is my data secure in $b?", "Access keys and tokens are stored encrypted, files customers send are only served to signed-in users of your account, phone numbers can be hidden from salespeople with every reveal and export logged, and your messages, campaigns and contacts are never deleted."],
        ["Which countries does $b work in?", "Anywhere WhatsApp works. $b is built in Egypt for businesses across the Middle East — Egypt, Saudi Arabia, the UAE, Kuwait, Qatar and beyond — and handles local and international phone numbers automatically."],
    ];
}
