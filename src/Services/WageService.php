<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Wages paid to staff, recorded as a business expense (taken off the store's
 * profit). Cash may come out of the POS drawer of the current accounting day,
 * which then lowers that day's expected drawer cash.
 */
class WageService
{
    public const TYPES = ['day' => 'รายวัน', 'hour' => 'รายชั่วโมง', 'fixed' => 'เหมาจ่าย'];
    public const METHODS = ['cash' => 'เงินสด', 'transfer' => 'โอนเงิน'];

    public function __construct(private PDO $db, private AuditLogger $audit, private DocumentNumberService $docs)
    {
    }

    /** Staff who can be paid: active accounts that work in the store (not consignor-only logins). */
    public function staff(): array
    {
        return $this->db->query(
            "SELECT id, full_name, role FROM users WHERE active = 1 AND role IN ('admin','manager','advisor','cashier') ORDER BY full_name"
        )->fetchAll();
    }

    /**
     * @param array{user_id:int, pay_date:string, period_from?:?string, period_to?:?string, wage_type:string,
     *              units?:?float, rate?:?float, amount:float, method:string, from_drawer?:bool, note?:?string} $d
     * @param ?string $drawerDay the accounting day whose drawer pays cash now (null = cannot pay from the drawer)
     */
    public function pay(array $d, int $userId, ?string $drawerDay, ?string $ip): array
    {
        $staff = $this->db->prepare("SELECT id, full_name FROM users WHERE id = ? AND role IN ('admin','manager','advisor','cashier')");
        $staff->execute([(int) $d['user_id']]);
        $employee = $staff->fetch();
        if (!$employee) {
            throw new \RuntimeException('กรุณาเลือกพนักงาน');
        }
        $type = (string) ($d['wage_type'] ?? 'day');
        if (!isset(self::TYPES[$type])) {
            throw new \RuntimeException('ประเภทค่าแรงไม่ถูกต้อง');
        }
        $method = (string) ($d['method'] ?? 'cash');
        if (!isset(self::METHODS[$method])) {
            throw new \RuntimeException('วิธีจ่ายเงินไม่ถูกต้อง');
        }
        $payDate = self::date($d['pay_date'] ?? null);
        if (!$payDate || $payDate > date('Y-m-d')) {
            throw new \RuntimeException('วันที่จ่ายไม่ถูกต้อง (ต้องไม่เกินวันนี้)');
        }
        $from = self::date($d['period_from'] ?? null);
        $to = self::date($d['period_to'] ?? null);
        if ($from && $to && $from > $to) {
            throw new \RuntimeException('ช่วงวันที่ทำงานไม่ถูกต้อง');
        }
        $amount = round((float) ($d['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \RuntimeException('กรุณาระบุจำนวนเงินค่าแรง');
        }
        $units = isset($d['units']) && $d['units'] !== '' && $type !== 'fixed' ? round((float) $d['units'], 2) : null;
        $rate = isset($d['rate']) && $d['rate'] !== '' && $type !== 'fixed' ? round((float) $d['rate'], 2) : null;

        // Cash out of the drawer: only today's drawer once it is counted, before or after closing (which
        // counts the sales only). It lowers the cash to hand over in the daily account summary.
        $drawerDate = null;
        if (!empty($d['from_drawer'])) {
            if ($method !== 'cash') {
                throw new \RuntimeException('จ่ายจากลิ้นชักได้เฉพาะเงินสด');
            }
            if ($drawerDay === null || $payDate !== date('Y-m-d')) {
                throw new \RuntimeException('จ่ายจากเงินในลิ้นชักได้เฉพาะรายการที่จ่ายวันนี้');
            }
            $drawer = $this->db->prepare('SELECT closed_at FROM cash_drawers WHERE business_date = ?');
            $drawer->execute([$drawerDay]);
            $row = $drawer->fetch();
            if (!$row) {
                throw new \RuntimeException('วันนี้ยังไม่ได้ระบุเงินในลิ้นชัก จึงจ่ายจากลิ้นชักไม่ได้');
            }
            $drawerDate = $drawerDay;
        }

        $note = trim((string) ($d['note'] ?? ''));
        $this->db->beginTransaction();
        try {
            $docNo = $this->docs->next('WG', 'wage_payments', 'doc_no');
            $this->db->prepare(
                'INSERT INTO wage_payments (doc_no, user_id, pay_date, period_from, period_to, wage_type, units, rate, amount, method, drawer_date, note, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $docNo, $employee['id'], $payDate, $from, $to, $type, $units, $rate, $amount, $method, $drawerDate,
                $note !== '' ? mb_substr($note, 0, 500) : null, $userId, date('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->db->lastInsertId();
            if ($drawerDate !== null) {
                $this->db->prepare(
                    'INSERT INTO cash_drawer_events (business_date, user_id, subject_user_id, event, amount, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$drawerDate, $userId, $employee['id'], 'wage_out', $amount, mb_substr("{$docNo} จ่ายค่าแรง {$employee['full_name']}", 0, 500), date('Y-m-d H:i:s')]);
            }
            $this->audit->log('wage_payments', $id, 'create', null, [
                'doc_no' => $docNo, 'user_id' => (int) $employee['id'], 'amount' => $amount, 'method' => $method, 'drawer_date' => $drawerDate,
            ], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->find($id);
    }

    public function cancel(int $id, string $reason, int $userId, ?string $ip): void
    {
        $w = $this->find($id);
        if (!$w) {
            throw new \RuntimeException('ไม่พบรายการ');
        }
        if ($w['cancelled_at'] !== null) {
            throw new \RuntimeException('รายการนี้ถูกยกเลิกไปแล้ว');
        }
        $this->db->prepare('UPDATE wage_payments SET cancelled_at = ?, cancelled_by = ?, cancel_reason = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $userId, mb_substr($reason, 0, 500), $id]);
        if ($w['drawer_date'] !== null) {
            $this->db->prepare(
                'INSERT INTO cash_drawer_events (business_date, user_id, subject_user_id, event, amount, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$w['drawer_date'], $userId, $w['user_id'], 'wage_cancel', $w['amount'], mb_substr("ยกเลิก {$w['doc_no']}: {$reason}", 0, 500), date('Y-m-d H:i:s')]);        }
        $this->audit->log('wage_payments', $id, 'cancel', ['cancelled_at' => null], ['cancel_reason' => $reason], $userId, $ip);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare($this->select() . ' WHERE w.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Payments in a period (by pay date), newest first, with totals per employee. */
    public function list(string $from, string $to, ?int $employeeId = null): array
    {
        $sql = $this->select() . ' WHERE w.pay_date BETWEEN ? AND ?';
        $params = [$from, $to];
        if ($employeeId) {
            $sql .= ' AND w.user_id = ?';
            $params[] = $employeeId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY w.pay_date DESC, w.id DESC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $byEmployee = [];
        foreach ($rows as $r) {
            if ($r['cancelled_at'] !== null) {
                continue;
            }
            $k = (int) $r['user_id'];
            $byEmployee[$k] ??= ['user_id' => $k, 'full_name' => $r['employee_name'], 'payments' => 0, 'amount' => 0.0];
            $byEmployee[$k]['payments']++;
            $byEmployee[$k]['amount'] = round($byEmployee[$k]['amount'] + (float) $r['amount'], 2);
        }
        usort($byEmployee, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        return [
            'rows' => $rows,
            'by_employee' => array_values($byEmployee),
            'total' => round(array_sum(array_column($byEmployee, 'amount')), 2),
        ];
    }

    /** Total wages (not cancelled) paid in a period, or ever when no dates are given. */
    public function total(?string $from = null, ?string $to = null): float
    {
        try {
            if ($from && $to) {
                $stmt = $this->db->prepare('SELECT COALESCE(SUM(amount), 0) FROM wage_payments WHERE cancelled_at IS NULL AND pay_date BETWEEN ? AND ?');
                $stmt->execute([$from, $to]);
                return round((float) $stmt->fetchColumn(), 2);
            }
            return round((float) $this->db->query('SELECT COALESCE(SUM(amount), 0) FROM wage_payments WHERE cancelled_at IS NULL')->fetchColumn(), 2);
        } catch (\PDOException $e) {
            return 0.0; // migration 0034 not run yet
        }
    }

    /** Cash paid out of one day's drawer for wages (not cancelled). */
    public static function drawerOut(PDO $db, string $date): float
    {
        try {
            $stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM wage_payments WHERE drawer_date = ? AND cancelled_at IS NULL');
            $stmt->execute([$date]);
            return round((float) $stmt->fetchColumn(), 2);
        } catch (\PDOException $e) {
            return 0.0; // migration 0034 not run yet
        }
    }

    private function select(): string
    {
        return 'SELECT w.*, u.full_name AS employee_name, u.role AS employee_role, cb.full_name AS created_by_name, xb.full_name AS cancelled_by_name
                FROM wage_payments w
                LEFT JOIN users u ON u.id = w.user_id
                LEFT JOIN users cb ON cb.id = w.created_by
                LEFT JOIN users xb ON xb.id = w.cancelled_by';
    }

    private static function date(mixed $v): ?string
    {
        $v = trim((string) $v);
        $d = $v !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }
}
