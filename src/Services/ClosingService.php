<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * สรุปบัญชีรายวัน: each accounting day is checked by whoever is on duty for its weekday
 * (the roster), who records the cash handed over to the college and its receipt.
 * Admin / manager see every day, set the roster and confirm a summary.
 */
class ClosingService
{
    public const WEEKDAYS = [1 => 'จันทร์', 2 => 'อังคาร', 3 => 'พุธ', 4 => 'พฤหัสบดี', 5 => 'ศุกร์', 6 => 'เสาร์', 7 => 'อาทิตย์'];
    /** Roles that can be put on the roster. */
    public const DUTY_ROLES = ['closer', 'manager', 'admin'];

    public function __construct(private PDO $db, private AuditLogger $audit)
    {
    }

    /** @return array<int, array<int, array{id:int, full_name:string}>> weekday => people on duty */
    public function roster(): array
    {
        $out = array_fill_keys(array_keys(self::WEEKDAYS), []);
        $rows = $this->db->query(
            'SELECT d.weekday, u.id, u.full_name FROM closing_duties d JOIN users u ON u.id = d.user_id
             WHERE u.active = 1 ORDER BY d.weekday, u.full_name'
        )->fetchAll();
        foreach ($rows as $r) {
            $out[(int) $r['weekday']][] = ['id' => (int) $r['id'], 'full_name' => $r['full_name']];
        }
        return $out;
    }

    /** People who may be put on the roster. */
    public function candidates(): array
    {
        $in = implode(',', array_fill(0, count(self::DUTY_ROLES), '?'));
        $stmt = $this->db->prepare("SELECT id, full_name, role FROM users WHERE active = 1 AND role IN ($in) ORDER BY role = 'closer' DESC, full_name");
        $stmt->execute(self::DUTY_ROLES);
        return $stmt->fetchAll();
    }

    /** @param array<int|string, array<int>> $roster weekday => user ids */
    public function setRoster(array $roster, int $userId, ?string $ip): array
    {
        $allowed = array_map(fn ($c) => (int) $c['id'], $this->candidates());
        $old = $this->roster();
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM closing_duties');
            $ins = $this->db->prepare('INSERT IGNORE INTO closing_duties (weekday, user_id) VALUES (?, ?)');
            foreach ($roster as $weekday => $ids) {
                $weekday = (int) $weekday;
                if (!isset(self::WEEKDAYS[$weekday]) || !is_array($ids)) {
                    continue;
                }
                foreach ($ids as $id) {
                    if (in_array((int) $id, $allowed, true)) {
                        $ins->execute([$weekday, (int) $id]);
                    }
                }
            }
            $new = $this->roster();
            $this->audit->log('closing_duties', 0, 'update', $old, $new, $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $new;
    }

    public function weekdayOf(string $date): int
    {
        return (int) (new \DateTimeImmutable($date))->format('N');
    }

    public function isOnDuty(int $userId, string $date): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM closing_duties WHERE weekday = ? AND user_id = ?');
        $stmt->execute([$this->weekdayOf($date), $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /** The user's own duty weekdays. */
    public function dutiesOf(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT weekday FROM closing_duties WHERE user_id = ? ORDER BY weekday');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Goods bought for the store dated that day. Each is paid from the day's drawer unless
     * marked as paid with other money (from_drawer = 0, e.g. profit taken out earlier).
     */
    public function purchasesOf(string $date): array
    {
        $sql = "SELECT pd.id, pd.doc_no, pd.supplier_name, pd.receipt_no, pd.total, pd.funding, %s AS from_drawer, %s AS flagged_by
                FROM purchase_documents pd %s
                WHERE pd.status = 'active' AND pd.funding <> 'stock' AND COALESCE(pd.receipt_date, DATE(pd.created_at)) = ? ORDER BY pd.id";
        try {
            $stmt = $this->db->prepare(sprintf($sql, 'COALESCE(f.from_drawer, 1)', 'u.full_name',
                'LEFT JOIN closing_purchase_flags f ON f.purchase_id = pd.id LEFT JOIN users u ON u.id = f.user_id'));
            $stmt->execute([$date]);
        } catch (\PDOException $e) {
            try {
                $stmt = $this->db->prepare(sprintf($sql, '1', 'NULL', '')); // migration 0053 not run yet
                $stmt->execute([$date]);
            } catch (\PDOException $e) {
                return []; // purchases not set up
            }
        }
        return array_map(fn ($r) => ['from_drawer' => (bool) $r['from_drawer']] + $r, $stmt->fetchAll());
    }

    /** Marks whether a purchase was paid from its day's drawer. */
    public function setPurchaseDrawer(string $date, int $purchaseId, bool $fromDrawer, int $userId, ?string $ip): void
    {
        if (!in_array($purchaseId, array_map(fn ($p) => (int) $p['id'], $this->purchasesOf($date)), true)) {
            throw new \RuntimeException('ไม่พบรายการซื้อสินค้าของวันนี้');
        }
        try {
            $this->db->prepare(
                'INSERT INTO closing_purchase_flags (purchase_id, from_drawer, user_id, updated_at) VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE from_drawer = VALUES(from_drawer), user_id = VALUES(user_id), updated_at = NOW()'
            )->execute([$purchaseId, $fromDrawer ? 1 : 0, $userId]);
        } catch (\PDOException $e) {
            throw new \RuntimeException('ยังระบุไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0053) ก่อน');
        }
        $this->audit->log('purchase_documents', $purchaseId, 'update', null, ['closing_date' => $date, 'from_drawer' => $fromDrawer], $userId, $ip);
    }

    /**
     * Consignors paid that day. One paid in cash is paid from the day's drawer unless
     * marked as paid with other money; transfers never touch the drawer.
     */
    public function payoutsOf(string $date): array
    {
        $sql = "SELECT w.id, w.doc_no, w.amount, w.method, w.paid_at, c.code AS consignor_code, c.name AS consignor_name,
                       IF(w.method = 'cash', %s, 0) AS from_drawer, %s AS flagged_by
                FROM withdrawals w JOIN consignors c ON c.id = w.consignor_id %s
                WHERE w.status = 'paid' AND DATE(w.paid_at) = ? ORDER BY w.id";
        try {
            $stmt = $this->db->prepare(sprintf($sql, 'COALESCE(f.from_drawer, 1)', 'u.full_name',
                'LEFT JOIN closing_payout_flags f ON f.withdrawal_id = w.id LEFT JOIN users u ON u.id = f.user_id'));
            $stmt->execute([$date]);
        } catch (\PDOException $e) {
            try {
                $stmt = $this->db->prepare(sprintf($sql, '1', 'NULL', '')); // migration 0054 not run yet
                $stmt->execute([$date]);
            } catch (\PDOException $e) {
                return [];
            }
        }
        return array_map(fn ($r) => ['from_drawer' => (bool) $r['from_drawer']] + $r, $stmt->fetchAll());
    }

    /** Marks whether a consignor paid in cash was paid from its day's drawer. */
    public function setPayoutDrawer(string $date, int $withdrawalId, bool $fromDrawer, int $userId, ?string $ip): void
    {
        $cash = array_filter($this->payoutsOf($date), fn ($p) => $p['method'] === 'cash');
        if (!in_array($withdrawalId, array_map(fn ($p) => (int) $p['id'], $cash), true)) {
            throw new \RuntimeException('ไม่พบรายการจ่ายเงินสดให้ผู้ฝากขายของวันนี้');
        }
        try {
            $this->db->prepare(
                'INSERT INTO closing_payout_flags (withdrawal_id, from_drawer, user_id, updated_at) VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE from_drawer = VALUES(from_drawer), user_id = VALUES(user_id), updated_at = NOW()'
            )->execute([$withdrawalId, $fromDrawer ? 1 : 0, $userId]);
        } catch (\PDOException $e) {
            throw new \RuntimeException('ยังระบุไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0054) ก่อน');
        }
        $this->audit->log('withdrawals', $withdrawalId, 'update', null, ['closing_date' => $date, 'from_drawer' => $fromDrawer], $userId, $ip);
    }

    /** The change left in the drawer for the next day, as saved with the day's summary (null = not set). */
    private function savedFloat(string $date): ?float
    {
        if (!\App\Support\Db::hasColumn($this->db, 'daily_closings', 'float_kept')) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT float_kept FROM daily_closings WHERE business_date = ?');
        $stmt->execute([$date]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (float) $v;
    }

    /**
     * The day's figures as the system has them now: sales per payment method, the drawer at
     * closing (float + cash sales, against the count), cash paid out of it afterwards (wages,
     * consignors, goods bought), the change kept for the next day ($floatKept; by default as
     * saved, else the day's opening float) and the cash to hand over (the rest).
     */
    public function figures(string $date, ?float $floatKept = null): array
    {
        $s = (new CashDrawerService($this->db, $this->audit))->summary($date, false);
        $by = fn (array $methods) => round(array_sum(array_column($methods, 'amount')), 2);
        $cash = round((float) $s['cash_sales'], 2);
        $transfer = $by(array_filter($s['methods'], fn ($m) => in_array($m['method'], ['transfer', 'qr'], true)));
        $card = $by(array_filter($s['methods'], fn ($m) => $m['method'] === 'card'));
        $cashOut = round((float) $s['cash_out'], 2);
        $purchaseOut = round(array_sum(array_map(fn ($p) => $p['from_drawer'] ? (float) $p['total'] : 0, $this->purchasesOf($date))), 2);
        $payoutOut = round(array_sum(array_map(fn ($p) => $p['from_drawer'] ? (float) $p['amount'] : 0, $this->payoutsOf($date))), 2);
        $drawer = $s['drawer'];
        $counted = $drawer && $drawer['counted_cash'] !== null ? (float) $drawer['counted_cash'] : null;
        // Goods bought with drawer money left it during the day, so the count at closing is already short of them;
        // wages and consignors paid from the drawer come out of that count afterwards.
        $atClose = $drawer ? round((float) $drawer['opening_cash'] + $cash - $purchaseOut, 2) : null;
        $afterPay = $drawer ? round(($counted ?? $atClose) - $cashOut - $payoutOut, 2) : null;
        $out = round($cashOut + $payoutOut + $purchaseOut, 2);
        $float = $drawer ? round($floatKept ?? $this->savedFloat($date) ?? (float) $drawer['opening_cash'], 2) : 0.0;
        $expected = $drawer
            ? round($afterPay - $float, 2) // what is left in the drawer, less the change kept for the next day
            : round($cash - $out, 2);
        return [
            'date' => $date,
            'total_sales' => round((float) $s['total']['amount'], 2),
            'sales_count' => (int) $s['total']['sales'],
            'cash_sales' => $cash,
            'transfer_sales' => $transfer,
            'card_sales' => $card,
            'unknown_sales' => round((float) $s['unknown']['amount'], 2),
            'cash_out' => $cashOut,
            'payout_out' => $payoutOut,
            'purchase_out' => $purchaseOut,
            'float_kept' => $float,
            'methods' => $s['methods'],
            'drawer' => $drawer ? [
                'opening_cash' => (float) $drawer['opening_cash'],
                // Cash that should be in the drawer at closing: the float plus cash sales, less goods bought with it.
                'should_have' => $atClose,
                // Left in the drawer after wages and consignors were paid from it (from the count once closed).
                'after_pay' => $afterPay,
                'counted_cash' => $counted,
                'expected_cash' => $drawer['expected_cash'] !== null ? (float) $drawer['expected_cash'] : null,
                'cash_diff' => $drawer['cash_diff'] !== null ? (float) $drawer['cash_diff'] : null,
                'closed_at' => $drawer['closed_at'],
                'closed_by_name' => $drawer['closed_by_name'] ?? null,
            ] : null,
            'expected_send' => max(0.0, $expected),
        ];
    }

    public function find(string $date): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT dc.*, u.full_name AS closer_name, v.full_name AS verified_by_name FROM daily_closings dc
             LEFT JOIN users u ON u.id = dc.closer_id LEFT JOIN users v ON v.id = dc.verified_by
             WHERE dc.business_date = ?'
        );
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['snapshot'] = $row['snapshot'] ? json_decode($row['snapshot'], true) : null;
        $row['actual_accounts'] = !empty($row['actual_accounts']) ? json_decode($row['actual_accounts'], true) : null;
        return $row;
    }

    /** One line per day from $from to $to (newest first): figures, who is on duty and the summary if made. */
    public function days(string $from, string $to): array
    {
        $roster = $this->roster();
        $stmt = $this->db->prepare(
            'SELECT dc.*, u.full_name AS closer_name, v.full_name AS verified_by_name FROM daily_closings dc
             LEFT JOIN users u ON u.id = dc.closer_id LEFT JOIN users v ON v.id = dc.verified_by
             WHERE dc.business_date BETWEEN ? AND ?'
        );
        $stmt->execute([$from, $to]);
        $closings = [];
        foreach ($stmt->fetchAll() as $r) {
            unset($r['snapshot']);
            $closings[$r['business_date']] = $r;
        }
        $out = [];
        for ($d = new \DateTimeImmutable($to); $d->format('Y-m-d') >= $from; $d = $d->modify('-1 day')) {
            $date = $d->format('Y-m-d');
            $f = $this->figures($date);
            unset($f['methods']);
            $weekday = (int) $d->format('N');
            $out[] = $f + [
                'weekday' => $weekday,
                'on_duty' => $roster[$weekday],
                'closing' => $closings[$date] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Records (or corrects) the day's summary. The person on duty for that weekday may do it,
     * as may an admin / manager ($manage); once confirmed only they can change it.
     */
    public function submit(string $date, array $data, int $userId, bool $manage, string $today, ?string $ip): array
    {
        if ($date > $today) {
            throw new \RuntimeException('สรุปบัญชีล่วงหน้าไม่ได้');
        }
        if (!$manage && !$this->isOnDuty($userId, $date)) {
            throw new \RuntimeException('วัน' . self::WEEKDAYS[$this->weekdayOf($date)] . 'ไม่ใช่เวรสรุปบัญชีของคุณ');
        }
        $old = $this->find($date);
        if ($old && $old['verified_at'] !== null && !$manage) {
            throw new \RuntimeException('สรุปบัญชีวันนี้ได้รับการยืนยันแล้ว แก้ไขได้เฉพาะผู้ดูแลระบบหรือผู้จัดการร้าน');
        }
        $sent = round((float) ($data['cash_sent'] ?? -1), 2);
        if ($sent < 0) {
            throw new \RuntimeException('กรุณากรอกจำนวนเงินสดที่ส่งวิทยาลัย');
        }
        $text = fn (string $k, int $max) => ($v = mb_substr(trim((string) ($data[$k] ?? '')), 0, $max)) === '' ? null : $v;
        $sentTo = $text('sent_to', 150);
        $receipt = $text('receipt_no', 60);
        $note = $text('note', 500);
        $float = null;
        if (isset($data['float_kept']) && $data['float_kept'] !== '') {
            $float = round((float) $data['float_kept'], 2);
            if (!is_numeric($data['float_kept']) || $float < 0) {
                throw new \RuntimeException('เงินที่คงไว้เป็นเงินทอนวันถัดไปต้องไม่ติดลบ');
            }
        }
        $f = $this->figures($date, $float);
        if ($f['drawer'] && $f['float_kept'] > $f['drawer']['after_pay'] + 0.005) {
            throw new \RuntimeException('เงินที่คงไว้เป็นเงินทอนมากกว่าเงินสดที่เหลือในลิ้นชัก (฿' . number_format($f['drawer']['after_pay'], 2) . ')');
        }
        if (abs($sent - $f['expected_send']) >= 0.005 && $note === null) {
            throw new \RuntimeException('เงินที่ส่งไม่ตรงกับยอดที่ควรส่ง (฿' . number_format($f['expected_send'], 2) . ') กรุณาระบุหมายเหตุ');
        }
        // What actually reached each receiving account, for every non-cash method in use.
        $actual = null;
        if (\App\Support\Db::hasColumn($this->db, 'daily_closings', 'actual_accounts')) {
            $given = is_array($data['actual_accounts'] ?? null) ? $data['actual_accounts'] : [];
            $actual = [];
            foreach ($this->accounts($f) as $a) {
                $v = $given[$a['method']] ?? null;
                if ($v === null || $v === '' || !is_numeric($v) || (float) $v < 0) {
                    throw new \RuntimeException("กรุณากรอกยอดที่ได้รับจริง: {$a['label']}");
                }
                $actual[$a['method']] = round((float) $v, 2);
                if (abs($actual[$a['method']] - $a['expected']) >= 0.005 && $note === null) {
                    throw new \RuntimeException("{$a['label']} ได้รับจริงไม่ตรงกับยอดขาย (฿" . number_format($a['expected'], 2) . ') กรุณาระบุหมายเหตุ');
                }
            }
        }
        $values = [
            $userId, $f['total_sales'], $f['cash_sales'], $f['transfer_sales'], $f['card_sales'], $f['unknown_sales'],
            $f['cash_out'], $f['expected_send'], $sent, $sentTo, $receipt, $note, json_encode($f, JSON_UNESCAPED_UNICODE),
        ];
        $this->saveRow($old, $date, $values, $actual);
        if ($f['drawer'] && \App\Support\Db::hasColumn($this->db, 'daily_closings', 'float_kept')) {
            $this->db->prepare('UPDATE daily_closings SET float_kept = ? WHERE business_date = ?')->execute([$f['float_kept'], $date]);
        }
        $strip = fn (?array $r) => $r ? array_diff_key($r, array_flip(['snapshot', 'closer_name', 'verified_by_name'])) : null;
        $new = $this->find($date);
        $this->audit->log('daily_closings', (int) $new['id'], $old ? 'update' : 'create', $strip($old), $strip($new), $userId, $ip);
        return $new;
    }

    private function saveRow(?array $old, string $date, array $values, ?array $actual): void
    {
        if ($old) {
            // Any change needs confirming again.
            $this->db->prepare(
                'UPDATE daily_closings SET closer_id = ?, total_sales = ?, cash_sales = ?, transfer_sales = ?, card_sales = ?, unknown_sales = ?,
                    cash_out = ?, expected_send = ?, cash_sent = ?, sent_to = ?, receipt_no = ?, note = ?, snapshot = ?, updated_at = NOW(),
                    verified_by = NULL, verified_at = NULL
                 WHERE id = ?'
            )->execute([...$values, $old['id']]);
        } else {
            $this->db->prepare(
                'INSERT INTO daily_closings (closer_id, total_sales, cash_sales, transfer_sales, card_sales, unknown_sales,
                    cash_out, expected_send, cash_sent, sent_to, receipt_no, note, snapshot, business_date, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([...$values, $date]);
        }
        if ($actual !== null) {
            $this->db->prepare('UPDATE daily_closings SET actual_accounts = ? WHERE business_date = ?')
                ->execute([json_encode($actual), $date]);
        }
    }

    /** Non-cash methods in use (or that took money that day), with what the sales say each account should have received. */
    public function accounts(array $figures): array
    {
        $on = (new SettingsService($this->db))->paymentMethods();
        $got = array_column($figures['methods'], 'amount', 'method');
        $out = [];
        foreach (SettingsService::PAYMENT_METHODS as $method => $label) {
            if ($method === 'cash' || (!in_array($method, $on, true) && empty($got[$method]))) {
                continue;
            }
            $out[] = ['method' => $method, 'label' => $label, 'expected' => round((float) ($got[$method] ?? 0), 2)];
        }
        return $out;
    }

    /** Steps of the day's report that may be left for later. */
    public const SKIP_ITEMS = ['drawer' => 'ปิดยอดลิ้นชัก', 'wages' => 'จ่ายค่าแรง', 'payouts' => 'จ่ายเงินผู้ฝากขาย', 'purchases' => 'ซื้อสินค้าเข้าร้าน'];

    /**
     * The end-of-day report: sales, the drawer (float, what should be there, what was
     * counted), each receiving account (expected / actually received), wages paid,
     * consignors paid, money spent on goods for the store, and the steps skipped for now.
     */
    public function report(string $date): array
    {
        $f = $this->figures($date);
        $q = function (string $sql, array $args) {
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute($args);
                return $stmt->fetchAll();
            } catch (\PDOException $e) {
                return []; // its migration not run yet
            }
        };
        $wages = $q(
            "SELECT w.doc_no, w.amount, w.method, w.drawer_date, w.note, u.full_name AS employee_name FROM wage_payments w
             LEFT JOIN users u ON u.id = w.user_id
             WHERE w.cancelled_at IS NULL AND (w.pay_date = ? OR w.drawer_date = ?) ORDER BY w.id",
            [$date, $date]
        );
        $payouts = $this->payoutsOf($date);
        $pending = $q("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount FROM withdrawals WHERE status = 'pending'", [])[0]
            ?? ['n' => 0, 'amount' => 0];
        $purchases = $this->purchasesOf($date);
        $skips = [];
        foreach ($q('SELECT s.item, s.note, s.created_at, u.full_name AS user_name FROM closing_skips s LEFT JOIN users u ON u.id = s.user_id WHERE s.business_date = ?', [$date]) as $s) {
            $skips[$s['item']] = $s;
        }
        $sum = fn (array $rows, string $k) => round(array_sum(array_map(fn ($r) => (float) $r[$k], $rows)), 2);
        return [
            'figures' => $f,
            'accounts' => $this->accounts($f),
            'wages' => ['rows' => $wages, 'total' => $sum($wages, 'amount'),
                'from_drawer' => $sum(array_filter($wages, fn ($w) => $w['drawer_date'] === $date), 'amount')],
            'payouts' => ['rows' => $payouts, 'total' => $sum($payouts, 'amount'),
                'cash' => $sum(array_filter($payouts, fn ($w) => $w['method'] === 'cash'), 'amount'), 'from_drawer' => $f['payout_out'],
                'pending_count' => (int) $pending['n'], 'pending_amount' => round((float) $pending['amount'], 2)],
            'purchases' => ['rows' => $purchases, 'total' => $sum($purchases, 'total'), 'from_drawer' => $f['purchase_out']],
            'skips' => (object) $skips,
        ];
    }

    /** Marks a step of the day's report as left for later ($on = false takes the mark back). */
    public function skip(string $date, string $item, bool $on, int $userId, ?string $note, ?string $ip): void
    {
        if (!isset(self::SKIP_ITEMS[$item])) {
            throw new \RuntimeException('ไม่รู้จักรายการนี้');
        }
        try {
            if ($on) {
                $this->db->prepare('INSERT IGNORE INTO closing_skips (business_date, item, user_id, note, created_at) VALUES (?, ?, ?, ?, NOW())')
                    ->execute([$date, $item, $userId, $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null]);
            } else {
                $this->db->prepare('DELETE FROM closing_skips WHERE business_date = ? AND item = ?')->execute([$date, $item]);
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException('ยังข้ามรายการไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0052) ก่อน');
        }
        $this->audit->log('daily_closings', 0, 'update', null, ['date' => $date, 'skip' => $item, 'on' => $on], $userId, $ip);
    }

    /** Admin / manager: confirms the summary (the cash was received by the college). */
    public function verify(string $date, int $userId, ?string $ip): array
    {
        $old = $this->find($date);
        if (!$old) {
            throw new \RuntimeException('ยังไม่มีสรุปบัญชีของวันนี้');
        }
        if ($old['verified_at'] !== null) {
            throw new \RuntimeException('สรุปบัญชีวันนี้ยืนยันไปแล้ว');
        }
        // A manager on the roster sums up some days; someone else confirms them.
        if ((int) $old['closer_id'] === $userId) {
            throw new \RuntimeException('ยืนยันสรุปบัญชีที่ตัวเองบันทึกไม่ได้ ให้ผู้ดูแลระบบหรือผู้จัดการคนอื่นยืนยัน');
        }
        $this->db->prepare('UPDATE daily_closings SET verified_by = ?, verified_at = NOW() WHERE id = ?')->execute([$userId, $old['id']]);
        $this->audit->log('daily_closings', (int) $old['id'], 'update', ['verified_at' => null], ['verified_by' => $userId], $userId, $ip);
        return $this->find($date);
    }
}
