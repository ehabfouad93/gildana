<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Messages that need a person to look at them.
 *
 *   dead   — WhatsApp kept failing with an error worth retrying, and the retries ran out.
 *   review — a send was attempted but the result never came back (the worker died mid-call).
 *            It may already have reached the customer, so it is deliberately NOT resent
 *            automatically; sending it again would double-message them and double-charge.
 *
 * Both used to be invisible: the message just stopped, and nothing said so.
 */
$cid = (int) $CLIENT['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    verify_csrf();
    $a  = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($a === 'retry') {
        // Retry clears the attempt history so the backoff starts fresh.
        $n = db_run("UPDATE campaign_messages SET status='queued', attempt_count=0, next_attempt_at=NULL,
                            claimed_by=NULL, claimed_at=NULL, error_code=NULL, error_title=NULL, updated_at=NOW()
                      WHERE id=? AND client_id=? AND status IN ('dead','review','failed')", [$id, $cid]);
        if ($n) {
            db_run("DELETE FROM send_attempts WHERE campaign_message_id=?", [$id]);
            trigger_worker();
        }
        json_out(['ok' => (bool) $n]);
    }
    if ($a === 'discard') {
        $n = db_run("UPDATE campaign_messages SET status='failed', error_title='Discarded by user', updated_at=NOW()
                      WHERE id=? AND client_id=? AND status IN ('dead','review','failed')", [$id, $cid]);
        json_out(['ok' => (bool) $n]);
    }
    if ($a === 'retry_all_capped') {
        /* Only the codes wa_error_explain() marks 'later' are eligible, so this cannot mass-retry
           blocked numbers or a bad token. Filtered in PHP rather than SQL because the mapping
           from code to "worth retrying" lives in one function, not duplicated in a query. */
        $ids = [];
        foreach (db_all("SELECT id, error_code, error_title FROM campaign_messages
                          WHERE client_id=? AND status='failed'
                            AND COALESCE(error_title,'') <> 'Discarded by user'", [$cid]) as $r) {
            $ex = wa_error_explain((string) ($r['error_code'] ?? ''), (string) ($r['error_title'] ?? ''));
            if ($ex['action'] === 'later') $ids[] = (int) $r['id'];
        }
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            db_run("UPDATE campaign_messages SET status='queued', attempt_count=0, next_attempt_at=NULL,
                           claimed_by=NULL, claimed_at=NULL, error_code=NULL, error_title=NULL, updated_at=NOW()
                     WHERE id IN ($ph) AND client_id=?", array_merge($ids, [$cid]));
            trigger_worker();
        }
        json_out(['ok' => true, 'n' => count($ids)]);
    }
    if ($a === 'retry_all_dead') {
        $n = db_run("UPDATE campaign_messages SET status='queued', attempt_count=0, next_attempt_at=NULL,
                            claimed_by=NULL, claimed_at=NULL, updated_at=NOW()
                      WHERE client_id=? AND status='dead'", [$cid]);
        if ($n) trigger_worker();
        json_out(['ok' => true, 'n' => $n]);
    }
    json_out(['ok' => false]);
}

/* 'failed' is included deliberately, and it is the reason this page was missing most of what
   a client actually wanted to see. A per-recipient cap (#131049) is NOT retried automatically
   — retrying inside the same run returns the identical error and burns a credit — so it lands
   as terminal 'failed'. This page only listed 'dead' and 'review', so those messages vanished:
   no count, no reason, no way to send them again once the cap had lifted.

   The filter is by cause, not by status: wa_error_explain() marks a code 'later' only when
   resending it later can actually succeed. A blocked number or a bad token stays off this list
   rather than inviting a retry that cannot work. */
$candidates = db_all(
    "SELECT m.*, c.name AS campaign_name, ct.name AS contact_name
       FROM campaign_messages m
       JOIN campaigns c  ON c.id = m.campaign_id
       LEFT JOIN contacts ct ON ct.id = m.contact_id
      WHERE m.client_id = ? AND m.status IN ('dead','review','failed')
        AND COALESCE(m.error_title,'') <> 'Discarded by user'
      ORDER BY m.updated_at DESC LIMIT 800", [$cid]);

$rows = [];
foreach ($candidates as $r) {
    if ($r['status'] === 'failed') {
        $ex = wa_error_explain((string) ($r['error_code'] ?? ''), (string) ($r['error_title'] ?? ''));
        if ($ex['action'] !== 'later') continue;      // a resend would buy the same error
        $r['explain'] = $ex;
    }
    $rows[] = $r;
    if (count($rows) >= 500) break;
}

$deadCount   = 0;
$reviewCount = 0;
$cappedCount = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'dead') $deadCount++;
    elseif ($r['status'] === 'review') $reviewCount++;
    else $cappedCount++;
}

client_header('Needs attention', 'attention', $CLIENT);
?>
<div class="page-head">
  <h1>Needs attention</h1>
  <p class="text-muted">Messages that stopped and need you to decide what happens next.</p>
  <div class="page-actions"><?= guide_button('failed') ?></div>
</div>

<?php if (!$rows): ?>
  <div class="card empty-state">
    <h2>Nothing needs attention</h2>
    <p class="text-muted">Every message either went out or failed for a reason we could report on the campaign.</p>
  </div>
<?php else: ?>

  <?php if ($reviewCount): ?>
    <div class="note warn mb16">
      <strong><?= (int) $reviewCount ?> message<?= $reviewCount === 1 ? '' : 's' ?> may already have been delivered.</strong>
      The send was interrupted before WhatsApp confirmed it, so we stopped rather than risk
      sending the same message twice and charging you twice. Check with the customer, then
      send again or discard.
    </div>
  <?php endif; ?>

  <?php if ($deadCount): ?>
    <div class="row-between mb16">
      <span><strong><?= (int) $deadCount ?></strong> message<?= $deadCount === 1 ? '' : 's' ?> gave up after repeated errors.</span>
      <button class="btn btn-ghost" id="retry-all">Try all of them again</button>
    </div>
  <?php endif; ?>

  <?php if ($cappedCount): ?>
    <div class="note warn mb16">
      <strong><?= (int) $cappedCount ?> message<?= $cappedCount === 1 ? '' : 's' ?> WhatsApp held back for now.</strong>
      These were not charged and nothing is wrong with your account — WhatsApp limits how many
      marketing messages one person receives from all businesses in a period. It clears by
      itself, usually within 24 hours, so sending them again tomorrow normally works.
      <div style="margin-top:10px">
        <button class="btn btn-sm" id="retry-capped">Send all <?= (int) $cappedCount ?> again</button>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <table class="table">
      <thead><tr><th>Campaign</th><th>To</th><th>What happened</th><th>Tries</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-id="<?= (int) $r['id'] ?>">
          <td><?= e((string) $r['campaign_name']) ?></td>
          <td>
            <?= e((string) ($r['contact_name'] ?: '')) ?>
            <span class="text-muted d-block">+<?= e((string) $r['phone_e164']) ?></span>
          </td>
          <td>
            <?php if ($r['status'] === 'review'): ?>
              <span class="pill warn">Result unconfirmed</span>
              <span class="text-muted d-block">Sent, but WhatsApp never confirmed it.</span>
            <?php elseif ($r['status'] === 'failed' && !empty($r['explain'])): ?>
              <span class="pill gold"><?= e((string) $r['explain']['label']) ?></span>
              <span class="text-muted d-block"><?= strip_tags((string) $r['explain']['hint']) ?></span>
            <?php else: ?>
              <span class="pill red">Gave up</span>
              <span class="text-muted d-block"><?= e((string) ($r['error_title'] ?: 'Repeated errors')) ?></span>
            <?php endif; ?>
          </td>
          <td><?= (int) $r['attempt_count'] ?></td>
          <td class="text-right">
            <button class="btn btn-sm" data-act="retry">Send again</button>
            <button class="btn btn-sm btn-ghost" data-act="discard">Discard</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
async function act(action, id, btn) {
  if (btn) { btn.disabled = true; }
  const b = new URLSearchParams({ ajax: '1', action, id: id || '', csrf_token: CSRF });
  const r = await fetch(location.pathname, { method: 'POST', body: b });
  const j = await r.json().catch(() => ({ ok: false }));
  if (j.ok) { location.reload(); return; }
  if (btn) { btn.disabled = false; }
  alert('That did not work. Reload the page and try again.');
}
document.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', () => {
  const act_ = b.dataset.act;
  const id = b.closest('tr').dataset.id;
  if (act_ === 'discard' && !confirm('Discard this message? It will not be sent.')) return;
  act(act_, id, b);
}));
const all = document.getElementById('retry-all');
if (all) all.addEventListener('click', () => act('retry_all_dead', '', all));
const cap = document.getElementById('retry-capped');
if (cap) cap.addEventListener('click', () => act('retry_all_capped', '', cap));
</script>
<?php layout_footer(); ?>
