<?php
declare(strict_types=1);

/**
 * What a client has bought, and what each of its users may do with it.
 *
 * Two independent layers, and a user gets the intersection:
 *
 *   the client's modules   set by the platform operator in Admin → client. "This account has
 *                          Campaigns and CRM." NULL means everything, which is what every
 *                          existing account has.
 *
 *   the user's modules     set by the client's own Admin in Team. "This salesperson sees CRM
 *                          and Inbox." NULL means the role's defaults.
 *
 * Intersecting rather than trusting either alone is the point: a client Admin can narrow what a
 * salesperson sees, but can never hand out a module the operator has switched off.
 *
 * Enforcement lives in client/_init.php, which every client page loads, so there is one place to
 * get right instead of twenty-five pages each remembering to check.
 */

/**
 * Every module, the pages that belong to it, and its sidebar key.
 *
 * `pages` lists the files a user needs this module to open. A page in several lists opens if the
 * user has ANY of them — upload_media.php serves Campaigns, Automations, the Qualifier and the
 * Inbox's template picker alike, and should not demand all four.
 */
function perm_modules(): array
{
    return [
        'inbox'       => ['label' => 'Inbox',          'pages' => ['inbox.php', 'upload_media.php', 'media.php']],
        'crm'         => ['label' => 'CRM',            'pages' => ['crm.php', 'crm_lead.php', 'crm_import.php',
                                                                   'crm_reports.php', 'meta_leads.php',
                                                                   'crm_team.php', 'crm_rules.php', 'crm_setup.php',
                                                                   'crm_messages.php', 'crm_calendar.php', 'crm_dashboard.php', 'media.php',
                                                                   'crm_manage.php', 'lead_search.php', 'crm_events.php']],
        'contacts'    => ['label' => 'Contacts',       'pages' => ['contacts.php']],
        'lists'       => ['label' => 'Lists',          'pages' => ['lists.php', 'contact_search.php']],
        'templates'   => ['label' => 'Templates',      'pages' => ['templates.php']],
        'campaigns'   => ['label' => 'Campaigns',      'pages' => ['campaigns.php', 'campaign_new.php', 'report.php',
                                                                   'failed.php', 'upload_media.php']],
        'automations' => ['label' => 'Automations',    'pages' => ['automations.php', 'automation_edit.php',
                                                                   'automation_report.php', 'google_sheet.php',
                                                                   'upload_media.php']],
        'qualifier'   => ['label' => 'Lead Qualifier', 'pages' => ['qualifiers.php', 'qualifier_edit.php', 'leads.php',
                                                                   'google_sheet.php', 'upload_media.php']],
        'agents'      => ['label' => 'AI Chat Agent',  'pages' => ['agents.php', 'agent_edit.php', 'agent_chats.php']],
        'reports'     => ['label' => 'Reports',        'pages' => ['reports.php']],
        'billing'     => ['label' => 'Billing',        'pages' => ['billing.php']],
        'settings'    => ['label' => 'Settings',       'pages' => ['settings.php', 'diagnostics.php']],
        'team'        => ['label' => 'Team',           'pages' => ['team.php']],
    ];
}

/**
 * Account administration — not something a plan switches on or off.
 *
 * Selling a client "Campaigns only" still leaves them needing to connect WhatsApp (Settings)
 * and add their own people (Team). If these were ordinary modules, that exact plan would create
 * an account whose own Admin could do neither. So they are always part of the account, left off
 * the operator's plan checklist, and handed out by the client's Admin like anything else.
 */
function perm_account_modules(): array
{
    return ['team', 'settings'];
}

/** The modules a plan switches on and off: everything except account administration. */
function perm_plan_modules(): array
{
    return array_diff_key(perm_modules(), array_flip(perm_account_modules()));
}

/** Pages every signed-in client user may open whatever their modules. */
function perm_open_pages(): array
{
    // index.php is where login lands; refusing it would leave a narrowly-scoped user with no
    // page to arrive on. profile.php and my_whatsapp.php are the person's OWN password and
    // phone link — theirs whatever the account lets them see.
    // notices.php opens the person's own notices from the bell.
    return ['index.php', 'profile.php', 'my_whatsapp.php', 'notices.php'];
}

/** The roles, and what each sees when nobody has ticked anything specific for them. */
function perm_roles(): array
{
    return [
        'admin'  => ['label' => 'Admin',  'defaults' => null],       // null = everything the client has
        'sales'  => ['label' => 'Sales',  'defaults' => ['crm', 'inbox', 'contacts']],
        'viewer' => ['label' => 'Viewer', 'defaults' => ['crm', 'reports']],
    ];
}

/** Parse a stored module list. NULL / '' means "no restriction" and comes back as null. */
function perm_parse(?string $raw): ?array
{
    if ($raw === null || trim($raw) === '') return null;
    $known = array_keys(perm_modules());
    return array_values(array_intersect($known, array_map('trim', explode(',', $raw))));
}

/**
 * Store a client's plan. Every plan module ticked is stored as NULL, so "all" keeps meaning all
 * as modules are added in later releases, rather than freezing today's list.
 */
function perm_store(array $modules): ?string
{
    $plan = array_keys(perm_plan_modules());
    $keep = array_values(array_intersect($plan, $modules));
    return count($keep) === count($plan) ? null : implode(',', $keep);
}

/** Modules this client account has: its plan, plus account administration, always. */
function client_modules(array $client): array
{
    $plan = perm_parse($client['modules'] ?? null) ?? array_keys(perm_plan_modules());
    return array_values(array_unique(array_merge($plan, perm_account_modules())));
}

/** The user's role inside the client, defaulting to the least surprising answer. */
function user_client_role(array $user): string
{
    $r = (string) ($user['client_role'] ?? 'admin');
    return isset(perm_roles()[$r]) ? $r : 'viewer';     // an unknown role gets the least access
}

/**
 * Modules this user may use: their own list (or their role's defaults) ∩ the client's.
 *
 * A platform admin working inside a client's account sees everything the client has — they are
 * there to support the client, and hiding modules from them would hide what they came to fix.
 */
function user_modules(array $user, array $client): array
{
    $clientHas = client_modules($client);
    if (($user['role'] ?? '') === 'admin') return $clientHas;

    $role = user_client_role($user);
    $mine = perm_parse($user['modules'] ?? null) ?? perm_roles()[$role]['defaults'] ?? $clientHas;

    // An Admin always keeps Team and Settings, or a client could lock itself out of the screen
    // it would need to undo the mistake.
    if ($role === 'admin') $mine = array_merge($mine, ['team', 'settings']);

    return array_values(array_intersect($clientHas, $mine));
}

/**
 * The CRM's own pages, a level below the CRM module. An Admin ticks which of these each person
 * may open, under the CRM tick on the Team page. The Pipeline (and a lead's page) is the CRM
 * itself, so it comes with the module; manager pages stay Admin-only whatever is ticked.
 */
function perm_crm_pages(): array
{
    return [
        'pipeline'  => ['label' => 'Pipeline & leads',   'pages' => ['crm.php', 'crm_lead.php'], 'always' => true],
        'dashboard' => ['label' => 'Dashboard',          'pages' => ['crm_dashboard.php']],
        'visits'    => ['label' => 'Visits, meetings & events', 'pages' => ['crm_calendar.php', 'crm_events.php']],
        'reports'   => ['label' => 'Reports',            'pages' => ['crm_reports.php']],
        'import'    => ['label' => 'Import leads',       'pages' => ['crm_import.php']],
        'team'      => ['label' => 'Team & transfer',    'pages' => ['crm_team.php'],     'admin' => true],
        'rules'     => ['label' => 'Assignment rules',   'pages' => ['crm_rules.php'],    'admin' => true],
        'messages'  => ['label' => 'Automatic messages', 'pages' => ['crm_messages.php'], 'admin' => true],
        'forms'     => ['label' => 'Lead forms',         'pages' => ['meta_leads.php'],   'admin' => true],
        'setup'     => ['label' => 'Projects & lists',   'pages' => ['crm_setup.php'],    'admin' => true],
        'manage'    => ['label' => 'Requests, bin & imports', 'pages' => ['crm_manage.php'], 'admin' => true],
    ];
}

/** The CRM pages this person may open, or NULL for all of them. */
function user_crm_pages(array $user): ?array
{
    if (($user['role'] ?? '') === 'admin') return null;                // the platform operator sees everything
    $raw = $user['crm_pages'] ?? null;
    if ($raw === null) return null;
    $keys = array_values(array_intersect(array_keys(perm_crm_pages()), array_map('trim', explode(',', (string) $raw))));
    $keys = array_values(array_unique(array_merge($keys, ['pipeline'])));
    // Every page ticked (stored alongside actions like "export") means all of them, new ones included.
    return count($keys) === count(perm_crm_pages()) ? null : $keys;
}

/** May the signed-in person open this CRM page? Needs the CRM itself, and — unless all — the tick. */
function can_crm(string $key): bool
{
    if (!can_use('crm')) return false;
    [$u] = perm_context();
    $mine = user_crm_pages($u);
    return $mine === null || in_array($key, $mine, true);
}

/** Which CRM page a file is, if any. */
function crm_page_key(string $page): ?string
{
    foreach (perm_crm_pages() as $k => $d) if (in_array($page, $d['pages'], true)) return $k;
    return null;
}

/** Current request's user row and client, as set up by client/_init.php. */
function perm_context(): array
{
    return [$GLOBALS['PERM_USER'] ?? [], $GLOBALS['CLIENT'] ?? []];
}

function can_use(string $module): bool
{
    [$u, $c] = perm_context();
    if (!$u || !$c) return false;
    return in_array($module, user_modules($u, $c), true);
}

/** May this user change anything? Viewers may not; everyone else may. */
function can_write(): bool
{
    [$u] = perm_context();
    if (!$u) return false;
    if (($u['role'] ?? '') === 'admin') return true;
    return user_client_role($u) !== 'viewer';
}

function is_sales(): bool
{
    [$u] = perm_context();
    return $u && ($u['role'] ?? '') !== 'admin' && user_client_role($u) === 'sales';
}

/**
 * May this person export leads to Excel? A phone list is the most valuable thing a sales team
 * holds and the easiest to walk out with, so only an Admin — or someone an Admin ticked
 * "Export leads" for on the Team page — may.
 */
function can_crm_export(): bool
{
    return function_exists('can_crm_action') && can_crm_action('export');
}

function is_client_admin(): bool
{
    [$u] = perm_context();
    return $u && (($u['role'] ?? '') === 'admin' || user_client_role($u) === 'admin');
}

/**
 * May this user open this page? Returns null when yes, or the reason when no.
 *
 * Distinguishes "your plan doesn't include this" from "your role doesn't include this", because
 * the fix is different: the first is a conversation with the operator, the second with the
 * account's own Admin.
 */
function page_denied(string $page, array $user, array $client): ?string
{
    if (in_array($page, perm_open_pages(), true)) return null;

    $needs = [];
    foreach (perm_modules() as $key => $m) {
        if (in_array($page, $m['pages'], true)) $needs[] = $key;
    }
    // A page no module claims is not something this layer knows about. Allow it rather than
    // lock users out of something new — but that is a bug to fix by adding it above.
    if (!$needs) return null;

    if (array_intersect($needs, user_modules($user, $client))) {
        // Inside the CRM, a page the Admin did not tick for this person stays closed.
        $k = crm_page_key($page);
        if ($k !== null && ($mine = user_crm_pages($user)) !== null && !in_array($k, $mine, true)) return 'role';
        return null;
    }
    if (!array_intersect($needs, client_modules($client))) return 'plan';
    return 'role';
}
