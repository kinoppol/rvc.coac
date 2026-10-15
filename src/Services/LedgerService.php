<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Appends a passbook ledger entry for a consignor and recomputes the running
 * balance, locking the consignor row so concurrent writes stay consistent.
 */
class LedgerService
{
    public function __construct(private PDO $db)
    {
    }

    /** Must be called inside an existing transaction. */
    public function append(
        int $consignorId,
        string $type,
        string $description,
        string $refNo,
        float $amountIn,
        float $amountOut,
        ?int $userId,
        ?string $entryDate = null
    ): float {
        // Lock so two concurrent transactions can't both read a stale balance.
        $lock = $this->db->prepare('SELECT id FROM consignors WHERE id = ? FOR UPDATE');
        $lock->execute([$consignorId]);

        $prevStmt = $this->db->prepare(
            'SELECT balance_after FROM ledger_entries WHERE consignor_id = ? ORDER BY id DESC LIMIT 1'
        );
        $prevStmt->execute([$consignorId]);
        $prevBalance = (float) ($prevStmt->fetchColumn() ?: 0);

        $newBalance = round($prevBalance + $amountIn - $amountOut, 2);

        $ins = $this->db->prepare(
            'INSERT INTO ledger_entries
                (consignor_id, entry_date, description, ref_no, type, amount_in, amount_out, balance_after, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $ins->execute([
            $consignorId,
            $entryDate ?? date('Y-m-d'),
            $description,
            $refNo,
            $type,
            $amountIn,
            $amountOut,
            $newBalance,
            $userId,
        ]);

        return $newBalance;
    }

    public function balanceOf(int $consignorId): float
    {
        $stmt = $this->db->prepare(
            'SELECT balance_after FROM ledger_entries WHERE consignor_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$consignorId]);
        return (float) ($stmt->fetchColumn() ?: 0);
    }

    public function forConsignor(int $consignorId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ledger_entries WHERE consignor_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$consignorId]);
        return $stmt->fetchAll();
    }
}
