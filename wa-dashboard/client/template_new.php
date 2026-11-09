<?php
declare(strict_types=1);
/**
 * Templates → Create template: write a WhatsApp message template and submit it to Meta for review,
 * without WhatsApp Manager. Also edits a template (?edit=) and starts a new one from another (?copy=).
 * The rules and the payload are in includes/template_builder.php; the page only collects the parts.
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/template_builder.php';
require_once __DIR__ . '/../includes/sending.php';

$cid = (int) $CLIENT['id'];
if (channel_is_personal($CLIENT) && !tb_sources($CLIENT)) { flash('Templates are for a WhatsApp Business API number. Your account sends from a linked phone, which needs none.', 'error'); redirect('templates.php'); }
if (!can_write()) { flash('You can only look at this account.', 'error'); redirect('templates.php'); }
$sources = tb_sources($CLIENT);
if (!$sources) { flash('Connect a WhatsApp Business API number first (Settings).', 'error'); redirect('templates.php'); }

$edit = null; $init = null;
if (($id = (int) ($_GET['edit'] ?? $_POST['edit'] ?? 0)) > 0) {
    $edit = db_row("SELECT * FROM templates WHERE id=? AND client_id=?", [$id, $cid]);
    if (!$edit || empty($edit['meta_id'])) { flash('That template cannot be edited here — sync templates first.', 'error'); redirect('templates.php'); }
    $init = tb_describe($edit);
    $init['source'] = (string) ($edit['waba_id'] ?? '') ?: (string) $CLIENT['waba_id'];
} elseif (($id = (int) ($_GET['copy'] ?? 0)) > 0 && ($t = db_row("SELECT * FROM templates WHERE id=? AND client_id=?", [$id, $cid]))) {
    $init = tb_describe($t);
    $init['name'] = substr($init['name'] . '_copy', 0, 512);
    $init['source'] = (string) ($t['waba_id'] ?? '') ?: (string) $CLIENT['waba_id'];
}
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $in = json_decode((string) ($_POST['spec'] ?? ''), true);
    if (!is_array($in)) { $err = 'Something went wrong reading the form. Please try again.'; $in = $init ?? []; }
    else {
        if ($edit) { $in['name'] = $edit['wa_name']; $in['language'] = $edit['language']; $in['source'] = $init['source']; }
        $src = $sources[(string) ($in['source'] ?? '')] ?? reset($sources);
        $upload = function (string $url, string $kind) use ($src, $cid): array {
            $path = tb_local_media($cid, $url);
            if (!$path) return ['ok' => false, 'handle' => '', 'error' => 'the file was not found; upload it again'];
            return wa_upload_sample($src['client'], $path);
        };
        $b = tb_build($in, $CLIENT, $upload);
        if (!$b['ok']) $err = $b['error'];
        elseif (!$edit && db_val("SELECT 1 FROM templates WHERE client_id=? AND wa_name=? AND language=?", [$cid, $b['payload']['name'], $b['payload']['language']])) {
            $err = 'There is already a template called ' . $b['payload']['name'] . ' in this language. Choose another name.';
        } else {
            $stored = json_encode($b['stored'], JSON_UNESCAPED_UNICODE);
            $bi = wa_template_body($b['stored']);
            $wabaCol = $src['key'] === '' ? null : $src['waba'];
            if ($edit) {
                // An approved template keeps its category; Meta refuses a change there.
                $p = $b['payload']; if ($edit['status'] === 'APPROVED') unset($p['category']);
                $r = wa_edit_template($src['client'], (string) $edit['meta_id'], $p);
                if (!$r['ok']) $err = 'Meta did not accept the change: ' . $r['error'];
                else {
                    db_run("UPDATE templates SET components=?, body_text=?, variable_count=?, status='PENDING', rejected_reason=NULL, synced_at=NOW() WHERE id=?",
                           [$stored, $bi['body'], $bi['vars'], (int) $edit['id']]);
                    flash('Changes sent to Meta for review. The template is "Pending" until Meta decides — usually minutes, at most a day. Click Sync Templates to see the result.');
                    redirect('templates.php');
                }
            } else {
                $r = wa_create_template($src['client'], $src['waba'], $b['payload']);
                if (!$r['ok']) $err = 'Meta did not accept the template: ' . $r['error'];
                else {
                    db_insert("INSERT INTO templates (client_id, wa_name, language, category, status, components, body_text, variable_count, waba_id, meta_id, synced_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?,NOW())",
                              [$cid, $b['payload']['name'], $b['payload']['language'], $r['category'], $r['status'], $stored, $bi['body'], $bi['vars'], $wabaCol, $r['id']]);
                    flash('Template submitted to Meta' . ($r['status'] === 'APPROVED' ? ' and approved — you can use it now.' : ' for review. It shows as "Pending" until Meta decides — usually minutes, at most a day. Click Sync Templates to see the result.'));
                    redirect('templates.php');
                }
            }
        }
    }
    $init = $in;
}

$init ??= ['kind' => 'standard', 'category' => 'MARKETING', 'language' => (($CLIENT['default_country'] ?? '') === '20' ? 'ar' : 'en'),
           'name' => '', 'header' => ['format' => 'NONE'], 'body' => ['text' => '', 'samples' => []], 'footer' => '', 'buttons' => [], 'cards' => [], 'lto' => [], 'auth' => [],
           'source' => (string) array_key_first($sources)];

client_header($edit ? 'Edit template' : 'Create template', 'templates', $CLIENT);
page_head($edit ? 'Edit ' . $edit['wa_name'] : 'Create template', '<a class="btn btn-ghost btn-sm" href="templates.php">Back to templates</a>');
?>
<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
<?php if ($edit): ?><div class="alert info" style="font-size:12.5px">Editing sends the template back to Meta for review. Meta allows an approved template to be edited once a day and 10 times a month; its name, language and category stay as they are.</div><?php endif; ?>

<style>
.tb{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:18px;align-items:start}
@media (max-width:980px){.tb{grid-template-columns:1fr}.tb-side{position:static!important}}
.tb-side{position:sticky;top:76px}
.tb .card{margin-bottom:14px}
.tb-seg{display:flex;flex-wrap:wrap;gap:6px}
.tb-seg label{display:flex;gap:8px;align-items:flex-start;border:1px solid var(--line);border-radius:10px;padding:9px 12px;cursor:pointer;flex:1 1 170px;background:var(--surface)}
.tb-seg label:has(input:checked){border-color:var(--primary,#7c3aed);box-shadow:0 0 0 1px var(--primary,#7c3aed) inset}
.tb-seg small{display:block;color:var(--muted,#6b7280);font-size:11.5px;margin-top:2px}
.tb-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.tb-row > *{flex:1 1 160px;min-width:0}
.tb-item{border:1px solid var(--line);border-radius:10px;padding:10px;margin-bottom:8px;background:var(--surface)}
.tb-item h4{margin:0 0 8px;font-size:13px;display:flex;justify-content:space-between;align-items:center}
.tb-count{font-size:11px;color:var(--muted,#6b7280);text-align:end}
.tb-hint{font-size:11.5px;color:var(--muted,#6b7280);margin-top:4px}
.tb textarea{min-height:110px}
.tb-mediabox{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.tb-mediabox img{width:64px;height:40px;object-fit:cover;border-radius:6px;border:1px solid var(--line)}
</style>

<form method="post" id="tb-form" onsubmit="return tbSubmit()">
  <?= csrf_field() ?><input type="hidden" name="spec" id="tb-spec"><?php if ($edit): ?><input type="hidden" name="edit" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
  <div class="tb">
    <div id="tb-main"></div>
    <div class="tb-side">
      <div class="card"><h2 style="font-size:15px">Preview</h2><div id="tb-preview"></div>
        <p class="tb-hint">Variables show as {{1}}; customers see the real values.</p></div>
      <button class="btn btn-primary" style="width:100%" id="tb-go"><?= $edit ? 'Send changes to Meta' : 'Submit to Meta for review' ?></button>
      <p class="tb-hint" style="text-align:center">Meta reviews every template — usually minutes, at most a day.</p>
    </div>
  </div>
</form>

<script src="../assets/tpl_preview.js?v=1"></script>
<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const EDIT = <?= $edit ? json_encode(['status' => $edit['status']]) : 'null' ?>;
const SOURCES = <?= json_encode(array_values(array_map(fn($s) => ['key' => $s['waba'], 'label' => $s['label']], $sources)), JSON_HEX_TAG) ?>;
const LANGS = <?= json_encode(TB_LANGS, JSON_HEX_TAG) ?>;
let S = <?= json_encode($init, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
S.header = S.header || {format:'NONE'}; S.body = S.body || {text:'',samples:[]}; S.buttons = S.buttons || []; S.cards = S.cards || [];
S.lto = S.lto || {}; S.auth = S.auth || {}; if (!S.source && SOURCES[0]) S.source = SOURCES[0].key;
if (Array.isArray(S.lto)) S.lto = {}; if (Array.isArray(S.auth)) S.auth = {};
if (S.kind === 'carousel' && !S.cards.length) S.cards = [newCard(), newCard()];

const $ = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const varsIn = t => [...new Set((String(t||'').match(/\{\{\s*(\d+)\s*\}\}/g)||[]).map(x=>+x.replace(/\D/g,'')))].sort((a,b)=>a-b);
function newCard(){ return {format: (S && S.cards && S.cards[0] ? S.cards[0].format : 'IMAGE') || 'IMAGE', media:'', text:'', samples:[], buttons: cardTypes().map(t=>({type:t, text:'', url:'', url_sample:'', phone:''}))}; }
function cardTypes(){ return (S && S.cards && S.cards[0] ? S.cards[0].buttons.map(b=>b.type) : ['URL']); }
const BTN = {QUICK_REPLY:'Quick reply', URL:'Visit website', PHONE_NUMBER:'Call phone number', COPY_CODE:'Copy offer code'};

/* ── the form, drawn from S; every input writes back into S and redraws the preview ── */
function render(){
  const m = $('tb-main'); const k = S.kind; const cat = S.category;
  let h = '';
  h += `<div class="card"><h2>Basics</h2>
    ${SOURCES.length > 1 ? `<div class="field"><span class="lbl">WhatsApp Business Account</span><select data-k="source" ${EDIT?'disabled':''}>${SOURCES.map(s=>`<option value="${esc(s.key)}" ${s.key===S.source?'selected':''}>${esc(s.label)}</option>`).join('')}</select></div>` : ''}
    <div class="field"><span class="lbl">Category</span><div class="tb-seg">
      ${[['MARKETING','Marketing','Offers, news, invitations, reminders to buy'],['UTILITY','Utility','About something they did: an order, a booking, an account'],['AUTHENTICATION','Authentication','A one-time passcode to log in or confirm']].map(([v,l,d])=>
        `<label><input type="radio" name="cat" value="${v}" ${cat===v?'checked':''} ${EDIT?'disabled':''} onchange="setCat('${v}')"><span><b>${l}</b><small>${d}</small></span></label>`).join('')}
    </div><div class="tb-hint">Meta may move a template to the category it thinks fits; Marketing is charged at a higher rate.</div></div>
    ${cat==='MARKETING' ? `<div class="field"><span class="lbl">Type</span><div class="tb-seg">
      ${[['standard','Standard','Text, picture, video or document, and buttons'],['carousel','Carousel','2–10 swipeable cards, each with a picture or video and buttons'],['lto','Limited-time offer','An offer with a countdown, a code to copy and a link']].map(([v,l,d])=>
        `<label><input type="radio" name="kind" value="${v}" ${k===v?'checked':''} ${EDIT?'disabled':''} onchange="setKind('${v}')"><span><b>${l}</b><small>${d}</small></span></label>`).join('')}
    </div></div>` : ''}
    <div class="tb-row">
      <div class="field"><span class="lbl">Name</span><input data-k="name" value="${esc(S.name)}" maxlength="512" placeholder="summer_offer_2026" ${EDIT?'disabled':''}>
        <div class="tb-hint">Lowercase letters, numbers and _ — only you see it.</div></div>
      <div class="field"><span class="lbl">Language</span><select data-k="language" ${EDIT?'disabled':''}>${Object.entries(LANGS).map(([v,l])=>`<option value="${v}" ${v===S.language?'selected':''}>${esc(l)} (${v})</option>`).join('')}</select></div>
    </div></div>`;

  if (k === 'auth') {
    const a = S.auth; const otp = a.otp_type || 'COPY_CODE';
    h += `<div class="card"><h2>Passcode message</h2>
      <p class="text-muted" style="font-size:12.5px">WhatsApp writes the text itself: "<b>123456</b> is your verification code." Revenect fills the code when it sends.</p>
      <label class="mod-all"><input type="checkbox" data-a="security" ${a.security?'checked':''}> Add "For your security, do not share this code."</label>
      <div class="tb-row" style="margin-top:8px"><div class="field"><span class="lbl">Code expires after (minutes, optional)</span><input type="number" min="1" max="90" data-a="expiry" value="${esc(a.expiry||'')}" placeholder="10"></div>
        <div class="field"><span class="lbl">Button text</span><input data-a="copy_text" maxlength="25" value="${esc(a.copy_text||'Copy code')}"></div></div>
      <div class="field"><span class="lbl">How the code is filled in</span><div class="tb-seg">
        ${[['COPY_CODE','Copy code','They tap the button and paste the code'],['ONE_TAP','One-tap autofill','Your Android app fills it in when they tap'],['ZERO_TAP','Zero-tap','Your Android app fills it in by itself']].map(([v,l,d])=>
          `<label><input type="radio" name="otp" value="${v}" ${otp===v?'checked':''} onchange="S.auth.otp_type='${v}';render()"><span><b>${l}</b><small>${d}</small></span></label>`).join('')}</div></div>
      ${otp!=='COPY_CODE' ? `<div class="tb-row"><div class="field"><span class="lbl">Android package name</span><input data-a="package" value="${esc(a.package||'')}" placeholder="com.example.app"></div>
        <div class="field"><span class="lbl">App signature hash</span><input data-a="signature" maxlength="11" value="${esc(a.signature||'')}" placeholder="K8a/AINcGX7"></div></div>
        <div class="field"><span class="lbl">Autofill button text (optional)</span><input data-a="autofill_text" maxlength="25" value="${esc(a.autofill_text||'')}" placeholder="Autofill"></div>
        ${otp==='ZERO_TAP' ? `<label class="mod-all"><input type="checkbox" data-a="zero_tap_terms" ${a.zero_tap_terms?'checked':''}> I accept WhatsApp's zero-tap terms (the code is filled in without the customer seeing it).</label>` : ''}` : ''}
    </div>`;
  } else {
    if (k !== 'carousel') {
      const hf = S.header.format || 'NONE';
      const opts = k==='lto' ? [['NONE','None'],['IMAGE','Picture'],['VIDEO','Video']] : [['NONE','None'],['TEXT','Text'],['IMAGE','Picture'],['VIDEO','Video'],['DOCUMENT','Document'],['LOCATION','Location']];
      h += `<div class="card"><h2>Header <span class="text-muted" style="font-size:12px;font-weight:400">optional</span></h2>
        <div class="tb-seg">${opts.map(([v,l])=>`<label style="flex:0 1 auto"><input type="radio" name="hf" value="${v}" ${hf===v?'checked':''} onchange="S.header.format='${v}';render()"> ${l}</label>`).join('')}</div>
        ${hf==='TEXT' ? `<div class="field" style="margin-top:10px"><input data-h="text" maxlength="60" value="${esc(S.header.text||'')}" placeholder="Header text — one {{1}} allowed"><div class="tb-count">${(S.header.text||'').length}/60</div></div>${samplesHtml('h', S.header.text, S.header.samples)}` : ''}
        ${['IMAGE','VIDEO','DOCUMENT'].includes(hf) ? `<div style="margin-top:10px">${mediaHtml('header', hf, S.header.media)}<div class="tb-hint">A sample for Meta's review. Campaigns send this one unless you choose another when sending.</div></div>` : ''}
        ${hf==='LOCATION' ? `<p class="tb-hint" style="margin-top:8px">The place is chosen when sending (campaign, automation or Inbox).</p>` : ''}
      </div>`;
    }
    if (k === 'lto') {
      h += `<div class="card"><h2>Offer</h2><div class="field"><span class="lbl">Offer line</span><input data-o="text" maxlength="16" value="${esc(S.lto.text||'')}" placeholder="Ends soon!"><div class="tb-count">${(S.lto.text||'').length}/16</div></div>
        <label class="mod-all"><input type="checkbox" data-o="has_expiration" ${S.lto.has_expiration?'checked':''}> Show a countdown to when the offer ends (the end time is set when sending)</label></div>`;
    }
    const max = k==='lto' ? 600 : 1024;
    h += `<div class="card"><h2>${k==='carousel' ? 'Message above the cards' : 'Message'}</h2>
      <div class="field"><textarea data-b="text" maxlength="${max}" placeholder="Hi {{1}}, …">${esc(S.body.text||'')}</textarea>
      <div class="tb-row" style="justify-content:space-between"><span class="tb-hint" style="flex:1 1 auto">*bold* _italic_ ~strike~ \`\`\`mono\`\`\` · a variable cannot be the very first or last thing.</span>
        <button type="button" class="btn btn-ghost btn-sm" style="flex:0 0 auto" onclick="addVar('b')">+ Add variable</button></div>
      <div class="tb-count">${(S.body.text||'').length}/${max}</div></div>
      ${samplesHtml('b', S.body.text, S.body.samples)}</div>`;
    if (k === 'standard') {
      h += `<div class="card"><h2>Footer <span class="text-muted" style="font-size:12px;font-weight:400">optional</span></h2><input data-k="footer" maxlength="60" value="${esc(S.footer||'')}" placeholder="Reply STOP to stop these messages"><div class="tb-count">${(S.footer||'').length}/60</div></div>`;
    }
    if (k !== 'carousel') {
      const types = k==='lto' ? ['COPY_CODE','URL'] : ['QUICK_REPLY','URL','PHONE_NUMBER','COPY_CODE'];
      h += `<div class="card"><h2>Buttons ${k==='lto' ? '' : '<span class="text-muted" style="font-size:12px;font-weight:400">optional · up to 10</span>'}</h2>
        ${k==='lto' ? '<p class="tb-hint">A website button is needed (where the offer is used); an offer-code button is optional.</p>' : '<p class="tb-hint">More than 3 buttons show as a list. Up to 2 website buttons, 1 call button and 1 offer code.</p>'}
        <div id="tb-btns">${S.buttons.map((b,i)=>buttonHtml(b, 'buttons', i, true)).join('')}</div>
        <div class="tb-row" style="justify-content:flex-start">${types.map(t=>`<button type="button" class="btn btn-ghost btn-sm" style="flex:0 0 auto" onclick="addBtn('${t}')">+ ${BTN[t]}</button>`).join('')}</div></div>`;
    } else {
      const ct = cardTypes(); const fmt = (S.cards[0]||{}).format || 'IMAGE';
      h += `<div class="card"><h2>Cards <span class="text-muted" style="font-size:12px;font-weight:400">2–10</span></h2>
        <div class="tb-row"><div class="field"><span class="lbl">Each card shows</span><select onchange="S.cards.forEach(c=>{c.format=this.value;c.media=''});render()"><option value="IMAGE" ${fmt==='IMAGE'?'selected':''}>A picture</option><option value="VIDEO" ${fmt==='VIDEO'?'selected':''}>A video</option></select></div>
          <div class="field"><span class="lbl">Buttons on every card</span><select onchange="setCardBtns(this.value)">
            ${[['URL','Website'],['QUICK_REPLY','Quick reply'],['PHONE_NUMBER','Call'],['URL,QUICK_REPLY','Website + quick reply'],['URL,URL','Two websites'],['QUICK_REPLY,QUICK_REPLY','Two quick replies'],['URL,PHONE_NUMBER','Website + call'],['QUICK_REPLY,PHONE_NUMBER','Quick reply + call']].map(([v,l])=>`<option value="${v}" ${ct.join(',')===v?'selected':''}>${l}</option>`).join('')}
          </select></div></div>
        <p class="tb-hint">Use pictures of the same shape. Every card has the same buttons; their text and links can differ.</p>
        ${S.cards.map((c,i)=>`<div class="tb-item"><h4>Card ${i+1} ${S.cards.length>2?`<button type="button" class="btn-link" style="color:var(--danger)" onclick="S.cards.splice(${i},1);render()">Remove</button>`:''}</h4>
          ${mediaHtml('card'+i, fmt, c.media)}
          <div class="field" style="margin-top:8px"><textarea data-c="${i}" data-f="text" maxlength="160" style="min-height:64px" placeholder="Card text">${esc(c.text||'')}</textarea><div class="tb-count">${(c.text||'').length}/160</div></div>
          ${samplesHtml('c'+i, c.text, c.samples)}
          ${c.buttons.map((b,j)=>buttonHtml(b, 'card'+i, j, false)).join('')}</div>`).join('')}
        ${S.cards.length < 10 ? `<button type="button" class="btn btn-ghost btn-sm" onclick="S.cards.push(newCard());render()">+ Add card</button>` : ''}</div>`;
    }
  }
  m.innerHTML = h;
  preview();
}
function samplesHtml(key, text, samples){
  const v = varsIn(text); if (!v.length) return '';
  samples = samples || [];
  return `<div class="tb-item"><h4>Samples for Meta's review</h4><div class="tb-row">${v.map((n,i)=>`<div class="field"><span class="lbl">{{${n}}}</span><input data-s="${key}" data-i="${i}" value="${esc(samples[i]||'')}" placeholder="${n===1?'Ahmed':'Sample'}"></div>`).join('')}</div>
    <div class="tb-hint">Real-looking examples, not "test". They are not sent to customers.</div></div>`;
}
function mediaHtml(key, fmt, url){
  const acc = fmt==='IMAGE' ? '.jpg,.jpeg,.png' : fmt==='VIDEO' ? '.mp4' : '.pdf';
  const what = {IMAGE:'picture (JPG or PNG, up to 5 MB)', VIDEO:'video (MP4, up to 16 MB)', DOCUMENT:'document (PDF)'}[fmt];
  const thumb = url ? (fmt==='IMAGE' ? `<img src="${esc(url)}" alt="">` : `<span class="pill green">${fmt==='VIDEO'?'Video':'PDF'} uploaded</span>`) : '';
  return `<div class="tb-mediabox">${thumb}<input type="file" accept="${acc}" style="display:none" id="f-${key}" onchange="upload('${key}', this)">
    <button type="button" class="btn btn-ghost btn-sm" onclick="$('f-${key}').click()">${url ? 'Change' : 'Upload'} ${fmt==='IMAGE'?'picture':fmt==='VIDEO'?'video':'document'}</button>
    <span class="tb-hint" id="st-${key}">${url ? '' : 'Sample ' + what}</span></div>`;
}
function buttonHtml(b, where, i, removable){
  const t = b.type; const p = `data-btn="${where}" data-i="${i}"`;
  let f = '';
  if (t !== 'COPY_CODE') f += `<div class="field"><span class="lbl">Button text</span><input ${p} data-f="text" maxlength="25" value="${esc(b.text||'')}" placeholder="${t==='URL'?'Shop now':t==='PHONE_NUMBER'?'Call us':'Yes, interested'}"></div>`;
  if (t === 'URL') f += `<div class="field"><span class="lbl">Web address</span><input ${p} data-f="url" value="${esc(b.url||'')}" placeholder="https://example.com/offer or …/{{1}}"></div>`
     + (/\{\{\s*1\s*\}\}$/.test(b.url||'') ? `<div class="field"><span class="lbl">Sample for {{1}}</span><input ${p} data-f="url_sample" value="${esc(b.url_sample||'')}" placeholder="summer26"></div>` : '');
  if (t === 'PHONE_NUMBER') f += `<div class="field"><span class="lbl">Phone number</span><input ${p} data-f="phone" value="${esc(b.phone||'')}" placeholder="+201001234567"></div>`;
  if (t === 'COPY_CODE') f += `<div class="field"><span class="lbl">Sample offer code</span><input ${p} data-f="code" maxlength="15" value="${esc(b.code||'')}" placeholder="SUMMER25"><div class="tb-hint">The real code is set when sending.</div></div>`;
  return `<div class="tb-item"><h4>${BTN[t]}${removable ? `<button type="button" class="btn-link" style="color:var(--danger)" onclick="S.buttons.splice(${i},1);render()">Remove</button>` : ''}</h4><div class="tb-row">${f}</div></div>`;
}

/* ── changes ── */
function setCat(v){ S.category = v; S.kind = v==='AUTHENTICATION' ? 'auth' : (v==='UTILITY' ? 'standard' : (S.kind==='auth' ? 'standard' : S.kind)); render(); }
function setKind(v){ S.kind = v; if (v==='carousel' && S.cards.length < 2) S.cards = [newCard(), newCard()]; if (v==='lto') { S.footer=''; if (!['NONE','IMAGE','VIDEO'].includes(S.header.format)) S.header.format='NONE'; S.buttons = S.buttons.filter(b=>['URL','COPY_CODE'].includes(b.type)); } render(); }
function setCardBtns(v){ const ts = v.split(','); S.cards.forEach(c=>{ c.buttons = ts.map((t,j)=>({type:t, text:(c.buttons[j]&&c.buttons[j].type===t?c.buttons[j].text:''), url:(c.buttons[j]||{}).url||'', url_sample:(c.buttons[j]||{}).url_sample||'', phone:(c.buttons[j]||{}).phone||''})); }); render(); }
function addBtn(t){ if (S.buttons.length >= 10) return alert('At most 10 buttons.'); S.buttons.push({type:t, text:'', url:'', url_sample:'', phone:'', code:''}); render(); }
function addVar(where){
  const ta = document.querySelector('[data-b="text"]'); const n = (varsIn(S.body.text).slice(-1)[0]||0) + 1;
  const pos = ta.selectionStart ?? ta.value.length; ta.value = ta.value.slice(0,pos) + '{{'+n+'}}' + ta.value.slice(pos);
  S.body.text = ta.value; render(); const t2 = document.querySelector('[data-b="text"]'); t2.focus(); t2.selectionStart = t2.selectionEnd = pos + String(n).length + 4;
}
async function upload(key, input){
  const f = input.files[0]; if (!f) return; const st = $('st-'+key); st.textContent = 'Uploading…';
  const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('media', f);
  try {
    const d = await (await fetch('upload_media.php', {method:'POST', body:fd})).json();
    if (!d.ok) { st.textContent = d.error || 'Upload failed.'; return; }
    if (key==='header') S.header.media = d.url; else S.cards[+key.slice(4)].media = d.url;
    render();
  } catch (e) { st.textContent = 'Upload failed.'; }
}
document.addEventListener('input', e => {
  const t = e.target, d = t.dataset; let redraw = false;
  if (d.k) S[d.k] = t.type==='checkbox' ? t.checked : t.value;
  else if (d.h) S.header[d.h] = t.value, redraw = true;
  else if (d.b) { S.body[d.b] = t.value; redraw = 'soft'; }
  else if (d.o) S.lto[d.o] = t.type==='checkbox' ? t.checked : t.value;
  else if (d.a) S.auth[d.a] = t.type==='checkbox' ? t.checked : t.value;
  else if (d.c !== undefined) { S.cards[+d.c][d.f] = t.value; redraw = 'soft'; }
  else if (d.s) { const tgt = d.s==='h' ? S.header : d.s==='b' ? S.body : S.cards[+d.s.slice(1)]; (tgt.samples = tgt.samples||[])[+d.i] = t.value; }
  else if (d.btn) { const list = d.btn==='buttons' ? S.buttons : S.cards[+d.btn.slice(4)].buttons; list[+d.i][d.f] = t.value; if (d.f==='url') redraw = 'soft'; }
  if (d.k === 'name') { const v = t.value.toLowerCase().replace(/[\s-]+/g,'_').replace(/[^a-z0-9_]/g,''); if (v !== t.value) { const p = t.selectionStart; t.value = v; S.name = v; t.selectionStart = t.selectionEnd = Math.min(p, v.length); } }
  // Redraw when the set of variables (and so the sample boxes) changed; keep the caret where it was.
  if (redraw) { const key = JSON.stringify([varsIn(S.header.text), varsIn(S.body.text), S.cards.map(c=>varsIn(c.text)), S.buttons.map(b=>/\{\{1\}\}$/.test(b.url||'')), S.cards.map(c=>c.buttons.map(b=>/\{\{1\}\}$/.test(b.url||'')))]);
    if (redraw === true || key !== render.lastKey) { render.lastKey = key; const sel = [t.dataset.b, t.dataset.c, t.dataset.f, t.dataset.btn, t.dataset.i, t.dataset.h, t.selectionStart]; render();
      const q = sel[0] ? `[data-b="${sel[0]}"]` : sel[1] !== undefined ? `[data-c="${sel[1]}"][data-f="${sel[2]}"]` : sel[3] ? `[data-btn="${sel[3]}"][data-i="${sel[4]}"][data-f="${sel[2]}"]` : sel[5] ? `[data-h="${sel[5]}"]` : null;
      const el2 = q && document.querySelector(q); if (el2) { el2.focus(); try { el2.selectionStart = el2.selectionEnd = sel[6]; } catch(_){} } return; } }
  // Counters and preview without redrawing (so typing is not interrupted).
  const cnt = t.parentElement && t.parentElement.querySelector('.tb-count'); if (cnt && t.maxLength > 0) cnt.textContent = t.value.length + '/' + t.maxLength;
  preview();
});

/* ── the preview: the same components Meta gets ── */
function comps(){
  const out = []; const k = S.kind;
  const btn = b => b.type==='URL' ? {type:'URL', text:b.text, url:b.url} : b.type==='PHONE_NUMBER' ? {type:'PHONE_NUMBER', text:b.text} : b.type==='COPY_CODE' ? {type:'COPY_CODE'} : {type:'QUICK_REPLY', text:b.text};
  if (k === 'auth') {
    out.push({type:'BODY', add_security_recommendation: !!S.auth.security});
    if (+S.auth.expiry) out.push({type:'FOOTER', code_expiration_minutes: +S.auth.expiry});
    out.push({type:'BUTTONS', buttons:[{type:'OTP', text: S.auth.copy_text || 'Copy code'}]});
    return out;
  }
  const hf = S.header.format || 'NONE';
  if (k !== 'carousel' && hf !== 'NONE') out.push(hf==='TEXT' ? {type:'HEADER', format:'TEXT', text:S.header.text||''} : {type:'HEADER', format:hf, _media:S.header.media||''});
  if (k === 'lto') out.push({type:'LIMITED_TIME_OFFER', limited_time_offer:{text:S.lto.text||'Offer', has_expiration:!!S.lto.has_expiration}});
  out.push({type:'BODY', text:S.body.text || 'Your message'});
  if (k === 'standard' && S.footer) out.push({type:'FOOTER', text:S.footer});
  if (k !== 'carousel' && S.buttons.length) out.push({type:'BUTTONS', buttons:S.buttons.map(btn)});
  if (k === 'carousel') out.push({type:'CAROUSEL', cards:S.cards.map(c=>({components:[{type:'HEADER', format:c.format||'IMAGE', _media:c.media||''}, {type:'BODY', text:c.text||'Card text'}, {type:'BUTTONS', buttons:c.buttons.map(btn)}]}))});
  return out;
}
function preview(){ $('tb-preview').innerHTML = tplPreview(comps()); }
function tbSubmit(){
  if (!S.name && !EDIT) { alert('Give the template a name.'); return false; }
  $('tb-spec').value = JSON.stringify(S); $('tb-go').disabled = true; $('tb-go').textContent = 'Sending to Meta…'; return true;
}
render();
</script>
<?php layout_footer(); ?>
