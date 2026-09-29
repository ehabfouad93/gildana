<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm_sales_events.php';
require_once __DIR__ . '/../includes/crm_list.php';
require_once __DIR__ . '/../includes/crm_control.php';

/**
 * Sales events: an open day, a launch, a webinar. A manager creates it and chooses the WhatsApp
 * invitation; leads are added from here (by project, stage, heat…) or from the leads list; then
 * everyone not yet invited gets the invitation in one go. Afterwards: who said yes, who came,
 * who bought. A salesperson sees and marks only their own leads.
 */

$cid = (int) $CLIENT['id'];
$me = function_exists('crm_actor_id') ? crm_actor_id() : (int) $ME['id'];
$isAdmin = is_client_admin();
$canW = can_write();
$eid = (int) ($_GET['id'] ?? $_POST['event'] ?? 0);
$err = '';

if (!sev_ready()) {
    client_header('Events', 'crm', $CLIENT); page_head('Events');
    echo '<div class="card"><p>Run the latest update (migration 056) to use events.</p></div>';
    layout_footer(); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');
    $back = fn(string $h = '') => redirect('crm_events.php' . ($eid ? '?id=' . $eid : '') . $h);

    if ($a === 'save' && $isAdmin) {
        $r = sev_save($CLIENT, $_POST + ['invite_vars' => crm_tokens_from_post('ivars', $_POST), 'remind_vars' => crm_tokens_from_post('rvars', $_POST)], $me, $eid);
        if ($r['ok']) { flash($eid ? 'Event saved.' : 'Event created. Now add the leads to invite.'); redirect('crm_events.php?id=' . $r['id'] . '#guests'); }
        $err = $r['error'];
    }
    if ($a === 'cancel' && $isAdmin && $eid) { sev_cancel($CLIENT, $eid); flash('Event cancelled. Nothing more will be sent for it.'); $back(); }
    if ($a === 'add' && $canW && $eid) {
        $f = crm_list_filters(array_intersect_key($_POST, array_flip(['project', 'stage', 'state', 'owner', 'heat', 'source', 'campaign', 'q'])));
        if (!array_filter($f)) { flash('Choose at least one filter, so the whole account is not added by mistake.', 'error'); $back('#guests'); }
        [$w, $p] = crm_list_where($cid, $f);
        $ids = array_column(db_all("SELECT c.id FROM contacts c JOIN crm_stages s ON s.id=c.stage_id WHERE $w LIMIT 5000", $p), 'id');
        $n = sev_add_guests($CLIENT, $eid, $ids, $me);
        flash($n . ' lead' . ($n === 1 ? '' : 's') . ' added.' . (count($ids) > $n ? ' ' . (count($ids) - $n) . ' were already on the list.' : ''));
        $back('#guests');
    }
    if ($a === 'invite' && $isAdmin && $eid) {
        $r = sev_invite($CLIENT, $eid, $me);
        if ($r['ok']) flash($r['n'] . ' invitation' . ($r['n'] === 1 ? '' : 's') . ' queued. They go out within your working hours.');
        else flash($r['error'], 'error');
        $back('#guests');
    }
    if ($a === 'mark' && $canW && $eid) {
        $n = sev_mark($CLIENT, $eid, (array) ($_POST['g'] ?? []), (string) ($_POST['status'] ?? ''), $me);
        flash($n ? $n . ' updated.' : 'Tick the guests first.', $n ? 'success' : 'error');
        $back('#guests');
    }
}

$ev = $eid ? sev_get($cid, $eid) : null;
if ($eid && !$ev) { flash('Event not found.', 'error'); redirect('crm_events.php'); }
$tpls = crm_tpl_choices($cid);
$projects = crm_project_names($cid);
$stages = crm_stage_map($cid);
$statuses = sev_statuses();

client_header($ev ? $ev['name'] : 'Events', 'crm', $CLIENT);
page_head($ev ? (string) $ev['name'] : 'Events', $ev ? '<a class="btn btn-ghost btn-sm" href="crm_events.php">&larr; All events</a>'
    : ($isAdmin ? '<a class="btn btn-primary btn-sm" href="#new" onclick="document.getElementById(\'new\').hidden=false">+ New event</a>' : ''));
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif;

/* ── the event form (new, or editing one) ── */
$form = function (?array $ev) use ($isAdmin, $tpls, $projects) {
    if (!$isAdmin) return;
    $at = $ev ? strtotime((string) $ev['starts_at']) : strtotime('+7 days 11:00'); ?>
  <form method="post" class="ev-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($ev): ?><input type="hidden" name="event" value="<?= (int) $ev['id'] ?>"><?php endif; ?>
    <div class="grid2">
      <div class="field"><span class="lbl">Name</span><input type="text" name="name" maxlength="150" required value="<?= e((string) ($ev['name'] ?? '')) ?>" placeholder="Open day at Badya"></div>
      <div class="field"><span class="lbl">Project</span><select name="project_id"><option value="0">—</option>
        <?php foreach ($projects as $pid => $pn): ?><option value="<?= (int) $pid ?>" <?= (int) ($ev['project_id'] ?? 0) === (int) $pid ? 'selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?></select></div>
      <div class="field"><span class="lbl">Date</span><input type="date" name="date" required value="<?= e(date('Y-m-d', $at)) ?>"></div>
      <div class="field"><span class="lbl">Time</span><input type="time" name="time" step="900" required value="<?= e(date('H:i', $at)) ?>"></div>
      <div class="field"><span class="lbl">Where</span><input type="text" name="place" maxlength="500" value="<?= e((string) ($ev['place'] ?? '')) ?>" placeholder="The address, a map link, or the online link"></div>
      <div class="field"><span class="lbl">How long</span><select name="duration">
        <?php foreach ([60 => '1 hour', 120 => '2 hours', 180 => '3 hours', 240 => '4 hours', 480 => 'All day'] as $m => $l): ?>
          <option value="<?= $m ?>" <?= (int) ($ev['duration_min'] ?? 120) === $m ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="grid2">
      <div><div class="field"><span class="lbl">WhatsApp invitation</span>
        <select name="invite_tpl" class="ev-tpl" data-box="iv" data-prefix="ivars" data-saved="<?= e((string) ($ev['invite_vars'] ?? '[]')) ?>"><option value="0">Choose a template…</option>
          <?php foreach ($tpls as $t): if ($t['media'] !== '') continue; ?><option value="<?= $t['id'] ?>" <?= (int) ($ev['invite_tpl'] ?? 0) === $t['id'] ? 'selected' : '' ?>><?= e($t['name'] . ' (' . $t['lang'] . ')') ?></option><?php endforeach; ?></select></div>
        <div class="tpl-vars" id="iv"></div></div>
      <div><div class="field"><span class="lbl">Reminder, the day before</span>
        <select name="remind_tpl" class="ev-tpl" data-box="rv" data-prefix="rvars" data-saved="<?= e((string) ($ev['remind_vars'] ?? '[]')) ?>"><option value="0">Don't send</option>
          <?php foreach ($tpls as $t): if ($t['media'] !== '') continue; ?><option value="<?= $t['id'] ?>" <?= (int) ($ev['remind_tpl'] ?? 0) === $t['id'] ? 'selected' : '' ?>><?= e($t['name'] . ' (' . $t['lang'] . ')') ?></option><?php endforeach; ?></select></div>
        <div class="tpl-vars" id="rv"></div></div>
    </div>
    <?php if (!$tpls): ?><p class="note warn">No approved templates yet. Create one on the Templates page — invitations must use an approved template.</p><?php endif; ?>
    <div class="field"><span class="lbl">Note for the team</span><input type="text" name="notes" maxlength="1000" value="<?= e((string) ($ev['notes'] ?? '')) ?>"></div>
    <button class="btn btn-primary"><?= $ev ? 'Save event' : 'Create event' ?></button>
  </form>
<?php };

if (!$ev):
    $up = sev_list($cid, false); $past = sev_list($cid, true);
    $row = function (array $e) { $cancelled = $e['status'] === 'cancelled'; ?>
      <tr>
        <td><a href="crm_events.php?id=<?= (int) $e['id'] ?>"><strong><?= e((string) $e['name']) ?></strong></a>
          <?php if ($cancelled): ?> <span class="pill gray">Cancelled</span><?php endif; ?>
          <span class="text-muted" style="display:block;font-size:12.5px"><?= e(implode(' · ', array_filter([(string) ($e['project_name'] ?? ''), (string) ($e['place'] ?? '')]))) ?></span></td>
        <td><?= e(date('D j M Y, H:i', strtotime((string) $e['starts_at']))) ?></td>
        <td class="num"><?= (int) $e['guests'] ?></td><td class="num"><?= (int) $e['invited'] ?></td>
        <td class="num"><?= (int) $e['said_yes'] ?></td><td class="num"><?= (int) $e['came'] ?></td><td class="num"><?= (int) $e['bought'] ?></td>
      </tr>
    <?php };
    $head = '<thead><tr><th>Event</th><th>When</th><th class="num">Guests</th><th class="num">Invited</th><th class="num">Said yes</th><th class="num">Came</th><th class="num">Bought</th></tr></thead>'; ?>
  <div class="card" id="new" <?= $err || isset($_GET['new']) ? '' : 'hidden' ?>><h2>New event</h2><?php $form(null); ?></div>
  <div class="card card-flush"><div style="padding:14px 18px"><h2 style="margin:0;border:0;padding:0">Coming up</h2></div>
    <?php if (!$up): ?><p class="text-muted" style="padding:0 18px 16px;margin:0">No events planned.<?= $isAdmin ? ' Create one with “+ New event”.' : '' ?></p>
    <?php else: ?><div class="table-wrap"><table class="data"><?= $head ?><tbody><?php foreach ($up as $e) $row($e); ?></tbody></table></div><?php endif; ?></div>
  <?php if ($past): ?>
  <div class="card card-flush"><div style="padding:14px 18px"><h2 style="margin:0;border:0;padding:0">Past events</h2></div>
    <div class="table-wrap"><table class="data"><?= $head ?><tbody><?php foreach ($past as $e) $row($e); ?></tbody></table></div></div>
  <?php endif; ?>
<?php else:
    $filter = (string) ($_GET['st'] ?? '');
    $guests = sev_guests($cid, $eid, $filter);
    $all = sev_guests($cid, $eid);
    $count = array_count_values(array_column($all, 'status'));
    $bought = count(array_filter($all, fn($g) => $g['status'] === 'attended' && $g['stage_kind'] === 'won'));
    $isPast = strtotime((string) $ev['starts_at']) < time();
    $active = $ev['status'] === 'active'; ?>
  <div class="card">
    <div class="row-between" style="flex-wrap:wrap;gap:8px">
      <div><strong><?= e(date('l j F Y, H:i', strtotime((string) $ev['starts_at']))) ?></strong>
        <?php if (!$active): ?> <span class="pill gray">Cancelled</span><?php endif; ?>
        <span class="text-muted" style="display:block;font-size:13px"><?= e(implode(' · ', array_filter([$projects[(int) $ev['project_id']] ?? '', (string) ($ev['place'] ?? '')]))) ?></span>
        <?php if (!empty($ev['notes'])): ?><span style="display:block;font-size:13px;margin-top:4px"><?= e((string) $ev['notes']) ?></span><?php endif; ?></div>
      <?php if ($isAdmin && $active): ?>
      <div style="display:flex;gap:8px">
        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('ev-edit').hidden=!document.getElementById('ev-edit').hidden">Edit</button>
        <form method="post" onsubmit="return confirm('Cancel this event? Invitations and reminders not yet sent will not go out.')"><?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel"><input type="hidden" name="event" value="<?= $eid ?>"><button class="btn-link" style="color:var(--danger)">Cancel event</button></form>
      </div>
      <?php endif; ?>
    </div>
    <div id="ev-edit" class="mt10" hidden><?php $form($ev); ?></div>
  </div>

  <div class="crm-tiles">
    <?php foreach (['' => ['Guests', count($all)], 'added' => ['Not invited yet', $count['added'] ?? 0], 'invited' => ['Invited, no answer', $count['invited'] ?? 0],
                    'confirmed' => ['Said yes', ($count['confirmed'] ?? 0)], 'attended' => ['Came', $count['attended'] ?? 0]] as $k => [$l, $n]): ?>
      <a class="crm-tile <?= $filter === $k ? 'on' : '' ?>" href="?id=<?= $eid ?><?= $k !== '' ? '&st=' . $k : '' ?>#guests"><span class="lbl"><?= e($l) ?></span><span class="val"><?= (int) $n ?></span></a>
    <?php endforeach; ?>
  </div>
  <?php if ($isPast && ($count['attended'] ?? 0)): ?><p class="text-muted" style="margin-top:-4px">Of those who came, <?= $bought ?> have bought so far.</p><?php endif; ?>

  <?php if ($canW && $active && !$isPast): ?>
  <div class="card">
    <h2>Add leads</h2>
    <p class="text-muted" style="font-size:12.5px;margin-top:-4px">Everyone matching these goes on the list<?= is_sales() ? ' — only your own leads' : '' ?>.
      You can also tick leads on the <a href="crm.php?view=table">leads list</a> and choose “Add to event”.</p>
    <form method="post" class="ev-add mt10"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="event" value="<?= $eid ?>">
      <select name="project"><option value="">Any project</option><?php foreach ($projects as $pid => $pn): ?><option value="<?= (int) $pid ?>" <?= (int) $ev['project_id'] === (int) $pid ? 'selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?></select>
      <select name="state"><option value="open">Open leads</option><option value="">Open, won and lost</option></select>
      <select name="stage"><option value="">Any stage</option><?php foreach ($stages as $sid => $st): ?><option value="<?= (int) $sid ?>"><?= e($st['name']) ?></option><?php endforeach; ?></select>
      <select name="heat"><option value="">Any heat</option><option value="hot">Hot</option><option value="warm">Warm</option><option value="cold">Cold</option></select>
      <input type="text" name="campaign" placeholder="Campaign" maxlength="160">
      <button class="btn btn-primary btn-sm">Add to the list</button>
    </form>
    <?php if ($isAdmin && ($count['added'] ?? 0)): ?>
      <form method="post" class="mt10" onsubmit="return confirm('Send the invitation on WhatsApp to <?= (int) $count['added'] ?> lead(s)?')"><?= csrf_field() ?>
        <input type="hidden" name="action" value="invite"><input type="hidden" name="event" value="<?= $eid ?>">
        <button class="btn btn-primary" <?= (int) $ev['invite_tpl'] ? '' : 'disabled' ?>>Send invitation to <?= (int) $count['added'] ?> not yet invited</button>
        <?php if (!(int) $ev['invite_tpl']): ?><span class="text-muted" style="font-size:12.5px"> Choose the invitation template under Edit first.</span><?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card card-flush" id="guests">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark"><input type="hidden" name="event" value="<?= $eid ?>">
    <div style="padding:14px 18px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <h2 style="margin:0;border:0;padding:0;flex:1">Guests<?= $filter !== '' ? ' — ' . e($statuses[$filter] ?? '') : '' ?></h2>
      <?php if ($canW && $guests): ?>
        <select name="status" aria-label="Mark the ticked guests" style="width:auto"><option value="">Mark ticked as…</option>
          <?php foreach ($isPast ? ['attended', 'no_show', 'confirmed', 'declined'] : ['confirmed', 'declined', 'invited', 'attended', 'no_show'] as $s): ?><option value="<?= $s ?>"><?= e($statuses[$s]) ?></option><?php endforeach; ?></select>
        <button class="btn btn-ghost btn-sm">Save</button>
      <?php endif; ?>
    </div>
    <?php if (!$guests): ?><p class="text-muted" style="padding:0 18px 16px;margin:0">No guests<?= $filter !== '' ? ' here' : ' yet' ?>.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><?php if ($canW): ?><th style="width:32px"><input type="checkbox" aria-label="Tick all" onclick="document.querySelectorAll('.ev-g').forEach(c=>c.checked=this.checked)"></th><?php endif; ?>
        <th>Lead</th><th>Stage</th><th>Salesperson</th><th>Answer</th><th>Invited</th></tr></thead>
      <tbody><?php foreach ($guests as $g): ?>
        <tr><?php if ($canW): ?><td><input type="checkbox" class="ev-g" name="g[]" value="<?= (int) $g['id'] ?>" aria-label="Tick <?= e((string) $g['name']) ?>"></td><?php endif; ?>
          <td><a href="crm_lead.php?id=<?= (int) $g['contact_id'] ?>"><strong><?= e((string) ($g['name'] ?: 'No name')) ?></strong></a>
            <span class="text-muted ltr" style="display:block;font-size:12px"><?= e(crm_phone_show((string) $g['phone_e164'])) ?></span></td>
          <td><?= e((string) $g['stage']) ?></td><td><?= e((string) ($g['owner_name'] ?? '—')) ?></td>
          <td><span class="pill <?= ['added' => 'gray', 'invited' => 'blue', 'confirmed' => 'green', 'declined' => 'gray', 'attended' => 'green', 'no_show' => 'red'][$g['status']] ?? 'gray' ?>"><?= e($statuses[$g['status']] ?? $g['status']) ?></span></td>
          <td><?= $g['invited_at'] ? e(date('j M, H:i', strtotime((string) $g['invited_at']))) : '—' ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    </form>
  </div>
<?php endif; ?>

<script>
/* The template's {{n}}: what fills each one. */
(function(){
  const TPLS = <?= json_encode($tpls, JSON_UNESCAPED_UNICODE) ?>;
  const TOKENS = <?= json_encode(crm_tpl_tokens(), JSON_UNESCAPED_UNICODE) ?>;
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const guess = ['first_name', 'event_name', 'event_date', 'event_time', 'event_place', 'owner_name'];
  function draw(sel, saved){
    const box = document.getElementById(sel.dataset.box), t = TPLS[sel.value], prefix = sel.dataset.prefix;
    box.innerHTML = ''; if (!t) return;
    for (let i = 0; i < t.body + t.header_vars; i++) {
      const cur = saved[i] || guess[i] || 'name', isText = cur.startsWith('text:');
      const row = document.createElement('div'); row.className = 'tpl-var';
      row.innerHTML = `<span class="tpl-var-n">{{${i + 1}}}</span><select name="${prefix}[${i}]">${Object.entries(TOKENS).map(([k, l]) =>
        `<option value="${k}" ${(isText ? 'text' : cur) === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>
        <input type="text" name="${prefix}_text[${i}]" value="${isText ? esc(cur.slice(5)) : ''}" placeholder="Type the text" ${isText ? '' : 'hidden'}>`;
      row.querySelector('select').onchange = e => { row.querySelector('input').hidden = e.target.value !== 'text'; };
      box.appendChild(row);
    }
  }
  document.querySelectorAll('.ev-tpl').forEach(sel => {
    let saved = []; try { saved = JSON.parse(sel.dataset.saved || '[]') || []; } catch (e) {}
    draw(sel, saved); sel.addEventListener('change', () => draw(sel, []));
  });
})();
</script>
<?php layout_footer();
