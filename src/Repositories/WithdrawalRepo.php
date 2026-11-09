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

    /**
     * Latest first, each with its corrections. With $from/$to (Y-m-d, both inclusive): those paid on
     * these days (requested, until paid), plus every request still waiting so none drops out of sight.
     */
    public function recent(int $limit = 20, ?string $from = null, ?string $to = null): array
    {
        $where = '';
        $params = [];
        if ($from !== null && $to !== null) {
            $where = "WHERE w.status = 'pending' OR (COALESCE(w.paid_at, w.requested_at) >= ? AND COALESCE(w.paid_at, w.requested_at) < ?)";
            $params = [$from . ' 00:00:00', (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
        }
        $stmt = $this->db->prepare(
            "SELECT w.*, c.name AS consignor_name, cu.full_name AS cancelled_by_name FROM withdrawals w
             JOIN consignors c ON c.id = w.consignor_id
             LEFT JOIN users cu ON cu.id = w.cancelled_by
             {$where}
             ORDER BY w.id DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute($params);
        return $this->withEdits($stmt->fetchAll());
    }

    /** Adds each withdrawal's corrections, oldest first, from the audit trail that already keeps them. */
    private function withEdits(array $rows): array
    {
        $edits = [];
        if ($rows) {
            $in = implode(',', array_fill(0, count($rows), '?'));
            $stmt = $this->db->prepare(
                "SELECT al.record_id, al.old_data, al.new_data, al.created_at, u.full_name AS user_name
                 FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id
                 WHERE al.table_name = 'withdrawals' AND al.action = 'update' AND al.new_data LIKE '%\"edit_reason\"%' AND al.record_id IN ($in)
                 ORDER BY al.id"
            );
            $stmt->execute(array_column($rows, 'id'));
            foreach ($stmt->fetchAll() as $e) {
                $old = json_decode((string) $e['old_data'], true) ?: [];
                $new = json_decode((string) $e['new_data'], true) ?: [];
                $edits[(int) $e['record_id']][] = [
                    'old_amount' => (float) ($old['amount'] ?? 0), 'new_amount' => (float) ($new['amount'] ?? 0),
                    'old_method' => $old['method'] ?? null, 'new_method' => $new['method'] ?? null,
                    'reason' => $new['edit_reason'] ?? '', 'user_name' => $e['user_name'], 'at' => $e['created_at'],
                ];
            }
        }
        foreach ($rows as &$r) {
            $r['edits'] = $edits[(int) $r['id']] ?? [];
        }
        unset($r);
        return $rows;
    }

    private static function methodLabel(string $method): string
    {
        return match ($method) {
            'promptpay' => 'พร้อมเพย์',
            'bank_transfer' => 'โอนธนาคาร',
            'hold' => 'พักในบัญชีเพื่อผู้ฝากขาย',
            default => 'เงินสด',
        };
    }

    /**
     * Admin correction of the amount or method, with a reason. The passbook keeps the original line
     * and gets one more for the difference, so the balance and reports follow. Moving money out of
     * the account for consignors is refused once that money has already been handed over.
     */
    public function edit(int $id, float $amount, string $method, string $reason, int $userId, ?string $ip): void
    {
        $amount = round($amount, 2);
        $reason = mb_substr(trim($reason), 0, 150);
        if ($reason === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่แก้ไข');
        }
        if ($amount <= 0) {
            throw new \RuntimeException('จำนวนเงินต้องมากกว่า 0');
        }
        if (!in_array($method, ['cash', 'promptpay', 'bank_transfer', 'hold'], true)) {
            throw new \RuntimeException('ช่องทางจ่ายเงินไม่ถูกต้อง');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM withdrawals WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old || !in_array($old['status'], ['pending', 'paid'], true)) {
                throw new \RuntimeException('ไม่พบรายการเบิกเงินที่แก้ไขได้');
            }
            $oldAmount = round((float) $old['amount'], 2);
            if (abs($amount - $oldAmount) < 0.005 && $method === $old['method']) {
                throw new \RuntimeException('ยังไม่ได้เปลี่ยนจำนวนเงินหรือช่องทาง');
            }
            if ($method === 'hold' || $old['method'] === 'hold') {
                $this->guardHeld($old, $amount, $method);
            }
            $this->db->prepare('UPDATE withdrawals SET amount = ?, method = ? WHERE id = ?')->execute([$amount, $method, $id]);
            if ($old['status'] === 'paid') {
                $diff = round($amount - $oldAmount, 2);
                $this->ledger->append(
                    (int) $old['consignor_id'],
                    $diff < 0 ? 'withdrawal_cancel' : 'withdrawal',
                    mb_substr(
                        'แก้ไขการจ่ายเงินจาก ฿' . number_format($oldAmount, 2) . ' (' . self::methodLabel($old['method']) . ')'
                        . ' เป็น ฿' . number_format($amount, 2) . ' (' . self::methodLabel($method) . "): {$reason}",
                        0,
                        255
                    ),
                    $old['doc_no'],
                    $diff < 0 ? -$diff : 0,
                    $diff > 0 ? $diff : 0,
                    $userId
                );
            }
            $this->audit->log('withdrawals', $id, 'update',
                ['amount' => $oldAmount, 'method' => $old['method']],
                ['amount' => $amount, 'method' => $method, 'edit_reason' => $reason],
                $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** What a correction does to the money held for the consignor: it can't leave less than was handed over. */
    private function guardHeld(array $old, float $amount, string $method): void
    {
        $holds = new \App\Services\ConsignorHoldService($this->db, $this->audit, $this->docs);
        if (!$holds->ready()) {
            throw new \RuntimeException('ยังพักเงินไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0069) ก่อน');
        }
        if ($old['status'] !== 'paid') {
            throw new \RuntimeException('คำขอเบิกที่รออนุมัติเลือกการพักเงินไม่ได้ ให้พักเงินจากสรุปบัญชีรายวัน');
        }
        $lock = $this->db->prepare('SELECT id FROM consignors WHERE id = ? FOR UPDATE');
        $lock->execute([$old['consignor_id']]);
        $held = $holds->heldOf((int) $old['consignor_id']);
        $after = $held - ($old['method'] === 'hold' ? (float) $old['amount'] : 0) + ($method === 'hold' ? $amount : 0);
        if ($after < -0.005) {
            throw new \RuntimeException('เงินที่พักไว้ถูกจ่ายให้ผู้ฝากขายไปแล้ว เหลือ ฿' . number_format($held, 2) . ' ให้ยกเลิกการจ่ายเงินในหน้าบัญชีเพื่อผู้ฝากขายก่อน');
        }
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
        $this->db->beginTransaction();
        try {
            $id = $this->insertPaid($consignorId, $amount, $method, $userId, $ip, $overdrawReason);
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * payNow inside the caller's transaction; $note goes after the passbook line (e.g. the
     * sales period it pays for).
     */
    public function insertPaid(int $consignorId, float $amount, string $method, int $userId, ?string $ip, ?string $overdrawReason = null, ?string $note = null): int
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
        $action = $method === 'hold' ? 'พักเงินในบัญชีเพื่อผู้ฝากขาย' : "เบิกเงิน ({$methodLabel})";
        $description = $overdraw
            ? mb_substr("{$action} เกินยอด คิดบัญชีติดลบ: {$overdrawReason}", 0, 255)
            : $action;
        if ($note !== null && $note !== '') {
            $description = mb_substr("{$description} · {$note}", 0, 255);
        }
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

        return $id;
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
            if ($old['method'] === 'hold' && $old['status'] === 'paid') {
                // Money already handed to the consignor can't go back into the drawer's day.
                $lock = $this->db->prepare('SELECT id FROM consignors WHERE id = ? FOR UPDATE');
                $lock->execute([$old['consignor_id']]);
                $held = (new \App\Services\ConsignorHoldService($this->db, $this->audit, $this->docs))->heldOf((int) $old['consignor_id']);
                if ((float) $old['amount'] > $held + 0.005) {
                    throw new \RuntimeException('เงินที่พักไว้ถูกจ่ายให้ผู้ฝากขายไปแล้ว เหลือ ฿' . number_format($held, 2) . ' ให้ยกเลิกการจ่ายเงินในหน้าบัญชีเพื่อผู้ฝากขายก่อน');
                }
            }
            try {
                $this->db->prepare("UPDATE withdrawals SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?")
                    ->execute([$userId, $reason, $id]);
            } catch (\PDOException $e) {
                throw new \RuntimeException('ยังยกเลิกรายการเบิกไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0049) ก่อน');
            }
            if (\App\Support\Db::hasColumn($this->db, 'sale_items', 'withdrawal_id')) {
                // The sales it paid for can be paid out again.
                $this->db->prepare('UPDATE sale_items SET withdrawal_id = NULL WHERE withdrawal_id = ?')->execute([$id]);
            }
            if ($old['status'] === 'paid') {
                $this->ledger->append(
                    (int) $old['consignor_id'],
                    'withdrawal_cancel',
                    mb_substr(($old['method'] === 'hold' ? 'ยกเลิกการพักเงิน' : 'ยกเลิกการเบิกเงิน') . ": {$reason}", 0, 255),
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
