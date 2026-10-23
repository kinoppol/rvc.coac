<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * The cash drawer of each accounting day (sales.business_date): staff count the
 * cash in the drawer before selling, and again at closing, so the cash can be
 * checked against cash sales. Staff may skip the opening count for a few minutes
 * to serve a customer; every skip and late count is logged for review.
 */
class CashDrawerService
{
    /** How long one "skip for now" lets a cashier sell without the opening count. */
    public const SKIP_SECONDS = 300;

    public function __construct(private PDO $db, private AuditLogger $audit)
    {
    }

    public function find(string $date): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM cash_drawers WHERE business_date = ?');
        $stmt->execute([$date]);
        return $stmt->fetch() ?: null;
    }

    /** Cash taken for POS sales of the day (cash payment rows hold the amount net of change). */
    public function cashSales(string $date): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp
             JOIN sales s ON s.id = sp.sale_id
             WHERE s.business_date = ? AND sp.method = 'cash'"
        );
        $stmt->execute([$date]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** When this user's latest skip stops letting them sell, or null when none is running. */
    public function skipUntil(string $date, int $userId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT MAX(created_at) FROM cash_drawer_events WHERE business_date = ? AND user_id = ? AND event = 'skip'"
        );
        $stmt->execute([$date, $userId]);
        $last = $stmt->fetchColumn();
        if (!$last) {
            return null;
        }
        $until = (new \DateTimeImmutable((string) $last))->modify('+' . self::SKIP_SECONDS . ' seconds');
        return $until > new \DateTimeImmutable() ? $until->format('Y-m-d H:i:s') : null;
    }

    /** What the POS needs: whether the day's drawer is counted, and the user's running skip. */
    public function state(string $date, int $userId, bool $required): array
    {
        $drawer = $this->find($date);
        return [
            'date' => $date,
            'required' => $required,
            'open' => $drawer !== null,
            'closed' => $drawer !== null && $drawer['closed_at'] !== null,
            'opening_cash' => $drawer ? (float) $drawer['opening_cash'] : null,
            'cash_sales' => $this->cashSales($date),
            'skip_until' => $drawer ? null : $this->skipUntil($date, $userId),
            'skip_seconds' => self::SKIP_SECONDS,
            'now' => date('Y-m-d H:i:s'),
        ];
    }

    /** May this user sell now? The drawer is counted, or their skip is still running (30 s grace). */
    public function canSell(string $date, int $userId): bool
    {
        if ($this->find($date)) {
            return true;
        }
        $stmt = $this->db->prepare(
            "SELECT 1 FROM cash_drawer_events WHERE business_date = ? AND user_id = ? AND event = 'skip'
               AND created_at >= ? LIMIT 1"
        );
        $stmt->execute([$date, $userId, date('Y-m-d H:i:s', time() - self::SKIP_SECONDS - 30)]);
        return (bool) $stmt->fetchColumn();
    }

    public function skip(string $date, int $userId): array
    {
        if (!$this->find($date)) {
            $this->event($date, $userId, 'skip', $this->cashSales($date), 'ข้ามการระบุเงินในลิ้นชักชั่วคราว');
        }
        return $this->state($date, $userId, true);
    }

    /**
     * The opening count. Counted after cash sales were made (after a skip), the
     * drawer already holds that cash, so it is taken off to get the opening cash.
     */
    public function open(string $date, float $counted, int $userId, ?string $note, ?string $ip): array
    {
        if ($counted < 0) {
            throw new \RuntimeException('จำนวนเงินต้องไม่ติดลบ');
        }
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM cash_drawers WHERE business_date = ? FOR UPDATE');
            $lock->execute([$date]);
            if ($lock->fetchColumn()) {
                throw new \RuntimeException('ระบุเงินในลิ้นชักของวันนี้ไปแล้ว');
            }
            $before = $this->cashSales($date);
            $lastSale = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) FROM sales')->fetchColumn();
            $skipped = $this->db->prepare("SELECT COUNT(*) FROM cash_drawer_events WHERE business_date = ? AND event = 'skip'");
            $skipped->execute([$date]);
            $late = $before > 0 || (int) $skipped->fetchColumn() > 0;
            $opening = round($counted - $before, 2);
            if ($opening < 0) {
                throw new \RuntimeException('เงินที่นับได้น้อยกว่าเงินสดที่ขายไปแล้ววันนี้ (฿' . number_format($before, 2) . ') กรุณานับเงินในลิ้นชักทั้งหมดอีกครั้ง');
            }
            $note = $note !== null ? mb_substr(trim($note), 0, 500) : null;
            $this->db->prepare(
                'INSERT INTO cash_drawers (business_date, opening_cash, counted_at_open, cash_sales_before_open, last_sale_id_at_open, late_open, opened_by, opened_at, open_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$date, $opening, round($counted, 2), $before, $lastSale, (int) $late, $userId, date('Y-m-d H:i:s'), $note ?: null]);
            $id = (int) $this->db->lastInsertId();
            $this->event($date, $userId, $late ? 'open_late' : 'open', $counted,
                $late ? 'ระบุเงินในลิ้นชักหลังเริ่มขาย (หักเงินสดที่ขายไปแล้ว ฿' . number_format($before, 2) . ')' : 'ระบุเงินในลิ้นชักก่อนเริ่มขาย');
            $this->audit->log('cash_drawers', $id, 'create', null, [
                'business_date' => $date, 'opening_cash' => $opening, 'counted_at_open' => $counted,
                'cash_sales_before_open' => $before, 'late_open' => $late,
            ], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->state($date, $userId, true);
    }

    /** Admin / manager: fix a wrongly entered opening count. */
    public function adjustOpening(string $date, float $opening, int $userId, string $note, ?string $ip): void
    {
        $drawer = $this->find($date);
        if (!$drawer) {
            throw new \RuntimeException('ยังไม่มีการระบุเงินในลิ้นชักของวันนี้');
        }
        if ($opening < 0) {
            throw new \RuntimeException('จำนวนเงินต้องไม่ติดลบ');
        }
        $this->db->prepare('UPDATE cash_drawers SET opening_cash = ? WHERE id = ?')->execute([round($opening, 2), $drawer['id']]);
        $this->refreshClose($date);
        $this->event($date, $userId, 'adjust_open', $opening, mb_substr('แก้เงินตั้งต้นจาก ฿' . number_format((float) $drawer['opening_cash'], 2) . ($note !== '' ? " · {$note}" : ''), 0, 500), (int) $drawer['opened_by']);
        $this->audit->log('cash_drawers', (int) $drawer['id'], 'update', ['opening_cash' => $drawer['opening_cash']], ['opening_cash' => $opening, 'note' => $note], $userId, $ip);
    }

    /** The closing count; a day already closed can be counted again only by admin / manager ($recount). */
    public function close(string $date, float $counted, int $userId, ?string $note, bool $recount, ?string $ip): void
    {
        $drawer = $this->find($date);
        if (!$drawer) {
            throw new \RuntimeException('ยังไม่มีการระบุเงินในลิ้นชักตอนเปิดร้านของวันนี้');
        }
        if ($drawer['closed_at'] !== null && !$recount) {
            throw new \RuntimeException('ปิดยอดของวันนี้ไปแล้ว (แก้ไขได้โดยผู้ดูแลระบบหรือผู้จัดการร้าน)');
        }
        if ($counted < 0) {
            throw new \RuntimeException('จำนวนเงินต้องไม่ติดลบ');
        }
        $expected = round((float) $drawer['opening_cash'] + $this->cashSales($date) - WageService::drawerOut($this->db, $date), 2);
        $diff = round($counted - $expected, 2);
        $note = $note !== null ? mb_substr(trim($note), 0, 500) : null;
        $this->db->prepare(
            'UPDATE cash_drawers SET closed_by = ?, closed_at = ?, counted_cash = ?, expected_cash = ?, cash_diff = ?, close_note = ? WHERE id = ?'
        )->execute([$userId, date('Y-m-d H:i:s'), round($counted, 2), $expected, $diff, $note ?: null, $drawer['id']]);
        $this->event($date, $userId, $drawer['closed_at'] !== null ? 'recount' : 'close', $counted,
            'เงินที่ควรมี ฿' . number_format($expected, 2) . ' · ' . ($diff == 0.0 ? 'ตรงกัน' : ($diff > 0 ? 'เกิน ฿' : 'ขาด ฿') . number_format(abs($diff), 2)));
        $this->audit->log('cash_drawers', (int) $drawer['id'], 'update',
            ['counted_cash' => $drawer['counted_cash'], 'expected_cash' => $drawer['expected_cash']],
            ['counted_cash' => $counted, 'expected_cash' => $expected, 'cash_diff' => $diff], $userId, $ip);
    }

    /**
     * Admin / manager: correct how a sale was paid (the cashier picked the wrong method).
     * @param array<int,array{method:string, amount:float}> $lines must add up to the sale total
     */
    public function editPayments(int $saleId, array $lines, int $userId, ?string $ip): void
    {
        $stmt = $this->db->prepare('SELECT * FROM sales WHERE id = ?');
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            throw new \RuntimeException('ไม่พบรายการขาย');
        }
        $byMethod = [];
        foreach ($lines as $l) {
            $method = (string) ($l['method'] ?? '');
            $amount = round((float) ($l['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            if (!isset(SettingsService::PAYMENT_METHODS[$method])) {
                throw new \RuntimeException('วิธีชำระเงินไม่ถูกต้อง');
            }
            $byMethod[$method] = round(($byMethod[$method] ?? 0) + $amount, 2);
        }
        $total = round((float) $sale['total_value'], 2);
        if (!$byMethod || abs(array_sum($byMethod) - $total) > 0.004) {
            throw new \RuntimeException('ยอดรวมของวิธีชำระต้องเท่ากับยอดขาย ฿' . number_format($total, 2));
        }

        $old = $this->db->prepare('SELECT method, amount FROM sale_payments WHERE sale_id = ? ORDER BY id');
        $old->execute([$saleId]);
        $oldLines = $old->fetchAll();

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM sale_payments WHERE sale_id = ?')->execute([$saleId]);
            $ins = $this->db->prepare('INSERT INTO sale_payments (sale_id, method, amount) VALUES (?, ?, ?)');
            foreach ($byMethod as $method => $amount) {
                $ins->execute([$saleId, $method, $amount]);
            }
            // Cash received / change only mean something while part of it is cash.
            $cash = $byMethod['cash'] ?? null;
            $change = $cash !== null ? (float) $sale['change_amount'] : 0.0;
            $this->db->prepare('UPDATE sales SET cash_received = ?, change_amount = ? WHERE id = ?')
                ->execute([$cash !== null ? round($cash + $change, 2) : null, $change, $saleId]);
            // A sale before a late opening count: its cash was taken off that count,
            // so the opening cash moves with the corrected cash amount.
            $drawer = $this->find((string) $sale['business_date']);
            if ($drawer && (int) $drawer['late_open'] && $saleId <= (int) $drawer['last_sale_id_at_open']) {
                $stmt = $this->db->prepare(
                    "SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
                     WHERE s.business_date = ? AND s.id <= ? AND sp.method = 'cash'"
                );
                $stmt->execute([$sale['business_date'], $drawer['last_sale_id_at_open']]);
                $before = round((float) $stmt->fetchColumn(), 2);
                $this->db->prepare('UPDATE cash_drawers SET cash_sales_before_open = ?, opening_cash = ROUND(opening_cash + ?, 2) WHERE id = ?')
                    ->execute([$before, round((float) $drawer['cash_sales_before_open'] - $before, 2), $drawer['id']]);
            }
            $this->refreshClose((string) $sale['business_date']);

            $label = fn (array $ls) => implode(' + ', array_map(
                fn ($l) => SettingsService::PAYMENT_METHODS[$l['method']] . ' ' . number_format((float) $l['amount'], 2), $ls
            ));
            $newLines = array_map(fn ($m, $a) => ['method' => $m, 'amount' => $a], array_keys($byMethod), $byMethod);
            $this->event((string) $sale['business_date'], $userId, 'payment_edit', $total,
                mb_substr("{$sale['doc_no']}: " . ($label($oldLines) ?: 'ไม่มีข้อมูล') . ' → ' . $label($newLines), 0, 500),
                (int) $sale['cashier_id']);
            $this->audit->log('sale_payments', $saleId, 'update', ['payments' => $oldLines], ['payments' => $newLines], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Day summary: drawer, totals per payment method, the sales and what staff did. */
    public function summary(string $date, bool $manage): array
    {
        $drawer = $this->find($date);
        if ($drawer) {
            $drawer = $this->withNames($drawer);
        }
        $stmt = $this->db->prepare(
            "SELECT sp.method, COUNT(DISTINCT sp.sale_id) AS sales, SUM(sp.amount) AS amount
             FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
             WHERE s.business_date = ? GROUP BY sp.method"
        );
        $stmt->execute([$date]);
        $methods = [];
        foreach ($stmt->fetchAll() as $r) {
            $methods[] = ['method' => $r['method'], 'label' => SettingsService::PAYMENT_METHODS[$r['method']] ?? $r['method'],
                'sales' => (int) $r['sales'], 'amount' => round((float) $r['amount'], 2)];
        }
        // Sales with no payment rows: imported from the outside POS (method unknown).
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS n, COALESCE(SUM(total_value), 0) AS amount, (SELECT COUNT(*) FROM sales WHERE business_date = ?) AS all_sales,
                    (SELECT COALESCE(SUM(total_value), 0) FROM sales WHERE business_date = ?) AS all_amount
             FROM sales s WHERE s.business_date = ? AND NOT EXISTS (SELECT 1 FROM sale_payments sp WHERE sp.sale_id = s.id)'
        );
        $stmt->execute([$date, $date, $date]);
        $other = $stmt->fetch();

        $cashSales = $this->cashSales($date);
        $cashOut = WageService::drawerOut($this->db, $date);
        $out = [
            'date' => $date,
            'drawer' => $drawer,
            'cash_sales' => $cashSales,
            'cash_out' => $cashOut, // wages paid out of the drawer
            'expected_cash' => $drawer ? round((float) $drawer['opening_cash'] + $cashSales - $cashOut, 2) : null,
            'methods' => $methods,
            'unknown' => ['sales' => (int) $other['n'], 'amount' => round((float) $other['amount'], 2)],
            'total' => ['sales' => (int) $other['all_sales'], 'amount' => round((float) $other['all_amount'], 2)],
        ];
        if ($manage) {
            $out['sales'] = $this->salesOf($date);
            $out['events'] = $this->eventsOf($date);
        }
        return $out;
    }

    /** Per staff member over the last $days days: on-time / late counts, skips and corrected sales. */
    public function behavior(int $days): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.full_name,
                    SUM(e.event = 'open' AND e.user_id = u.id) AS opens,
                    SUM(e.event = 'open_late' AND e.user_id = u.id) AS late_opens,
                    SUM(e.event = 'skip' AND e.user_id = u.id) AS skips,
                    COUNT(DISTINCT CASE WHEN e.event = 'skip' AND e.user_id = u.id THEN e.business_date END) AS skip_days,
                    SUM(e.event IN ('close', 'recount') AND e.user_id = u.id) AS closes,
                    SUM(e.event = 'payment_edit' AND e.subject_user_id = u.id) AS corrected
             FROM cash_drawer_events e
             JOIN users u ON u.id = e.user_id OR u.id = e.subject_user_id
             WHERE e.business_date >= ?
             GROUP BY u.id, u.full_name
             ORDER BY skips DESC, late_opens DESC, u.full_name"
        );
        $stmt->execute([date('Y-m-d', strtotime("-{$days} days"))]);
        $ints = ['id', 'opens', 'late_opens', 'skips', 'skip_days', 'closes', 'corrected'];
        return array_map(function ($r) use ($ints) {
            foreach ($ints as $k) {
                $r[$k] = (int) $r[$k];
            }
            return $r;
        }, $stmt->fetchAll());
    }

    private function salesOf(string $date): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.id, s.doc_no, s.sale_datetime, s.total_value, s.after_hours, u.full_name AS cashier_name,
                    (SELECT GROUP_CONCAT(CONCAT(sp.method, ':', sp.amount) ORDER BY sp.id SEPARATOR ',') FROM sale_payments sp WHERE sp.sale_id = s.id) AS pays
             FROM sales s LEFT JOIN users u ON u.id = s.cashier_id
             WHERE s.business_date = ? ORDER BY s.sale_datetime DESC, s.id DESC"
        );
        $stmt->execute([$date]);
        return array_map(function ($r) {
            $r['payments'] = $r['pays'] ? array_map(function ($p) {
                [$m, $a] = explode(':', $p);
                return ['method' => $m, 'amount' => (float) $a];
            }, explode(',', $r['pays'])) : [];
            unset($r['pays']);
            return $r;
        }, $stmt->fetchAll());
    }

    private function eventsOf(string $date): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, u.full_name AS user_name, su.full_name AS subject_name
             FROM cash_drawer_events e
             LEFT JOIN users u ON u.id = e.user_id
             LEFT JOIN users su ON su.id = e.subject_user_id
             WHERE e.business_date = ? ORDER BY e.id'
        );
        $stmt->execute([$date]);
        return $stmt->fetchAll();
    }

    private function withNames(array $drawer): array
    {
        $stmt = $this->db->prepare('SELECT id, full_name FROM users WHERE id IN (?, ?)');
        $stmt->execute([$drawer['opened_by'], $drawer['closed_by'] ?? 0]);
        $names = array_column($stmt->fetchAll(), 'full_name', 'id');
        $drawer['opened_by_name'] = $names[$drawer['opened_by']] ?? null;
        $drawer['closed_by_name'] = $drawer['closed_by'] ? ($names[$drawer['closed_by']] ?? null) : null;
        return $drawer;
    }

    /** Keeps a closed day's expected cash and difference in step after a correction. */
    public function refreshClose(string $date): void
    {
        $this->db->prepare(
            'UPDATE cash_drawers SET expected_cash = ROUND(opening_cash + ?, 2), cash_diff = ROUND(counted_cash - opening_cash - ?, 2)
             WHERE business_date = ? AND closed_at IS NOT NULL'
        )->execute([$net = round($this->cashSales($date) - WageService::drawerOut($this->db, $date), 2), $net, $date]);
    }

    private function event(string $date, int $userId, string $event, ?float $amount, ?string $detail, ?int $subject = null): void
    {
        $this->db->prepare(
            'INSERT INTO cash_drawer_events (business_date, user_id, subject_user_id, event, amount, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$date, $userId, $subject, $event, $amount, $detail, date('Y-m-d H:i:s')]);
    }
}
