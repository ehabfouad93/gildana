<?php
declare(strict_types=1);

/**
 * Orchestrator: turns a campaign brief + a chosen module into a stored asset.
 * Text/image/design run synchronously; video (Kling) runs async via a job row
 * that the cron worker (cron/worker.php) finalizes.
 */

/** Compose a rich prompt for a stage from the campaign brief + the user's own note. */
function studio_build_prompt(string $stage, array $brief, string $note): string
{
    $b = fn(string $k) => trim((string) ($brief[$k] ?? ''));
    $lines = [];
    if ($b('product'))     $lines[] = 'Product / service: ' . $b('product');
    if ($b('audience'))    $lines[] = 'Target audience: ' . $b('audience');
    if ($b('goal'))        $lines[] = 'Campaign goal: ' . $b('goal');
    if ($b('key_message')) $lines[] = 'Key message: ' . $b('key_message');
    if ($b('tone'))        $lines[] = 'Tone / style: ' . $b('tone');
    if ($b('cta'))         $lines[] = 'Call to action: ' . $b('cta');
    if ($b('language'))    $lines[] = 'Language: ' . $b('language');
    $ctx = $lines ? implode("\n", $lines) . "\n\n" : '';

    if ($note !== '') $ctx .= "Extra direction for this " . $stage . ": " . $note . "\n\n";

    switch ($stage) {
        case 'copy':
            return $ctx . "Write social media marketing copy. Provide, clearly labeled:\n"
                . "1) Three headline options\n2) A primary caption (2–4 short lines) with tasteful emojis\n"
                . "3) 8–12 relevant hashtags\n4) A one-line call to action.\n"
                . "Match the language and tone above.";
        case 'design':
            return $ctx . "Design a single eye-catching social media poster image for the campaign above. "
                . "Describe it as a vivid image-generation prompt: subject, composition, lighting, color palette, "
                . "mood and any on-image text. Keep it brand-appropriate and uncluttered.";
        case 'video':
            return $ctx . "A short (5s) cinematic promo video for the campaign above. "
                . "Describe the scene, camera motion, lighting and mood in one vivid paragraph suitable for a text-to-video model.";
        default:
            return $ctx . $note;
    }
}

/** Insert an asset row and return its id. */
function studio_save_asset(int $campaignId, array $a): int
{
    return db_insert(
        "INSERT INTO assets
           (campaign_id, stage, module_id, vendor, kind, status, title, text_content, rel_path, url, mime, bytes, error, meta_json, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
        [
            $campaignId,
            (string) ($a['stage'] ?? ''),
            (string) ($a['module_id'] ?? ''),
            (string) ($a['vendor'] ?? ''),
            (string) ($a['kind'] ?? ''),
            (string) ($a['status'] ?? 'ready'),
            (string) ($a['title'] ?? ''),
            $a['text_content'] ?? null,
            $a['rel_path'] ?? null,
            $a['url'] ?? null,
            $a['mime'] ?? null,
            (int) ($a['bytes'] ?? 0),
            (string) ($a['error'] ?? ''),
            json_encode($a['meta'] ?? [], JSON_UNESCAPED_UNICODE),
        ]
    );
}

/**
 * Run one stage for a campaign with the chosen module.
 * $inputs = ['model'=>..., 'note'=>..., 'size'=>..., 'aspect_ratio'=>...]
 * Returns ['ok','async','asset_id','error','status'].
 */
function studio_run_stage(int $campaignId, string $stage, string $moduleId, array $brief, array $inputs): array
{
    $m = studio_module($moduleId);
    if (!$m || $m['stage'] !== $stage) return ['ok' => false, 'error' => 'Invalid module for stage', 'async' => false];
    if (!studio_vendor_ready($m['vendor'])) {
        return ['ok' => false, 'error' => ucfirst($m['vendor']) . ' is not configured — add its key on Provider Keys.', 'async' => false];
    }

    $model = (string) ($inputs['model'] ?? '');
    if ($model === '' && !empty($m['models'])) $model = (string) array_key_first($m['models']);
    $note   = trim((string) ($inputs['note'] ?? ''));
    $prompt = studio_build_prompt($stage, $brief, $note);
    $title  = $m['name'] . ' · ' . studio_stages()[$stage]['label'];

    // ── TEXT copy ──
    if ($m['modality'] === 'text') {
        $sys = 'You are an expert social media copywriter for marketing campaigns.';
        $res = adapter_text($m['vendor'], $model, $sys, $prompt, 1200);
        $aid = studio_save_asset($campaignId, [
            'stage' => $stage, 'module_id' => $moduleId, 'vendor' => $m['vendor'], 'kind' => 'text',
            'status' => $res['ok'] ? 'ready' : 'failed', 'title' => $title,
            'text_content' => $res['ok'] ? $res['text'] : null, 'error' => $res['ok'] ? '' : $res['error'],
            'meta' => ['model' => $model],
        ]);
        return ['ok' => $res['ok'], 'async' => false, 'asset_id' => $aid, 'error' => $res['error'] ?? '', 'status' => $res['ok'] ? 'ready' : 'failed'];
    }

    // ── IMAGE design ──
    if ($m['modality'] === 'image') {
        // Turn the brief into a concrete visual prompt first (cheap, improves output).
        $visual = $note !== '' ? ($prompt) : $prompt;
        $res = adapter_image($m['vendor'], $model, $visual, [
            'size'         => (string) ($inputs['size'] ?? '1024x1024'),
            'aspect_ratio' => (string) ($inputs['aspect_ratio'] ?? '1:1'),
        ]);
        $aid = studio_save_asset($campaignId, [
            'stage' => $stage, 'module_id' => $moduleId, 'vendor' => $m['vendor'], 'kind' => 'image',
            'status' => $res['ok'] ? 'ready' : 'failed', 'title' => $title,
            'rel_path' => $res['rel'] ?? null, 'url' => $res['url'] ?? null, 'mime' => $res['mime'] ?? null,
            'bytes' => $res['bytes'] ?? 0, 'error' => $res['ok'] ? '' : $res['error'], 'meta' => ['model' => $model],
        ]);
        return ['ok' => $res['ok'], 'async' => false, 'asset_id' => $aid, 'error' => $res['error'] ?? '', 'status' => $res['ok'] ? 'ready' : 'failed'];
    }

    // ── DESIGN markup (Claude editable poster) ──
    if ($m['modality'] === 'design_markup') {
        $res = adapter_design_markup($model, $prompt, ['width' => 1080, 'height' => 1080]);
        $aid = studio_save_asset($campaignId, [
            'stage' => $stage, 'module_id' => $moduleId, 'vendor' => $m['vendor'], 'kind' => 'design',
            'status' => $res['ok'] ? 'ready' : 'failed', 'title' => $title,
            'rel_path' => $res['rel'] ?? null, 'url' => $res['url'] ?? null, 'mime' => $res['mime'] ?? null,
            'bytes' => $res['bytes'] ?? 0, 'error' => $res['ok'] ? '' : $res['error'], 'meta' => ['model' => $model],
        ]);
        return ['ok' => $res['ok'], 'async' => false, 'asset_id' => $aid, 'error' => $res['error'] ?? '', 'status' => $res['ok'] ? 'ready' : 'failed'];
    }

    // ── VIDEO (async) ──
    if ($m['modality'] === 'video') {
        $res = adapter_video_start($model, $prompt, [
            'duration'     => (string) ($inputs['duration'] ?? '5'),
            'aspect_ratio' => (string) ($inputs['aspect_ratio'] ?? '16:9'),
        ]);
        if (!$res['ok']) {
            $aid = studio_save_asset($campaignId, [
                'stage' => $stage, 'module_id' => $moduleId, 'vendor' => $m['vendor'], 'kind' => 'video',
                'status' => 'failed', 'title' => $title, 'error' => $res['error'], 'meta' => ['model' => $model],
            ]);
            return ['ok' => false, 'async' => true, 'asset_id' => $aid, 'error' => $res['error'], 'status' => 'failed'];
        }
        $aid = studio_save_asset($campaignId, [
            'stage' => $stage, 'module_id' => $moduleId, 'vendor' => $m['vendor'], 'kind' => 'video',
            'status' => 'pending', 'title' => $title, 'meta' => ['model' => $model],
        ]);
        db_insert(
            "INSERT INTO jobs (campaign_id, asset_id, vendor, task_id, model, status, attempts, created_at, updated_at)
             VALUES (?,?,?,?,?, 'processing', 0, NOW(), NOW())",
            [$campaignId, $aid, 'kling', $res['job']['task_id'], $res['job']['model']]
        );
        return ['ok' => true, 'async' => true, 'asset_id' => $aid, 'error' => '', 'status' => 'pending'];
    }

    return ['ok' => false, 'error' => 'Unsupported modality', 'async' => false];
}

/** Poll + finalize a single async job row. Returns the new status. */
function studio_process_job(array $job): string
{
    if ($job['vendor'] !== 'kling') return (string) $job['status'];
    $res = adapter_video_poll((string) $job['task_id']);
    if ($res['status'] === 'processing') {
        db_run("UPDATE jobs SET attempts = attempts + 1, updated_at = NOW() WHERE id = ?", [$job['id']]);
        return 'processing';
    }
    if ($res['status'] === 'failed') {
        db_run("UPDATE jobs SET status='failed', error=?, updated_at=NOW() WHERE id=?", [$res['error'], $job['id']]);
        db_run("UPDATE assets SET status='failed', error=? WHERE id=?", [$res['error'], $job['asset_id']]);
        return 'failed';
    }
    // succeeded
    db_run("UPDATE assets SET status='ready', rel_path=?, url=?, mime=?, bytes=? WHERE id=?",
        [$res['rel'], $res['url'], $res['mime'], (int) $res['bytes'], $job['asset_id']]);
    db_run("UPDATE jobs SET status='succeeded', updated_at=NOW() WHERE id=?", [$job['id']]);
    return 'succeeded';
}

/** Process all outstanding jobs (cron worker + on-demand poll). */
function studio_process_open_jobs(int $limit = 20): int
{
    $rows = db_all("SELECT * FROM jobs WHERE status='processing' ORDER BY id ASC LIMIT ?", [$limit]);
    $done = 0;
    foreach ($rows as $j) {
        $s = studio_process_job($j);
        if ($s !== 'processing') $done++;
    }
    return $done;
}
