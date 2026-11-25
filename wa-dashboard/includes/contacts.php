<?php
declare(strict_types=1);

require_once __DIR__ . '/crm.php';

/**
 * Contact tags and bulk operations.
 *
 * Tags live in `contacts.tags` as a comma-separated string, written by the automation
 * engine's tag step (auto_add_tag). Everything here reads and writes that same format —
 * trimmed, no spaces around the commas — so a tag applied by hand and one applied by a
 * flow are the same tag, and FIND_IN_SET keeps working. The column collation is
 * utf8mb4_general_ci, so matching is case-insensitive, which is what people expect: nobody
 * means "Hot" and "hot" to be two different tags.
 *
 * The important design point is SELECTION. A page shows fifty contacts; a client has four
 * thousand. Ticking boxes is fine for a handful, and useless for "everyone tagged hot", so
 * every bulk action accepts either a list of ids or the current filter — and the filter
 * version never sends four thousand ids through the browser.
 */

/** Split a stored tag string into clean parts. */
function tags_split(?string $raw): array
{
    return array_values(array_filter(array_map('trim', explode(',', (string) $raw)), fn($t) => $t !== ''));
}

/** Join tags back for storage, dropping duplicates that differ only in case. */
function tags_join(array $tags): string
{
    $seen = []; $out = [];
    foreach ($tags as $t) {
        $t = trim($t);
        if ($t === '') continue;
        $k = mb_strtolower($t);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $t;
    }
    // The column is varchar(255): drop the overflow rather than let MySQL truncate
    // mid-tag and leave a fragment behind.
    while ($out && mb_strlen(implode(',', $out)) > 255) array_pop($out);
    return implode(',', $out);
}

/**
 * Every tag this client uses, with how many contacts carry it.
 *
 * Computed in PHP rather than SQL because the tags are a delimited string, not rows. That
 * is fine at this size — one narrow column, and only for tagged contacts — and it keeps the
 * storage format the automation engine already writes.
 *
 * @return array<int, array{tag: string, n: int}> most used first
 */
function contact_tags(int $clientId): array
{
    $counts = [];   // lower-case key => ['tag' => first spelling seen, 'n' => count]
    try {
        $rows = db_all("SELECT tags FROM contacts WHERE client_id=? AND tags IS NOT NULL AND tags<>''", [$clientId]);
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as $r) {
        foreach (tags_split((string) $r['tags']) as $t) {
            $k = mb_strtolower($t);
            if (!isset($counts[$k])) $counts[$k] = ['tag' => $t, 'n' => 0];
            $counts[$k]['n']++;
        }
    }
    uasort($counts, fn($a, $b) => $b['n'] <=> $a['n'] ?: strcasecmp($a['tag'], $b['tag']));
    return array_values($counts);
}

/**
 * Turn what the page posted into a WHERE clause over `contacts`.
 *
 * Two shapes, and only two:
 *   ids    — the boxes that were ticked
 *   filter — everything matching the search box and the tag filter the client is looking at
 *
 * Returns [sql, params] with the client scope already applied, so a caller cannot forget it.
 */
function contact_selection_where(int $clientId, array $post): array
{
    $sql = "client_id = ?";
    $params = [$clientId];

    /* The list, the CSV export and every bulk action are all built here, so scoping once covers
       them all: a salesperson sees, exports and bulk-edits only the contacts that are theirs. */
    if (function_exists('crm_scope')) {
        [$scope, $sp] = crm_scope('');
        $sql .= $scope; $params = array_merge($params, $sp);
    }

    if (($post['scope'] ?? 'ids') === 'filter') {
        $q   = trim((string) ($post['q'] ?? ''));
        $tag = trim((string) ($post['tag'] ?? ''));
        $opt = (string) ($post['opt'] ?? '');
        if ($q !== '')   { $sql .= " AND (phone_e164 LIKE ? OR name LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
        if ($tag !== '') { $sql .= " AND FIND_IN_SET(?, tags)";               $params[] = $tag; }
        if ($opt === 'in' || $opt === 'out') { $sql .= " AND opt_in_status = ?"; $params[] = $opt; }
        // A list: its members (the list must be this account's).
        if (($lid = (int) ($post['list'] ?? 0)) > 0) {
            $sql .= " AND EXISTS (SELECT 1 FROM contact_list_members lm JOIN contact_lists ll ON ll.id=lm.list_id WHERE lm.contact_id=contacts.id AND lm.list_id=? AND ll.client_id=?)";
            $params[] = $lid; $params[] = $clientId;
        }
        return [$sql, $params];
    }

    $ids = array_values(array_filter(array_map('intval', (array) ($post['ids'] ?? []))));
    if (!$ids) return ["client_id = ? AND 0", [$clientId]];   // nothing selected: match nothing
    $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    foreach ($ids as $i) $params[] = $i;
    return [$sql, $params];
}

/** The contact ids a selection resolves to. Capped, so one click cannot start a runaway job. */
function contact_selection_ids(int $clientId, array $post, int $cap = 5000): array
{
    [$where, $params] = contact_selection_where($clientId, $post);
    return array_map('intval', array_column(
        db_all("SELECT id FROM contacts WHERE {$where} ORDER BY id DESC LIMIT {$cap}", $params), 'id'));
}

/**
 * Add a tag to a set of contacts. Returns how many actually changed.
 *
 * Read-modify-write per row rather than a clever SQL string concat: the column holds a list,
 * and the alternative is CONCAT with a FIND_IN_SET guard that silently produces "a,,b" the
 * first time it meets a NULL. Correctness beats one round trip at this size.
 */
function contacts_add_tag(int $clientId, array $ids, string $tag): int
{
    $tag = trim($tag);
    if ($tag === '' || !$ids) return 0;
    $changed = 0;
    foreach (array_chunk($ids, 200) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $rows = db_all("SELECT id, tags FROM contacts WHERE client_id=? AND id IN ($in)",
            array_merge([$clientId], $chunk));
        foreach ($rows as $r) {
            $cur = tags_split((string) $r['tags']);
            foreach ($cur as $t) if (mb_strtolower($t) === mb_strtolower($tag)) continue 2;  // already has it
            $next = tags_join(array_merge($cur, [$tag]));
            if ($next === (string) $r['tags']) continue;    // the 255-char cap refused it
            db_run("UPDATE contacts SET tags=? WHERE id=? AND client_id=?", [$next, (int) $r['id'], $clientId]);
            $changed++;
        }
    }
    return $changed;
}

/** Remove a tag from a set of contacts. Returns how many actually changed. */
function contacts_remove_tag(int $clientId, array $ids, string $tag): int
{
    $tag = trim($tag);
    if ($tag === '' || !$ids) return 0;
    $changed = 0;
    foreach (array_chunk($ids, 200) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $rows = db_all("SELECT id, tags FROM contacts WHERE client_id=? AND id IN ($in) AND tags<>''",
            array_merge([$clientId], $chunk));
        foreach ($rows as $r) {
            $cur  = tags_split((string) $r['tags']);
            $next = array_values(array_filter($cur, fn($t) => mb_strtolower($t) !== mb_strtolower($tag)));
            if (count($next) === count($cur)) continue;
            db_run("UPDATE contacts SET tags=? WHERE id=? AND client_id=?", [tags_join($next), (int) $r['id'], $clientId]);
            $changed++;
        }
    }
    return $changed;
}

/**
 * Put a set of contacts into a list. Returns how many were newly added.
 *
 * INSERT IGNORE against the membership key, so adding the same people twice is a no-op
 * rather than a duplicate — and the count reported is what actually changed, not how many
 * were selected.
 */
function contacts_add_to_list(int $clientId, array $ids, int $listId): int
{
    if (!$ids) return 0;
    $owns = db_row("SELECT id FROM contact_lists WHERE id=? AND client_id=?", [$listId, $clientId]);
    if (!$owns) return 0;

    $added = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        // The inner SELECT re-checks ownership: an id posted by hand cannot pull another
        // client's contact into this list.
        $added += db_run(
            "INSERT IGNORE INTO contact_list_members (list_id, contact_id)
             SELECT ?, id FROM contacts WHERE client_id=? AND id IN ($in)",
            array_merge([$listId, $clientId], $chunk));
    }
    return $added;
}

/** Create a list, or return the id of the client's existing one with that name. */
function contact_list_ensure(int $clientId, string $name): int
{
    $name = trim($name);
    if ($name === '') return 0;
    $existing = db_val("SELECT id FROM contact_lists WHERE client_id=? AND name=?", [$clientId, $name]);
    if ($existing) return (int) $existing;
    return (int) db_insert("INSERT INTO contact_lists (client_id,name,created_at) VALUES (?,?,NOW())",
        [$clientId, mb_substr($name, 0, 160)]);
}

/** Tag chips for a table cell. */
function tags_html(?string $raw, int $max = 3): string
{
    $tags = tags_split($raw);
    if (!$tags) return '<span class="text-muted">—</span>';
    $shown = array_slice($tags, 0, $max);
    $html  = '';
    foreach ($shown as $t) $html .= '<span class="tag-chip">' . e($t) . '</span>';
    if (count($tags) > $max) {
        $html .= '<span class="tag-chip more" title="' . e(implode(', ', array_slice($tags, $max))) . '">+'
               . (count($tags) - $max) . '</span>';
    }
    return $html;
}

/* ───────────── Filtering by tag and list (reports, downloads, the Inbox) ───────────── */

/** The tag and list picked on a page (?tag=…&list=…). */
function contact_filter_from_get(): array
{
    return ['tag' => mb_substr(trim((string) ($_GET['tag'] ?? '')), 0, 60), 'list' => max(0, (int) ($_GET['list'] ?? 0))];
}

/**
 * SQL that keeps only contacts with that tag and/or in that list. $idExpr is the contact id in
 * the caller's query (e.g. "campaign_messages.contact_id", "r.contact_id", "c.id").
 * @return array{0:string,1:array} " AND …" and its parameters ('' when nothing is picked)
 */
function contact_filter_sql(string $idExpr, array $f): array
{
    $sql = ''; $p = [];
    $tag = mb_strtolower(trim((string) ($f['tag'] ?? '')));
    if ($tag !== '') {
        $sql .= " AND EXISTS (SELECT 1 FROM contacts kt WHERE kt.id={$idExpr} AND CONCAT(',', LOWER(REPLACE(kt.tags, ', ', ',')), ',') LIKE ?)";
        $p[] = '%,' . addcslashes($tag, '%_\\') . ',%';
    }
    if ((int) ($f['list'] ?? 0) > 0) {
        $sql .= " AND EXISTS (SELECT 1 FROM contact_list_members lm WHERE lm.contact_id={$idExpr} AND lm.list_id=?)";
        $p[] = (int) $f['list'];
    }
    return [$sql, $p];
}

/** "&tag=…&list=…" for links that must keep the filter (paging, other filters, downloads). */
function contact_filter_qs(array $f): string
{
    $q = '';
    if (($f['tag'] ?? '') !== '') $q .= '&tag=' . rawurlencode((string) $f['tag']);
    if ((int) ($f['list'] ?? 0) > 0) $q .= '&list=' . (int) $f['list'];
    return $q;
}

/**
 * The Tag / List filter: two pickers that apply at once. $keep: the page's other query
 * parameters (id, status…), carried as they are.
 */
function contact_filter_bar(int $clientId, array $f, array $keep, string $label = 'Only people'): string
{
    $tags  = contact_tags($clientId);
    $lists = db_all("SELECT id, name FROM contact_lists WHERE client_id=? ORDER BY name", [$clientId]);
    if (!$tags && !$lists) return '';
    $h = '<form method="get" class="cf-bar">';
    foreach ($keep as $k => $v) if ($v !== '' && $v !== null && !in_array($k, ['tag', 'list', 'page'], true)) $h .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
    $h .= '<span class="cf-lbl">' . e($label) . '</span>';
    $h .= '<select name="tag" onchange="this.form.submit()" aria-label="Tag"><option value="">Any tag</option>';
    foreach ($tags as $t) $h .= '<option value="' . e((string) $t['tag']) . '"' . (mb_strtolower((string) $t['tag']) === mb_strtolower((string) $f['tag']) ? ' selected' : '') . '>Tag: ' . e((string) $t['tag']) . '</option>';
    $h .= '</select><select name="list" onchange="this.form.submit()" aria-label="List"><option value="">Any list</option>';
    foreach ($lists as $l) $h .= '<option value="' . (int) $l['id'] . '"' . ((int) $l['id'] === (int) $f['list'] ? ' selected' : '') . '>List: ' . e((string) $l['name']) . '</option>';
    $h .= '</select>';
    if ($f['tag'] !== '' || $f['list'] > 0) {
        $h .= '<a class="btn-link" href="?' . e(http_build_query(array_filter(array_diff_key($keep, ['tag' => 1, 'list' => 1, 'page' => 1]), fn($v) => $v !== '' && $v !== null))) . '">Clear</a>';
    }
    return $h . '<noscript><button class="btn btn-ghost btn-sm">Apply</button></noscript></form>';
}
