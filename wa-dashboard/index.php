<?php
declare(strict_types=1);
/**
 * The public landing page — the first thing anyone sees at the app's address.
 *
 * Sign-in moved to login.php when this page took over the root: everything that needs a
 * session redirects there, so an expired session never lands on a hero section with no
 * password box in sight.
 *
 * Two things on this page are read from the database rather than written here, because they
 * are the operator's to change without a deploy:
 *   - the FAQ, which is the SAME rows the in-app Help centre shows (Admin → Help Content),
 *     so the public answer and the answer a client reads can never drift apart;
 *   - the plans, from Admin → Plans. The pricing section hides itself entirely while no
 *     plan is marked active, rather than showing an empty table.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/help.php';
require_once __DIR__ . '/includes/notify.php';

// First run: no admin yet → go create one.
if (!admin_exists()) {
    redirect('setup.php');
}

// Already signed in → straight to work. Nobody wants the brochure twice a day.
$u = current_user();
if ($u) {
    redirect($u['role'] === 'admin' ? 'admin/index.php' : 'client/index.php');
}

/* ── Request access ──────────────────────────────────────────────────────────────
   There is no self-signup: an account is created by the operator, with a WhatsApp number
   attached to it. So the front door is a request, and it lands in the same place support
   requests do — Admin → Help Content — rather than in an inbox that may not be watched. */
$sent = false;
$err  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'access') {
    verify_csrf();
    $name    = trim((string) ($_POST['name'] ?? ''));
    $email   = trim((string) ($_POST['email'] ?? ''));
    $company = trim((string) ($_POST['company'] ?? ''));
    $phone   = trim((string) ($_POST['phone'] ?? ''));
    $about   = trim((string) ($_POST['about'] ?? ''));
    // Hidden from people, irresistible to bots. Silently accepted so the bot doesn't retry.
    $trap    = trim((string) ($_POST['website'] ?? ''));

    if ($trap !== '') {
        $sent = true;
    } elseif ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please give us your name and a valid email address.';
    } else {
        $body = "Name: {$name}\nEmail: {$email}\nBusiness: " . ($company ?: '—')
              . "\nWhatsApp: " . ($phone ?: '—') . "\n\n" . ($about ?: '(no message)');
        try {
            db_run("INSERT INTO support_tickets (client_id,user_id,name,email,subject,message,status,created_at)
                    VALUES (NULL, NULL, ?, ?, ?, ?, 'open', NOW())",
                [mb_substr($name, 0, 120), mb_substr($email, 0, 190),
                 'Access request: ' . mb_substr($company ?: $name, 0, 150), $body]);
            @notify_admin('Access request from ' . $name, $body);
            $sent = true;
        } catch (Throwable $e) {
            error_log('access request failed: ' . $e->getMessage());
            $err = 'Something went wrong on our side. Please try again in a moment.';
        }
    }
}

$appName = brand_name();
$faqs    = faq_live();

// Admin → Settings → Branding. Passed to CSS as a variable so one number drives the tag,
// the bar height, the footer and the scroll offset together.
$logoH = site_logo_height();
$footH = max(20, (int) round($logoH * 0.8));

// Only plans the operator has switched on. Empty is a valid answer: the section disappears.
try {
    $plans = db_all("SELECT * FROM plans WHERE is_active=1 ORDER BY sort, price_month");
} catch (Throwable $e) {
    $plans = [];
}

/** The one inline icon left on the page: the mobile menu button. */
function ico(string $d): string
{
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $d . '</svg>';
}

$features = [
    ['Campaigns that actually arrive',
     'Pick an approved template or write the message yourself, pull each person’s name and details in from their contact record, send now or schedule it. Progress, delivery and read counts update live.'],
    ['Automations on a canvas',
     'Drag steps, wire them together, and watch a conversation branch. 22 kinds of step — ask a question, send buttons, call a web service, split-test, wait until Tuesday at 9. Five ready-made flows to start from.'],
    ['Try it before anyone sees it',
     'Preview a flow as a real WhatsApp conversation, answering as the customer, with variables filled in. Nothing is sent, nothing is charged. Then Check for problems finds the dead ends before your customers do.'],
    ['An AI that only knows your business',
     'Give it your price list, your policies, your FAQ. It answers from that and nothing else, chases the goals you set, captures what you asked for, and hands over to a human when it should.'],
    ['Lead scoring you can read',
     'Built for property developers: it works the sheet your ads fill, asks about budget, area and payment, and hands every lead back graded hot, warm or cold with the reason attached.'],
    ['A live inbox on your phone',
     'Every conversation in one thread list, replies from any device, push notifications when someone answers. Install it to your home screen and it behaves like an app.'],
];

$steps = [
    ['Connect a number', 'Link your official WhatsApp Business account, or scan a QR code with the phone you already use. Either way you are sending within the hour.'],
    ['Bring your people in', 'Upload a CSV, paste a list, or point us at a Google Sheet that keeps filling itself. Opt-outs are honoured from the first message.'],
    ['Build the conversation', 'Start from a ready-made flow or an empty canvas. Preview it end to end, fix what the checker flags, then switch it on.'],
    ['Send, then watch', 'Delivery and read receipts land back on the campaign. Each step shows how many people reached it and how many stopped there.'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($appName) ?> — WhatsApp campaigns, automations and a live inbox</title>
<meta name="description" content="<?= e($appName) ?> turns WhatsApp into a working sales channel: bulk campaigns, an automation builder you can preview before it sends, an AI agent that answers from your own knowledge, and lead scoring you can read.">
<meta name="theme-color" content="#0B1020">
<meta property="og:title" content="<?= e($appName) ?> — <?= e(BRAND_TAGLINE) ?>">
<meta property="og:description" content="Campaigns, automations, an AI agent and a live inbox — on the WhatsApp number your customers already message.">
<meta property="og:type" content="website">
<link rel="icon" href="assets/icons/favicon.png">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="assets/site.css?v=<?= @filemtime(__DIR__ . '/assets/site.css') ?: '1' ?>">
<!-- Reveal-on-scroll hides content until it is reached, so it may only be armed once we know
     scripting is available. Without this class nothing is ever hidden. -->
<script>document.documentElement.className += ' js';</script>
</head>
<body style="--logo-h:<?= $logoH ?>px;--foot-logo-h:<?= $footH ?>px">

<!-- ── nav ───────────────────────────────────────────────────────────────── -->
<header class="nav">
  <div class="wrap nav-in">
    <a class="nav-logo" href="#top" aria-label="<?= e($appName) ?>"><?= brand_logo('full', $logoH, './', false) ?></a>
    <nav class="nav-links" id="nav-links">
      <a href="#what">What it does</a>
      <a href="#how">How it works</a>
      <a href="#channels">Your number</a>
      <a href="#qualifier">Lead Qualifier</a>
      <?php if ($plans): ?><a href="#pricing">Pricing</a><?php endif; ?>
      <?php if ($faqs): ?><a href="#faq">FAQ</a><?php endif; ?>
    </nav>
    <div class="nav-cta">
      <a class="signin" href="login.php">Log in</a>
      <a class="btn btn-primary btn-sm" href="#access">Get started</a>
      <button class="nav-burger" id="burger" aria-label="Menu" aria-expanded="false">
        <?= ico('<path d="M4 7h16M4 12h16M4 17h16"/>') ?>
      </button>
    </div>
  </div>
</header>

<!-- ── hero ──────────────────────────────────────────────────────────────── -->
<section class="hero" id="top">
  <span class="eyebrow"><?= e(BRAND_TAGLINE) ?></span>
  <h1>Your customers already use WhatsApp. <span class="grad">Start selling there.</span></h1>
  <p class="hero-sub">
    <?= e($appName) ?> sends the campaign, answers the reply, scores the lead and hands you
    the conversation — on the number your customers already message. One dashboard, no
    developers.
  </p>
  <div class="hero-cta">
    <a class="btn btn-primary" href="#access">Get started</a>
    <a class="btn btn-ghost" href="#how">See how it works</a>
  </div>
  <p class="hero-note">Official WhatsApp Business API · or your own number by QR</p>

  <!-- The product on the fold: the flow that ran, and the conversation it produced.
       Not a screenshot — real nodes and real bubbles, so it can never go stale. -->
  <div class="hero-shot" aria-hidden="true">
    <div class="hero-shot-in">
      <div class="canvas">
        <div class="canvas-bar"><span>Automation canvas</span><span class="live">live · 412 in flight</span></div>
        <div class="node">
          <div class="node-h">Send template</div>
          <div class="node-b">autumn_offer · 20% this week</div>
          <div class="node-s"><span>412 reached</span></div>
        </div>
        <div class="wire"></div>
        <div class="node on">
          <div class="node-h">Ask &amp; capture → city</div>
          <div class="node-b">“Which city are we delivering to?”</div>
          <div class="node-s"><span>338 reached</span><span class="drop">44 stopped</span></div>
        </div>
        <div class="wire"></div>
        <div class="node-row">
          <div class="node">
            <div class="node-h">If / then</div>
            <div class="node-b">city is <b>Alexandria</b></div>
          </div>
          <div class="node">
            <div class="node-h">AI score</div>
            <div class="node-b">🎯 buying intent · max 100</div>
          </div>
        </div>
      </div>

      <div class="phone">
        <div class="phone-bar">
          <span class="phone-av"></span>
          <span class="phone-who">Nile Interiors<small>● online</small></span>
        </div>
        <div class="thread">
          <div class="bub out">Hi Mona 👋 Our autumn collection is live — 20% off this week.<span class="tick">✓✓</span></div>
          <div class="bub in">Do you deliver to Alexandria?</div>
          <div class="bub out">We do — 2 to 3 days, free over EGP 2,000. Want me to send the catalogue?
            <span class="bub-btns"><span>Yes, send it</span><span>Talk to someone</span></span>
          </div>
          <div class="bub in">Yes, send it</div>
        </div>
        <div class="phone-tag"><span>AI scored this lead</span><span class="hot">HOT · 82</span></div>
      </div>
    </div>
  </div>
</section>

<!-- ── the honest numbers: what the product has, not invented metrics ────── -->
<section class="strip">
  <div class="wrap strip-in">
    <div class="strip-i" data-reveal><span class="k"><span class="grad">2</span></span><span class="l">ways to send — official API or your own number</span></div>
    <div class="strip-i" data-reveal><span class="k"><span class="grad">22</span></span><span class="l">kinds of automation step</span></div>
    <div class="strip-i" data-reveal><span class="k"><span class="grad">5</span></span><span class="l">ready-made automations, installed in one click</span></div>
    <div class="strip-i" data-reveal><span class="k"><span class="grad">24/7</span></span><span class="l">worker — campaigns keep sending after you close the tab</span></div>
  </div>
</section>

<!-- ── what it does ──────────────────────────────────────────────────────── -->
<section class="section tint" id="what">
  <div class="wrap">
    <div class="section-head">
      <span class="eyebrow">What it does</span>
      <h2>Everything between “send the offer” and “close the sale”</h2>
      <p>The broadcast is the easy half. The reply is where the money is, so that is where
         most of this product lives.</p>
    </div>
    <div class="grid">
      <?php foreach ($features as $i => [$title, $copy]): ?>
        <article class="f" data-reveal>
          <span class="f-num"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
          <h3><?= e($title) ?></h3>
          <p><?= e($copy) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── how it works ──────────────────────────────────────────────────────── -->
<section class="section" id="how">
  <div class="wrap">
    <div class="section-head">
      <span class="eyebrow">How it works</span>
      <h2>Live by this afternoon</h2>
      <p>Four steps, in this order. Nothing here needs a developer.</p>
    </div>
    <div class="steps">
      <?php foreach ($steps as $i => [$t, $c]): ?>
        <div class="step" data-reveal>
          <div class="step-n"><span><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span><span class="rule"></span></div>
          <h3><?= e($t) ?></h3><p><?= e($c) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── the two channels: the real differentiator, stated honestly ────────── -->
<section class="section tint" id="channels">
  <div class="wrap">
    <div class="section-head">
      <span class="eyebrow">Your number, your choice</span>
      <h2>Two ways to send. Start with whichever you can today.</h2>
      <p>The official API is the right answer for most businesses. But it needs a Meta
         business account, an approved template for every first message, and a number you
         are willing to dedicate to it — and plenty of businesses simply have not got there
         yet. So the same dashboard also drives the phone already on the desk.</p>
    </div>
    <div class="two">
      <div class="ch lead" data-reveal>
        <span class="ch-tag">Recommended</span>
        <h3>Official WhatsApp Business API</h3>
        <p>A verified sender, at scale, with Meta’s blessing.</p>
        <ul class="ticks">
          <li>Green tick and your business name on every message</li>
          <li>Tappable reply buttons and menu lists of up to 10 options</li>
          <li>Delivery and read receipts back on every campaign</li>
          <li>No practical sending limit beyond your tier</li>
          <li>Bring your own Meta account — Meta bills you directly, at their rates</li>
        </ul>
        <div class="ch-note info">
          <strong>What it needs:</strong> a Meta business account, a phone number not already
          on WhatsApp, and a short template approval for each message you send first — usually
          minutes, sometimes a few hours. We walk you through all of it.
        </div>
      </div>
      <div class="ch" data-reveal>
        <span class="ch-tag">No approval needed</span>
        <h3>The number you already use</h3>
        <p>Scan a QR code with your phone and start sending in minutes.</p>
        <ul class="ticks">
          <li>No Meta business account, no template approval, no waiting</li>
          <li>Write the message yourself, with an image if you want one</li>
          <li>Same automations, same inbox, same lead scoring</li>
          <li>Paced automatically — a batch, then a pause, to protect the number</li>
          <li class="no">Choices arrive as a numbered list, not tappable buttons</li>
        </ul>
        <div class="ch-note">
          <strong>Said plainly:</strong> bulk sending from a personal number is against
          WhatsApp’s terms, and a number can be restricted or banned for it. Use a number you
          can afford to lose, keep the volume sensible, and move to the official API when you
          are ready. We will not pretend otherwise.
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── the builder ───────────────────────────────────────────────────────── -->
<section class="section builder on-dark">
  <div class="wrap builder-in">
    <div>
      <span class="eyebrow">The builder</span>
      <h2>See the conversation before your customer does</h2>
      <p class="lead">
        A broken flow doesn’t throw an error. It goes quiet, and you find out from the people
        who stopped replying. So this builder shows you the whole conversation before it runs,
        and refuses to go live with a step that leads nowhere.
      </p>
      <ul class="ticks">
        <li><strong>Preview</strong> runs the real engine and sends nothing. No messages, no credits.</li>
        <li><strong>Check for problems</strong> catches unreachable steps, questions with no way out, an AI step with no fallback, a deleted template, a message the 24-hour rule would block.</li>
        <li><strong>Per-step numbers</strong> show how many people reached each step and how many stopped there — so you fix the step that loses them.</li>
        <li><strong>It saves itself</strong>, undoes anything, and works on a phone.</li>
      </ul>
    </div>
    <div class="canvas" aria-hidden="true">
      <div class="node">
        <div class="node-h">Send template</div>
        <div class="node-b">autumn_offer · 20% this week</div>
        <div class="node-s"><span>412 reached</span></div>
      </div>
      <div class="wire"></div>
      <div class="node on">
        <div class="node-h">Ask &amp; capture → city</div>
        <div class="node-b">“Which city are we delivering to?”</div>
        <div class="node-s"><span>338 reached</span><span class="drop">44 stopped</span></div>
      </div>
      <div class="wire"></div>
      <div class="node-row">
        <div class="node">
          <div class="node-h">If / then</div>
          <div class="node-b">city is <b>Alexandria</b></div>
        </div>
        <div class="node">
          <div class="node-h">AI score</div>
          <div class="node-b">🎯 buying intent · max 100</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── lead qualifier ────────────────────────────────────────────────────── -->
<section class="section" id="qualifier">
  <div class="wrap">
    <div class="section-head">
      <span class="eyebrow">Lead Qualifier</span>
      <h2>Built for real estate. Your sales team only calls the ones worth calling.</h2>
      <p>Ads fill a sheet with hundreds of numbers and nobody knows which twenty are serious.
         This was built for exactly that problem, with property developers, and it has been
         through the hands of several large real-estate companies. It reaches every lead on
         WhatsApp, asks what your sales team would have asked, and hands them back sorted.</p>
    </div>

    <div class="lq">
      <div>
        <ol class="lq-steps">
          <li>
            <h3>It watches your sheet</h3>
            <p>Point it at the Google Sheet your ads already fill. New rows are picked up on
               their own, every few minutes. Local numbers get your country code added;
               anyone already in the qualifier is never messaged twice.</p>
          </li>
          <li>
            <h3>It opens the conversation</h3>
            <p>An approved template on the official API, or a message you wrote if you are
               sending from your own number — with the project image attached if you want one.</p>
          </li>
          <li>
            <h3>The AI asks what you would ask</h3>
            <p>Budget, area, unit type, cash or instalments, when they want to move. It
               answers their questions too — from your price list and your project details
               and nothing else — in your dialect, with the persona you set. It pursues the
               goals you give it and pulls out the details you asked it to capture.</p>
          </li>
          <li>
            <h3>Every lead comes back graded</h3>
            <p>You write what makes a good lead; the AI scores the whole conversation against
               it and marks each one <strong>hot</strong>, <strong>warm</strong> or
               <strong>cold</strong> at the thresholds you choose. The score, the reason and
               the full conversation sit together — and can be written straight back out to a
               sheet your team already works from.</p>
          </li>
        </ol>
      </div>

      <!-- A lead as it actually comes back, not an illustration of one. -->
      <aside class="lead-card" data-reveal aria-hidden="true">
        <div class="lead-top">
          <div>
            <strong>Mona Adel</strong>
            <span class="lead-ph">+20 100 000 0000</span>
          </div>
          <span class="pill-hot">HOT · 82</span>
        </div>
        <div class="lead-meter"><span style="width:82%"></span></div>
        <dl class="lead-fields">
          <div><dt>Budget</dt><dd>6–7M EGP</dd></div>
          <div><dt>Area</dt><dd>New Cairo, Fifth Settlement</dd></div>
          <div><dt>Unit</dt><dd>3-bed apartment</dd></div>
          <div><dt>Payment</dt><dd>Instalments, 8 years</dd></div>
          <div><dt>Timing</dt><dd>Within 3 months</dd></div>
        </dl>
        <p class="lead-why"><span>Why hot</span> Budget matches the project, ready to pay in
          instalments, asked twice about delivery date, and requested a site visit.</p>
        <div class="lead-foot">Qualified in 6 messages · no one from your team involved</div>
      </aside>
    </div>
  </div>
</section>

<?php if ($plans): ?>
<!-- ── pricing: whatever the operator has switched on in Admin → Plans ───── -->
<section class="section tint" id="pricing">
  <div class="wrap">
    <div class="section-head">
      <span class="eyebrow">Pricing</span>
      <h2>Plans</h2>
      <p>Each plan includes a monthly allowance of message credits. One credit is one
         outbound message; replies you receive are free.</p>
    </div>
    <div class="plans">
      <?php foreach ($plans as $i => $p):
        $hi = count($plans) > 1 && $i === (int) floor((count($plans) - 1) / 2); ?>
        <div class="plan<?= $hi ? ' hi' : '' ?>" data-reveal>
          <h3><?= e((string) $p['name']) ?></h3>
          <div class="price"><?= e((string) ($p['currency'] ?: 'USD')) ?> <?= number_format((float) $p['price_month'], 0) ?></div>
          <div class="per">per month</div>
          <ul class="ticks">
            <li><strong><?= number_format((int) $p['included_credits']) ?></strong> message credits included</li>
            <?php if ((float) $p['overage_per_1k'] > 0): ?>
              <li>Then <?= e((string) ($p['currency'] ?: 'USD')) ?> <?= number_format((float) $p['overage_per_1k'], 2) ?> per extra 1,000</li>
            <?php endif; ?>
            <li><?= (int) $p['max_numbers'] ?> WhatsApp number<?= (int) $p['max_numbers'] === 1 ? '' : 's' ?> · <?= (int) $p['max_seats'] ?> team seat<?= (int) $p['max_seats'] === 1 ? '' : 's' ?></li>
            <li><?= (int) $p['max_contacts'] ? number_format((int) $p['max_contacts']) . ' contacts' : 'Unlimited contacts' ?></li>
            <li><?= (int) $p['max_flows'] ? (int) $p['max_flows'] . ' automations' : 'Unlimited automations' ?></li>
            <li><?= $p['ai_mode'] === 'included'
                    ? 'AI included — ' . number_format((int) $p['included_ai_credits']) . ' credits'
                    : 'Use your own AI key, at no extra cost' ?></li>
          </ul>
          <a class="btn <?= $hi ? 'btn-primary' : 'btn-ghost' ?>" href="#access">Get started</a>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="price-note">
      <strong>Two things that are not on this list.</strong> If you send through your own
      Meta account, WhatsApp bills you directly for messages at their published rates —
      nothing about that passes through us. And if you bring your own AI key, the AI costs
      you exactly what your provider charges and nothing more.
    </p>
  </div>
</section>
<?php endif; ?>

<?php if ($faqs): ?>
<!-- ── FAQ: the same rows the in-app Help centre shows ───────────────────── -->
<section class="section" id="faq">
  <div class="wrap">
    <div class="section-head">
      <span class="eyebrow">Questions</span>
      <h2>The things people ask before they start</h2>
      <p>Still unsure? Ask us below — a person reads every one of these.</p>
    </div>
    <div class="faq">
      <?php foreach ($faqs as $i => $f): ?>
        <details class="qa"<?= $i === 0 ? ' open' : '' ?>>
          <summary><?= e((string) $f['question']) ?></summary>
          <div class="qa-a"><?= nl2br(e((string) $f['answer'])) ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── request access ────────────────────────────────────────────────────── -->
<section class="section cta on-dark" id="access">
  <div class="wrap cta-in">
    <div>
      <span class="eyebrow">Get started</span>
      <h2>Tell us about your business</h2>
      <p class="lead">
        We set the account up with you rather than handing you an empty box: your number
        connected, your first list imported, and one automation working before you are left
        alone with it.
      </p>
      <ul class="ticks">
        <li>A reply the same working day</li>
        <li>We tell you which of the two channels fits you, honestly</li>
        <li>Your data is yours — messages, campaigns and contacts are never deleted</li>
      </ul>
    </div>

    <form class="form" method="post" action="#access">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="access">
      <?php if ($sent): ?>
        <div class="note ok">Thank you — that is with us. We will reply by email today or
          the next working day.</div>
      <?php elseif ($err): ?>
        <div class="note bad"><?= e($err) ?></div>
      <?php endif; ?>

      <div class="fi">
        <label for="a-name">Your name</label>
        <input id="a-name" type="text" name="name" value="<?= old('name') ?>" required maxlength="120" autocomplete="name">
      </div>
      <div class="fi">
        <label for="a-email">Email</label>
        <input id="a-email" type="email" name="email" value="<?= old('email') ?>" required maxlength="190" autocomplete="email">
      </div>
      <div class="fi">
        <label for="a-company">Business name</label>
        <input id="a-company" type="text" name="company" value="<?= old('company') ?>" maxlength="150" autocomplete="organization">
      </div>
      <div class="fi">
        <label for="a-phone">WhatsApp number <span style="font-weight:400;opacity:.6">(optional)</span></label>
        <input id="a-phone" type="tel" name="phone" value="<?= old('phone') ?>" maxlength="32" placeholder="+20 …" autocomplete="tel">
      </div>
      <div class="fi">
        <label for="a-about">What do you want to use it for?</label>
        <textarea id="a-about" name="about" maxlength="2000" placeholder="e.g. we have 4,000 customers in a sheet and want to send an offer, then answer whoever replies"><?= old('about') ?></textarea>
      </div>
      <!-- Hidden from people, irresistible to bots. -->
      <div style="position:absolute;left:-9999px" aria-hidden="true">
        <label for="a-website">Website</label>
        <input id="a-website" type="text" name="website" tabindex="-1" autocomplete="off">
      </div>
      <button type="submit" class="btn btn-primary">Request access</button>
      <p class="fine">Already have an account? <a href="login.php" style="color:#fff">Log in</a>.</p>
    </form>
  </div>
</section>

<!-- ── footer ────────────────────────────────────────────────────────────── -->
<footer class="foot">
  <div class="wrap">
    <div class="foot-in">
      <div style="color:#fff"><?= brand_logo('full', $footH, './', true) ?></div>
      <nav class="foot-links">
        <a href="#what">What it does</a>
        <a href="#how">How it works</a>
        <a href="#qualifier">Lead Qualifier</a>
        <?php if ($plans): ?><a href="#pricing">Pricing</a><?php endif; ?>
        <?php if ($faqs): ?><a href="#faq">FAQ</a><?php endif; ?>
        <a href="#access">Get started</a>
        <a href="login.php">Log in</a>
      </nav>
    </div>
    <div class="foot-legal">
      © <?= date('Y') ?> <?= e(BRAND_PARENT) ?>. <?= e($appName) ?> is a product of <?= e(BRAND_PARENT) ?>.
      Not affiliated with or endorsed by WhatsApp or Meta. WhatsApp is a trademark of Meta Platforms, Inc.
    </div>
  </div>
</footer>

<script>
/* The whole page is server-rendered; this is the only behaviour it needs. */
/* Sections arrive as you reach them. Skipped entirely when the visitor has asked for less
   motion, and skipped when IntersectionObserver is missing — in both cases the CSS default
   would leave the page invisible, so the class goes on immediately instead. */
(function () {
  var els = document.querySelectorAll('[data-reveal]');
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (still || !('IntersectionObserver' in window)) {
    els.forEach(function (el) { el.classList.add('in'); });
    return;
  }
  var io = new IntersectionObserver(function (rows) {
    rows.forEach(function (r) { if (r.isIntersecting) { r.target.classList.add('in'); io.unobserve(r.target); } });
    // Fire on the first pixel, and a little before it: a fast scroll must never leave a
    // card blank on screen while it waits for a threshold to be crossed.
  }, { rootMargin: '0px 0px 5% 0px', threshold: 0 });
  els.forEach(function (el, i) { el.style.transitionDelay = (i % 4) * 60 + 'ms'; io.observe(el); });
})();

(function () {
  var b = document.getElementById('burger'), n = document.getElementById('nav-links');
  if (!b || !n) return;
  b.addEventListener('click', function () {
    var open = n.classList.toggle('open');
    b.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  n.addEventListener('click', function (e) {
    if (e.target.tagName === 'A') { n.classList.remove('open'); b.setAttribute('aria-expanded', 'false'); }
  });
})();
</script>
</body>
</html>
