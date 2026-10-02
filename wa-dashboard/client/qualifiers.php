<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/automation.php';

$cid = (int) $CLIENT['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='qualifier'", [$id, $cid]);
    if (!$flow) json_out(['ok' => false]);
    if (($_POST['action'] ?? '') === 'toggle') {
        $new = $flow['status'] === 'active' ? 'paused' : 'active';
        db_run("UPDATE flows SET status=? WHERE id=?", [$new, $id]);
        if ($new === 'paused') {
            // Deactivating cancels the pending outreach backlog so it stops sending.
            $cancelled = (int) db_val("SELECT COUNT(*) FROM flow_runs WHERE flow_id=? AND status='queued'", [$id]);
            db_run("UPDATE flow_runs SET status='stopped', updated_at=NOW() WHERE flow_id=? AND status='queued'", [$id]);
            json_out(['ok' => true, 'status' => $new, 'cancelled' => $cancelled]);
        }
        trigger_worker(); // reactivated → resume sending immediately
        json_out(['ok' => true, 'status' => $new]);
    }
    json_out(['ok' => false]);
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') $err = 'Enter a name.';
    else {
        $newId = db_insert("INSERT INTO flows (client_id,name,kind,status,trigger_type,created_at) VALUES (?,?, 'qualifier','draft','google_sheet', NOW())", [$cid, $name]);
        redirect('qualifier_edit.php?id=' . $newId);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'duplicate') {
    verify_csrf();
    $newId = flow_duplicate((int) ($_POST['id'] ?? 0), $cid, 'qualifier');
    if ($newId > 0) {
        flash('Copied. This one is a draft — check the sheet and questions, then switch it on.');
        redirect('qualifier_edit.php?id=' . $newId);
    }
    $err = 'That qualifier could not be copied.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    db_run("DELETE FROM flows WHERE id=? AND client_id=? AND kind='qualifier'", [(int) ($_POST['id'] ?? 0), $cid]);
    flash('Qualifier deleted.');
    redirect('qualifiers.php');
}
/* Resend just the outreach that failed.
   "Send now" also does this, but it re-reads the Google Sheet first, so a client whose only
   problem was a handful of capped sends had to run a full import to clear them — and nothing
   on the page told them there were any failures to clear in the first place. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_failed') {
    verify_csrf();
    $f = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='qualifier'", [(int) ($_POST['id'] ?? 0), $cid]);
    if (!$f) { flash('Qualifier not found.', 'error'); redirect('qualifiers.php'); }
    // Same definition of "stuck outreach" that automation_send_now() uses: blocked before the
    // lead was ever graded, i.e. the first message never landed.
    $stuck = qualifier_stuck_runs((int) $f['id'], $cid);
    $n     = count($stuck['retry']);
    if ($n > 0) {
        $ph = implode(',', array_fill(0, $n, '?'));
        db_run("UPDATE flow_runs SET status='queued', updated_at=NOW()
                 WHERE id IN ($ph) AND client_id=?", array_merge($stuck['retry'], [$cid]));
        trigger_worker();
    }
    // Numbers that cannot receive the message are left where they are rather than silently
    // resent, so the count here matches the advice printed next to it.
    $skipped = count($stuck['never']);
    flash($n > 0
        ? "Queued {$n} message(s) to send again. They go out in the background."
          . ($skipped ? " {$skipped} left alone — those numbers cannot receive the message." : '')
        : ($skipped ? "Nothing to resend — those {$skipped} number(s) cannot receive the message."
                    : 'Nothing to resend.'));
    redirect('qualifiers.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_now') {
    verify_csrf();
    $f = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='qualifier'", [(int) ($_POST['id'] ?? 0), $cid]);
    if (!$f) { flash('Qualifier not found.', 'error'); redirect('qualifiers.php'); }
    $r = automation_send_now($CLIENT, $f);
    if ($r['error'] !== '') flash('Send failed: ' . $r['error'], 'error');
    else flash("Sent now: {$r['imported']} new · {$r['retried']} retried · {$r['skipped']} already in this qualifier · {$r['invalid']} invalid of {$r['rows']} rows.");
    redirect('qualifiers.php');
}

$flows = db_all(
    "SELECT f.*,
            (SELECT COUNT(*) FROM flow_runs r WHERE r.flow_id=f.id) AS leads,
            (SELECT COUNT(*) FROM flow_runs r WHERE r.flow_id=f.id AND r.grade='hot') AS hot,
            -- Outreach that never reached the lead. Counted here rather than left implicit,
            -- because a qualifier with 40 leads and 40 failed sends looked identical to a
            -- healthy one on this page.
            (SELECT COUNT(*) FROM flow_runs r
              WHERE r.flow_id=f.id AND r.status='blocked' AND r.grade IS NULL) AS failed
       FROM flows f WHERE f.client_id=? AND f.kind='qualifier' ORDER BY f.id DESC", [$cid]
);

/* Split each qualifier's stuck outreach into "worth resending" and "cannot be delivered",
   so the button offers only the first and the count never promises more than it can do. */
$stuckBy = [];
foreach ($flows as $f) {
    $stuckBy[(int) $f['id']] = (int) $f['failed']
        ? qualifier_stuck_runs((int) $f['id'], $cid)
        : ['retry' => [], 'never' => []];
}

// Each qualifier's leads by what happened to the outreach: sent, read, not read yet, never arrived.
require_once __DIR__ . '/../includes/msg_status.php';
$mc = qualifier_msg_counts($cid);
$sum = ['leads' => 0, 'sent' => 0, 'read' => 0, 'unread' => 0, 'failed' => 0];
foreach ($mc as $x) foreach ($sum as $k => $_) $sum[$k] += $x[$k];
$pc = fn(int $n, int $of) => $of > 0 ? round(100 * $n / $of) . '%' : '—';

$actions = '<a class="btn btn-ghost btn-sm" href="diagnostics.php">🩺 Health check</a>'
         . '<button class="btn btn-primary btn-sm" onclick="document.getElementById(\'m-new\').classList.add(\'open\')">+ New Qualifier</button>';
client_header('Lead Qualifier', 'qualifier', $CLIENT);
page_head('Lead Qualifier', $actions);
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="alert info" style="font-size:12.5px">
  A qualifier pulls leads from a <strong>Google Sheet</strong>, sends an approved outreach template, then an <strong>AI</strong> asks your questions and scores each lead <strong>hot / warm / cold</strong>.
  <?php if (!($CLIENT['ai_provider'] ?? '')): ?> Add your AI key in <a href="settings.php#ai">Settings</a> first.<?php endif; ?>
</div>

<?php if ($flows): ?>
<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Leads</span><span class="val"><?= number_format($sum['leads']) ?></span><span class="sub"><?= count($flows) ?> qualifier<?= count($flows) === 1 ? '' : 's' ?></span></div>
  <div class="stat-tile"><span class="lbl">Sent</span><span class="val"><?= number_format($sum['sent']) ?></span><span class="sub"><?= $pc($sum['sent'], $sum['leads']) ?> of leads</span></div>
  <div class="stat-tile"><span class="lbl">Read</span><span class="val accent"><?= number_format($sum['read']) ?></span><span class="sub"><?= $pc($sum['read'], $sum['sent']) ?> of sent</span></div>
  <div class="stat-tile"><span class="lbl">Unread</span><span class="val"><?= number_format($sum['unread']) ?></span><span class="sub"><?= $pc($sum['unread'], $sum['sent']) ?> of sent</span></div>
  <div class="stat-tile"><span class="lbl">Failed</span><span class="val danger"><?= number_format($sum['failed']) ?></span><span class="sub"><?= $pc($sum['failed'], $sum['leads']) ?> of leads</span></div>
</div>
<?php endif; ?>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Qualifier</th><th>Sheet</th><th class="num">Leads</th><th class="num">Sent</th><th class="num">Read</th><th class="num">Unread</th><th class="num">Failed</th><th class="num">Hot</th><th>Active</th><th></th></tr></thead>
      <tbody>
      <?php if (!$flows): ?><tr><td colspan="10"><div class="empty">No qualifiers yet.</div></td></tr><?php endif; ?>
      <?php foreach ($flows as $f):
        $sc = json_decode((string) $f['source_config'], true) ?: [];
      ?>
        <tr>
          <td><strong><?= e((string) $f['name']) ?></strong> <?= $f['status'] === 'draft' ? '<span class="pill gray">draft</span>' : '' ?></td>
          <td><?= !empty($sc['csv_url']) ? '<span class="pill green">connected</span>' : '<span class="pill gray">not set</span>' ?></td>
          <?php $m_ = $mc[(int) $f['id']] ?? ['sent' => 0, 'read' => 0, 'unread' => 0, 'failed' => 0]; $ll = 'leads.php?flow=' . (int) $f['id'] . '&msg='; ?>
          <td class="num"><a href="leads.php?flow=<?= (int) $f['id'] ?>"><?= (int) $f['leads'] ?></a></td>
          <td class="num"><a href="<?= $ll ?>sent"><?= $m_['sent'] ?></a></td>
          <td class="num"><a href="<?= $ll ?>read"><?= $m_['read'] ?></a><?php if ($m_['sent']): ?><small class="text-muted cm-pct"><?= round(100 * $m_['read'] / $m_['sent']) ?>%</small><?php endif; ?></td>
          <td class="num"><a href="<?= $ll ?>unread"><?= $m_['unread'] ?></a></td>
          <td class="num"><?= $m_['failed'] ? '<a href="' . $ll . 'failed" class="pill red" title="Outreach that never reached the lead">' . $m_['failed'] . '</a>' : '<span class="text-muted">0</span>' ?></td>
          <td class="num"><?= (int) $f['hot'] ? '<span class="pill red">' . (int) $f['hot'] . '</span>' : '0' ?></td>
          <td><label class="switch"><input type="checkbox" <?= $f['status'] === 'active' ? 'checked' : '' ?> onchange="toggleQ(<?= (int) $f['id'] ?>,this)"><span class="slider"></span></label></td>
          <td style="text-align: end;white-space:nowrap">
            <form method="post" style="display:inline" onsubmit="return confirm('Import new leads from the sheet and send outreach now?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="send_now"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button class="btn btn-primary btn-sm" title="Import new + send outreach + retry stuck">&#9658; Send now</button>
            </form>
            <?php $canRetry = count($stuckBy[(int) $f['id']]['retry'] ?? []); ?>
            <?php if ($canRetry): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Send the <?= $canRetry ?> failed message(s) again?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="resend_failed"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button class="btn btn-sm" title="Try the messages that did not reach the lead again — does not re-read the sheet">&#8635; Resend <?= $canRetry ?></button>
            </form>
            <?php endif; ?>
            <a class="btn btn-ghost btn-sm" href="qualifier_edit.php?id=<?= (int) $f['id'] ?>">Edit</a>
            <a class="btn btn-ghost btn-sm" href="leads.php?flow=<?= (int) $f['id'] ?>">Leads</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button class="btn btn-ghost btn-sm" title="Make a copy of this qualifier">Duplicate</button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this qualifier and its leads?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button class="icon-btn" title="Delete">&#x2715;</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
/* Why the outreach did not arrive, per qualifier, grouped by cause.
   The count in the table says how many; this says what to do about them — a client seeing
   "12 not sent" with no reason has no way to tell a temporary WhatsApp cap (resend tomorrow)
   from a wrong phone number (resending buys the same error). */
foreach ($flows as $f):
    if (!(int) $f['failed']) continue;
    $reasons = db_all(
        "SELECT COALESCE(NULLIF(fm.error_code,''),'') AS code,
                COALESCE(NULLIF(fm.error_title,''),'Send failed') AS title,
                COUNT(DISTINCT fm.run_id) AS n
           FROM flow_messages fm
           JOIN flow_runs r ON r.id = fm.run_id AND r.status='blocked' AND r.grade IS NULL
          WHERE fm.flow_id=? AND fm.client_id=? AND fm.status='failed'
          GROUP BY code, title
          ORDER BY n DESC", [(int) $f['id'], $cid]);
    if (!$reasons) continue;
?>
  <div class="card" id="fail-<?= (int) $f['id'] ?>" style="margin-top:14px">
    <h2 style="margin:0 0 4px;font-size:15px">
      <?= (int) $f['failed'] ?> message(s) never reached the lead — <?= e((string) $f['name']) ?>
    </h2>
    <p class="text-muted" style="font-size:12.5px;margin:0 0 10px">
      These leads are still in the qualifier. Nothing was charged for a message that did not arrive.
    </p>
    <ul style="margin:0;padding-inline-start:18px;font-size:13px">
      <?php foreach ($reasons as $rr):
        $ex = wa_error_explain((string) $rr['code'], (string) $rr['title']); ?>
        <li style="margin-bottom:8px">
          <strong><?= number_format((int) $rr['n']) ?>×</strong> <?= e($ex['label']) ?>
          <?php if (trim((string) $rr['code']) !== ''): ?>
            <span class="text-muted">(#<?= e((string) $rr['code']) ?>)</span>
          <?php endif; ?>
          <div class="text-muted" style="font-size:12px;margin-top:2px"><?= $ex['hint'] ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php $canRetry = count($stuckBy[(int) $f['id']]['retry'] ?? []);
          $cannot   = count($stuckBy[(int) $f['id']]['never'] ?? []); ?>
    <form method="post" style="margin-top:10px">
      <?= csrf_field() ?><input type="hidden" name="action" value="resend_failed"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
      <?php if ($canRetry): ?>
        <button class="btn btn-primary btn-sm">&#8635; Send <?= $canRetry ?> again</button>
        <span class="text-muted" style="font-size:12px;margin-inline-start:8px">
          Does not re-read the Google Sheet.<?= $cannot ? ' The other ' . $cannot . ' cannot be delivered and are left alone.' : '' ?>
        </span>
      <?php else: ?>
        <span class="text-muted" style="font-size:12px">
          None of these can be delivered by sending again — check the numbers instead.
        </span>
      <?php endif; ?>
    </form>
  </div>
<?php endforeach; ?>

<div class="modal-back" id="m-new">
  <form class="modal" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <h2>New Lead Qualifier</h2>
    <div class="field"><span class="lbl">Name</span><input type="text" name="name" placeholder="e.g. Property leads Q3" required></div>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-new').classList.remove('open')">Cancel</button><button class="btn btn-primary">Create &amp; Configure</button></div>
  </form>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
async function toggleQ(id, el){
  const fd=new FormData(); fd.append('ajax','1'); fd.append('csrf_token',CSRF); fd.append('action','toggle'); fd.append('id',id);
  const r=await fetch('',{method:'POST',body:fd}); const d=await r.json();
  if(d.ok) showToast(d.status==='active'?'Qualifier activated.':('Qualifier paused.'+(d.cancelled?' '+d.cancelled+' pending outreach cancelled.':''))); else { el.checked=!el.checked; showToast('Could not update.',true); }
}
</script>

<?php layout_footer(); ?>
