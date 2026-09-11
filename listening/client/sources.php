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

            // Only keys this connector declares are read, so a field belonging to a
            // different connector can never be posted in and stored.
            $cfg = [];
            foreach (($meta['config'] ?? []) as $key => $spec) {
                $v = trim((string) ($_POST['cfg_' . $key] ?? ''));
                if ($v === '' && !empty($spec['required'])) {
                    $err = connector_field_label($connector, (string) $key, (string) $spec['label'])
                         . ' — ' . t('kw.need_term');
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

$sources     = db_all("SELECT * FROM sources WHERE client_id = ? ORDER BY connector, id", [$cid]);
$keywords    = keywords_active($cid);
$byConnector = [];
foreach ($sources as $s) { $byConnector[(string) $s['connector']][] = $s; }

client_header(t('nav.sources'), 'sources', $CLIENT);
page_head(t('src.title'), t('src.sub'));

if ($err !== '') echo '<div class="alert error">' . e($err) . '</div>';
if (!$keywords) {
    echo '<div class="alert warn">' . e(t('kw.need_term'))
       . ' <a href="keywords.php">' . e(t('kw.add')) . ' →</a></div>';
}

/** The shared field rows for every connector's form. */
function source_common_fields(array $keywords, array $meta): void
{ ?>
    <div class="field">
      <span class="lbl"><?= e(t('ui.name')) ?></span>
      <input type="text" name="label" placeholder="<?= e(connector_label((string) $meta['id'])) ?>">
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
        <input type="number" name="fetch_interval_min"
               min="<?= (int) $meta['min_interval'] ?>" step="5"
               value="<?= (int) $meta['default_interval'] ?>">
        <span class="hint"><?= e(t('src.interval')) ?> ≥ <?= (int) $meta['min_interval'] ?> <?= e(t('src.minutes')) ?></span>
      </div>
    </div>
<?php }
?>

<div class="conn-grid">
<?php foreach (listen_connectors() as $id => $meta):
    $meta['id'] = $id;
    $ready   = listen_connector_ready($id, $CLIENT);
    $missing = connector_missing_labels($id, $CLIENT);
    $mine    = $byConnector[$id] ?? [];
    $steps   = connector_steps($id);
    $help    = connector_help($id);
    $tierCls = $meta['tier'] === 'paid' ? 'gold' : ($meta['tier'] === 'official' ? 'gray' : 'green');
    $tierLbl = $meta['tier'] === 'paid' ? t('src.paid') : ($meta['tier'] === 'official' ? t('src.official') : t('src.free'));
?>
  <div class="card conn-card">
    <div class="conn-head">
      <h2><?= e(connector_label($id)) ?></h2>
      <div class="conn-pills">
        <span class="pill <?= $tierCls ?>"><?= e($tierLbl) ?></span>
        <?php if ($meta['needs']): ?>
          <?= $ready ? status_pill('ready') : '<span class="pill red">' . e(t('src.not_ready')) . '</span>' ?>
        <?php else: ?>
          <span class="pill green"><?= e(t('src.free_note')) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <p class="conn-why"><?= e(connector_why($id)) ?></p>
    <p class="conn-caveat">⚠ <?= e(connector_caveat($id)) ?></p>

    <?php /* Say exactly what is missing, in words, with somewhere to go. */ ?>
    <?php if (!$ready && $missing): ?>
      <div class="alert error" style="margin:12px 0">
        <?= e(t('src.missing', ['what' => implode('، ', $missing)])) ?>
        <a href="settings.php#sources"><?= e(t('src.missing_go')) ?></a>
      </div>
    <?php endif; ?>

    <?php if ($steps): ?>
      <details class="conn-setup"<?= $ready ? '' : ' open' ?>>
        <summary><?= e(t('src.setup')) ?></summary>
        <ol>
          <?php foreach ($steps as $stp): ?><li><?= e($stp) ?></li><?php endforeach; ?>
        </ol>
        <?php if ($help): ?>
          <a class="btn btn-sm" href="<?= e($help['url']) ?>" target="_blank" rel="noopener noreferrer">
            <?= e($help['label']) ?> ↗</a>
        <?php endif; ?>
      </details>
    <?php endif; ?>

    <div class="conn-instances">
      <div class="conn-instances-head"><?= e(t('src.instances')) ?></div>
      <?php if (!$mine): ?>
        <p class="text-muted" style="font-size:12.5px"><?= e(t('src.none_yet')) ?></p>
      <?php else: ?>
        <?php foreach ($mine as $s): $cfg = source_config($s); ?>
          <div class="conn-row">
            <div>
              <strong><?= e(source_label($s)) ?></strong>
              <div class="text-muted" style="font-size:11.5px">
                <?= e(t('src.interval')) ?> <?= (int) $s['fetch_interval_min'] ?> <?= e(t('src.minutes')) ?>
                · <?= e(t('src.last_fetch')) ?>: <?= e(time_ago((string) $s['last_ok_at'])) ?>
              </div>
              <?php if ((string) $s['last_error'] !== ''): ?>
                <div style="font-size:11.5px;color:var(--danger)"><?= e(excerpt((string) $s['last_error'], 140)) ?></div>
              <?php endif; ?>
            </div>
            <div class="conn-row-actions">
              <?= status_pill((string) $s['status'] === 'active' ? (string) $s['last_status'] : (string) $s['status']) ?>
              <button class="btn btn-sm btn-ghost" data-fetch-source="<?= (int) $s['id'] ?>"><?= e(t('src.fetch_now')) ?></button>
              <button class="btn btn-sm btn-ghost"
                data-modal="m-src-<?= e($id) ?>"
                data-modal-title="<?= e(t('ui.edit')) ?>"
                data-set-id="<?= (int) $s['id'] ?>"
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
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <button class="btn btn-sm <?= $ready ? 'btn-primary' : '' ?>" style="margin-top:12px"
      data-modal="m-src-<?= e($id) ?>"
      data-modal-title="<?= e(connector_label($id)) ?>"
      data-set-id="0"
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

<?php
/* ── One modal per connector.
      The previous version rendered every connector's fields into a single modal
      and hid the irrelevant ones with JavaScript, which meant the same edit window
      for all eight sources and no way to tell which field belonged to what. Each
      connector now owns its form and shows only its own fields. ── */
foreach (listen_connectors() as $id => $meta):
    $meta['id'] = $id; ?>
<div class="modal-back" id="m-src-<?= e($id) ?>">
  <form class="modal" method="post">
    <button type="button" class="modal-x">&times;</button>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="0">
    <input type="hidden" name="connector" value="<?= e($id) ?>">
    <h2><?= e(connector_label($id)) ?></h2>

    <?php source_common_fields($keywords, $meta); ?>

    <?php foreach (($meta['config'] ?? []) as $key => $spec): ?>
      <div class="field">
        <span class="lbl"><?= e(connector_field_label($id, (string) $key, (string) $spec['label'])) ?><?= !empty($spec['required']) ? ' *' : '' ?></span>
        <?php if (($spec['type'] ?? 'text') === 'select'): ?>
          <select name="cfg_<?= e($key) ?>">
            <?php foreach (($spec['options'] ?? []) as $ov => $ol): ?>
              <option value="<?= e((string) $ov) ?>"><?= e((string) $ol) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" name="cfg_<?= e($key) ?>"
                 placeholder="<?= e((string) ($spec['placeholder'] ?? '')) ?>"
                 <?= !empty($spec['required']) ? 'required' : '' ?>>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php /* The RSS form is the one where people get stuck hunting for a URL. */ ?>
    <?php if ($id === 'rss'): ?>
      <div class="presets">
        <div class="presets-head"><?= e(t('src.presets')) ?>
          <span class="text-muted"><?= e(t('src.presets_hint')) ?></span></div>
        <?php foreach (rss_presets() as $group => $feeds): ?>
          <div class="presets-group"><?= e($group) ?></div>
          <div class="presets-row">
            <?php foreach ($feeds as $name => $url): ?>
              <button type="button" class="chip" data-preset="<?= e($url) ?>"
                      data-preset-name="<?= e($name) ?>"><?= e($name) ?></button>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

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
<?php endforeach; ?>

<script>
/* Clicking a ready-made feed fills the address in, and names the source after the
   outlet so the list stays readable when a client adds six of them. */
document.addEventListener('click', function (ev) {
  var chip = ev.target.closest('[data-preset]');
  if (!chip) return;
  ev.preventDefault();
  var form = chip.closest('form');
  if (!form) return;
  var url = form.querySelector('[name="cfg_feed_url"]');
  if (url) url.value = chip.getAttribute('data-preset');
  var label = form.querySelector('[name="label"]');
  if (label && !label.value) label.value = chip.getAttribute('data-preset-name') || '';
  form.querySelectorAll('[data-preset]').forEach(function (c) { c.classList.remove('on'); });
  chip.classList.add('on');
});
</script>

<?php layout_footer(); ?>
