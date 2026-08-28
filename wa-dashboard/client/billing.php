<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/billing.php';

/**
 * What the client is on, what they have used this month, and what it went on.
 *
 * Deliberately shown in credits and plain counts, never in tokens or fractions of a cent —
 * the client should be able to answer "why is my balance lower" without a calculator.
 */
$cid    = (int) $CLIENT['id'];
$period = billing_period_start();

$plan   = ($CLIENT['plan_id'] ?? null)
        ? db_row("SELECT * FROM plans WHERE id=?", [(int) $CLIENT['plan_id']]) : null;
$usage  = db_row("SELECT * FROM usage_periods WHERE client_id=? AND period_start=?", [$cid, $period]);
$charges= db_all("SELECT * FROM message_charges WHERE client_id=? AND period_start=? ORDER BY credits DESC",
                 [$cid, $period]);
$ledger = credits_ledger($cid, 30);
$aiLeft = billing_ai_remaining($CLIENT);

$used   = (int) ($usage['credits_used'] ?? 0);
$sent   = (int) ($usage['messages_sent'] ?? 0);
$aiUsed = (int) ($usage['ai_credits'] ?? 0);
$inc    = (int) ($plan['included_credits'] ?? 0);

$catName = ['marketing' => 'Promotions', 'utility' => 'Updates & confirmations',
            'authentication' => 'Verification codes', 'service' => 'Replies within 24 hours'];

layout_header('Billing', 'client', 'billing');
?>
<div class="page-head"><h1>Billing</h1>
  <p class="text-muted">Your plan and what you have used since <?= e(date('j F', strtotime($period))) ?>.</p></div>

<div class="grid2">
  <div class="card">
    <div class="lbl" style="font-size:12px">Your plan</div>
    <div style="font-size:22px;font-weight:800"><?= $plan ? e((string) $plan['name']) : 'No plan yet' ?></div>
    <?php if ($plan): ?>
      <div class="text-muted" style="font-size:13px;margin-top:4px">
        <?= number_format((float) $plan['price_month'], 2) ?> <?= e((string) $plan['currency']) ?> a month
        · <?= number_format($inc) ?> credits included
      </div>
      <?php if (!empty($CLIENT['plan_renews_at'])): ?>
        <div class="text-muted" style="font-size:12px;margin-top:6px">
          Renews <?= e(date('j M Y', strtotime((string) $CLIENT['plan_renews_at']))) ?>.</div>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-muted" style="font-size:13px">Ask your account manager to put you on a plan.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="lbl" style="font-size:12px">Credits left</div>
    <div style="font-size:22px;font-weight:800"><?= number_format((int) $CLIENT['credits_balance']) ?></div>
    <div class="text-muted" style="font-size:13px;margin-top:4px">
      <?= number_format($used) ?> used this month across <?= number_format($sent) ?> message<?= $sent === 1 ? '' : 's' ?>.
    </div>
    <?php if ($inc > 0): ?>
      <?php $pct = min(100, (int) round($used / max(1, $inc) * 100)); ?>
      <div class="meter" title="<?= $pct ?>% of this month's included credits">
        <span style="width:<?= $pct ?>%"></span></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($aiLeft !== null): ?>
  <div class="card">
    <div class="row-between">
      <div>
        <div class="lbl" style="font-size:12px">AI included in your plan</div>
        <div style="font-size:18px;font-weight:700"><?= number_format($aiLeft) ?> credits left</div>
      </div>
      <div class="text-muted" style="font-size:12.5px;max-width:420px;text-align:right">
        <?= $aiLeft > 0
            ? 'Used by AI replies and lead scoring.'
            : 'Your AI allowance is used up for this month. AI steps now send their written fallback message instead — nothing extra is charged.' ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($charges): ?>
<div class="card">
  <h2 style="margin-top:0">What your messages went on</h2>
  <table class="table">
    <thead><tr><th>Type</th><th>Where</th><th>Messages</th><th>Credits</th></tr></thead>
    <tbody>
    <?php foreach ($charges as $c): ?>
      <tr>
        <td><?= e($catName[(string) $c['category']] ?? ucfirst((string) $c['category'])) ?></td>
        <td><?= $c['country_code'] === '*' ? '<span class="text-muted">other countries</span>' : '+' . e((string) $c['country_code']) ?></td>
        <td><?= number_format((int) $c['qty']) ?></td>
        <td><?= number_format((int) $c['credits']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="text-muted" style="font-size:12px">Replies sent within 24 hours of a customer message are the
     cheapest kind — starting a conversation costs more than continuing one.</p>
</div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0">Recent credit activity</h2>
  <?php if (!$ledger): ?>
    <p class="text-muted">Nothing yet.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>When</th><th>What</th><th>Change</th><th>Balance</th></tr></thead>
      <tbody>
      <?php
      $reasonName = [
          'send' => 'Campaign messages', 'automation' => 'Automation reply', 'inbox' => 'Message you sent',
          'ai_usage' => 'AI', 'plan_grant' => 'Monthly plan credits', 'initial_grant' => 'Opening balance',
          'refund_failed' => 'Refund — message failed', 'refund_retry' => 'Refund — will retry',
          'refund_unused' => 'Refund — unused', 'automation_refund' => 'Refund — automation failed',
          'inbox_refund' => 'Refund — message failed', 'topup' => 'Top-up',
      ];
      foreach ($ledger as $t): $d = (int) $t['delta']; ?>
        <tr>
          <td class="text-muted"><?= e(date('j M, H:i', strtotime((string) $t['created_at']))) ?></td>
          <td><?= e($reasonName[(string) $t['reason']] ?? ucfirst(str_replace('_', ' ', (string) $t['reason']))) ?></td>
          <td style="color:<?= $d < 0 ? '#D14343' : '#2E7D32' ?>;font-weight:700"><?= $d > 0 ? '+' : '' ?><?= number_format($d) ?></td>
          <td><?= number_format((int) $t['balance_after']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php layout_footer(); ?>
