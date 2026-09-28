<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/inbox.php';

// Live AJAX (threads / thread / send) — handled + exits before any output.
inbox_handle_ajax($CLIENT);

client_header('Inbox', 'inbox', $CLIENT);
page_head('Inbox');
$IB_ENDPOINT = 'inbox.php';
$IB_UPLOAD   = 'upload_media.php';   // lets the template picker upload a header image

/* inbox.php?contact=ID opens that conversation — the CRM's "Open conversation" link. Checked
   against the same rule as the thread list, so a salesperson cannot open a colleague's lead by
   editing the number in the address bar. */
$IB_OPEN = null;
if (($openId = (int) ($_GET['contact'] ?? 0)) > 0) {
    $oc = db_row("SELECT id, name, phone_e164, owner_user_id FROM contacts WHERE id=? AND client_id=?", [$openId, (int) $CLIENT['id']]);
    if ($oc && crm_can_see($oc)) $IB_OPEN = ['id' => (int) $oc['id'], 'name' => (string) $oc['name'], 'phone' => (string) $oc['phone_e164']];
}
require_once __DIR__ . '/../includes/inbox_view.php';
layout_footer();
