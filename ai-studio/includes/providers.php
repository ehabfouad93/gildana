<?php
declare(strict_types=1);

/**
 * The provider registry — the heart of the "pick which AI, and why" experience.
 *
 * A campaign is a pipeline of STAGES (copy → design → video → publish). Each stage
 * offers one or more MODULES (a specific model from a specific vendor). Every module
 * carries a "why" rationale and "best_for" tags so the user can choose deliberately.
 *
 * Credentials are grouped by VENDOR (openai, anthropic, kling, stability, meta) and
 * stored encrypted in the provider_keys table, managed on the Provider Keys screen.
 */

/** Ordered pipeline stages. */
function studio_stages(): array
{
    return [
        'copy' => [
            'label'    => 'Content / Copy',
            'modality' => 'text',
            'icon'     => 'pen',
            'desc'     => 'Headlines, captions, ad copy, scripts and CTAs.',
            'required' => false,
        ],
        'design' => [
            'label'    => 'Design / Image',
            'modality' => 'image',
            'icon'     => 'image',
            'desc'     => 'Posters, social creatives and product visuals.',
            'required' => false,
        ],
        'video' => [
            'label'    => 'Video',
            'modality' => 'video',
            'icon'     => 'film',
            'desc'     => 'Short promo clips and reels from a prompt.',
            'required' => false,
        ],
        'publish' => [
            'label'    => 'Publish',
            'modality' => 'publish',
            'icon'     => 'send',
            'desc'     => 'Post the chosen assets to social, or just download.',
            'required' => false,
        ],
    ];
}

/**
 * Every selectable module. `vendor` is the credential group. `why`/`best_for`
 * drive the comparison cards in the wizard.
 */
function studio_modules(): array
{
    return [
        // ── COPY ──────────────────────────────────────────────
        'openai_gpt' => [
            'stage'    => 'copy',
            'vendor'   => 'openai',
            'name'     => 'ChatGPT',
            'sub'      => 'OpenAI · GPT',
            'modality' => 'text',
            'models'   => ['gpt-4o' => 'GPT-4o', 'gpt-4o-mini' => 'GPT-4o mini (cheaper)', 'gpt-4.1' => 'GPT-4.1'],
            'why'      => 'Fast, punchy marketing copy with lots of variations. Excels at ad hooks, captions and CTAs.',
            'best_for' => ['Social captions', 'Ad copy', 'Short-form', 'Variations'],
            'badge'    => 'Popular',
        ],
        'anthropic_claude' => [
            'stage'    => 'copy',
            'vendor'   => 'anthropic',
            'name'     => 'Claude',
            'sub'      => 'Anthropic',
            'modality' => 'text',
            'models'   => ['claude-opus-4-8' => 'Claude Opus 4.8', 'claude-sonnet-4-5' => 'Claude Sonnet 4.5', 'claude-haiku-4-5' => 'Claude Haiku 4.5 (cheaper)'],
            'why'      => 'On-brand, long-form storytelling with careful tone control. Best for briefs, articles and nuanced brand voice.',
            'best_for' => ['Long-form', 'Brand voice', 'Briefs', 'Structured'],
            'badge'    => '',
        ],

        // ── DESIGN ────────────────────────────────────────────
        'openai_image' => [
            'stage'    => 'design',
            'vendor'   => 'openai',
            'name'     => 'GPT Image',
            'sub'      => 'OpenAI · gpt-image-1',
            'modality' => 'image',
            'models'   => ['gpt-image-1' => 'gpt-image-1'],
            'why'      => 'Follows detailed prompts and renders readable text inside posters — great when the creative needs words baked in.',
            'best_for' => ['Posters with text', 'Product creatives', 'Prompt accuracy'],
            'badge'    => 'Text in image',
        ],
        'stability_image' => [
            'stage'    => 'design',
            'vendor'   => 'stability',
            'name'     => 'Stable Diffusion',
            'sub'      => 'Stability AI',
            'modality' => 'image',
            'models'   => ['core' => 'Stable Image Core', 'sd3.5-large' => 'SD 3.5 Large', 'ultra' => 'Stable Image Ultra'],
            'why'      => 'High-control, photorealistic and artistic imagery, cost-effective at volume. Best for hero visuals and backgrounds.',
            'best_for' => ['Hero imagery', 'Photoreal', 'Art styles', 'Volume'],
            'badge'    => 'Cost-effective',
        ],
        'claude_design' => [
            'stage'    => 'design',
            'vendor'   => 'anthropic',
            'name'     => 'Claude Design',
            'sub'      => 'Anthropic · HTML/SVG',
            'modality' => 'design_markup',
            'models'   => ['claude-opus-4-8' => 'Claude Opus 4.8', 'claude-sonnet-4-5' => 'Claude Sonnet 4.5'],
            'why'      => 'Produces an editable vector/HTML poster layout (exact headlines + layout you can tweak), not a flat image. Ideal for text-heavy, brand-precise designs.',
            'best_for' => ['Editable layout', 'Exact text', 'Brand layout', 'Vector'],
            'badge'    => 'Editable',
        ],

        // ── VIDEO ─────────────────────────────────────────────
        'kling_video' => [
            'stage'    => 'video',
            'vendor'   => 'kling',
            'name'     => 'Kling',
            'sub'      => 'Kuaishou',
            'modality' => 'video',
            'models'   => ['kling-v1-6' => 'Kling v1.6', 'kling-v1' => 'Kling v1'],
            'why'      => 'State-of-the-art text-to-video with smooth, cinematic motion. Ideal for short promo clips and reels.',
            'best_for' => ['Promo clips', 'Reels', 'Cinematic motion'],
            'badge'    => 'Video',
        ],

        // ── PUBLISH ───────────────────────────────────────────
        'meta_facebook' => [
            'stage'    => 'publish',
            'vendor'   => 'meta',
            'name'     => 'Facebook Page',
            'sub'      => 'Meta Graph API',
            'modality' => 'publish',
            'models'   => [],
            'why'      => 'Publish the chosen image or video straight to your Facebook Page feed with a caption.',
            'best_for' => ['Facebook feed', 'Photo posts', 'Video posts'],
            'badge'    => '',
        ],
        'meta_instagram' => [
            'stage'    => 'publish',
            'vendor'   => 'meta',
            'name'     => 'Instagram',
            'sub'      => 'Meta Graph API',
            'modality' => 'publish',
            'models'   => [],
            'why'      => 'Publish to an Instagram Business/Creator account as a feed post or reel with a caption.',
            'best_for' => ['IG feed', 'Reels', 'Business accounts'],
            'badge'    => '',
        ],
    ];
}

function studio_module(string $id): ?array
{
    $all = studio_modules();
    if (!isset($all[$id])) return null;
    return ['id' => $id] + $all[$id];
}

function studio_modules_for_stage(string $stage): array
{
    $out = [];
    foreach (studio_modules() as $id => $m) {
        if ($m['stage'] === $stage) $out[$id] = ['id' => $id] + $m;
    }
    return $out;
}

/** Credential field specs per vendor (for the Provider Keys screen). */
function studio_key_specs(): array
{
    return [
        'openai' => [
            'label'  => 'OpenAI (ChatGPT / GPT Image)',
            'fields' => [
                'api_key' => ['label' => 'API Key', 'secret' => true, 'placeholder' => 'sk-...'],
            ],
            'help'   => 'From platform.openai.com → API keys. Powers ChatGPT copy and GPT Image.',
        ],
        'anthropic' => [
            'label'  => 'Anthropic (Claude)',
            'fields' => [
                'api_key' => ['label' => 'API Key', 'secret' => true, 'placeholder' => 'sk-ant-...'],
            ],
            'help'   => 'From console.anthropic.com. Powers Claude copy and Claude Design.',
        ],
        'stability' => [
            'label'  => 'Stability AI (Stable Diffusion)',
            'fields' => [
                'api_key' => ['label' => 'API Key', 'secret' => true, 'placeholder' => 'sk-...'],
            ],
            'help'   => 'From platform.stability.ai. Powers Stable Diffusion image generation.',
        ],
        'kling' => [
            'label'  => 'Kling (video)',
            'fields' => [
                'access_key' => ['label' => 'Access Key',  'secret' => false, 'placeholder' => 'AK...'],
                'secret_key' => ['label' => 'Secret Key',  'secret' => true,  'placeholder' => 'SK...'],
                'base'       => ['label' => 'API Base URL', 'secret' => false, 'placeholder' => 'https://api-singapore.klingai.com'],
            ],
            'help'   => 'From the Kling AI open platform. The two keys are used to sign a JWT for each request.',
        ],
        'meta' => [
            'label'  => 'Meta (Facebook + Instagram publishing)',
            'fields' => [
                'access_token'       => ['label' => 'Access Token',          'secret' => true,  'placeholder' => 'EAAB...'],
                'facebook_page_id'   => ['label' => 'Facebook Page ID',      'secret' => false, 'placeholder' => '1234567890'],
                'instagram_user_id'  => ['label' => 'Instagram User ID',     'secret' => false, 'placeholder' => '1789xxxxxxxxxxx'],
                'graph_version'      => ['label' => 'Graph API version',      'secret' => false, 'placeholder' => 'v21.0'],
            ],
            'help'   => 'A long-lived Page access token with pages_manage_posts + instagram_content_publish. Instagram publishing requires base_url to be set (Meta fetches media by URL).',
        ],
    ];
}

/**
 * Resolve a vendor's stored credentials (decrypted assoc array).
 * Falls back to config('fallback_keys') for single-key vendors when unset.
 */
function studio_credentials(string $vendor): array
{
    static $cache = [];
    if (array_key_exists($vendor, $cache)) return $cache[$vendor];

    $creds = [];
    try {
        $row = db_row("SELECT credentials_enc FROM provider_keys WHERE vendor = ?", [$vendor]);
        if ($row) $creds = decrypt_json((string) $row['credentials_enc']);
    } catch (Throwable $e) { $creds = []; }

    // Single-key vendors can fall back to a global config key.
    if (empty($creds['api_key']) && in_array($vendor, ['openai', 'anthropic', 'stability'], true)) {
        $fb = (array) config('fallback_keys', []);
        if (!empty($fb[$vendor])) $creds['api_key'] = (string) $fb[$vendor];
    }
    return $cache[$vendor] = $creds;
}

/** Is a vendor usable (has the minimum required credentials)? */
function studio_vendor_ready(string $vendor): bool
{
    $c = studio_credentials($vendor);
    switch ($vendor) {
        case 'openai':
        case 'anthropic':
        case 'stability': return !empty($c['api_key']);
        case 'kling':     return !empty($c['access_key']) && !empty($c['secret_key']);
        case 'meta':      return !empty($c['access_token']) && (!empty($c['facebook_page_id']) || !empty($c['instagram_user_id']));
    }
    return false;
}

/** Is a specific module usable right now? */
function studio_module_ready(string $moduleId): bool
{
    $m = studio_module($moduleId);
    return $m ? studio_vendor_ready($m['vendor']) : false;
}
