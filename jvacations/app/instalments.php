<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/** Collections board across all reservations: what is overdue, due soon, or just paid. */
require_cap('contracts.view', 'payments.record');

$views = [
    'overdue'  => [t('inst.overdue'),  "i.status <> 'paid' AND i.due_date < CURDATE()", 'i.due_date ASC'],
    'month'    => [t('inst.month'),    "i.status <> 'paid' AND i.due_date >= CURDATE() AND i.due_date <= LAST_DAY(CURDATE())", 'i.due_date ASC'],
    'upcoming' => [t('inst.upcoming'), "i.status <> 'paid' AND i.due_date > LAST_DAY(CURDATE()) AND i.due_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)", 'i.due_date ASC'],
    'paid'     => [t('inst.paid'),     "i.paid_amount > 0 AND i.paid_on >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)", 'i.paid_on DESC'],
];
$tab = (string) ($_GET['tab'] ?? 'overdue');
if (!isset($views[$tab])) $tab = 'overdue';

$tabData = [];
foreach ($views as $k => $v) {
    $tabData[$k] = ['label' => $v[0], 'count' => (int) db_val("SELECT COUNT(*) FROM instalments i WHERE {$v[1]}")];
}

$rows = db_all(
    "SELECT i.*, ct.contract_no, ct.currency, ct.official_name, c.phone, c.id AS client_id
       FROM instalments i JOIN contracts ct ON ct.id = i.contract_id JOIN clients c ON c.id = ct.client_id
      WHERE {$views[$tab][1]} ORDER BY {$views[$tab][2]} LIMIT 1000");

$sumDue = 0.0;
foreach ($rows as $r) $sumDue += $tab === 'paid' ? (float) $r['paid_amount'] : (float) $r['amount'] - (float) $r['paid_amount'];

layout_header(t('nav.instalments'), 'instalments');
page_head(t('nav.instalments'), t('inst.sub'));
echo tabs($tabData, $tab);
?>
<div class="card card-flush">
  <div class="card-head"><h2><?= e($views[$tab][0]) ?></h2>
    <strong class="<?= $tab === 'overdue' ? 'danger-text' : '' ?>"><?= e(money($sumDue)) ?></strong></div>
  <?php if (!$rows): ?><div class="empty"><?= e(t('inst.empty')) ?></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr>
      <th><?= e(t('pay.due_date')) ?></th><th><?= e(t('contract.no')) ?></th><th><?= e(t('contract.official_name')) ?></th>
      <th><?= e(t('pay.item')) ?></th><th class="num"><?= e(t('pay.amount')) ?></th><th class="num"><?= e($tab === 'paid' ? t('pay.paid_amount') : t('res.remaining')) ?></th>
      <th><?= e(t('pay.status')) ?></th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $late = (int) ((strtotime(date('Y-m-d')) - strtotime($r['due_date'])) / 86400); ?>
      <tr>
        <td class="nowrap"><?= e(fmt_date($r['due_date'])) ?>
          <?php if ($tab === 'overdue'): ?><div class="small danger-text"><?= e(t('inst.days_late', ['n' => (string) $late])) ?></div><?php endif; ?></td>
        <td class="mono nowrap"><?= e($r['contract_no']) ?></td>
        <td><a href="client.php?id=<?= (int) $r['client_id'] ?>"><?= e($r['official_name']) ?></a><div class="small text-muted" dir="ltr"><?= e($r['phone']) ?></div></td>
        <td class="nowrap"><?= e(seq_label((int) $r['seq'])) ?></td>
        <td class="num nowrap"><?= e(money($r['amount'], $r['currency'])) ?></td>
        <td class="num nowrap"><?= e(money($tab === 'paid' ? $r['paid_amount'] : (float) $r['amount'] - (float) $r['paid_amount'], $r['currency'])) ?></td>
        <td><?= pay_pill($r) ?></td>
        <td><a class="btn btn-sm" href="reservation.php?id=<?= (int) $r['contract_id'] ?>#i<?= (int) $r['id'] ?>"><?= e(t('res.statement')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php layout_footer();
