<?php
declare(strict_types=1);

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

    if (($post['scope'] ?? 'ids') === 'filter') {
        $q   = trim((string) ($post['q'] ?? ''));
        $tag = trim((string) ($post['tag'] ?? ''));
        $opt = (string) ($post['opt'] ?? '');
        if ($q !== '')   { $sql .= " AND (phone_e164 LIKE ? OR name LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
        if ($tag !== '') { $sql .= " AND FIND_IN_SET(?, tags)";               $params[] = $tag; }
        if ($opt === 'in' || $opt === 'out') { $sql .= " AND opt_in_status = ?"; $params[] = $opt; }
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
