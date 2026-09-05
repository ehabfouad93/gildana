<?php
declare(strict_types=1);
/**
 * Choosing a spreadsheet, and reading its shape for column mapping.
 *
 * The picker is Google's own, not ours, and that is the point: our OAuth scope is drive.file,
 * which grants access only to files the user selects through it. We cannot list their Drive
 * and do not want to be able to — so "browse and choose" has to happen inside Google's widget,
 * which hands back an id we are then allowed to open.
 *
 *   ?action=picker            → the picker page (opened in a popup)
 *   ?action=tabs&id=…         → JSON: spreadsheet title + tab names
 *   ?action=header&id=…&tab=… → JSON: the header row, for mapping columns
 */
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/google.php';

$cid    = (int) $CLIENT['id'];
$client = db_row("SELECT * FROM clients WHERE id=?", [$cid]) ?: $CLIENT;
$action = (string) ($_GET['action'] ?? 'picker');

if ($action === 'tabs' || $action === 'header') {
    if (!google_connected($client)) json_out(['ok' => false, 'error' => 'Google account is not connected.']);
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') json_out(['ok' => false, 'error' => 'No spreadsheet chosen.']);

    if ($action === 'tabs') {
        $r = google_sheet_tabs($client, $id);
        json_out(['ok' => (bool) $r['ok'], 'title' => $r['title'], 'tabs' => $r['tabs'], 'error' => $r['error']]);
    }
    $r = google_sheet_rows($client, $id, (string) ($_GET['tab'] ?? ''), 2);
    json_out(['ok' => (bool) $r['ok'], 'header' => $r['header'], 'error' => $r['error']]);
}

/* ── the picker popup ── */
$cfg = google_cfg();
if (!google_connected($client)) {
    http_response_code(400);
    exit('<p style="font:14px system-ui;padding:24px">Connect your Google account first, in Settings → Google Sheets.</p>');
}
if ($cfg['api_key'] === '') {
    http_response_code(400);
    exit('<p style="font:14px system-ui;padding:24px">The sheet picker needs a Google <strong>API key</strong>. '
       . 'Ask ' . e(BRAND_PARENT) . ' to add one in Admin → Settings → Google.</p>');
}
// The token is this client's own and the page is same-origin behind their login; it is scoped
// to drive.file, so it cannot reach anything they have not chosen here anyway.
$token = google_access_token($client);
if ($token === '') { http_response_code(400); exit('<p style="font:14px system-ui;padding:24px">Could not refresh your Google session. Reconnect in Settings.</p>'); }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>Choose a spreadsheet</title>
<style>body{font:14px system-ui;margin:0;padding:20px;color:#111}</style>
</head><body>
<p id="msg">Loading Google's file picker…</p>
<script src="https://apis.google.com/js/api.js"></script>
<script>
const TOKEN = <?= json_encode($token) ?>;
const KEY   = <?= json_encode($cfg['api_key']) ?>;

function fail(t){ document.getElementById('msg').textContent = t; }

gapi.load('picker', () => {
  try {
    const view = new google.picker.DocsView(google.picker.ViewId.SPREADSHEETS)
      .setIncludeFolders(true).setSelectFolderEnabled(false);
    new google.picker.PickerBuilder()
      .addView(view)
      .setOAuthToken(TOKEN)
      .setDeveloperKey(KEY)
      .setCallback(data => {
        if (data.action === google.picker.Action.CANCELLED) { window.close(); return; }
        if (data.action !== google.picker.Action.PICKED) return;
        const doc = data.docs && data.docs[0];
        if (!doc) return;
        // Hand the choice back to the automation editor that opened this window.
        if (window.opener && !window.opener.closed) {
          window.opener.postMessage({ source: 'revenect-picker', id: doc.id, name: doc.name }, window.location.origin);
        }
        window.close();
      })
      .build().setVisible(true);
    document.getElementById('msg').textContent = 'Pick a spreadsheet…';
  } catch (e) { fail('Could not open the picker: ' + e.message); }
});
</script>
</body></html>
