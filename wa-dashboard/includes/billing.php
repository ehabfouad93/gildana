<?php
declare(strict_types=1);

/**
 * Plans, message pricing and AI metering.
 *
 * The one thing to understand before reading the rest: what a credit MEANS depends on how the
 * client is connected to WhatsApp.
 *
 *   byo      — the client owns their WhatsApp Business Account, so Meta invoices them
 *              directly. We carry no message cost at all, and a credit is simply the fee for
 *              using this platform. One message, one credit, exactly as it has always been.
 *
 *   platform — the client sends under OUR account, so we pay Meta and rebill. Here a credit
 *              has to track a real cost, which varies by destination country and by message
 *              category, and is zero for a service reply inside the 24-hour window.
 *
 * Getting that distinction wrong in either direction is expensive: charge a BYO client for a
 * cost they already paid Meta, or under-charge a platform client and absorb their bill.
 */

require_once __DIR__ . '/credits.php';

/** Money value of one credit, so cost and credits can be converted both ways. */
function billing_credit_value(): float
{
    static $v = null;
    if ($v === null) {
        $raw = db_val("SELECT v FROM app_settings WHERE k='credit_value'");
        $v = ($raw === false || $raw === null || (float) $raw <= 0) ? 0.01 : (float) $raw;
    }
    return $v;
}

/** Multiplier applied to our real cost before charging the client. 1.0 = at cost. */
function billing_markup(): float
{
    static $m = null;
    if ($m === null) {
        $raw = db_val("SELECT v FROM app_settings WHERE k='billing_markup'");
        $m = ($raw === false || $raw === null || (float) $raw <= 0) ? 1.0 : (float) $raw;
    }
    return $m;
}

function billing_period_start(): string { return date('Y-m-01'); }
function billing_period_end(): string   { return date('Y-m-t'); }

/** Is this client sending under our WhatsApp account rather than their own? */
function billing_on_platform_waba(array $client): bool
{
    return ($client['waba_mode'] ?? 'byo') === 'platform';
}

/**
 * The dialling prefix for a number, longest match first.
 *
 * Rates are per country, and E.164 prefixes vary in length (1, 20, 966…), so the longest
 * configured prefix that the number starts with is the right one. Falls back to '*'.
 */
function billing_country_for(string $phoneE164): string
{
    static $prefixes = null;
    if ($prefixes === null) {
        $prefixes = array_column(
            db_all("SELECT DISTINCT country_code FROM wa_rates WHERE country_code <> '*'"), 'country_code');
        usort($prefixes, fn($a, $b) => strlen($b) <=> strlen($a));
    }
    $digits = preg_replace('/\D+/', '', $phoneE164) ?? '';
    foreach ($prefixes as $p) {
        if ($p !== '' && str_starts_with($digits, $p)) return $p;
    }
    return '*';
}

/**
 * What Meta charges us for one message. Returns 0 for anything we do not pay for — which is
 * every BYO client, and every service message inside the 24-hour window.
 */
function billing_message_cost(array $client, string $phoneE164, string $category): float
{
    if (!billing_on_platform_waba($client)) return 0.0;

    $category = strtolower(trim($category)) ?: 'utility';
    // A free-form reply within the customer-service window is not billed by Meta.
    if ($category === 'service') return 0.0;

    $country = billing_country_for($phoneE164);
    $row = db_row(
        "SELECT cost FROM wa_rates
          WHERE country_code IN (?, '*') AND category = ? AND effective_from <= CURDATE()
          ORDER BY (country_code = '*') ASC, effective_from DESC LIMIT 1",
        [$country, $category]
    );
    return $row ? (float) $row['cost'] : 0.0;
}

/** Convert money into whole credits, always rounding in the client's favour is NOT safe here —
 *  a fraction of a credit still costs us money, so round up. */
function billing_cost_to_credits(float $cost): int
{
    if ($cost <= 0) return 0;
    $v = billing_credit_value();
    return max(1, (int) ceil(($cost * billing_markup()) / $v));
}

/**
 * How many credits one outbound message costs this client.
 *
 * BYO stays at exactly 1, which is what every existing client already pays and what all the
 * existing tests assert. Platform mode prices from the rate table.
 */
function billing_message_credits(array $client, string $phoneE164, string $category = 'utility'): int
{
    if (!billing_on_platform_waba($client)) return 1;
    $cost = billing_message_cost($client, $phoneE164, $category);
    return $cost > 0 ? billing_cost_to_credits($cost) : 1;
}

/** Record what a batch of messages cost, for the invoice line and the period total. */
function billing_record_messages(array $client, string $phoneE164, string $category, int $qty, float $cost, int $credits): void
{
    if ($qty <= 0) return;
    $cid    = (int) $client['id'];
    $period = billing_period_start();
    $mode   = billing_on_platform_waba($client) ? 'platform' : 'byo';
    $country = billing_country_for($phoneE164);
    try {
        db_run(
            "INSERT INTO message_charges (client_id,period_start,country_code,category,waba_mode,qty,cost,credits)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty), cost=cost+VALUES(cost), credits=credits+VALUES(credits)",
            [$cid, $period, $country, strtolower($category ?: 'utility'), $mode, $qty, $cost, $credits]
        );
        billing_touch_period($cid, ['messages_sent' => $qty, 'credits_used' => $credits, 'platform_cost' => $cost]);
    } catch (Throwable $e) { /* billing records must never block a send */ }
}

/** Add to this client's running totals for the current month. */
function billing_touch_period(int $clientId, array $deltas): void
{
    try {
        db_run(
            "INSERT INTO usage_periods (client_id,period_start,period_end,credits_used,messages_sent,ai_credits,platform_cost)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                credits_used  = credits_used  + VALUES(credits_used),
                messages_sent = messages_sent + VALUES(messages_sent),
                ai_credits    = ai_credits    + VALUES(ai_credits),
                platform_cost = platform_cost + VALUES(platform_cost)",
            [$clientId, billing_period_start(), billing_period_end(),
             (int) ($deltas['credits_used'] ?? 0), (int) ($deltas['messages_sent'] ?? 0),
             (int) ($deltas['ai_credits'] ?? 0), (float) ($deltas['platform_cost'] ?? 0)]
        );
    } catch (Throwable $e) { /* non-fatal */ }
}

/* ── AI ─────────────────────────────────────────────────────────────────────────── */

/** The platform's own AI key, used only for clients whose plan includes AI. */
function billing_platform_ai(): ?array
{
    $provider = (string) (db_val("SELECT v FROM app_settings WHERE k='ai_platform_provider'") ?: '');
    $keyEnc   = (string) (db_val("SELECT v FROM app_settings WHERE k='ai_platform_key'") ?: '');
    if (!in_array($provider, ['claude', 'openai'], true) || $keyEnc === '') return null;
    $key = decrypt_secret($keyEnc);
    if ($key === '') return null;
    $model = (string) (db_val("SELECT v FROM app_settings WHERE k='ai_platform_model'") ?: '');
    return ['provider' => $provider, 'key' => $key, 'model' => $model];
}

/** Does this client's plan include AI from our key? */
function billing_ai_included(array $client): bool
{
    $planId = (int) ($client['plan_id'] ?? 0);
    if ($planId <= 0) return false;
    return (string) db_val("SELECT ai_mode FROM plans WHERE id=?", [$planId]) === 'included';
}

/** AI credits already used this month. */
function billing_ai_used(int $clientId): int
{
    return (int) (db_val("SELECT ai_credits FROM usage_periods WHERE client_id=? AND period_start=?",
        [$clientId, billing_period_start()]) ?: 0);
}

/** How much AI allowance is left. Returns null when the client is on their own key (no limit). */
function billing_ai_remaining(array $client): ?int
{
    if (!billing_ai_included($client)) return null;
    $allow = (int) db_val("SELECT included_ai_credits FROM plans WHERE id=?", [(int) $client['plan_id']]);
    if ($allow <= 0) return 0;
    return max(0, $allow - billing_ai_used((int) $client['id']));
}

/**
 * Charge one metered AI call.
 *
 * Called only when the PLATFORM key was used — a client on their own key never reaches here,
 * costs us nothing and is never billed for tokens.
 */
function billing_charge_ai(array $client, string $provider, string $model, int $inTok, int $outTok, string $source = ''): int
{
    if ($inTok <= 0 && $outTok <= 0) return 0;
    $cid = (int) $client['id'];

    $rate = db_row("SELECT * FROM ai_rates WHERE provider=? AND model=?", [$provider, $model]);
    $cost = $rate
        ? ($inTok / 1000000) * (float) $rate['input_per_mtok'] + ($outTok / 1000000) * (float) $rate['output_per_mtok']
        : 0.0;
    $credits = billing_cost_to_credits($cost);

    try {
        db_run(
            "INSERT INTO ai_usage (client_id,period_start,provider,model,input_tokens,output_tokens,cost,credits,source,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())",
            [$cid, billing_period_start(), $provider, $model, $inTok, $outTok, $cost, $credits, $source ?: null]
        );
        billing_touch_period($cid, ['ai_credits' => $credits, 'platform_cost' => $cost]);
        if ($credits > 0) credits_adjust($cid, -$credits, 'ai_usage', null);
    } catch (Throwable $e) { /* never break a live conversation over accounting */ }
    return $credits;
}

/** Grant this month's included credits and move the renewal date on. */
function billing_renew(array $client): bool
{
    $planId = (int) ($client['plan_id'] ?? 0);
    if ($planId <= 0) return false;
    $plan = db_row("SELECT * FROM plans WHERE id=? AND is_active=1", [$planId]);
    if (!$plan) return false;

    $renews = $client['plan_renews_at'] ?? null;
    if ($renews !== null && strtotime((string) $renews) > time()) return false;   // not due

    $cid = (int) $client['id'];
    if ((int) $plan['included_credits'] > 0) {
        credits_adjust($cid, (int) $plan['included_credits'], 'plan_grant', null);
    }
    db_run("UPDATE clients SET plan_started_at=COALESCE(plan_started_at,NOW()),
                   plan_renews_at=DATE_ADD(COALESCE(plan_renews_at,NOW()), INTERVAL 1 MONTH)
             WHERE id=?", [$cid]);
    return true;
}
