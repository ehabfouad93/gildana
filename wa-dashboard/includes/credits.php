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
