<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/../includes/ai.php';
require __DIR__ . '/../includes/notify.php';
require __DIR__ . '/../includes/automation.php';

$cid = (int) $CLIENT['id'];
$id  = (int) ($_GET['id'] ?? 0);
$flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='qualifier'", [$id, $cid]);
if (!$flow) { http_response_code(404); exit('Qualifier not found.'); }

$err = '';
// A personal number has no approved templates, so the cold first message is written here.
$PERSONAL = channel_is_personal($CLIENT);

/* ── Send now: import new leads + send outreach + retry stuck ones ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_now') {
    verify_csrf();
    $r = automation_send_now($CLIENT, $flow);
    if ($r['error'] !== '') {
        flash('Send failed: ' . $r['error'], 'error');
    } else {
        flash("Sent now: {$r['imported']} new · {$r['retried']} retried · {$r['skipped']} already in this qualifier · {$r['invalid']} invalid of {$r['rows']} rows.");
    }
    redirect('qualifier_edit.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verify_csrf();
    $name       = trim((string) ($_POST['name'] ?? $flow['name']));
    $templateId = (int) ($_POST['outreach_template_id'] ?? 0);
    $criterion  = trim((string) ($_POST['criterion'] ?? ''));
    $maxPoints  = max(1, (int) ($_POST['max_points'] ?? 50));
    $hotMin     = max(0, (int) ($_POST['hot_min'] ?? 60));
    $warmMin    = max(0, (int) ($_POST['warm_min'] ?? 30));
    $questions  = array_values(array_filter(array_map('trim', (array) ($_POST['question'] ?? []))));

    // AI conversation (knowledge base): pasted text + optional uploaded .txt/.docx.
    $knowledge = trim((string) ($_POST['knowledge'] ?? ''));
    if (!empty($_FILES['kb_file']['tmp_name']) && (int) ($_FILES['kb_file']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $extracted = extract_text_from_upload((string) $_FILES['kb_file']['tmp_name'], (string) $_FILES['kb_file']['name']);
        if ($extracted !== '') $knowledge = trim($knowledge . "\n\n" . $extracted);
    }
    $chatIntro        = trim((string) ($_POST['chat_intro'] ?? ''));
    $chatPersona      = trim((string) ($_POST['chat_persona'] ?? ''));
    $chatInstructions = trim((string) ($_POST['chat_instructions'] ?? ''));
    $chatMaxTurns     = max(1, min(20, (int) ($_POST['chat_max_turns'] ?? 6)));
    $chatGoals    = array_values(array_filter(array_map('trim', (array) ($_POST['chat_goal'] ?? []))));
    // Capture items: each label → a slug key (unique); Arabic labels fall back to field_N.
    $chatCaptures = []; $usedKeys = [];
    foreach (array_values(array_filter(array_map('trim', (array) ($_POST['capture'] ?? [])))) as $ci => $lab) {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($lab)), '_');
        if ($key === '') $key = 'field_' . ($ci + 1);
        $base = $key; $k = 2; while (in_array($key, $usedKeys, true)) { $key = $base . '_' . $k; $k++; }
        $usedKeys[] = $key;
        $chatCaptures[] = ['key' => $key, 'label' => $lab];
    }

    $sourceConfig = json_encode([
        'csv_url'            => trim((string) ($_POST['csv_url'] ?? '')),
        'fetch_interval_min' => max(1, (int) ($_POST['fetch_interval_min'] ?? 15)),
        'column_map'         => ['phone' => trim((string) ($_POST['map_phone'] ?? '')), 'name' => trim((string) ($_POST['map_name'] ?? ''))],
        'country'            => preg_replace('/\D+/', '', (string) ($_POST['import_country'] ?? '')),
        'last_fetched_at'    => (json_decode((string) $flow['source_config'], true)['last_fetched_at'] ?? null),
    ], JSON_UNESCAPED_UNICODE);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run("DELETE FROM flow_steps WHERE flow_id=?", [$id]);

        // Compile a linear flow: template → questions → ai_chat → ai_score → collect → End.
        $chain = [];
        if ($PERSONAL) {
            // Same 'template' step type so the outreach sender still finds it, but carrying
            // its own text instead of a template id.
            $outText  = trim((string) ($_POST['outreach_text'] ?? ''));
            $outMedia = trim((string) ($_POST['outreach_media'] ?? ''));
            if ($outText !== '' || $outMedia !== '') {
                $chain[] = ['type' => 'template', 'config' => ['text' => $outText, 'media' => $outMedia]];
            }
        } elseif ($templateId) {
            // Collect ALL template parameters (header media/location/text, body vars, dynamic buttons)
            // so any approved template sends correctly (avoids Meta #132012).
            $tRow  = db_row("SELECT components FROM templates WHERE id=? AND client_id=?", [$templateId, $cid]);
            $tspec = function_exists('wa_template_spec')
                   ? wa_template_spec(json_decode((string) ($tRow['components'] ?? ''), true) ?: [])
                   : ['header' => ['format' => '', 'text_vars' => 0], 'body_vars' => 0, 'buttons' => []];
            $tcfg = ['template_id' => $templateId, 'lang' => 'en'];
            $rv = function ($pfx, $i) {
                return ['source' => (($_POST[$pfx . '_source'][$i] ?? '') === 'name' ? 'name' : 'static'),
                        'value' => trim((string) ($_POST[$pfx . '_value'][$i] ?? '')),
                        'fallback' => trim((string) ($_POST[$pfx . '_fallback'][$i] ?? ''))];
            };
            $hf = strtoupper((string) $tspec['header']['format']);
            if (in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                $tcfg['header_media'] = trim((string) ($_POST['header_media'] ?? ''));
            } elseif ($hf === 'LOCATION') {
                $tcfg['header_loc'] = ['lat' => trim((string) ($_POST['header_lat'] ?? '')), 'lng' => trim((string) ($_POST['header_lng'] ?? '')),
                                       'name' => trim((string) ($_POST['header_locname'] ?? '')), 'address' => trim((string) ($_POST['header_addr'] ?? ''))];
            } elseif ($hf === 'TEXT' && (int) $tspec['header']['text_vars'] > 0) {
                $hv = []; for ($i = 1; $i <= (int) $tspec['header']['text_vars']; $i++) $hv[(string) $i] = $rv('hvar', $i);
                $tcfg['header_vars'] = $hv;
            }
            $bv = []; for ($i = 1; $i <= (int) $tspec['body_vars']; $i++) $bv[(string) $i] = $rv('tvar', $i);
            $tcfg['vars'] = $bv;
            $btn = [];
            foreach ((array) $tspec['buttons'] as $b) {
                if (empty($b['dynamic'])) continue;
                $btn[(string) $b['index']] = trim((string) ($_POST['btn_param'][$b['index']] ?? ''));
            }
            if ($btn) $tcfg['buttons'] = $btn;
            $chain[] = ['type' => 'template', 'config' => $tcfg];
        }
        // The AI conversation drives qualification: it answers the lead's reply directly and
        // asks the scripted questions naturally (skipping any already answered). Questions live
        // inside the ai_chat node — no rigid one-by-one question sends.
        if ($knowledge !== '' || $questions || $chatGoals || $chatCaptures) $chain[] = ['type' => 'ai_chat', 'config' => [
            'knowledge' => $knowledge, 'intro' => $chatIntro, 'persona' => $chatPersona,
            'instructions' => $chatInstructions, 'questions' => $questions,
            'goals' => $chatGoals, 'captures' => $chatCaptures, 'max_turns' => $chatMaxTurns,
        ]];
        if ($criterion !== '') $chain[] = ['type' => 'ai_score', 'config' => ['criterion' => $criterion, 'max_points' => $maxPoints]];
        $chain[] = ['type' => 'collect', 'config' => ['sheet_name' => 'Leads', 'fields' => ['phone', 'name', 'last_reply', 'score', 'grade', 'tags']]];

        $ids = [];
        foreach ($chain as $pos => $st) {
            $ids[$pos] = db_insert("INSERT INTO flow_steps (flow_id,client_id,sort,type,config,created_at) VALUES (?,?,?,?,?,NOW())",
                [$id, $cid, $pos, $st['type'], json_encode($st['config'], JSON_UNESCAPED_UNICODE)]);
        }
        foreach ($chain as $pos => $st) {
            db_run("UPDATE flow_steps SET next_step_id=? WHERE id=?", [$ids[$pos + 1] ?? null, $ids[$pos]]);
        }

        db_run("UPDATE flows SET name=?, source_config=?, hot_min=?, warm_min=?, first_step_id=?, updated_at=NOW() WHERE id=?",
            [$name, $sourceConfig, $hotMin, $warmMin, $ids[0] ?? null, $id]);
        $pdo->commit();
        flash('Qualifier saved.');
        redirect('qualifier_edit.php?id=' . $id);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('qualifier save: ' . $ex->getMessage());
        $err = 'Could not save. Please try again.';
    }
    $flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=?", [$id, $cid]);
}

/* reconstruct form from compiled steps */
$templates = db_all("SELECT id, wa_name, language, variable_count, components FROM templates WHERE client_id=? AND status='APPROVED' ORDER BY wa_name", [$cid]);
// Full parameter spec per template (header/body/buttons) for the adaptive mapping UI.
$tplSpec = [];
foreach ($templates as $t) {
    $comp = json_decode((string) $t['components'], true) ?: [];
    $sp = function_exists('wa_template_spec')
        ? wa_template_spec($comp)
        : ['header' => ['format' => '', 'text_vars' => 0], 'body_vars' => (int) ($t['variable_count'] ?? 0), 'buttons' => []];
    $sp['header_example'] = function_exists('wa_template_header_example') ? wa_template_header_example($comp) : '';
    $tplSpec[(int) $t['id']] = $sp;
}
$sc = json_decode((string) $flow['source_config'], true) ?: [];
$steps = db_all("SELECT * FROM flow_steps WHERE flow_id=? ORDER BY sort, id", [$id]);
$outreachId = 0; $outreachCfg = []; $questions = []; $criterion = ''; $maxPoints = 50;
$outreachText = ''; $outreachMedia = '';
$knowledge = ''; $chatIntro = ''; $chatPersona = ''; $chatInstructions = ''; $chatMaxTurns = 6;
$chatGoals = []; $chatCaptures = [];
foreach ($steps as $s) {
    $c = json_decode((string) $s['config'], true) ?: [];
    if ($s['type'] === 'template') {
        $outreachId    = (int) ($c['template_id'] ?? 0);
        $outreachCfg   = $c;
        $outreachText  = (string) ($c['text'] ?? '');
        $outreachMedia = (string) ($c['media'] ?? '');
    }
    elseif ($s['type'] === 'question') $questions[] = (string) ($c['body'] ?? '');
    elseif ($s['type'] === 'ai_score') { $criterion = (string) ($c['criterion'] ?? ''); $maxPoints = (int) ($c['max_points'] ?? 50); }
    elseif ($s['type'] === 'ai_chat') {
        $knowledge        = (string) ($c['knowledge'] ?? '');
        $chatIntro        = (string) ($c['intro'] ?? '');
        $chatPersona      = (string) ($c['persona'] ?? '');
        $chatInstructions = (string) ($c['instructions'] ?? '');
        $chatMaxTurns     = (int) ($c['max_turns'] ?? 6);
        $chatGoals        = (array) ($c['goals'] ?? []);
        $chatCaptures     = (array) ($c['captures'] ?? []);
        if (!empty($c['questions'])) $questions = (array) $c['questions']; // questions now live in the ai_chat node
    }
}
if (!$questions) $questions = [''];
if (!$chatGoals) $chatGoals = [''];
if (!$chatCaptures) $chatCaptures = [['key' => '', 'label' => '']];

client_header('Qualifier · ' . $flow['name'], 'qualifier', $CLIENT);
?>
<div class="page-head"><h1><?= e((string) $flow['name']) ?></h1>
  <div class="page-actions">
    <?= guide_button('qualifier') ?>
    <a class="btn btn-ghost btn-sm" href="qualifiers.php">← All</a>
    <form method="post" style="display:inline" onsubmit="return confirm('Import new leads from the sheet and send them the outreach now?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="send_now">
      <button class="btn btn-primary btn-sm">&#9658; Send now</button>
    </form>
    <a class="btn btn-ghost btn-sm" href="leads.php?flow=<?= $id ?>">Leads →</a>
  </div>
</div>
<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
<?php if (!$PERSONAL && !$templates): ?><div class="alert info">You have no <strong>approved</strong> templates yet — sync them in <a href="templates.php">Templates</a> before the qualifier can send cold outreach.</div><?php endif; ?>

<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">

  <div class="card">
    <h2>1 · Lead source (Google Sheet)</h2>
    <div class="alert info" style="font-size:12px">In Google Sheets: File → Share → <strong>Publish to web</strong> → choose the sheet → <strong>CSV</strong> → copy the link. The sheet must contain a phone column (opted-in contacts only).</div>
    <div class="field"><span class="lbl">Name</span><input type="text" name="name" value="<?= e((string) $flow['name']) ?>" required></div>
    <div class="field"><span class="lbl">Published CSV URL</span><input type="text" name="csv_url" value="<?= e((string) ($sc['csv_url'] ?? '')) ?>" placeholder="https://docs.google.com/spreadsheets/.../pub?output=csv"></div>
    <div class="grid3">
      <div class="field"><span class="lbl">Phone column</span><input type="text" name="map_phone" value="<?= e((string) ($sc['column_map']['phone'] ?? '')) ?>" placeholder="phone"></div>
      <div class="field"><span class="lbl">Name column</span><input type="text" name="map_name" value="<?= e((string) ($sc['column_map']['name'] ?? '')) ?>" placeholder="name"></div>
      <div class="field"><span class="lbl">Import every (min)</span><input type="number" name="fetch_interval_min" min="1" value="<?= (int) ($sc['fetch_interval_min'] ?? 15) ?>"></div>
    </div>
    <div class="field"><span class="lbl">Country <span class="text-muted">(local numbers get this code; numbers that already have a code are kept)</span></span>
      <?= function_exists('country_picker_html')
            ? country_picker_html('import_country', (string) ($sc['country'] ?? ''))
            : '<select name="import_country"><option value="">Auto-detect</option><option value="20">Egypt (+20)</option><option value="966">Saudi Arabia (+966)</option><option value="971">UAE (+971)</option></select>' ?>
    </div>
  </div>

  <?php if ($PERSONAL): ?>
  <div class="card">
    <h2>2 · Outreach message (cold first message)</h2>
    <div class="field"><span class="lbl">Message</span>
      <textarea name="outreach_text" rows="5" placeholder="أهلاً {{name}} 👋&#10;معاك أحمد من …"><?= e($outreachText) ?></textarea>
      <div class="hint">Personalise with <code>{{name}}</code> or <code>{{phone}}</code>. Sent to each new lead; after they reply, the AI takes over.</div>
    </div>
    <div class="field"><span class="lbl">Image <span class="text-muted">(optional)</span></span>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="text" id="om-url" name="outreach_media" value="<?= e($outreachMedia) ?>" placeholder="https://… or click Upload" style="flex:1;min-width:220px">
        <input type="file" id="om-file" accept=".jpg,.jpeg,.png,.webp" style="display:none" onchange="uploadOutreachMedia(this)">
        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('om-file').click()">&#8593; Upload</button>
        <span id="om-status" class="text-muted" style="font-size:12px"></span>
      </div>
      <div class="hint">Sent as a photo with the message above as its caption.</div>
    </div>
    <div class="alert info" style="font-size:12.5px">
      Sending from <strong>your own number</strong> — no template approval and no 24-hour window.
      Outreach goes out <strong><?= (int) ($CLIENT['slot_size'] ?: 15) ?> at a time</strong>, then pauses
      <strong><?= round((int) ($CLIENT['slot_pause_sec'] ?: 180) / 60, 1) ?> minutes</strong>, sharing one batch with your campaigns and automations.
    </div>
  </div>
  <?php else: ?>
  <div class="card">
    <h2>2 · Outreach template (cold first message)</h2>
    <div class="field"><span class="lbl">Approved template</span>
      <select name="outreach_template_id" id="tpl-select" onchange="renderTvars()">
        <option value="0" data-vc="0">— choose —</option>
        <?php foreach ($templates as $t): ?>
          <option value="<?= (int) $t['id'] ?>" data-vc="<?= (int) ($t['variable_count'] ?? 0) ?>" <?= $outreachId === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['wa_name'] . ' (' . $t['language'] . ')') ?><?= (int) ($t['variable_count'] ?? 0) > 0 ? ' · ' . (int) $t['variable_count'] . ' var' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <div class="hint">Sent to each new lead. After they reply, the AI asks your questions inside the 24h window.</div>
    </div>
    <div id="tvars"></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="row-between" style="margin-bottom:12px"><h2 style="border:0;padding:0;margin:0">3 · Qualifying questions</h2>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addQ()">+ Question</button></div>
    <div id="qs">
      <?php foreach ($questions as $i => $q): ?>
        <div class="qrow" style="display:flex;gap:8px;margin-bottom:8px">
          <input type="text" name="question[]" value="<?= e($q) ?>" placeholder="e.g. What's your budget?">
          <button type="button" class="icon-btn" onclick="this.closest('.qrow').remove()">&#x2715;</button>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="hint">The AI asks these naturally within the conversation, answers the lead's own questions, and skips any question the lead already answered.</div>
  </div>

  <div class="card">
    <h2>4 · AI conversation <span class="text-muted" style="font-weight:400;font-size:13px">(human-like chatbot — optional)</span></h2>
    <div class="alert info" style="font-size:12px">After the questions above, the AI keeps chatting like a human and answers the lead's questions <strong>using only the knowledge you give here</strong>. Leave the knowledge empty to skip this stage. It never invents prices or promises — anything not covered, it says a colleague will follow up.</div>
    <div class="field"><span class="lbl">Knowledge base — everything the AI may answer from (project details, prices, FAQs, offers…)</span>
      <textarea name="knowledge" rows="8" placeholder="e.g. Porto Garden is a compound in New Cairo. Prices start at 2.5M EGP. Delivery Q4 2027. 10% down payment, 8-year installments. Amenities: pool, gym, security…"><?= e($knowledge) ?></textarea>
    </div>
    <div class="field"><span class="lbl">Or upload a document <span class="text-muted">(.txt or .docx — its text is added to the knowledge above)</span></span>
      <input type="file" name="kb_file" accept=".txt,.docx">
    </div>
    <div class="field"><span class="lbl">Instructions <span class="text-muted">(language, dialect, tone, wording rules — followed strictly)</span></span>
      <textarea name="chat_instructions" rows="3" placeholder="مثال: رد دايماً باللهجة المصرية العامية، بلاش فصحى. استخدم كلمة 'وحدة' مش 'شقة'. متكتبش إنجليزي."><?= e($chatInstructions) ?></textarea>
    </div>
    <div class="grid3">
      <div class="field"><span class="lbl">Intro message <span class="text-muted">(after the questions)</span></span><input type="text" name="chat_intro" value="<?= e($chatIntro) ?>" placeholder="تحت أمرك، اسأل عن أي تفاصيل 🙂"></div>
      <div class="field"><span class="lbl">Who is the AI? <span class="text-muted">(persona)</span></span><input type="text" name="chat_persona" value="<?= e($chatPersona) ?>" placeholder="e.g. a sales rep at Amer Group"></div>
      <div class="field"><span class="lbl">Max AI replies per lead</span><input type="number" name="chat_max_turns" value="<?= (int) $chatMaxTurns ?>" min="1" max="20"></div>
    </div>
    <div class="field"><span class="lbl">Goals <span class="text-muted">(the AI pursues all of these while answering the lead)</span></span>
      <div id="goals">
        <?php foreach ($chatGoals as $g): ?>
          <div class="grow" style="display:flex;gap:8px;margin-bottom:8px">
            <input type="text" name="chat_goal[]" value="<?= e((string) $g) ?>" placeholder="e.g. book a site visit / get their budget">
            <button type="button" class="icon-btn" onclick="this.closest('.grow').remove()">&#x2715;</button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addGoal()">+ Goal</button>
    </div>
    <div class="field"><span class="lbl">Capture from chat <span class="text-muted">(details the AI pulls out — e.g. mobile, final request)</span></span>
      <div id="caps">
        <?php foreach ($chatCaptures as $cap): ?>
          <div class="crow" style="display:flex;gap:8px;margin-bottom:8px">
            <input type="text" name="capture[]" value="<?= e((string) ($cap['label'] ?? '')) ?>" placeholder="e.g. Mobile number">
            <button type="button" class="icon-btn" onclick="this.closest('.crow').remove()">&#x2715;</button>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="hint">Short English labels give the cleanest report columns (mobile, budget, final_request). Arabic labels work too.</div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addCap()">+ Capture</button>
    </div>
  </div>

  <div class="card">
    <h2>5 · AI quality score</h2>
    <div class="field"><span class="lbl">What makes a good lead? (the AI scores the whole conversation against this)</span>
      <textarea name="criterion" rows="3" placeholder="e.g. Ready to buy within 3 months, budget over 2M EGP, looking in New Cairo."><?= e($criterion) ?></textarea>
    </div>
    <div class="grid3">
      <div class="field"><span class="lbl">Max AI points</span><input type="number" name="max_points" value="<?= (int) $maxPoints ?>" min="1"></div>
      <div class="field"><span class="lbl">Hot score ≥</span><input type="number" name="hot_min" value="<?= (int) $flow['hot_min'] ?>"></div>
      <div class="field"><span class="lbl">Warm score ≥</span><input type="number" name="warm_min" value="<?= (int) $flow['warm_min'] ?>"></div>
    </div>
    <div class="hint">Leads scoring ≥ hot are marked <span class="pill red">hot</span>, ≥ warm are <span class="pill gold">warm</span>, else <span class="pill gray">cold</span>. Results appear in <a href="leads.php?flow=<?= $id ?>">Leads</a>.</div>
  </div>

  <div class="card"><button type="submit" class="btn btn-primary">Save Qualifier</button>
    <span class="text-muted" style="font-size:12px;margin-left:10px">Activate it from the <a href="qualifiers.php">Lead Qualifier</a> list when ready.</span></div>
</form>

<script>
const TPLSPEC = <?= json_encode($tplSpec, JSON_UNESCAPED_UNICODE) ?>;
const OUTCFG  = <?= json_encode($outreachCfg ?: (object)[], JSON_UNESCAPED_UNICODE) ?>;
const CSRF    = <?= json_encode(csrf_token()) ?>;
async function uploadHeaderMedia(input){
  if(!input.files || !input.files[0]) return;
  const st=document.getElementById('hm-status'); st.textContent='Uploading…';
  const fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('media',input.files[0]);
  try{
    const r=await fetch('upload_media.php',{method:'POST',body:fd}); const d=await r.json();
    if(d.ok){ document.getElementById('hm-url').value=d.url; st.textContent='✓ uploaded'; st.style.color='#25623c'; }
    else { st.textContent='✕ '+(d.error||'upload failed'); st.style.color='#c0392b'; }
  }catch(e){ st.textContent='✕ network error'; st.style.color='#c0392b'; }
  input.value='';
}
const eatt = s => String(s==null?'':s).replace(/"/g,'&quot;');
function fld(lbl, inner){ return '<div class="field"><span class="lbl">'+lbl+'</span>'+inner+'</div>'; }
function inp(name, val, ph){ return '<input type="text" name="'+name+'" value="'+eatt(val)+'" placeholder="'+(ph||'')+'">'; }
function varRow(pfx, i, saved){
  saved=saved||{}; const isName=(saved.source==='name');
  return '<div class="grid3" style="align-items:end;margin-bottom:8px">'
    +'<div class="field"><span class="lbl">{{'+i+'}}</span><select name="'+pfx+'_source['+i+']" onchange="this.closest(\'.grid3\').querySelector(\'.tv-val\').style.display=this.value===\'name\'?\'none\':\'block\'">'
    +'<option value="name"'+(isName?' selected':'')+'>Contact name</option>'
    +'<option value="static"'+(!isName?' selected':'')+'>Fixed text</option></select></div>'
    +'<div class="field tv-val" style="display:'+(isName?'none':'block')+'"><span class="lbl">Value</span>'+inp(pfx+'_value['+i+']', saved.value, 'e.g. 20% off')+'</div>'
    +'<div class="field"><span class="lbl">Fallback</span>'+inp(pfx+'_fallback['+i+']', saved.fallback, 'e.g. there')+'</div></div>';
}
async function uploadOutreachMedia(input){
  if(!input.files || !input.files[0]) return;
  const st=document.getElementById('om-status'); st.textContent='Uploading…';
  const fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('media',input.files[0]);
  try{
    const r=await fetch('upload_media.php',{method:'POST',body:fd}); const d=await r.json();
    if(d.ok){ document.getElementById('om-url').value=d.url; st.textContent='✓ uploaded'; st.style.color='#25623c'; }
    else { st.textContent='✕ '+(d.error||'upload failed'); st.style.color='#c0392b'; }
  }catch(e){ st.textContent='✕ network error'; st.style.color='#c0392b'; }
  input.value='';
}
function renderTvars(){
  const sel=document.getElementById('tpl-select'); if(!sel) return;   // personal channel: no template picker
  const id=sel.value; const box=document.getElementById('tvars');
  const spec=TPLSPEC[id]; if(!id||id==='0'||!spec){ box.innerHTML=''; return; }
  let h=''; const hf=String(spec.header&&spec.header.format||'').toUpperCase();
  const loc=OUTCFG.header_loc||{};
  if(['IMAGE','VIDEO','DOCUMENT'].indexOf(hf)>=0){
    const k=hf.toLowerCase();
    const accept = k==='image'?'image/*':(k==='video'?'video/mp4':'.pdf');
    const cur = (OUTCFG.header_media!=null && OUTCFG.header_media!=='') ? OUTCFG.header_media : (spec.header_example||'');
    const note = (!OUTCFG.header_media && spec.header_example) ? ' <span class="text-muted">(imported from the template — you can upload your own for a permanent link)</span>' : '';
    h+='<div class="hint" style="margin:8px 0 4px">Header '+k+' — upload a file, or paste a public URL.'+note+'</div>'
      +'<div class="field"><span class="lbl">Header '+k+'</span>'
      +'<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
      +'<input type="text" id="hm-url" name="header_media" value="'+eatt(cur)+'" placeholder="https://... or click Upload" style="flex:1;min-width:220px">'
      +'<input type="file" id="hm-file" accept="'+accept+'" style="display:none" onchange="uploadHeaderMedia(this)">'
      +'<button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById(\'hm-file\').click()">&#8593; Upload</button>'
      +'<span id="hm-status" class="text-muted" style="font-size:12px"></span>'
      +'</div></div>';
  } else if(hf==='LOCATION'){
    h+='<div class="hint" style="margin:8px 0 4px">Header location:</div>'
      +'<div class="grid3">'+fld('Latitude',inp('header_lat',loc.lat,'30.0'))+fld('Longitude',inp('header_lng',loc.lng,'31.2'))+fld('Place name',inp('header_locname',loc.name,'Porto Grande'))+'</div>'
      +fld('Address',inp('header_addr',loc.address,'New Alamein'));
  } else if(hf==='TEXT' && spec.header.text_vars>0){
    h+='<div class="hint" style="margin:8px 0 4px">Header variables:</div>';
    for(let i=1;i<=spec.header.text_vars;i++) h+=varRow('hvar',i,(OUTCFG.header_vars||{})[i]||(OUTCFG.header_vars||{})[String(i)]);
  }
  if(spec.body_vars>0){
    h+='<div class="hint" style="margin:8px 0 4px">Body variables — choose what each {{n}} becomes:</div>';
    for(let i=1;i<=spec.body_vars;i++) h+=varRow('tvar',i,(OUTCFG.vars||{})[i]||(OUTCFG.vars||{})[String(i)]);
  }
  (spec.buttons||[]).forEach(function(b){ if(!b.dynamic) return;
    const cur=(OUTCFG.buttons||{})[b.index]||(OUTCFG.buttons||{})[String(b.index)]||'';
    const lbl = b.type==='COPY_CODE' ? 'Coupon code for the "'+eatt(b.text)+'" button' : 'Value for the URL button "'+eatt(b.text)+'" ({{1}})';
    h+='<div class="hint" style="margin:8px 0 4px">Button parameter:</div>'+fld(lbl, inp('btn_param['+b.index+']', cur, ''));
  });
  box.innerHTML=h;
}
document.addEventListener('DOMContentLoaded',renderTvars);
function addQ(){ document.getElementById('qs').insertAdjacentHTML('beforeend',
  '<div class="qrow" style="display:flex;gap:8px;margin-bottom:8px"><input type="text" name="question[]" placeholder="e.g. When are you looking to buy?"><button type="button" class="icon-btn" onclick="this.closest(\'.qrow\').remove()">&#x2715;</button></div>'); }
function addGoal(){ document.getElementById('goals').insertAdjacentHTML('beforeend',
  '<div class="grow" style="display:flex;gap:8px;margin-bottom:8px"><input type="text" name="chat_goal[]" placeholder="e.g. get their budget"><button type="button" class="icon-btn" onclick="this.closest(\'.grow\').remove()">&#x2715;</button></div>'); }
function addCap(){ document.getElementById('caps').insertAdjacentHTML('beforeend',
  '<div class="crow" style="display:flex;gap:8px;margin-bottom:8px"><input type="text" name="capture[]" placeholder="e.g. Final request"><button type="button" class="icon-btn" onclick="this.closest(\'.crow\').remove()">&#x2715;</button></div>'); }
</script>

<?php layout_footer(); ?>
