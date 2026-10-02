<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * The client profile — the one record every department shares. What it shows
 * and which action panel appears depend on the viewer's permissions and the stage.
 */
$id = (int) ($_GET['id'] ?? 0);
$c  = client_or_403($id, $ME);

$role     = $ME['role'];
$admin    = $role === 'admin';
$contract = db_row("SELECT ct.*, p.name AS project_name FROM contracts ct JOIN projects p ON p.id = ct.project_id WHERE ct.client_id = ?", [$id]);
$showMoney = $contract && can_view_contract($ME, $c);
$totals    = $showMoney ? contract_totals((int) $contract['id']) : null;

$events = db_all("SELECT e.*, u.name AS user_name, u.role AS user_role FROM client_events e
                   LEFT JOIN users u ON u.id = e.user_id WHERE e.client_id = ? ORDER BY e.id DESC", [$id]);

$salesReps = [];
foreach (users_by_role('sales') as $s) $salesReps[$s['id']] = $s['name'];
$places = setting_list('meeting_places');

$canBook    = can('clients.book') && in_array($c['stage'], ['new', 'booked'], true);
$canConfirm = can('clients.confirm') && in_array($c['stage'], ['booked', 'confirmed'], true);
$canArrive  = can('clients.arrive') && in_array($c['stage'], ['booked', 'confirmed', 'arrived'], true);
$canClose   = can('clients.close') && $c['stage'] === 'arrived' && ($admin || (int) $c['sales_id'] === $ME['id']);
$canEditContract = $contract && $showMoney && (can('contracts.edit')
    || ((float) $totals['paid'] == 0 && can('clients.close') && (int) $c['sales_id'] === $ME['id']));
$canEdit    = can('clients.edit') || ($c['stage'] === 'new' && can('clients.add') && (int) $c['created_by'] === $ME['id']);
$waAutos    = can('whatsapp.send') ? db_all("SELECT id, template_name, language, trigger_key FROM wa_automations WHERE active = 1 ORDER BY trigger_key, id") : [];
$waLog      = can('whatsapp.send') ? db_all("SELECT * FROM wa_messages WHERE client_id = ? ORDER BY id DESC LIMIT 10", [$id]) : [];

$actions = '';
if ($canEdit) $actions .= '<a class="btn" href="client_new.php?id=' . $id . '">' . e(t('ui.edit')) . '</a>';
if ($contract && $showMoney) {
    $actions .= '<a class="btn" href="contract.php?id=' . (int) $contract['id'] . '">' . e(t('contract.view')) . '</a>';
    if (can_any('contracts.view', 'payments.record')) {
        $actions .= '<a class="btn btn-primary" href="reservation.php?id=' . (int) $contract['id'] . '">' . e(t('res.statement')) . '</a>';
    }
}

layout_header($c['full_name'], 'clients');
page_head($c['full_name'], t('client.profile_sub', ['id' => client_code($id)]), $actions);

/* ── pipeline stepper ── */
$steps   = ['new', 'booked', 'confirmed', 'arrived', 'contracted'];
$reached = array_search($c['stage'], $steps, true);
$closed  = in_array($c['stage'], ['lost', 'cancelled'], true);
?>
<div class="stepper">
  <?php foreach ($steps as $i => $s):
      $state = $reached !== false && $i < $reached ? 'done' : ($reached === $i ? 'current' : '');
      if ($s === 'contracted' && $c['stage'] === 'contracted') $state = 'done';
  ?>
    <div class="step <?= $state ?>"><span class="step-dot"><?= $state === 'done' ? '✓' : $i + 1 ?></span>
      <span class="step-lbl"><?= e(t('step.' . $s)) ?></span></div>
  <?php endforeach; ?>
  <?php if ($closed): ?><div class="step closed"><span class="step-dot">✕</span><span class="step-lbl"><?= e(t('stage.' . $c['stage'])) ?></span></div><?php endif; ?>
</div>

<div class="grid-main">
<div>
  <div class="card">
    <h2><?= e(t('client.info')) ?> <?= stage_pill($c['stage']) ?></h2>
    <dl class="dl">
      <?= dl_row(t('client.phone'), $c['phone'] ? '<span dir="ltr">' . e($c['phone']) . '</span>' : null, true) ?>
      <?= dl_row(t('client.phone2'), $c['phone2'] ? '<span dir="ltr">' . e($c['phone2']) . '</span>' : null, true) ?>
      <?= dl_row(t('client.email'), $c['email']) ?>
      <?= dl_row(t('client.city'), $c['city']) ?>
      <?= dl_row(t('client.job'), $c['job']) ?>
      <?= dl_row(t('client.marital'), $c['marital_status'] ? t('marital.' . $c['marital_status']) : null) ?>
      <?= dl_row(t('client.source'), $c['source']) ?>
      <?= dl_row(t('client.notes'), $c['notes']) ?>
      <?= dl_row(t('role.advisor'), ($c['advisor_name'] ?? '—') . ' · ' . fmt_dt($c['created_at'])) ?>
    </dl>
  </div>

  <div class="card">
    <h2><?= e(t('client.meeting')) ?></h2>
    <dl class="dl">
      <?= dl_row(t('meeting.date'), $c['meeting_date'] ? fmt_date($c['meeting_date']) . ' · ' . fmt_time($c['meeting_time']) : null) ?>
      <?= dl_row(t('meeting.place'), $c['meeting_place']) ?>
      <?= dl_row(t('role.booker'), $c['booker_name'] ? $c['booker_name'] . ' · ' . fmt_dt($c['booked_at']) : null) ?>
      <?= dl_row(t('role.communicator'), $c['communicator_name'] ? $c['communicator_name'] . ($c['confirmed_at'] ? ' · ' . fmt_dt($c['confirmed_at']) : '') : null) ?>
      <?= dl_row(t('meeting.arrived'), $c['arrived_at'] ? fmt_dt($c['arrived_at']) . ($c['manager_name'] ? ' · ' . $c['manager_name'] : '') : null) ?>
      <?= dl_row(t('role.sales'), $c['sales_name']) ?>
      <?php if ($closed || $c['lost_reason']): ?><?= dl_row(t('client.reason'), $c['lost_reason']) ?><?php endif; ?>
    </dl>
  </div>

  <?php if ($contract && $showMoney): ?>
  <div class="card">
    <h2><?= e(t('contract.reservation')) ?> <span class="mono text-muted"><?= e($contract['contract_no']) ?></span></h2>
    <div class="stats-row compact">
      <div class="stat-tile"><span class="lbl"><?= e(t('contract.total')) ?></span><span class="val"><?= e(money($contract['total_amount'], $contract['currency'])) ?></span></div>
      <div class="stat-tile"><span class="lbl"><?= e(t('res.paid')) ?></span><span class="val good"><?= e(money($totals['paid'], $contract['currency'])) ?></span></div>
      <div class="stat-tile"><span class="lbl"><?= e(t('res.remaining')) ?></span><span class="val accent"><?= e(money($totals['remaining'], $contract['currency'])) ?></span></div>
      <div class="stat-tile"><span class="lbl"><?= e(t('res.overdue')) ?></span><span class="val <?= $totals['overdue'] > 0 ? 'danger' : '' ?>"><?= e(money($totals['overdue'], $contract['currency'])) ?></span></div>
    </div>
    <dl class="dl">
      <?= dl_row(t('contract.project'), $contract['project_name'] . ($contract['unit_type'] ? ' · ' . $contract['unit_type'] : '')) ?>
      <?= dl_row(t('contract.plan'), t('contract.plan_line', ['pct' => rtrim(rtrim($contract['down_pct'], '0'), '.'), 'down' => money($contract['down_amount'], $contract['currency']), 'months' => (string) $contract['months'], 'monthly' => money($contract['monthly_amount'], $contract['currency'])])) ?>
      <?= dl_row(t('contract.weeks'), t('contract.weeks_line', ['w' => (string) $contract['weeks_per_year'], 'y' => (string) $contract['duration_years'], 'from' => (string) $contract['start_year']])) ?>
    </dl>
  </div>
  <?php endif; ?>
</div>

<div>
  <?php if ($canBook): ?>
  <form method="post" action="client_action.php" class="card action-card">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="book">
    <h2><?= e($c['stage'] === 'new' ? t('act.book') : t('act.rebook')) ?></h2>
    <div class="grid2">
      <div class="field"><span class="lbl"><?= e(t('meeting.date')) ?> *</span><input type="date" name="meeting_date" value="<?= e($c['meeting_date'] ?? '') ?>" min="<?= date('Y-m-d') ?>" required></div>
      <div class="field"><span class="lbl"><?= e(t('meeting.time')) ?> *</span><input type="time" name="meeting_time" value="<?= e(substr((string) $c['meeting_time'], 0, 5)) ?>" required></div>
    </div>
    <div class="field"><span class="lbl"><?= e(t('meeting.place')) ?></span>
      <input type="text" name="meeting_place" list="places" value="<?= e($c['meeting_place'] ?? '') ?>"></div>
    <div class="field"><span class="lbl"><?= e(t('meeting.sales')) ?></span>
      <select name="sales_id"><?= options($salesReps, $c['sales_id']) ?></select>
      <span class="hint"><?= e(t('meeting.sales_optional')) ?></span></div>
    <div class="field"><span class="lbl"><?= e(t('ui.note')) ?></span><textarea name="note" rows="2"></textarea></div>
    <button class="btn btn-primary" type="submit"><?= e(t('act.book_save')) ?></button>
  </form>
  <?php endif; ?>

  <?php if ($canConfirm): ?>
  <form method="post" action="client_action.php" class="card action-card">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <h2><?= e($c['stage'] === 'booked' ? t('act.confirm') : t('act.reconfirm')) ?></h2>
    <p class="hint-line"><?= e(t('act.confirm_hint')) ?></p>
    <div class="grid2">
      <div class="field"><span class="lbl"><?= e(t('meeting.date')) ?> *</span><input type="date" name="meeting_date" value="<?= e($c['meeting_date'] ?? '') ?>"></div>
      <div class="field"><span class="lbl"><?= e(t('meeting.time')) ?> *</span><input type="time" name="meeting_time" value="<?= e(substr((string) $c['meeting_time'], 0, 5)) ?>"></div>
    </div>
    <div class="field"><span class="lbl"><?= e(t('meeting.place')) ?></span>
      <input type="text" name="meeting_place" list="places" value="<?= e($c['meeting_place'] ?? '') ?>"></div>
    <div class="field"><span class="lbl"><?= e(t('meeting.sales')) ?></span>
      <select name="sales_id"><?= options($salesReps, $c['sales_id']) ?></select>
      <span class="hint"><?= e(t('meeting.sales_optional')) ?></span></div>
    <div class="field"><span class="lbl"><?= e(t('ui.note')) ?></span><textarea name="note" rows="2"></textarea></div>
    <div class="btn-row">
      <button class="btn btn-primary" name="action" value="confirm"><?= e(t('act.confirm_btn')) ?></button>
      <button class="btn" name="action" value="no_answer" formnovalidate><?= e(t('act.no_answer')) ?></button>
      <button class="btn" name="action" value="rebook" formnovalidate data-confirm="<?= e(t('act.rebook_q')) ?>"><?= e(t('act.send_back')) ?></button>
      <button class="btn btn-danger-ghost" name="action" value="cancel" formnovalidate data-confirm="<?= e(t('act.cancel_q')) ?>"><?= e(t('act.cancel')) ?></button>
    </div>
  </form>
  <?php endif; ?>

  <?php if ($canArrive): ?>
  <form method="post" action="client_action.php" class="card action-card">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="as" value="manager">
    <h2><?= e($c['stage'] === 'arrived' ? t('act.reassign') : t('act.arrive')) ?></h2>
    <p class="hint-line"><?= e($c['stage'] === 'arrived' ? t('act.reassign_hint') : t('act.arrive_hint')) ?></p>
    <div class="field"><span class="lbl"><?= e(t('meeting.sales')) ?> *</span>
      <select name="sales_id" required><?= options($salesReps, $c['sales_id']) ?></select></div>
    <div class="field"><span class="lbl"><?= e(t('ui.note')) ?></span><textarea name="note" rows="2"></textarea></div>
    <div class="btn-row">
      <?php if ($c['stage'] === 'arrived'): ?>
        <button class="btn btn-primary" name="action" value="assign"><?= e(t('act.reassign_btn')) ?></button>
      <?php else: ?>
        <button class="btn btn-primary" name="action" value="arrive">✓ <?= e(t('act.arrive_btn')) ?></button>
        <button class="btn" name="action" value="rebook" formnovalidate data-confirm="<?= e(t('act.rebook_q')) ?>"><?= e(t('act.no_show')) ?></button>
      <?php endif; ?>
    </div>
  </form>
  <?php endif; ?>

  <?php if ($canClose): ?>
  <div class="card action-card">
    <h2><?= e(t('act.result')) ?></h2>
    <p class="hint-line"><?= e(t('act.result_hint')) ?></p>
    <a class="btn btn-primary btn-block" href="contract_form.php?client=<?= $id ?>">✓ <?= e(t('act.won')) ?></a>
    <form method="post" action="client_action.php" class="lost-form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="lost">
      <div class="field"><span class="lbl"><?= e(t('act.lost_reason')) ?></span><textarea name="note" rows="2" required></textarea></div>
      <button class="btn btn-danger-ghost" type="submit">✕ <?= e(t('act.lost')) ?></button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($canEditContract): ?>
  <div class="card action-card">
    <a class="btn btn-block" href="contract_form.php?client=<?= $id ?>"><?= e(t('contract.edit')) ?></a>
  </div>
  <?php endif; ?>

  <?php if ($contract && can('stays.manage')): ?>
  <div class="card action-card">
    <h2><?= e(t('nav.stays')) ?></h2>
    <a class="btn btn-block" href="stays.php?contract=<?= (int) $contract['id'] ?>"><?= e(t('stay.manage')) ?></a>
  </div>
  <?php endif; ?>

  <?php if ($admin && $closed): ?>
  <form method="post" action="client_action.php" class="card action-card">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reopen">
    <button class="btn btn-block" type="submit"><?= e(t('act.reopen')) ?></button>
  </form>
  <?php endif; ?>

  <?php if ($waAutos): ?>
  <form method="post" action="client_action.php" class="card">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="wa_send">
    <h2><?= e(t('wa.send_title')) ?></h2>
    <div class="note-form">
      <select name="automation_id" required>
        <option value="">—</option>
        <?php foreach ($waAutos as $a): ?>
          <option value="<?= (int) $a['id'] ?>"><?= e($a['template_name'] . ' (' . $a['language'] . ') · ' . t(wa_triggers()[$a['trigger_key']] ?? 'wa.trg.manual')) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm" type="submit" data-confirm="<?= e(t('wa.send_q')) ?>"><?= e(t('wa.send_btn')) ?></button>
    </div>
    <?php if ($waLog): ?>
      <ul class="wa-log">
        <?php foreach ($waLog as $m): ?>
          <li><?= wa_status_pill($m['status']) ?> <span class="mono"><?= e($m['template_name']) ?></span>
            <span class="text-muted small"><?= e(fmt_dt($m['created_at'])) ?></span>
            <?php if ($m['error']): ?><div class="small danger-text" dir="auto"><?= e($m['error']) ?></div><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </form>
  <?php endif; ?>

  <div class="card" id="timeline">
    <h2><?= e(t('client.timeline')) ?></h2>
    <form method="post" action="client_action.php" class="note-form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="note">
      <textarea name="note" rows="2" placeholder="<?= e(t('client.add_note')) ?>" required></textarea>
      <button class="btn btn-sm" type="submit"><?= e(t('ui.add')) ?></button>
    </form>
    <ul class="timeline">
      <?php foreach ($events as $ev): ?>
        <li>
          <div class="tl-head"><strong><?= e(t('event.' . $ev['action'])) ?></strong>
            <span class="text-muted"><?= e($ev['user_name'] ?? '—') ?><?= $ev['user_role'] ? ' · ' . e(t('role.' . $ev['user_role'])) : '' ?></span></div>
          <?php if ($ev['note']): ?><div class="tl-note" dir="auto"><?= nl2br(e($ev['note'])) ?></div><?php endif; ?>
          <div class="tl-time"><?= e(fmt_dt($ev['created_at'])) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
</div>
<datalist id="places"><?php foreach ($places as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist>
<?php layout_footer();
