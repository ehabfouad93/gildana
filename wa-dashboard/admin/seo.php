<?php
declare(strict_types=1);
/**
 * Admin → SEO: how the public site is described to Google, Bing and AI assistants.
 *
 * Every field starts with a recommended value (includes/seo.php), so the site is well described
 * before anyone opens this page. What is set here feeds the public pages' <head>, the structured
 * data, /robots.txt, /sitemap.xml and /llms.txt.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/seo.php';
require_once __DIR__ . '/../includes/push.php';   // setting_set

$F = seo_fields();
$checks = ['seo_index', 'seo_ai_search', 'seo_ai_training'];
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string) ($_POST['action'] ?? '');
    if ($act === 'save') {
        foreach ($F as $k => [$def]) {
            if (in_array($k, $checks, true)) { setting_set($k, !empty($_POST[$k]) ? '1' : '0'); continue; }
            if (!array_key_exists($k, $_POST)) continue;
            $v = trim((string) $_POST[$k]);
            if ($k === 'seo_site_url' && $v !== '' && !preg_match('~^https?://[^\s/]+~i', $v)) { $err = 'The site address must start with https://'; continue; }
            if ($k === 'seo_ga4' && $v !== '' && !preg_match('~^G-[A-Z0-9]{4,16}$~i', $v)) { $err = 'A Google Analytics 4 ID looks like G-XXXXXXXXXX.'; continue; }
            // Verification codes: people paste the whole <meta> tag; keep only its content.
            if (in_array($k, ['seo_google_verify', 'seo_bing_verify'], true) && preg_match('~content=["\']([^"\']+)~', $v, $m)) $v = $m[1];
            setting_set($k, mb_substr($v, 0, 4000));
        }
        if (!empty($_FILES['og_upload']['tmp_name']) && is_uploaded_file($_FILES['og_upload']['tmp_name'])) {
            $info = @getimagesize($_FILES['og_upload']['tmp_name']);
            $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? '';
            if ($ext === '' || $_FILES['og_upload']['size'] > 5 * 1024 * 1024) $err = 'The share image must be a PNG, JPG or WebP under 5 MB.';
            else {
                @mkdir(video_dir_path(), 0755, true);
                $name = 'og-' . bin2hex(random_bytes(5)) . '.' . $ext;
                if (move_uploaded_file($_FILES['og_upload']['tmp_name'], video_dir_path() . '/' . $name)) setting_set('seo_og_image', video_dir_url() . '/' . $name);
            }
        }
        if (!$err) { flash('SEO settings saved.'); redirect('seo.php'); }
    }
    if ($act === 'defaults') {
        foreach (['seo_title', 'seo_description', 'seo_keywords', 'seo_llms_summary', 'seo_og_image'] as $k) setting_set($k, $F[$k][0]);
        flash('Recommended wording restored.');
        redirect('seo.php');
    }
    if ($act === 'add_faq') {
        $have = array_map(fn($q) => mb_strtolower(trim((string) $q)), array_column(db_all("SELECT question FROM faq_items"), 'question'));
        $sort = (int) db_val("SELECT COALESCE(MAX(sort),0) FROM faq_items");
        $n = 0;
        foreach (seo_recommended_faq() as [$q, $a]) {
            if (in_array(mb_strtolower($q), $have, true)) continue;
            db_run("INSERT INTO faq_items (sort,question,answer,status,created_at) VALUES (?,?,?,'active',NOW())", [$sort += 10, $q, $a]);
            $n++;
        }
        flash($n ? $n . ' questions added to the Help Center. Edit them any time in Help Content.' : 'The recommended questions are already there.');
        redirect('seo.php');
    }
}

$v = fn(string $k) => e($err && isset($_POST[$k]) ? (string) $_POST[$k] : seo_get($k));
$on = fn(string $k) => ($err ? !empty($_POST[$k]) : seo_get($k) === '1') ? ' checked' : '';
$site = seo_site_url();
$health = seo_health();
$good = count(array_filter($health, fn($h) => $h[1]));
$img = seo_abs(seo_get('seo_og_image'));
$missingFaq = count(array_filter(seo_recommended_faq(), fn($r) => !db_val("SELECT 1 FROM faq_items WHERE question=?", [$r[0]])));

layout_header('SEO', 'admin', 'seo');
page_head('SEO', '<a class="btn btn-ghost" href="../help-center.php" target="_blank" rel="noopener">Open the Help Center</a>');
?>
<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="card">
  <div class="row-between" style="flex-wrap:wrap;gap:10px">
    <h2 style="margin:0">Health — <?= $good ?> of <?= count($health) ?> in place</h2>
    <div class="meter" style="flex:1;max-width:260px"><span style="width:<?= round($good / count($health) * 100) ?>%"></span></div>
  </div>
  <ul class="issue-list">
    <?php foreach ($health as [$what, $ok, $detail]): ?>
      <li class="issue <?= $ok ? '' : 'warn' ?>"<?= $ok ? ' style="border-inline-start-color:var(--success)"' : '' ?>><strong><?= $ok ? '✓' : '!' ?> <?= e($what) ?></strong> <span class="text-muted">— <?= e($detail) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <div class="section-label">What search engines and AI assistants read</div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach (['robots.txt' => 'robots.php', 'sitemap.xml' => 'sitemap.php', 'llms.txt' => 'llms.php', 'llms-full.txt' => 'llms.php?full=1'] as $label => $file): ?>
      <a class="btn btn-ghost btn-sm" href="../<?= $file ?>" target="_blank" rel="noopener"><?= $label ?></a>
    <?php endforeach; ?>
    <a class="btn btn-ghost btn-sm" href="https://search.google.com/test/rich-results?url=<?= rawurlencode($site . '/') ?>" target="_blank" rel="noopener">Test in Google</a>
    <a class="btn btn-ghost btn-sm" href="https://search.google.com/search-console" target="_blank" rel="noopener">Search Console</a>
    <a class="btn btn-ghost btn-sm" href="https://www.bing.com/webmasters" target="_blank" rel="noopener">Bing Webmaster</a>
  </div>
  <p class="text-muted" style="font-size:12.5px;margin:10px 0 0">Once verified, submit <code class="ltr"><?= e($site) ?>/sitemap.xml</code> in Search Console and in Bing Webmaster Tools (Bing also feeds ChatGPT search and Copilot).</p>
</div>

<div class="card">
  <h2>Help Center questions</h2>
  <p class="text-muted" style="font-size:13px;margin:-6px 0 10px">
    Questions and answers are what Google shows as rich results and what AI assistants quote. They appear on the
    public <a href="../help-center.php" target="_blank" rel="noopener">Help Center</a>, on the landing page, and in llms.txt.
    There are <?= count(faq_live()) ?> live; edit them in <a href="help_admin.php">Help Content</a>.</p>
  <?php if ($missingFaq): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_faq">
      <button class="btn btn-primary btn-sm">Add <?= $missingFaq ?> recommended questions</button>
      <span class="text-muted" style="font-size:12.5px">— what businesses search for: the API or your own number, AI lead qualification, Lead Ads, the CRM, Arabic, pricing, security.</span></form>
  <?php else: ?>
    <p style="font-size:13px;margin:0">✓ All the recommended questions are in.</p>
  <?php endif; ?>
</div>

<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">

  <div class="card">
    <h2>In search results</h2>
    <div class="field"><span class="lbl">Site address</span><input type="url" name="seo_site_url" value="<?= $v('seo_site_url') ?>" placeholder="<?= e($site) ?>" class="ltr">
      <div class="hint">The one address search engines should use. Empty uses <?= e($site) ?>.</div></div>
    <div class="field"><span class="lbl">Title <span class="text-muted" data-count="seo_title">0</span></span><input type="text" name="seo_title" id="seo_title" maxlength="120" value="<?= $v('seo_title') ?>">
      <div class="hint">About 60 characters show in Google. Lead with what people search for.</div></div>
    <div class="field"><span class="lbl">Description <span class="text-muted" data-count="seo_description">0</span></span><textarea name="seo_description" id="seo_description" rows="3" maxlength="300"><?= $v('seo_description') ?></textarea>
      <div class="hint">About 155 characters show. Say who it is for and what it does.</div></div>
    <div class="section-label">Preview</div>
    <div class="seo-serp" aria-hidden="true">
      <div class="seo-serp-url ltr"><?= e(preg_replace('~^https?://~', '', $site)) ?></div>
      <div class="seo-serp-title" id="pv-title"></div>
      <div class="seo-serp-desc" id="pv-desc"></div>
    </div>
    <div class="field" style="margin-top:14px"><span class="lbl">Keywords, in English and Arabic</span><textarea name="seo_keywords" rows="3"><?= $v('seo_keywords') ?></textarea>
      <div class="hint">Google ignores this tag, but Bing and some AI tools read it. Comma-separated.</div></div>
    <label class="mod-opt"><input type="checkbox" name="seo_index" value="1"<?= $on('seo_index') ?>> Let search engines index the site <span class="text-muted">— switch off only on a test copy</span></label>
  </div>

  <div class="card">
    <h2>When the link is shared</h2>
    <div class="seo-share">
      <?php if ($img): ?><img src="<?= e($img) ?>" alt="" width="1200" height="630"><?php endif; ?>
      <div><strong id="pv-share-title"></strong><span id="pv-share-desc"></span><small class="ltr"><?= e(preg_replace('~^https?://~', '', $site)) ?></small></div>
    </div>
    <div class="grid2">
      <div class="field"><span class="lbl">Share image</span><input type="text" name="seo_og_image" value="<?= $v('seo_og_image') ?>" class="ltr">
        <div class="hint">1200 × 630. Shown on WhatsApp, LinkedIn, Facebook and X.</div></div>
      <div class="field"><span class="lbl">…or upload one</span><input type="file" name="og_upload" accept="image/png,image/jpeg,image/webp"></div>
      <div class="field"><span class="lbl">X (Twitter) handle</span><input type="text" name="seo_twitter" value="<?= $v('seo_twitter') ?>" placeholder="@gildana" class="ltr"></div>
    </div>
  </div>

  <div class="card">
    <h2>Who is behind it</h2>
    <p class="text-muted" style="font-size:13px;margin:-6px 0 10px">Written into every public page as structured data, so Google and AI assistants know the company, the product and how to reach you.</p>
    <div class="grid2">
      <div class="field"><span class="lbl">Company name</span><input type="text" name="seo_org_name" value="<?= $v('seo_org_name') ?>"></div>
      <div class="field"><span class="lbl">Logo</span><input type="text" name="seo_org_logo" value="<?= $v('seo_org_logo') ?>" class="ltr"></div>
      <div class="field"><span class="lbl">Contact email</span><input type="email" name="seo_email" value="<?= $v('seo_email') ?>" class="ltr"></div>
      <div class="field"><span class="lbl">Contact phone</span><input type="text" name="seo_phone" value="<?= $v('seo_phone') ?>" placeholder="+20 …" class="ltr"></div>
    </div>
    <div class="field"><span class="lbl">Areas served</span><input type="text" name="seo_areas" value="<?= $v('seo_areas') ?>"><div class="hint">Countries, comma-separated.</div></div>
    <div class="field"><span class="lbl">Social profiles</span><textarea name="seo_same_as" rows="3" class="ltr" placeholder="https://www.linkedin.com/company/…&#10;https://www.facebook.com/…&#10;https://www.instagram.com/…"><?= $v('seo_same_as') ?></textarea>
      <div class="hint">One per line. Links the site to the company's profiles — a strong signal for Google's knowledge panel and for AI answers.</div></div>
  </div>

  <div class="card">
    <h2>AI assistants</h2>
    <label class="mod-opt"><input type="checkbox" name="seo_ai_search" value="1"<?= $on('seo_ai_search') ?>> Let AI search engines read the site <span class="text-muted">— ChatGPT search, Claude, Perplexity, Apple, DuckDuckGo. They answer people's questions and link to you. Recommended.</span></label>
    <label class="mod-opt"><input type="checkbox" name="seo_ai_training" value="1"<?= $on('seo_ai_training') ?>> Let AI models learn from the site <span class="text-muted">— GPTBot, ClaudeBot, Google-Extended, Common Crawl. Makes assistants know the product without searching.</span></label>
    <div class="field" style="margin-top:10px"><span class="lbl">The product in plain words (llms.txt)</span><textarea name="seo_llms_summary" rows="5"><?= $v('seo_llms_summary') ?></textarea>
      <div class="hint">The first thing an AI assistant reads about the product. Facts, not slogans: who it is for, what it does, where.</div></div>
  </div>

  <div class="card">
    <h2>Verification and analytics</h2>
    <div class="grid2">
      <div class="field"><span class="lbl">Google Search Console</span><input type="text" name="seo_google_verify" value="<?= $v('seo_google_verify') ?>" class="ltr" placeholder="Paste the HTML tag or its code">
        <div class="hint">Search Console → Add property → URL prefix → HTML tag.</div></div>
      <div class="field"><span class="lbl">Bing Webmaster Tools</span><input type="text" name="seo_bing_verify" value="<?= $v('seo_bing_verify') ?>" class="ltr" placeholder="Paste the meta tag or its code"></div>
      <div class="field"><span class="lbl">Google Analytics 4</span><input type="text" name="seo_ga4" value="<?= $v('seo_ga4') ?>" class="ltr" placeholder="G-XXXXXXXXXX">
        <div class="hint">Public pages only — nothing inside the app is tracked.</div></div>
    </div>
  </div>

  <div style="display:flex;gap:8px;margin-bottom:22px">
    <button class="btn btn-primary">Save SEO settings</button>
  </div>
</form>
<form method="post" onsubmit="return confirm('Put back the recommended title, description, keywords, AI summary and share image?')" style="margin-bottom:22px">
  <?= csrf_field() ?><input type="hidden" name="action" value="defaults">
  <button class="btn btn-link btn-sm">Restore the recommended wording</button>
</form>

<script>
(function () {
  const t = document.getElementById('seo_title'), d = document.getElementById('seo_description');
  const cut = (s, n) => s.length > n ? s.slice(0, n - 1).trimEnd() + '…' : s;
  function draw() {
    document.getElementById('pv-title').textContent = cut(t.value, 60);
    document.getElementById('pv-desc').textContent = cut(d.value, 158);
    document.getElementById('pv-share-title').textContent = t.value;
    document.getElementById('pv-share-desc').textContent = cut(d.value, 110);
    document.querySelectorAll('[data-count]').forEach(el => {
      const n = document.getElementById(el.dataset.count).value.length, max = el.dataset.count === 'seo_title' ? 65 : 160;
      el.textContent = '· ' + n + ' / ' + max; el.style.color = n > max ? 'var(--danger)' : '';
    });
  }
  t.addEventListener('input', draw); d.addEventListener('input', draw); draw();
})();
</script>
<?php layout_footer(); ?>
