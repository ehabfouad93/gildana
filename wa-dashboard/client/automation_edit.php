<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];
$id  = (int) ($_GET['id'] ?? 0);
$flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='bot'", [$id, $cid]);
if (!$flow) { http_response_code(404); exit('Automation not found.'); }

$err = '';

/* ── Save (canvas graph) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verify_csrf();
    $name    = trim((string) ($_POST['name'] ?? $flow['name']));
    $trigger = (string) ($_POST['trigger_type'] ?? $flow['trigger_type']);
    if (!in_array($trigger, ['keyword', 'welcome'], true)) $trigger = 'keyword';
    $hotMin  = max(0, (int) ($_POST['hot_min'] ?? 70));
    $warmMin = max(0, (int) ($_POST['warm_min'] ?? 40));

    $tc = null;
    if ($trigger === 'keyword') {
        $kws = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', (string) ($_POST['keywords'] ?? '')))));
        $tc  = ['keywords' => $kws, 'match_type' => (string) ($_POST['match_type'] ?? 'contains')];
    }

    $graph = json_decode((string) ($_POST['flow_json'] ?? '{}'), true);
    $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
    $start = is_array($graph['start'] ?? null) ? $graph['start'] : [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run("DELETE FROM flow_steps WHERE flow_id=?", [$id]);

        // Pass 1: insert nodes → tempid map.
        $map = [];
        foreach ($nodes as $i => $nd) {
            $tid = (string) ($nd['id'] ?? '');
            if ($tid === '') continue;
            $map[$tid] = db_insert(
                "INSERT INTO flow_steps (flow_id,client_id,sort,pos_x,pos_y,type,config,created_at) VALUES (?,?,?,?,?,?, '{}', NOW())",
                [$id, $cid, $i, (int) ($nd['x'] ?? 0), (int) ($nd['y'] ?? 0), (string) ($nd['type'] ?? 'text')]
            );
        }
        $R = fn($tid) => ($tid && isset($map[$tid])) ? $map[$tid] : null;

        // Pass 2: config + routing from outputs.
        foreach ($nodes as $nd) {
            $tid = (string) ($nd['id'] ?? '');
            if ($tid === '' || !isset($map[$tid])) continue;
            $type = (string) ($nd['type'] ?? 'text');
            $c    = (array) ($nd['config'] ?? []);
            $out  = (array) ($nd['outputs'] ?? []);
            $cfg  = []; $next = null;
            switch ($type) {
                case 'text':     $cfg = ['body' => (string) ($c['body'] ?? '')]; $next = $R($out['next'] ?? null); break;
                case 'image':    $cfg = ['link' => (string) ($c['link'] ?? ''), 'caption' => (string) ($c['caption'] ?? '')]; $next = $R($out['next'] ?? null); break;
                case 'template':
                    // Full parameter set — a template with an image header needs its header
                    // component or Meta rejects every send (#132012).
                    $cfg = [
                        'template_id'  => (int) ($c['template_id'] ?? 0),
                        'lang'         => 'en',
                        'header_media' => (string) ($c['header_media'] ?? ''),
                        'header_vars'  => (array) ($c['header_vars'] ?? []),
                        'header_loc'   => (array) ($c['header_loc'] ?? []),
                        'vars'         => (array) ($c['vars'] ?? []),
                        'buttons'      => (array) ($c['buttons'] ?? []),
                    ];
                    $next = $R($out['next'] ?? null);
                    break;
                case 'question': $cfg = ['body' => (string) ($c['body'] ?? ''), 'save_as' => (string) ($c['save_as'] ?? '')]; $next = $R($out['next'] ?? null); break;
                case 'ai_score': $cfg = ['criterion' => (string) ($c['criterion'] ?? ''), 'max_points' => (int) ($c['max_points'] ?? 10)]; $next = $R($out['next'] ?? null); break;
                case 'score':    $cfg = ['points' => (int) ($c['points'] ?? 0)]; $next = $R($out['next'] ?? null); break;
                case 'wait':     $cfg = ['seconds' => max(1, (int) ($c['seconds'] ?? 3600))]; $next = $R($out['next'] ?? null); break;
                case 'tag':      $cfg = ['tag' => (string) ($c['tag'] ?? '')]; $next = $R($out['next'] ?? null); break;
                case 'list_add': $cfg = ['list_id' => (int) ($c['list_id'] ?? 0)]; $next = $R($out['next'] ?? null); break;
                case 'notify':   $cfg = ['message' => (string) ($c['message'] ?? '')]; $next = $R($out['next'] ?? null); break;
                case 'collect':  $cfg = ['sheet_name' => (string) ($c['sheet_name'] ?? 'Leads'), 'fields' => array_values((array) ($c['fields'] ?? []))]; $next = $R($out['next'] ?? null); break;
                case 'buttons':
                    $btns = [];
                    foreach ((array) ($c['buttons'] ?? []) as $bi => $b) {
                        $btns[] = ['title' => (string) ($b['title'] ?? ''), 'points' => (int) ($b['points'] ?? 0), 'next_step_id' => $R($out['b' . $bi] ?? null)];
                    }
                    $cfg = ['body' => (string) ($c['body'] ?? ''), 'buttons' => $btns];
                    break;
                case 'ai_branch':
                    $brs = [];
                    foreach ((array) ($c['branches'] ?? []) as $ri => $b) {
                        $brs[] = ['label' => (string) ($b['label'] ?? ''), 'description' => (string) ($b['description'] ?? ''), 'points' => (int) ($b['points'] ?? 0), 'next_step_id' => $R($out['r' . $ri] ?? null)];
                    }
                    $cfg = ['prompt' => (string) ($c['prompt'] ?? ''), 'branches' => $brs, 'fallback_next' => $R($out['fallback'] ?? null)];
                    break;
            }
            db_run("UPDATE flow_steps SET config=?, next_step_id=? WHERE id=?",
                [json_encode($cfg, JSON_UNESCAPED_UNICODE), $next, $map[$tid]]);
        }

        $firstStep = $R($start['next'] ?? null);
        $sourceConfig = json_encode(['start' => ['x' => (int) ($start['x'] ?? 60), 'y' => (int) ($start['y'] ?? 220)]], JSON_UNESCAPED_UNICODE);

        db_run("UPDATE flows SET name=?, trigger_type=?, trigger_config=?, source_config=?, hot_min=?, warm_min=?, first_step_id=?, updated_at=NOW() WHERE id=?",
            [$name, $trigger, $tc ? json_encode($tc, JSON_UNESCAPED_UNICODE) : null, $sourceConfig, $hotMin, $warmMin, $firstStep, $id]);
        $pdo->commit();
        flash('Automation saved.');
        redirect('automation_edit.php?id=' . $id);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('flow save: ' . $ex->getMessage());
        $err = 'Could not save the automation. Please try again.';
    }
    $flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=?", [$id, $cid]);
}

/* ── data for builder ── */
$templates = db_all("SELECT id, wa_name, language, components FROM templates WHERE client_id=? AND status='APPROVED' ORDER BY wa_name", [$cid]);
$lists     = db_all("SELECT id, name FROM contact_lists WHERE client_id=? ORDER BY name", [$cid]);
$tc        = json_decode((string) $flow['trigger_config'], true) ?: [];
$scfg      = json_decode((string) $flow['source_config'], true) ?: [];

$stepsRaw = db_all("SELECT * FROM flow_steps WHERE flow_id=? ORDER BY sort, id", [$id]);
$tid = fn($sid) => ($sid !== null) ? ('s' . (int) $sid) : null;
$nodes = [];
foreach ($stepsRaw as $k => $s) {
    $c = json_decode((string) $s['config'], true) ?: [];
    $x = (int) $s['pos_x'] ?: (80 + ($k % 4) * 250);
    $y = (int) $s['pos_y'] ?: (120 + intdiv($k, 4) * 170);
    $node = ['id' => 's' . (int) $s['id'], 'type' => $s['type'], 'x' => $x, 'y' => $y, 'config' => [], 'outputs' => []];
    switch ($s['type']) {
        case 'text':     $node['config'] = ['body' => $c['body'] ?? '']; $node['outputs']['next'] = $tid($s['next_step_id']); break;
        case 'image':    $node['config'] = ['link' => $c['link'] ?? '', 'caption' => $c['caption'] ?? '']; $node['outputs']['next'] = $tid($s['next_step_id']); break;
        case 'template': $node['config'] = [
                'template_id'  => (int) ($c['template_id'] ?? 0),
                'header_media' => (string) ($c['header_media'] ?? ''),
                'header_vars'  => (object) ((array) ($c['header_vars'] ?? [])),
                'header_loc'   => (object) ((array) ($c['header_loc'] ?? [])),
                'vars'         => (object) ((array) ($c['vars'] ?? [])),
                'buttons'      => (object) ((array) ($c['buttons'] ?? [])),
            ]; $node['outputs']['next'] = $tid($s['next_step_id']); break;
        case 'question': $node['config'] = ['body' => $c['body'] ?? '', 'save_as' => $c['save_as'] ?? '']; $node['outputs']['next'] = $tid($s['next_step_id']); break;
        case 'ai_score': $node['config'] = ['criterion' => $c['criterion'] ?? '', 'max_points' => (int) ($c['max_points'] ?? 10)]; $node['outputs']['next'] = $tid($s['next_step_id']); break;
        case 'score':    $node['config'] = ['points' => (int) ($c['points'] ?? 0)]; $node['outputs']['next'] = $tid($s['next_step_id']); break;
        case 'wait':     $node['config'] = ['seconds' => (int) ($c['seconds'] ?? 3600)]; $node['outputs']['next'] = $tid($s['next_step_id']); break;
        case 'tag':      $node['config'] = ['tag' => $c['tag'] ?? '']; $node['outputs']['next'] = $tid($s['next_step_id']); break;
        case 'list_add': $node['config'] = ['list_id' => (int) ($c['list_id'] ?? 0)]; $node['outputs']['next'] = $tid($s['next_step_id']); break;
        case 'notify':   $node['config'] = ['message' => $c['message'] ?? '']; $node['outputs']['next'] = $tid($s['next_step_id']); break;
        case 'collect':  $node['config'] = ['sheet_name' => $c['sheet_name'] ?? 'Leads', 'fields' => $c['fields'] ?? ['phone','name','last_reply','score','tags']]; $node['outputs']['next'] = $tid($s['next_step_id']); break;
        case 'buttons':
            $node['config'] = ['body' => $c['body'] ?? '', 'buttons' => array_map(fn($b) => ['title' => $b['title'] ?? '', 'points' => (int) ($b['points'] ?? 0)], (array) ($c['buttons'] ?? []))];
            foreach ((array) ($c['buttons'] ?? []) as $bi => $b) $node['outputs']['b' . $bi] = $tid($b['next_step_id'] ?? null);
            break;
        case 'ai_branch':
            $node['config'] = ['prompt' => $c['prompt'] ?? '', 'branches' => array_map(fn($b) => ['label' => $b['label'] ?? '', 'description' => $b['description'] ?? '', 'points' => (int) ($b['points'] ?? 0)], (array) ($c['branches'] ?? []))];
            foreach ((array) ($c['branches'] ?? []) as $ri => $b) $node['outputs']['r' . $ri] = $tid($b['next_step_id'] ?? null);
            $node['outputs']['fallback'] = $tid($c['fallback_next'] ?? null);
            break;
    }
    $nodes[] = $node;
}
$startNode = [
    'x'    => (int) ($scfg['start']['x'] ?? 60),
    'y'    => (int) ($scfg['start']['y'] ?? 220),
    'next' => $tid($flow['first_step_id']),
];

client_header('Edit · ' . $flow['name'], 'automations', $CLIENT);
?>
<div class="page-head">
  <h1><?= e((string) $flow['name']) ?></h1>
  <div class="page-actions">
    <a class="btn btn-ghost btn-sm" href="automations.php">← All</a>
    <button type="submit" form="flow-form" class="btn btn-primary btn-sm">Save</button>
  </div>
</div>
<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<form method="post" id="flow-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="flow_json" id="flow_json">

  <div class="card">
    <div class="grid3">
      <div class="field"><span class="lbl">Name</span><input type="text" name="name" value="<?= e((string) $flow['name']) ?>" required></div>
      <div class="field"><span class="lbl">Trigger</span>
        <select name="trigger_type" id="trigger_type" onchange="onTrig()">
          <option value="keyword" <?= $flow['trigger_type'] === 'keyword' ? 'selected' : '' ?>>Keyword reply</option>
          <option value="welcome" <?= $flow['trigger_type'] === 'welcome' ? 'selected' : '' ?>>Welcome (first message)</option>
        </select>
      </div>
      <div class="field" id="kw-wrap"><span class="lbl">Keywords</span><input type="text" name="keywords" value="<?= e(implode(', ', (array) ($tc['keywords'] ?? []))) ?>" placeholder="price, info"></div>
    </div>
    <div class="grid3">
      <div class="field" id="mt-wrap"><span class="lbl">Match</span>
        <select name="match_type">
          <?php foreach (['contains'=>'Contains','exact'=>'Exact','starts'=>'Starts with'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= ($tc['match_type'] ?? 'contains')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><span class="lbl">Hot score ≥</span><input type="number" name="hot_min" value="<?= (int) $flow['hot_min'] ?>"></div>
      <div class="field"><span class="lbl">Warm score ≥</span><input type="number" name="warm_min" value="<?= (int) $flow['warm_min'] ?>"></div>
    </div>
  </div>

  <div class="card">
    <div class="canvas-toolbar">
      <select id="add-type">
        <optgroup label="Send">
          <option value="text">Send text</option>
          <option value="image">Send image</option>
          <option value="template">Send template</option>
          <option value="buttons">Reply buttons</option>
        </optgroup>
        <optgroup label="Ask / branch">
          <option value="question">Ask &amp; capture</option>
          <option value="ai_branch">AI branch</option>
        </optgroup>
        <optgroup label="Score / act">
          <option value="ai_score">AI score</option>
          <option value="score">Add points</option>
          <option value="wait">Wait</option>
          <option value="tag">Tag contact</option>
          <option value="list_add">Add to list</option>
          <option value="notify">Notify me</option>
          <option value="collect">Collect to sheet</option>
        </optgroup>
      </select>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addNode(document.getElementById('add-type').value)">+ Add node</button>
      <span class="canvas-hint">Drag node headers to move · drag a right-side port onto another node to connect · click a node to edit · <strong>right-click a connection to delete it</strong> · Ctrl/⌘+scroll or the +/− buttons to zoom · middle-drag or space-drag to pan.</span>
    </div>
    <div class="canvas-stage">
      <!-- Zoom control lives outside the scroller so it stays pinned while the board scrolls. -->
      <div class="canvas-zoom">
        <button type="button" onclick="zoomBy(0.1)" title="Zoom in">＋</button>
        <div class="zlevel" id="zlevel">100%</div>
        <button type="button" onclick="zoomBy(-0.1)" title="Zoom out">−</button>
        <button type="button" class="zfit" onclick="zoomFit()" title="Fit the whole flow">Fit</button>
      </div>
      <div class="canvas-wrap" id="cwrap">
        <div class="canvas" id="canvas"><svg class="edges" id="edges"></svg></div>
      </div>
    </div>
  </div>
</form>

<div class="cfg-panel" id="cfg"><h3><span id="cfg-title">Step</span><span class="close-x" onclick="closeCfg()">✕</span></h3><div id="cfg-body"></div>
  <div class="mt16"><button type="button" class="btn btn-danger btn-sm" onclick="deleteCurrent()">Delete node</button></div>
</div>

<script>
const TEMPLATES = <?= json_encode(array_map(fn($t)=>['id'=>(int)$t['id'],'label'=>$t['wa_name'].' ('.$t['language'].')'],$templates)) ?>;
const CSRF = <?= json_encode(csrf_token()) ?>;
// Full parameter spec per template — drives the template node's field panel.
const TPL_SPECS = <?= json_encode(array_reduce($templates, function ($acc, $t) {
    $comp = json_decode((string) $t['components'], true) ?: [];
    $s = wa_template_spec($comp);
    $s['header_example'] = wa_template_header_example($comp);
    $acc[(int) $t['id']] = $s;
    return $acc;
}, [])) ?>;
const LISTS = <?= json_encode(array_map(fn($l)=>['id'=>(int)$l['id'],'name'=>$l['name']],$lists)) ?>;
const INIT_NODES = <?= json_encode($nodes) ?>;
const INIT_START = <?= json_encode($startNode) ?>;
const esc = s => (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

const TYPE_LABEL = {start:'Trigger',text:'Send text',image:'Send image',template:'Template',buttons:'Buttons',question:'Ask',ai_branch:'AI branch',ai_score:'AI score',score:'Add points',wait:'Wait',tag:'Tag',list_add:'Add to list',notify:'Notify',collect:'Collect'};

let nodes = {};        // id -> {id,type,x,y,config,outputs}
let start = {id:'start', type:'start', x:INIT_START.x, y:INIT_START.y, outputs:{next:INIT_START.next||null}};
let seq = 1;
const canvas = document.getElementById('canvas');
const edges = document.getElementById('edges');
let current = null;    // node being configured

function nid(){ return 't'+(seq++); }

/* ── summaries ── */
function summary(n){
  const c=n.config||{};
  switch(n.type){
    case 'start': return '<span class="muted">When '+(document.getElementById('trigger_type')?.value||'keyword')+' triggers</span>';
    case 'text': return esc(c.body)||'<span class="muted">(empty message)</span>';
    case 'image': return '🖼 '+(esc(c.caption)||esc(c.link)||'<span class="muted">image</span>');
    case 'template': {
      const t=TEMPLATES.find(x=>x.id==c.template_id);
      if(!t) return '<span class="muted">choose template</span>';
      const sp=TPL_SPECS[c.template_id], fmt=(sp&&sp.header&&sp.header.format||'').toUpperCase();
      // Flag the exact thing that used to make these sends fail silently.
      const needMedia=['IMAGE','VIDEO','DOCUMENT'].includes(fmt) && !(c.header_media||'').trim();
      return esc(t.label)+(needMedia?'<div class="muted" style="margin-top:4px;color:#c0392b">⚠ '+fmt.toLowerCase()+' header missing</div>':'');
    }
    case 'buttons': return esc(c.body)+'<div class="muted" style="margin-top:4px">'+((c.buttons||[]).map(b=>'▸ '+esc(b.title||'?')).join('<br>'))+'</div>';
    case 'question': return '❓ '+(esc(c.body)||'<span class="muted">question</span>')+(c.save_as?' <span class="muted">→ '+esc(c.save_as)+'</span>':'');
    case 'ai_branch': return '🤖 AI branch<div class="muted" style="margin-top:4px">'+((c.branches||[]).map(b=>'▸ '+esc(b.label||'?')+(b.points?' +'+b.points:'')).join('<br>'))+'<br>▸ (else)</div>';
    case 'ai_score': return '🎯 AI score: '+(esc(c.criterion).slice(0,40)||'<span class="muted">criterion</span>')+' <span class="muted">(max '+(c.max_points||10)+')</span>';
    case 'score': return '➕ '+(c.points||0)+' points';
    case 'wait': { let s=c.seconds||0; let t=s%86400===0?(s/86400+'d'):s%3600===0?(s/3600+'h'):Math.round(s/60)+'m'; return '⏱ wait '+t; }
    case 'tag': return '🏷 tag: '+esc(c.tag);
    case 'list_add': { const l=LISTS.find(x=>x.id==c.list_id); return '📋 add to '+(l?esc(l.name):'list'); }
    case 'notify': return '🔔 notify me';
    case 'collect': return '📥 sheet: '+esc(c.sheet_name||'Leads');
  }
  return '';
}
function outPorts(n){
  if(n.type==='start') return [{key:'next',label:''}];
  if(n.type==='buttons') return (n.config.buttons||[]).map((b,i)=>({key:'b'+i,label:b.title||('Button '+(i+1))}));
  if(n.type==='ai_branch'){ const a=(n.config.branches||[]).map((b,i)=>({key:'r'+i,label:b.label||('Branch '+(i+1))})); a.push({key:'fallback',label:'(else)'}); return a; }
  return [{key:'next',label:''}];
}

/* ── render ── */
function nodeEl(n){
  const el=document.createElement('div');
  el.className='node'+(n.type==='start'?' start':''); el.dataset.id=n.id;
  el.style.left=n.x+'px'; el.style.top=n.y+'px';
  const ports=outPorts(n);
  el.innerHTML =
    (n.type==='start'?'':`<span class="port port-in" data-node="${n.id}" data-port="in"></span>`) +
    `<div class="node-head" data-drag="1">${TYPE_LABEL[n.type]||n.type}${n.type==='start'?'':'<span class="del" title="Delete">✕</span>'}</div>`+
    `<div class="node-body">${summary(n)}</div>`+
    `<div class="node-ports">`+ports.map(p=>`<div class="prow"><span class="plabel">${esc(p.label)}</span><span class="port port-out" data-node="${n.id}" data-port="${p.key}"></span></div>`).join('')+`</div>`;
  return el;
}
function render(){
  [...canvas.querySelectorAll('.node')].forEach(e=>e.remove());
  canvas.appendChild(nodeEl(start));
  Object.values(nodes).forEach(n=>canvas.appendChild(nodeEl(n)));
  redraw();
}
/* ── zoom ──
   The canvas is CSS-scaled, so every getBoundingClientRect() value comes back already
   multiplied by the zoom while the SVG edge layer still draws in unscaled units. Every
   screen→canvas conversion must therefore divide by Z, or nodes jump away from the cursor
   and edges detach from their ports. toCanvas() is the single place that does it. */
let Z = 1;
const wrap = document.getElementById('cwrap');
function setZoom(z, anchor){
  const nz = Math.min(1.5, Math.max(0.3, Math.round(z*100)/100));
  if(nz===Z) return;
  // Keep the anchor point (cursor, or viewport centre) visually fixed while scaling.
  const a = anchor || {x:wrap.clientWidth/2, y:wrap.clientHeight/2};
  const cx = (wrap.scrollLeft + a.x) / Z, cy = (wrap.scrollTop + a.y) / Z;
  Z = nz;
  canvas.style.setProperty('--z', Z);
  wrap.scrollLeft = cx*Z - a.x;
  wrap.scrollTop  = cy*Z - a.y;
  document.getElementById('zlevel').textContent = Math.round(Z*100)+'%';
  redraw();
}
function zoomBy(d){ setZoom(Z+d); }
function zoomFit(){
  const all=[start,...Object.values(nodes)];
  if(!all.length) return setZoom(1);
  let maxX=0,maxY=0;
  all.forEach(n=>{ maxX=Math.max(maxX,n.x+240); maxY=Math.max(maxY,n.y+200); });
  const z=Math.min(1, (wrap.clientWidth-24)/maxX, (wrap.clientHeight-24)/maxY);
  setZoom(Math.max(0.4, z));
  wrap.scrollLeft=0; wrap.scrollTop=0;
}
/** Screen coords → unscaled canvas coords. */
function toCanvas(clientX, clientY){
  const cr=canvas.getBoundingClientRect();
  return {x:(clientX-cr.left)/Z, y:(clientY-cr.top)/Z};
}

function portCenter(nid,port,kind){
  const sel = kind==='in' ? `.port-in[data-node="${nid}"]` : `.port-out[data-node="${nid}"][data-port="${port}"]`;
  const el=canvas.querySelector(sel); if(!el) return null;
  const cr=canvas.getBoundingClientRect(), r=el.getBoundingClientRect();
  // Divide by Z: both rects are scaled, but the SVG draws unscaled.
  return {x:(r.left-cr.left+r.width/2)/Z, y:(r.top-cr.top+r.height/2)/Z};
}
function pathD(a,b){ const dx=Math.max(40,Math.abs(b.x-a.x)/2); return `M${a.x},${a.y} C${a.x+dx},${a.y} ${b.x-dx},${b.y} ${b.x},${b.y}`; }
function redraw(tmp){
  let h='';
  const all=[start,...Object.values(nodes)];
  all.forEach(n=>{ Object.entries(n.outputs||{}).forEach(([port,tgt])=>{
    if(!tgt || (tgt!=='start' && !nodes[tgt])) return;
    const a=portCenter(n.id,port,'out'), b=portCenter(tgt,null,'in'); if(!a||!b) return;
    const d=pathD(a,b);
    // Each edge is identifiable (data-node/data-port) and gets a fat transparent
    // hit path so it can be right-clicked.
    h+=`<path class="edge" data-node="${n.id}" data-port="${port}" d="${d}"/>`
      +`<path class="hit" data-node="${n.id}" data-port="${port}" d="${d}"/>`;
  }); });
  if(tmp) h+=`<path class="temp" d="${pathD(tmp.a,tmp.b)}"/>`;
  edges.innerHTML=h;
}
/* ── right-click a connection → delete it ── */
function edgeLabel(nodeId, port){
  const n = nodeId==='start'?start:nodes[nodeId];
  if(!n) return 'connection';
  const from = TYPE_LABEL[n.type]||n.type;
  const p = outPorts(n).find(p=>p.key===port);
  return from + (p && p.label ? ' › '+p.label : '');
}
function closeEdgeMenu(){ const m=document.getElementById('edge-menu'); if(m) m.remove(); }
function openEdgeMenu(x,y,nodeId,port){
  closeEdgeMenu();
  const m=document.createElement('div');
  m.className='edge-menu'; m.id='edge-menu';
  m.style.left=Math.min(x,window.innerWidth-190)+'px';
  m.style.top=Math.min(y,window.innerHeight-90)+'px';
  m.innerHTML=`<div style="padding:6px 10px;font-size:11px;color:var(--muted);border-bottom:1px solid var(--line,#e5e2dc);margin-bottom:4px">${esc(edgeLabel(nodeId,port))}</div>`
    +`<button type="button" class="danger" id="em-del">✕ Delete connection</button>`;
  document.body.appendChild(m);
  document.getElementById('em-del').onclick=()=>{
    const src = nodeId==='start'?start:nodes[nodeId];
    if(src && src.outputs) src.outputs[port]=null;
    closeEdgeMenu(); redraw();
  };
}
edges.addEventListener('contextmenu', e=>{
  const p=e.target.closest('path.hit'); if(!p) return;   // only over an edge
  e.preventDefault();
  openEdgeMenu(e.clientX, e.clientY, p.dataset.node, p.dataset.port);
});
edges.addEventListener('mouseover', e=>{
  const p=e.target.closest('path.hit'); if(!p) return;
  const vis=edges.querySelector(`path.edge[data-node="${p.dataset.node}"][data-port="${p.dataset.port}"]`);
  if(vis) vis.classList.add('hot');
});
edges.addEventListener('mouseout', e=>{
  const p=e.target.closest('path.hit'); if(!p) return;
  edges.querySelectorAll('path.edge.hot').forEach(x=>x.classList.remove('hot'));
});
document.addEventListener('mousedown', e=>{ if(!e.target.closest('.edge-menu')) closeEdgeMenu(); });
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeEdgeMenu(); });

/* ── add / delete ── */
function defaultConfig(type){
  return {text:{body:''},image:{link:'',caption:''},template:{template_id:0,header_media:'',header_vars:{},header_loc:{},vars:{},buttons:{}},buttons:{body:'',buttons:[{title:'Yes',points:0},{title:'No',points:0}]},question:{body:'',save_as:''},ai_branch:{prompt:'',branches:[{label:'Interested',description:'',points:0}]},ai_score:{criterion:'',max_points:10},score:{points:0},wait:{seconds:3600},tag:{tag:''},list_add:{list_id:0},notify:{message:''},collect:{sheet_name:'Leads',fields:['phone','name','last_reply','score','tags']}}[type]||{};
}
function addNode(type,data){
  const w=document.getElementById('cwrap');
  // Drop it into the visible area — scroll offsets are in scaled px, so divide by Z.
  const n={id:nid(),type,x:(w.scrollLeft+120)/Z,y:(w.scrollTop+90)/Z,config:data?JSON.parse(JSON.stringify(data.config||defaultConfig(type))):defaultConfig(type),outputs:data?(data.outputs||{}):{}};
  nodes[n.id]=n; render(); openCfg(n.id);
}
function deleteCurrent(){ if(!current) return; const id=current.id; delete nodes[id];
  [start,...Object.values(nodes)].forEach(n=>Object.keys(n.outputs||{}).forEach(k=>{ if(n.outputs[k]===id) n.outputs[k]=null; }));
  closeCfg(); render();
}

/* ── config panel ── */
function openCfg(id){ current = id==='start'?start:nodes[id]; if(!current) return;
  document.querySelectorAll('.node').forEach(e=>e.classList.toggle('sel',e.dataset.id===id));
  document.getElementById('cfg-title').textContent=TYPE_LABEL[current.type]||current.type;
  document.getElementById('cfg-body').innerHTML=current.type==='start'?'<p class="text-muted" style="font-size:13px">This is the entry point. Connect its port to the first step. Trigger settings are at the top of the page.</p>':cfgForm(current);
  document.querySelector('#cfg [onclick="deleteCurrent()"]').parentElement.style.display=current.type==='start'?'none':'';
  document.getElementById('cfg').classList.add('open');
  bindCfg();
}
function closeCfg(){ document.getElementById('cfg').classList.remove('open'); document.querySelectorAll('.node.sel').forEach(e=>e.classList.remove('sel')); current=null; }
function cfgForm(n){ const c=n.config;
  switch(n.type){
    case 'text': return `<label><span class="lbl">Message</span><textarea data-k="body" rows="3">${esc(c.body)}</textarea></label><div class="hint">Use {{name}}, {{phone}} or a saved field.</div>`;
    case 'image': return `<label><span class="lbl">Image URL</span><input data-k="link" value="${esc(c.link)}"></label><label><span class="lbl">Caption</span><input data-k="caption" value="${esc(c.caption)}"></label>`;
    case 'template': return `<label><span class="lbl">Template</span><select id="tpl-sel" onchange="onTplChange(this.value)">${'<option value="0">— choose —</option>'+TEMPLATES.map(t=>`<option value="${t.id}" ${t.id==c.template_id?'selected':''}>${esc(t.label)}</option>`).join('')}</select></label><div id="tpl-fields"></div>`;
    case 'question': return `<label><span class="lbl">Question</span><textarea data-k="body" rows="2">${esc(c.body)}</textarea></label><label><span class="lbl">Save answer as</span><input data-k="save_as" value="${esc(c.save_as)}" placeholder="e.g. budget"></label>`;
    case 'ai_score': return `<label><span class="lbl">Scoring criterion</span><textarea data-k="criterion" rows="3">${esc(c.criterion)}</textarea></label><label><span class="lbl">Max points</span><input type="number" data-k="max_points" value="${c.max_points}"></label>`;
    case 'score': return `<label><span class="lbl">Points to add</span><input type="number" data-k="points" value="${c.points}"></label>`;
    case 'wait': { let s=c.seconds||3600,u='m',v=Math.round(s/60); if(s%86400===0){u='d';v=s/86400;}else if(s%3600===0){u='h';v=s/3600;} return `<div class="grid2"><label><span class="lbl">Wait</span><input type="number" id="wv" value="${v}" min="1"></label><label><span class="lbl">Unit</span><select id="wu"><option value="m" ${u==='m'?'selected':''}>Minutes</option><option value="h" ${u==='h'?'selected':''}>Hours</option><option value="d" ${u==='d'?'selected':''}>Days</option></select></label></div><div class="hint">After 24h only template steps can send.</div>`; }
    case 'tag': return `<label><span class="lbl">Tag</span><input data-k="tag" value="${esc(c.tag)}"></label>`;
    case 'list_add': return `<label><span class="lbl">Add to list</span><select data-k="list_id">${'<option value="0">— choose —</option>'+LISTS.map(l=>`<option value="${l.id}" ${l.id==c.list_id?'selected':''}>${esc(l.name)}</option>`).join('')}</select></label>`;
    case 'notify': return `<label><span class="lbl">Message (emailed to Gildana)</span><textarea data-k="message" rows="2">${esc(c.message)}</textarea></label>`;
    case 'collect': { const f=c.fields||[]; return `<label><span class="lbl">Sheet name</span><input data-k="sheet_name" value="${esc(c.sheet_name)}"></label><div class="lbl mt10">Capture fields</div>`+['phone','name','last_reply','score','grade','tags'].map(k=>`<label style="display:flex;gap:6px;font-weight:normal;align-items:center"><input type="checkbox" class="cf" value="${k}" ${f.includes(k)?'checked':''} style="width:auto">${k}</label>`).join(''); }
    case 'buttons': return `<label><span class="lbl">Message</span><textarea data-k="body" rows="2">${esc(c.body)}</textarea></label><div class="lbl mt10">Buttons (max 3) — wire each on the canvas</div><div id="rows">${(c.buttons||[]).map((b,i)=>btnRow(b,i)).join('')}</div><button type="button" class="btn-link" onclick="addRow('buttons')">+ button</button>`;
    case 'ai_branch': return `<label><span class="lbl">Prompt (optional, asked before classifying)</span><textarea data-k="prompt" rows="2">${esc(c.prompt)}</textarea></label><div class="lbl mt10">Branches — wire each (and “else”) on the canvas</div><div id="rows">${(c.branches||[]).map((b,i)=>brRow(b,i)).join('')}</div><button type="button" class="btn-link" onclick="addRow('ai_branch')">+ branch</button>`;
  }
  return '';
}
function btnRow(b,i){ return `<div class="rowitem" style="display:grid;grid-template-columns:1fr .5fr auto;gap:6px;margin-bottom:6px"><input class="ri-title" placeholder="Label" maxlength="20" value="${esc(b.title)}"><input class="ri-points" type="number" placeholder="pts" value="${b.points||0}"><button type="button" class="icon-btn" onclick="this.closest('.rowitem').remove();syncRows()">✕</button></div>`; }
function brRow(b,i){ return `<div class="rowitem" style="display:grid;grid-template-columns:1fr 1.3fr .5fr auto;gap:6px;margin-bottom:6px"><input class="ri-label" placeholder="Label" value="${esc(b.label)}"><input class="ri-desc" placeholder="When reply means…" value="${esc(b.description)}"><input class="ri-points" type="number" placeholder="pts" value="${b.points||0}"><button type="button" class="icon-btn" onclick="this.closest('.rowitem').remove();syncRows()">✕</button></div>`; }
function addRow(kind){ const wrap=document.getElementById('rows'); if(!wrap) return; const max=kind==='buttons'?3:5; if(wrap.children.length>=max) return; wrap.insertAdjacentHTML('beforeend', kind==='buttons'?btnRow({},wrap.children.length):brRow({},wrap.children.length)); syncRows(); }
function syncRows(){ // rebuild config buttons/branches from panel, re-render node (ports may change)
  if(!current) return; const wrap=document.getElementById('rows'); if(!wrap) return;
  if(current.type==='buttons'){ current.config.buttons=[...wrap.children].map(r=>({title:r.querySelector('.ri-title').value,points:+r.querySelector('.ri-points').value})); }
  else if(current.type==='ai_branch'){ current.config.branches=[...wrap.children].map(r=>({label:r.querySelector('.ri-label').value,description:r.querySelector('.ri-desc').value,points:+r.querySelector('.ri-points').value})); }
  renderKeepPanel();
}
/* ── template node: full parameter fields (header media/text/location, body vars, buttons) ── */
function onTplChange(v){
  if(!current) return;
  current.config.template_id = +v;
  // Different template = different parameter shape; clear stale values.
  current.config.header_media=''; current.config.header_vars={}; current.config.header_loc={};
  current.config.vars={}; current.config.buttons={};
  renderTplFields(); renderKeepPanel(false);
}
function tplVarRow(i, prefix, label, cur){
  const c = cur||{};
  const src = c.source||'static';
  return `<div style="margin-bottom:10px" data-vrow="${prefix}" data-i="${i}">
    <div class="lbl" style="margin-bottom:4px">${label} {{${i}}}</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
      <select class="tv-src"><option value="static" ${src==='static'?'selected':''}>Fixed text</option><option value="name" ${src==='name'?'selected':''}>Contact name</option></select>
      <input class="tv-val" placeholder="Value" value="${esc(c.value||'')}" ${src==='name'?'style="display:none"':''}>
      <input class="tv-fb" placeholder="Fallback if empty" value="${esc(c.fallback||'')}" ${src==='name'?'':'style="display:none"'}>
    </div></div>`;
}
function renderTplFields(){
  const box=document.getElementById('tpl-fields'); if(!box||!current) return;
  const c=current.config, spec=TPL_SPECS[c.template_id];
  if(!spec){ box.innerHTML=''; return; }
  const fmt=(spec.header&&spec.header.format||'').toUpperCase();
  const dyn=(spec.buttons||[]).filter(b=>b.dynamic);
  let h='';
  if(['IMAGE','VIDEO','DOCUMENT'].includes(fmt)){
    const kind=fmt.toLowerCase();
    const cur=(c.header_media!=null&&c.header_media!=='')?c.header_media:(spec.header_example||'');
    h+=`<div class="lbl mt10">Header ${kind}</div>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <input id="tf-media" value="${esc(cur)}" placeholder="https://… or Upload" style="flex:1;min-width:150px">
        <input type="file" id="tf-file" style="display:none" accept="${kind==='image'?'.jpg,.jpeg,.png,.webp':kind==='video'?'.mp4,.3gp':'.pdf'}">
        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('tf-file').click()">Upload</button>
      </div><div class="hint" id="tf-status">Uploaded once to WhatsApp, then reused for every send.</div>`;
  } else if(fmt==='LOCATION'){
    const L=c.header_loc||{};
    h+=`<div class="lbl mt10">Header location</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
        <input id="tf-lat" placeholder="Latitude" value="${esc(L.lat||'')}">
        <input id="tf-lng" placeholder="Longitude" value="${esc(L.lng||'')}">
        <input id="tf-locname" placeholder="Name" value="${esc(L.name||'')}">
        <input id="tf-address" placeholder="Address" value="${esc(L.address||'')}">
      </div>`;
  } else if(fmt==='TEXT' && (spec.header.text_vars||0)>0){
    h+=`<div class="lbl mt10">Header variables</div>`;
    for(let i=1;i<=spec.header.text_vars;i++) h+=tplVarRow(i,'h','Header',(c.header_vars||{})[i]);
  }
  if((spec.body_vars||0)>0){
    h+=`<div class="lbl mt10">Message variables</div>`;
    for(let i=1;i<=spec.body_vars;i++) h+=tplVarRow(i,'b','Variable',(c.vars||{})[i]);
  }
  if(dyn.length){
    h+=`<div class="lbl mt10">Dynamic buttons</div>`;
    dyn.forEach(b=>{
      const isCode=b.type==='COPY_CODE';
      h+=`<label style="display:block;margin-bottom:8px"><span class="lbl">${esc(b.text||('Button '+(b.index+1)))} — ${isCode?'coupon code':'URL suffix'}</span>
        <input class="tv-btn" data-i="${b.index}" value="${esc((c.buttons||{})[b.index]||'')}" placeholder="${isCode?'EID20':'summer-sale'}"></label>`;
    });
  }
  box.innerHTML=h;
  bindTplFields();
}
function syncTplFields(){
  if(!current) return; const c=current.config;
  const m=document.getElementById('tf-media'); if(m) c.header_media=m.value.trim();
  const lat=document.getElementById('tf-lat');
  if(lat) c.header_loc={lat:lat.value.trim(),lng:(document.getElementById('tf-lng')||{}).value?.trim()||'',
    name:(document.getElementById('tf-locname')||{}).value?.trim()||'',address:(document.getElementById('tf-address')||{}).value?.trim()||''};
  const hv={}, bv={};
  document.querySelectorAll('#tpl-fields [data-vrow]').forEach(row=>{
    const i=row.dataset.i, spec={source:row.querySelector('.tv-src').value,
      value:row.querySelector('.tv-val').value, fallback:row.querySelector('.tv-fb').value};
    (row.dataset.vrow==='h'?hv:bv)[i]=spec;
  });
  c.header_vars=hv; c.vars=bv;
  const btns={};
  document.querySelectorAll('#tpl-fields .tv-btn').forEach(el=>{ btns[el.dataset.i]=el.value.trim(); });
  c.buttons=btns;
}
function bindTplFields(){
  document.querySelectorAll('#tpl-fields input, #tpl-fields select').forEach(el=>{
    el.addEventListener('input',syncTplFields);
    el.addEventListener('change',()=>{
      syncTplFields();
      // Source switch toggles Value vs Fallback — re-render that row set.
      if(el.classList.contains('tv-src')) renderTplFields();
    });
  });
  const f=document.getElementById('tf-file');
  if(f) f.addEventListener('change', async ()=>{
    if(!f.files||!f.files[0]) return;
    const st=document.getElementById('tf-status'); st.textContent='Uploading…';
    const fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('media',f.files[0]);
    try{
      const r=await fetch('upload_media.php',{method:'POST',body:fd}); const d=await r.json();
      if(d.ok){ document.getElementById('tf-media').value=d.url; syncTplFields(); st.textContent='✓ Uploaded'; }
      else st.textContent='⚠ '+(d.error||'Upload failed');
    }catch(e){ st.textContent='⚠ Upload failed'; }
  });
}

function bindCfg(){
  if(current && current.type==='template') renderTplFields();
  document.querySelectorAll('#cfg-body [data-k]').forEach(el=>{
    el.addEventListener('input',()=>{ let v=el.value; if(el.type==='number') v=+v; current.config[el.dataset.k]=v; renderKeepPanel(false); });
  });
  document.querySelectorAll('#cfg-body .cf').forEach(cb=>cb.addEventListener('change',()=>{ current.config.fields=[...document.querySelectorAll('#cfg-body .cf:checked')].map(x=>x.value); }));
  const wv=document.getElementById('wv'),wu=document.getElementById('wu');
  if(wv&&wu){ const upd=()=>{ current.config.seconds=(+wv.value)*(wu.value==='d'?86400:wu.value==='h'?3600:60); }; wv.addEventListener('input',upd); wu.addEventListener('change',upd); }
  document.querySelectorAll('#cfg-body .ri-title,#cfg-body .ri-label,#cfg-body .ri-desc,#cfg-body .ri-points').forEach(el=>el.addEventListener('input',syncRows));
}
function renderKeepPanel(rebind=true){ const id=current?current.id:null; render(); if(id){ // update just the summary text without reopening
    // keep panel open; refresh node summary already done by render()
  } }

/* ── interactions: drag + connect + pan ── */
let drag=null, conn=null, pan=null, spaceDown=false;
canvas.addEventListener('mousedown',e=>{
  const port=e.target.closest('.port-out');
  if(port){ e.preventDefault(); const nidv=port.dataset.node, pv=port.dataset.port; conn={node:nidv,port:pv}; return; }
  const head=e.target.closest('.node-head');
  if(e.target.classList.contains('del')){ const el=e.target.closest('.node'); const id=el.dataset.id; if(id!=='start'){ if(current&&current.id===id)closeCfg(); delete nodes[id]; [start,...Object.values(nodes)].forEach(n=>Object.keys(n.outputs).forEach(k=>{if(n.outputs[k]===id)n.outputs[k]=null;})); render(); } e.stopPropagation(); return; }
  if(head){ const el=head.closest('.node'); const id=el.dataset.id; const n=id==='start'?start:nodes[id];
    const p=toCanvas(e.clientX,e.clientY); drag={n,dx:p.x-n.x,dy:p.y-n.y}; }
});
// Middle-drag, or space+drag, pans the board.
wrap.addEventListener('mousedown',e=>{
  if(e.button===1 || (spaceDown && !e.target.closest('.node'))){
    e.preventDefault();
    pan={x:e.clientX,y:e.clientY,sl:wrap.scrollLeft,st:wrap.scrollTop};
    wrap.classList.add('panning');
  }
});
document.addEventListener('keydown',e=>{ if(e.code==='Space' && !/INPUT|TEXTAREA|SELECT/.test((e.target.tagName||''))) spaceDown=true; });
document.addEventListener('keyup',  e=>{ if(e.code==='Space') spaceDown=false; });
document.addEventListener('mousemove',e=>{
  if(pan){ wrap.scrollLeft=pan.sl-(e.clientX-pan.x); wrap.scrollTop=pan.st-(e.clientY-pan.y); return; }
  if(drag){
    const p=toCanvas(e.clientX,e.clientY);
    drag.n.x=Math.max(0,p.x-drag.dx); drag.n.y=Math.max(0,p.y-drag.dy);
    const el=canvas.querySelector(`.node[data-id="${drag.n.id}"]`);
    if(el){el.style.left=drag.n.x+'px';el.style.top=drag.n.y+'px';}
    redraw();
  }
  else if(conn){ const a=portCenter(conn.node,conn.port,'out'); redraw({a:a||{x:0,y:0},b:toCanvas(e.clientX,e.clientY)}); }
});
document.addEventListener('mouseup',e=>{
  if(pan){ pan=null; wrap.classList.remove('panning'); }
  if(conn){ const tnode=e.target.closest('.node'); const src=conn.node==='start'?start:nodes[conn.node];
    if(tnode && tnode.dataset.id!==conn.node && tnode.dataset.id!=='start'){ src.outputs[conn.port]=tnode.dataset.id; }
    else if(!tnode){ src.outputs[conn.port]=null; }
    conn=null; redraw();
  }
  if(drag){ drag=null; }
});
// Ctrl/⌘ + wheel zooms at the cursor; plain wheel keeps scrolling the board.
wrap.addEventListener('wheel',e=>{
  if(!e.ctrlKey && !e.metaKey) return;
  e.preventDefault();
  const r=wrap.getBoundingClientRect();
  setZoom(Z + (e.deltaY<0?0.1:-0.1), {x:e.clientX-r.left, y:e.clientY-r.top});
},{passive:false});
canvas.addEventListener('click',e=>{ if(e.target.closest('.port'))return; if(e.target.closest('.del'))return; const el=e.target.closest('.node'); if(el&&!e.target.closest('.node-head')) openCfg(el.dataset.id); });

/* ── trigger form ── */
function onTrig(){ const k=document.getElementById('trigger_type').value==='keyword'; document.getElementById('kw-wrap').style.display=k?'':'none'; document.getElementById('mt-wrap').style.display=k?'':'none'; const s=canvas.querySelector('.node.start .node-body'); if(s)s.innerHTML=summary(start); }

/* ── serialize ── */
document.getElementById('flow-form').addEventListener('submit',()=>{
  const out={start:{x:start.x,y:start.y,next:start.outputs.next||null}, nodes:Object.values(nodes).map(n=>({id:n.id,type:n.type,x:n.x,y:n.y,config:n.config,outputs:n.outputs}))};
  document.getElementById('flow_json').value=JSON.stringify(out);
});

/* ── init ── */
INIT_NODES.forEach(n=>{ nodes[n.id]={id:n.id,type:n.type,x:n.x,y:n.y,config:n.config||{},outputs:n.outputs||{}}; const m=n.id.match(/^s(\d+)$/); });
onTrig(); render();
// Open with the whole flow visible instead of scrolled off the right edge.
zoomFit();
window.addEventListener('resize',()=>redraw());
</script>

<?php layout_footer(); ?>
