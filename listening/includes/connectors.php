<?php
declare(strict_types=1);

/**
 * Connector registry — declarative data, no plugin system, mirroring the way
 * ai-studio registers its providers.
 *
 * Every connector lives in includes/sources/<id>.php and exposes exactly one
 * function, `src_<id>_fetch(array $client, array $source, array $keyword): array`,
 * returning the envelope documented in includes/ingest.php.
 */

function listen_connectors(): array
{
    return [
        'google_news' => [
            'label'    => 'Google News',
            'platform' => 'news',
            'tier'     => 'free',
            'needs'    => [],
            'per_keyword'      => true,
            'min_interval'     => 15,
            'default_interval' => 30,
            'why'      => 'Broadest free news coverage, Arabic and English, per-country editions.',
            'caveat'   => 'Undocumented RSS endpoint — Google can throttle or change it without notice.',
            'config'   => [
                'hl' => ['label' => 'Language', 'type' => 'select', 'default' => 'ar',
                         'options' => ['ar' => 'العربية', 'en' => 'English']],
                'gl' => ['label' => 'Country', 'type' => 'select', 'default' => 'EG',
                         'options' => ['EG' => 'Egypt', 'SA' => 'Saudi Arabia', 'AE' => 'UAE',
                                       'US' => 'United States', 'GB' => 'United Kingdom']],
                'when' => ['label' => 'Window', 'type' => 'select', 'default' => '1d',
                           'options' => ['1h' => 'Last hour', '1d' => 'Last day', '7d' => 'Last week']],
            ],
        ],

        'bing_news' => [
            'label'    => 'Bing News',
            'platform' => 'news',
            'tier'     => 'free',
            'needs'    => [],
            'per_keyword'      => true,
            'min_interval'     => 30,
            'default_interval' => 60,
            'why'      => 'A second news index — catches outlets Google misses, and returns real article URLs.',
            'caveat'   => 'Undocumented RSS endpoint, same caveat as Google News.',
            'config'   => [
                'cc' => ['label' => 'Country', 'type' => 'select', 'default' => 'EG',
                         'options' => ['EG' => 'Egypt', 'SA' => 'Saudi Arabia', 'AE' => 'UAE',
                                       'US' => 'United States', 'GB' => 'United Kingdom']],
            ],
        ],

        'rss' => [
            'label'    => 'RSS / Atom feed',
            'platform' => 'blog',
            'tier'     => 'free',
            'needs'    => [],
            'per_keyword'      => false,   // fetch the whole feed, then match keywords locally
            'min_interval'     => 15,
            'default_interval' => 60,
            'why'      => 'Follow any specific blog, news site or forum that publishes a feed.',
            'caveat'   => 'Only covers what the site itself publishes in its feed.',
            'config'   => [
                'feed_url' => ['label' => 'Feed URL', 'type' => 'url', 'default' => '',
                               'placeholder' => 'https://example.com/feed', 'required' => true],
            ],
        ],

        'reddit' => [
            'label'    => 'Reddit',
            'platform' => 'reddit',
            'tier'     => 'free',
            'needs'    => [],
            'per_keyword'      => true,
            'min_interval'     => 30,
            'default_interval' => 60,
            'why'      => 'Unfiltered public discussion, often the first place a complaint surfaces.',
            'caveat'   => 'Anonymous access is rate limited and can start refusing requests.',
            'config'   => [
                'subreddit' => ['label' => 'Limit to subreddit (optional)', 'type' => 'text',
                                'default' => '', 'placeholder' => 'egypt'],
                't' => ['label' => 'Window', 'type' => 'select', 'default' => 'week',
                        'options' => ['day' => 'Last day', 'week' => 'Last week', 'month' => 'Last month']],
            ],
        ],

        'youtube' => [
            'label'    => 'YouTube',
            'platform' => 'youtube',
            'tier'     => 'free',
            'needs'    => ['youtube_key_enc'],
            'per_keyword'      => true,
            'min_interval'     => 60,   // search costs 100 of 10,000 daily quota units
            'default_interval' => 180,
            'why'      => 'Video reviews and vlogs, with real view and like counts.',
            'caveat'   => 'A search costs 100 of the 10,000 free daily quota units — roughly 100 searches a day.',
            'config'   => [
                'region' => ['label' => 'Region', 'type' => 'select', 'default' => 'EG',
                             'options' => ['EG' => 'Egypt', 'SA' => 'Saudi Arabia', 'AE' => 'UAE', 'US' => 'United States']],
            ],
        ],

        'serpapi' => [
            'label'    => 'SerpApi (paid aggregator)',
            'platform' => 'news',
            'tier'     => 'paid',
            'needs'    => ['aggregator_key_enc'],
            'per_keyword'      => true,
            'min_interval'     => 120,
            'default_interval' => 240,
            'why'      => 'Reliable, supported coverage — the fallback when a free source starts failing.',
            'caveat'   => 'Every check consumes a paid search credit.',
            'config'   => [
                'engine' => ['label' => 'Engine', 'type' => 'select', 'default' => 'google_news',
                             'options' => ['google_news' => 'Google News', 'google' => 'Google Web']],
            ],
        ],

        'meta_page' => [
            'label'    => 'Facebook Page',
            'platform' => 'facebook',
            'tier'     => 'official',
            'needs'    => ['meta_page_id', 'meta_token_enc'],
            'per_keyword'      => false,
            'min_interval'     => 15,
            'default_interval' => 30,
            'why'      => 'Posts that tag your page, plus the comments on your own posts.',
            'caveat'   => 'Only the page you own. The Graph API cannot search public Facebook.',
            'config'   => [],
        ],

        'meta_ig' => [
            'label'    => 'Instagram',
            'platform' => 'instagram',
            'tier'     => 'official',
            'needs'    => ['meta_ig_user_id', 'meta_token_enc'],
            'per_keyword'      => false,
            'min_interval'     => 15,
            'default_interval' => 30,
            'why'      => 'Posts that tag your account, plus the comments on your own media.',
            'caveat'   => 'Only the account you own. The Graph API cannot search public Instagram.',
            'config'   => [],
        ],
    ];
}

function listen_connector(string $id): ?array
{
    $all = listen_connectors();
    return $all[$id] ?? null;
}

/** Load the file that defines src_<id>_fetch(). */
function listen_connector_load(string $id): bool
{
    if (!listen_connector($id)) return false;
    $fn = 'src_' . $id . '_fetch';
    if (function_exists($fn)) return true;
    $file = __DIR__ . '/sources/' . $id . '.php';
    if (!is_file($file)) return false;
    require_once $file;
    return function_exists($fn);
}

/** True when the client has every credential this connector requires. */
function listen_connector_ready(string $id, array $client): bool
{
    $c = listen_connector($id);
    if (!$c) return false;
    foreach ($c['needs'] as $column) {
        if (trim((string) ($client[$column] ?? '')) === '') return false;
    }
    return true;
}

/** Which client column, if any, is missing — used to explain a "not ready" pill. */
function listen_connector_missing(string $id, array $client): array
{
    $c = listen_connector($id);
    if (!$c) return [];
    $missing = [];
    foreach ($c['needs'] as $column) {
        if (trim((string) ($client[$column] ?? '')) === '') $missing[] = $column;
    }
    return $missing;
}

/** Decoded config for a source row, with registry defaults filled in. */
function source_config(array $source): array
{
    $c   = listen_connector((string) $source['connector']);
    $cfg = json_decode((string) ($source['config_json'] ?? ''), true);
    if (!is_array($cfg)) $cfg = [];
    foreach (($c['config'] ?? []) as $key => $spec) {
        if (!isset($cfg[$key]) || $cfg[$key] === '') $cfg[$key] = $spec['default'] ?? '';
    }
    return $cfg;
}

/** Human label for a source row, falling back to the connector name. */
function source_label(array $source): string
{
    $label = trim((string) ($source['label'] ?? ''));
    if ($label !== '') return $label;
    $c = listen_connector((string) $source['connector']);
    return $c['label'] ?? (string) $source['connector'];
}

/* ── setup guidance ──────────────────────────────────────────────────────
   The copy lives in lang/, not here, so the Arabic UI does not drop back to
   English on the Sources page. The registry keeps only structure. */

/** Translated label for a connector, falling back to the registry's own. */
function connector_label(string $id): string
{
    $t = t('conn.' . $id . '.label');
    if ($t !== 'conn.' . $id . '.label') return $t;
    $c = listen_connector($id);
    return $c['label'] ?? $id;
}

/** Why you would enable this source, and what its limits are. */
function connector_why(string $id): string
{
    $t = t('conn.' . $id . '.why');
    return $t === 'conn.' . $id . '.why' ? (listen_connector($id)['why'] ?? '') : $t;
}

function connector_caveat(string $id): string
{
    $t = t('conn.' . $id . '.caveat');
    return $t === 'conn.' . $id . '.caveat' ? (listen_connector($id)['caveat'] ?? '') : $t;
}

/**
 * Numbered setup steps, read from lang as conn.<id>.step1, step2, …
 * Stops at the first missing key, so adding a step is a lang edit only.
 */
function connector_steps(string $id): array
{
    $out = [];
    for ($i = 1; $i <= 9; $i++) {
        $key = 'conn.' . $id . '.step' . $i;
        $val = t($key);
        if ($val === $key) break;
        $out[] = $val;
    }
    return $out;
}

/** Where to go to get the credential, when there is such a place. */
function connector_help(string $id): array
{
    $urls = [
        'youtube'   => 'https://console.cloud.google.com/apis/library/youtube.googleapis.com',
        'serpapi'   => 'https://serpapi.com/manage-api-key',
        'meta_page' => 'https://developers.facebook.com/tools/explorer/',
        'meta_ig'   => 'https://developers.facebook.com/tools/explorer/',
    ];
    if (!isset($urls[$id])) return [];
    $label = t('conn.' . $id . '.help');
    return ['url' => $urls[$id], 'label' => $label === 'conn.' . $id . '.help' ? $urls[$id] : $label];
}

/** Human names for the missing credentials, for the "Needs: …" line. */
function connector_missing_labels(string $id, array $client): array
{
    $out = [];
    foreach (listen_connector_missing($id, $client) as $column) {
        $key = 'cred.' . $column;
        $val = t($key);
        $out[] = $val === $key ? $column : $val;
    }
    return $out;
}

/**
 * Ready-made feeds, so a client picks a masthead instead of hunting for a URL.
 * Verified as RSS at the time of writing; a dead one simply shows an error on
 * its first check rather than failing silently.
 */
function rss_presets(): array
{
    return [
        'مصر' => [
            'اليوم السابع'      => 'https://www.youm7.com/rss/SectionRss?SectionID=65',
            'مصراوي'            => 'https://www.masrawy.com/rss/rssfeeds',
            'الأهرام'           => 'https://gate.ahram.org.eg/rss/22.aspx',
            'الشروق'            => 'https://www.shorouknews.com/rss/RssFeed/Egypt',
            'الوطن'             => 'https://www.elwatannews.com/RSSFeed',
            'المصري اليوم'      => 'https://www.almasryalyoum.com/rss/rssfeeds',
        ],
        'أعمال / اقتصاد' => [
            'العربية بزنس'      => 'https://www.alarabiya.net/.mrss/ar/aswaq.xml',
            'Enterprise Egypt'  => 'https://enterprise.press/feed/',
            'Daily News Egypt'  => 'https://www.dailynewsegypt.com/feed/',
        ],
        'إقليمي / دولي' => [
            'الجزيرة'           => 'https://www.aljazeera.net/aljazeerarss/a7c186be-1baa-4bd4-9d80-a84db769f779/73d0e1b4-532f-45ef-b135-bfdff8b8cab9',
            'BBC Arabic'        => 'https://feeds.bbci.co.uk/arabic/rss.xml',
            'CNN Arabic'        => 'https://arabic.cnn.com/api/v1/rss/rss.xml',
            'Reuters Business'  => 'https://www.reutersagency.com/feed/?best-topics=business-finance',
        ],
    ];
}

/**
 * Translated label for one of a connector's config fields.
 * Falls back to the registry's English so a newly added field still renders.
 */
function connector_field_label(string $connector, string $key, string $fallback = ''): string
{
    $t = t('cfg.' . $connector . '.' . $key);
    return $t === 'cfg.' . $connector . '.' . $key ? ($fallback ?: $key) : $t;
}
