<?php
declare(strict_types=1);

/**
 * Credit ledger. Every balance change goes through here so the
 * credit_transactions table stays an accurate append-only history.
 */

/**
 * Adjust a client's balance by $delta (may be negative). Records a ledger row.
 * Returns the new balance, or null on failure (e.g. would go negative on debit).
 * Runs inside a transaction with a row lock to stay correct under concurrency.
 */
function credits_adjust(int $clientId, int $delta, string $reason, ?int $campaignId = null): ?int
{
    $pdo = db();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();

    try {
        $row = db_row("SELECT credits_balance FROM clients WHERE id = ? FOR UPDATE", [$clientId]);
        if (!$row) {
            if ($ownTx) $pdo->rollBack();
            return null;
        }
        $balance = (int) $row['credits_balance'];
        $newBal  = $balance + $delta;
        if ($newBal < 0) {           // never allow a debit past zero
            if ($ownTx) $pdo->rollBack();
            return null;
        }
        db_run("UPDATE clients SET credits_balance = ? WHERE id = ?", [$newBal, $clientId]);
        db_run(
            "INSERT INTO credit_transactions (client_id, delta, balance_after, reason, campaign_id, created_at)
             VALUES (?,?,?,?,?,NOW())",
            [$clientId, $delta, $newBal, $reason, $campaignId]
        );
        if ($ownTx) $pdo->commit();
        return $newBal;
    } catch (Throwable $ex) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

function credits_balance(int $clientId): int
{
    return (int) db_val("SELECT credits_balance FROM clients WHERE id = ?", [$clientId]);
}

function credits_ledger(int $clientId, int $limit = 50): array
{
    return db_all(
        "SELECT * FROM credit_transactions WHERE client_id = ? ORDER BY id DESC LIMIT " . (int) $limit,
        [$clientId]
    );
}

/**
 * Reserve credits for a whole claimed batch in ONE transaction.
 *
 * The per-message path (credits_adjust in a loop) took a row lock on the client's balance
 * once per message: 1,000 sends meant 1,000 transactions contending on a single row, which
 * becomes a real bottleneck the moment several tenants send at the same time.
 *
 * Reserves as many as the balance allows and reports the number granted, so a client who
 * can afford 40 of 50 queued messages sends 40 rather than failing the batch. Returns
 * ['granted' => int, 'txn_id' => ?int]; txn_id is null when nothing was granted.
 */
function credits_reserve(int $clientId, int $want, ?int $campaignId = null): array
{
    if ($want <= 0) return ['granted' => 0, 'txn_id' => null];

    $pdo = db();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();

    try {
        $row = db_row("SELECT credits_balance FROM clients WHERE id = ? FOR UPDATE", [$clientId]);
        if (!$row) {
            if ($ownTx) $pdo->rollBack();
            return ['granted' => 0, 'txn_id' => null];
        }
        $balance = (int) $row['credits_balance'];
        $granted = max(0, min($want, $balance));
        if ($granted === 0) {
            if ($ownTx) $pdo->rollBack();
            return ['granted' => 0, 'txn_id' => null];
        }
        $newBal = $balance - $granted;
        db_run("UPDATE clients SET credits_balance = ? WHERE id = ?", [$newBal, $clientId]);
        $txnId = db_insert(
            "INSERT INTO credit_transactions (client_id, delta, balance_after, reason, campaign_id, created_at)
             VALUES (?,?,?,?,?,NOW())",
            [$clientId, -$granted, $newBal, 'send', $campaignId]
        );
        if ($ownTx) $pdo->commit();
        return ['granted' => $granted, 'txn_id' => (int) $txnId];
    } catch (Throwable $ex) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/**
 * Give back credits reserved by credits_reserve() that were never spent — messages that
 * failed, or that the batch never got to. One ledger row for the whole release.
 */
function credits_release(int $clientId, int $count, ?int $campaignId = null, string $reason = 'refund_failed'): void
{
    if ($count <= 0) return;
    credits_adjust($clientId, $count, $reason, $campaignId);
}
