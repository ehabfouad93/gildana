<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/../includes/ai.php';
require __DIR__ . '/../includes/notify.php';
require __DIR__ . '/../includes/automation.php';

$cid  = (int) $CLIENT['id'];
$id   = (int) ($_GET['id'] ?? 0);
$flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='agent'", [$id, $cid]);
if (!$flow) { http_response_code(404); exit('Agent not found.'); }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verify_csrf();
    $name    = trim((string) ($_POST['name'] ?? $flow['name']));
    $trigger = in_array(($_POST['trigger_type'] ?? ''), ['welcome', 'keyword'], true) ? (string) $_POST['trigger_type'] : 'welcome';
    $match   = in_array(($_POST['match_type'] ?? ''), ['contains', 'exact', 'starts'], true) ? (string) $_POST['match_type'] : 'contains';
    $keywords = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['keywords'] ?? '')))));

    $knowledge = trim((string) ($_POST['knowledge'] ?? ''));
    if (!empty($_FILES['kb_file']['tmp_name']) && (int) ($_FILES['kb_file']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $extracted = extract_text_from_upload((string) $_FILES['kb_file']['tmp_name'], (string) $_FILES['kb_file']['name']);
        if ($extracted !== '') $knowledge = trim($knowledge . "\n\n" . $extracted);
    }
    $intro        = trim((string) ($_POST['chat_intro'] ?? ''));
    $persona      = trim((string) ($_POST['chat_persona'] ?? ''));
    $instructions = trim((string) ($_POST['chat_instructions'] ?? ''));
    $maxTurns     = max(1, min(30, (int) ($_POST['chat_max_turns'] ?? 8)));
    $goals    = array_values(array_filter(array_map('trim', (array) ($_POST['chat_goal'] ?? []))));
    $captures = []; $usedKeys = [];
    foreach (array_values(array_filter(array_map('trim', (array) ($_POST['capture'] ?? [])))) as $ci => $lab) {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($lab)), '_');
        if ($key === '') $key = 'field_' . ($ci + 1);
        $base = $key; $k = 2; while (in_array($key, $usedKeys, true)) { $key = $base . '_' . $k; $k++; }
        $usedKeys[] = $key;
        $captures[] = ['key' => $key, 'label' => $lab];
    }

    if ($knowledge === '') $err = 'Add a knowledge base — the agent answers only from it.';
    if ($err === '') {
        $triggerConfig = $trigger === 'keyword' ? json_encode(['keywords' => $keywords, 'match_type' => $match], JSON_UNESCAPED_UNICODE) : null;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_run("DELETE FROM flow_steps WHERE flow_id=?", [$id]);
            // Compile: ai_chat → collect → End.
            $chain = [
                ['type' => 'ai_chat', 'config' => [
                    'knowledge' => $knowledge, 'intro' => $intro, 'persona' => $persona,
                    'instructions' => $instructions,
                    'goals' => $goals, 'captures' => $captures, 'max_turns' => $maxTurns,
                ]],
                ['type' => 'collect', 'config' => ['sheet_name' => 'Chats', 'fields' => ['phone', 'name', 'last_reply', 'tags']]],
            ];
            $ids = [];
            foreach ($chain as $pos => $st) {
                $ids[$pos] = db_insert("INSERT INTO flow_steps (flow_id,client_id,sort,type,config,created_at) VALUES (?,?,?,?,?,NOW())",
                    [$id, $cid, $pos, $st['type'], json_encode($st['config'], JSON_UNESCAPED_UNICODE)]);
            }
            foreach ($chain as $pos => $st) {
                db_run("UPDATE flow_steps SET next_step_id=? WHERE id=?", [$ids[$pos + 1] ?? null, $ids[$pos]]);
            }
            db_run("UPDATE flows SET name=?, trigger_type=?, trigger_config=?, first_step_id=?, updated_at=NOW() WHERE id=?",
                [$name, $trigger, $triggerConfig, $ids[0] ?? null, $id]);
            $pdo->commit();
            flash('Agent saved.');
            redirect('agent_edit.php?id=' . $id);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('agent save: ' . $ex->getMessage());
            $err = 'Could not save. Please try again.';
        }
    }
    $flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=?", [$id, $cid]);
}

/* reconstruct form */
$tc = json_decode((string) $flow['trigger_config'], true) ?: [];
$kwStr = implode(', ', (array) ($tc['keywords'] ?? [])); $matchType = (string) ($tc['match_type'] ?? 'contains');
$steps = db_all("SELECT * FROM flow_steps WHERE flow_id=? ORDER BY sort, id", [$id]);
$knowledge = ''; $chatIntro = ''; $chatPersona = ''; $chatInstructions = ''; $chatMaxTurns = 8; $chatGoals = []; $chatCaptures = [];
foreach ($steps as $s) {
    if ($s['type'] !== 'ai_chat') continue;
    $c = json_decode((string) $s['config'], true) ?: [];
    $knowledge        = (string) ($c['knowledge'] ?? '');
    $chatIntro        = (string) ($c['intro'] ?? '');
    $chatPersona      = (string) ($c['persona'] ?? '');
    $chatInstructions = (string) ($c['instructions'] ?? '');
    $chatMaxTurns     = (int) ($c['max_turns'] ?? 8);
    $chatGoals        = (array) ($c['goals'] ?? []);
    $chatCaptures     = (array) ($c['captures'] ?? []);
}
if (!$chatGoals) $chatGoals = [''];
if (!$chatCaptures) $chatCaptures = [['key' => '', 'label' => '']];

client_header('Agent · ' . $flow['name'], 'agents', $CLIENT);
?>
<div class="page-head"><h1><?= e((string) $flow['name']) ?></h1>
  <div class="page-actions">
    <?= guide_button('agents') ?>
    <a class="btn btn-ghost btn-sm" href="agents.php">← All</a>
    <a class="btn btn-ghost btn-sm" href="agent_chats.php?flow=<?= $id ?>">Chats →</a>
  </div>
</div>
<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">

  <div class="card">
    <h2>1 · Trigger</h2>
    <div class="field"><span class="lbl">Name</span><input type="text" name="name" value="<?= e((string) $flow['name']) ?>" required></div>
    <div class="grid3">
      <div class="field"><span class="lbl">Start when…</span>
        <select name="trigger_type" id="trig">
          <option value="welcome" <?= $flow['trigger_type'] === 'welcome' ? 'selected' : '' ?>>Any customer messages</option>
          <option value="keyword" <?= $flow['trigger_type'] === 'keyword' ? 'selected' : '' ?>>A keyword is received</option>
        </select>
      </div>
      <div class="field"><span class="lbl">Keywords <span class="text-muted">(comma separated)</span></span><input type="text" name="keywords" value="<?= e($kwStr) ?>" placeholder="price, info, مهتم"></div>
      <div class="field"><span class="lbl">Match</span>
        <select name="match_type">
          <?php foreach (['contains' => 'Contains', 'exact' => 'Exact', 'starts' => 'Starts with'] as $mv => $ml): ?>
            <option value="<?= $mv ?>" <?= $matchType === $mv ? 'selected' : '' ?>><?= $ml ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="hint">Free-form AI replies only work within 24h of the customer's last message; each sent message costs 1 credit.</div>
  </div>

  <div class="card">
    <h2>2 · Knowledge base</h2>
    <div class="alert info" style="font-size:12px">The agent answers <strong>only</strong> from this. Anything not covered → it says a colleague will follow up (never invents prices or promises).</div>
    <div class="field"><span class="lbl">Everything the agent may answer from</span>
      <textarea name="knowledge" rows="9" placeholder="Company, products, prices, offers, working hours, FAQs…"><?= e($knowledge) ?></textarea>
    </div>
    <div class="field"><span class="lbl">Or upload a document <span class="text-muted">(.txt or .docx)</span></span><input type="file" name="kb_file" accept=".txt,.docx"></div>
    <div class="field"><span class="lbl">Instructions <span class="text-muted">(language, dialect, tone, wording rules — followed strictly)</span></span>
      <textarea name="chat_instructions" rows="3" placeholder="مثال: رد دايماً باللهجة المصرية العامية، بلاش فصحى ولا إنجليزي. كن مختصر ومهذب."><?= e($chatInstructions) ?></textarea>
    </div>
  </div>

  <div class="card">
    <h2>3 · Personality &amp; goals</h2>
    <div class="grid3">
      <div class="field"><span class="lbl">Greeting <span class="text-muted">(optional — sent before first reply)</span></span><input type="text" name="chat_intro" value="<?= e($chatIntro) ?>" placeholder="أهلاً! اسأل عن أي حاجة 🙂"></div>
      <div class="field"><span class="lbl">Who is the AI? <span class="text-muted">(persona)</span></span><input type="text" name="chat_persona" value="<?= e($chatPersona) ?>" placeholder="e.g. a support agent at Amer Group"></div>
      <div class="field"><span class="lbl">Max AI replies per chat</span><input type="number" name="chat_max_turns" value="<?= (int) $chatMaxTurns ?>" min="1" max="30"></div>
    </div>
    <div class="field"><span class="lbl">Goals <span class="text-muted">(the AI pursues these while helping)</span></span>
      <div id="goals">
        <?php foreach ($chatGoals as $g): ?>
          <div class="grow" style="display:flex;gap:8px;margin-bottom:8px"><input type="text" name="chat_goal[]" value="<?= e((string) $g) ?>" placeholder="e.g. book an appointment"><button type="button" class="icon-btn" onclick="this.closest('.grow').remove()">&#x2715;</button></div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addGoal()">+ Goal</button>
    </div>
    <div class="field"><span class="lbl">Capture from chat <span class="text-muted">(e.g. mobile, final request)</span></span>
      <div id="caps">
        <?php foreach ($chatCaptures as $cap): ?>
          <div class="crow" style="display:flex;gap:8px;margin-bottom:8px"><input type="text" name="capture[]" value="<?= e((string) ($cap['label'] ?? '')) ?>" placeholder="e.g. Mobile number"><button type="button" class="icon-btn" onclick="this.closest('.crow').remove()">&#x2715;</button></div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addCap()">+ Capture</button>
    </div>
  </div>

  <div class="form-actions" style="display:flex;gap:10px;justify-content:flex-end">
    <a class="btn btn-ghost" href="agents.php">Cancel</a>
    <button class="btn btn-primary">Save agent</button>
  </div>
</form>

<script>
function addGoal(){ document.getElementById('goals').insertAdjacentHTML('beforeend',
  '<div class="grow" style="display:flex;gap:8px;margin-bottom:8px"><input type="text" name="chat_goal[]" placeholder="e.g. get their budget"><button type="button" class="icon-btn" onclick="this.closest(\'.grow\').remove()">&#x2715;</button></div>'); }
function addCap(){ document.getElementById('caps').insertAdjacentHTML('beforeend',
  '<div class="crow" style="display:flex;gap:8px;margin-bottom:8px"><input type="text" name="capture[]" placeholder="e.g. Final request"><button type="button" class="icon-btn" onclick="this.closest(\'.crow\').remove()">&#x2715;</button></div>'); }
</script>

<?php layout_footer(); ?>
