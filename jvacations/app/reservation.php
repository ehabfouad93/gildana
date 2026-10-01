<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Reservation statement: total, 25% down payment and every monthly instalment,
 * generated from the contract. The accountant reads it; owner services records
 * each payment (paid / partial / unpaid, date, receipt).
 */
require_role('accountant', 'owner_services');
$canPay = has_role('owner_services');

$id = (int) ($_GET['id'] ?? 0);
$ct = db_row("SELECT ct.*, p.name AS project_name, c.full_name, c.phone AS client_phone, u.name AS sales_name
                FROM contracts ct JOIN projects p ON p.id = ct.project_id JOIN clients c ON c.id = ct.client_id
                LEFT JOIN users u ON u.id = ct.created_by WHERE ct.id = ?", [$id]);
if (!$ct) { http_response_code(404); exit(t('err.not_found')); }
$cur = $ct['currency'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canPay) { http_response_code(403); exit(t('err.forbidden')); }
    $iid  = (int) ($_POST['inst_id'] ?? 0);
    $inst = db_row("SELECT * FROM instalments WHERE id = ? AND contract_id = ?", [$iid, $id]);
    if (!$inst) { flash(t('err.not_found'), 'error'); redirect('reservation.php?id=' . $id); }

    $status = (string) ($_POST['status'] ?? 'unpaid');
    $amount = to_cents($_POST['paid_amount'] ?? '');
    $due    = to_cents($inst['amount']);
    $paidOn = valid_date($_POST['paid_on'] ?? '');
    $err    = '';

    if ($status === 'unpaid') {
        $amount = 0; $paidOn = null;
    } elseif ($status === 'paid') {
        $amount = $due;
        $paidOn = $paidOn ?? date('Y-m-d');
    } elseif ($status === 'partial') {
        if ($amount <= 0 || $amount >= $due) $err = t('pay.err_partial');
        $paidOn = $paidOn ?? date('Y-m-d');
    } else {
        $err = t('err.unknown_action');
    }

    if ($err !== '') {
        flash($err, 'error');
    } else {
        db_run("UPDATE instalments SET status=?, paid_amount=?, paid_on=?, receipt_no=?, method=?, note=?, updated_by=?, updated_at=NOW() WHERE id=?",
            [$status, from_cents($amount), $paidOn, mb_substr(post_str('receipt_no'), 0, 60) ?: null,
             mb_substr(post_str('method'), 0, 30) ?: null, mb_substr(post_str('note'), 0, 255) ?: null, $ME['id'], $iid]);
        log_event((int) $ct['client_id'], 'payment', seq_label((int) $inst['seq']) . ' → ' . t('pay.' . $status)
            . ($amount > 0 ? ' · ' . money(from_cents($amount), $cur) : ''));
        flash(t('ui.saved'));
    }
    redirect('reservation.php?id=' . $id . '#i' . $iid);
}

$inst   = db_all("SELECT i.*, u.name AS updated_by_name FROM instalments i LEFT JOIN users u ON u.id = i.updated_by WHERE i.contract_id = ? ORDER BY i.seq", [$id]);
$totals = contract_totals($id);
$today  = date('Y-m-d');
$methods = ['cash' => t('method.cash'), 'transfer' => t('method.transfer'), 'card' => t('method.card'), 'cheque' => t('method.cheque')];

$actions = '<button class="btn btn-primary" type="button" onclick="window.print()">' . e(t('doc.print')) . '</button>'
         . '<a class="btn" href="contract.php?id=' . $id . '">' . e(t('contract.view')) . '</a>'
         . '<a class="btn btn-ghost" href="client.php?id=' . (int) $ct['client_id'] . '">' . e(t('client.profile')) . '</a>';

layout_header(t('res.statement') . ' ' . $ct['contract_no'], 'reservations');
page_head(t('res.statement'), $ct['contract_no'] . ' · ' . $ct['official_name'], $actions);
?>
<div class="print-only print-title"><?= e(setting('company_name')) ?> — <?= e(t('res.statement')) ?> · <?= e(date('Y-m-d')) ?></div>

<div class="card">
  <dl class="dl dl-3">
    <?= dl_row(t('contract.official_name'), $ct['official_name']) ?>
    <?= dl_row(t('contract.national_id'), $ct['national_id']) ?>
    <?= dl_row(t('client.phone'), $ct['phone'] ?: $ct['client_phone']) ?>
    <?= dl_row(t('contract.project'), $ct['project_name'] . ($ct['unit_type'] ? ' · ' . $ct['unit_type'] : '')) ?>
    <?= dl_row(t('contract.date'), fmt_date($ct['contract_date'])) ?>
    <?= dl_row(t('role.sales'), $ct['sales_name']) ?>
  </dl>
</div>

<div class="stats-row">
  <div class="stat-tile"><span class="lbl"><?= e(t('contract.total')) ?></span><span class="val"><?= e(money($ct['total_amount'], $cur)) ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('pay.down')) ?> <?= e(rtrim(rtrim($ct['down_pct'], '0'), '.')) ?>%</span><span class="val"><?= e(money($ct['down_amount'], $cur)) ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('contract.monthly')) ?> × <?= (int) $ct['months'] ?></span><span class="val"><?= e(money($ct['monthly_amount'], $cur)) ?></span>
    <span class="sub"><?= e(t('contract.months_n', ['n' => (string) $ct['months']])) ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('res.paid')) ?></span><span class="val good"><?= e(money($totals['paid'], $cur)) ?></span>
    <span class="sub"><?= (int) $totals['paid_count'] ?> / <?= (int) $totals['n'] ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('res.remaining')) ?></span><span class="val accent"><?= e(money($totals['remaining'], $cur)) ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('res.overdue')) ?></span><span class="val <?= (float) $totals['overdue'] > 0 ? 'danger' : '' ?>"><?= e(money($totals['overdue'], $cur)) ?></span>
    <span class="sub"><?= e(t('res.overdue_n', ['n' => (string) (int) $totals['overdue_count']])) ?></span></div>
</div>

<div class="card card-flush">
  <div class="card-head"><h2><?= e(t('res.schedule')) ?></h2>
    <?php if (!$canPay): ?><span class="text-muted small"><?= e(t('res.readonly')) ?></span><?php endif; ?></div>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th>#</th><th><?= e(t('pay.item')) ?></th><th><?= e(t('pay.due_date')) ?></th><th class="num"><?= e(t('pay.amount')) ?></th>
      <th><?= e(t('pay.status')) ?></th><th class="num"><?= e(t('pay.paid_amount')) ?></th><th><?= e(t('pay.paid_on')) ?></th>
      <th><?= e(t('pay.receipt')) ?></th><?php if ($canPay): ?><th class="no-print"></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($inst as $i):
        $over = $i['status'] !== 'paid' && $i['due_date'] < $today; ?>
      <tr id="i<?= (int) $i['id'] ?>" class="<?= $over ? 'row-over' : '' ?><?= (int) $i['seq'] === 0 ? ' row-down' : '' ?>">
        <td class="text-muted"><?= (int) $i['seq'] ?></td>
        <td class="nowrap"><?= e(seq_label((int) $i['seq'])) ?></td>
        <td class="nowrap"><?= e(fmt_date($i['due_date'])) ?></td>
        <td class="num nowrap"><?= e(money($i['amount'], $cur)) ?></td>
        <td><?= pay_pill($i) ?></td>
        <td class="num nowrap"><?= (float) $i['paid_amount'] > 0 ? e(money($i['paid_amount'], $cur)) : '—' ?></td>
        <td class="nowrap"><?= e(fmt_date($i['paid_on'])) ?><?= $i['method'] ? '<div class="small text-muted">' . e($methods[$i['method']] ?? $i['method']) . '</div>' : '' ?></td>
        <td><?= e($i['receipt_no'] ?? '—') ?><?= $i['note'] ? '<div class="small text-muted" dir="auto">' . e($i['note']) . '</div>' : '' ?></td>
        <?php if ($canPay): ?>
        <td class="no-print"><button class="btn btn-sm" type="button" data-toggle="pf<?= (int) $i['id'] ?>"><?= e(t('pay.update')) ?></button></td>
        <?php endif; ?>
      </tr>
      <?php if ($canPay): ?>
      <tr class="pay-form-row no-print" id="pf<?= (int) $i['id'] ?>" hidden>
        <td colspan="9">
          <form method="post" class="pay-form" data-due="<?= e($i['amount']) ?>">
            <?= csrf_field() ?><input type="hidden" name="inst_id" value="<?= (int) $i['id'] ?>">
            <label><span><?= e(t('pay.status')) ?></span>
              <select name="status" data-pay-status><?= options(['paid' => t('pay.paid'), 'partial' => t('pay.partial'), 'unpaid' => t('pay.unpaid')], $i['status'] === 'unpaid' ? 'paid' : $i['status'], false) ?></select></label>
            <label data-partial-only><span><?= e(t('pay.paid_amount')) ?></span><input type="text" inputmode="decimal" name="paid_amount" value="<?= (float) $i['paid_amount'] > 0 ? e($i['paid_amount']) : '' ?>" dir="ltr"></label>
            <label><span><?= e(t('pay.paid_on')) ?></span><input type="date" name="paid_on" value="<?= e($i['paid_on'] ?? $today) ?>"></label>
            <label><span><?= e(t('pay.method')) ?></span><select name="method"><?= options($methods, $i['method'] ?? 'cash') ?></select></label>
            <label><span><?= e(t('pay.receipt')) ?></span><input type="text" name="receipt_no" value="<?= e($i['receipt_no'] ?? '') ?>"></label>
            <label class="grow"><span><?= e(t('ui.note')) ?></span><input type="text" name="note" value="<?= e($i['note'] ?? '') ?>"></label>
            <button class="btn btn-primary btn-sm" type="submit"><?= e(t('ui.save')) ?></button>
          </form>
          <?php if ($i['updated_by_name']): ?><div class="small text-muted"><?= e(t('pay.last_update', ['who' => $i['updated_by_name'], 'when' => fmt_dt($i['updated_at'])])) ?></div><?php endif; ?>
        </td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td colspan="3"><strong><?= e(t('res.totals')) ?></strong></td>
      <td class="num"><strong><?= e(money($totals['total'], $cur)) ?></strong></td><td></td>
      <td class="num"><strong><?= e(money($totals['paid'], $cur)) ?></strong></td>
      <td colspan="<?= $canPay ? 3 : 2 ?>"><?= e(t('res.remaining')) ?>: <strong><?= e(money($totals['remaining'], $cur)) ?></strong></td>
    </tr></tfoot>
  </table></div>
</div>
<?php layout_footer();
