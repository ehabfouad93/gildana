<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Sales closes the deal: the client's official data and the payment plan.
 * Saving creates the reservation, generates the instalment schedule and moves
 * the client to "contracted". The plan can be corrected until the first
 * payment is recorded; after that it is locked so payments never orphan.
 */
require_role('sales');

$cid = (int) ($_GET['client'] ?? 0);
$c   = client_or_403($cid, $ME);
if ($ME['role'] === 'sales' && (int) $c['sales_id'] !== $ME['id']) { http_response_code(403); exit(t('err.forbidden')); }

$ct = db_row("SELECT * FROM contracts WHERE client_id = ?", [$cid]);
if (!$ct && $c['stage'] !== 'confirmed') {
    flash(t('err.wrong_stage'), 'error');
    redirect('client.php?id=' . $cid);
}
if ($ct && (float) db_val("SELECT COALESCE(SUM(paid_amount),0) FROM instalments WHERE contract_id = ?", [$ct['id']]) > 0) {
    flash(t('contract.locked'), 'error');
    redirect('contract.php?id=' . (int) $ct['id']);
}

$projects = [];
foreach (db_all("SELECT id, name FROM projects WHERE active = 1 OR id = ? ORDER BY name", [$ct['project_id'] ?? 0]) as $p) $projects[$p['id']] = $p['name'];
$monthOpts = month_options();
$canPct    = $ME['role'] === 'admin';

$today = date('Y-m-d');
$defaults = [
    'contract_date'  => $today,
    'official_name'  => $c['full_name'],
    'national_id'    => '',
    'nationality'    => t('contract.default_nationality'),
    'birth_date'     => '',
    'address'        => $c['city'] ?? '',
    'phone'          => $c['phone'],
    'email'          => $c['email'] ?? '',
    'second_party'   => '',
    'project_id'     => '',
    'unit_type'      => '',
    'season'         => '',
    'weeks_per_year' => '1',
    'duration_years' => '10',
    'start_year'     => date('Y'),
    'total_amount'   => '',
    'down_pct'       => setting('down_pct'),
    'months'         => '',
    'first_due_date' => add_months($today, 1),
    'notes'          => '',
];
$val = [];
foreach ($defaults as $k => $d) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') $val[$k] = post_str($k);
    elseif ($ct)                               $val[$k] = (string) ($ct[$k] ?? '');
    else                                       $val[$k] = (string) $d;
}
if ($ct && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $val['total_amount'] = rtrim(rtrim($ct['total_amount'], '0'), '.');
    $val['down_pct']     = rtrim(rtrim($ct['down_pct'], '0'), '.');
}
if (!$canPct) $val['down_pct'] = $ct ? rtrim(rtrim($ct['down_pct'], '0'), '.') : setting('down_pct');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $cDate  = valid_date($val['contract_date']);
    $first  = valid_date($val['first_due_date']);
    $birth  = $val['birth_date'] !== '' ? valid_date($val['birth_date']) : null;
    $total  = to_cents($val['total_amount']);
    $pct    = (float) $val['down_pct'];
    $months = (int) $val['months'];

    if (!$cDate)                                   $errors[] = t('contract.err.date');
    if ($val['official_name'] === '')              $errors[] = t('contract.err.name');
    if ($val['national_id'] === '')                $errors[] = t('contract.err.nid');
    if ($val['birth_date'] !== '' && !$birth)      $errors[] = t('contract.err.birth');
    if (!isset($projects[(int) $val['project_id']])) $errors[] = t('contract.err.project');
    if ($total <= 0)                               $errors[] = t('contract.err.total');
    if ($pct < 0 || $pct >= 100 || !is_numeric($val['down_pct'])) $errors[] = t('contract.err.pct');
    if (!in_array($months, $monthOpts, true))      $errors[] = t('contract.err.months');
    if (!$first)                                   $errors[] = t('contract.err.first');
    elseif ($cDate && $first < $cDate)             $errors[] = t('contract.err.first_before');
    $wpy = (int) $val['weeks_per_year'];
    $dur = (int) $val['duration_years'];
    $sy  = (int) $val['start_year'];
    if ($wpy < 1 || $wpy > 10)                     $errors[] = t('contract.err.weeks');
    if ($dur < 1 || $dur > 99)                     $errors[] = t('contract.err.duration');
    if ($sy < 2000 || $sy > 2100)                  $errors[] = t('contract.err.start');
    if ($val['email'] !== '' && !filter_var($val['email'], FILTER_VALIDATE_EMAIL)) $errors[] = t('err.bad_email');

    if (!$errors) {
        $plan = build_schedule($total, $pct, $months, $cDate, $first);
        $pdo  = db();
        $pdo->beginTransaction();
        try {
            $data = [
                $cDate, $val['official_name'], $val['national_id'], $val['nationality'] ?: null, $birth,
                $val['address'] ?: null, $val['phone'] ?: null, $val['email'] ?: null, $val['second_party'] ?: null,
                (int) $val['project_id'], $val['unit_type'] ?: null, $val['season'] ?: null, $wpy, $dur, $sy,
                setting('currency'), from_cents($total), $pct, from_cents($plan['down']), $months,
                from_cents($plan['monthly']), $first, $val['notes'] ?: null,
            ];
            if ($ct) {
                $contractId = (int) $ct['id'];
                db_run("UPDATE contracts SET contract_date=?, official_name=?, national_id=?, nationality=?, birth_date=?, address=?, phone=?, email=?, second_party=?,
                        project_id=?, unit_type=?, season=?, weeks_per_year=?, duration_years=?, start_year=?, currency=?, total_amount=?, down_pct=?, down_amount=?,
                        months=?, monthly_amount=?, first_due_date=?, notes=?, updated_at=NOW() WHERE id=?", array_merge($data, [$contractId]));
                db_run("DELETE FROM instalments WHERE contract_id = ?", [$contractId]);
            } else {
                $contractId = db_insert("INSERT INTO contracts (contract_date, official_name, national_id, nationality, birth_date, address, phone, email, second_party,
                        project_id, unit_type, season, weeks_per_year, duration_years, start_year, currency, total_amount, down_pct, down_amount,
                        months, monthly_amount, first_due_date, notes, client_id, contract_no, created_by, created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())",
                        array_merge($data, [$cid, next_contract_no(), $ME['id']]));
            }
            $st = $pdo->prepare("INSERT INTO instalments (contract_id, seq, due_date, amount) VALUES (?,?,?,?)");
            foreach ($plan['rows'] as $r) {
                $st->execute([$contractId, $r['seq'], $r['due'], from_cents($r['amount'])]);
            }
            if (!$ct) {
                db_run("UPDATE clients SET stage='contracted', closed_at=NOW(), updated_at=NOW() WHERE id=?", [$cid]);
            }
            log_event($cid, $ct ? 'contract_edited' : 'contracted',
                money(from_cents($total)) . ' · ' . t('contract.months_n', ['n' => (string) $months]));
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        flash(t('contract.saved'));
        redirect('contract.php?id=' . $contractId);
    }
}

$seasons = ['high' => t('season.high'), 'mid' => t('season.mid'), 'low' => t('season.low'), 'flex' => t('season.flex')];

layout_header(t('contract.form_title'), 'clients');
page_head(t('contract.form_title'), t('contract.form_sub', ['name' => $c['full_name']]),
    '<a class="btn btn-ghost" href="client.php?id=' . $cid . '">' . e(t('ui.back')) . '</a>');
?>
<?php if ($errors): ?><div class="alert error"><ul class="err-list"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" class="contract-form" id="contractForm">
  <?= csrf_field() ?>
  <div class="grid-main">
  <div>
    <div class="card">
      <h2>1 · <?= e(t('contract.sec.party')) ?></h2>
      <div class="grid2">
        <div class="field"><span class="lbl"><?= e(t('contract.official_name')) ?> *</span>
          <input type="text" name="official_name" value="<?= e($val['official_name']) ?>" required>
          <span class="hint"><?= e(t('contract.official_hint')) ?></span></div>
        <div class="field"><span class="lbl"><?= e(t('contract.national_id')) ?> *</span>
          <input type="text" name="national_id" value="<?= e($val['national_id']) ?>" required dir="ltr"></div>
        <div class="field"><span class="lbl"><?= e(t('contract.nationality')) ?></span>
          <input type="text" name="nationality" value="<?= e($val['nationality']) ?>"></div>
        <div class="field"><span class="lbl"><?= e(t('contract.birth_date')) ?></span>
          <input type="date" name="birth_date" value="<?= e($val['birth_date']) ?>"></div>
        <div class="field"><span class="lbl"><?= e(t('client.phone')) ?></span>
          <input type="tel" name="phone" value="<?= e($val['phone']) ?>" dir="ltr"></div>
        <div class="field"><span class="lbl"><?= e(t('client.email')) ?></span>
          <input type="email" name="email" value="<?= e($val['email']) ?>" dir="ltr"></div>
      </div>
      <div class="field"><span class="lbl"><?= e(t('contract.address')) ?></span>
        <input type="text" name="address" value="<?= e($val['address']) ?>"></div>
      <div class="field"><span class="lbl"><?= e(t('contract.second_party')) ?></span>
        <input type="text" name="second_party" value="<?= e($val['second_party']) ?>">
        <span class="hint"><?= e(t('contract.second_hint')) ?></span></div>
    </div>

    <div class="card">
      <h2>2 · <?= e(t('contract.sec.unit')) ?></h2>
      <div class="grid2">
        <div class="field"><span class="lbl"><?= e(t('contract.project')) ?> *</span>
          <select name="project_id" required><?= options($projects, $val['project_id']) ?></select>
          <?php if (!$projects): ?><span class="hint err-text"><?= e(t('contract.no_projects')) ?></span><?php endif; ?></div>
        <div class="field"><span class="lbl"><?= e(t('contract.unit_type')) ?></span>
          <input type="text" name="unit_type" value="<?= e($val['unit_type']) ?>" list="unitTypes"></div>
        <div class="field"><span class="lbl"><?= e(t('contract.season')) ?></span>
          <select name="season"><?= options($seasons, $val['season']) ?></select></div>
        <div class="field"><span class="lbl"><?= e(t('contract.weeks_per_year')) ?> *</span>
          <input type="number" name="weeks_per_year" value="<?= e($val['weeks_per_year']) ?>" min="1" max="10" required></div>
        <div class="field"><span class="lbl"><?= e(t('contract.duration_years')) ?> *</span>
          <input type="number" name="duration_years" value="<?= e($val['duration_years']) ?>" min="1" max="99" required></div>
        <div class="field"><span class="lbl"><?= e(t('contract.start_year')) ?> *</span>
          <input type="number" name="start_year" value="<?= e($val['start_year']) ?>" min="2000" max="2100" required></div>
      </div>
      <datalist id="unitTypes"><option value="Studio"><option value="1 Bedroom"><option value="2 Bedrooms"><option value="3 Bedrooms"><option value="Chalet"><option value="Villa"></datalist>
    </div>

    <div class="card">
      <h2>3 · <?= e(t('contract.sec.payment')) ?></h2>
      <div class="grid2">
        <div class="field"><span class="lbl"><?= e(t('contract.date')) ?> *</span>
          <input type="date" name="contract_date" value="<?= e($val['contract_date']) ?>" required data-calc></div>
        <div class="field"><span class="lbl"><?= e(t('contract.total')) ?> (<?= e(setting('currency')) ?>) *</span>
          <input type="text" inputmode="decimal" name="total_amount" value="<?= e($val['total_amount']) ?>" required dir="ltr" data-calc></div>
        <div class="field"><span class="lbl"><?= e(t('contract.down_pct')) ?></span>
          <input type="number" step="0.01" name="down_pct" value="<?= e($val['down_pct']) ?>" <?= $canPct ? '' : 'readonly' ?> data-calc>
          <?php if (!$canPct): ?><span class="hint"><?= e(t('contract.pct_fixed')) ?></span><?php endif; ?></div>
        <div class="field"><span class="lbl"><?= e(t('contract.months')) ?> *</span>
          <div class="seg">
            <?php foreach ($monthOpts as $m): ?>
              <label class="seg-opt"><input type="radio" name="months" value="<?= $m ?>" <?= (string) $m === $val['months'] ? 'checked' : '' ?> required data-calc><span><?= e(t('contract.months_n', ['n' => (string) $m])) ?></span></label>
            <?php endforeach; ?>
          </div></div>
        <div class="field"><span class="lbl"><?= e(t('contract.first_due')) ?> *</span>
          <input type="date" name="first_due_date" value="<?= e($val['first_due_date']) ?>" required data-calc></div>
      </div>
      <div class="field"><span class="lbl"><?= e(t('contract.notes')) ?></span>
        <textarea name="notes" rows="2"><?= e($val['notes']) ?></textarea></div>
    </div>
  </div>

  <div>
    <div class="card sticky calc-card" data-currency="<?= e(setting('currency')) ?>">
      <h2><?= e(t('contract.summary')) ?></h2>
      <div class="calc-row"><span><?= e(t('contract.total')) ?></span><strong id="cTotal">—</strong></div>
      <div class="calc-row"><span><?= e(t('pay.down')) ?> (<span id="cPct">—</span>%)</span><strong id="cDown">—</strong></div>
      <div class="calc-row"><span><?= e(t('res.remaining')) ?></span><strong id="cRest">—</strong></div>
      <div class="calc-row big"><span><?= e(t('contract.monthly')) ?> × <span id="cMonths">—</span></span><strong id="cMonthly">—</strong></div>
      <div class="calc-row" id="cLastRow" hidden><span><?= e(t('contract.last_inst')) ?></span><strong id="cLast">—</strong></div>
      <div class="calc-row"><span><?= e(t('contract.first_due')) ?></span><strong id="cFirst">—</strong></div>
      <div class="calc-row"><span><?= e(t('contract.last_due')) ?></span><strong id="cEnd">—</strong></div>
      <button class="btn btn-primary btn-block" type="submit"><?= e($ct ? t('contract.save_edit') : t('contract.save_preview')) ?></button>
      <p class="hint-line"><?= e(t('contract.save_hint')) ?></p>
    </div>
  </div>
  </div>
</form>
<?php layout_footer();
