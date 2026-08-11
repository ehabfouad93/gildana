<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid  = (int) $CLIENT['id'];
$fid  = (int) ($_GET['flow'] ?? 0);
$flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='agent'", [$fid, $cid]);
if (!$flow) { http_response_code(404); exit('Agent not found.'); }

/* ── CSV export (chats + captured fields) ── */
if (($_GET['export'] ?? '') === '1') {
    $rows = db_all(
        "SELECT r.*, c.phone_e164, c.name FROM flow_runs r JOIN contacts c ON c.id=r.contact_id
          WHERE r.flow_id=? ORDER BY r.id DESC", [$fid]
    );
    $fieldKeys = [];
    foreach ($rows as $r) {
        $ctx = json_decode((string) $r['context'], true) ?: [];
        foreach (array_keys((array) ($ctx['fields'] ?? [])) as $k) $fieldKeys[$k] = true;
    }
    $fieldKeys = array_keys($fieldKeys);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="chats-' . $fid . '-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map('csv_cell', array_merge(['phone', 'name', 'status', 'messages', 'started'], $fieldKeys))) . "\n";
    foreach ($rows as $r) {
        $ctx = json_decode((string) $r['context'], true) ?: [];
        $fields = (array) ($ctx['fields'] ?? []);
        $out = [
            (string) $r['phone_e164'], (string) $r['name'], (string) $r['status'],
            (string) count((array) ($ctx['transcript'] ?? [])), (string) $r['created_at'],
        ];
        foreach ($fieldKeys as $k) $out[] = (string) ($fields[$k] ?? '');
        echo implode(',', array_map('csv_cell', $out)) . "\n";
    }
    exit;
}

$counts = db_row(
    "SELECT COUNT(*) total,
            SUM(status IN ('waiting_input','active')) chatting,
            SUM(status='completed') done
       FROM flow_runs WHERE flow_id=?", [$fid]
) ?: [];

$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 50; $off = ($page - 1) * $per;
$total = (int) db_val("SELECT COUNT(*) FROM flow_runs WHERE flow_id=?", [$fid]);
$chats = db_all("SELECT r.*, c.phone_e164, c.name FROM flow_runs r JOIN contacts c ON c.id=r.contact_id WHERE r.flow_id=? ORDER BY r.id DESC LIMIT $per OFFSET $off", [$fid]);
$pages = (int) max(1, ceil($total / $per));

function chat_status_pill(string $s): string {
    $map = ['completed' => ['green','Done'], 'waiting_input' => ['gold','Chatting'], 'active' => ['gold','Chatting'],
            'blocked' => ['red','Stopped'], 'stopped' => ['gray','Stopped']];
    [$cls,$lbl] = $map[$s] ?? ['gray', ucfirst($s)];
    return '<span class="pill ' . $cls . '">' . e($lbl) . '</span>';
}

$actions = '<a class="btn btn-ghost btn-sm" href="agent_edit.php?id=' . $fid . '">Edit</a>'
         . '<a class="btn btn-ghost btn-sm" href="agent_chats.php?flow=' . $fid . '&export=1">Export CSV</a>';
client_header('Chats · ' . $flow['name'], 'agents', $CLIENT);
page_head('Chats — ' . $flow['name'], $actions);
?>
<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Conversations</span><span class="val accent"><?= (int) ($counts['total'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Chatting</span><span class="val"><?= (int) ($counts['chatting'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Done</span><span class="val"><?= (int) ($counts['done'] ?? 0) ?></span></div>
</div>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Phone</th><th>Name</th><th>Status</th><th>Captured</th><th>Started</th><th></th></tr></thead>
      <tbody>
      <?php if (!$chats): ?><tr><td colspan="6"><div class="empty">No conversations yet. Activate the agent — it replies when a customer messages your number.</div></td></tr><?php endif; ?>
      <?php foreach ($chats as $r):
        $ctx = json_decode((string) $r['context'], true) ?: [];
        $tr  = json_encode($ctx['transcript'] ?? [], JSON_UNESCAPED_UNICODE);
        $fields = (array) ($ctx['fields'] ?? []);
        $cap = [];
        foreach ($fields as $k => $v) { if ($k === 'not_interested_reason' || $v === '') continue; $cap[] = $k . ': ' . $v; }
        $capStr = implode(' · ', array_slice($cap, 0, 3));
      ?>
        <tr>
          <td class="mono">+<?= e((string) $r['phone_e164']) ?></td>
          <td><?= e((string) $r['name']) ?: '<span class="text-muted">—</span>' ?></td>
          <td><?= chat_status_pill((string) $r['status']) ?></td>
          <td class="text-muted" style="max-width:260px;font-size:12px"><?= $capStr !== '' ? e($capStr) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted"><?= e(date('d M, H:i', strtotime((string) $r['created_at']))) ?></td>
          <td style="text-align:right"><button class="btn-link" onclick='viewChat(this)' data-tr='<?= e($tr) ?>' data-name="<?= e((string) $r['name'] ?: $r['phone_e164']) ?>">Transcript</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div style="padding:14px 18px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
      <?php for ($p = 1; $p <= min($pages, 30); $p++): ?>
        <a class="btn <?= $p === $page ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="agent_chats.php?flow=<?= $fid ?>&page=<?= $p ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<div class="modal-back" id="m-chat">
  <div class="modal">
    <h2 id="chat-title">Transcript</h2>
    <div id="chat-body" style="max-height:60vh;overflow-y:auto"></div>
    <div class="modal-actions"><button class="btn btn-ghost" onclick="document.getElementById('m-chat').classList.remove('open')">Close</button></div>
  </div>
</div>

<script>
function viewChat(btn){
  const tr = JSON.parse(btn.dataset.tr || '[]');
  document.getElementById('chat-title').textContent = 'Transcript — ' + btn.dataset.name;
  const box = document.getElementById('chat-body');
  box.innerHTML = tr.length ? tr.map(m=>{
    const bot = m.role==='assistant';
    return `<div style="display:flex;justify-content:${bot?'flex-start':'flex-end'};margin:6px 0">
      <div style="max-width:78%;padding:8px 11px;border-radius:10px;font-size:13px;background:${bot?'var(--paper)':'#d9f5e3'}">${(m.text||'').replace(/</g,'&lt;')}</div></div>`;
  }).join('') : '<p class="text-muted">No messages yet.</p>';
  document.getElementById('m-chat').classList.add('open');
}
</script>

<?php layout_footer(); ?>
