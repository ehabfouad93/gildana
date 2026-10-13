<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Admin → WhatsApp. Connection to the Meta WhatsApp Cloud API, the automations
 * ("when a client reaches <step>, send <template>"), and the message log.
 */
require_role('admin');

$tab  = in_array($_GET['tab'] ?? '', ['auto', 'log', 'connect'], true) ? $_GET['tab'] : (wa_ready() ? 'auto' : 'connect');
$self = fn(array $q = []) => 'whatsapp.php?' . http_build_query(array_merge(['tab' => $tab], $q));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'connect') {
        save_setting('wa_enabled', isset($_POST['wa_enabled']) ? '1' : '0');
        foreach (['wa_phone_id', 'wa_waba_id', 'wa_graph', 'wa_country'] as $k) save_setting($k, preg_replace('/[^A-Za-z0-9.]/', '', post_str($k)) ?? '');
        if (post_str('wa_token') !== '') save_setting('wa_token', post_str('wa_token'));
        flash(t('ui.saved'));
        redirect('whatsapp.php?tab=connect');
    }
    if ($do === 'test_connection') {
        [$ok, $res] = wa_graph('GET', rawurlencode(setting('wa_phone_id')) . '?fields=display_phone_number,verified_name,quality_rating');
        flash($ok ? t('wa.conn_ok', ['num' => (string) ($res['display_phone_number'] ?? ''), 'name' => (string) ($res['verified_name'] ?? '')])
                  : t('wa.conn_fail', ['err' => (string) $res]), $ok ? 'success' : 'error');
        redirect('whatsapp.php?tab=connect');
    }
    if ($do === 'refresh') {
        [$ok, $res] = wa_fetch_templates();
        flash($ok ? t('wa.tpl_loaded', ['n' => (string) count($res)]) : t('wa.conn_fail', ['err' => (string) $res]), $ok ? 'success' : 'error');
        redirect('whatsapp.php?tab=auto');
    }
    if ($do === 'save_auto') {
        $aid  = (int) ($_POST['id'] ?? 0);
        $trg  = (string) ($_POST['trigger_key'] ?? '');
        $pick = (string) ($_POST['template_pick'] ?? '');
        $name = post_str('template_name');
        $lang = post_str('language');
        if ($pick !== '' && str_contains($pick, '|')) [$name, $lang] = explode('|', $pick, 2);
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($name)) ?? '';
        $lang = preg_replace('/[^A-Za-z_]/', '', $lang) ?: 'ar';
        $keys = array_values(array_intersect(wa_param_keys($_POST['params'] ?? ''), wa_var_keys()));
        $head = in_array($_POST['header_param'] ?? '', wa_var_keys(), true) ? (string) $_POST['header_param'] : null;
        if (!isset(wa_triggers()[$trg]) || $name === '') {
            flash(t('wa.auto_err'), 'error');
            redirect('whatsapp.php?tab=auto' . ($aid ? '&edit=' . $aid : ''));
        }
        $data = [$trg, $name, $lang, implode("\n", $keys), $head, isset($_POST['active']) ? 1 : 0];
        if ($aid) db_run("UPDATE wa_automations SET trigger_key=?, template_name=?, language=?, params=?, header_param=?, active=? WHERE id=?", array_merge($data, [$aid]));
        else      db_insert("INSERT INTO wa_automations (trigger_key, template_name, language, params, header_param, active, created_at) VALUES (?,?,?,?,?,?,NOW())", $data);
        flash(t('ui.saved'));
        redirect('whatsapp.php?tab=auto');
    }
    if ($do === 'toggle') {
        db_run("UPDATE wa_automations SET active = 1 - active WHERE id = ?", [(int) $_POST['id']]);
        redirect('whatsapp.php?tab=auto');
    }
    if ($do === 'delete') {
        db_run("DELETE FROM wa_automations WHERE id = ?", [(int) $_POST['id']]);
        flash(t('ui.deleted'));
        redirect('whatsapp.php?tab=auto');
    }
    if ($do === 'test') {
        $a   = db_row("SELECT * FROM wa_automations WHERE id = ?", [(int) $_POST['id']]);
        $cid = (int) ltrim(post_str('client_id'), '#');
        if (!$a || !$cid || !load_client($cid)) { flash(t('wa.test_need_client'), 'error'); redirect('whatsapp.php?tab=auto'); }
        [$ok, $res] = wa_send_to_client($cid, $a, [], 'test');
        flash($ok ? t('wa.flash_sent', ['n' => '1']) : t('wa.send_failed', ['err' => (string) $res]), $ok ? 'success' : 'error');
        redirect('whatsapp.php?tab=auto');
    }
    if ($do === 'resend') {
        $m = db_row("SELECT * FROM wa_messages WHERE id = ?", [(int) $_POST['id']]);
        $a = $m && $m['automation_id'] ? db_row("SELECT * FROM wa_automations WHERE id = ?", [$m['automation_id']]) : null;
        if (!$m || !$a || !$m['client_id']) { flash(t('wa.resend_gone'), 'error'); redirect('whatsapp.php?tab=log'); }
        [$ok, $res] = wa_send_to_client((int) $m['client_id'], $a, [], $m['trigger_key']);
        flash($ok ? t('wa.flash_sent', ['n' => '1']) : t('wa.send_failed', ['err' => (string) $res]), $ok ? 'success' : 'error');
        redirect('whatsapp.php?tab=log');
    }
}

$templates = wa_cached_templates();
$tplIndex  = [];
foreach ($templates as $tp) $tplIndex[$tp['name'] . '|' . $tp['language']] = $tp;
$trgOpts = [];
foreach (wa_triggers() as $k => $lbl) $trgOpts[$k] = t($lbl);
$varOpts = [];
foreach (wa_var_keys() as $k) $varOpts[$k] = t('wa.var.' . $k) . ' {' . $k . '}';

layout_header(t('nav.whatsapp'), 'whatsapp');
page_head(t('nav.whatsapp'), t('wa.sub'));
echo tabs(['auto' => ['label' => t('wa.tab_auto')], 'log' => ['label' => t('wa.tab_log')], 'connect' => ['label' => t('wa.tab_connect')]], $tab);
if (!wa_ready() && $tab !== 'connect'): ?>
  <div class="alert warn"><?= e(t('wa.not_ready')) ?> <a href="whatsapp.php?tab=connect"><?= e(t('wa.tab_connect')) ?></a></div>
<?php endif;

/* ───────── connection ───────── */
if ($tab === 'connect'): ?>
<div class="grid-main">
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="do" value="connect">
    <h2><?= e(t('wa.tab_connect')) ?> <?= wa_ready() ? '<span class="pill green dot">' . e(t('ui.active')) . '</span>' : '<span class="pill gray dot">' . e(t('ui.inactive')) . '</span>' ?></h2>
    <label class="check"><input type="checkbox" name="wa_enabled" value="1" <?= setting('wa_enabled') === '1' ? 'checked' : '' ?>> <?= e(t('wa.enabled')) ?></label>
    <div class="field" style="margin-top:12px"><span class="lbl"><?= e(t('wa.token')) ?></span>
      <input type="password" name="wa_token" autocomplete="off" placeholder="<?= setting('wa_token') !== '' ? e(t('wa.token_saved')) : '' ?>" dir="ltr">
      <span class="hint"><?= e(t('wa.token_hint')) ?></span></div>
    <div class="grid2">
      <div class="field"><span class="lbl"><?= e(t('wa.phone_id')) ?></span><input type="text" name="wa_phone_id" value="<?= e(setting('wa_phone_id')) ?>" dir="ltr"></div>
      <div class="field"><span class="lbl"><?= e(t('wa.waba_id')) ?></span><input type="text" name="wa_waba_id" value="<?= e(setting('wa_waba_id')) ?>" dir="ltr"></div>
      <div class="field"><span class="lbl"><?= e(t('wa.country')) ?></span><input type="text" name="wa_country" value="<?= e(setting('wa_country')) ?>" dir="ltr">
        <span class="hint"><?= e(t('wa.country_hint')) ?></span></div>
      <div class="field"><span class="lbl"><?= e(t('wa.graph')) ?></span><input type="text" name="wa_graph" value="<?= e(setting('wa_graph')) ?>" dir="ltr"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
      <button class="btn" type="submit" name="do" value="test_connection" formnovalidate><?= e(t('wa.test_conn')) ?></button>
    </div>
  </form>
  <div class="card">
    <h2><?= e(t('wa.howto_title')) ?></h2>
    <ol class="howto"><?php for ($n = 1; $n <= 5; $n++): ?><li><?= e(t('wa.howto' . $n)) ?></li><?php endfor; ?></ol>
  </div>
</div>
<?php

/* ───────── automations ───────── */
elseif ($tab === 'auto'):
    $autos = db_all("SELECT a.*, (SELECT COUNT(*) FROM wa_messages m WHERE m.automation_id = a.id AND m.status = 'sent') AS sent_n
                       FROM wa_automations a ORDER BY FIELD(a.trigger_key,'" . implode("','", array_keys(wa_triggers())) . "'), a.id");
    $edit  = isset($_GET['edit']) ? db_row("SELECT * FROM wa_automations WHERE id = ?", [(int) $_GET['edit']]) : null;
    $editKeys = wa_param_keys($edit['params'] ?? '');
?>
<div class="grid-main">
<div class="card card-flush">
  <div class="card-head"><h2><?= e(t('wa.tab_auto')) ?></h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="refresh">
      <button class="btn btn-sm" type="submit"><?= e(t('wa.refresh')) ?></button></form></div>
  <?php if (!$autos): ?><div class="empty"><?= e(t('wa.auto_empty')) ?></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th><?= e(t('wa.when')) ?></th><th><?= e(t('wa.template')) ?></th><th><?= e(t('wa.params')) ?></th><th><?= e(t('wa.sent_n')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($autos as $a): ?>
      <tr class="<?= $a['active'] ? '' : 'row-muted' ?>">
        <td><strong><?= e(t(wa_triggers()[$a['trigger_key']] ?? $a['trigger_key'])) ?></strong></td>
        <td class="mono"><?= e($a['template_name']) ?> <span class="text-muted">(<?= e($a['language']) ?>)</span></td>
        <td class="small"><?php foreach (wa_param_keys($a['params']) as $n => $k): ?><span class="chip-sm">{{<?= $n + 1 ?>}} <?= e(t('wa.var.' . $k)) ?></span> <?php endforeach; ?></td>
        <td><?= (int) $a['sent_n'] ?></td>
        <td class="nowrap">
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button class="btn btn-sm" type="submit"><?= e($a['active'] ? t('wa.pause') : t('wa.resume')) ?></button></form>
          <a class="btn btn-sm" href="?tab=auto&amp;edit=<?= (int) $a['id'] ?>"><?= e(t('ui.edit')) ?></a>
          <button class="btn btn-sm btn-ghost" type="button" data-toggle="test<?= (int) $a['id'] ?>"><?= e(t('wa.test')) ?></button>
          <form method="post" class="inline" data-confirm-form="<?= e(t('wa.delete_q')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button class="btn btn-sm btn-danger-ghost" type="submit">✕</button></form>
        </td>
      </tr>
      <tr class="pay-form-row" id="test<?= (int) $a['id'] ?>" hidden><td colspan="5">
        <form method="post" class="pay-form"><?= csrf_field() ?><input type="hidden" name="do" value="test"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
          <label><span><?= e(t('wa.test_client')) ?></span><input type="text" name="client_id" placeholder="#12" required></label>
          <button class="btn btn-primary btn-sm" type="submit"><?= e(t('wa.send_btn')) ?></button>
          <span class="small text-muted"><?= e(t('wa.test_hint')) ?></span></form></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<form method="post" class="card action-card" id="autoForm">
  <?= csrf_field() ?><input type="hidden" name="do" value="save_auto"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <h2><?= e($edit ? t('wa.auto_edit') : t('wa.auto_add')) ?></h2>
  <div class="field"><span class="lbl"><?= e(t('wa.when')) ?> *</span>
    <select name="trigger_key" required><?= options($trgOpts, $edit['trigger_key'] ?? '') ?></select></div>

  <?php if ($templates): ?>
  <div class="field"><span class="lbl"><?= e(t('wa.template')) ?> *</span>
    <select name="template_pick" id="tplPick">
      <option value="">—</option>
      <?php foreach ($templates as $tp): $key = $tp['name'] . '|' . $tp['language']; ?>
        <option value="<?= e($key) ?>" data-body="<?= e($tp['body']) ?>" data-vars="<?= (int) $tp['body_vars'] ?>" data-hvars="<?= (int) $tp['header_vars'] ?>"
          <?= $edit && $key === $edit['template_name'] . '|' . $edit['language'] ? 'selected' : '' ?>><?= e($tp['name'] . ' (' . $tp['language'] . ')') ?></option>
      <?php endforeach; ?>
    </select>
    <div class="tpl-preview" id="tplBody" dir="auto" hidden></div></div>
  <?php else: ?>
    <p class="hint-line"><?= e(t('wa.no_cache')) ?></p>
  <?php endif; ?>
  <details <?= $templates ? '' : 'open' ?>><summary class="small text-muted"><?= e(t('wa.manual_tpl')) ?></summary>
    <div class="grid2">
      <div class="field"><span class="lbl"><?= e(t('wa.tpl_name')) ?></span><input type="text" name="template_name" value="<?= e($edit['template_name'] ?? '') ?>" dir="ltr"></div>
      <div class="field"><span class="lbl"><?= e(t('wa.tpl_lang')) ?></span><input type="text" name="language" value="<?= e($edit['language'] ?? 'ar') ?>" dir="ltr"></div>
    </div>
  </details>

  <div class="field"><span class="lbl"><?= e(t('wa.params')) ?></span>
    <textarea name="params" id="paramsBox" rows="4" dir="ltr" placeholder="name&#10;meeting_date&#10;meeting_time"><?= e(implode("\n", $editKeys)) ?></textarea>
    <span class="hint"><?= e(t('wa.params_hint')) ?></span>
    <div class="var-chips"><?php foreach (wa_var_keys() as $k): ?><button class="chip" type="button" data-var="<?= e($k) ?>"><?= e(t('wa.var.' . $k)) ?></button><?php endforeach; ?></div>
  </div>
  <div class="field"><span class="lbl"><?= e(t('wa.header_param')) ?></span>
    <select name="header_param"><?= options($varOpts, $edit['header_param'] ?? '') ?></select>
    <span class="hint"><?= e(t('wa.header_hint')) ?></span></div>
  <label class="check"><input type="checkbox" name="active" value="1" <?= !$edit || $edit['active'] ? 'checked' : '' ?>> <?= e(t('ui.active')) ?></label>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
    <?php if ($edit): ?><a class="btn btn-ghost" href="whatsapp.php?tab=auto"><?= e(t('ui.cancel')) ?></a><?php endif; ?>
  </div>
</form>
</div>
<?php

/* ───────── log ───────── */
else:
    $st   = in_array($_GET['status'] ?? '', ['sent', 'failed', 'skipped'], true) ? $_GET['status'] : '';
    $rows = db_all("SELECT m.*, c.full_name FROM wa_messages m LEFT JOIN clients c ON c.id = m.client_id"
                 . ($st ? " WHERE m.status = ?" : '') . " ORDER BY m.id DESC LIMIT 300", $st ? [$st] : []);
?>
<div class="card card-flush">
  <div class="card-head">
    <form method="get" class="filter-row"><input type="hidden" name="tab" value="log">
      <select name="status" data-autosubmit><?= options(['' => t('res.f_all'), 'sent' => t('wa.status.sent'), 'failed' => t('wa.status.failed'), 'skipped' => t('wa.status.skipped')], $st, false) ?></select></form>
  </div>
  <?php if (!$rows): ?><div class="empty"><?= e(t('wa.log_empty')) ?></div><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th><?= e(t('wa.time')) ?></th><th><?= e(t('client.full_name')) ?></th><th><?= e(t('wa.to')) ?></th><th><?= e(t('wa.template')) ?></th>
      <th><?= e(t('wa.when')) ?></th><th><?= e(t('pay.status')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $m): ?>
      <tr>
        <td class="nowrap small"><?= e(fmt_dt($m['created_at'])) ?></td>
        <td><?php if ($m['client_id']): ?><a href="client.php?id=<?= (int) $m['client_id'] ?>"><?= e(client_code((int) $m['client_id'])) ?> <?= e($m['full_name'] ?? '') ?></a><?php endif; ?></td>
        <td dir="ltr" class="mono"><?= e($m['to_phone']) ?></td>
        <td class="mono"><?= e($m['template_name']) ?> <span class="text-muted">(<?= e($m['language']) ?>)</span></td>
        <td class="small"><?= e($m['trigger_key'] ? t(wa_triggers()[$m['trigger_key']] ?? 'wa.trg.' . $m['trigger_key']) : '—') ?></td>
        <td><?= wa_status_pill($m['status']) ?><?php if ($m['error']): ?><div class="small danger-text" dir="auto"><?= e($m['error']) ?></div><?php endif; ?></td>
        <td><?php if ($m['status'] !== 'sent' && $m['automation_id']): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="resend"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <button class="btn btn-sm" type="submit"><?= e(t('wa.resend')) ?></button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif;
layout_footer();
