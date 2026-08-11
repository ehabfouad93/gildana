<?php
/**
 * Shared Inbox UI (WhatsApp-style two-pane live chat). Include after the page header.
 * Expects: $IB_ENDPOINT (string, e.g. 'inbox.php' or 'inbox.php?client=3').
 * The including page must already have handled ?ajax via inbox_handle_ajax().
 */
$IB_ENDPOINT = $IB_ENDPOINT ?? 'inbox.php';
$IB_SEP = strpos($IB_ENDPOINT, '?') === false ? '?' : '&';
?>
<style>
  .ib-wrap{display:flex;gap:0;height:72vh;min-height:460px;border:1px solid var(--line,#e5e2dc);border-radius:12px;overflow:hidden;background:var(--card,#fff)}
  .ib-list{width:320px;min-width:260px;border-right:1px solid var(--line,#e5e2dc);display:flex;flex-direction:column;background:var(--card,#fff)}
  .ib-search{padding:10px;border-bottom:1px solid var(--line,#e5e2dc)}
  .ib-search input{width:100%;padding:8px 10px;border:1px solid var(--line,#e5e2dc);border-radius:8px;font-size:13px}
  .ib-threads{overflow-y:auto;flex:1}
  .ib-th{display:flex;gap:10px;align-items:center;padding:11px 12px;cursor:pointer;border-bottom:1px solid var(--line,#f0eee9)}
  .ib-th:hover{background:var(--paper,#f7f5f1)} .ib-th.active{background:var(--paper,#eef2f0)}
  .ib-av{width:38px;height:38px;border-radius:50%;background:#25623c;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:15px;flex-shrink:0}
  .ib-th .nm{font-weight:600;font-size:13.5px} .ib-th .pv{color:var(--muted,#8a857c);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}
  .ib-th .meta{margin-left:auto;text-align:right;display:flex;flex-direction:column;gap:3px;align-items:flex-end}
  .ib-th .tm{color:var(--muted,#8a857c);font-size:11px}
  .ib-badge{background:#25d366;color:#fff;border-radius:10px;font-size:11px;padding:1px 7px;font-weight:600}
  .ib-chat{flex:1;display:flex;flex-direction:column;background:#efe7dd;background-image:linear-gradient(rgba(255,255,255,.55),rgba(255,255,255,.55))}
  .ib-chat-h{padding:12px 16px;background:var(--card,#fff);border-bottom:1px solid var(--line,#e5e2dc);display:flex;align-items:center;gap:10px}
  .ib-body{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:6px}
  .ib-b{max-width:74%;padding:7px 11px;border-radius:9px;font-size:13.5px;line-height:1.35;white-space:pre-wrap;word-wrap:break-word;box-shadow:0 1px .5px rgba(0,0,0,.08)}
  .ib-in{align-self:flex-start;background:#fff}
  .ib-out{align-self:flex-end;background:#d9fdd3}
  .ib-b .st{display:block;text-align:right;font-size:10.5px;color:#667;margin-top:2px}
  .ib-b .st.failed{color:#c0392b}
  .ib-foot{padding:10px 12px;background:var(--card,#fff);border-top:1px solid var(--line,#e5e2dc)}
  .ib-foot form{display:flex;gap:8px;align-items:flex-end}
  .ib-foot textarea{flex:1;resize:none;border:1px solid var(--line,#e5e2dc);border-radius:20px;padding:9px 14px;font-size:13.5px;max-height:120px}
  .ib-note{font-size:12px;color:var(--muted,#8a857c);text-align:center;padding:8px}
  .ib-empty{margin:auto;color:var(--muted,#8a857c);text-align:center;font-size:13px}
  .ib-bot{font-size:11.5px;padding:3px 9px;border-radius:11px;white-space:nowrap;font-weight:600}
  .ib-bot.on{background:#e8f5ea;color:#1e7a3c}
  .ib-bot.off{background:#fdf0e3;color:#a9631a}
  @media(max-width:720px){.ib-list{width:44%;min-width:0}.ib-th .pv{max-width:110px}}
</style>

<div class="ib-wrap">
  <div class="ib-list">
    <div class="ib-search"><input type="text" id="ib-q" placeholder="Search name or number…"></div>
    <div class="ib-threads" id="ib-threads"><div class="ib-empty" style="padding:20px">Loading…</div></div>
  </div>
  <div class="ib-chat">
    <div class="ib-chat-h" id="ib-head" style="display:none">
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
      <div class="ib-note" id="ib-closed" style="display:none">⏱️ Outside the 24-hour window — you can only reach this contact with an approved template (Campaigns).</div>
    </div>
  </div>
</div>

<script>
const IB_URL = <?= json_encode($IB_ENDPOINT) ?>;
const IB_SEP = <?= json_encode($IB_SEP) ?>;
const IB_CSRF = <?= json_encode(csrf_token()) ?>;
let ibCur = 0, ibLast = 0, ibOpen = false;
const el = id => document.getElementById(id);
const esc = s => (s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const initials = s => (s||'?').trim().slice(0,2).toUpperCase();
function tfmt(t){ if(!t) return ''; const d=new Date(t.replace(' ','T')); return isNaN(d)?'':d.toLocaleString([], {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); }
const tick = s => s==='read'?'✓✓':s==='delivered'?'✓✓':s==='sent'?'✓':s==='failed'?'⚠ failed':'';

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
    const errLine = (m.status==='failed' && m.error_title) ? `<span class="st failed" style="display:block">⚠ ${esc(m.error_title)}</span>` : '';
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
el('ib-q').addEventListener('input',()=>{ clearTimeout(window._ibq); window._ibq=setTimeout(loadThreads,300); });
loadThreads(); setInterval(loadThreads,5000); setInterval(pollThread,3500);
</script>
