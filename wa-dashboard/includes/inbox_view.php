<?php
/**
 * Shared Inbox UI (WhatsApp-style two-pane live chat). Include after the page header.
 * Expects: $IB_ENDPOINT (string, e.g. 'inbox.php' or 'inbox.php?client=3').
 * Optional: $IB_UPLOAD — a media upload endpoint for the template picker. Left empty the
 *   Upload button is not shown and a link can still be pasted. Admin has no such endpoint
 *   (upload_media.php saves under the signed-in CLIENT's folder), so admin gets the paste box.
 * The including page must already have handled ?ajax via inbox_handle_ajax().
 */
$IB_ENDPOINT = $IB_ENDPOINT ?? 'inbox.php';
$IB_UPLOAD   = $IB_UPLOAD ?? '';
$IB_SEP = strpos($IB_ENDPOINT, '?') === false ? '?' : '&';
?>
<style>
  .ib-wrap{display:flex;gap:0;height:72vh;min-height:460px;border:1px solid var(--line,rgba(13,19,33,.10));border-radius:12px;overflow:hidden;background:var(--surface,#fff)}
  .ib-list{width:320px;min-width:260px;border-right:1px solid var(--line,rgba(13,19,33,.10));display:flex;flex-direction:column;background:var(--surface,#fff)}
  .ib-search{padding:10px;border-bottom:1px solid var(--line,rgba(13,19,33,.10))}
  .ib-search input{width:100%;padding:8px 10px;border:1px solid var(--line,rgba(13,19,33,.10));border-radius:8px;font-size:13px}
  .ib-threads{overflow-y:auto;flex:1}
  .ib-th{display:flex;gap:10px;align-items:center;padding:11px 12px;cursor:pointer;border-bottom:1px solid var(--line,#f0eee9)}
  .ib-th:hover{background:var(--brand-tint,rgba(124,58,237,.06))} .ib-th.active{background:var(--brand-tint,rgba(124,58,237,.10))}
  .ib-av{width:38px;height:38px;border-radius:50%;background:var(--brand,#7C3AED);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:15px;flex-shrink:0}
  .ib-th .nm{font-weight:600;font-size:13.5px} .ib-th .pv{color:var(--muted,rgba(13,19,33,.55));font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}
  .ib-th .meta{margin-left:auto;text-align:right;display:flex;flex-direction:column;gap:3px;align-items:flex-end}
  .ib-th .tm{color:var(--muted,rgba(13,19,33,.55));font-size:11px}
  .ib-badge{background:var(--brand,#7C3AED);color:#fff;border-radius:10px;font-size:11px;padding:1px 7px;font-weight:600}
  .ib-chat{flex:1;display:flex;flex-direction:column;position:relative;background:#efe7dd;background-image:linear-gradient(rgba(255,255,255,.55),rgba(255,255,255,.55))}
  .ib-chat-h{padding:12px 16px;background:var(--surface,#fff);border-bottom:1px solid var(--line,rgba(13,19,33,.10));display:flex;align-items:center;gap:10px}
  .ib-body{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:6px}
  .ib-b{max-width:74%;padding:7px 11px;border-radius:9px;font-size:13.5px;line-height:1.35;white-space:pre-wrap;word-wrap:break-word;box-shadow:0 1px .5px rgba(0,0,0,.08)}
  .ib-in{align-self:flex-start;background:#fff}
  .ib-out{align-self:flex-end;background:#d9fdd3}
  .ib-b .st{display:block;text-align:right;font-size:10.5px;color:#667;margin-top:2px}
  .ib-b .st.failed{color:#c0392b}
  /* The explanation under a failed send. Left-aligned and wrapping, unlike the timestamp it
     sits beneath, because it is a sentence to read rather than a status to glance at. */
  .ib-b .ib-err-code{opacity:.7;font-weight:400}
  .ib-b .ib-err-hint{display:block;text-align:left;font-size:11px;line-height:1.45;
    color:#8a6d3b;background:#fdf6e3;border-radius:6px;padding:5px 7px;margin-top:4px;white-space:normal}
  .ib-b .ib-resend{display:inline-block;margin-top:6px;font-size:11.5px;font-weight:600;
    color:#fff;background:var(--brand,#6c4cf1);border:0;border-radius:6px;padding:5px 10px;cursor:pointer}
  .ib-b .ib-resend:hover{filter:brightness(1.08)}
  .ib-b .ib-resend[disabled]{opacity:.55;cursor:default}
  .ib-b .ib-tpl-alt{display:inline-block;margin:6px 0 0 6px;font-size:11.5px;font-weight:600;
    color:var(--brand,#6c4cf1);background:transparent;border:1px solid currentColor;border-radius:6px;
    padding:4px 9px;cursor:pointer}
  .ib-note .ib-tpl-open{margin-left:8px;font-size:12px;font-weight:600;color:#fff;
    background:var(--brand,#6c4cf1);border:0;border-radius:6px;padding:5px 10px;cursor:pointer}
  /* Template picker. Anchored over the thread rather than the page so the conversation the
     agent is answering stays visible behind it. */
  .ib-tpl-overlay{position:absolute;inset:0;background:rgba(13,19,33,.34);display:flex;
    align-items:center;justify-content:center;z-index:30;padding:16px}
  /* [hidden] is display:none in the UA sheet, which a class selector outranks — without this
     the closed picker stays laid out, invisible over the whole chat pane, and eats every
     click in the thread. */
  .ib-tpl-overlay[hidden]{display:none}
  .ib-tpl-card{background:var(--card,#fff);border-radius:12px;width:min(520px,100%);
    max-height:82%;display:flex;flex-direction:column;box-shadow:0 18px 48px rgba(13,19,33,.24)}
  .ib-tpl-head{display:flex;justify-content:space-between;align-items:center;
    padding:12px 14px;border-bottom:1px solid var(--line,#e6e8ef)}
  .ib-tpl-x{background:0;border:0;font-size:20px;line-height:1;cursor:pointer;color:var(--muted,#667)}
  .ib-tpl-body{overflow:auto;padding:8px 14px 14px}
  .ib-tpl-foot{display:flex;justify-content:space-between;gap:8px;padding:12px 14px;
    border-top:1px solid var(--line,#e6e8ef)}
  .ib-tpl-row{width:100%;text-align:left;background:0;border:1px solid var(--line,#e6e8ef);
    border-radius:9px;padding:9px 11px;margin-top:8px;cursor:pointer;display:block}
  .ib-tpl-row:hover{border-color:var(--brand,#6c4cf1)}
  .ib-tpl-row b{display:block;font-size:13px}
  .ib-tpl-row span{display:block;font-size:11.5px;color:var(--muted,#667);margin-top:3px;
    white-space:pre-wrap;overflow:hidden;max-height:34px}
  .ib-tpl-row em{font-style:normal;font-size:11px;color:var(--muted,#667)}
  .ib-tpl-f{margin-top:10px}
  .ib-tpl-f label{display:block;font-size:11.5px;color:var(--muted,#667);margin-bottom:3px}
  .ib-tpl-f input{width:100%;padding:7px 9px;border:1px solid var(--line,#e6e8ef);border-radius:7px;font-size:13px}
  .ib-tpl-err{color:#c0392b;font-size:12px;margin-top:8px}
  .ib-foot{padding:10px 12px;background:var(--surface,#fff);border-top:1px solid var(--line,rgba(13,19,33,.10))}
  .ib-foot form{display:flex;gap:8px;align-items:flex-end}
  .ib-foot textarea{flex:1;resize:none;border:1px solid var(--line,rgba(13,19,33,.10));border-radius:20px;padding:9px 14px;font-size:13.5px;max-height:120px}
  .ib-note{font-size:12px;color:var(--muted,rgba(13,19,33,.55));text-align:center;padding:8px}
  .ib-empty{margin:auto;color:var(--muted,rgba(13,19,33,.55));text-align:center;font-size:13px}
  .ib-bot{font-size:11.5px;padding:3px 9px;border-radius:11px;white-space:nowrap;font-weight:600}
  .ib-bot.on{background:#e8f5ea;color:#1e7a3c}
  .ib-bot.off{background:#fdf0e3;color:#a9631a}
  .ib-back{display:none;background:0;border:0;cursor:pointer;color:var(--ink,#2a221a);padding:2px 6px 2px 0;font-size:19px;line-height:1}
  /* Phones: one pane at a time — the thread list fills the screen, and opening a chat
     swaps to the conversation with a back arrow (the two-pane desktop view is unusable
     at this width). */
  @media(max-width:720px){
    .ib-wrap{height:calc(100vh - 190px);min-height:0;border-radius:10px}
    .ib-list{width:100%;min-width:0;border-right:0}
    .ib-chat{display:none}
    .ib-th .pv{max-width:60vw}
    .ib-wrap.chatting .ib-list{display:none}
    .ib-wrap.chatting .ib-chat{display:flex}
    .ib-back{display:inline-block}
    /* Both of these are scoped rules that would otherwise beat the global 16px rule,
       and anything under 16px makes iOS Safari zoom the page on focus. */
    .ib-foot textarea, .ib-search input{font-size:16px}
  }
</style>

<div class="ib-wrap">
  <div class="ib-list">
    <div class="ib-search"><input type="text" id="ib-q" placeholder="Search name or number…"></div>
    <div class="ib-threads" id="ib-threads"><div class="ib-empty" style="padding:20px">Loading…</div></div>
  </div>
  <div class="ib-chat">
    <div class="ib-chat-h" id="ib-head" style="display:none">
      <button type="button" class="ib-back" id="ib-back" aria-label="Back to conversations">&#8592;</button>
      <div class="ib-av" id="ib-hav"></div>
      <div><div class="nm" id="ib-hname"></div><div class="pv" id="ib-hphone"></div></div>
      <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
        <span class="ib-bot" id="ib-bot"></span>
        <button type="button" class="btn btn-ghost btn-sm" id="ib-bot-btn"></button>
      </div>
    </div>
    <div class="ib-body" id="ib-body"><div class="ib-empty">Select a conversation to view messages.</div></div>
    <div class="ib-foot" id="ib-foot" style="display:none">
      <form id="ib-form"><textarea id="ib-text" rows="1" placeholder="Type a reply…"></textarea><button class="btn btn-primary" type="submit">Send</button></form>
      <div class="ib-note" id="ib-closed" style="display:none">
        ⏱️ Outside the 24-hour window — only an approved template can reach this contact.
        <button class="ib-tpl-open" type="button">📄 Send a template</button>
      </div>
      <div class="ib-tpl-overlay" id="ib-tpl" hidden>
        <div class="ib-tpl-card" role="dialog" aria-label="Send a template">
          <div class="ib-tpl-head">
            <strong>Send a template</strong>
            <button type="button" class="ib-tpl-x" aria-label="Close">×</button>
          </div>
          <div class="ib-tpl-body" id="ib-tpl-list"></div>
          <div class="ib-tpl-foot" id="ib-tpl-foot" hidden>
            <button type="button" class="btn btn-ghost btn-sm" id="ib-tpl-back">← Back</button>
            <button type="button" class="btn btn-primary btn-sm" id="ib-tpl-send">Send</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const IB_URL = <?= json_encode($IB_ENDPOINT) ?>;
const IB_UPLOAD = <?= json_encode($IB_UPLOAD) ?>;
const IB_SEP = <?= json_encode($IB_SEP) ?>;
const IB_CSRF = <?= json_encode(csrf_token()) ?>;
let ibCur = 0, ibLast = 0, ibOpen = false;
const el = id => document.getElementById(id);
const esc = s => (s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const initials = s => (s||'?').trim().slice(0,2).toUpperCase();
function tfmt(t){ if(!t) return ''; const d=new Date(t.replace(' ','T')); return isNaN(d)?'':d.toLocaleString([], {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); }
const tick = s => s==='read'?'✓✓':s==='delivered'?'✓✓':s==='sent'?'✓'
  :s==='failed'?'⚠ failed':s==='resent'?'↻ queued again':'';

async function loadThreads(){
  const q = encodeURIComponent(el('ib-q').value||'');
  const r = await fetch(IB_URL+IB_SEP+'ajax=threads&q='+q); const d = await r.json();
  if(!d.ok) return;
  const box = el('ib-threads');
  if(!d.threads.length){ box.innerHTML='<div class="ib-empty" style="padding:20px">No conversations yet.</div>'; return; }
  box.innerHTML = d.threads.map(t=>{
    const pv = (t.last_dir==='out'?'↩ ':'') + esc((t.last_body||'').slice(0,40));
    const badge = t.unread>0 ? `<span class="ib-badge">${t.unread}</span>` : '';
    return `<div class="ib-th ${t.contact_id==ibCur?'active':''}" onclick="openThread(${t.contact_id},'${esc(t.name||'').replace(/'/g,"\\'")}','${esc(t.phone_e164)}')">
      <div class="ib-av">${initials(t.name||t.phone_e164)}</div>
      <div style="min-width:0"><div class="nm">${esc(t.name||('+'+t.phone_e164))}</div><div class="pv">${pv}</div></div>
      <div class="meta"><span class="tm">${tfmt(t.last_at)}</span>${badge}</div></div>`;
  }).join('');
}
function openThread(id,name,phone){
  ibCur=id; ibLast=0; el('ib-body').innerHTML='';
  document.querySelector('.ib-wrap').classList.add('chatting');   // phones: show the chat pane
  el('ib-head').style.display='flex'; el('ib-foot').style.display='block';
  el('ib-hav').textContent=initials(name||phone); el('ib-hname').textContent=name||('+'+phone); el('ib-hphone').textContent='+'+phone;
  document.querySelectorAll('.ib-th').forEach(e=>e.classList.remove('active'));
  pollThread(); loadThreads();
}
async function pollThread(){
  if(!ibCur) return;
  const r = await fetch(IB_URL+IB_SEP+'ajax=thread&contact='+ibCur+'&after='+ibLast); const d = await r.json();
  if(!d.ok) return;
  const body = el('ib-body'); const atBottom = body.scrollHeight-body.scrollTop-body.clientHeight < 80;
  d.messages.forEach(m=>{
    ibLast=Math.max(ibLast,m.id);
    const div=document.createElement('div');
    div.className='ib-b '+(m.direction==='out'?'ib-out':'ib-in');
    let st=''; if(m.direction==='out'){ const t=tick(m.status); if(t) st=`<span class="st ${m.status==='failed'?'failed':''}">${t} ${tfmt(m.created_at)}</span>`; }
    else st=`<span class="st">${tfmt(m.created_at)}</span>`;
    /* A failed send now says what went wrong in words the agent can act on, with Meta's own
       wording kept underneath so nothing is hidden. 'never' means a resend buys the same
       error, so the line says so rather than leaving them to try it three times. */
    let errLine = '';
    if (m.status==='failed' && (m.error_label || m.error_title)) {
      /* The friendly label, plus the raw code. Meta's full sentence is developer noise for a
         client, but the code is exactly what support asks for, so it stays visible. */
      const label = esc(m.error_label || m.error_title)
                  + (m.error_code ? ` <span class="ib-err-code">#${esc(m.error_code)}</span>` : '');
      const hint  = m.error_hint ? `<span class="ib-err-hint">${esc(m.error_hint)}</span>` : '';
      /* A resend button right here, because this is where the client notices the failure.
         It is offered only when the error can clear and we know which queued item to re-run;
         otherwise fall back to telling them where the fix lives. */
      const again = m.can_resend
        ? `<button class="ib-resend" data-msg="${m.id}" data-wait="${m.resend_wait||0}"
             title="${m.resend_wait ? 'WhatsApp is still capping this number. It will go out automatically once the cap lifts.' : 'Send this message again now'}"
           >↻ ${m.resend_wait ? `Send when the cap lifts (~${m.resend_wait}h)` : 'Send again'}</button>`
        : (m.error_action === 'never' ? ''
           : `<span class="ib-err-hint">Resend it from ${m.error_action === 'fix' ? 'Campaigns once fixed' : 'Needs attention or Lead Qualifier'}.</span>`);
      /* A different template is often the better move: when WhatsApp caps a marketing
         template, a utility one goes through, and resending the same one does not. */
      const alt = m.error_action === 'never' ? ''
                : `<button class="ib-tpl-alt" type="button">📄 Try another template</button>`;
      const where = again + alt;
      errLine = `<span class="st failed" style="display:block">⚠ ${label}</span>${hint}${where}`;
    }
    div.innerHTML=esc(m.body)+st+errLine;
    body.appendChild(div);
  });
  if(d.messages.length && atBottom) body.scrollTop=body.scrollHeight;
  ibOpen=!!d.window_open;
  el('ib-form').style.display = ibOpen?'flex':'none';
  el('ib-closed').style.display = ibOpen?'none':'block';
  setBotState(!!d.bot_paused);
}
/* ── live takeover: while paused the bot won't reply to this contact ── */
let ibPaused=false;
function setBotState(paused){
  ibPaused=paused;
  const pill=el('ib-bot'), btn=el('ib-bot-btn');
  pill.className='ib-bot '+(paused?'off':'on');
  pill.textContent = paused ? "🙋 You're handling this" : '🤖 Bot active';
  btn.textContent  = paused ? 'Resume bot' : 'Take over';
  btn.title = paused ? 'Hand the conversation back to the bot'
                     : 'Pause the bot so you can reply by hand';
}
el('ib-bot-btn').addEventListener('click', async ()=>{
  if(!ibCur) return;
  const btn=el('ib-bot-btn'); btn.disabled=true;
  const fd=new FormData();
  fd.append('ajax', ibPaused?'resume':'takeover');
  fd.append('csrf_token',IB_CSRF); fd.append('contact',ibCur);
  try{
    const r=await fetch(IB_URL,{method:'POST',body:fd}); const d=await r.json();
    if(d.ok) setBotState(!!d.bot_paused);
  }catch(e){ /* leave the pill as-is */ }
  btn.disabled=false;
});
el('ib-form').addEventListener('submit', async e=>{
  e.preventDefault(); const ta=el('ib-text'); const body=ta.value.trim(); if(!body||!ibCur) return;
  const btn=e.target.querySelector('button'); btn.disabled=true;
  const fd=new FormData(); fd.append('ajax','send'); fd.append('csrf_token',IB_CSRF); fd.append('contact',ibCur); fd.append('body',body);
  const r=await fetch(IB_URL,{method:'POST',body:fd}); const d=await r.json();
  btn.disabled=false;
  if(d.ok){ ta.value=''; pollThread(); loadThreads(); }
  else { alert(d.error||'Could not send.'); }
});
el('ib-text').addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); el('ib-form').requestSubmit(); }});

/* ── resend a failed message, and send a template by hand ──
   Both live on the thread because that is where a failure is noticed. Clicks are caught on the
   body rather than bound per bubble: pollThread() appends new bubbles continuously, so anything
   bound at render time would be missed by every message that arrived after it. */
el('ib-body').addEventListener('click', async e=>{
  const rs = e.target.closest('.ib-resend');
  if (rs) {
    rs.disabled = true; const was = rs.textContent;
    rs.textContent = parseInt(rs.dataset.wait || '0', 10) > 0 ? 'Scheduling…' : 'Sending…';
    const fd = new FormData();
    fd.append('ajax','resend'); fd.append('csrf_token',IB_CSRF); fd.append('message',rs.dataset.msg);
    try {
      const r = await fetch(IB_URL,{method:'POST',body:fd}); const d = await r.json();
      if (d.ok) {
        rs.textContent = d.queued === 'later' ? `✓ Will send in ~${d.hours}h` : '✓ Sent again';
        pollThread(); loadThreads();
      }
      else if (d.pick_template !== undefined) {
        /* The variables were never stored, so "the same template" needs the fields filled in
           rather than guessed. Open the picker on it instead of refusing. */
        rs.disabled = false; rs.textContent = was;
        openTpl(d.pick_template || 0);      // 0 = we could not identify it; show the list
      }
      else { rs.disabled = false; rs.textContent = was; alert(d.error || 'Could not resend.'); }
    } catch (err) { rs.disabled = false; rs.textContent = was; alert('Could not resend.'); }
    return;
  }
  if (e.target.closest('.ib-tpl-alt')) openTpl();
});
document.querySelector('.ib-tpl-open')?.addEventListener('click', openTpl);

let ibTpls = null, ibPick = null;
const tplBack = () => el('ib-tpl');

function closeTpl(){ tplBack().hidden = true; ibPick = null; }
tplBack().addEventListener('click', e=>{ if(e.target === tplBack()) closeTpl(); });
document.querySelector('.ib-tpl-x').addEventListener('click', closeTpl);
el('ib-tpl-back').addEventListener('click', ()=> renderTplList());

async function openTpl(preselectId){
  if(!ibCur) return;
  tplBack().hidden = false;
  if (ibTpls === null) {
    el('ib-tpl-list').innerHTML = '<p class="text-muted" style="font-size:12.5px">Loading…</p>';
    try {
      const r = await fetch(IB_URL+IB_SEP+'ajax=templates'); const d = await r.json();
      ibTpls = d.ok ? (d.templates || []) : [];
    } catch(e){ ibTpls = []; }
  }
  const i = preselectId ? ibTpls.findIndex(t => t.id === preselectId) : -1;
  if (i >= 0) pickTpl(i); else renderTplList();
}

function renderTplList(){
  ibPick = null;
  el('ib-tpl-foot').hidden = true;
  const box = el('ib-tpl-list');
  if (!ibTpls.length) {
    box.innerHTML = '<p class="text-muted" style="font-size:12.5px">'
      + 'No approved templates yet. Templates are approved by WhatsApp before you can send them — '
      + 'add one on the Templates page.</p>';
    return;
  }
  box.innerHTML = ibTpls.map((t,i)=>`
    <button type="button" class="ib-tpl-row" data-i="${i}">
      <b>${esc(t.name)}</b>
      <span>${esc((t.preview||'').slice(0,160))}</span>
      <em>${esc(t.language)}${t.category?' · '+esc(t.category):''}${
        t.body_vars||t.header_vars?' · '+(t.body_vars+t.header_vars)+' field(s) to fill':''}${
        t.needs_media?' · needs a '+esc(t.needs_media)+' link':''}</em>
    </button>`).join('');
  box.querySelectorAll('.ib-tpl-row').forEach(b=>
    b.addEventListener('click',()=> pickTpl(parseInt(b.dataset.i,10))));
}

function pickTpl(i){
  ibPick = ibTpls[i];
  const f = [];
  for (let n=1; n<=ibPick.header_vars; n++)
    f.push(`<div class="ib-tpl-f"><label>Header field {{${n}}}</label><input data-h="${n}"></div>`);
  for (let n=1; n<=ibPick.body_vars; n++)
    f.push(`<div class="ib-tpl-f"><label>Field {{${n}}}</label><input data-v="${n}"></div>`);
  if (ibPick.needs_media) {
    /* Prefilled with the image this template was last sent with, because that is almost always
       the right one and the agent should not have to go and find it. Upload is there for the
       first send, or when they want a different one. */
    const cur = ibPick.last_media || '';
    f.push(`<div class="ib-tpl-f">
      <label>${esc(ibPick.needs_media)} — reusing the one from your campaign${cur?'':' (none found yet, upload or paste a link)'}</label>
      <div style="display:flex;gap:6px;align-items:center">
        <input data-m="1" value="${esc(cur)}" placeholder="https://…" style="flex:1">
        ${IB_UPLOAD ? `<button type="button" class="btn btn-ghost btn-sm" id="ib-tpl-up">Upload</button>
        <input type="file" id="ib-tpl-file" hidden accept="image/*,video/mp4,application/pdf">` : ''}
      </div>
      <span id="ib-tpl-upst" class="text-muted" style="font-size:11px"></span>
    </div>`);
  }
  el('ib-tpl-list').innerHTML =
    `<b style="font-size:13px">${esc(ibPick.name)}</b>
     <span style="display:block;font-size:11.5px;color:#667;white-space:pre-wrap;margin-top:4px">${esc(ibPick.preview||'')}</span>
     ${f.join('') || '<p class="text-muted" style="font-size:12.5px;margin-top:10px">Nothing to fill in — ready to send.</p>'}
     <div class="ib-tpl-err" id="ib-tpl-err" hidden></div>`;
  el('ib-tpl-foot').hidden = false;

  const up = el('ib-tpl-up'), file = el('ib-tpl-file'), st = el('ib-tpl-upst');
  if (up && file) {
    up.addEventListener('click', ()=> file.click());
    file.addEventListener('change', async ()=>{
      if (!file.files || !file.files[0]) return;
      st.textContent = 'Uploading…'; up.disabled = true;
      const fd = new FormData(); fd.append('csrf_token', IB_CSRF); fd.append('media', file.files[0]);
      try {
        const r = await fetch(IB_UPLOAD, {method:'POST', body:fd}); const d = await r.json();
        if (d.ok) {
          el('ib-tpl-list').querySelector('[data-m]').value = d.url;
          st.textContent = '✓ Uploaded';
        } else st.textContent = d.error || 'Upload failed.';
      } catch(e){ st.textContent = 'Upload failed.'; }
      up.disabled = false;
    });
  }
}

el('ib-tpl-send').addEventListener('click', async ()=>{
  if(!ibPick || !ibCur) return;
  const btn = el('ib-tpl-send'); btn.disabled = true; btn.textContent = 'Sending…';
  const fd = new FormData();
  fd.append('ajax','send_template'); fd.append('csrf_token',IB_CSRF);
  fd.append('contact',ibCur); fd.append('template',ibPick.id);
  el('ib-tpl-list').querySelectorAll('[data-v]').forEach(i=> fd.append('vars[]', i.value));
  el('ib-tpl-list').querySelectorAll('[data-h]').forEach(i=> fd.append('header_vars[]', i.value));
  const med = el('ib-tpl-list').querySelector('[data-m]');
  if (med) fd.append('header_media', med.value);
  try {
    const r = await fetch(IB_URL,{method:'POST',body:fd}); const d = await r.json();
    if (d.ok) { closeTpl(); pollThread(); loadThreads(); }
    else {
      const err = el('ib-tpl-err');
      err.hidden = false; err.textContent = d.error || 'Could not send.';
    }
  } catch(e){
    const err = el('ib-tpl-err'); err.hidden = false; err.textContent = 'Could not send.';
  }
  btn.disabled = false; btn.textContent = 'Send';
});
el('ib-back').addEventListener('click',()=>{
  document.querySelector('.ib-wrap').classList.remove('chatting');
  ibCur=0; loadThreads();
});
el('ib-q').addEventListener('input',()=>{ clearTimeout(window._ibq); window._ibq=setTimeout(loadThreads,300); });
loadThreads(); setInterval(loadThreads,5000); setInterval(pollThread,3500);
</script>
