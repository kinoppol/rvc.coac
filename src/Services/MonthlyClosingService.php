<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Db;
use PDO;

/**
 * Month-end closing (migration 0085). For one month: income and expenses (as the profit statement
 * counts them), every loss and mistake (damaged goods, goods short / over in stock counts and the
 * corrections of them, sale bills corrected, cash and transfers over / short at the daily close),
 * and the store goods left at cost — the stock carried to the next month. Consigned goods are not
 * the store's capital and are left out (they are all handed back); the ones still in the store are
 * listed to be returned. Closing keeps the figures as they are; records stay editable and the page
 * shows which figures changed since.
 */
class MonthlyClosingService
{
    public function __construct(private PDO $db)
    {
    }

    public static function ready(PDO $db): bool
    {
        return Db::hasColumn($db, 'monthly_closings', 'figures');
    }

    /** @return array{0:string,1:string} first and last day of YYYY-MM */
    public static function range(string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new \RuntimeException('เดือนไม่ถูกต้อง');
        }
        $from = "{$month}-01";
        return [$from, (new \DateTimeImmutable($from))->format('Y-m-t')];
    }

    /** Store goods on hand at the end of $date at today's cost per piece. */
    private function stockValue(string $date): array
    {
        $qty = (new InventoryService($this->db))->storeStockAt($date);
        $cost = [];
        foreach ($this->db->query('SELECT id, cost_price FROM products WHERE consignor_id IS NULL')->fetchAll() as $p) {
            $cost[(int) $p['id']] = (float) $p['cost_price'];
        }
        $value = 0.0;
        $pieces = 0;
        $items = 0;
        $negItems = 0;
        $negPieces = 0;
        foreach ($qty as $pid => $n) {
            if ($n > 0) {
                $value += $n * ($cost[$pid] ?? 0);
                $pieces += $n;
                $items++;
            } elseif ($n < 0) {
                $negItems++;
                $negPieces += $n;
            }
        }
        return ['value' => round($value, 2), 'pieces' => $pieces, 'items' => $items, 'neg_items' => $negItems, 'neg_pieces' => $negPieces];
    }

    private function one(string $sql, array $args): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($args);
            return $stmt->fetch() ?: [];
        } catch (\PDOException $e) {
            return []; // the table comes with a migration not run yet
        }
    }

    /**
     * Every figure of the month as [key => [section, label, value, unit, note]] (unit: money / qty /
     * count), in display order.
     */
    public function figures(string $month): array
    {
        [$from, $to] = self::range($month);
        $reports = new ReportService($this->db);
        $p = [];
        foreach ($reports->profit($from, $to, 'month') as $r) {
            foreach ($r as $k => $v) {
                if ($k !== 'period' && is_numeric($v)) {
                    $p[$k] = ($p[$k] ?? 0) + $v;
                }
            }
        }
        $f = [];
        $add = function (string $key, string $section, string $label, float|int $value, string $unit = 'money', string $note = '') use (&$f) {
            $f[$key] = ['section' => $section, 'label' => $label, 'value' => $unit === 'money' ? round((float) $value, 2) : (int) $value, 'unit' => $unit, 'note' => $note];
        };

        // ---- Income and expenses (as the profit statement counts them) ----
        $add('consign_fee', 'รายได้', 'ค่าฝากขายสินค้า', $p['consign_fee'] ?? 0);
        $add('listing_fee', 'รายได้', 'ค่าทำข้อมูลสินค้า (สุทธิหลังคืน)', $p['listing_fee_net'] ?? 0);
        $add('store_sales', 'รายได้', 'ยอดขายสินค้าของร้าน', $p['store_sales'] ?? 0);
        $add('total_income', 'รายได้', 'รวมรายได้', $p['total_income'] ?? 0);
        $add('store_cost', 'ค่าใช้จ่าย', 'ต้นทุนสินค้าของร้านที่ขายไป', $p['store_cost'] ?? 0);
        $add('supply_cost', 'ค่าใช้จ่าย', 'ค่าวัสดุใช้ในร้าน', $p['supply_cost'] ?? 0);
        $add('wage_cost', 'ค่าใช้จ่าย', 'ค่าแรงพนักงาน', $p['wage_cost'] ?? 0);
        $add('damage_cost', 'ค่าใช้จ่าย', 'สินค้าเสียหาย (ตัดสต๊อก ตามต้นทุน)', $p['damage_cost'] ?? 0);

        // ---- Losses and mistakes ----
        // Stock counts approved this month, store goods at today's cost: short (lost) and over (found).
        $count = $this->one(
            "SELECT COALESCE(SUM(IF(sci.diff_qty < 0, -sci.diff_qty, 0)), 0) AS short_qty,
                    COALESCE(SUM(IF(sci.diff_qty < 0, -sci.diff_qty * p.cost_price, 0)), 0) AS short_value,
                    COALESCE(SUM(IF(sci.diff_qty > 0, sci.diff_qty, 0)), 0) AS over_qty,
                    COALESCE(SUM(IF(sci.diff_qty > 0, sci.diff_qty * p.cost_price, 0)), 0) AS over_value
             FROM stock_count_items sci JOIN stock_counts sc ON sc.id = sci.stock_count_id JOIN products p ON p.id = sci.product_id
             WHERE sc.status = 'approved' AND sci.counted_qty IS NOT NULL AND p.consignor_id IS NULL AND sc.count_date BETWEEN ? AND ?",
            [$from, $to]
        );
        $add('count_short', 'ความสูญเสียและข้อผิดพลาด', 'สินค้าหายจากการตรวจนับ (ตามต้นทุน)', $count['short_value'] ?? 0, 'money', ((int) ($count['short_qty'] ?? 0)) . ' ชิ้น');
        $add('count_over', 'ความสูญเสียและข้อผิดพลาด', 'สินค้าเกินจากการตรวจนับ (ตามต้นทุน)', $count['over_value'] ?? 0, 'money', ((int) ($count['over_qty'] ?? 0)) . ' ชิ้น');
        $adj = $this->one(
            "SELECT COALESCE(SUM(sa.qty), 0) AS qty, COALESCE(SUM(sa.qty * p.cost_price), 0) AS value
             FROM stock_adjustments sa JOIN products p ON p.id = sa.product_id
             WHERE sa.cancelled_at IS NULL AND p.consignor_id IS NULL AND sa.adjust_date BETWEEN ? AND ?",
            [$from, $to]
        );
        $add('stock_fix', 'ความสูญเสียและข้อผิดพลาด', 'แก้การปรับยอดสต๊อก (+ เพิ่ม / − ลด ตามต้นทุน)', $adj['value'] ?? 0, 'money', ((int) ($adj['qty'] ?? 0)) . ' ชิ้น');
        $dmg = $this->one(
            'SELECT COUNT(*) AS n, COALESCE(SUM(qty), 0) AS qty FROM damaged_goods WHERE cancelled_at IS NULL AND damage_date BETWEEN ? AND ?',
            [$from, $to]
        );
        $add('damage_qty', 'ความสูญเสียและข้อผิดพลาด', 'สินค้าเสียหายที่ตัดสต๊อก (ชิ้น)', (int) ($dmg['qty'] ?? 0), 'qty', ((int) ($dmg['n'] ?? 0)) . ' รายการ');
        // Sale bills corrected this month (an employee's request approved, or a manager's direct edit).
        $edits = $this->one(
            "SELECT COUNT(*) AS n, COALESCE(SUM(total_after - total_before), 0) AS diff,
                    COALESCE(SUM(ABS(total_after - total_before) >= 0.005), 0) AS money_n
             FROM sale_edit_requests WHERE status = 'approved' AND reviewed_at >= ? AND reviewed_at < DATE_ADD(?, INTERVAL 1 DAY)",
            [$from, $to]
        );
        $add('sale_edits', 'ความสูญเสียและข้อผิดพลาด', 'แก้ไขรายการขายที่ลงผิด (บิล)', (int) ($edits['n'] ?? 0), 'count', ((int) ($edits['money_n'] ?? 0)) . ' บิลที่ยอดเงินเปลี่ยน');
        $add('sale_edit_diff', 'ความสูญเสียและข้อผิดพลาด', 'ยอดขายเปลี่ยนจากการแก้ไขรายการขาย (+ เพิ่ม / − ลด)', $edits['diff'] ?? 0);
        $os = $this->one(
            'SELECT COALESCE(SUM(cash_over_short), 0) AS cash, COALESCE(SUM(transfer_over_short), 0) AS transfer,
                    COALESCE(SUM(ABS(COALESCE(cash_over_short, 0)) >= 0.005 OR ABS(COALESCE(transfer_over_short, 0)) >= 0.005), 0) AS days
             FROM daily_closings WHERE business_date BETWEEN ? AND ?',
            [$from, $to]
        );
        $add('cash_over_short', 'ความสูญเสียและข้อผิดพลาด', 'เงินสดขาด (−) / เกิน (+) จากสรุปบัญชีรายวัน', $os['cash'] ?? 0);
        $add('transfer_over_short', 'ความสูญเสียและข้อผิดพลาด', 'เงินโอนขาด (−) / เกิน (+) จากสรุปบัญชีรายวัน', $os['transfer'] ?? 0);
        $add('over_short_days', 'ความสูญเสียและข้อผิดพลาด', 'จำนวนวันที่ยอดเงินไม่ตรง', (int) ($os['days'] ?? 0), 'count');
        $lossTotal = (float) ($p['damage_cost'] ?? 0) + (float) ($count['short_value'] ?? 0) - (float) ($count['over_value'] ?? 0)
            - (float) ($adj['value'] ?? 0) - (float) ($os['cash'] ?? 0) - (float) ($os['transfer'] ?? 0);
        $add('loss_total', 'ความสูญเสียและข้อผิดพลาด', 'รวมความสูญเสียสุทธิ (เสียหาย + หายจากนับ − เกินจากนับ − ปรับยอดเพิ่ม + เงินขาด)', $lossTotal);

        // ---- Result ----
        $net = (float) ($p['net_profit'] ?? 0);
        $add('net_profit', 'ผลประกอบการ', 'กำไร (ขาดทุน) ตามงบกำไรขาดทุน', $net);
        $add('net_after_loss', 'ผลประกอบการ', 'กำไร (ขาดทุน) หลังหักสินค้าหายจากนับและเงินขาด', $net - ($lossTotal - (float) ($p['damage_cost'] ?? 0)),
            'money', 'สินค้าเสียหายหักไว้แล้วในงบกำไรขาดทุน');

        // ---- Store goods carried forward (consigned goods are not the store's capital) ----
        $prevEnd = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $prevMonth = substr($prevEnd, 0, 7);
        $prevClosed = self::ready($this->db) ? $this->one('SELECT closing_stock_value FROM monthly_closings WHERE month = ?', [$prevMonth]) : [];
        $opening = $prevClosed ? (float) $prevClosed['closing_stock_value'] : $this->stockValue($prevEnd)['value'];
        $closing = $this->stockValue($to);
        $add('opening_stock', 'สินค้าคงเหลือ (ทุนยกยอด)', $prevClosed ? 'สินค้าของร้านยกมาต้นเดือน (จากปิดบัญชีเดือนก่อน)' : 'สินค้าของร้านยกมาต้นเดือน (คำนวณ)', $opening);
        $add('closing_stock', 'สินค้าคงเหลือ (ทุนยกยอด)', 'สินค้าของร้านคงเหลือสิ้นเดือน ตามต้นทุน — ยกไปเดือนถัดไป', $closing['value'], 'money',
            "{$closing['items']} รายการ {$closing['pieces']} ชิ้น");
        $add('closing_stock_change', 'สินค้าคงเหลือ (ทุนยกยอด)', 'เปลี่ยนแปลงระหว่างเดือน', $closing['value'] - $opening);
        $add('negative_items', 'สินค้าคงเหลือ (ทุนยกยอด)', 'สินค้าที่คงเหลือติดลบ ณ สิ้นเดือน (ไม่นับเป็นทุน)', $closing['neg_items'], 'count',
            $closing['neg_items'] ? "รวม {$closing['neg_pieces']} ชิ้น — ควรตรวจนับหรือระบุว่าหมด" : '');
        // Consigned goods still in the store: all of them are to be handed back.
        $cons = $this->one(
            'SELECT COUNT(*) AS n, COALESCE(SUM(qty_stock), 0) AS qty, COUNT(DISTINCT consignor_id) AS c FROM products WHERE consignor_id IS NOT NULL AND qty_stock > 0',
            []
        );
        $add('consigned_left', 'สินค้าฝากขาย (ไม่นับเป็นทุน)', 'สินค้าฝากขายที่ยังอยู่ในร้าน (ต้องคืนผู้ฝาก)', (int) ($cons['qty'] ?? 0), 'qty',
            ((int) ($cons['n'] ?? 0)) . ' รายการ จาก ' . ((int) ($cons['c'] ?? 0)) . ' ผู้ฝาก · ยอดตอนนี้');
        return $f;
    }

    /** Months with activity, newest first, each with its closing (if any) and what changed since. */
    public function months(): array
    {
        $first = (new ReportService($this->db))->firstDay();
        $closed = [];
        if (self::ready($this->db)) {
            foreach ($this->db->query('SELECT m.*, u.full_name AS closer FROM monthly_closings m LEFT JOIN users u ON u.id = m.closed_by')->fetchAll() as $r) {
                $closed[$r['month']] = $r;
            }
        }
        $out = [];
        $m = new \DateTimeImmutable(substr(date('Y-m-d'), 0, 7) . '-01');
        $stop = substr($first, 0, 7);
        for ($i = 0; $i < 36 && $m->format('Y-m') >= $stop; $i++, $m = $m->modify('-1 month')) {
            $key = $m->format('Y-m');
            $c = $closed[$key] ?? null;
            $out[] = [
                'month' => $key, 'ended' => $m->format('Y-m-t') < date('Y-m-d'), 'closed' => $c !== null,
                'closed_at' => $c['closed_at'] ?? null, 'closer' => $c['closer'] ?? null,
                'net_profit' => $c ? (float) $c['net_profit'] : null, 'closing_stock_value' => $c ? (float) $c['closing_stock_value'] : null,
            ];
        }
        return $out;
    }

    /** One month: the figures now, the snapshot kept at closing, and which figures differ. */
    public function show(string $month): array
    {
        self::range($month);
        $now = $this->figures($month);
        $c = self::ready($this->db) ? $this->one('SELECT m.*, u.full_name AS closer FROM monthly_closings m LEFT JOIN users u ON u.id = m.closed_by WHERE m.month = ?', [$month]) : [];
        $kept = $c ? (json_decode((string) $c['figures'], true) ?: []) : [];
        $changed = [];
        foreach ($now as $k => $f) {
            if ($c && isset($kept[$k]) && abs((float) $kept[$k]['value'] - (float) $f['value']) >= 0.005) {
                $changed[] = ['key' => $k, 'label' => $f['label'], 'closed' => $kept[$k]['value'], 'now' => $f['value'], 'unit' => $f['unit']];
            }
        }
        return [
            'month' => $month, 'ready' => self::ready($this->db), 'figures' => $now, 'kept' => $kept ?: null, 'changed' => $changed,
            'closing' => $c ? ['closed_at' => $c['closed_at'], 'closer' => $c['closer'], 'note' => $c['note']] : null,
        ];
    }

    /** Closes (or closes again) a month: keeps its figures as they are now. */
    public function close(string $month, ?string $note, int $userId, AuditLogger $audit, ?string $ip): array
    {
        if (!self::ready($this->db)) {
            throw new \RuntimeException('ยังปิดบัญชีประจำเดือนไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0085) ก่อน');
        }
        [, $to] = self::range($month);
        if ($to >= date('Y-m-d')) {
            throw new \RuntimeException('ปิดบัญชีได้เมื่อสิ้นเดือนแล้ว (ตั้งแต่วันที่ 1 ของเดือนถัดไป)');
        }
        $f = $this->figures($month);
        $note = trim((string) $note) === '' ? null : mb_substr(trim((string) $note), 0, 500);
        $old = $this->one('SELECT id, figures FROM monthly_closings WHERE month = ?', [$month]);
        $this->db->prepare(
            'INSERT INTO monthly_closings (month, figures, opening_stock_value, closing_stock_value, net_profit, note, closed_by, closed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE figures = VALUES(figures), opening_stock_value = VALUES(opening_stock_value),
                closing_stock_value = VALUES(closing_stock_value), net_profit = VALUES(net_profit), note = VALUES(note),
                closed_by = VALUES(closed_by), closed_at = VALUES(closed_at)'
        )->execute([$month, json_encode($f, JSON_UNESCAPED_UNICODE), $f['opening_stock']['value'], $f['closing_stock']['value'], $f['net_profit']['value'], $note, $userId]);
        $id = $old ? (int) $old['id'] : (int) $this->db->lastInsertId();
        $audit->log('monthly_closings', $id, $old ? 'update' : 'create', $old ? ['month' => $month, 'figures' => json_decode((string) $old['figures'], true)] : null,
            ['month' => $month, 'net_profit' => $f['net_profit']['value'], 'closing_stock_value' => $f['closing_stock']['value'], 'note' => $note], $userId, $ip);
        return $this->show($month);
    }
}
