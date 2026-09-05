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

/** The same question for the public landing page's demo video. */
function intro_promo_on(): bool
{
    return help_setting('promo_video_on', '0') === '1' && trim(help_setting('promo_video_url', '')) !== '';
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

/**
 * Where uploaded walkthrough videos live, and how one gets there.
 *
 * The intro video used to be a URL box and nothing else, which meant a self-hosted file had
 * to be copied onto the server by hand before anyone could point at it. Uploading is the
 * common case — the videos are produced by tools/tour/record.py and then need to get in —
 * so it is a form field like every other asset.
 *
 * Validation follows the brand-logo upload in admin/settings.php: an extension allowlist, a
 * size cap, and a writability check that says what is wrong rather than failing silently.
 * The extra check here is the container magic, because "an mp4" is the one thing a caller
 * cannot verify from the name alone and this file is served back to browsers.
 */
const VIDEO_EXT = ['mp4', 'webm'];
const VIDEO_MAX = 120 * 1024 * 1024;    // 120 MB — a 4-minute 720p H.264 take is ~7 MB

/**
 * The largest video this server will really accept, in bytes.
 *
 * VIDEO_MAX is only our own ceiling. PHP ships with upload_max_filesize at 2M, which silently
 * rejects a perfectly ordinary 7 MB screen recording before a single line of this file runs —
 * so the number shown to the operator is the smallest of the three limits that actually apply,
 * not the one we would like to enforce. Telling someone "up to 120 MB" on a server that stops
 * at 2 MB is how you get a bug report that looks like a broken upload.
 */
function video_max_bytes(): int
{
    $ini = function (string $k): int {
        $v = trim((string) ini_get($k));
        if ($v === '') return PHP_INT_MAX;
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    };
    return (int) min(VIDEO_MAX, $ini('upload_max_filesize'), $ini('post_max_size'));
}

function video_dir_path(): string { return dirname(__DIR__) . '/assets/media'; }
function video_dir_url(): string  { return 'assets/media'; }

/**
 * Save an uploaded walkthrough video. Returns [url, error]; exactly one is non-empty.
 *
 * @param array $f one entry from $_FILES
 */
function video_store_upload(array $f): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE
     || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
        return ['', 'That file is larger than this server accepts. Check upload_max_filesize in php.ini.'];
    }
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
        return ['', 'The upload did not complete. Please try again.'];
    }

    $ext = strtolower((string) pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, VIDEO_EXT, true)) {
        return ['', 'Use an MP4 or WebM file. MP4 is the safer choice — some older iPhones will not play WebM.'];
    }
    if ((int) ($f['size'] ?? 0) > video_max_bytes()) {
        return ['', 'That file is over ' . (int) (video_max_bytes() / 1024 / 1024) . ' MB. Please use a smaller one.'];
    }

    /* The name says mp4; the bytes decide. Both containers are ISO/Matroska boxes with a
       recognisable signature in the first few bytes, and checking it stops a script renamed
       to .mp4 from being written into a web-served directory. */
    $head = (string) file_get_contents((string) $f['tmp_name'], false, null, 0, 12);
    $isMp4  = strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp';
    $isWebm = str_starts_with($head, "\x1A\x45\xDF\xA3");
    if (($ext === 'mp4' && !$isMp4) || ($ext === 'webm' && !$isWebm)) {
        return ['', 'That file is not a readable ' . strtoupper($ext) . ' video.'];
    }

    $dir = video_dir_path();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir) || !is_writable($dir)) {
        return ['', 'The folder wa-dashboard/assets/media is not writable. Create it and set its permissions to 755, then try again.'];
    }

    // A fresh name each time, so a browser that cached the old video does not keep showing it.
    $file = 'tour-' . date('Ymd-His') . '.' . $ext;
    if (!@move_uploaded_file((string) $f['tmp_name'], "$dir/$file")) {
        return ['', 'Could not save the file.'];
    }
    @chmod("$dir/$file", 0644);

    // Keep only the most recent few, so re-recording does not slowly fill the disk.
    $old = glob("$dir/tour-*") ?: [];
    if (count($old) > 4) {
        usort($old, fn($a, $b) => filemtime($a) <=> filemtime($b));
        foreach (array_slice($old, 0, count($old) - 4) as $stale) @unlink($stale);
    }
    return [video_dir_url() . '/' . $file, ''];
}

/**
 * A stored video path is relative to the app root, so a page that is not AT the root has to
 * say where the root is. External links (YouTube, or an absolute URL) are returned untouched.
 */
function video_src(string $url, string $base = ''): string
{
    $url = trim($url);
    if ($url === '' || preg_match('~^(https?:)?//~i', $url) || str_starts_with($url, '/')) return $url;
    return $base . $url;
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
