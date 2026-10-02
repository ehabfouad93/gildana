<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Reservation statement: total, 25% down payment and every monthly instalment,
 * generated from the contract. The accountant reads it; owner services records
 * each payment (paid / partial / unpaid, date, receipt).
 */
require_cap('contracts.view', 'payments.record');
$canPay = can('payments.record');
$canResched = can('contracts.reschedule');

$id = (int) ($_GET['id'] ?? 0);
$ct = db_row("SELECT ct.*, p.name AS project_name, c.full_name, c.phone AS client_phone, u.name AS sales_name
                FROM contracts ct JOIN projects p ON p.id = ct.project_id JOIN clients c ON c.id = ct.client_id
                LEFT JOIN users u ON u.id = ct.created_by WHERE ct.id = ?", [$id]);
if (!$ct) { http_response_code(404); exit(t('err.not_found')); }
$cur = $ct['currency'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reschedule') {
    verify_csrf();
    if (!$canResched) { http_response_code(403); exit(t('err.forbidden')); }
    $back   = 'reservation.php?id=' . $id;
    $total  = to_cents($_POST['total_amount'] ?? '');
    $months = (int) ($_POST['months'] ?? 0);
    $first  = valid_date($_POST['first_due'] ?? '');
    if ($total <= 0 || $months < 1 || $months > 120 || !$first) { flash(t('res.resched_err'), 'error'); redirect($back . '#reschedule'); }

    $rows = array_map(fn($r) => ['id' => (int) $r['id'], 'seq' => (int) $r['seq'], 'amount' => to_cents($r['amount']),
                                 'paid' => to_cents($r['paid_amount']), 'status' => $r['status']],
                      db_all("SELECT id, seq, amount, paid_amount, status FROM instalments WHERE contract_id = ? ORDER BY seq", [$id]));
    try {
        $plan = reschedule_plan($rows, $total, $months, $first);
    } catch (InvalidArgumentException $e) {
        flash(t('res.resched_below'), 'error');
        redirect($back . '#reschedule');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($plan['close'] as $iid => $paid) {
            db_run("UPDATE instalments SET amount = ?, status = 'paid', note = CONCAT(COALESCE(note,''), ?), updated_by = ?, updated_at = NOW() WHERE id = ?",
                [from_cents($paid), ' [' . t('res.closed_partial') . ']', $ME['id'], $iid]);
        }
        if ($plan['delete']) {
            db_run("DELETE FROM instalments WHERE contract_id = ? AND id IN (" . implode(',', array_map('intval', $plan['delete'])) . ")", [$id]);
        }
        $st = $pdo->prepare("INSERT INTO instalments (contract_id, seq, due_date, amount) VALUES (?,?,?,?)");
        foreach ($plan['rows'] as $r) $st->execute([$id, $r['seq'], $r['due'], from_cents($r['amount'])]);
        $n = (int) db_val("SELECT COUNT(*) FROM instalments WHERE contract_id = ? AND seq > 0", [$id]);
        db_run("UPDATE contracts SET total_amount = ?, months = ?, monthly_amount = ?, updated_at = NOW() WHERE id = ?",
            [from_cents($total), $n, from_cents($plan['monthly'] ?: to_cents($ct['monthly_amount'])), $id]);
        log_event((int) $ct['client_id'], 'rescheduled', money(from_cents($total), $cur) . ' · ' . t('res.resched_line', ['n' => (string) $months, 'from' => fmt_date($first)])
            . (post_str('reason') !== '' ? ' — ' . post_str('reason') : ''));
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    flash(t('res.resched_done'));
    redirect($back);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_inst') {
    verify_csrf();
    if (!$canResched) { http_response_code(403); exit(t('err.forbidden')); }
    $iid  = (int) ($_POST['inst_id'] ?? 0);
    $inst = db_row("SELECT * FROM instalments WHERE id = ? AND contract_id = ?", [$iid, $id]);
    $amt  = to_cents($_POST['amount'] ?? '');
    $due  = valid_date($_POST['due_date'] ?? '');
    if (!$inst || $amt <= 0 || !$due || $amt < to_cents($inst['paid_amount'])) {
        flash(t('res.edit_inst_err'), 'error');
    } else {
        $status = to_cents($inst['paid_amount']) >= $amt ? 'paid' : (to_cents($inst['paid_amount']) > 0 ? 'partial' : 'unpaid');
        db_run("UPDATE instalments SET amount = ?, due_date = ?, status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
            [from_cents($amt), $due, $status, $ME['id'], $iid]);
        db_run("UPDATE contracts SET total_amount = (SELECT SUM(amount) FROM instalments WHERE contract_id = ?), updated_at = NOW() WHERE id = ?", [$id, $id]);
        log_event((int) $ct['client_id'], 'inst_edited', seq_label((int) $inst['seq']) . ': ' . money($inst['amount'], $cur) . ' / ' . fmt_date($inst['due_date'])
            . ' → ' . money(from_cents($amt), $cur) . ' / ' . fmt_date($due));
        flash(t('ui.saved'));
    }
    redirect('reservation.php?id=' . $id . '#i' . $iid);
}

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
        if ($amount > 0 && $amount > to_cents($inst['paid_amount'])) {
            wa_fire('payment', (int) $ct['client_id'], ['amount' => money(from_cents($amount), $cur), 'item' => seq_label((int) $inst['seq'])]);
        }
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
    <?= dl_row(t('search.client_no'), client_code((int) $ct['client_id'])) ?>
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
      <th><?= e(t('pay.receipt')) ?></th><?php if ($canPay || $canResched): ?><th class="no-print"></th><?php endif; ?>
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
        <?php if ($canPay || $canResched): ?>
        <td class="no-print nowrap">
          <?php if ($canPay): ?><button class="btn btn-sm" type="button" data-toggle="pf<?= (int) $i['id'] ?>"><?= e(t('pay.update')) ?></button><?php endif; ?>
          <?php if ($canResched && $i['status'] !== 'paid'): ?><button class="btn btn-sm btn-ghost" type="button" data-toggle="ef<?= (int) $i['id'] ?>" title="<?= e(t('res.edit_inst')) ?>">✎</button><?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php if ($canResched && $i['status'] !== 'paid'): ?>
      <tr class="pay-form-row no-print" id="ef<?= (int) $i['id'] ?>" hidden>
        <td colspan="9">
          <form method="post" class="pay-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="edit_inst"><input type="hidden" name="inst_id" value="<?= (int) $i['id'] ?>">
            <label><span><?= e(t('pay.due_date')) ?></span><input type="date" name="due_date" value="<?= e($i['due_date']) ?>" required></label>
            <label><span><?= e(t('pay.amount')) ?></span><input type="text" inputmode="decimal" name="amount" value="<?= e($i['amount']) ?>" dir="ltr" required></label>
            <button class="btn btn-primary btn-sm" type="submit"><?= e(t('res.edit_inst')) ?></button>
            <span class="small text-muted"><?= e(t('res.edit_inst_hint')) ?></span>
          </form>
        </td>
      </tr>
      <?php endif; ?>
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
      <td colspan="<?= ($canPay || $canResched) ? 3 : 2 ?>"><?= e(t('res.remaining')) ?>: <strong><?= e(money($totals['remaining'], $cur)) ?></strong></td>
    </tr></tfoot>
  </table></div>
</div>

<?php if ($canResched):
    $unpaidLeft = (int) db_val("SELECT COUNT(*) FROM instalments WHERE contract_id = ? AND seq > 0 AND status = 'unpaid' AND paid_amount = 0", [$id]);
    $nextDue    = (string) (db_val("SELECT MIN(due_date) FROM instalments WHERE contract_id = ? AND seq > 0 AND status = 'unpaid' AND paid_amount = 0", [$id]) ?: add_months(date('Y-m-01'), 1)); ?>
<form method="post" class="card action-card no-print" id="reschedule" data-confirm-form="<?= e(t('res.resched_q')) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="reschedule">
  <h2><?= e(t('res.reschedule')) ?></h2>
  <p class="hint-line"><?= e(t('res.resched_hint', ['n' => (string) $unpaidLeft])) ?></p>
  <div class="grid3">
    <div class="field"><span class="lbl"><?= e(t('res.new_total')) ?> (<?= e($cur) ?>)</span>
      <input type="text" inputmode="decimal" name="total_amount" value="<?= e(rtrim(rtrim($ct['total_amount'], '0'), '.')) ?>" dir="ltr" required></div>
    <div class="field"><span class="lbl"><?= e(t('res.new_months')) ?></span>
      <input type="number" name="months" min="1" max="120" value="<?= max(1, $unpaidLeft) ?>" required list="monthOpts">
      <datalist id="monthOpts"><?php foreach (month_options() as $m): ?><option value="<?= $m ?>"><?php endforeach; ?></datalist>
      <span class="hint"><?= e(t('res.new_months_hint')) ?></span></div>
    <div class="field"><span class="lbl"><?= e(t('res.new_first')) ?></span>
      <input type="date" name="first_due" value="<?= e($nextDue) ?>" required></div>
  </div>
  <div class="field"><span class="lbl"><?= e(t('client.reason')) ?></span><input type="text" name="reason"></div>
  <button class="btn btn-primary" type="submit"><?= e(t('res.resched_btn')) ?></button>
</form>
<?php endif; ?>
<?php layout_footer();
