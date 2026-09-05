<?php
declare(strict_types=1);

/**
 * The walkthrough player, with its language toggle.
 *
 * Two places show a video — the public landing page and the Help centre — and they must show
 * the same thing. Rendering it twice by hand is how the two quietly diverge the first time one
 * of them is edited, so both call this.
 *
 * The toggle only appears when both languages actually have a file. A language switch that
 * lands on the same video either way reads as broken, and is worse than no switch at all.
 */

require_once __DIR__ . '/help.php';

/**
 * @param string $slot  'intro' (Help) or 'promo' (landing page)
 * @param string $base  path back to the app root, '' at the root and '../' in a subfolder
 * @param string $class extra classes for the outer frame
 */
function video_player_html(string $slot, string $base = '', string $class = ''): string
{
    $have = video_langs($slot);
    if (!$have) return '';

    // ?v=ar / ?v=en is how the toggle works with JavaScript switched off — it is a real link,
    // and the server honours it. With JavaScript the same buttons swap the source in place.
    $wanted = isset($_GET['v']) ? strtolower(substr((string) $_GET['v'], 0, 2)) : null;
    $lang   = video_default_lang($slot, $wanted);

    $srcFor = function (string $l) use ($slot, $base): array {
        $url = video_for($slot, $l);
        return [$url, video_is_file($url) ? video_src($url, $base) : video_embed_url($url)];
    };
    [$rawNow, $srcNow] = $srcFor($lang);
    if ($rawNow === '') return '';

    $id = 'vp-' . $slot;
    $h  = '';

    /* ── the toggle ── */
    if (count($have) > 1) {
        $label = ['ar' => 'العربية', 'en' => 'English'];
        $h .= '<div class="vlang" role="group" aria-label="Video language">';
        foreach ($have as $l) {
            [$raw, $src] = $srcFor($l);
            $h .= '<a class="vlang-b' . ($l === $lang ? ' on' : '') . '"'
                . ' href="?v=' . e($l) . '#' . e($id) . '"'
                . ' data-lang="' . e($l) . '"'
                . ' data-src="' . e($src) . '"'
                . ' data-file="' . ($raw !== '' && video_is_file($raw) ? '1' : '0') . '"'
                . ' lang="' . e($l) . '"'
                . ($l === $lang ? ' aria-current="true"' : '') . '>'
                . e($label[$l] ?? $l) . '</a>';
        }
        $h .= '</div>';
    }

    /* ── the player ──
       preload="metadata" so the frame knows its own size without pulling several megabytes
       down for a video most visitors will not press play on. */
    $h .= '<div class="' . e(trim('demo-frame ' . $class)) . '" id="' . e($id) . '">';
    $h .= video_is_file($rawNow)
        ? '<video src="' . e($srcNow) . '" controls playsinline preload="metadata"></video>'
        : '<iframe src="' . e($srcNow) . '" title="Product walkthrough" loading="lazy"'
          . ' allow="encrypted-media; fullscreen" allowfullscreen></iframe>';
    $h .= '</div>';

    /* ── swapping in place ──
       Progressive enhancement: without this the links above already work by reloading. With
       it the swap is instant and the choice is remembered, so someone who picked Arabic once
       is not asked again on the next page. Every storage access is guarded — a private window
       throws on localStorage rather than returning null. */
    if (count($have) > 1) {
        $h .= '<script>(function(){
  var wrap = document.getElementById(' . json_encode($id) . ');
  if (!wrap) return;
  var bar = wrap.previousElementSibling;
  if (!bar || !bar.classList.contains("vlang")) return;
  var KEY = "revenect.videoLang";

  function show(lang, remember){
    var btn = bar.querySelector(\'[data-lang="\' + lang + \'"]\');
    if (!btn) return;
    var isFile = btn.getAttribute("data-file") === "1";
    var src = btn.getAttribute("data-src");
    var cur = wrap.firstElementChild;
    // Replacing the element rather than setting .src: an <iframe> keeps playing the old video
    // otherwise, and a <video> needs load() anyway.
    var el = document.createElement(isFile ? "video" : "iframe");
    if (isFile) { el.controls = true; el.playsInline = true; el.preload = "metadata"; }
    else { el.title = "Product walkthrough"; el.loading = "lazy";
           el.setAttribute("allow","encrypted-media; fullscreen"); el.allowFullscreen = true; }
    el.src = src;
    if (cur) wrap.replaceChild(el, cur); else wrap.appendChild(el);
    Array.prototype.forEach.call(bar.children, function(b){
      var on = b.getAttribute("data-lang") === lang;
      b.classList.toggle("on", on);
      if (on) b.setAttribute("aria-current","true"); else b.removeAttribute("aria-current");
    });
    if (remember) { try { localStorage.setItem(KEY, lang); } catch(e){} }
  }

  bar.addEventListener("click", function(e){
    var b = e.target.closest("[data-lang]");
    if (!b) return;
    e.preventDefault();
    show(b.getAttribute("data-lang"), true);
  });

  // A ?v= in the URL is a deliberate choice for this visit and outranks the stored one.
  if (!/[?&]v=/.test(location.search)) {
    var saved = null; try { saved = localStorage.getItem(KEY); } catch(e){}
    if (saved && saved !== ' . json_encode($lang) . ' && bar.querySelector(\'[data-lang="\' + saved + \'"]\')) show(saved, false);
  }
})();</script>';
    }

    return $h;
}
