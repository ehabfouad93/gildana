<?php
declare(strict_types=1);
require __DIR__ . '/includes/functions.php';

$data = site_data();
$brand = $data['brand'] ?? [];
$nav = $data['nav'] ?? [];
$hero = $data['hero'] ?? [];
$stats = $data['stats'] ?? [];
$services = $data['services'] ?? [];
$portfolio = $data['portfolio'] ?? [];
$testimonials = $data['testimonials'] ?? [];
$clients = $data['clients'] ?? [];
$contact = $data['contact'] ?? [];
$footer = $data['footer'] ?? [];
$messageSent = isset($_GET['sent']);
$newsletterSent = isset($_GET['joined']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= e($data['meta']['description'] ?? '') ?>">
<title><?= e($data['meta']['title'] ?? 'Gildana') ?></title>
<style>
@font-face {
  font-family: 'Bebas Neue';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url(https://fonts.gstatic.com/s/bebasneue/v14/JTUSjIg69CK48gW7PXoo9Wlhyw.woff2) format('woff2');
}
@font-face {
  font-family: 'Cormorant Garamond';
  font-style: italic;
  font-weight: 300;
  font-display: swap;
  src: url(https://fonts.gstatic.com/s/cormorantgaramond/v16/64cEYfeHvd5dnvDYGLVfxyYnoETWKw.woff2) format('woff2');
}
</style>
<link rel="stylesheet" href="styles-v2-2.css">
</head>
<body>
<nav class="nav">
  <div class="brand">
    <span class="name"><?= e($brand['name'] ?? '') ?></span>
    <span class="tag"><?= e($brand['tagline'] ?? '') ?></span>
  </div>
  <ul class="nav-links">
    <?php foreach ($nav as $i => $item): ?>
      <li><a href="<?= e($item['url'] ?? '#') ?>" class="<?= $i === 0 ? 'active' : '' ?>"><?= e($item['label'] ?? '') ?></a></li>
    <?php endforeach; ?>
  </ul>
  <a href="#contact" class="nav-cta">Let's Connect <?= svg_arrow_right(12) ?></a>
  <button class="nav-toggle" id="nav-toggle" aria-label="Toggle menu" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
</nav>

<div class="mobile-menu" id="mobile-menu" aria-hidden="true">
  <ul class="mobile-links">
    <?php foreach ($nav as $item): ?>
      <li><a href="<?= e($item['url'] ?? '#') ?>" onclick="closeMobileMenu()"><?= e($item['label'] ?? '') ?></a></li>
    <?php endforeach; ?>
    <li><a href="#contact" class="mobile-cta" onclick="closeMobileMenu()">Let's Connect</a></li>
  </ul>
</div>

<main>
  <section class="hero" id="top">
    <div class="hero-grid">
      <div class="hero-text">
        <div class="eyebrow"><?= e($hero['eyebrow'] ?? '') ?></div>
        <h1 class="hero-headline">
          <?= e($hero['title'] ?? '') ?>
          <span class="gold"><?= e($hero['title_gold'] ?? '') ?></span>
        </h1>
        <div class="hero-rule"></div>
        <p class="hero-lede"><?= e($hero['lede'] ?? '') ?></p>
        <div class="hero-cta-row">
          <a href="<?= e($hero['primary_url'] ?? '#services') ?>" class="btn btn-primary">
            <?= e($hero['primary_label'] ?? '') ?>
            <span class="arrow"><?= svg_arrow_right(12) ?></span>
          </a>
          <a href="<?= e($hero['secondary_url'] ?? '#work') ?>" class="btn btn-ghost">
            <?= e($hero['secondary_label'] ?? '') ?>
            <span class="arrow"><?= svg_arrow_right(12) ?></span>
          </a>
        </div>
      </div>
      <div class="hero-photo">
        <img src="<?= asset_path($hero['image'] ?? '') ?>" alt="<?= e($hero['image_alt'] ?? '') ?>" class="hero-photo-img">
        <div class="strategy-card">
          <?php foreach (($hero['tags'] ?? []) as $tag): ?>
            <span><?= e($tag) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="stats" id="about">
    <div class="stats-inner">
      <div class="stats-eyebrow"><?= e($stats['eyebrow'] ?? '') ?></div>
      <div class="stats-grid">
        <?php foreach (($stats['items'] ?? []) as $item): ?>
          <div class="stat">
            <div class="num"><?= e($item['number'] ?? '') ?><span style="color: var(--gold-soft)"><?= e($item['suffix'] ?? '') ?></span></div>
            <div class="label"><?= e($item['label'] ?? '') ?></div>
            <div class="sub"><?= e($item['sub'] ?? '') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="stats-cta">
        <a href="<?= e($stats['cta_url'] ?? '#work') ?>"><?= e($stats['cta_label'] ?? '') ?> &rarr;</a>
      </div>
    </div>
  </section>

  <section class="services" id="services">
    <div class="services-inner">
      <div class="services-head">
        <div class="eyebrow"><?= e($services['eyebrow'] ?? '') ?></div>
        <h2><?= e($services['title'] ?? '') ?> <span class="gold"><?= e($services['title_gold'] ?? '') ?></span></h2>
      </div>
      <div class="services-grid">
        <?php foreach (($services['items'] ?? []) as $item): ?>
          <div class="service-card">
            <div class="icon"><?= service_icon((string) ($item['icon'] ?? 'strategy')) ?></div>
            <h3><?= e($item['name'] ?? '') ?></h3>
            <p><?= e($item['desc'] ?? '') ?></p>
            <span class="arrow"><?= svg_arrow_right(14) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="portfolio" id="work">
    <div class="portfolio-inner">
      <div class="portfolio-head">
        <div class="left">
          <div class="eyebrow"><?= e($portfolio['eyebrow'] ?? '') ?></div>
          <h2><?= e($portfolio['title'] ?? '') ?></h2>
        </div>
        <a href="<?= e($portfolio['view_all_url'] ?? '#') ?>" class="view-all"><?= e($portfolio['view_all_label'] ?? '') ?> &rarr;</a>
      </div>
      <div class="portfolio-grid">
        <?php foreach (($portfolio['items'] ?? []) as $item): ?>
          <article class="case">
            <img class="case-img" src="<?= asset_path($item['image'] ?? '') ?>" alt="<?= e($item['title'] ?? '') ?>">
            <div class="overlay"></div>
            <div class="meta">
              <div class="cat"><?= e($item['cat'] ?? '') ?></div>
              <div class="title"><?= e($item['title'] ?? '') ?></div>
              <div class="stat-row">
                <div>
                  <div class="stat-pct"><?= e($item['pct'] ?? '') ?></div>
                  <div class="stat-label"><?= e($item['label'] ?? '') ?></div>
                </div>
                <span class="pct-arrow"><?= svg_arrow_right(14) ?></span>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="testimonial" id="testimonials">
    <div class="testimonial-inner" data-testimonials>
      <div class="eyebrow"><?= e($testimonials['eyebrow'] ?? '') ?></div>
      <button class="testimonial-arrow prev" type="button" data-prev aria-label="Previous"><?= svg_arrow_left(12) ?></button>
      <button class="testimonial-arrow next" type="button" data-next aria-label="Next"><?= svg_arrow_right(12) ?></button>
      <?php foreach (($testimonials['items'] ?? []) as $i => $item): ?>
        <div class="testimonial-slide" data-slide="<?= $i ?>" style="<?= $i === 0 ? '' : 'display:none' ?>">
          <div class="testimonial-quote">
            <span class="q-mark left">&ldquo;</span>
            <p><?= e($item['quote'] ?? '') ?></p>
            <span class="q-mark right">&ldquo;</span>
          </div>
          <div class="testimonial-attr">
            <div class="who"><?= e($item['who'] ?? '') ?></div>
            <div class="role"><?= e($item['role'] ?? '') ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="testimonial-dots">
        <?php foreach (($testimonials['items'] ?? []) as $i => $_): ?>
          <button type="button" class="dot<?= $i === 0 ? ' active' : '' ?>" data-dot="<?= $i ?>" aria-label="Slide <?= $i + 1 ?>"></button>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="clients">
    <div class="clients-inner">
      <div class="clients-eyebrow"><?= e($clients['eyebrow'] ?? '') ?></div>
      <div class="clients-grid">
        <?php foreach (($clients['items'] ?? []) as $item): ?>
          <div class="client"><img src="<?= asset_path($item['image'] ?? '') ?>" alt="<?= e($item['alt'] ?? '') ?>"></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="contact" id="contact">
    <div class="contact-inner">
      <div class="contact-side">
        <div class="eyebrow"><?= e($contact['eyebrow'] ?? '') ?></div>
        <h2><?= nl2br(e($contact['title'] ?? '')) ?></h2>
        <p class="lede"><?= e($contact['lede'] ?? '') ?></p>
        <div class="contact-info-list">
          <?php foreach (($contact['info'] ?? []) as $item): ?>
            <div class="contact-info-row">
              <span class="icon-circle"><?= contact_icon((string) ($item['icon'] ?? 'phone')) ?></span>
              <span><?= e($item['text'] ?? '') ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="contact-form-wrap">
        <?php if ($messageSent): ?>
          <div class="contact-sent"><strong>Thank you.</strong> <?= e($contact['success'] ?? '') ?></div>
        <?php else: ?>
          <form class="contact-form" action="submit-contact.php" method="post">
            <input type="hidden" name="type" value="contact">
            <div class="row-2">
              <div class="field"><input type="text" name="name" placeholder="Your Name" required></div>
              <div class="field"><input type="tel" name="phone" placeholder="Phone Number"></div>
            </div>
            <div class="row-2">
              <div class="field"><input type="email" name="email" placeholder="Email Address" required></div>
              <div class="field"><input type="text" name="company" placeholder="Company Name"></div>
            </div>
            <div class="field"><textarea name="message" placeholder="Tell us about your project"></textarea></div>
            <button type="submit" class="submit">Send Message <?= svg_arrow_right(12) ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <a class="float-cta" href="#contact" aria-label="Chat"><?= contact_icon('chat') ?></a>
  </section>
</main>

<footer class="footer">
  <div class="footer-inner">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="name"><?= e($brand['name'] ?? '') ?></div>
        <div class="tag"><?= e($brand['tagline'] ?? '') ?></div>
      </div>
      <div class="footer-col">
        <h4>QUICK LINKS</h4>
        <ul>
          <?php foreach (array_slice($nav, 0, 3) as $item): ?>
            <li><a href="<?= e($item['url'] ?? '#') ?>"><?= e($item['label'] ?? '') ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="footer-col">
        <h4>&nbsp;</h4>
        <ul>
          <?php foreach (array_slice($nav, 3) as $item): ?>
            <li><a href="<?= e($item['url'] ?? '#') ?>"><?= e($item['label'] ?? '') ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="footer-col footer-newsletter">
        <h4><?= e($footer['follow_title'] ?? '') ?></h4>
        <div class="footer-social">
          <?php foreach (($footer['socials'] ?? []) as $item): ?>
            <a href="<?= e($item['url'] ?? '#') ?>" aria-label="<?= e($item['label'] ?? '') ?>"><?= social_icon((string) ($item['type'] ?? 'instagram')) ?></a>
          <?php endforeach; ?>
        </div>
        <h4 style="margin-bottom: 8px"><?= e($footer['newsletter_title'] ?? '') ?></h4>
        <p><?= e($newsletterSent ? 'Thank you for joining.' : ($footer['newsletter_text'] ?? '')) ?></p>
        <form action="submit-contact.php" method="post">
          <input type="hidden" name="type" value="newsletter">
          <input type="email" name="email" placeholder="Your Email" required>
          <button type="submit">Join</button>
        </form>
      </div>
    </div>
    <div class="footer-bottom">
      <span><?= e($footer['copyright'] ?? '') ?></span>
      <div class="right">
        <a href="<?= e($footer['privacy_url'] ?? '#') ?>"><?= e($footer['privacy_label'] ?? '') ?></a>
        <a href="<?= e($footer['terms_url'] ?? '#') ?>"><?= e($footer['terms_label'] ?? '') ?></a>
      </div>
    </div>
  </div>
</footer>

<script>
/* ── mobile menu ── */
(() => {
  const toggle = document.getElementById('nav-toggle');
  const menu   = document.getElementById('mobile-menu');
  if (!toggle || !menu) return;

  toggle.addEventListener('click', () => {
    const open = menu.classList.toggle('open');
    toggle.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', open);
    menu.setAttribute('aria-hidden', !open);
    document.body.style.overflow = open ? 'hidden' : '';
  });

  /* close on anchor click or Escape */
  menu.addEventListener('click', e => { if (e.target.tagName === 'A') closeMobileMenu(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMobileMenu(); });
})();

function closeMobileMenu() {
  const toggle = document.getElementById('nav-toggle');
  const menu   = document.getElementById('mobile-menu');
  if (!menu) return;
  menu.classList.remove('open');
  toggle.classList.remove('open');
  toggle.setAttribute('aria-expanded', false);
  menu.setAttribute('aria-hidden', true);
  document.body.style.overflow = '';
}

/* ── smooth scroll for nav links (offset for sticky nav) ── */
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const id = a.getAttribute('href').slice(1);
    const el = document.getElementById(id);
    if (!el) return;
    e.preventDefault();
    const offset = document.querySelector('.nav')?.offsetHeight || 0;
    window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - offset, behavior: 'smooth' });
  });
});

/* ── active nav link on scroll ── */
(() => {
  const links    = document.querySelectorAll('.nav-links a');
  const sections = [...document.querySelectorAll('section[id], div[id]')];
  if (!links.length) return;

  const io = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        const id = e.target.id;
        links.forEach(l => l.classList.toggle('active', l.getAttribute('href') === '#' + id));
      }
    });
  }, { rootMargin: '-30% 0px -60% 0px' });

  sections.forEach(s => io.observe(s));
})();

/* ── nav shadow on scroll ── */
(() => {
  const nav = document.querySelector('.nav');
  if (!nav) return;
  const update = () => nav.classList.toggle('scrolled', window.scrollY > 10);
  window.addEventListener('scroll', update, { passive: true });
  update();
})();

/* ── testimonial slider ── */
(() => {
  const root = document.querySelector('[data-testimonials]');
  if (!root) return;
  const slides = [...root.querySelectorAll('[data-slide]')];
  const dots = [...root.querySelectorAll('[data-dot]')];
  let idx = 0;
  const show = (next) => {
    idx = (next + slides.length) % slides.length;
    slides.forEach((slide, i) => { slide.style.display = i === idx ? '' : 'none'; });
    dots.forEach((dot, i) => dot.classList.toggle('active', i === idx));
  };
  root.querySelector('[data-prev]')?.addEventListener('click', () => show(idx - 1));
  root.querySelector('[data-next]')?.addEventListener('click', () => show(idx + 1));
  dots.forEach((dot, i) => dot.addEventListener('click', () => show(i)));
})();
</script>
</body>
</html>
