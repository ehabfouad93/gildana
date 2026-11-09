<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];
$err = ''; $syncMsg = '';
// Templates only exist on the Cloud API. A personal number sends ordinary messages, so this
// page is informational there rather than an error about missing Meta credentials.
$PERSONAL = channel_is_personal($CLIENT);

/* ── Sync templates from the client's WABA ──
   And from the WhatsApp Business Account of every extra Business API number in another one
   (Settings → WhatsApp numbers): their templates are kept with that account's id (waba_id). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    verify_csrf();
    require_once __DIR__ . '/../includes/sending.php';
    $sources = [];                                             // waba id => client row to read it with ('' = main)
    if (!$PERSONAL && client_ready($CLIENT) && $CLIENT['waba_id']) $sources[''] = $CLIENT;
    if (wa_lines_ready()) {
        foreach (wa_lines($CLIENT) as $k => $l) {
            if ($k[0] !== 'n' || $l['kind'] !== 'cloud' || $l['waba'] === '' || $l['waba'] === (string) $CLIENT['waba_id'] || isset($sources[$l['waba']])) continue;
            if ($lc = wa_line_client($CLIENT, $k)) $sources[$l['waba']] = $lc;
        }
    }
    if (!$sources) {
        $err = $PERSONAL ? 'Your account sends from your own WhatsApp number, which has no approved templates to sync.'
                         : 'Your WhatsApp account is not fully configured yet (WABA ID / token missing). Contact Gildana.';
    } else {
        $seen = []; $found = 0; $errs = [];
        require_once __DIR__ . '/../includes/template_builder.php';
        // Revenect's own copies of sample pictures (templates made here) survive the sync.
        $oldComps = [];
        foreach (db_all("SELECT wa_name, language, components FROM templates WHERE client_id=?", [$cid]) as $o) $oldComps[$o['wa_name'] . '|' . $o['language']] = json_decode((string) $o['components'], true) ?: [];
        $hasMeta = db_has_column('templates', 'meta_id');
        foreach ($sources as $waba => $src) {
            $res = wa_fetch_templates($src);
            if (!$res['ok']) { $errs[] = ($waba === '' ? 'Main account' : 'Account ' . $waba) . ': ' . $res['error']; continue; }
            // The main account's templates win a name clash; another account's only fill gaps.
            $upsert = db()->prepare($waba === ''
                ? "INSERT INTO templates (client_id, wa_name, language, category, status, components, body_text, variable_count, waba_id, synced_at)
                   VALUES (?,?,?,?,?,?,?,?,NULL,NOW())
                   ON DUPLICATE KEY UPDATE category=VALUES(category), status=VALUES(status), components=VALUES(components),
                      body_text=VALUES(body_text), variable_count=VALUES(variable_count), waba_id=NULL, synced_at=NOW()"
                : "INSERT INTO templates (client_id, wa_name, language, category, status, components, body_text, variable_count, waba_id, synced_at)
                   VALUES (?,?,?,?,?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE synced_at=IF(waba_id <=> VALUES(waba_id), NOW(), synced_at),
                      status=IF(waba_id <=> VALUES(waba_id), VALUES(status), status)");
            foreach ($res['templates'] as $t) {
                $name = (string) ($t['name'] ?? '');
                $lang = (string) ($t['language'] ?? 'en');
                if ($name === '') continue;
                $components = $t['components'] ?? [];
                if (is_array($components) && isset($oldComps[$name . '|' . $lang])) $components = tb_keep_media($components, $oldComps[$name . '|' . $lang]);
                $bodyInfo   = wa_template_body(is_array($components) ? $components : []);
                $vals = [$cid, $name, $lang, (string) ($t['category'] ?? ''), strtoupper((string) ($t['status'] ?? '')),
                         json_encode($components, JSON_UNESCAPED_UNICODE), $bodyInfo['body'], $bodyInfo['vars']];
                if ($waba !== '') $vals[] = $waba;
                $upsert->execute($vals);
                if ($hasMeta) db_run("UPDATE templates SET meta_id=?, rejected_reason=? WHERE client_id=? AND wa_name=? AND language=? AND (waba_id <=> ?)",
                                     [(string) ($t['id'] ?? '') ?: null, ($r = trim((string) ($t['rejected_reason'] ?? ''))) !== '' && strtoupper($r) !== 'NONE' ? mb_substr($r, 0, 255) : null,
                                      $cid, $name, $lang, $waba === '' ? null : $waba]);
                $seen[] = $name . '|' . $lang;
                $found++;
            }
        }
        if ($errs && !$seen) {
            $err = 'Could not load templates: ' . implode(' · ', $errs);
        } else {
            // Remove templates that no longer exist on Meta's side (in any of the accounts read).
            if ($seen && !$errs) {
                $ph = implode(',', array_fill(0, count($seen), '?'));
                db_run("DELETE FROM templates WHERE client_id=? AND CONCAT(wa_name,'|',language) NOT IN ($ph)", array_merge([$cid], $seen));
            }
            flash('Templates synced — ' . $found . ' found.' . ($errs ? ' Not read: ' . implode(' · ', $errs) : ''), $errs ? 'error' : 'success');
            redirect('templates.php');
        }
    }
}

/* ── Delete a template at Meta (and here) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    if (!can_write()) { flash('You can only look at this account.', 'error'); redirect('templates.php'); }
    require_once __DIR__ . '/../includes/template_builder.php';
    $t = db_row("SELECT * FROM templates WHERE id=? AND client_id=?", [(int) ($_POST['id'] ?? 0), $cid]);
    if (!$t) { flash('That template is not on this account.', 'error'); redirect('templates.php'); }
    $src = tb_source($CLIENT, (string) ($t['waba_id'] ?? ''));
    if (!$src) { flash('The WhatsApp Business Account of this template is not connected any more.', 'error'); redirect('templates.php'); }
    $r = wa_delete_template($src['client'], $src['waba'], (string) $t['wa_name'], (string) ($t['meta_id'] ?? ''));
    if (!$r['ok']) { flash('Meta did not delete it: ' . $r['error'], 'error'); redirect('templates.php'); }
    db_run("DELETE FROM templates WHERE id=?", [(int) $t['id']]);
    flash('Template "' . $t['wa_name'] . '" deleted. Meta keeps the name blocked for new templates for 30 days.');
    redirect('templates.php');
}

$templates = db_all("SELECT * FROM templates WHERE client_id=? ORDER BY status='APPROVED' DESC, wa_name", [$cid]);
$lastSync  = db_val("SELECT MAX(synced_at) FROM templates WHERE client_id=?", [$cid]);

$canCreate = client_ready($CLIENT) && !$PERSONAL && can_write();
$syncForm = ($canCreate ? '<a class="btn btn-primary btn-sm" href="template_new.php">+ Create template</a> ' : '')
          . '<form method="post" style="display:inline">' . csrf_field()
          . '<input type="hidden" name="action" value="sync">'
          . '<button class="btn btn-ghost btn-sm" ' . (client_ready($CLIENT) && !$PERSONAL ? '' : 'disabled') . '>&#8635; Sync Templates</button></form>';

client_header('Templates', 'templates', $CLIENT);
page_head('Message Templates', $syncForm);

if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<?php if ($PERSONAL): ?>
<div class="alert info" style="font-size:12.5px">
  <strong>You don't need templates.</strong> Your account sends from your own WhatsApp number, so
  there is nothing to submit to Meta and nothing to wait for approval on — write the message
  directly in <a href="campaign_new.php">Campaigns</a> or in your
  <a href="qualifiers.php">Lead Qualifier</a> and it goes out as an ordinary WhatsApp message.
  <?php if ($templates): ?><br>The templates below are left over from a Cloud API setup and aren't used on this channel.<?php endif; ?>
</div>
<?php if (!$templates) { layout_footer(); exit; } ?>
<?php else: ?>
<div class="alert info" style="font-size:12.5px">
  Campaigns can only use templates that Meta has <strong>approved</strong>. Click <strong>Create template</strong> to write one here — text, picture, video or document, buttons,
  carousel cards, limited-time offers or passcodes — and submit it to Meta. Approval usually takes from a few minutes to a few hours; <strong>Sync Templates</strong> brings in the result
  (and any template made in WhatsApp Manager).
  <?php if ($lastSync): ?><br>Last synced: <?= e(date('d M Y, H:i', strtotime((string) $lastSync))) ?>.<?php endif; ?>
</div>
<?php endif; ?>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Template</th><th>Language</th><th>Category</th><th>Type</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$templates): ?>
        <tr><td colspan="6"><div class="empty"><?= client_ready($CLIENT) ? 'No templates yet — click “Sync Templates”.' : 'Your account isn’t connected yet.' ?></div></td></tr>
      <?php endif; ?>
      <?php foreach ($templates as $t):
        $isApproved = $t['status'] === 'APPROVED';
        $stCls = $isApproved ? 'green' : ($t['status'] === 'REJECTED' ? 'red' : 'gold');
      ?>
        <tr>
          <td><strong><?= e((string) $t['wa_name']) ?></strong></td>
          <td class="text-muted"><?= e((string) $t['language']) ?></td>
          <td class="text-muted"><?= e(ucfirst(strtolower((string) $t['category']))) ?: '—' ?></td>
          <td class="text-muted"><?php $tc = json_decode((string) $t['components'], true) ?: []; $tsp = wa_template_spec($tc);
            echo e($tsp['cards'] ? 'Carousel · ' . count($tsp['cards']) . ' cards' : ($tsp['lto'] ? 'Limited-time offer' : (strtoupper((string) $t['category']) === 'AUTHENTICATION' ? 'Passcode'
               : (['IMAGE' => 'Picture', 'VIDEO' => 'Video', 'DOCUMENT' => 'Document', 'LOCATION' => 'Location'][strtoupper((string) $tsp['header']['format'])] ?? 'Text'))))
               . ((int) $t['variable_count'] ? ' · ' . (int) $t['variable_count'] . ' variable' . ((int) $t['variable_count'] === 1 ? '' : 's') : ''); ?></td>
          <td><span class="pill <?= $stCls ?>"><?= e(ucfirst(strtolower(str_replace('_', ' ', (string) $t['status'])))) ?></span>
            <?php if (!empty($t['rejected_reason']) && !$isApproved): ?><span class="text-muted" style="display:block;font-size:11.5px;margin-top:3px">Why: <?= e(ucfirst(strtolower(str_replace('_', ' ', (string) $t['rejected_reason'])))) ?></span><?php endif; ?></td>
          <td style="text-align: end;white-space:nowrap">
            <button class="btn-link" onclick='showTemplate(<?= json_encode($t['wa_name']) ?>, <?= json_encode($tc, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Preview</button>
            <?php if ($canCreate): ?>
              <?php if (!empty($t['meta_id']) && in_array($t['status'], ['APPROVED', 'REJECTED', 'PAUSED'], true)): ?> · <a class="btn-link" href="template_new.php?edit=<?= (int) $t['id'] ?>">Edit</a><?php endif; ?>
              · <a class="btn-link" href="template_new.php?copy=<?= (int) $t['id'] ?>" title="Start a new template from this one">Copy</a>
              · <form method="post" style="display:inline" onsubmit="return confirm('Delete <?= e(addslashes((string) $t['wa_name'])) ?> at Meta? Campaigns and automations using it stop sending, and the name cannot be used again for 30 days.')">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button class="btn-link" style="color:var(--danger)">Delete</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back" id="m-preview">
  <div class="modal">
    <h2 id="tpl-name">Template</h2>
    <div id="tpl-body"></div>
    <p class="text-muted mt10" style="font-size:12px"><code>{{1}}</code>, <code>{{2}}</code>… are variables you fill when building a campaign.</p>
    <div class="modal-actions"><button class="btn btn-ghost" onclick="document.getElementById('m-preview').classList.remove('open')">Close</button></div>
  </div>
</div>

<script src="../assets/tpl_preview.js?v=1"></script>
<script>
function showTemplate(name, comps){
  document.getElementById('tpl-name').textContent = name;
  document.getElementById('tpl-body').innerHTML = tplPreview(comps || []);
  document.getElementById('m-preview').classList.add('open');
}
</script>

<?php layout_footer(); ?>
