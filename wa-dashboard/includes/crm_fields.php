<?php
declare(strict_types=1);

/**
 * What a lead carries besides its stage and owner: a short code, the detail under its stage,
 * whether it is fresh or cold data, whether it is qualified, which campaign and platform it came
 * from, the account's own fields — and the teams its owners belong to.
 *
 * These are what the leads list filters by and the reports count by, so each is a short, fixed
 * list rather than free text: "No answer", "no answer " and "لا يرد" would be three answers.
 *
 * Loaded by crm.php. Every function tolerates migration 049 not having run yet.
 */

/* ───────────────────────── the lead's code ───────────────────────── */

/**
 * The lead's short code, e.g. 4DE2F2. Worked out from the id (multiplying by an odd number is a
 * one-to-one shuffle below 2^24), so it is unique without a lookup and needs no counter.
 * Migration 049 fills the same value into contacts.code, which is what search looks in.
 */
function crm_code(int $id): string
{
    return strtoupper(str_pad(dechex(($id * 10368889) % 16777216), 6, '0', STR_PAD_LEFT));
}

/** Make sure a contact has its code stored (new rows get it the first time they become a lead). */
function crm_code_ensure(int $id): void
{
    if (db_has_column('contacts', 'code')) db_run("UPDATE contacts SET code=? WHERE id=? AND code IS NULL", [crm_code($id), $id]);
}

/** "#4DE2F2" or "4de2f2" typed into a search box → the code, or '' if it is not one. */
function crm_code_from_search(string $q): string
{
    $q = strtoupper(trim(ltrim(trim($q), '#')));
    return preg_match('/^[0-9A-F]{6}$/', $q) ? $q : '';
}

/* ───────────────────────── account settings used everywhere ───────────────────────── */

function crm_currency(int $clientId): string
{
    $c = (string) (crm_settings($clientId)['currency'] ?? 'EGP');
    return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'EGP';
}

/** 5,400,000 EGP — or '' for no amount. */
function crm_money_fmt($v, int $clientId, bool $withCurrency = true): string
{
    if ($v === null || $v === '') return '';
    return number_format((float) $v) . ($withCurrency ? ' ' . crm_currency($clientId) : '');
}

/** Currencies an account can pick. */
function crm_currencies(): array
{
    return ['EGP' => 'Egyptian pound', 'SAR' => 'Saudi riyal', 'AED' => 'UAE dirham', 'USD' => 'US dollar',
            'EUR' => 'Euro', 'KWD' => 'Kuwaiti dinar', 'QAR' => 'Qatari riyal', 'OMR' => 'Omani rial', 'BHD' => 'Bahraini dinar',
            'JOD' => 'Jordanian dinar', 'GBP' => 'Pound sterling'];
}

/* ───────────────────────── sub-statuses ───────────────────────── */

/**
 * What a new account starts with under each stage, matched by the stage's name (or its kind, for
 * a renamed Won). Real-estate shaped: what a sales manager asks "and then what happened?" about.
 */
function crm_default_substatuses(string $stageName, string $kind): array
{
    $byName = [
        'new'         => ['Not called yet', 'Called — no answer', 'Wrong number'],
        'contacted'   => ['No answer', 'Busy / call later', 'Phone off', 'Answered — follow up', 'Sent details on WhatsApp', 'Wants a call back'],
        'viewing'     => ['Visit booked', 'Visited', 'Did not come', 'Wants another visit'],
        'negotiating' => ['Waiting for an offer', 'Discussing the payment plan', 'Waiting for their decision', 'Paid EOI / reservation'],
    ];
    $k = mb_strtolower(trim($stageName));
    if (isset($byName[$k])) return $byName[$k];
    if ($kind === 'won') return ['Reservation paid', 'Contract signed'];
    return [];
}

/**
 * Sub-statuses per stage: [stage_id => [label, …]]. Seeded once per stage from the defaults; a
 * stage whose list an Admin cleared stays empty (marked with a sort of -1 row).
 */
function crm_substatuses(int $clientId): array
{
    if (isset($GLOBALS['__crm_sub'][$clientId])) return $GLOBALS['__crm_sub'][$clientId];
    $out = [];
    try {
        $rows = db_all("SELECT stage_id, label, sort FROM crm_substatuses WHERE client_id=? ORDER BY stage_id, sort, id", [$clientId]);
    } catch (Throwable $e) { return []; }
    $seen = [];
    foreach ($rows as $r) {
        $seen[(int) $r['stage_id']] = true;
        if ((int) $r['sort'] >= 0) $out[(int) $r['stage_id']][] = (string) $r['label'];
    }
    foreach (crm_stages($clientId) as $s) {
        $sid = (int) $s['id'];
        if (isset($seen[$sid]) || $s['kind'] === 'lost') continue;           // Lost's detail is its lost reason
        $defaults = crm_default_substatuses((string) $s['name'], (string) $s['kind']);
        crm_substatuses_save($clientId, $sid, $defaults, false);
        if ($defaults) $out[$sid] = $defaults;
    }
    return $GLOBALS['__crm_sub'][$clientId] = $out;
}

/** The list under one stage — for Lost, the lost reasons. */
function crm_substatus_list(int $clientId, ?int $stageId): array
{
    if (!$stageId) return [];
    if (crm_stage_kind($clientId, $stageId) === 'lost') return crm_options($clientId, 'lost_reason');
    return crm_substatuses($clientId)[$stageId] ?? [];
}

function crm_substatuses_save(int $clientId, int $stageId, array $labels, bool $clearCache = true): void
{
    db_run("DELETE FROM crm_substatuses WHERE client_id=? AND stage_id=?", [$clientId, $stageId]);
    $seen = []; $i = 0;
    foreach ($labels as $l) {
        $l = mb_substr(trim((string) $l), 0, 80);
        if ($l === '' || isset($seen[mb_strtolower($l)])) continue;
        $seen[mb_strtolower($l)] = true;
        db_insert("INSERT INTO crm_substatuses (client_id,stage_id,label,sort) VALUES (?,?,?,?)", [$clientId, $stageId, $l, $i++]);
    }
    // Marks the stage as set up, so an emptied list is not refilled with the defaults.
    if (!$i) db_insert("INSERT INTO crm_substatuses (client_id,stage_id,label,sort) VALUES (?,?,'',-1)", [$clientId, $stageId]);
    if ($clearCache) unset($GLOBALS['__crm_sub'][$clientId]);
}

/**
 * Set the detail under the lead's current stage (or clear it with ''). Only a value from that
 * stage's list is kept. Logged, so the reports can count how leads moved within a stage too.
 */
function crm_set_substatus(array $client, int $contactId, string $label, ?int $by = null): bool
{
    if (!db_has_column('contacts', 'substatus')) return false;
    $cid = (int) $client['id'];
    $c = db_row("SELECT stage_id, substatus FROM contacts WHERE id=? AND client_id=?", [$contactId, $cid]);
    if (!$c || $c['stage_id'] === null) return false;
    $label = trim($label);
    if ($label !== '') {
        $match = null;
        foreach (crm_substatus_list($cid, (int) $c['stage_id']) as $l) if (mb_strtolower($l) === mb_strtolower($label)) $match = $l;
        if ($match === null) return false;
        $label = $match;
    }
    if ((string) $c['substatus'] === $label) return true;
    db_run("UPDATE contacts SET substatus=? WHERE id=?", [$label !== '' ? $label : null, $contactId]);
    crm_log($cid, $contactId, 'substatus', $c['substatus'] !== null ? (string) $c['substatus'] : null, $label !== '' ? $label : null, $by);
    if ($by !== null) crm_touch($contactId);
    return true;
}

/* ───────────────────────── fresh / cold, qualification, platform ───────────────────────── */

function crm_data_types(): array
{
    return ['fresh' => 'Fresh', 'cold' => 'Cold data'];
}

/**
 * Fresh or cold, decided when a contact becomes a lead: imported or sheet data is cold; a contact
 * we already had for longer than the account's fresh window is cold (old data brought back);
 * anything arriving now from an ad, a form, a message or by hand is fresh.
 */
function crm_data_type_for(int $clientId, array $contact, string $source): string
{
    if (in_array($source, ['import', 'sheet'], true)) return 'cold';
    $days = max(1, (int) (crm_settings($clientId)['fresh_days'] ?? 30));
    $born = strtotime((string) ($contact['created_at'] ?? '')) ?: time();
    return $born < time() - $days * 86400 ? 'cold' : 'fresh';
}

function crm_qualifications(): array
{
    return ['qualified' => 'Qualified', 'not_qualified' => 'Not qualified'];
}

function crm_platforms(): array
{
    return ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'messenger' => 'Messenger', 'whatsapp_ad' => 'WhatsApp ad',
            'whatsapp' => 'WhatsApp', 'website' => 'Website', 'import' => 'Import', 'manual' => 'Added by hand', 'other' => 'Other'];
}

function crm_platform_label(?string $p): string
{
    return $p ? (crm_platforms()[$p] ?? ucfirst($p)) : '—';
}

/** The platform a lead's source implies, when nothing more precise is known. */
function crm_platform_for_source(string $source): ?string
{
    return ['ctwa' => 'whatsapp_ad', 'inbound' => 'whatsapp', 'meta_form' => 'facebook', 'import' => 'import',
            'sheet' => 'import', 'manual' => 'manual', 'qualifier' => 'whatsapp'][$source] ?? null;
}

/** Meta's lead platform ("fb", "ig", "msg", "an") → ours. */
function crm_platform_from_meta(string $p): ?string
{
    return ['fb' => 'facebook', 'ig' => 'instagram', 'msg' => 'messenger', 'messenger' => 'messenger', 'an' => 'facebook',
            'facebook' => 'facebook', 'instagram' => 'instagram'][strtolower(trim($p))] ?? null;
}

/**
 * Where on Meta a lead came from. Only fills what is empty: the first campaign that brought a
 * person is the one that gets the credit, as with ads.
 */
function crm_set_origin(int $contactId, array $o): void
{
    if (!db_has_column('contacts', 'campaign')) return;
    $cols = ['platform' => 16, 'campaign' => 160, 'meta_campaign_id' => 32, 'adset' => 160, 'meta_adset_id' => 32, 'ad_name' => 160, 'meta_ad_id' => 32];
    $set = []; $p = [];
    foreach ($cols as $c => $len) {
        $v = trim((string) ($o[$c] ?? ''));
        if ($v === '') continue;
        if ($c === 'platform') {
            // A platform only guessed from the source ("a Meta form, so Facebook") gives way to
            // the real one when Meta says it was Instagram.
            $guess = [];
            foreach (['meta_form', 'inbound', 'ctwa', 'manual', 'import', 'sheet', 'qualifier'] as $src) { $guess[] = "(source=? AND platform=?)"; array_push($p, $src, crm_platform_for_source($src)); }
            $set[] = "platform = IF(platform IS NULL OR platform='' OR " . implode(' OR ', $guess) . ", ?, platform)";
            $p[] = mb_substr($v, 0, $len);
            continue;
        }
        $set[] = "$c = COALESCE(NULLIF($c,''), ?)"; $p[] = mb_substr($v, 0, $len);
    }
    if (!$set) return;
    $p[] = $contactId;
    db_run("UPDATE contacts SET " . implode(', ', $set) . " WHERE id=?", $p);
}

/* ───────────────────────── teams ───────────────────────── */

function crm_teams(int $clientId): array
{
    try {
        return db_all("SELECT t.*, COALESCE(NULLIF(u.name,''), u.email) AS leader_name,
                              (SELECT COUNT(*) FROM users m WHERE m.team_id=t.id AND m.status='active') AS members
                         FROM crm_teams t LEFT JOIN users u ON u.id=t.leader_user_id
                        WHERE t.client_id=? ORDER BY t.sort, t.name", [$clientId]);
    } catch (Throwable $e) { return []; }
}

/** id => name */
function crm_team_names(int $clientId): array
{
    return array_column(crm_teams($clientId), 'name', 'id');
}

/** The people on a team (active), and its leader whether or not they are on it. */
function crm_team_member_ids(int $teamId): array
{
    try {
        $ids = array_map('intval', array_column(db_all("SELECT id FROM users WHERE team_id=? AND status='active'", [$teamId]), 'id'));
        $lead = (int) db_val("SELECT leader_user_id FROM crm_teams WHERE id=?", [$teamId]);
        if ($lead) $ids[] = $lead;
        return array_values(array_unique($ids));
    } catch (Throwable $e) { return []; }
}

/** The teams this person leads. */
function crm_led_team_ids(int $userId): array
{
    try { return array_map('intval', array_column(db_all("SELECT id FROM crm_teams WHERE leader_user_id=?", [$userId]), 'id')); }
    catch (Throwable $e) { return []; }
}

/**
 * Whose leads this salesperson may see: their own, and — if they lead a team — their team's.
 * NULL means everyone's (Admins and Viewers).
 */
function crm_visible_owner_ids(): ?array
{
    if (!function_exists('is_sales') || !is_sales()) return null;
    $me = (int) crm_actor_id();
    static $cache = [];
    if (isset($cache[$me])) return $cache[$me];
    $ids = [$me];
    foreach (crm_led_team_ids($me) as $t) $ids = array_merge($ids, crm_team_member_ids($t));
    return $cache[$me] = array_values(array_unique(array_map('intval', $ids)));
}

/** Does the signed-in salesperson lead a team? Leaders may pass leads around inside it. */
function crm_is_team_leader(): bool
{
    $ids = crm_visible_owner_ids();
    return $ids !== null && count($ids) > 1;
}

/* ───────────────────────── the account's own fields ───────────────────────── */

function crm_field_types(): array
{
    return ['text' => 'Text', 'number' => 'Number', 'date' => 'Date', 'list' => 'Pick from a list'];
}

function crm_fields(int $clientId, bool $activeOnly = true): array
{
    try {
        $rows = db_all("SELECT * FROM crm_fields WHERE client_id=?" . ($activeOnly ? " AND active=1" : "") . " ORDER BY sort, id", [$clientId]);
    } catch (Throwable $e) { return []; }
    foreach ($rows as &$r) $r['choices'] = $r['type'] === 'list' ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $r['options']) ?: []), 'strlen')) : [];
    return $rows;
}

/** A key for a new field, from its label: "Nationality" → nationality, "الجنسية" → f_1a2b3c. */
function crm_field_key(int $clientId, string $label): string
{
    $k = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
    if ($k === '' || strlen($k) < 2) $k = 'f_' . substr(md5($label), 0, 6);
    $k = substr($k, 0, 36);
    $base = $k; $n = 2;
    while (db_val("SELECT COUNT(*) FROM crm_fields WHERE client_id=? AND fkey=?", [$clientId, $k])) $k = substr($base, 0, 33) . '_' . $n++;
    return $k;
}

function crm_custom_get(array $lead): array
{
    return json_decode((string) ($lead['custom'] ?? ''), true) ?: [];
}

/**
 * Save the account's fields for a lead from submitted values (key => raw). Values are checked
 * against the field's type; anything not a field of this account is dropped.
 *
 * @return string '' when saved, else what is wrong
 */
function crm_custom_save(int $clientId, int $contactId, array $raw): string
{
    if (!db_has_column('contacts', 'custom')) return '';
    $fields = crm_fields($clientId, false);
    if (!$fields) return '';
    $cur = crm_custom_get(db_row("SELECT custom FROM contacts WHERE id=? AND client_id=?", [$contactId, $clientId]) ?: []);
    foreach ($fields as $f) {
        if (!array_key_exists($f['fkey'], $raw)) continue;
        $v = trim((string) $raw[$f['fkey']]);
        if ($v === '') { unset($cur[$f['fkey']]); continue; }
        switch ($f['type']) {
            case 'number':
                $n = str_replace([',', ' '], '', $v);
                if (!is_numeric($n)) return $f['label'] . ' has to be a number.';
                $v = (string) (0 + $n);
                break;
            case 'date':
                if (!strtotime($v)) return $f['label'] . ' has to be a date.';
                $v = date('Y-m-d', strtotime($v));
                break;
            case 'list':
                $m = null;
                foreach ($f['choices'] as $ch) if (mb_strtolower($ch) === mb_strtolower($v)) $m = $ch;
                if ($m === null) return 'Choose ' . $f['label'] . ' from its list.';
                $v = $m;
                break;
            default:
                $v = mb_substr($v, 0, 255);
        }
        $cur[$f['fkey']] = $v;
    }
    db_run("UPDATE contacts SET custom=? WHERE id=? AND client_id=?", [$cur ? json_encode($cur, JSON_UNESCAPED_UNICODE) : null, $contactId, $clientId]);
    return '';
}

/** A custom field's value for display. */
function crm_custom_show(array $field, $v): string
{
    if ($v === null || $v === '') return '';
    if ($field['type'] === 'number') return number_format((float) $v, fmod((float) $v, 1.0) ? 2 : 0);
    if ($field['type'] === 'date') return date('j M Y', strtotime((string) $v));
    return (string) $v;
}
