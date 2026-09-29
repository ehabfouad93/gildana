<?php
declare(strict_types=1);
/**
 * A person's own way of seeing the app: language, light/dark, and the pages starred in the menu.
 * At the root rather than under client/ because it is theirs whatever their role — a view-only
 * user may still read in Arabic — and admins use it too.
 *
 *   POST action=lang  lang=en|ar
 *   POST action=theme theme=auto|light|dark
 *   POST action=fav   url=<page, relative to the role's folder>  title=<its name>   (toggles)
 *
 * A form post goes back to `back`; a fetch (ajax=1) gets JSON.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/i18n.php';

$me = current_user();
$ajax = !empty($_POST['ajax']);
$reply = function (bool $ok, array $extra = []) use ($ajax): void {
    if ($ajax) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['ok' => $ok] + $extra); exit; }
    $back = (string) ($_POST['back'] ?? '');
    // Only somewhere inside this app: a path, never another site.
    if ($back === '' || !preg_match('~^/?[A-Za-z0-9_\-./]+(\?[^\s]*)?(#[\w\-]*)?$~', $back) || str_contains($back, '//')) $back = 'index.php';
    header('Location: ' . $back);
    exit;
};
if (!$me) { http_response_code(401); $reply(false, ['error' => 'Not signed in']); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); $reply(false); }
verify_csrf();
if (!db_has_column('users', 'lang')) $reply(false, ['error' => 'Run the latest update first']);

$uid = (int) $me['id'];
switch ((string) ($_POST['action'] ?? '')) {
    case 'lang':
        $l = ($_POST['lang'] ?? '') === 'ar' ? 'ar' : 'en';
        db_run("UPDATE users SET lang=? WHERE id=?", [$l, $uid]);
        $reply(true, ['lang' => $l]);
    case 'theme':
        $t = in_array($_POST['theme'] ?? '', ['light', 'dark'], true) ? $_POST['theme'] : null;
        db_run("UPDATE users SET theme=? WHERE id=?", [$t, $uid]);
        $reply(true, ['theme' => $t ?? 'auto']);
    case 'display':                                  // the Profile form: both at once
        $l = ($_POST['lang'] ?? '') === 'ar' ? 'ar' : 'en';
        $t = in_array($_POST['theme'] ?? '', ['light', 'dark'], true) ? $_POST['theme'] : null;
        db_run("UPDATE users SET lang=?, theme=? WHERE id=?", [$l, $t, $uid]);
        if (!$ajax) flash($l === 'ar' ? 'تم الحفظ.' : 'Saved.');
        $reply(true);
    case 'fav':
        $url = trim((string) ($_POST['url'] ?? ''));
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 80);
        if ($url === '' || !preg_match('~^[A-Za-z0-9_\-]+\.php(\?[^\s#]*)?$~', $url)) $reply(false, ['error' => 'Not a page']);
        $favs = nav_favs_decode((string) db_val("SELECT nav_favs FROM users WHERE id=?", [$uid]));
        $was = false;
        foreach ($favs as $i => $f) if ($f['u'] === $url) { unset($favs[$i]); $was = true; }
        if (!$was) {
            if (count($favs) >= 15) $reply(false, ['error' => 'Up to 15 favourites — remove one first']);
            $favs[] = ['u' => $url, 't' => $title !== '' ? $title : $url];
        }
        db_run("UPDATE users SET nav_favs=? WHERE id=?", [json_encode(array_values($favs), JSON_UNESCAPED_UNICODE), $uid]);
        $reply(true, ['starred' => !$was]);
}
$reply(false, ['error' => 'Unknown action']);
