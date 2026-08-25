<?php
declare(strict_types=1);

/**
 * The help centre: FAQ, support requests, and the intro video.
 *
 * All three are operator-controlled content, so nothing here is hard-coded — the FAQ is rows,
 * the video is a setting, and both can be switched off entirely without a deploy.
 */

/** Live FAQ entries, in the order the operator arranged them. */
function faq_live(): array
{
    try { return db_all("SELECT * FROM faq_items WHERE status='active' ORDER BY sort, id"); }
    catch (Throwable $e) { return []; }   // migration 017 not applied yet
}

function help_setting(string $k, string $default = ''): string
{
    try { $v = db_val("SELECT v FROM app_settings WHERE k=?", [$k]); return $v === null ? $default : (string) $v; }
    catch (Throwable $e) { return $default; }
}

/** Is the intro video switched on AND actually pointing somewhere? */
function intro_video_on(): bool
{
    return help_setting('intro_video_on', '0') === '1' && trim(help_setting('intro_video_url', '')) !== '';
}

/**
 * A YouTube/Vimeo watch link turned into its embeddable form.
 *
 * Clients paste the URL from their browser's address bar — that page cannot be put in an
 * iframe, so accepting it verbatim gives a blank box with no clue why. Anything already
 * embeddable, or self-hosted, is passed through untouched.
 */
function video_embed_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('~youtube\.com/watch\?.*v=([A-Za-z0-9_-]{6,})~', $url, $m)
     || preg_match('~youtu\.be/([A-Za-z0-9_-]{6,})~', $url, $m)
     || preg_match('~youtube\.com/shorts/([A-Za-z0-9_-]{6,})~', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }
    return $url;
}

/** True when the URL is a file we should render with <video> rather than an <iframe>. */
function video_is_file(string $url): bool
{
    return (bool) preg_match('~\.(mp4|webm|ogg|mov)(\?|$)~i', trim($url));
}

/**
 * The floating Help / Watch buttons, injected on every signed-in page.
 *
 * Rendered as a fixed pair bottom-right, deliberately clear of the mobile tab bar so they
 * never sit on top of navigation.
 */
function help_launcher_html(string $base = './'): string
{
    $video   = trim(help_setting('intro_video_url', ''));
    $showVid = intro_video_on();
    $embed   = $showVid ? video_embed_url($video) : '';
    $isFile  = $showVid && video_is_file($video);

    ob_start(); ?>
<div class="help-dock">
  <?php if ($showVid): ?>
    <button type="button" class="help-fab video" id="fab-video" title="Watch the intro" aria-label="Watch the intro video">
      <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round">
        <rect x="2" y="4" width="16" height="12" rx="2.5"/><path d="M8.5 8l4 2-4 2V8z" fill="currentColor" stroke="none"/>
      </svg>
    </button>
  <?php endif; ?>
  <a class="help-fab help" href="<?= e($base) ?>help.php" title="Help &amp; support" aria-label="Help and support">
    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
      <circle cx="10" cy="10" r="7.5"/><path d="M7.9 7.6a2.2 2.2 0 114 1.3c-.7.8-1.4 1-1.4 2"/><path d="M10 14.2h.01"/>
    </svg>
  </a>
</div>

<?php if ($showVid): ?>
<div class="video-back" id="video-back" hidden>
  <div class="video-box">
    <button type="button" class="video-close" id="video-close" aria-label="Close">&#x2715;</button>
    <div class="video-frame" id="video-frame" data-src="<?= e($embed) ?>" data-file="<?= $isFile ? '1' : '0' ?>"></div>
  </div>
</div>
<script>
(function(){
  var back=document.getElementById('video-back'), frame=document.getElementById('video-frame'),
      open=document.getElementById('fab-video'), close=document.getElementById('video-close');
  if(!back||!frame||!open) return;
  function show(){
    // Built on open, torn down on close: an iframe left in the DOM keeps buffering, and a
    // paused <video> would otherwise resume mid-sentence the next time it is opened.
    frame.innerHTML = frame.dataset.file === '1'
      ? '<video src="'+frame.dataset.src+'" controls autoplay playsinline style="width:100%;height:100%"></video>'
      : '<iframe src="'+frame.dataset.src+'" allow="autoplay; encrypted-media; fullscreen" allowfullscreen style="width:100%;height:100%;border:0"></iframe>';
    back.hidden=false; document.body.style.overflow='hidden';
  }
  function hide(){ frame.innerHTML=''; back.hidden=true; document.body.style.overflow=''; }
  open.addEventListener('click',show);
  close.addEventListener('click',hide);
  back.addEventListener('click',function(e){ if(e.target===back) hide(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape' && !back.hidden) hide(); });
})();
</script>
<?php endif;
    return (string) ob_get_clean();
}
