/* A WhatsApp-style preview of a message template, from Meta's `components` (Templates list and
   Templates → Create template). Everything shown is escaped; pictures come only from https links. */
(function () {
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  // WhatsApp's own formatting: *bold* _italic_ ~strike~ ```mono```, and {{n}} shown as a chip.
  const fmt = s => esc(s)
    .replace(/```([\s\S]+?)```/g, '<code>$1</code>')
    .replace(/\*([^*\n]+)\*/g, '<b>$1</b>')
    .replace(/(^|[\s(])_([^_\n]+)_/g, '$1<i>$2</i>')
    .replace(/~([^~\n]+)~/g, '<s>$1</s>')
    .replace(/\{\{\s*(\d+)\s*\}\}/g, '<span class="tp-var">{{$1}}</span>')
    .replace(/\n/g, '<br>');
  const media = (c) => {
    const f = String(c.format || '').toUpperCase();
    let src = c._media || '';
    const h = c.example && (c.example.header_handle || c.example.header_url);
    if (!src && Array.isArray(h) && /^https:\/\//i.test(h[0] || '')) src = h[0];
    if (f === 'IMAGE' && /^https?:\/\//i.test(src)) return `<div class="tp-media"><img src="${esc(src)}" alt=""></div>`;
    if (f === 'VIDEO' && /^https?:\/\//i.test(src)) return `<div class="tp-media"><video src="${esc(src)}" muted playsinline preload="metadata"></video><span class="tp-play">▶</span></div>`;
    const ic = {IMAGE: '🖼', VIDEO: '🎬', DOCUMENT: '📄', LOCATION: '📍'}[f] || '';
    const lb = {IMAGE: 'Picture', VIDEO: 'Video', DOCUMENT: 'Document', LOCATION: 'Location'}[f] || '';
    return `<div class="tp-media tp-ph"><span>${ic}</span><small>${lb}</small></div>`;
  };
  const btns = (list) => {
    if (!list || !list.length) return '';
    const shown = list.length > 3 ? list.slice(0, 2) : list;
    const ic = b => ({URL: '↗', PHONE_NUMBER: '📞', COPY_CODE: '⧉', QUICK_REPLY: '↩', OTP: '⧉', FLOW: '☰', CATALOG: '🛍'}[String(b.type).toUpperCase()] || '');
    const txt = b => String(b.type).toUpperCase() === 'COPY_CODE' ? 'Copy offer code' : (b.text || '');
    return `<div class="tp-btns">${shown.map(b => `<div class="tp-btn">${ic(b)} ${esc(txt(b))}</div>`).join('')}${list.length > 3 ? '<div class="tp-btn">☰ See all options</div>' : ''}</div>`;
  };
  function bubble(comps, isCard) {
    let head = '', lto = '', body = '', foot = '', b = '';
    for (const c of comps || []) {
      const t = String(c.type || '').toUpperCase();
      if (t === 'HEADER') head = String(c.format || 'TEXT').toUpperCase() === 'TEXT' ? `<div class="tp-head">${fmt(c.text)}</div>` : media(c);
      else if (t === 'BODY') body = c.text != null ? `<div class="tp-body">${fmt(c.text)}</div>`
        : `<div class="tp-body"><b>123456</b> is your verification code.${c.add_security_recommendation ? ' For your security, do not share this code.' : ''}</div>`;
      else if (t === 'FOOTER') foot = `<div class="tp-foot">${c.code_expiration_minutes ? 'This code expires in ' + (+c.code_expiration_minutes) + ' minutes.' : esc(c.text)}</div>`;
      else if (t === 'BUTTONS') b = btns(c.buttons);
      else if (t === 'LIMITED_TIME_OFFER') { const o = c.limited_time_offer || {}; lto = `<div class="tp-lto"><span>🎁</span><div><b>${esc(o.text)}</b>${o.has_expiration ? '<small>Ends in 23:59:59</small>' : ''}</div></div>`; }
    }
    return `<div class="tp-bub${isCard ? ' tp-card' : ''}">${head}${lto}${body}${foot}</div>${b}`;
  }
  window.tplPreview = function (comps) {
    comps = Array.isArray(comps) ? comps : [];
    const car = comps.find(c => String(c.type || '').toUpperCase() === 'CAROUSEL');
    const main = comps.filter(c => c !== car);
    let html = `<div class="tp-wrap"><div class="tp-msg">${bubble(main, false)}</div>`;
    if (car) html += `<div class="tp-cards">${(car.cards || []).map(cd => `<div class="tp-cardwrap">${bubble(cd.components, true)}</div>`).join('')}</div>`;
    return html + '</div>';
  };
  if (!document.getElementById('tp-css')) {
    const st = document.createElement('style'); st.id = 'tp-css';
    st.textContent = `
.tp-wrap{background:#efeae2;border-radius:12px;padding:14px;font:13.5px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif;color:#111b21;max-width:100%;overflow:hidden}
:root[data-theme=dark] .tp-wrap{background:#0b141a;color:#e9edef}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]) .tp-wrap{background:#0b141a;color:#e9edef}}
.tp-msg{max-width:300px}
.tp-bub{background:#fff;border-radius:8px;padding:4px;box-shadow:0 1px .5px rgba(0,0,0,.13);overflow:hidden}
:root[data-theme=dark] .tp-bub,:root[data-theme=dark] .tp-btn{background:#202c33}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]) .tp-bub,:root:not([data-theme=light]) .tp-btn{background:#202c33}}
.tp-head{font-weight:700;padding:6px 8px 0}
.tp-body{padding:6px 8px;white-space:normal;word-wrap:break-word}
.tp-foot{padding:0 8px 6px;font-size:12px;color:#667781}
.tp-media{border-radius:6px;overflow:hidden;background:#d1d7db;position:relative;aspect-ratio:1.91/1;display:flex;align-items:center;justify-content:center}
.tp-media img,.tp-media video{width:100%;height:100%;object-fit:cover;display:block}
.tp-ph{flex-direction:column;gap:2px;color:#54656f}.tp-ph span{font-size:26px}
.tp-play{position:absolute;color:#fff;font-size:26px;text-shadow:0 1px 4px rgba(0,0,0,.5)}
.tp-lto{display:flex;gap:8px;align-items:center;background:#f0f2f5;border-radius:6px;margin:4px;padding:8px}.tp-lto small{display:block;color:#667781}
:root[data-theme=dark] .tp-lto{background:#2a3942}
.tp-btns{display:flex;flex-direction:column;gap:2px;margin-top:2px}
.tp-btn{background:#fff;border-radius:8px;text-align:center;color:#027eb5;padding:8px;font-weight:500;box-shadow:0 1px .5px rgba(0,0,0,.13)}
.tp-var{background:#e1f3fb;color:#027eb5;border-radius:4px;padding:0 3px;font-size:12px}
.tp-cards{display:flex;gap:8px;overflow-x:auto;margin-top:8px;padding-bottom:4px}
.tp-cardwrap{flex:0 0 220px}.tp-card .tp-body{font-size:13px}
.tp-body code{font-family:ui-monospace,monospace;font-size:12.5px}`;
    document.head.appendChild(st);
  }
})();
