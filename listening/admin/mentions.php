<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/** Cross-client firehose — read-only triage and a way to spot-check classification quality. */

$clientFilter = (int) ($_GET['client'] ?? 0);
$sentiment    = (string) ($_GET['sentiment'] ?? '');
$q            = trim((string) ($_GET['q'] ?? ''));

$where  = 'm.is_hidden = 0';
$params = [];
if ($clientFilter > 0) { $where .= ' AND m.client_id = ?'; $params[] = $clientFilter; }
if (in_array($sentiment, ['positive', 'negative', 'neutral', 'unknown'], true)) {
    $where .= ' AND m.sentiment = ?';
    $params[] = $sentiment;
}
if ($q !== '') {
    $norm = sent_normalize($q);
    $where .= ' AND (m.search_text LIKE ? OR m.title LIKE ? OR m.content LIKE ?)';
    $params[] = '%' . $norm . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$page  = max(1, (int) ($_GET['page'] ?? 1));
$per   = 50;
$off   = ($page - 1) * $per;
$total = (int) db_val("SELECT COUNT(*) FROM mentions m WHERE $where", $params);
$pages = (int) max(1, ceil($total / $per));

$rows = db_all(
    "SELECT m.*, c.name AS client_name
       FROM mentions m JOIN clients c ON c.id = m.client_id
      WHERE $where
      ORDER BY COALESCE(m.published_at, m.fetched_at) DESC
      LIMIT $per OFFSET $off",
    $params
);

$clients = db_all("SELECT id, name FROM clients ORDER BY name");

layout_header(t('nav.mentions'), 'admin', 'mentions');
page_head(t('nav.mentions'), t('mn.count', ['n' => fmt_num($total)]));
?>

<div class="card">
  <form method="get" class="filter-row">
    <select name="client" data-autosubmit>
      <option value="0"><?= e(t('ui.all')) ?> <?= e(t('nav.clients')) ?></option>
      <?php foreach ($clients as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $clientFilter === (int) $c['id'] ? 'selected' : '' ?>>
          <?= e((string) $c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sentiment" data-autosubmit>
      <option value=""><?= e(t('ui.all')) ?></option>
      <?php foreach (['positive', 'negative', 'neutral', 'unknown'] as $s): ?>
        <option value="<?= e($s) ?>" <?= $sentiment === $s ? 'selected' : '' ?>><?= e(t('sent.' . $s)) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('mn.search_ph')) ?>" style="min-width:200px">
    <button class="btn btn-sm" type="submit"><?= e(t('ui.filter')) ?></button>
  </form>
</div>

<div class="card card-flush">
  <?php if (!$rows): ?>
    <div class="empty"><?= e(t('mn.none')) ?></div>
  <?php else: ?>
    <?php foreach ($rows as $m): ?>
      <div style="padding-inline:18px;padding-top:10px">
        <a class="pill gold" href="client.php?id=<?= (int) $m['client_id'] ?>"><?= e((string) $m['client_name']) ?></a>
      </div>
      <?= mention_card($m, [], true) ?>
    <?php endforeach; ?>
    <?php
    $pagerQuery = $_GET;
    unset($pagerQuery['page']);
    echo pager($page, $pages, $pagerQuery);
    ?>
  <?php endif; ?>
</div>

<?php layout_footer(); ?>
