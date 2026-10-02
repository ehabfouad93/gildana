<?php
declare(strict_types=1);

/**
 * Permissions. Every action in the app is a named capability; each role has a
 * default set, and the admin can change any role's set on the Roles page
 * (stored as JSON in settings.role_caps). Admin always has everything.
 *
 * Which clients a person can *see* also follows from their capabilities — see
 * can_view_client() in domain.php — so granting "Book meetings" to a role also
 * shows that role the booking queue.
 */

/** capability => default roles. Order here is the order on the Roles page. */
function cap_defaults(): array
{
    return [
        'clients.add'          => ['advisor'],
        'clients.book'         => ['booker'],
        'clients.confirm'      => ['communicator'],
        'clients.arrive'       => ['sales_manager'],
        'clients.close'        => ['sales'],
        'clients.view_all'     => ['owner_services', 'sales_manager'],
        'clients.edit'         => [],
        'contracts.view'       => ['accountant', 'owner_services', 'sales_manager'],
        'contracts.edit'       => [],
        'contracts.reschedule' => [],
        'payments.record'      => ['owner_services'],
        'stays.manage'         => ['owner_services'],
        'projects.manage'      => ['owner_services'],
        'whatsapp.send'        => ['communicator', 'owner_services'],
        'export.csv'           => ['accountant', 'owner_services', 'sales_manager'],
    ];
}

/** role => [cap, …] currently in force (defaults merged with the admin's overrides). */
function role_caps(): array
{
    static $map = null;
    if ($map !== null) return $map;

    $map = [];
    foreach (ROLES as $r) $map[$r] = [];
    foreach (cap_defaults() as $cap => $roles) {
        foreach ($roles as $r) $map[$r][] = $cap;
    }
    $saved = json_decode(setting('role_caps'), true);
    if (is_array($saved)) {
        $known = array_keys(cap_defaults());
        foreach ($saved as $role => $caps) {
            if (!isset($map[$role]) || $role === 'admin' || !is_array($caps)) continue;
            $map[$role] = array_values(array_intersect($known, $caps));
        }
    }
    return $map;
}

/** Does the signed-in user (or $u) have this capability? */
function can(string $cap, ?array $u = null): bool
{
    $u = $u ?? current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    return in_array($cap, role_caps()[$u['role']] ?? [], true);
}

/** Any of the given capabilities. */
function can_any(string ...$caps): bool
{
    foreach ($caps as $c) if (can($c)) return true;
    return false;
}

/** Guard: stop with 403 unless the user has one of $caps. */
function require_cap(string ...$caps): void
{
    if (!can_any(...$caps)) {
        http_response_code(403);
        exit(t('err.forbidden'));
    }
}
