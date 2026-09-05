<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];
$err = ''; $syncMsg = '';
// Templates only exist on the Cloud API. A personal number sends ordinary messages, so this
// page is informational there rather than an error about missing Meta credentials.
$PERSONAL = channel_is_personal($CLIENT);

/* ── Sync templates from the client's WABA ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    verify_csrf();
    if ($PERSONAL) {
        $err = 'Your account sends from your own WhatsApp number, which has no approved templates to sync.';
    } elseif (!client_ready($CLIENT) || !$CLIENT['waba_id']) {
        $err = 'Your WhatsApp account is not fully configured yet (WABA ID / token missing). Contact Gildana.';
    } else {
        $res = wa_fetch_templates($CLIENT);
        if (!$res['ok']) {
            $err = 'Could not load templates: ' . $res['error'];
        } else {
            $upsert = db()->prepare(
                "INSERT INTO templates (client_id, wa_name, language, category, status, components, body_text, variable_count, synced_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE category=VALUES(category), status=VALUES(status),
                    components=VALUES(components), body_text=VALUES(body_text),
                    variable_count=VALUES(variable_count), synced_at=NOW()"
            );
            $seen = [];
            foreach ($res['templates'] as $t) {
                $name = (string) ($t['name'] ?? '');
                $lang = (string) ($t['language'] ?? 'en');
                if ($name === '') continue;
                $components = $t['components'] ?? [];
                $bodyInfo   = wa_template_body(is_array($components) ? $components : []);
                $upsert->execute([
                    $cid, $name, $lang,
                    (string) ($t['category'] ?? ''),
                    strtoupper((string) ($t['status'] ?? '')),
                    json_encode($components, JSON_UNESCAPED_UNICODE),
                    $bodyInfo['body'],
                    $bodyInfo['vars'],
                ]);
                $seen[] = $name . '|' . $lang;
            }
            // Remove templates that no longer exist on Meta's side.
            if ($seen) {
                $ph = implode(',', array_fill(0, count($seen), '?'));
                db_run(
                    "DELETE FROM templates WHERE client_id=? AND CONCAT(wa_name,'|',language) NOT IN ($ph)",
                    array_merge([$cid], $seen)
                );
            }
            flash('Templates synced — ' . count($res['templates']) . ' found.');
            redirect('templates.php');
        }
    }
}

$templates = db_all("SELECT * FROM templates WHERE client_id=? ORDER BY status='APPROVED' DESC, wa_name", [$cid]);
$lastSync  = db_val("SELECT MAX(synced_at) FROM templates WHERE client_id=?", [$cid]);

$syncForm = '<form method="post" style="display:inline">' . csrf_field()
          . '<input type="hidden" name="action" value="sync">'
          . '<button class="btn btn-primary btn-sm" ' . (client_ready($CLIENT) && !$PERSONAL ? '' : 'disabled') . '>&#8635; Sync Templates</button></form>';

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
  Campaigns can only use templates that Meta has <strong>approved</strong>. Create &amp; submit templates in your Meta / WhatsApp Manager, then click <strong>Sync Templates</strong> here to pull them in.
  <?php if ($lastSync): ?><br>Last synced: <?= e(date('d M Y, H:i', strtotime((string) $lastSync))) ?>.<?php endif; ?>
</div>
<?php endif; ?>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Template</th><th>Language</th><th>Category</th><th>Variables</th><th>Status</th><th></th></tr></thead>
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
          <td class="text-muted"><?= e((string) $t['category']) ?: '—' ?></td>
          <td><?= (int) $t['variable_count'] ?></td>
          <td><span class="pill <?= $stCls ?>"><?= e(ucfirst(strtolower((string) $t['status']))) ?></span></td>
          <td style="text-align:right">
            <button class="btn-link" onclick='showTemplate(<?= json_encode($t['wa_name']) ?>, <?= json_encode($t['body_text']) ?>)'>Preview</button>
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
    <div style="background:var(--paper);border-radius:10px;padding:16px;white-space:pre-wrap;font-size:13.5px" id="tpl-body"></div>
    <p class="text-muted mt10" style="font-size:12px"><code>{{1}}</code>, <code>{{2}}</code>… are variables you fill when building a campaign.</p>
    <div class="modal-actions"><button class="btn btn-ghost" onclick="document.getElementById('m-preview').classList.remove('open')">Close</button></div>
  </div>
</div>

<script>
function showTemplate(name, body){
  document.getElementById('tpl-name').textContent = name;
  document.getElementById('tpl-body').textContent = body || '(no body text)';
  document.getElementById('m-preview').classList.add('open');
}
</script>

<?php layout_footer(); ?>
