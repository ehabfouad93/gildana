<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Owner Services → Client history. Everything that ever happened to an owner on
 * one page: every hand-off and note, payments, stays, welcome calls, finance
 * review and every WhatsApp sent.
 */
require_cap('owners.welcome', 'stays.manage');

$cid = (int) ($_GET['client'] ?? 0);

if ($cid):
    $c  = client_or_403($cid, $ME);
    $ct = db_row("SELECT ct.*, p.name AS project_name FROM contracts ct JOIN projects p ON p.id = ct.project_id WHERE ct.client_id = ?", [$cid]);
    $type = (string) ($_GET['type'] ?? '');
    $groups = [
        'pipeline' => ['created', 'edited', 'booked', 'rebooked', 'confirmed', 'no_answer', 'sent_back', 'no_show', 'cancelled', 'arrived', 'sales_assigned', 'lost', 'reopened'],
        'contract' => ['contracted', 'contract_edited', 'contract_printed', 'rescheduled', 'inst_edited', 'finance_approved', 'finance_returned', 'finance_pending'],
        'payment'  => ['payment'],
        'stay'     => ['stay_booked', 'stay_cancelled'],
        'welcome'  => ['welcome_call', 'welcome_whatsapp', 'welcome_note', 'welcome_done', 'welcome_reopened'],
        'note'     => ['note'],
    ];
    $items = [];
    foreach (db_all("SELECT e.*, u.name AS user_name, u.role AS user_role FROM client_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.client_id = ?", [$cid]) as $ev) {
        $g = 'other';
        foreach ($groups as $k => $acts) if (in_array($ev['action'], $acts, true)) { $g = $k; break; }
        if ($ev['action'] === 'whatsapp') $g = 'whatsapp';
        $items[] = ['at' => $ev['created_at'], 'group' => $g, 'title' => t('event.' . $ev['action']), 'note' => $ev['note'],
                    'who' => ($ev['user_name'] ?? '—') . ($ev['user_role'] ? ' · ' . t('role.' . $ev['user_role']) : '')];
    }
    foreach (db_all("SELECT * FROM wa_messages WHERE client_id = ? AND (trigger_key IS NULL OR trigger_key NOT IN ('manual'))", [$cid]) as $msg) {
        $items[] = ['at' => $msg['created_at'], 'group' => 'whatsapp', 'title' => t('hist.wa_auto') . ': ' . $msg['template_name'],
                    'note' => t('wa.status.' . $msg['status']) . ($msg['trigger_key'] ? ' · ' . t(wa_triggers()[$msg['trigger_key']] ?? 'wa.trg.' . $msg['trigger_key']) : '') . ($msg['error'] ? ' — ' . $msg['error'] : ''),
                    'who' => t('hist.system')];
    }
    // Newest first; within the same second, the later-recorded item first.
    foreach ($items as $n => &$it) $it['n'] = $n;
    unset($it);
    usort($items, fn($a, $b) => strcmp($b['at'], $a['at']) ?: $b['n'] <=> $a['n']);
    $counts = array_count_values(array_column($items, 'group'));
    if ($type !== '') $items = array_values(array_filter($items, fn($i) => $i['group'] === $type));
    $totals = $ct ? contract_totals((int) $ct['id']) : null;
    $year   = (int) date('Y');
    $allow  = $ct ? (week_allowance($ct)[$year] ?? null) : null;

    $actions = '<a class="btn" href="client.php?id=' . $cid . '">' . e(t('client.profile')) . '</a>';
    if ($ct) $actions .= '<a class="btn" href="reservation.php?id=' . (int) $ct['id'] . '">' . e(t('res.statement')) . '</a>';
    $actions .= '<a class="btn btn-ghost" href="history.php">' . e(t('ui.back')) . '</a>';
    layout_header(t('nav.history'), 'history');
    page_head($ct['official_name'] ?? $c['full_name'], client_code($cid) . ($ct ? ' · ' . $ct['contract_no'] : ''), $actions);
    ?>
<?php if ($ct): $cur = $ct['currency']; ?>
<div class="stats-row">
  <div class="stat-tile"><span class="lbl"><?= e(t('contract.project')) ?></span><span class="val small-val"><?= e($ct['project_name']) ?></span><span class="sub"><?= e(fmt_date($ct['contract_date'])) ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('res.paid')) ?></span><span class="val good"><?= e(money($totals['paid'], $cur)) ?></span><span class="sub"><?= e(t('res.remaining')) ?> <?= e(money($totals['remaining'], $cur)) ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('res.overdue')) ?></span><span class="val <?= (float) $totals['overdue'] > 0 ? 'danger' : '' ?>"><?= e(money($totals['overdue'], $cur)) ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('stay.this_year')) ?></span><span class="val"><?= $allow ? (int) $allow['used'] . ' / ' . (int) $allow['allowed'] : '—' ?></span><span class="sub"><?= e(t('stay.weeks')) ?></span></div>
  <div class="stat-tile"><span class="lbl"><?= e(t('nav.welcome')) ?></span><span class="val small-val"><?= welcome_pill($ct['welcome_status']) ?></span><span class="sub"><?= finance_pill($ct['finance_status']) ?></span></div>
</div>
<?php endif; ?>
<?php if ($ct): ?><?= stays_card((int) $ct['id']) ?><?php endif; ?>
<div class="chips-row">
  <a class="chip <?= $type === '' ? 'on' : '' ?>" href="?client=<?= $cid ?>"><?= e(t('tab.all')) ?> (<?= array_sum($counts) ?>)</a>
  <?php foreach (['pipeline', 'contract', 'payment', 'stay', 'welcome', 'whatsapp', 'note'] as $g): if (empty($counts[$g])) continue; ?>
    <a class="chip <?= $type === $g ? 'on' : '' ?>" href="?client=<?= $cid ?>&amp;type=<?= $g ?>"><?= e(t('hist.g.' . $g)) ?> (<?= (int) $counts[$g] ?>)</a>
  <?php endforeach; ?>
</div>
<div class="card">
  <?php if (!$items): ?><p class="text-muted"><?= e(t('hist.empty')) ?></p><?php else: ?>
  <ul class="timeline">
    <?php foreach ($items as $it): ?>
      <li class="tl-<?= e($it['group']) ?>">
        <div class="tl-head"><span class="pill gray"><?= e(t('hist.g.' . $it['group'])) ?></span> <strong><?= e($it['title']) ?></strong> <span class="text-muted"><?= e($it['who']) ?></span></div>
        <?php if ($it['note']): ?><div class="tl-note" dir="auto"><?= nl2br(e((string) $it['note'])) ?></div><?php endif; ?>
        <div class="tl-time"><?= e(fmt_dt($it['at'])) ?></div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php
else:
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = "c.stage = 'contracted'"; $params = [];
    if ($q !== '') { [$sw, $sp] = client_search_sql($q); $where .= " AND $sw"; $params = $sp; }
    $rows = db_all("SELECT c.id, c.phone, ct.official_name, ct.contract_no, p.name AS project_name,
                           (SELECT MAX(e.created_at) FROM client_events e WHERE e.client_id = c.id) AS last_at,
                           (SELECT COUNT(*) FROM client_events e WHERE e.client_id = c.id) AS n
                      FROM clients c JOIN contracts ct ON ct.client_id = c.id JOIN projects p ON p.id = ct.project_id
                     WHERE $where ORDER BY last_at DESC LIMIT 500", $params);
    layout_header(t('nav.history'), 'history');
    page_head(t('nav.history'), t('hist.sub'));
    ?>
<div class="card card-flush">
  <div class="card-head"><form method="get" class="filter-row">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('clients.search')) ?>"><button class="btn btn-sm" type="submit"><?= e(t('ui.search')) ?></button></form></div>
  <?php if (!$rows): ?><div class="empty"><?= e(t('res.empty')) ?></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th><?= e(t('search.client_no')) ?></th><th><?= e(t('contract.official_name')) ?></th><th><?= e(t('contract.no')) ?></th>
      <th><?= e(t('contract.project')) ?></th><th><?= e(t('hist.last')) ?></th><th><?= e(t('hist.events')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="mono"><?= e(client_code((int) $r['id'])) ?></td>
        <td><strong><?= e($r['official_name']) ?></strong><div class="small text-muted" dir="ltr"><?= e($r['phone']) ?></div></td>
        <td class="mono nowrap"><?= e($r['contract_no']) ?></td>
        <td><?= e($r['project_name']) ?></td>
        <td class="nowrap small text-muted"><?= e(fmt_dt($r['last_at'])) ?></td>
        <td><?= (int) $r['n'] ?></td>
        <td><a class="btn btn-sm" href="history.php?client=<?= (int) $r['id'] ?>"><?= e(t('ui.open')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif;
layout_footer();
