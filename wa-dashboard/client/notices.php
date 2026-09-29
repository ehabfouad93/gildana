<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';

/**
 * The bell's links land here: mark the notice read, then go where it points.
 *
 * A GET, on purpose — the bell is a list of links, and a Viewer (whose POSTs are refused
 * everywhere) must still be able to clear their own bell. Marking "read" changes nothing anyone
 * else can see, and "all" needs the session's token so a link on another site cannot do it.
 */
$uid = (int) ($PERM_USER['id'] ?? 0);
if (isset($_GET['all'])) {
    if (hash_equals(csrf_token(), (string) ($_GET['t'] ?? ''))) crm_notices_mark_read($uid);
    redirect((string) ($_GET['back'] ?? '') !== '' && preg_match('/^[a-z_]+\.php(\?[\w=&%.-]*)?$/', (string) $_GET['back']) ? (string) $_GET['back'] : 'index.php');
}
$id = (int) ($_GET['id'] ?? 0);
$n  = $id ? db_row("SELECT * FROM crm_notices WHERE id=? AND user_id=?", [$id, $uid]) : null;
if (!$n) redirect('crm.php');
crm_notices_mark_read($uid, $id);
redirect(crm_notice_text($n)['url']);
