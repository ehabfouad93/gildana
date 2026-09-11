<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $id       = (int) ($_POST['id'] ?? 0);
        $term     = trim((string) ($_POST['term'] ?? ''));
        $kind     = (string) ($_POST['kind'] ?? 'keyword');
        $mode     = (string) ($_POST['match_mode'] ?? 'phrase');
        $required = keyword_list_encode((string) ($_POST['required'] ?? ''));
        $excluded = keyword_list_encode((string) ($_POST['excluded'] ?? ''));
        $lang     = (string) ($_POST['lang'] ?? '');
        $country  = (string) ($_POST['country'] ?? '');
        $status   = ($_POST['status'] ?? 'active') === 'paused' ? 'paused' : 'active';

        if (!in_array($kind, ['brand', 'competitor', 'keyword', 'hashtag'], true)) $kind = 'keyword';
        if (!in_array($mode, ['phrase', 'all_words', 'exact'], true)) $mode = 'phrase';

        if ($term === '') {
            $err = t('kw.need_term');
        } else {
            try {
                if ($id > 0) {
                    db_run(
                        "UPDATE keywords SET term=?, kind=?, match_mode=?, required_json=?, excluded_json=?,
                                lang=?, country=?, status=?
                          WHERE id=? AND client_id=?",
                        [$term, $kind, $mode, $required, $excluded, $lang, $country, $status, $id, $cid]
                    );
                } else {
                    db_run(
                        "INSERT INTO keywords (client_id, term, kind, match_mode, required_json, excluded_json,
                                               lang, country, status, created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,NOW())",
                        [$cid, $term, $kind, $mode, $required, $excluded, $lang, $country, $status]
                    );
                }
                flash(t('ui.saved'));
                redirect('keywords.php');
            } catch (PDOException $ex) {
                $err = str_contains($ex->getMessage(), 'uq_client_term') ? t('kw.dupe') : t('ui.error');
                if (!str_contains($ex->getMessage(), 'uq_client_term')) {
                    error_log('keyword save failed: ' . $ex->getMessage());
                }
            }
        }
    } elseif ($action === 'delete') {
        db_run("DELETE FROM keywords WHERE id=? AND client_id=?", [(int) ($_POST['id'] ?? 0), $cid]);
        flash(t('ui.deleted'));
        redirect('keywords.php');
    }
}

$keywords = db_all(
    "SELECT k.*,
            (SELECT COUNT(*) FROM mentions m WHERE m.keyword_id = k.id AND m.is_hidden = 0) AS mention_count
       FROM keywords k WHERE k.client_id = ?
      ORDER BY FIELD(k.kind,'brand','competitor','hashtag','keyword'), k.term",
    [$cid]
);

$addBtn = '<button class="btn btn-primary" data-modal="m-kw" data-modal-title="' . e(t('kw.add')) . '"'
        . ' data-set-id="0" data-set-term="" data-set-required="" data-set-excluded="">+ ' . e(t('kw.add')) . '</button>';

client_header(t('nav.keywords'), 'keywords', $CLIENT);
page_head(t('kw.title'), t('kw.sub'), $addBtn);

if ($err !== '') echo '<div class="alert error">' . e($err) . '</div>';
?>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th><?= e(t('kw.term')) ?></th>
          <th><?= e(t('kw.kind')) ?></th>
          <th><?= e(t('kw.match')) ?></th>
          <th><?= e(t('nav.mentions')) ?></th>
          <th><?= e(t('ui.status')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$keywords): ?>
        <tr><td colspan="6"><div class="empty"><?= e(t('ui.empty')) ?></div></td></tr>
      <?php else: foreach ($keywords as $k): ?>
        <tr>
          <td><strong dir="auto"><?= e((string) $k['term']) ?></strong></td>
          <td><span class="pill gray"><?= e(t('kw.kind.' . $k['kind'])) ?></span></td>
          <td class="text-muted"><?= e(t('kw.match.' . $k['match_mode'])) ?></td>
          <td><?= e(fmt_num((int) $k['mention_count'])) ?></td>
          <td><?= status_pill((string) $k['status']) ?></td>
          <td class="nowrap">
            <button class="btn btn-sm btn-ghost"
              data-modal="m-kw"
              data-modal-title="<?= e(t('ui.edit')) ?>"
              data-set-id="<?= (int) $k['id'] ?>"
              data-set-term="<?= e((string) $k['term']) ?>"
              data-set-kind="<?= e((string) $k['kind']) ?>"
              data-set-match_mode="<?= e((string) $k['match_mode']) ?>"
              data-set-lang="<?= e((string) $k['lang']) ?>"
              data-set-country="<?= e((string) $k['country']) ?>"
              data-set-status="<?= e((string) $k['status']) ?>"
              data-set-required="<?= e(implode("\n", keyword_list($k, 'required_json'))) ?>"
              data-set-excluded="<?= e(implode("\n", keyword_list($k, 'excluded_json'))) ?>"
            ><?= e(t('ui.edit')) ?></button>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('<?= e(t('ui.delete')) ?>?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
              <button class="btn btn-sm btn-ghost" type="submit"><?= e(t('ui.delete')) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back" id="m-kw">
  <form class="modal" method="post">
    <button type="button" class="modal-x">&times;</button>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="0">
    <h2><?= e(t('kw.add')) ?></h2>

    <div class="field">
      <span class="lbl"><?= e(t('kw.term')) ?></span>
      <input type="text" name="term" required dir="auto">
    </div>

    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('kw.kind')) ?></span>
        <select name="kind">
          <?php foreach (['brand', 'competitor', 'keyword', 'hashtag'] as $k): ?>
            <option value="<?= e($k) ?>"><?= e(t('kw.kind.' . $k)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('kw.match')) ?></span>
        <select name="match_mode">
          <?php foreach (['phrase', 'all_words', 'exact'] as $m): ?>
            <option value="<?= e($m) ?>"><?= e(t('kw.match.' . $m)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field">
      <span class="lbl"><?= e(t('kw.required')) ?></span>
      <textarea name="required" rows="2" dir="auto"></textarea>
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('kw.excluded')) ?></span>
      <textarea name="excluded" rows="2" dir="auto"></textarea>
      <span class="hint">Use this to cut noise — a common word that keeps pulling in unrelated results.</span>
    </div>

    <div class="grid2">
      <div class="field">
        <span class="lbl"><?= e(t('kw.lang')) ?></span>
        <select name="lang">
          <option value=""><?= e(t('ui.all')) ?></option>
          <option value="ar">العربية</option>
          <option value="en">English</option>
        </select>
      </div>
      <div class="field">
        <span class="lbl"><?= e(t('ui.status')) ?></span>
        <select name="status">
          <option value="active"><?= e(t('ui.active')) ?></option>
          <option value="paused"><?= e(t('ui.paused')) ?></option>
        </select>
      </div>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn btn-ghost modal-x" style="position:static"><?= e(t('ui.cancel')) ?></button>
      <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
    </div>
  </form>
</div>

<?php layout_footer(); ?>
