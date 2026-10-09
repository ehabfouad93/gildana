<?php
declare(strict_types=1);

/**
 * Connecting Facebook & Instagram, from wherever someone notices it isn't connected yet:
 * the Inbox, the Comments page, the Facebook & Instagram page — and its status on the
 * platform admin's client page.
 *
 * One Facebook sign-in brings the client's Pages (and the Instagram accounts linked to them);
 * then each use — Messenger, Instagram messages, Facebook comments, Instagram comments — is a
 * switch per Page. Lead forms keep their own page in the CRM.
 */
require_once __DIR__ . '/meta_leads.php';
require_once __DIR__ . '/social.php';
require_once __DIR__ . '/social_sync.php';

/** The Page switches and the channel each needs. */
function social_switches(): array
{
    return ['msg_on' => ['messenger', 'Messenger messages'], 'ig_msg_on' => ['instagram', 'Instagram messages'],
            'comments_on' => ['fb_comments', 'Facebook comments'], 'ig_comments_on' => ['ig_comments', 'Instagram comments']];
}

/** The Facebook permissions each channel needs, with what they let Revenect do (for the "missing" list). */
function social_needed_perms(array $client): array
{
    $need = [
        'messenger'   => ['pages_messaging' => 'read and answer Messenger messages', 'pages_manage_metadata' => 'connect the Page to Revenect'],
        'instagram'   => ['instagram_basic' => 'see the Instagram account', 'instagram_manage_messages' => 'read and answer Instagram messages', 'pages_manage_metadata' => 'connect the Page to Revenect'],
        'fb_comments' => ['pages_read_user_content' => 'read comments on the Page', 'pages_manage_engagement' => 'reply to and hide comments', 'pages_read_engagement' => 'read the Page\'s posts'],
        'ig_comments' => ['instagram_basic' => 'see the Instagram account', 'instagram_manage_comments' => 'read and reply to Instagram comments'],
    ];
    $out = [];
    foreach ($need as $ch => $perms) if (client_has_channel($client, $ch)) $out += $perms;
    return $out;
}

/** Permissions the last Facebook sign-in did not grant: [permission => what it is for]. Null when unknown (signed in before this was recorded). */
function social_missing_perms(array $client): ?array
{
    $raw = meta_setting('meta_perms_' . (int) $client['id']);
    if ($raw === '') return null;
    $g = (array) ((json_decode($raw, true) ?: [])['granted'] ?? []);
    return array_diff_key(social_needed_perms($client), array_flip($g));
}

/**
 * Where this client stands: Pages connected, which are in use, which uses are working, and which
 * of the channels its plan includes are not arriving yet. A switch that is on but whose Page has
 * an error from Facebook does not count as working.
 */
function social_connect_status(array $client): array
{
    $pages = [];
    try { $pages = db_all("SELECT * FROM meta_pages WHERE client_id=? ORDER BY name", [(int) $client['id']]); } catch (Throwable $e) {}
    $sw = array_filter(social_switches(), fn($x) => client_has_channel($client, $x[0]));
    $works = fn(array $p, string $k) => (int) ($p[$k] ?? 0) === 1 && trim((string) ($p['last_error'] ?? '')) === '';
    $used = array_values(array_filter($pages, fn($p) => (bool) array_filter(array_keys($sw), fn($k) => (int) ($p[$k] ?? 0) === 1)));
    $on = []; $missing = [];
    foreach ($sw as $k => [$ch, $label]) {
        $on[$k] = (bool) array_filter($pages, fn($p) => $works($p, $k));
        if (!$on[$k]) $missing[$k] = $label;
    }
    return ['configured' => meta_configured(), 'pages' => $pages, 'used' => $used, 'switches' => $sw, 'on' => $on, 'missing' => $missing,
            'perms_missing' => social_missing_perms($client),
            'ready' => $pages && !$missing];
}

/** Pages the platform remembers the Facebook sign-in should return to. */
function social_return_ok(string $to): string
{
    return in_array($to, ['social.php', 'inbox.php', 'comments.php', 'meta_leads.php'], true) ? $to : 'social.php';
}

/**
 * The actions behind every Connect / switch button. Returns [message, type, redirect] or null
 * when the POST was not one of these. Only an account Admin may change the connection.
 */
function social_connect_handle(array $client, array $post, bool $isAdmin, int $userId): ?array
{
    $a = (string) ($post['action'] ?? '');
    if (!in_array($a, ['fb_connect', 'social_set', 'social_all_on', 'social_use_page', 'social_sync_now'], true)) return null;
    if (!$isAdmin) return ['Only an account Admin can connect Facebook & Instagram.', 'error', 'social.php'];
    $cid = (int) $client['id'];

    if ($a === 'fb_connect') {
        if (!meta_configured()) return ['Facebook is not set up on this platform yet — ask the Revenect team.', 'error', 'social.php'];
        $_SESSION['meta_return'] = social_return_ok((string) ($post['return'] ?? 'social.php'));
        header('Location: ' . meta_auth_url($cid, $userId ?: null));
        exit;
    }

    // Bring in what is on Facebook / Instagram now: recent conversations, posts and comments.
    if ($a === 'social_sync_now') {
        @set_time_limit(180);
        $m = $c = 0; $errs = [];
        foreach (social_sync_pages($cid) as $p) { $r = social_sync_page($p); $m += $r['messages']; $c += $r['comments']; if ($r['error'] !== '') $errs[$r['error']] = 1; }
        if ($errs) return ['Fetched ' . $m . ' message(s) and ' . $c . ' comment(s). Facebook refused part of it: ' . implode(' ', array_keys($errs)), $m + $c ? 'success' : 'error', 'social.php'];
        return ['Fetched ' . $m . ' new message(s) and ' . $c . ' new comment(s) from the last ' . SOCIAL_SYNC_DAYS . ' days.', 'success', 'social.php'];
    }

    $sw = social_switches();
    if ($a === 'social_set') {
        $page = db_row("SELECT * FROM meta_pages WHERE id=? AND client_id=?", [(int) ($post['page'] ?? 0), $cid]);
        $what = (string) ($post['what'] ?? '');
        if (!$page || !isset($sw[$what]) || !client_has_channel($client, $sw[$what][0])) return ['That channel is not part of your plan.', 'error', 'social.php'];
        $on = !empty($post['on']);
        $r = social_page_set($page, $what, $on);
        return $r['ok'] ? [$sw[$what][1] . ($on ? ' now arrive in Revenect.' : ' stopped.'), 'success', 'social.php']
                        : ['Facebook refused: ' . meta_explain_error((string) $r['error']) . ' If it mentions a permission, press Reconnect so Meta asks for it.', 'error', 'social.php'];
    }

    // Facebook already told us a permission is missing: say so plainly instead of trying and failing Page after Page.
    $miss = social_missing_perms($client);
    if ($miss) return ['Facebook has not given Revenect permission to ' . implode(', ', array_unique(array_values($miss)))
                      . '. Press Reconnect and keep every permission ticked on the Facebook screen.', 'error', 'social.php#perms'];

    if ($a === 'social_use_page') {
        $page = db_row("SELECT * FROM meta_pages WHERE id=? AND client_id=?", [(int) ($post['page'] ?? 0), $cid]);
        if (!$page) return ['Choose one of your Pages.', 'error', 'social.php#choose'];
        $pages = [$page];
    } else {
        // "Turn everything on" works on the Pages already in use — never on every Page an agency manages.
        $all = db_all("SELECT * FROM meta_pages WHERE client_id=?", [$cid]);
        $pages = array_values(array_filter($all, fn($p) => (int) $p['msg_on'] || (int) $p['ig_msg_on'] || (int) $p['comments_on'] || (int) ($p['ig_comments_on'] ?? 0)));
        if (!$pages && count($all) === 1) $pages = $all;
        if (!$pages) return ['Choose which Page to use first.', 'error', 'social.php#choose'];
    }
    $done = []; $failed = [];
    foreach ($pages as $page) {
        foreach ($sw as $k => [$ch, $label]) {
            if (!client_has_channel($client, $ch) || ((int) ($page[$k] ?? 0) === 1 && trim((string) $page['last_error']) === '')) continue;
            $r = social_page_set($page, $k, true);                     // links the Page's Instagram account first when needed
            if ($r['ok']) $done[] = $label; else $failed[meta_explain_error((string) $r['error'])][] = $label;
            $page = db_row("SELECT * FROM meta_pages WHERE id=?", [(int) $page['id']]) ?: $page;
        }
    }
    $where = count($pages) === 1 ? ' on ' . $pages[0]['name'] : '';
    // Start full: the last two weeks of messages and comments from the Page(s) just switched on.
    $got = ['messages' => 0, 'comments' => 0];
    if ($done) { @set_time_limit(180); foreach ($pages as $p) { $r = social_sync_page(db_row("SELECT * FROM meta_pages WHERE id=?", [(int) $p['id']]) ?: $p); $got['messages'] += $r['messages']; $got['comments'] += $r['comments']; } }
    $brought = $got['messages'] + $got['comments'] ? ' Brought in ' . $got['messages'] . ' recent message(s) and ' . $got['comments'] . ' comment(s).' : '';
    if ($failed) {
        $why = implode(' ', array_map(fn($reason, $labels) => implode(', ', array_unique($labels)) . ': ' . $reason, array_keys($failed), $failed));
        return [($done ? 'Turned on ' . implode(', ', array_unique($done)) . $where . '. ' : '') . 'Not possible yet — ' . $why, $done ? 'success' : 'error', 'social.php'];
    }
    return [$done ? 'Done — ' . implode(', ', array_unique($done)) . $where . ' now arrive in Revenect.' . $brought : 'Everything was already on.', 'success', 'social.php'];
}

/**
 * The strip shown at the top of the Inbox and Comments while Facebook & Instagram isn't
 * fully working: one button that does the next thing needed. Empty once all is on.
 */
function social_connect_banner(array $client, string $return, bool $isAdmin): string
{
    if (!client_has_social($client)) return '';
    $st = social_connect_status($client);
    if ($st['ready'] || !$st['switches']) return '';
    $names = implode(', ', array_values($st['missing']));
    if (!$st['pages']) {
        $msg = '<strong>Connect Facebook &amp; Instagram</strong> to answer ' . e($names) . ' here, next to WhatsApp. It takes one Facebook sign-in.';
        $btn = $isAdmin && $st['configured']
            ? '<form method="post" action="social.php" style="margin:0">' . csrf_field() . '<input type="hidden" name="action" value="fb_connect"><input type="hidden" name="return" value="' . e($return) . '">'
              . '<button class="btn btn-primary btn-sm fb-connect-btn">' . social_fb_icon() . ' Connect Facebook &amp; Instagram</button></form>'
            : '<span class="text-muted" style="font-size:12.5px">' . ($st['configured'] ? 'Ask your account Admin to connect it.' : 'Not set up on this platform yet.') . '</span>';
    } elseif (!$st['used'] && count($st['pages']) > 1) {
        $msg = '<strong>Choose your Page:</strong> Facebook is connected — pick which of your ' . count($st['pages']) . ' Pages Revenect should use.';
        $btn = '<a class="btn btn-primary btn-sm" href="social.php#choose">Choose the Page</a>';
    } else {
        $msg = '<strong>Not arriving yet:</strong> ' . e($names) . '.';
        $btn = $isAdmin
            ? '<form method="post" action="social.php" style="margin:0">' . csrf_field() . '<input type="hidden" name="action" value="social_all_on">'
              . '<button class="btn btn-primary btn-sm">Turn them on</button></form><a class="btn btn-ghost btn-sm" href="social.php">Settings</a>'
            : '<a class="btn btn-ghost btn-sm" href="social.php">See the connection</a>';
    }
    return '<div class="social-banner" role="status"><span>' . $msg . '</span><span class="social-banner-act">' . $btn . '</span></div>';
}

function social_fb_icon(): string
{
    return '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:-2px"><path fill="currentColor" d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.95.93-1.95 1.88v2.26h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/></svg>';
}
