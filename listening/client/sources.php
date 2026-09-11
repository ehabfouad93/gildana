<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $id        = (int) ($_POST['id'] ?? 0);
        $connector = (string) ($_POST['connector'] ?? '');
        $meta      = listen_connector($connector);

        if (!$meta) {
            $err = 'Unknown connector.';
        } else {
            $label    = trim((string) ($_POST['label'] ?? ''));
            $keyword  = (int) ($_POST['keyword_id'] ?? 0);
            $interval = max((int) $meta['min_interval'], (int) ($_POST['fetch_interval_min'] ?? $meta['default_interval']));
            $status   = ($_POST['status'] ?? 'active') === 'paused' ? 'paused' : 'active';

            // Only keys the registry declares are stored — a posted field that
            // isn't part of this connector's schema is ignored, not persisted.
            $cfg = [];
            foreach (($meta['config'] ?? []) as $key => $spec) {
                $v = trim((string) ($_POST['cfg_' . $key] ?? ''));
                if ($v === '' && !empty($spec['required'])) {
                    $err = $spec['label'] . ' is required.';
                }
                $cfg[$key] = $v !== '' ? $v : (string) ($spec['default'] ?? '');
            }

            if ($err === '') {
                $cfgJson = $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : null;
                if ($id > 0) {
                    db_run(
                        "UPDATE sources SET label=?, keyword_id=?, config_json=?, fetch_interval_min=?, status=?
                          WHERE id=? AND client_id=?",
                        [$label, $keyword ?: null, $cfgJson, $interval, $status, $id, $cid]
                    );
                } else {
                    db_run(
                        "INSERT INTO sources (client_id, connector, label, keyword_id, config_json,
                                              fetch_interval_min, next_fetch_at, status, created_at)
                         VALUES (?,?,?,?,?,?,NOW(),?,NOW())",
                        [$cid, $connector, $label, $keyword ?: null, $cfgJson, $interval, $status]
                    );
                }
                flash(t('ui.saved'));
                redirect('sources.php');
            }
        }
    } elseif ($action === 'delete') {
        db_run("DELETE FROM sources WHERE id=? AND client_id=?", [(int) ($_POST['id'] ?? 0), $cid]);
        flash(t('ui.deleted'));
        redirect('sources.php');
    }
}

$sources    = db_all("SELECT * FROM sources WHERE client_id = ? ORDER BY connector, id", [$cid]);
$keywords   = keywords_active($cid);
$byConnector = [];
foreach ($sources as $s) { $byConnector[(string) $s['connector']][] = $s; }

client_header(t('nav.sources'), 'sources', $CLIENT);
page_head(t('src.title'), t('src.sub'));

if ($err !== '') echo '<div class="alert error">' . e($err) . '</div>';
if (!$keywords) {
    echo '<div class="alert warn">' . e(t('kw.need_term'))
       . ' <a href="keywords.php">' . e(t('kw.add')) . ' →</a></div>';
}
?>

<div class="conn-grid">
<?php foreach (listen_connectors() as $id => $meta):
    $ready   = listen_connector_ready($id, $CLIENT);
    $missing = listen_connector_missing($id, $CLIENT);
    $mine    = $byConnector[$id] ?? [];
    $tierCls = $meta['tier'] === 'paid' ? 'gold' : ($meta['tier'] === 'official' ? 'gray' : 'green');
    $tierLbl = $meta['tier'] === 'paid' ? t('src.paid') : ($meta['tier'] === 'official' ? t('src.official') : t('src.free'));
?>
  <div class="card">
    <div class="row-between" style="display:flex;align-items:flex-start;gap:10px;justify-content:space-between">
      <div>
        <h2 style="margin-bottom:4px"><?= e($meta['label']) ?></h2>
        <span class="pill <?= $tierCls ?>"><?= e($tierLbl) ?></span>
        <?= $ready ? status_pill('ready') : '<span class="pill gray">' . e(t('src.not_ready')) . '</span>' ?>
      </div>
    </div>

    <p class="text-muted" style="font-size:12.5px;margin:10px 0"><?= e($meta['why']) ?></p>
    <p class="text-muted" style="font-size:11.5px">⚠ <?= e($meta['caveat']) ?></p>

    <?php if (!$ready): ?>
      <p style="font-size:12px;margin-top:10px">
        <a href="settings.php"><?= e(t('src.not_ready')) ?>: <?= e(implode(', ', $missing)) ?> →</a>
      </p>
    <?php endif; ?>

    <?php if ($mine): ?>
      <div class="table-wrap" style="margin-top:12px">
        <table class="data">
          <tbody>
          <?php foreach ($mine as $s):
            $cfg = source_config($s); ?>
            <tr>
              <td>
                <strong><?= e(source_label($s)) ?></strong><br>
                <span class="text-muted" style="font-size:11.5px">
                  <?= e(t('src.interval')) ?> <?= (int) $s['fetch_interval_min'] ?> <?= e(t('src.minutes')) ?>
                  · <?= e(t('src.last_fetch')) ?>: <?= e(time_ago((string) $s['last_ok_at'])) ?>
                </span>
                <?php if ((string) $s['last_error'] !== ''): ?>
                  <br><span class="text-muted" style="font-size:11.5px;color:var(--danger)">
                    <?= e(excerpt((string) $s['last_error'], 120)) ?></span>
                <?php endif; ?>
              </td>
              <td class="nowrap"><?= status_pill((string) $s['status'] === 'active' ? (string) $s['last_status'] : (string) $s['status']) ?></td>
              <td class="nowrap">
                <button class="btn btn-sm btn-ghost" data-fetch-source="<?= (int) $s['id'] ?>"><?= e(t('src.fetch_now')) ?></button>
                <button class="btn btn-sm btn-ghost"
                  data-modal="m-src"
                  data-modal-title="<?= e(t('ui.edit')) ?>"
                  data-set-id="<?= (int) $s['id'] ?>"
                  data-set-connector="<?= e($id) ?>"
                  data-set-label="<?= e((string) $s['label']) ?>"
                  data-set-keyword_id="<?= (int) ($s['keyword_id'] ?? 0) ?>"
                  data-set-fetch_interval_min="<?= (int) $s['fetch_interval_min'] ?>"
                  data-set-status="<?= e((string) $s['status']) ?>"
                  <?php foreach ($cfg as $k => $v): ?>data-set-cfg_<?= e($k) ?>="<?= e((string) $v) ?>" <?php endforeach; ?>
                ><?= e(t('ui.edit')) ?></button>
                <form method="post" style="display:inline" onsubmit="return confirm('<?= e(t('ui.delete')) ?>?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <button class="btn btn-sm btn-ghost" type="submit">✕</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <button class="btn btn-sm" style="margin-top:12px"
      data-modal="m-src"
      data-modal-title="<?= e($meta['label']) ?>"
      data-set-id="0"
      data-set-connector="<?= e($id) ?>"
      data-set-label=""
      data-set-keyword_id="0"
      data-set-fetch_interval_min="<?= (int) $meta['default_interval'] ?>"
      data-set-status="active"
      <?php foreach (($meta['config'] ?? []) as $k => $spec): ?>data-set-cfg_<?= e($k) ?>="<?= e((string) ($spec['default'] ?? '')) ?>" <?php endforeach; ?>
    >+ <?= e(t('src.add')) ?></button>
  </div>
<?php endforeach; ?>
</div>

<div class="card">
  <h2><?= e(t('src.coverage')) ?></h2>
  <p class="text-muted" style="font-size:12.5px"><?= e(t('src.coverage_note')) ?></p>
</div>

<div class="modal-back" id="m-src">
  <form class="modal" method="post">
    <button type="button" class="modal-x">&times;</button>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="0">
    <input type="hidden" name="connector" value="">
    <h2><?= e(t('src.add')) ?></h2>

    <div class="field">
      <span class="lbl"><?= e(t('ui.name')) ?></span>
      <input type="text" name="label" placeholder="<?= e(t('src.connector')) ?>">
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
        <span class="lbl"><?= e(t('src.interval')) ?> (<?= e(t('src.minutes')) ?>)</span>
        <input type="number" name="fetch_interval_min" min="15" step="5" value="30">
      </div>
    </div>

    <?php
    /* One input per distinct config key, tagged with every connector that uses
       it, so two connectors sharing a key name still both work. The JS shows
       only the fields for the connector being edited; unknown keys are dropped
       on save by the registry loop above. */
    $fields = [];
    foreach (listen_connectors() as $cid2 => $cmeta) {
        foreach (($cmeta['config'] ?? []) as $key => $spec) {
            if (!isset($fields[$key])) $fields[$key] = ['spec' => $spec, 'for' => []];
            $fields[$key]['for'][] = $cid2;
        }
    }
    foreach ($fields as $key => $f):
        $spec = $f['spec']; ?>
      <div class="field" data-cfg-for="<?= e(implode(' ', $f['for'])) ?>">
        <span class="lbl"><?= e($spec['label']) ?></span>
        <?php if (($spec['type'] ?? 'text') === 'select'): ?>
          <select name="cfg_<?= e($key) ?>">
            <?php foreach (($spec['options'] ?? []) as $ov => $ol): ?>
              <option value="<?= e((string) $ov) ?>"><?= e((string) $ol) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" name="cfg_<?= e($key) ?>" placeholder="<?= e((string) ($spec['placeholder'] ?? '')) ?>">
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="field">
      <span class="lbl"><?= e(t('ui.status')) ?></span>
      <select name="status">
        <option value="active"><?= e(t('ui.active')) ?></option>
        <option value="paused"><?= e(t('ui.paused')) ?></option>
      </select>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn btn-ghost modal-x" style="position:static"><?= e(t('ui.cancel')) ?></button>
      <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
    </div>
  </form>
</div>

<script>
/* Show only the config fields that belong to the connector being edited. */
document.addEventListener('click', function (ev) {
  var opener = ev.target.closest('[data-modal="m-src"]');
  if (!opener) return;
  var conn = opener.getAttribute('data-set-connector') || '';
  document.querySelectorAll('#m-src [data-cfg-for]').forEach(function (el) {
    var owners = (el.getAttribute('data-cfg-for') || '').split(' ');
    el.hidden = owners.indexOf(conn) === -1;
  });
});
</script>

<?php layout_footer(); ?>
