<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid   = (int) $CLIENT['id'];
$terms = array_column(keywords_active($cid), 'term');

/* ── filters ── */
$fSentiment = (string) ($_GET['sentiment'] ?? '');
$fConnector = (string) ($_GET['connector'] ?? '');
$fKeyword   = (int) ($_GET['keyword'] ?? 0);
$fState     = (string) ($_GET['state'] ?? '');       // unread|starred
$fFrom      = (string) ($_GET['from'] ?? '');
$fTo        = (string) ($_GET['to'] ?? '');
$q          = trim((string) ($_GET['q'] ?? ''));

$where  = 'm.client_id = ? AND m.is_hidden = 0';
$params = [$cid];

if (in_array($fSentiment, ['positive', 'negative', 'neutral', 'unknown'], true)) {
    $where .= ' AND m.sentiment = ?';
    $params[] = $fSentiment;
}
if ($fConnector !== '' && listen_connector($fConnector)) {
    $where .= ' AND m.connector = ?';
    $params[] = $fConnector;
}
if ($fKeyword > 0) { $where .= ' AND m.keyword_id = ?'; $params[] = $fKeyword; }
if ($fState === 'unread')  $where .= ' AND m.is_read = 0';
if ($fState === 'starred') $where .= ' AND m.is_starred = 1';
if ($fFrom !== '') { $where .= ' AND COALESCE(m.published_at, m.fetched_at) >= ?'; $params[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')   { $where .= ' AND COALESCE(m.published_at, m.fetched_at) <= ?'; $params[] = $fTo . ' 23:59:59'; }

if ($q !== '') {
    // Search the normalized form so Arabic hamza/ta-marbuta variants still match.
    $where .= ' AND (m.title LIKE ? OR m.content LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

/* ── CSV export of exactly what is on screen ── */
if (($_GET['export'] ?? '') === '1') {
    $rows = db_all(
        "SELECT m.* FROM mentions m WHERE $where ORDER BY COALESCE(m.published_at, m.fetched_at) DESC LIMIT 5000",
        $params
    );
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="mentions-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";   // BOM so Excel opens Arabic correctly
    echo "published_at,source,domain,author,title,sentiment,score,confidence,method,reach,url\n";
    foreach ($rows as $r) {
        echo csv_cell((string) ($r['published_at'] ?: $r['fetched_at'])) . ','
           . csv_cell((string) $r['connector']) . ','
           . csv_cell((string) $r['domain']) . ','
           . csv_cell((string) $r['author_name']) . ','
           . csv_cell((string) ($r['title'] ?: $r['snippet'])) . ','
           . csv_cell((string) $r['sentiment']) . ','
           . csv_cell((string) $r['sentiment_score']) . ','
           . csv_cell((string) $r['sentiment_confidence']) . ','
           . csv_cell((string) $r['sentiment_method']) . ','
           . csv_cell((string) $r['reach']) . ','
           . csv_cell((string) $r['url']) . "\n";
    }
    exit;
}

/* ── page ── */
$page  = max(1, (int) ($_GET['page'] ?? 1));
$per   = 50;
$off   = ($page - 1) * $per;
$total = (int) db_val("SELECT COUNT(*) FROM mentions m WHERE $where", $params);
$pages = (int) max(1, ceil($total / $per));

$rows = db_all(
    "SELECT m.* FROM mentions m WHERE $where
      ORDER BY COALESCE(m.published_at, m.fetched_at) DESC
      LIMIT $per OFFSET $off",
    $params
);

$keywords   = keywords_active($cid);
$connectors = db_all("SELECT DISTINCT connector FROM mentions WHERE client_id = ? ORDER BY connector", [$cid]);

$exportQuery = $_GET;
$exportQuery['export'] = '1';
unset($exportQuery['page']);
$actions = '<a class="btn btn-sm" href="?' . e(http_build_query($exportQuery)) . '">' . e(t('ui.export_csv')) . '</a>';

client_header(t('nav.mentions'), 'mentions', $CLIENT);
page_head(t('mn.feed'), t('mn.count', ['n' => fmt_num($total)]), $actions);
?>

<div class="card">
  <form method="get" class="filter-row">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('mn.search_ph')) ?>" style="min-width:220px">

    <select name="sentiment" data-autosubmit>
      <option value=""><?= e(t('ui.all')) ?></option>
      <?php foreach (['positive', 'negative', 'neutral', 'unknown'] as $s): ?>
        <option value="<?= e($s) ?>" <?= $fSentiment === $s ? 'selected' : '' ?>><?= e(t('sent.' . $s)) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="connector" data-autosubmit>
      <option value=""><?= e(t('src.connector')) ?>: <?= e(t('ui.all')) ?></option>
      <?php foreach ($connectors as $c):
        $meta = listen_connector((string) $c['connector']); ?>
        <option value="<?= e((string) $c['connector']) ?>" <?= $fConnector === (string) $c['connector'] ? 'selected' : '' ?>>
          <?= e($meta['label'] ?? (string) $c['connector']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="keyword" data-autosubmit>
      <option value="0"><?= e(t('kw.term')) ?>: <?= e(t('ui.all')) ?></option>
      <?php foreach ($keywords as $k): ?>
        <option value="<?= (int) $k['id'] ?>" <?= $fKeyword === (int) $k['id'] ? 'selected' : '' ?>><?= e((string) $k['term']) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="state" data-autosubmit>
      <option value=""><?= e(t('ui.all')) ?></option>
      <option value="unread"  <?= $fState === 'unread'  ? 'selected' : '' ?>><?= e(t('mn.unread_only')) ?></option>
      <option value="starred" <?= $fState === 'starred' ? 'selected' : '' ?>><?= e(t('mn.starred_only')) ?></option>
    </select>

    <input type="date" name="from" value="<?= e($fFrom) ?>">
    <input type="date" name="to"   value="<?= e($fTo) ?>">
    <button class="btn btn-sm" type="submit"><?= e(t('ui.filter')) ?></button>
    <a class="btn btn-sm btn-ghost" href="mentions.php"><?= e(t('ui.all')) ?></a>
  </form>
</div>

<div class="card card-flush">
  <?php if (!$rows): ?>
    <div class="empty"><?= e(t('mn.none')) ?></div>
  <?php else: ?>
    <?php foreach ($rows as $m) { echo mention_card($m, $terms); } ?>
    <?php
    $pagerQuery = $_GET;
    unset($pagerQuery['page']);
    echo pager($page, $pages, $pagerQuery);
    ?>
  <?php endif; ?>
</div>

<?php layout_footer(); ?>
