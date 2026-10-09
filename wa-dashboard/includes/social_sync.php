<?php
declare(strict_types=1);

/**
 * Fetching Facebook & Instagram messages and comments ourselves, for two reasons:
 *   history  Meta's real-time feed (webhooks) only sends what happens after a Page is connected.
 *            When a Page is chosen, the last two weeks of conversations, posts and comments are
 *            brought in, so the Inbox and Comments start full instead of empty.
 *   safety   Webhooks can be missing for a while (app still in Development mode, callback not set,
 *            Meta delays). The worker re-reads every Page in use every couple of minutes, so new
 *            messages and comments still arrive.
 * Everything goes through the same handlers as the webhook (social_inbound_event,
 * social_comment_event), which drop repeats by Meta's own ids — fetching twice stores nothing twice.
 * Items older than 15 minutes are stored quietly: no automation, auto-reply or alert for history.
 */
require_once __DIR__ . '/social.php';

const SOCIAL_SYNC_DAYS = 14;

/** The Pages in use (anything switched on). */
function social_sync_pages(?int $clientId = null): array
{
    if (!db_has_column('meta_pages', 'social_synced_at')) return [];
    $w = "(msg_on=1 OR ig_msg_on=1 OR comments_on=1 OR ig_comments_on=1)";
    return $clientId ? db_all("SELECT * FROM meta_pages WHERE client_id=? AND $w", [$clientId]) : db_all("SELECT * FROM meta_pages WHERE $w");
}

/**
 * Fetch one Page's recent conversations and comments. $sinceTs: only what changed after it
 * (0 = the last SOCIAL_SYNC_DAYS days). Returns counts and the first error, if any.
 */
function social_sync_page(array $page, int $sinceTs = 0): array
{
    $out = ['messages' => 0, 'comments' => 0, 'error' => ''];
    $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $page['client_id']]);
    if (!$client || ($client['status'] ?? '') !== 'active') return $out;
    $floor = max($sinceTs, time() - SOCIAL_SYNC_DAYS * 86400);
    $tok = meta_page_token($page);
    $err = function (array $r) use (&$out) { if (!$r['ok'] && $out['error'] === '') $out['error'] = meta_explain_error((string) $r['error']); };

    /* ── Messenger / Instagram conversations ── */
    foreach (['messenger' => 'msg_on', 'instagram' => 'ig_msg_on'] as $platform => $flag) {
        if (!(int) ($page[$flag] ?? 0) || !client_has_channel($client, $platform)) continue;
        if ($platform === 'instagram' && empty($page['ig_user_id'])) continue;
        $own = $platform === 'instagram' ? (string) $page['ig_user_id'] : (string) $page['page_id'];
        $r = social_graph('GET', $page['page_id'] . '/conversations', ['platform' => $platform, 'limit' => 25,
                 'fields' => 'updated_time,participants,messages.limit(25){id,message,from,to,created_time,attachments{mime_type,name,image_data,video_data,file_url}}'], $tok);
        $err($r);
        foreach ((array) ($r['json']['data'] ?? []) as $conv) {
            if (strtotime((string) ($conv['updated_time'] ?? '')) < $floor) continue;
            // Oldest first, so the thread reads in order and "first message" triggers see the first one.
            foreach (array_reverse((array) ($conv['messages']['data'] ?? [])) as $m) {
                $ts = strtotime((string) ($m['created_time'] ?? '')) ?: time();
                if ($ts < $floor) continue;
                $from = (string) ($m['from']['id'] ?? '');
                if ($from === '' ) continue;
                if ($from === $own) {                     // sent from the Page (here or in Meta's own inbox)
                    $to = (string) ($m['to']['data'][0]['id'] ?? '');
                    if ($to !== '' && social_sync_outgoing($client, $page, $platform, $to, $m, $ts)) $out['messages']++;
                    continue;
                }
                $ev = ['sender' => ['id' => $from], 'recipient' => ['id' => $own], '_at' => $ts,
                       '_name' => (string) ($m['from']['name'] ?? ($m['from']['username'] ?? '')),
                       'message' => ['mid' => (string) ($m['id'] ?? ''), 'text' => (string) ($m['message'] ?? ''), 'attachments' => social_sync_attachments($m)]];
                if (in_array(social_inbound_event($page, $platform, $ev), ['logged', 'new_contact', 'history', 'opted_out'], true)) $out['messages']++;
            }
        }
    }

    /* ── Facebook comments: the Page's recent posts and their comments ── */
    if ((int) ($page['comments_on'] ?? 0) && client_has_channel($client, 'fb_comments')) {
        $r = social_graph('GET', $page['page_id'] . '/published_posts', ['limit' => 15,
                 'fields' => 'id,message,permalink_url,full_picture,created_time,comments.limit(50).order(reverse_chronological){id,message,from,created_time,parent{id}}'], $tok);
        $err($r);
        foreach ((array) ($r['json']['data'] ?? []) as $post) {
            $info = ['post_link' => (string) ($post['permalink_url'] ?? '') ?: null, 'post_text' => mb_substr((string) ($post['message'] ?? ''), 0, 600) ?: null,
                     'post_image' => (string) ($post['full_picture'] ?? '') ?: null];
            foreach (array_reverse((array) ($post['comments']['data'] ?? [])) as $c) {
                $ts = strtotime((string) ($c['created_time'] ?? '')) ?: time();
                if ($ts < $floor) continue;
                $v = ['item' => 'comment', 'verb' => 'add', 'comment_id' => (string) ($c['id'] ?? ''), 'post_id' => (string) $post['id'],
                      'message' => (string) ($c['message'] ?? ''), 'from' => (array) ($c['from'] ?? []), 'parent_id' => (string) ($c['parent']['id'] ?? ''),
                      '_at' => $ts, '_post' => $info];
                if (in_array(social_comment_event($page, 'fb', $v), ['stored', 'history', 'moderated', 'own'], true)) $out['comments']++;
            }
        }
    }

    /* ── Instagram comments: recent posts and reels, with replies ── */
    if ((int) ($page['ig_comments_on'] ?? 0) && !empty($page['ig_user_id']) && client_has_channel($client, 'ig_comments')) {
        $r = social_graph('GET', $page['ig_user_id'] . '/media', ['limit' => 15,
                 'fields' => 'id,caption,permalink,media_url,thumbnail_url,timestamp,comments.limit(50){id,text,username,from,timestamp,replies{id,text,username,from,timestamp}}'], $tok);
        $err($r);
        foreach ((array) ($r['json']['data'] ?? []) as $media) {
            $info = ['post_link' => (string) ($media['permalink'] ?? '') ?: null, 'post_text' => mb_substr((string) ($media['caption'] ?? ''), 0, 600) ?: null,
                     'post_image' => (string) ($media['thumbnail_url'] ?? ($media['media_url'] ?? '')) ?: null];
            foreach (array_reverse((array) ($media['comments']['data'] ?? [])) as $c) {
                foreach (array_merge([$c + ['_parent' => '']], array_map(fn($x) => $x + ['_parent' => (string) $c['id']], (array) ($c['replies']['data'] ?? []))) as $one) {
                    $ts = strtotime((string) ($one['timestamp'] ?? '')) ?: time();
                    if ($ts < $floor) continue;
                    $v = ['id' => (string) ($one['id'] ?? ''), 'text' => (string) ($one['text'] ?? ''), 'media' => ['id' => (string) $media['id']],
                          'from' => ['id' => (string) ($one['from']['id'] ?? ''), 'username' => (string) ($one['username'] ?? ($one['from']['username'] ?? ''))],
                          'parent_id' => $one['_parent'], '_at' => $ts, '_post' => $info];
                    if (in_array(social_comment_event($page, 'ig', $v), ['stored', 'history', 'moderated', 'own'], true)) $out['comments']++;
                }
            }
        }
    }

    db_run("UPDATE meta_pages SET social_synced_at=NOW() WHERE id=?", [(int) $page['id']]);
    return $out;
}

/** A message the Page sent (from Revenect or Meta's own inbox): kept in the thread once, never re-sent. */
function social_sync_outgoing(array $client, array $page, string $platform, string $to, array $m, int $ts): bool
{
    $mid = (string) ($m['id'] ?? '');
    if ($mid === '' || db_val("SELECT 1 FROM messages WHERE client_id=? AND ext_id=? LIMIT 1", [(int) $client['id'], $mid])) return false;
    $col = $platform === 'instagram' ? 'ig_sid' : 'fb_psid';
    $c = db_row("SELECT id FROM contacts WHERE client_id=? AND $col=?", [(int) $client['id'], $to]);
    if (!$c) return false;                                  // only alongside a person we already have
    $text = (string) ($m['message'] ?? '');
    msg_log((int) $client['id'], (int) $c['id'], 'out', $text !== '' ? $text : '[attachment]', ['channel' => $platform, 'ext' => $mid, 'status' => 'sent',
            'source' => 'page', 'at' => date('Y-m-d H:i:s', $ts), 'quiet' => true]);
    return true;
}

/** Graph's message attachments, in the shape the webhook uses. */
function social_sync_attachments(array $m): array
{
    $out = [];
    foreach ((array) ($m['attachments']['data'] ?? []) as $a) {
        if (!empty($a['image_data']['url'])) $out[] = ['type' => 'image', 'payload' => ['url' => (string) $a['image_data']['url']]];
        elseif (!empty($a['video_data']['url'])) $out[] = ['type' => 'video', 'payload' => ['url' => (string) $a['video_data']['url']]];
        elseif (!empty($a['file_url'])) $out[] = ['type' => str_starts_with((string) ($a['mime_type'] ?? ''), 'audio') ? 'audio' : 'file', 'payload' => ['url' => (string) $a['file_url']]];
    }
    return $out;
}

/**
 * The worker's pass: every Page in use whose last fetch is older than $everySec, a few per run.
 * Returns how many messages and comments came in.
 */
function social_sync_due(int $everySec = 120, int $maxPages = 10): int
{
    if (!db_has_column('meta_pages', 'social_synced_at')) return 0;
    $n = 0;
    $rows = db_all("SELECT * FROM meta_pages WHERE (msg_on=1 OR ig_msg_on=1 OR comments_on=1 OR ig_comments_on=1)
                     AND (social_synced_at IS NULL OR social_synced_at < NOW() - INTERVAL " . (int) $everySec . " SECOND)
                     ORDER BY social_synced_at IS NOT NULL, social_synced_at LIMIT " . (int) $maxPages);
    foreach ($rows as $p) {
        $since = $p['social_synced_at'] ? strtotime((string) $p['social_synced_at']) - 300 : 0;   // overlap: ids drop repeats
        $r = social_sync_page($p, $since);
        $n += $r['messages'] + $r['comments'];
    }
    return $n;
}
