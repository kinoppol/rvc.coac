<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\LedgerService;
use PDO;

class WithdrawalRepo
{
    public function __construct(
        private PDO $db,
        private AuditLogger $audit,
        private DocumentNumberService $docs,
        private LedgerService $ledger
    ) {
    }

    public function recent(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT w.*, c.name AS consignor_name, cu.full_name AS cancelled_by_name FROM withdrawals w
             JOIN consignors c ON c.id = w.consignor_id
             LEFT JOIN users cu ON cu.id = w.cancelled_by
             ORDER BY w.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function pendingCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM withdrawals WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Immediately marks the payment as paid and records it in the ledger,
     * matching the mockup's "ทุกครั้งที่จ่ายเงินระบบจะบันทึกลงสมุดให้ทันที".
     *
     * Paying more than the balance needs a reason (e.g. goods lost while the shop was
     * responsible): the account goes negative and later sales pay it back.
     */
    public function payNow(int $consignorId, float $amount, string $method, int $userId, ?string $ip, ?string $overdrawReason = null): int
    {
        $balance = $this->ledger->balanceOf($consignorId);
        $overdrawReason = mb_substr(trim((string) $overdrawReason), 0, 150);
        if ($amount <= 0) {
            throw new \RuntimeException('จำนวนเงินที่เบิกไม่ถูกต้อง');
        }
        $overdraw = $amount > $balance;
        if ($overdraw && $overdrawReason === '') {
            throw new \RuntimeException('จำนวนเงินที่เบิกเกินยอดคงเหลือ ถ้าร้านต้องรับผิดชอบ (เช่น สินค้าสูญหาย) ให้เลือกเบิกเกินยอดและระบุเหตุผล');
        }

        $docNo = $this->docs->next('WD', 'withdrawals', 'doc_no');

        $this->db->beginTransaction();
        try {
            $ins = $this->db->prepare(
                "INSERT INTO withdrawals (doc_no, consignor_id, amount, method, status, requested_by, approved_by, requested_at, paid_at)
                 VALUES (?, ?, ?, ?, 'paid', ?, ?, NOW(), NOW())"
            );
            $ins->execute([$docNo, $consignorId, $amount, $method, $userId, $userId]);
            $id = (int) $this->db->lastInsertId();

            $methodLabel = match ($method) {
                'promptpay' => 'พร้อมเพย์',
                'bank_transfer' => 'โอนธนาคาร',
                default => 'เงินสดที่เคาน์เตอร์',
            };
            $description = $overdraw
                ? mb_substr("เบิกเงินเกินยอด ({$methodLabel}) คิดบัญชีติดลบ: {$overdrawReason}", 0, 255)
                : "เบิกเงิน ({$methodLabel})";
            $this->ledger->append(
                $consignorId,
                'withdrawal',
                $description,
                $docNo,
                0,
                $amount,
                $userId
            );

            $this->audit->log('withdrawals', $id, 'create', null, $this->find($id), $userId, $ip);

            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function requestPending(int $consignorId, float $amount, string $method, int $userId, ?string $ip): int
    {
        $docNo = $this->docs->next('WD', 'withdrawals', 'doc_no');
        $stmt = $this->db->prepare(
            "INSERT INTO withdrawals (doc_no, consignor_id, amount, method, status, requested_by, requested_at)
             VALUES (?, ?, ?, ?, 'pending', ?, NOW())"
        );
        $stmt->execute([$docNo, $consignorId, $amount, $method, $userId]);
        $id = (int) $this->db->lastInsertId();
        $this->audit->log('withdrawals', $id, 'create', null, $this->find($id), $userId, $ip);
        return $id;
    }

    /**
     * Admin: cancels a withdrawal with a reason. A paid one goes back into the consignor's
     * account as a new ledger entry (the original entry stays, so the passbook shows both).
     */
    public function cancel(int $id, string $reason, int $userId, ?string $ip): void
    {
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่ยกเลิก');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM withdrawals WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old || !in_array($old['status'], ['pending', 'paid'], true)) {
                throw new \RuntimeException('ไม่พบรายการเบิกเงินที่ยกเลิกได้');
            }
            try {
                $this->db->prepare("UPDATE withdrawals SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?")
                    ->execute([$userId, $reason, $id]);
            } catch (\PDOException $e) {
                throw new \RuntimeException('ยังยกเลิกรายการเบิกไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0049) ก่อน');
            }
            if ($old['status'] === 'paid') {
                $this->ledger->append(
                    (int) $old['consignor_id'],
                    'withdrawal_cancel',
                    mb_substr("ยกเลิกการเบิกเงิน: {$reason}", 0, 255),
                    $old['doc_no'],
                    (float) $old['amount'],
                    0,
                    $userId
                );
            }
            $this->audit->log('withdrawals', $id, 'cancel', $old, $this->find($id), $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function approve(int $id, int $approverId, ?string $ip): void
    {
        $old = $this->find($id);
        if (!$old || $old['status'] !== 'pending') {
            throw new \RuntimeException('ไม่พบคำขอเบิกเงินที่รออนุมัติ');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE withdrawals SET status='paid', approved_by=?, paid_at=NOW() WHERE id = ?"
            );
            $stmt->execute([$approverId, $id]);

            $methodLabel = match ($old['method']) {
                'promptpay' => 'พร้อมเพย์',
                'bank_transfer' => 'โอนธนาคาร',
                default => 'เงินสดที่เคาน์เตอร์',
            };
            $this->ledger->append(
                (int) $old['consignor_id'],
                'withdrawal',
                "เบิกเงิน ({$methodLabel}) - อนุมัติจ่าย",
                $old['doc_no'],
                0,
                (float) $old['amount'],
                $approverId
            );

            $this->audit->log('withdrawals', $id, 'update', $old, $this->find($id), $approverId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
