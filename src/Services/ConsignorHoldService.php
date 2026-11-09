<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * บัญชีเพื่อผู้ฝากขาย: money taken from the day's drawer (a withdrawal with method 'hold') waits in an
 * account the store manager keeps until the consignor asks for it. Holding takes the money off the
 * consignor's passbook balance, so paying it out later changes that balance no further. What the
 * account should hold is every active hold less every active payout, to check against the bank.
 */
class ConsignorHoldService
{
    public const METHODS = ['cash' => 'เงินสด', 'promptpay' => 'พร้อมเพย์', 'bank_transfer' => 'โอนธนาคาร'];

    public function __construct(private PDO $db, private AuditLogger $audit, private DocumentNumberService $docs)
    {
    }

    /** False until migration 0069 has run. */
    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM consignor_hold_checks LIMIT 0');
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /** What is held for one consignor now (0 before the migration). */
    public function heldOf(int $consignorId): float
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT (SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE consignor_id = ? AND method = 'hold' AND status = 'paid')
                      - (SELECT COALESCE(SUM(amount), 0) FROM consignor_hold_payouts WHERE consignor_id = ? AND cancelled_at IS NULL)"
            );
            $stmt->execute([$consignorId, $consignorId]);
            return round((float) $stmt->fetchColumn(), 2);
        } catch (\PDOException $e) {
            return 0.0;
        }
    }

    /** What the account should hold at the end of a day. */
    public function expectedAt(string $date): float
    {
        $stmt = $this->db->prepare(
            "SELECT (SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE method = 'hold' AND status = 'paid' AND DATE(paid_at) <= ?)
                  - (SELECT COALESCE(SUM(amount), 0) FROM consignor_hold_payouts WHERE cancelled_at IS NULL AND DATE(paid_at) <= ?)"
        );
        $stmt->execute([$date, $date]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** Consignors with money held now, largest first, with how they like to be paid. */
    public function consignors(): array
    {
        $rows = $this->db->query(
            "SELECT c.id, c.code, c.name, c.pay_channel, c.pay_account, c.phone,
                    (SELECT COALESCE(SUM(w.amount), 0) FROM withdrawals w WHERE w.consignor_id = c.id AND w.method = 'hold' AND w.status = 'paid')
                  - (SELECT COALESCE(SUM(p.amount), 0) FROM consignor_hold_payouts p WHERE p.consignor_id = c.id AND p.cancelled_at IS NULL) AS held
             FROM consignors c
             HAVING ABS(held) >= 0.005
             ORDER BY held DESC, c.code"
        )->fetchAll();
        return array_map(fn ($r) => ['held' => round((float) $r['held'], 2)] + $r, $rows);
    }

    /**
     * The account's movements, newest first. Cancelled ones stay listed but no longer count;
     * balance_after is the account after each one.
     */
    public function movements(int $limit = 200): array
    {
        $rows = $this->db->query(
            "SELECT 'in' AS direction, w.id, w.doc_no, w.amount, w.method, w.note, w.paid_at AS at,
                    IF(w.status = 'cancelled', w.cancelled_at, NULL) AS cancelled_at, w.cancel_reason,
                    c.code AS consignor_code, c.name AS consignor_name, u.full_name AS user_name
             FROM withdrawals w JOIN consignors c ON c.id = w.consignor_id LEFT JOIN users u ON u.id = w.requested_by
             WHERE w.method = 'hold' AND w.paid_at IS NOT NULL
             UNION ALL
             SELECT 'out', p.id, p.doc_no, p.amount, p.method, p.note, p.paid_at, p.cancelled_at, p.cancel_reason,
                    c.code, c.name, u.full_name
             FROM consignor_hold_payouts p JOIN consignors c ON c.id = p.consignor_id LEFT JOIN users u ON u.id = p.user_id
             ORDER BY at, direction, id"
        )->fetchAll();
        $balance = 0.0;
        foreach ($rows as &$r) {
            $r['amount'] = (float) $r['amount'];
            if ($r['cancelled_at'] === null) {
                $balance = round($balance + ($r['direction'] === 'in' ? $r['amount'] : -$r['amount']), 2);
            }
            $r['balance_after'] = $balance;
        }
        unset($r);
        return array_slice(array_reverse($rows), 0, $limit);
    }

    /** Checks against the bank, newest first. */
    public function checks(int $limit = 30): array
    {
        $stmt = $this->db->prepare(
            'SELECT k.*, u.full_name AS user_name FROM consignor_hold_checks k LEFT JOIN users u ON u.id = k.user_id
             ORDER BY k.check_date DESC, k.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute();
        return array_map(fn ($r) => [
            'expected_amount' => (float) $r['expected_amount'], 'actual_amount' => (float) $r['actual_amount'],
            'diff' => round((float) $r['actual_amount'] - (float) $r['expected_amount'], 2),
        ] + $r, $stmt->fetchAll());
    }

    /** Pays held money to the consignor who asked for it. Comes from the account, never today's drawer. */
    public function pay(int $consignorId, float $amount, string $method, string $note, int $userId, ?string $ip): int
    {
        $amount = round($amount, 2);
        $note = mb_substr(trim($note), 0, 255);
        if (!isset(self::METHODS[$method])) {
            throw new \RuntimeException('ช่องทางจ่ายเงินไม่ถูกต้อง');
        }
        if ($amount <= 0) {
            throw new \RuntimeException('จำนวนเงินที่จ่ายต้องมากกว่า 0');
        }
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM consignors WHERE id = ? FOR UPDATE');
            $lock->execute([$consignorId]);
            if (!$lock->fetchColumn()) {
                throw new \RuntimeException('ไม่พบผู้ฝากขาย');
            }
            $held = $this->heldOf($consignorId);
            if ($amount > $held + 0.005) {
                throw new \RuntimeException('จำนวนเงินเกินยอดที่พักไว้ (฿' . number_format($held, 2) . ')');
            }
            $docNo = $this->docs->next('HP', 'consignor_hold_payouts');
            $this->db->prepare(
                'INSERT INTO consignor_hold_payouts (doc_no, consignor_id, amount, method, note, user_id, paid_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
            )->execute([$docNo, $consignorId, $amount, $method, $note !== '' ? $note : null, $userId]);
            $id = (int) $this->db->lastInsertId();
            $this->passbookNote(
                $consignorId,
                'รับเงินที่พักไว้ ฿' . number_format($amount, 2) . ' (' . self::METHODS[$method] . ')' . ($note !== '' ? " · {$note}" : ''),
                $docNo,
                $userId
            );
            $this->audit->log('consignor_hold_payouts', $id, 'create', null, [
                'doc_no' => $docNo, 'consignor_id' => $consignorId, 'amount' => $amount, 'method' => $method, 'note' => $note,
            ], $userId, $ip);
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Takes back a payout made by mistake: the money counts as held again. */
    public function cancelPayout(int $id, string $reason, int $userId, ?string $ip): void
    {
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่ยกเลิก');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM consignor_hold_payouts WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old || $old['cancelled_at'] !== null) {
                throw new \RuntimeException('ไม่พบรายการจ่ายเงินที่ยกเลิกได้');
            }
            $this->db->prepare('UPDATE consignor_hold_payouts SET cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?')
                ->execute([$userId, $reason, $id]);
            $this->passbookNote(
                (int) $old['consignor_id'],
                mb_substr('ยกเลิกการรับเงินที่พักไว้ ฿' . number_format((float) $old['amount'], 2) . ": {$reason}", 0, 255),
                $old['doc_no'],
                $userId
            );
            $this->audit->log('consignor_hold_payouts', $id, 'cancel', $old, ['cancel_reason' => $reason], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Records a check against the bank; a difference needs a note saying why. */
    public function recordCheck(string $date, float $actual, string $note, int $userId, ?string $ip): int
    {
        $actual = round($actual, 2);
        $note = mb_substr(trim($note), 0, 255);
        if ($actual < 0) {
            throw new \RuntimeException('ยอดในบัญชีต้องไม่ติดลบ');
        }
        $expected = $this->expectedAt($date);
        if (abs($actual - $expected) >= 0.005 && $note === '') {
            throw new \RuntimeException('ยอดในบัญชีไม่ตรงกับยอดที่ควรมี (฿' . number_format($expected, 2) . ') กรุณาระบุหมายเหตุ');
        }
        $this->db->prepare(
            'INSERT INTO consignor_hold_checks (check_date, expected_amount, actual_amount, note, user_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$date, $expected, $actual, $note !== '' ? $note : null, $userId]);
        $id = (int) $this->db->lastInsertId();
        $this->audit->log('consignor_hold_checks', $id, 'create', null, [
            'check_date' => $date, 'expected_amount' => $expected, 'actual_amount' => $actual, 'note' => $note,
        ], $userId, $ip);
        return $id;
    }

    /** A line in the passbook with no amount: holding already took this money off the balance. */
    private function passbookNote(int $consignorId, string $description, string $docNo, int $userId): void
    {
        (new LedgerService($this->db))->append($consignorId, 'hold_release', $description, $docNo, 0, 0, $userId);
    }
}
