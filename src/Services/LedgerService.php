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

    /**
     * Admin: brings a consignor's balance to 0 with a passbook entry that keeps the reason. No money
     * moves, and reports count it apart from payments. $expected is the balance the admin saw, so a
     * balance that changed in the meantime is not written off unseen.
     *
     * @return array{ref_no:string, amount:float}
     */
    public function adjustToZero(int $consignorId, float $expected, string $reason, DocumentNumberService $docs, AuditLogger $audit, int $userId, ?string $ip): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลของยอดที่ปรับ');
        }
        if (mb_strlen($reason) > 200) {
            throw new \RuntimeException('เหตุผลยาวได้ไม่เกิน 200 ตัวอักษร');
        }
        $type = $this->db->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_entries' AND COLUMN_NAME = 'type'"
        )->fetchColumn();
        if (!str_contains((string) $type, "'adjust'")) {
            throw new \RuntimeException('ยังปรับยอดไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0068) ก่อน');
        }

        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM consignors WHERE id = ? FOR UPDATE');
            $lock->execute([$consignorId]);
            if (!$lock->fetchColumn()) {
                throw new \RuntimeException('ไม่พบผู้ฝากขาย');
            }
            $pending = $this->db->prepare("SELECT doc_no FROM withdrawals WHERE consignor_id = ? AND status = 'pending' LIMIT 1");
            $pending->execute([$consignorId]);
            if (($docNo = $pending->fetchColumn()) !== false) {
                throw new \RuntimeException("ผู้ฝากรายนี้มีคำขอเบิกเงิน {$docNo} รออยู่ อนุมัติหรือยกเลิกคำขอก่อนจึงปรับยอดได้");
            }
            $balance = $this->balanceOf($consignorId);
            if (abs($balance) < 0.005) {
                throw new \RuntimeException('ยอดเงินคงเหลือเป็น 0 อยู่แล้ว');
            }
            if (abs($balance - $expected) >= 0.005) {
                throw new \RuntimeException('ยอดเงินคงเหลือเพิ่งเปลี่ยนไปเป็น ' . number_format($balance, 2) . ' บาท กรุณาตรวจสอบอีกครั้ง');
            }
            $refNo = $docs->next('ADJ', 'ledger_entries', 'ref_no');
            $this->append(
                $consignorId,
                'adjust',
                mb_substr('ปรับยอดเป็น 0 (จาก ' . number_format($balance, 2) . " บาท): {$reason}", 0, 255),
                $refNo,
                $balance < 0 ? -$balance : 0,
                $balance > 0 ? $balance : 0,
                $userId
            );
            $audit->log('consignors', $consignorId, 'update', ['balance' => $balance], ['balance' => 0, 'ref_no' => $refNo, 'reason' => $reason], $userId, $ip);
            $this->db->commit();
            return ['ref_no' => $refNo, 'amount' => $balance];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
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
