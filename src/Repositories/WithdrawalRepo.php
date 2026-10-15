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
            'SELECT w.*, c.name AS consignor_name FROM withdrawals w
             JOIN consignors c ON c.id = w.consignor_id
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
     */
    public function payNow(int $consignorId, float $amount, string $method, int $userId, ?string $ip): int
    {
        $balance = $this->ledger->balanceOf($consignorId);
        if ($amount <= 0 || $amount > $balance) {
            throw new \RuntimeException('จำนวนเงินที่เบิกไม่ถูกต้องหรือเกินยอดคงเหลือ');
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
            $this->ledger->append(
                $consignorId,
                'withdrawal',
                "เบิกเงิน ({$methodLabel})",
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
