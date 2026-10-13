<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Finance → Contract review. Each new contract waits here until Finance
 * approves it, or returns it to sales with a note. A returned contract comes
 * back to this queue once it is corrected.
 */
require_cap('contracts.review');

$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    verify_csrf();
    $ct = db_row("SELECT * FROM contracts WHERE id = ?", [$id]);
    if (!$ct) { http_response_code(404); exit(t('err.not_found')); }
    $decision = (string) ($_POST['decision'] ?? '');
    $note     = mb_substr(post_str('note'), 0, 255);
    if (!in_array($decision, ['approved', 'returned', 'pending'], true)) { flash(t('err.unknown_action'), 'error'); redirect('review.php?id=' . $id); }
    if ($decision === 'returned' && $note === '') { flash(t('review.need_note'), 'error'); redirect('review.php?id=' . $id); }
    db_run("UPDATE contracts SET finance_status = ?, finance_at = NOW(), finance_by = ?, finance_note = ? WHERE id = ?",
        [$decision, $ME['id'], $note ?: null, $id]);
    log_event((int) $ct['client_id'], 'finance_' . $decision, $note);
    flash(t('review.flash.' . $decision));
    redirect($decision === 'pending' ? 'review.php?id=' . $id : 'review.php');
}

/* ───────── one contract ───────── */
if ($id):
    $ct = db_row("SELECT ct.*, p.name AS project_name, c.full_name, c.phone AS client_phone, COALESCE(su.name, c.sales_rep_name) AS sales_name,
                         fb.name AS finance_by_name
                    FROM contracts ct JOIN clients c ON c.id = ct.client_id JOIN projects p ON p.id = ct.project_id
                    LEFT JOIN users su ON su.id = c.sales_id LEFT JOIN users fb ON fb.id = ct.finance_by WHERE ct.id = ?", [$id]);
    if (!$ct) { http_response_code(404); exit(t('err.not_found')); }
    $cur    = $ct['currency'];
    $totals = contract_totals($id);
    $first  = db_row("SELECT due_date, amount FROM instalments WHERE contract_id = ? AND seq = 1", [$id]);
    $last   = db_row("SELECT due_date, amount FROM instalments WHERE contract_id = ? ORDER BY seq DESC LIMIT 1", [$id]);

    layout_header(t('nav.review'), 'review');
    page_head($ct['official_name'], $ct['contract_no'] . ' · ' . client_code((int) $ct['client_id']),
        '<a class="btn" href="contract.php?id=' . $id . '" target="_blank">' . e(t('contract.view')) . '</a>'
        . '<a class="btn" href="reservation.php?id=' . $id . '">' . e(t('res.statement')) . '</a>'
        . '<a class="btn btn-ghost" href="review.php">' . e(t('ui.back')) . '</a>');
    ?>
<div class="grid-main">
<div class="card">
  <h2><?= e(t('review.check')) ?> <?= finance_pill($ct['finance_status']) ?></h2>
  <dl class="dl">
    <?= dl_row(t('contract.official_name'), $ct['official_name']) ?>
    <?= dl_row(t('contract.national_id'), $ct['national_id']) ?>
    <?= dl_row(t('client.phone'), $ct['phone'] ?: $ct['client_phone']) ?>
    <?= dl_row(t('contract.address'), $ct['address']) ?>
    <?= dl_row(t('contract.project'), $ct['project_name'] . ($ct['unit_type'] ? ' · ' . $ct['unit_type'] : '')) ?>
    <?= dl_row(t('contract.date'), fmt_date($ct['contract_date'])) ?>
    <?= dl_row(t('contract.total'), money($ct['total_amount'], $cur)) ?>
    <?= dl_row(t('pay.down') . ' ' . rtrim(rtrim($ct['down_pct'], '0'), '.') . '%', money($ct['down_amount'], $cur)) ?>
    <?= dl_row(t('contract.months'), t('contract.months_n', ['n' => (string) $ct['months']]) . ' × ' . money($ct['monthly_amount'], $cur)) ?>
    <?= dl_row(t('contract.first_due'), $first ? fmt_date($first['due_date']) : null) ?>
    <?= dl_row(t('contract.last_due'), $last ? fmt_date($last['due_date']) . ' · ' . money($last['amount'], $cur) : null) ?>
    <?= dl_row(t('review.schedule_sum'), money($totals['total'], $cur) . ((float) $totals['total'] === (float) $ct['total_amount'] ? ' ✓' : ' ≠ ' . money($ct['total_amount'], $cur))) ?>
    <?= dl_row(t('res.paid'), money($totals['paid'], $cur)) ?>
    <?= dl_row(t('role.sales'), $ct['sales_name']) ?>
    <?php if ($ct['finance_at']): ?><?= dl_row(t('review.last'), ($ct['finance_by_name'] ?? '—') . ' · ' . fmt_dt($ct['finance_at']) . ($ct['finance_note'] ? ' — ' . $ct['finance_note'] : '')) ?><?php endif; ?>
  </dl>
</div>
<form method="post" class="card action-card">
  <?= csrf_field() ?>
  <h2><?= e(t('review.decide')) ?></h2>
  <div class="field"><span class="lbl"><?= e(t('review.note')) ?></span><textarea name="note" rows="3"><?= e($ct['finance_status'] === 'returned' ? (string) $ct['finance_note'] : '') ?></textarea>
    <span class="hint"><?= e(t('review.note_hint')) ?></span></div>
  <div class="btn-row">
    <button class="btn btn-primary" name="decision" value="approved">✓ <?= e(t('review.approve')) ?></button>
    <button class="btn btn-danger-ghost" name="decision" value="returned">↩ <?= e(t('review.return')) ?></button>
    <?php if ($ct['finance_status'] !== 'pending'): ?><button class="btn btn-ghost" name="decision" value="pending"><?= e(t('review.reset')) ?></button><?php endif; ?>
  </div>
</form>
</div>
<?php
/* ───────── list ───────── */
else:
    $tab = in_array($_GET['tab'] ?? '', ['pending', 'returned', 'approved'], true) ? $_GET['tab'] : 'pending';
    $rows = db_all("SELECT ct.id, ct.contract_no, ct.contract_date, ct.official_name, ct.total_amount, ct.currency, ct.months, ct.finance_at, ct.finance_note,
                           c.id AS client_id, p.name AS project_name, COALESCE(su.name, c.sales_rep_name) AS sales_name
                      FROM contracts ct JOIN clients c ON c.id = ct.client_id JOIN projects p ON p.id = ct.project_id LEFT JOIN users su ON su.id = c.sales_id
                     WHERE ct.finance_status = ? ORDER BY " . ($tab === 'pending' ? 'ct.contract_date ASC, ct.id ASC' : 'ct.finance_at DESC') . " LIMIT 500", [$tab]);
    $tabs = [];
    foreach (['pending', 'returned', 'approved'] as $k) {
        $tabs[$k] = ['label' => t('review.tab.' . $k), 'count' => (int) db_val("SELECT COUNT(*) FROM contracts WHERE finance_status = ?", [$k])];
    }
    layout_header(t('nav.review'), 'review');
    page_head(t('nav.review'), t('review.sub'));
    echo tabs($tabs, $tab);
    ?>
<div class="card card-flush">
  <?php if (!$rows): ?><div class="empty"><?= e(t('review.empty')) ?></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th><?= e(t('contract.no')) ?></th><th><?= e(t('contract.official_name')) ?></th><th><?= e(t('contract.project')) ?></th>
      <th class="num"><?= e(t('contract.total')) ?></th><th><?= e(t('contract.months')) ?></th><th><?= e(t('role.sales')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono nowrap"><?= e($r['contract_no']) ?><div class="small text-muted"><?= e(fmt_date($r['contract_date'])) ?></div></td>
        <td><strong><?= e($r['official_name']) ?></strong>
          <?php if ($tab === 'returned' && $r['finance_note']): ?><div class="small danger-text" dir="auto"><?= e($r['finance_note']) ?></div><?php endif; ?></td>
        <td><?= e($r['project_name']) ?></td>
        <td class="num nowrap"><?= e(money($r['total_amount'], $r['currency'])) ?></td>
        <td><?= (int) $r['months'] ?></td>
        <td><?= e($r['sales_name'] ?? '—') ?></td>
        <td><a class="btn btn-sm <?= $tab === 'pending' ? 'btn-primary' : '' ?>" href="review.php?id=<?= (int) $r['id'] ?>"><?= e(t('ui.open')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif;
layout_footer();
