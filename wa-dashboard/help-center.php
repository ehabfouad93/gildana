<?php
declare(strict_types=1);
/**
 * The public Help Center: every live question from Admin → Help Content, answered in full on one
 * page anyone can read — people deciding whether to sign up, search engines, and AI assistants.
 * (help.php is the signed-in version, with support requests and the walkthrough video.)
 *
 * Each answer has its own address (#q-<id>) so a search result or an AI answer can link to it.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/site_chrome.php';

$faqs = faq_live();
$b = brand_name();

site_page_open(['title' => $b . ' Help Center — WhatsApp CRM questions answered',
                'description' => 'Answers about ' . $b . ': the WhatsApp Business API or your own number, AI lead qualification for real estate, Meta Lead Ads, the sales CRM, Arabic, pricing and data security.',
                'path' => 'help-center.php', 'faq' => $faqs, 'type' => 'website',
                'crumbs' => [[$b, ''], ['Help Center', 'help-center.php']]]);
?>
<main>
  <section class="hc-top">
    <div class="wrap">
      <nav class="hc-crumbs" aria-label="Breadcrumb"><a href="./"><?= e($b) ?></a> <span aria-hidden="true">/</span> Help Center</nav>
      <h1>Help Center</h1>
      <p>Straight answers about <?= e($b) ?> — sending on WhatsApp, the AI agent and lead
         qualifier, the sales CRM, pricing and your data.</p>
      <?php if ($faqs): ?>
        <label class="hc-search">
          <span class="sr">Search the questions</span>
          <input type="search" id="hc-q" placeholder="Search the questions — e.g. Arabic, Lead Ads, pricing" autocomplete="off">
        </label>
      <?php endif; ?>
    </div>
  </section>

  <section class="section hc-body">
    <div class="wrap">
      <?php if (!$faqs): ?>
        <p class="hc-empty">Answers are on their way. Until then, <a href="request.php">ask us directly</a> — a person reads every message.</p>
      <?php else: ?>
        <div class="faq" id="hc-list">
          <?php foreach ($faqs as $f): ?>
            <details class="qa" id="q-<?= (int) $f['id'] ?>">
              <summary><h2 class="hc-qh"><?= e((string) $f['question']) ?></h2></summary>
              <div class="qa-a"><?= nl2br(e((string) $f['answer'])) ?>
                <a class="hc-link" href="#q-<?= (int) $f['id'] ?>" aria-label="Link to this answer">#</a></div>
            </details>
          <?php endforeach; ?>
        </div>
        <p class="hc-empty" id="hc-none" hidden>Nothing matches that. <a href="request.php">Ask us instead</a>.</p>
      <?php endif; ?>
    </div>
  </section>

  <section class="section cta on-dark hc-cta">
    <div class="wrap">
      <span class="eyebrow">Still deciding?</span>
      <h2>Ask a person</h2>
      <p class="lead">Tell us what you want WhatsApp to do for your business. We reply the same working day,
         and we will tell you honestly whether <?= e($b) ?> fits.</p>
      <a class="btn btn-primary" href="request.php">Get started</a>
    </div>
  </section>
</main>
<script>
(function () {
  // The answer a link points at opens by itself.
  function openHash() { var el = location.hash && document.getElementById(location.hash.slice(1)); if (el && el.tagName === 'DETAILS') el.open = true; }
  openHash(); window.addEventListener('hashchange', openHash);
  var q = document.getElementById('hc-q'); if (!q) return;
  var items = document.querySelectorAll('#hc-list .qa'), none = document.getElementById('hc-none');
  q.addEventListener('input', function () {
    var words = q.value.toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
    items.forEach(function (d) {
      var t = d.textContent.toLowerCase(), hit = words.every(function (w) { return t.indexOf(w) >= 0; });
      d.hidden = !hit; if (hit) shown++;
      d.open = words.length > 0 && hit && shown <= 3;
    });
    none.hidden = shown > 0;
  });
})();
</script>
<?php site_page_close();
