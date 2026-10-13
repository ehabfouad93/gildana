<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Owner Services → Contract / welcome call. Every new contract lands here:
 * review it, log each call or WhatsApp with a comment, and mark the welcome
 * call done.
 */
require_cap('owners.welcome');

$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    verify_csrf();
    $ct = db_row("SELECT * FROM contracts WHERE id = ?", [$id]);
    if (!$ct) { http_response_code(404); exit(t('err.not_found')); }
    $back = 'welcome.php?id=' . $id;

    if (($_POST['do'] ?? '') === 'reopen') {
        db_run("UPDATE contracts SET welcome_status = 'pending', welcome_at = NULL, welcome_by = NULL WHERE id = ?", [$id]);
        log_event((int) $ct['client_id'], 'welcome_reopened');
        flash(t('ui.saved'));
        redirect($back);
    }

    $kind    = in_array($_POST['kind'] ?? '', welcome_kinds(), true) ? $_POST['kind'] : 'call';
    $outcome = in_array($_POST['outcome'] ?? '', welcome_outcomes(), true) ? $_POST['outcome'] : '';
    $comment = mb_substr(post_str('comment'), 0, 1000);
    $done    = isset($_POST['complete']);
    if ($outcome === '' && $comment === '' && !$done) {
        flash(t('welcome.err_empty'), 'error');
        redirect($back);
    }
    log_event((int) $ct['client_id'], 'welcome_' . $kind,
        trim(($outcome !== '' ? t('welcome.out.' . $outcome) : '') . ($comment !== '' ? ' — ' . $comment : ''), ' —'));
    if ($done && $ct['welcome_status'] !== 'done') {
        db_run("UPDATE contracts SET welcome_status = 'done', welcome_at = NOW(), welcome_by = ? WHERE id = ?", [$ME['id'], $id]);
        log_event((int) $ct['client_id'], 'welcome_done');
    }
    flash($done ? t('welcome.done_flash') : t('flash.logged'));
    redirect($done ? 'welcome.php' : $back);
}

/* ───────── one contract ───────── */
if ($id):
    $ct = db_row("SELECT ct.*, p.name AS project_name, c.full_name, c.phone AS client_phone, c.phone2,
                         COALESCE(su.name, c.sales_rep_name) AS sales_name, wb.name AS welcome_by_name
                    FROM contracts ct JOIN clients c ON c.id = ct.client_id JOIN projects p ON p.id = ct.project_id
                    LEFT JOIN users su ON su.id = c.sales_id LEFT JOIN users wb ON wb.id = ct.welcome_by
                   WHERE ct.id = ?", [$id]);
    if (!$ct) { http_response_code(404); exit(t('err.not_found')); }
    $totals = contract_totals($id);
    $log    = db_all("SELECT e.*, u.name AS user_name FROM client_events e LEFT JOIN users u ON u.id = e.user_id
                       WHERE e.client_id = ? AND e.action LIKE 'welcome%' ORDER BY e.id DESC", [$ct['client_id']]);
    $phone  = $ct['phone'] ?: $ct['client_phone'];
    $cur    = $ct['currency'];

    layout_header(t('nav.welcome'), 'welcome');
    page_head($ct['official_name'], $ct['contract_no'] . ' · ' . client_code((int) $ct['client_id']),
        '<a class="btn" href="contract.php?id=' . $id . '">' . e(t('contract.view')) . '</a>'
        . '<a class="btn" href="reservation.php?id=' . $id . '">' . e(t('res.statement')) . '</a>'
        . '<a class="btn" href="client.php?id=' . (int) $ct['client_id'] . '">' . e(t('client.profile')) . '</a>'
        . '<a class="btn btn-ghost" href="welcome.php">' . e(t('ui.back')) . '</a>');
    ?>
<div class="grid-main">
<div>
  <div class="card">
    <h2><?= e(t('welcome.review')) ?> <?= welcome_pill($ct['welcome_status']) ?> <?= finance_pill($ct['finance_status']) ?></h2>
    <dl class="dl">
      <?= dl_row(t('client.phone'), '<span dir="ltr">' . e($phone) . '</span> <a class="btn btn-sm" href="tel:' . e(preg_replace('/[^\d+]/', '', $phone)) . '">' . e(t('welcome.call')) . '</a> '
            . '<a class="btn btn-sm" target="_blank" rel="noopener" href="https://wa.me/' . e(wa_phone($phone)) . '">WhatsApp</a>', true) ?>
      <?= dl_row(t('contract.national_id'), $ct['national_id']) ?>
      <?= dl_row(t('contract.project'), $ct['project_name'] . ($ct['unit_type'] ? ' · ' . $ct['unit_type'] : '')) ?>
      <?= dl_row(t('contract.weeks'), t('contract.weeks_line', ['w' => (string) $ct['weeks_per_year'], 'y' => (string) $ct['duration_years'], 'from' => (string) $ct['start_year']])) ?>
      <?= dl_row(t('contract.plan'), t('contract.plan_line', ['pct' => rtrim(rtrim($ct['down_pct'], '0'), '.'), 'down' => money($ct['down_amount'], $cur), 'months' => (string) $ct['months'], 'monthly' => money($ct['monthly_amount'], $cur)])) ?>
      <?= dl_row(t('contract.total'), money($ct['total_amount'], $cur) . ' · ' . t('res.paid') . ' ' . money($totals['paid'], $cur)) ?>
      <?= dl_row(t('contract.date'), fmt_date($ct['contract_date'])) ?>
      <?= dl_row(t('role.sales'), $ct['sales_name']) ?>
      <?php if ($ct['welcome_status'] === 'done'): ?><?= dl_row(t('welcome.done_by'), ($ct['welcome_by_name'] ?? '—') . ' · ' . fmt_dt($ct['welcome_at'])) ?><?php endif; ?>
    </dl>
  </div>
  <div class="card">
    <h2><?= e(t('welcome.log')) ?></h2>
    <?php if (!$log): ?><p class="text-muted"><?= e(t('welcome.log_empty')) ?></p><?php else: ?>
    <ul class="timeline">
      <?php foreach ($log as $ev): ?>
        <li><div class="tl-head"><strong><?= e(t('event.' . $ev['action'])) ?></strong> <span class="text-muted"><?= e($ev['user_name'] ?? '—') ?></span></div>
          <?php if ($ev['note']): ?><div class="tl-note" dir="auto"><?= nl2br(e($ev['note'])) ?></div><?php endif; ?>
          <div class="tl-time"><?= e(fmt_dt($ev['created_at'])) ?></div></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
<div>
  <form method="post" class="card action-card">
    <?= csrf_field() ?>
    <h2><?= e(t('welcome.add_action')) ?></h2>
    <div class="field"><span class="lbl"><?= e(t('welcome.kind')) ?></span>
      <div class="seg"><?php foreach (welcome_kinds() as $k): ?>
        <label class="seg-opt"><input type="radio" name="kind" value="<?= e($k) ?>" <?= $k === 'call' ? 'checked' : '' ?>><span><?= e(t('welcome.kind.' . $k)) ?></span></label>
      <?php endforeach; ?></div></div>
    <div class="field"><span class="lbl"><?= e(t('welcome.outcome')) ?></span>
      <select name="outcome"><?php $o = []; foreach (welcome_outcomes() as $k) $o[$k] = t('welcome.out.' . $k); echo options($o, ''); ?></select></div>
    <div class="field"><span class="lbl"><?= e(t('welcome.comment')) ?></span><textarea name="comment" rows="3"></textarea></div>
    <?php if ($ct['welcome_status'] !== 'done'): ?>
      <label class="check"><input type="checkbox" name="complete" value="1"> <?= e(t('welcome.complete')) ?></label>
    <?php endif; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button></div>
  </form>
  <?php if ($ct['welcome_status'] === 'done'): ?>
  <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="do" value="reopen">
    <button class="btn btn-block" type="submit"><?= e(t('welcome.reopen')) ?></button></form>
  <?php endif; ?>
</div>
</div>
<?php
/* ───────── list ───────── */
else:
    $tab = ($_GET['tab'] ?? '') === 'done' ? 'done' : 'pending';
    $q   = trim((string) ($_GET['q'] ?? ''));
    $where = "ct.welcome_status = ?"; $params = [$tab];
    if ($q !== '') { [$sw, $sp] = client_search_sql($q); $where .= " AND $sw"; $params = array_merge($params, $sp); }
    $rows = db_all("SELECT ct.id, ct.contract_no, ct.contract_date, ct.official_name, ct.welcome_at, ct.finance_status, c.id AS client_id, c.phone,
                           p.name AS project_name, COALESCE(su.name, c.sales_rep_name) AS sales_name,
                           (SELECT COUNT(*) FROM client_events e WHERE e.client_id = c.id AND e.action IN ('welcome_call','welcome_whatsapp','welcome_note')) AS tries
                      FROM contracts ct JOIN clients c ON c.id = ct.client_id JOIN projects p ON p.id = ct.project_id LEFT JOIN users su ON su.id = c.sales_id
                     WHERE $where ORDER BY " . ($tab === 'pending' ? 'ct.contract_date ASC, ct.id ASC' : 'ct.welcome_at DESC') . " LIMIT 500", $params);
    $counts = [];
    foreach (['pending', 'done'] as $k) $counts[$k] = (int) db_val("SELECT COUNT(*) FROM contracts WHERE welcome_status = ?", [$k]);

    layout_header(t('nav.welcome'), 'welcome');
    page_head(t('nav.welcome'), t('welcome.sub'));
    echo tabs(['pending' => ['label' => t('welcome.tab_pending'), 'count' => $counts['pending']], 'done' => ['label' => t('welcome.tab_done'), 'count' => $counts['done']]], $tab);
    ?>
<div class="card card-flush">
  <div class="card-head"><form method="get" class="filter-row"><input type="hidden" name="tab" value="<?= e($tab) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('clients.search')) ?>"><button class="btn btn-sm" type="submit"><?= e(t('ui.search')) ?></button></form></div>
  <?php if (!$rows): ?><div class="empty"><?= e(t('welcome.empty')) ?></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th><?= e(t('contract.no')) ?></th><th><?= e(t('contract.official_name')) ?></th><th><?= e(t('contract.project')) ?></th>
      <th><?= e(t('role.sales')) ?></th><th><?= e($tab === 'pending' ? t('contract.date') : t('welcome.done_at')) ?></th><th><?= e(t('welcome.tries')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono nowrap"><?= e($r['contract_no']) ?><div class="small text-muted"><?= e(client_code((int) $r['client_id'])) ?></div></td>
        <td><strong><?= e($r['official_name']) ?></strong><div class="small text-muted" dir="ltr"><?= e($r['phone']) ?></div></td>
        <td><?= e($r['project_name']) ?></td>
        <td><?= e($r['sales_name'] ?? '—') ?></td>
        <td class="nowrap"><?= e($tab === 'pending' ? fmt_date($r['contract_date']) : fmt_dt($r['welcome_at'])) ?></td>
        <td><?= (int) $r['tries'] ?></td>
        <td><a class="btn btn-sm <?= $tab === 'pending' ? 'btn-primary' : '' ?>" href="welcome.php?id=<?= (int) $r['id'] ?>"><?= e(t('ui.open')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif;
layout_footer();
