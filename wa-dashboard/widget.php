<?php
/**
 * Website chat — what the client's website loads.
 *
 *   widget.php?k=KEY&js=1   the one-line embed: a chat bubble in the corner of the page
 *                           <script src=".../widget.php?k=KEY&js=1" async data-title="…" data-color="#…"></script>
 *   widget.php?k=KEY        the chat itself (the bubble opens it in an iframe; put it in your own
 *                           <iframe> to show the chat inside a section: add &inline=1)
 *
 * A page can change the title, colours, welcome words, teaser and more for itself, without a new
 * widget: data-* attributes on the script, or o=<base64 JSON> on the iframe URL.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config_loader.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/push.php';
require_once __DIR__ . '/includes/webchat.php';

$key = (string) ($_GET['k'] ?? '');
$widget = webchat_widget_by_key($key);
$base = app_base_url();
$J = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

/* ───────────────────── the embed script ───────────────────── */
if (($_GET['js'] ?? '') === '1') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');
    if (!$widget || $widget['status'] !== 'active') { echo "/* Revenect chat: this widget is not available. */"; exit; }
    $s = webchat_settings($widget);
    $launch = ['color' => $s['color'], 'text_color' => $s['text_color'], 'position' => $s['position'], 'teaser' => $s['teaser'], 'auto_open' => $s['auto_open'], 'title' => $s['title']];
    ?>
(function () {
  var K = <?= json_encode($key, $J) ?>, BASE = <?= json_encode($base, $J) ?>, CFG = <?= json_encode($launch, $J) ?>;
  var me = document.currentScript || (function () { var a = document.querySelectorAll('script[src*="widget.php"]'); for (var i = a.length - 1; i >= 0; i--) if (a[i].src.indexOf(K) > -1) return a[i]; return null; })();
  if (window['__revenect_' + K]) return; window['__revenect_' + K] = 1;
  var d = (me && me.dataset) || {}, o = {};
  // What this page changes for itself: data-title, data-subtitle, data-avatar, data-color, data-text-color, data-position,
  // data-dir, data-welcome, data-teaser, data-placeholder, data-auto-open (seconds), data-context (a label for this page).
  var map = {title:'title', subtitle:'subtitle', avatar:'avatar', color:'color', textColor:'text_color', position:'position', dir:'dir',
             welcome:'welcome', teaser:'teaser', placeholder:'placeholder', autoOpen:'auto_open', context:'context'};
  for (var k in map) if (d[k] !== undefined && d[k] !== '') o[map[k]] = d[k];
  for (var c in CFG) if (o[c] !== undefined) CFG[c] = o[c];
  if (!/^#[0-9a-fA-F]{6}$/.test(CFG.color)) CFG.color = '#0F766E';
  if (!/^#[0-9a-fA-F]{6}$/.test(CFG.text_color)) CFG.text_color = '#FFFFFF';
  var left = CFG.position === 'left', side = left ? 'left' : 'right';
  var enc = function (x) { try { return btoa(unescape(encodeURIComponent(JSON.stringify(x)))); } catch (e) { return ''; } };
  var src = BASE + '/widget.php?k=' + encodeURIComponent(K) + '&o=' + encodeURIComponent(enc(o)) + '&page=' + encodeURIComponent(String(location.href).slice(0, 480));

  var root = document.createElement('div');
  root.setAttribute('data-revenect-chat', K);
  root.style.cssText = 'position:fixed;z-index:2147483000;bottom:20px;' + side + ':20px;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif';
  var btn = document.createElement('button');
  btn.type = 'button'; btn.setAttribute('aria-label', CFG.title || 'Chat');
  btn.style.cssText = 'width:60px;height:60px;border-radius:50%;border:0;cursor:pointer;background:' + CFG.color + ';color:' + CFG.text_color +
    ';box-shadow:0 8px 24px rgba(0,0,0,.22);display:flex;align-items:center;justify-content:center;transition:transform .15s;position:relative;padding:0';
  var ICON_CHAT = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>';
  var ICON_X = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
  btn.innerHTML = ICON_CHAT;
  btn.onmouseenter = function () { btn.style.transform = 'scale(1.06)'; }; btn.onmouseleave = function () { btn.style.transform = ''; };
  var badge = document.createElement('span');
  badge.style.cssText = 'position:absolute;top:-2px;' + (left ? 'right' : 'left') + ':-2px;min-width:20px;height:20px;border-radius:10px;background:#E11D48;color:#fff;font-size:11px;font-weight:700;display:none;align-items:center;justify-content:center;padding:0 5px;border:2px solid #fff';
  btn.appendChild(badge);
  var teaser = null;
  if (CFG.teaser) {
    teaser = document.createElement('div');
    teaser.textContent = CFG.teaser; teaser.dir = 'auto';
    teaser.style.cssText = 'position:absolute;bottom:70px;' + side + ':0;max-width:240px;width:max-content;background:#fff;color:#111827;padding:10px 14px;border-radius:14px;box-shadow:0 8px 24px rgba(0,0,0,.16);font-size:14px;line-height:1.4;cursor:pointer';
    teaser.onclick = function () { open(); };
  }
  var frameBox = document.createElement('div');
  frameBox.style.cssText = 'position:absolute;bottom:76px;' + side + ':0;width:380px;height:600px;max-height:calc(100vh - 110px);max-width:calc(100vw - 40px);border-radius:16px;overflow:hidden;box-shadow:0 18px 50px rgba(0,0,0,.25);display:none;background:#fff';
  var frame = null, isOpen = false;
  function fit() {
    var small = window.innerWidth < 520;
    frameBox.style.position = small ? 'fixed' : 'absolute';
    frameBox.style.inset = small ? '0' : '';
    frameBox.style.width = small ? '100%' : '380px';
    frameBox.style.height = small ? '100%' : '600px';
    frameBox.style.maxWidth = small ? '100%' : 'calc(100vw - 40px)';
    frameBox.style.maxHeight = small ? '100%' : 'calc(100vh - 110px)';
    frameBox.style.borderRadius = small ? '0' : '16px';
    if (!small) { frameBox.style.bottom = '76px'; frameBox.style[side] = '0'; }
  }
  function tell(msg) { if (frame && frame.contentWindow) frame.contentWindow.postMessage({rv: K, a: msg}, BASE); }
  function open() {
    if (!frame) {
      frame = document.createElement('iframe');
      frame.src = src; frame.title = CFG.title || 'Chat'; frame.allow = 'clipboard-write';
      frame.style.cssText = 'width:100%;height:100%;border:0;display:block';
      frameBox.appendChild(frame);
    }
    fit(); frameBox.style.display = 'block'; isOpen = true; btn.innerHTML = ICON_X; btn.appendChild(badge); badge.style.display = 'none';
    if (teaser) teaser.style.display = 'none';
    try { sessionStorage.setItem('rv_open_' + K, '1'); } catch (e) {}
    tell('open');
  }
  function close() {
    frameBox.style.display = 'none'; isOpen = false; btn.innerHTML = ICON_CHAT; btn.appendChild(badge);
    try { sessionStorage.removeItem('rv_open_' + K); } catch (e) {}
    tell('close');
  }
  btn.onclick = function () { isOpen ? close() : open(); };
  window.addEventListener('message', function (e) {
    if (e.origin !== BASE || !e.data || e.data.rv !== K) return;
    if (e.data.a === 'close') close();
    if (e.data.a === 'unread' && !isOpen) { var n = +e.data.n || 0; badge.textContent = n > 9 ? '9+' : String(n); badge.style.display = n ? 'flex' : 'none'; }
  });
  window.addEventListener('resize', function () { if (isOpen) fit(); });
  root.appendChild(frameBox); if (teaser) root.appendChild(teaser); root.appendChild(btn);
  function mount() { document.body.appendChild(root); var was = false; try { was = sessionStorage.getItem('rv_open_' + K) === '1'; } catch (e) {}
    if (was) open(); else if (+CFG.auto_open > 0) setTimeout(function () { if (!isOpen) open(); }, +CFG.auto_open * 1000); }
  if (document.body) mount(); else document.addEventListener('DOMContentLoaded', mount);
  window.RevenectChat = window.RevenectChat || {};
  window.RevenectChat[K] = {open: open, close: close};
  window.RevenectChat.open = open; window.RevenectChat.close = close;
})();
<?php
    exit;
}

/* ───────────────────── the chat ───────────────────── */
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (!$widget) { http_response_code(404); header("Content-Security-Policy: frame-ancestors *"); exit('<p style="font-family:sans-serif;padding:20px">This chat does not exist.</p>'); }
header("Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors " . webchat_frame_ancestors($widget));

$s = webchat_settings($widget);
// What the page changed for itself (only what a page may change).
$o = json_decode((string) base64_decode((string) ($_GET['o'] ?? ''), true), true);
if (is_array($o)) $s = array_merge($s, webchat_clean(array_intersect_key($o, array_flip(webchat_overridable())), true));
$inline = ($_GET['inline'] ?? '') === '1';
$page = (string) ($_GET['page'] ?? '');
if (!preg_match('#^https?://#i', $page)) $page = '';
$dir = $s['dir'] !== 'auto' ? $s['dir'] : (preg_match('/\p{Arabic}/u', $s['title'] . $s['welcome'] . $s['placeholder']) ? 'rtl' : 'ltr');
$ar = $dir === 'rtl';
$T = $ar
    ? ['send' => 'إرسال', 'close' => 'إغلاق', 'name' => 'الاسم', 'phone' => 'رقم الموبايل', 'start' => 'ابدأ المحادثة', 'skip' => 'تخطي', 'typing' => 'بيكتب…', 'new' => 'محادثة جديدة', 'agent' => 'فريق الدعم', 'off' => 'المحادثة متوقفة حالياً.', 'err' => 'لم يتم الإرسال. حاول مرة أخرى.', 'need' => 'اكتب الاسم ورقم الموبايل.', 'by' => 'مدعوم من']
    : ['send' => 'Send', 'close' => 'Close', 'name' => 'Your name', 'phone' => 'Mobile number', 'start' => 'Start chat', 'skip' => 'Skip', 'typing' => 'typing…', 'new' => 'New chat', 'agent' => 'Support team', 'off' => 'This chat is switched off right now.', 'err' => 'Not sent. Try again.', 'need' => 'Enter your name and mobile number.', 'by' => 'Powered by'];
$initial = mb_strtoupper(mb_substr(trim($s['title']) ?: 'C', 0, 1));
$boot = ['k' => $key, 'api' => $base . '/widget_api.php', 'page' => $page, 'context' => (string) ($s['context'] ?? ''), 'welcome' => $s['welcome'],
         'actions' => $s['actions'], 'ask' => $s['ask_details'], 'note' => $s['details_note'], 'offline' => $s['offline_note'],
         'paused' => $widget['status'] !== 'active', 'inline' => $inline, 'base' => $base, 'T' => $T];
?><!doctype html>
<html lang="<?= $ar ? 'ar' : 'en' ?>" dir="<?= $dir ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($s['title']) ?></title>
<style>
  :root{--brand:<?= e($s['color']) ?>;--on-brand:<?= e($s['text_color']) ?>;--bg:#F6F7F9;--surface:#FFFFFF;--ink:#14171F;--muted:#6B7280;--line:#E5E7EB;--bot:#FFFFFF;--chip:#F1F5F4}
  @media (prefers-color-scheme: dark){:root{--bg:#121417;--surface:#1A1D22;--ink:#ECEEF1;--muted:#9AA1AC;--line:#2A2F37;--bot:#22262D;--chip:#262B33}}
  *{box-sizing:border-box}
  html,body{height:100%;margin:0}
  body{background:var(--bg);color:var(--ink);font:14.5px/1.5 system-ui,-apple-system,"Segoe UI",Tahoma,"Noto Sans Arabic",sans-serif;display:flex;flex-direction:column;-webkit-font-smoothing:antialiased}
  header{background:var(--brand);color:var(--on-brand);padding:14px 16px;display:flex;align-items:center;gap:12px;flex:0 0 auto}
  .av{width:42px;height:42px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;overflow:hidden;flex:0 0 auto}
  .av img{width:100%;height:100%;object-fit:cover}
  .hd{flex:1;min-width:0}.hd b{display:block;font-size:16px;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.hd small{opacity:.85;font-size:12.5px;display:flex;align-items:center;gap:6px}
  .hd small i{width:7px;height:7px;border-radius:50%;background:#4ADE80;display:inline-block}
  .x{background:transparent;border:0;color:inherit;width:34px;height:34px;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
  .x:hover{background:rgba(255,255,255,.16)}.x:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible,a:focus-visible{outline:2px solid var(--brand);outline-offset:2px}
  main{flex:1;overflow-y:auto;padding:16px 14px 8px;display:flex;flex-direction:column;gap:10px;scroll-behavior:smooth}
  main>*{flex-shrink:0}
  .row{display:flex;gap:8px;align-items:flex-end;max-width:100%}
  .row.me{justify-content:flex-end}
  .mini{width:26px;height:26px;border-radius:50%;background:var(--brand);color:var(--on-brand);font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex:0 0 auto;overflow:hidden}
  .mini img{width:100%;height:100%;object-fit:cover}
  .bw{max-width:80%;min-width:0;display:flex;flex-direction:column}
  .me .bw{align-items:flex-end}.bot .bw{align-items:flex-start}
  .b{max-width:100%;padding:9px 13px;border-radius:16px;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere}
  .bot .b{background:var(--bot);border:1px solid var(--line);border-end-start-radius:5px}
  .me .b{background:var(--brand);color:var(--on-brand);border-end-end-radius:5px}
  .b a{color:inherit;text-decoration:underline}
  .b img{display:block;max-width:100%;border-radius:10px;margin-bottom:6px}
  .who{font-size:11px;color:var(--muted);margin:0 34px -6px}
  .t{font-size:10.5px;color:var(--muted);margin-top:3px;padding:0 4px}
  .choices{display:flex;flex-wrap:wrap;gap:6px;margin-inline-start:34px}
  .chip{border:1px solid var(--brand);color:var(--brand);background:var(--surface);border-radius:999px;padding:6px 12px;font:inherit;font-size:13px;cursor:pointer;text-decoration:none}
  .chip:hover{background:var(--brand);color:var(--on-brand)}
  .cards{display:flex;gap:10px;overflow-x:auto;padding:2px 2px 6px;margin-inline-start:34px;scroll-snap-type:x mandatory}
  .card{flex:0 0 190px;scroll-snap-align:start;background:var(--surface);border:1px solid var(--line);border-radius:14px;overflow:hidden;cursor:pointer;text-align:center;font:inherit;color:inherit;padding:0;text-decoration:none;display:block}
  .card img{width:100%;height:110px;object-fit:cover;display:block;background:var(--chip)}
  .card span{display:block;padding:10px;font-weight:600;color:var(--brand);font-size:13.5px}
  .typing .b{display:flex;gap:4px;align-items:center;padding:12px 14px}
  .typing i{width:7px;height:7px;border-radius:50%;background:var(--muted);animation:bl 1.2s infinite}
  .typing i:nth-child(2){animation-delay:.2s}.typing i:nth-child(3){animation-delay:.4s}
  @keyframes bl{0%,80%,100%{opacity:.25}40%{opacity:1}}
  @media (prefers-reduced-motion: reduce){.typing i{animation:none}main{scroll-behavior:auto}}
  .form{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:12px;display:flex;flex-direction:column;gap:8px;margin-inline-start:34px}
  .form p{margin:0;font-size:13px;color:var(--muted)}
  .form input{font:inherit;padding:9px 11px;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);width:100%}
  .form .acts{display:flex;gap:8px;justify-content:flex-end}
  .btn{font:inherit;border:0;border-radius:10px;padding:8px 14px;cursor:pointer;background:var(--brand);color:var(--on-brand);font-weight:600}
  .btn.ghost{background:transparent;color:var(--muted)}
  .err{color:#DC2626;font-size:12.5px}
  footer{flex:0 0 auto;background:var(--surface);border-top:1px solid var(--line);padding:10px 12px 8px}
  form.send{display:flex;gap:8px;align-items:flex-end}
  textarea{flex:1;resize:none;border:1px solid var(--line);border-radius:12px;padding:10px 12px;font:inherit;background:var(--bg);color:var(--ink);max-height:120px;min-height:42px;line-height:1.4}
  .go{width:42px;height:42px;border-radius:12px;border:0;background:var(--brand);color:var(--on-brand);cursor:pointer;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
  .go:disabled{opacity:.45;cursor:default}
  [dir=rtl] .go svg{transform:scaleX(-1)}
  .meta{display:flex;justify-content:space-between;gap:8px;font-size:11px;color:var(--muted);margin-top:6px}
  .meta a,.meta button{color:var(--muted);background:none;border:0;font:inherit;cursor:pointer;padding:0;text-decoration:none}
  .meta a:hover,.meta button:hover{color:var(--ink)}
  .off{padding:10px 12px;text-align:center;color:var(--muted);font-size:13px}
</style>
</head>
<body>
<header>
  <div class="av" aria-hidden="true"><?php if ($s['avatar']): ?><img src="<?= e($s['avatar']) ?>" alt=""><?php else: ?><?= e($initial) ?><?php endif; ?></div>
  <div class="hd"><b><?= e($s['title']) ?></b><small><i></i><?= e($s['subtitle']) ?></small></div>
  <?php if (!$inline): ?><button class="x" id="x" type="button" aria-label="<?= e($T['close']) ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button><?php endif; ?>
</header>
<main id="log" aria-live="polite"></main>
<footer>
  <?php if ($widget['status'] !== 'active'): ?><div class="off"><?= e($T['off']) ?></div><?php else: ?>
  <form class="send" id="f" autocomplete="off">
    <textarea id="t" rows="1" placeholder="<?= e($s['placeholder']) ?>" aria-label="<?= e($s['placeholder']) ?>" dir="auto" maxlength="2000"></textarea>
    <button class="go" id="go" type="submit" aria-label="<?= e($T['send']) ?>" disabled><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg></button>
  </form>
  <?php endif; ?>
  <div class="meta"><button type="button" id="new"><?= e($T['new']) ?></button><?php if ($s['branding']): ?><a href="<?= e($base) ?>" target="_blank" rel="noopener"><?= e($T['by']) ?> Revenect</a><?php endif; ?></div>
</footer>
<script>
(function () {
  var B = <?= json_encode($boot, $J) ?>, AV = <?= json_encode($s['avatar'] ?: '', $J) ?>, INI = <?= json_encode($initial, $J) ?>;
  var T = B.T, log = document.getElementById('log'), ta = document.getElementById('t'), go = document.getElementById('go');
  var store = {get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
               set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }, del: function (k) { try { localStorage.removeItem(k); } catch (e) {} }};
  function newVid() { var a = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); }
  var VK = 'rv_vid_' + B.k, vid = store.get(VK);
  if (!/^[a-f0-9]{32}$/.test(vid || '')) { vid = newVid(); store.set(VK, vid); }
  var last = 0, seen = {}, open = B.inline, unread = 0, busy = false, identified = store.get('rv_id_' + B.k) === '1';

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function linkify(s) { return esc(s).replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, '<a href="$1" target="_blank" rel="noopener nofollow">$1</a>'); }
  function mini() { return '<div class="mini" aria-hidden="true">' + (AV ? '<img src="' + esc(AV) + '" alt="">' : esc(INI)) + '</div>'; }
  function time(ms) { var d = new Date(ms || Date.now()); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
  function down() { log.scrollTop = log.scrollHeight; }
  function bubble(m) {
    var me = m.from === 'visitor', row = document.createElement('div');
    row.className = 'row ' + (me ? 'me' : 'bot');
    var inner = (m.image ? '<img src="' + esc(m.image) + '" alt="">' : '') + linkify(m.text || '');
    row.innerHTML = (me ? '' : mini()) + '<div class="bw"><div class="b" dir="auto">' + inner + '</div><div class="t">' + time(m.at) + '</div></div>';
    if (m.from === 'agent') { var w = document.createElement('div'); w.className = 'who'; w.textContent = T.agent; log.appendChild(w); }
    log.appendChild(row);
    if (m.choices && m.choices.length) {
      var box = document.createElement('div'); box.className = 'choices';
      m.choices.forEach(function (c) { var b = document.createElement('button'); b.type = 'button'; b.className = 'chip'; b.textContent = c.title; b.onclick = function () { send(c.title, c.id); box.remove(); }; box.appendChild(b); });
      log.appendChild(box);
    }
  }
  function add(list) {
    var fresh = 0;
    (list || []).forEach(function (m) { if (seen[m.id]) return; seen[m.id] = 1; last = Math.max(last, m.id); bubble(m); if (m.from !== 'visitor') fresh++; });
    if (fresh && !open) { unread += fresh; parent.postMessage({rv: B.k, a: 'unread', n: unread}, '*'); }
    if (list && list.length) down();
  }
  function welcome() {
    if (B.welcome) bubble({from: 'bot', text: B.welcome, at: Date.now()});
    var acts = B.actions || [];
    if (acts.length) {
      var pics = acts.some(function (a) { return a.image; }), box = document.createElement('div');
      box.className = pics ? 'cards' : 'choices'; box.id = 'acts';
      acts.forEach(function (a) {
        var el = a.url ? document.createElement('a') : document.createElement('button');
        if (a.url) { el.href = a.url; el.target = '_blank'; el.rel = 'noopener'; } else { el.type = 'button'; el.onclick = function () { send(a.send || a.label); }; }
        el.className = pics ? 'card' : 'chip';
        el.innerHTML = pics ? (a.image ? '<img src="' + esc(a.image) + '" alt="">' : '<img alt="">') + '<span>' + esc(a.label) + '</span>' : esc(a.label);
        box.appendChild(el);
      });
      log.appendChild(box);
    }
    if (B.ask !== 'off' && !identified && !B.paused) askForm();
  }
  function askForm() {
    var f = document.createElement('form'); f.className = 'form'; f.id = 'who';
    f.innerHTML = '<p>' + esc(B.note || '') + '</p><input name="name" placeholder="' + esc(T.name) + '" maxlength="120" autocomplete="name">' +
      '<input name="phone" type="tel" placeholder="' + esc(T.phone) + '" maxlength="30" autocomplete="tel" dir="ltr">' +
      '<div class="err" hidden></div><div class="acts">' + (B.ask === 'optional' ? '<button type="button" class="btn ghost">' + esc(T.skip) + '</button>' : '') +
      '<button class="btn">' + esc(T.start) + '</button></div>';
    var row = document.createElement('div'); row.className = 'row bot'; row.appendChild(f); log.appendChild(row);
    if (B.ask === 'required') lock(true);
    var skip = f.querySelector('.ghost'); if (skip) skip.onclick = function () { row.remove(); identified = true; store.set('rv_id_' + B.k, '1'); lock(false); };
    f.onsubmit = function (e) {
      e.preventDefault();
      var n = f.name.value.trim(), p = f.phone.value.trim(), er = f.querySelector('.err');
      if (!n || !p) { er.textContent = T.need; er.hidden = false; return; }
      api('identify', {name: n, phone: p}).then(function (d) {
        if (!d.ok) { er.textContent = d.error || T.err; er.hidden = false; return; }
        row.remove(); identified = true; store.set('rv_id_' + B.k, '1'); lock(false); if (ta) ta.focus();
      });
    };
  }
  function lock(on) { if (!ta) return; ta.disabled = on; go.disabled = on || !ta.value.trim(); }
  function api(a, body) {
    body = body || {}; body.vid = vid; body.page = B.page; body.context = B.context;
    return fetch(B.api + '?k=' + encodeURIComponent(B.k) + '&a=' + a, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)})
      .then(function (r) { return r.json(); }).catch(function () { return {ok: false, error: T.err}; });
  }
  function typing(on) {
    var t = document.getElementById('typing');
    if (on && !t) { t = document.createElement('div'); t.id = 'typing'; t.className = 'row bot typing'; t.innerHTML = mini() + '<div class="b" aria-label="' + esc(T.typing) + '"><i></i><i></i><i></i></div>'; log.appendChild(t); down(); }
    if (!on && t) t.remove();
  }
  function send(text, buttonId) {
    text = (text || '').trim(); if ((!text && !buttonId) || busy || B.paused) return;
    if (B.ask === 'required' && !identified) return;
    busy = true; if (ta) { ta.value = ''; fitTa(); } go && (go.disabled = true);
    var acts = document.getElementById('acts'); if (acts && acts.className === 'choices') acts.remove();
    var tmp = {id: 0, from: 'visitor', text: text, at: Date.now()}; bubble(tmp); var tmpRow = log.lastElementChild; down();
    setTimeout(function () { if (busy) typing(true); }, 350);
    api('send', {text: text, button_id: buttonId || '', after: last}).then(function (d) {
      busy = false; typing(false); tmpRow.remove();
      if (!d.ok) { bubble({from: 'bot', text: d.error || T.err, at: Date.now()}); down(); return; }
      add(d.messages);
      // Nobody answered at once (the agent is off, or a person will answer): say so, once.
      var answered = (d.messages || []).some(function (m) { return m.from !== 'visitor'; });
      if (!answered && B.offline && !store.get('rv_off_' + B.k + vid)) { bubble({from: 'bot', text: B.offline, at: Date.now()}); store.set('rv_off_' + B.k + vid, '1'); down(); }
      lock(false);
    });
  }
  function poll() {
    fetch(B.api + '?k=' + encodeURIComponent(B.k) + '&a=poll&vid=' + vid + '&after=' + last).then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) add(d.messages); }).catch(function () {});
  }
  function fitTa() { if (!ta) return; ta.style.height = 'auto'; ta.style.height = Math.min(120, ta.scrollHeight) + 'px'; }
  if (ta) {
    ta.addEventListener('input', function () { fitTa(); go.disabled = !ta.value.trim() || busy; });
    ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(ta.value); } });
    document.getElementById('f').addEventListener('submit', function (e) { e.preventDefault(); send(ta.value); });
  }
  var x = document.getElementById('x'); if (x) x.onclick = function () { parent.postMessage({rv: B.k, a: 'close'}, '*'); };
  document.getElementById('new').onclick = function () {
    store.del(VK); store.del('rv_id_' + B.k); vid = newVid(); store.set(VK, vid); identified = false;
    log.innerHTML = ''; seen = {}; last = 0; unread = 0; welcome();
  };
  window.addEventListener('message', function (e) {
    if (e.source !== parent || !e.data || e.data.rv !== B.k) return;   // only the page that holds the chat talks to it
    if (e.data.a === 'open') { open = true; unread = 0; if (ta && !ta.disabled) ta.focus(); poll(); }
    if (e.data.a === 'close') open = false;
  });
  welcome();
  fetch(B.api + '?k=' + encodeURIComponent(B.k) + '&a=poll&vid=' + vid + '&after=0').then(function (r) { return r.json(); })
    .then(function (d) { if (d.ok) { var u = unread; add(d.messages); unread = u; } }).catch(function () {});
  (function tick() { setTimeout(function () { if (!document.hidden) poll(); tick(); }, open ? 4000 : 15000); })();
  if (ta && B.inline) ta.focus();
})();
</script>
</body>
</html>
