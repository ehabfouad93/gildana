<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    /* Dry run: how often would this rule have fired over the last week?
       Answering that before saving is the cure for alert fatigue. */
    if ($action === 'test_rule') {
        $rule = [
            'id' => 0, 'client_id' => $cid,
            'name' => (string) ($_POST['name'] ?? 'Test'),
            'type' => (string) ($_POST['type'] ?? 'any_negative'),
            'keyword_id' => (int) ($_POST['keyword_id'] ?? 0) ?: null,
            'connector' => (string) ($_POST['connector'] ?? ''),
            'threshold' => max(1, (int) ($_POST['threshold'] ?? 1)),
            'window_minutes' => max(5, (int) ($_POST['window_minutes'] ?? 60)),
            'min_confidence' => (float) ($_POST['min_confidence'] ?? 0.5),
            'cooldown_min' => 0, 'last_fired_at' => null,
            'recipients' => '', 'alert_email' => (string) $CLIENT['alert_email'],
            'brand_name' => (string) $CLIENT['brand_name'], 'client_name' => (string) $CLIENT['name'],
        ];
        $eval = alert_evaluate($rule, true);
        json_out(['ok' => true, 'message' => $eval['fire']
            ? sprintf('Would fire now — %d matching mention(s).', $eval['count'])
            : 'Would not fire with the current data.']);
    }

    if ($action === 'save') {
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = (string) ($_POST['type'] ?? 'any_negative');
        $valid = ['any_negative', 'negative_spike', 'volume_spike', 'high_reach_negative', 'keyword_match'];
        if (!in_array($type, $valid, true)) $type = 'any_negative';

        $keyword    = (int) ($_POST['keyword_id'] ?? 0);
        $connector  = (string) ($_POST['connector'] ?? '');
        if ($connector !== '' && !listen_connector($connector)) $connector = '';
        $threshold  = max(1, (int) ($_POST['threshold'] ?? 1));
        $window     = max(5, (int) ($_POST['window_minutes'] ?? 60));
        $cooldown   = max(0, (int) ($_POST['cooldown_min'] ?? 120));
        $minConf    = max(0.0, min(1.0, (float) ($_POST['min_confidence'] ?? 0.5)));
        $recipients = trim((string) ($_POST['recipients'] ?? ''));
        $status     = ($_POST['status'] ?? 'active') === 'paused' ? 'paused' : 'active';

        if ($name === '') {
            $err = t('ui.name') . ' is required.';
        } else {
            if ($id > 0) {
                db_run(
                    "UPDATE alert_rules SET name=?, type=?, keyword_id=?, connector=?, threshold=?,
                            window_minutes=?, min_confidence=?, cooldown_min=?, recipients=?, status=?
                      WHERE id=? AND client_id=?",
                    [$name, $type, $keyword ?: null, $connector, $threshold, $window,
                     $minConf, $cooldown, $recipients, $status, $id, $cid]
                );
            } else {
                db_run(
                    "INSERT INTO alert_rules (client_id, name, type, keyword_id, connector, threshold,
                                              window_minutes, min_confidence, cooldown_min, recipients,
                                              status, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())",
                    [$cid, $name, $type, $keyword ?: null, $connector, $threshold, $window,
                     $minConf, $cooldown, $recipients, $status]
                );
            }
            flash(t('ui.saved'));
            redirect('alerts.php');
        }
    } elseif ($action === 'delete') {
        db_run("DELETE FROM alert_rules WHERE id=? AND client_id=?", [(int) ($_POST['id'] ?? 0), $cid]);
        flash(t('ui.deleted'));
        redirect('alerts.php');
    } elseif ($action === 'mark_read') {
        db_run("UPDATE alerts SET is_read = 1 WHERE client_id = ?", [$cid]);
        redirect('alerts.php');
    }
}

$rules    = db_all("SELECT * FROM alert_rules WHERE client_id = ? ORDER BY id DESC", [$cid]);
$keywords = keywords_active($cid);
$history  = db_all("SELECT * FROM alerts WHERE client_id = ? ORDER BY id DESC LIMIT 50", [$cid]);

$addBtn = '<button class="btn btn-primary" data-modal="m-alert" data-modal-title="' . e(t('al.add')) . '"'
        . ' data-set-id="0" data-set-name="">+ ' . e(t('al.add')) . '</button>';

client_header(t('nav.alerts'), 'alerts', $CLIENT);
page_head(t('al.title'), '', $addBtn);

if ($err !== '') echo '<div class="alert error">' . e($err) . '</div>';
if (trim((string) $CLIENT['alert_email']) === '') {
    echo '<div class="alert warn">' . e(t('st.alert_email')) . ' — '
       . '<a href="settings.php#alerts">' . e(t('nav.settings')) . ' →</a></div>';
}
?>

<div class="card card-flush">
  <div class="card-head"><h2><?= e(t('al.rules')) ?></h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th><?= e(t('ui.name')) ?></th><th><?= e(t('al.type')) ?></th>
            <th><?= e(t('al.threshold')) ?></th><th><?= e(t('ui.status')) ?></th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$rules): ?>
        <tr><td colspan="5"><div class="empty"><?= e(t('ui.empty')) ?></div></td></tr>
      <?php else: foreach ($rules as $r): ?>
        <tr>
          <td><strong><?= e((string) $r['name']) ?></strong></td>
          <td class="text-muted"><?= e(t('al.type.' . $r['type'])) ?></td>
          <td><?= (int) $r['threshold'] ?> / <?= (int) $r['window_minutes'] ?>m</td>
          <td><?= status_pill((string) $r['status']) ?></td>
          <td class="nowrap">
            <button class="btn btn-sm btn-ghost"
              data-modal="m-alert" data-modal-title="<?= e(t('ui.edit')) ?>"
              data-set-id="<?= (int) $r['id'] ?>"
              data-set-name="<?= e((string) $r['name']) ?>"
              data-set-type="<?= e((string) $r['type']) ?>"
              data-set-keyword_id="<?= (int) ($r['keyword_id'] ?? 0) ?>"
              data-set-connector="<?= e((string) $r['connector']) ?>"
              data-set-threshold="<?= (int) $r['threshold'] ?>"
              data-set-window_minutes="<?= (int) $r['window_minutes'] ?>"
              data-set-cooldown_min="<?= (int) $r['cooldown_min'] ?>"
              data-set-min_confidence="<?= e((string) $r['min_confidence']) ?>"
              data-set-recipients="<?= e((string) $r['recipients']) ?>"
              data-set-status="<?= e((string) $r['status']) ?>"
            ><?= e(t('ui.edit')) ?></button>
            <form method="post" style="display:inline" onsubmit="return confirm('<?= e(t('ui.delete')) ?>?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-ghost" type="submit">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card card-flush">
  <div class="card-head">
    <h2><?= e(t('al.history')) ?></h2>
    <?php if ($history): ?>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_read">
        <button class="btn btn-sm btn-ghost" type="submit"><?= e(t('mn.read')) ?></button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (!$history): ?>
    <div class="empty"><?= e(t('al.none')) ?></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <tbody>
        <?php foreach ($history as $a): ?>
          <tr>
            <td style="width:1%" class="nowrap">
              <span class="pill <?= $a['level'] === 'critical' ? 'red' : ($a['level'] === 'warn' ? 'gold' : 'gray') ?>">
                <?= e((string) $a['level']) ?></span>
            </td>
            <td>
              <strong dir="auto"><?= e((string) $a['title']) ?></strong>
              <div class="text-muted" style="font-size:12px;white-space:pre-line" dir="auto"><?= e(excerpt((string) $a['body'], 300)) ?></div>
            </td>
            <td class="nowrap text-muted"><?= e(time_ago((string) $a['created_at'])) ?></td>
            <td class="nowrap">
              <span class="pill <?= $a['email_status'] === 'sent' ? 'green' : ($a['email_status'] === 'failed' ? 'red' : 'gray') ?>">
                <?= e((string) $a['email_status']) ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="modal-back" id="m-alert">
  <form class="modal" method="post" id="alert-form">
    <button type="button" class="modal-x">&times;</button>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="0">
    <h2><?= e(t('al.add')) ?></h2>

    <div class="field">
      <span class="lbl"><?= e(t('ui.name')) ?></span>
      <input type="text" name="name" required>
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('al.type')) ?></span>
      <select name="type">
        <?php foreach (['any_negative', 'negative_spike', 'volume_spike', 'high_reach_negative', 'keyword_match'] as $tp): ?>
          <option value="<?= e($tp) ?>"><?= e(t('al.type.' . $tp)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('kw.term')) ?></span>
        <select name="keyword_id">
          <option value="0"><?= e(t('ui.all')) ?></option>
          <?php foreach ($keywords as $k): ?>
            <option value="<?= (int) $k['id'] ?>"><?= e((string) $k['term']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('src.connector')) ?></span>
        <select name="connector">
          <option value=""><?= e(t('ui.all')) ?></option>
          <?php foreach (listen_connectors() as $ck => $cm): ?>
            <option value="<?= e($ck) ?>"><?= e($cm['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('al.threshold')) ?></span>
        <input type="number" name="threshold" min="1" value="1">
        <span class="hint">Mention count, or a follower/reach floor for the large-account rule.</span>
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('al.window')) ?></span>
        <input type="number" name="window_minutes" min="5" step="5" value="60">
      </div>
    </div>

    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('al.cooldown')) ?></span>
        <input type="number" name="cooldown_min" min="0" step="10" value="120">
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('sent.confidence', ['n' => '≥'])) ?></span>
        <input type="number" name="min_confidence" min="0" max="1" step="0.05" value="0.5">
      </div>
    </div>

    <div class="field">
      <span class="lbl"><?= e(t('al.recipients')) ?></span>
      <input type="text" name="recipients" placeholder="<?= e((string) $CLIENT['alert_email']) ?>">
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('ui.status')) ?></span>
      <select name="status">
        <option value="active"><?= e(t('ui.active')) ?></option>
        <option value="paused"><?= e(t('ui.paused')) ?></option>
      </select>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" id="test-rule">Test against recent data</button>
      <button type="button" class="btn btn-ghost modal-x" style="position:static"><?= e(t('ui.cancel')) ?></button>
      <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
    </div>
  </form>
</div>

<script>
document.getElementById('test-rule').addEventListener('click', function (ev) {
  var f = document.getElementById('alert-form');
  var data = {};
  new FormData(f).forEach(function (v, k) { data[k] = v; });
  data.action = 'test_rule';
  window.api('alerts.php', data, ev.target).then(function (d) { window.showToast(d.message); }).catch(function () {});
});
</script>

<?php layout_footer(); ?>
