<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class DashboardService
{
    public function __construct(private PDO $db)
    {
    }

    public function kpis(): array
    {
        $today = date('Y-m-d');

        $salesToday = $this->db->prepare(
            'SELECT COALESCE(SUM(total_value),0) AS value, COALESCE(SUM(total_qty),0) AS qty
             FROM sales WHERE business_date = ?'
        );
        $salesToday->execute([$today]);
        $sales = $salesToday->fetch();

        $feeToday = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN type='fee' THEN amount_out ELSE 0 END),0) AS fee,
                COALESCE(SUM(CASE WHEN type IN ('listing_fee') THEN amount_out ELSE 0 END),0) AS listing_fee
             FROM ledger_entries WHERE entry_date = ?"
        );
        $feeToday->execute([$today]);
        $fee = $feeToday->fetch();

        $balanceRow = $this->db->query(
            "SELECT COUNT(*) AS n, COALESCE(SUM(balance_after),0) AS total FROM (
                SELECT consignor_id, balance_after,
                       ROW_NUMBER() OVER (PARTITION BY consignor_id ORDER BY id DESC) rn
                FROM ledger_entries
             ) t WHERE rn = 1 AND balance_after > 0"
        )->fetch();

        $stockRow = $this->db->query(
            'SELECT COALESCE(SUM(qty_stock),0) AS units, COUNT(*) AS items,
                    COUNT(DISTINCT consignor_id) AS consignors
             FROM products WHERE qty_stock > 0 AND consignor_id IS NOT NULL'
        )->fetch();

        $bought = $this->db->query(
            "SELECT COALESCE(SUM(line_total),0) AS total,
                    COALESCE(SUM(CASE WHEN item_type='supply' THEN line_total END),0) AS supply
             FROM purchase_items"
        )->fetch();

        $store = $this->db->query(
            'SELECT COALESCE(SUM(qty_stock),0) AS units, COUNT(*) AS items,
                    COALESCE(SUM(qty_stock * cost_price),0) AS cost_value,
                    COALESCE(SUM(qty_stock * sale_price),0) AS retail_value
             FROM products WHERE qty_stock > 0 AND consignor_id IS NULL'
        )->fetch();
        $storeSales = $this->db->prepare(
            'SELECT COALESCE(SUM(si.qty * si.unit_price),0) AS sales, COALESCE(SUM(si.cost_amount),0) AS cogs,
                    COALESCE(SUM(si.qty),0) AS qty
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE si.consignor_id IS NULL AND s.business_date = ?'
        );
        $storeSales->execute([$today]);
        $ss = $storeSales->fetch();

        return [
            'sales_today_value' => (float) $sales['value'],
            'sales_today_qty' => (int) $sales['qty'],
            'fee_today' => (float) $fee['fee'],
            'listing_fee_today' => (float) $fee['listing_fee'],
            'outstanding_balance' => (float) $balanceRow['total'],
            'outstanding_consignor_count' => (int) $balanceRow['n'],
            'stock_units' => (int) $stockRow['units'],
            'stock_items' => (int) $stockRow['items'],
            'stock_consignors' => (int) $stockRow['consignors'],
            'purchase_total_cost' => round((float) $bought['total'], 2),
            'purchase_supply_cost' => round((float) $bought['supply'], 2),
            'store_sales_today' => (float) $ss['sales'],
            'store_profit_today' => round((float) $ss['sales'] - (float) $ss['cogs'], 2),
            'store_qty_today' => (int) $ss['qty'],
            'store_stock_units' => (int) $store['units'],
            'store_stock_items' => (int) $store['items'],
            'store_stock_cost' => round((float) $store['cost_value'], 2),
            'store_stock_retail' => round((float) $store['retail_value'], 2),
        ];
    }

    public function recentSales(int $limit = 8): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.sale_datetime AS time, p.name, COALESCE(c.name, 'สินค้าของร้าน') AS owner, si.qty,
                    (si.qty * si.unit_price) AS total
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             JOIN products p ON p.id = si.product_id
             LEFT JOIN consignors c ON c.id = si.consignor_id
             ORDER BY si.id DESC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function alerts(): array
    {
        $alerts = [];

        $pendingWd = $this->db->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) v FROM withdrawals WHERE status='pending'")->fetch();
        if ((int) $pendingWd['c'] > 0) {
            $alerts[] = [
                'title' => 'มีคำขอเบิกเงินรออนุมัติ ' . $pendingWd['c'] . ' รายการ',
                'detail' => 'ยอดรวม ฿' . number_format((float) $pendingWd['v'], 2),
                'action' => 'ไปที่เบิก/จ่ายเงิน',
                'target' => 'withdraw',
                'level' => 'warn',
            ];
        }

        $expiring = $this->db->query(
            "SELECT COUNT(*) c FROM products WHERE qty_stock > 0 AND consignor_id IS NOT NULL AND expiry_date IS NOT NULL
             AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
        )->fetchColumn();
        if ((int) $expiring > 0) {
            $alerts[] = [
                'title' => 'สินค้าใกล้หมดอายุภายใน 7 วัน ' . $expiring . ' รายการ',
                'detail' => 'ควรแจ้งผู้ฝากขายให้มารับคืนหรือลดราคา',
                'action' => 'ไปที่คืนสินค้า',
                'target' => 'ret',
                'level' => 'warn',
            ];
        }

        $pendingDocs = $this->db->query("SELECT COUNT(*) FROM consignors WHERE status='pending_docs'")->fetchColumn();
        if ((int) $pendingDocs > 0) {
            $alerts[] = [
                'title' => 'ผู้ฝากขายรอตรวจสอบเอกสาร ' . $pendingDocs . ' ราย',
                'detail' => 'ตรวจสอบเอกสารก่อนเปิดใช้งานบัญชี',
                'action' => 'ไปที่ทะเบียนผู้ฝากขาย',
                'target' => 'consignors',
                'level' => 'info',
            ];
        }

        $staleStock = $this->db->query(
            "SELECT COUNT(*) FROM products WHERE qty_stock > 0 AND consignor_id IS NOT NULL AND created_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();
        if ((int) $staleStock > 0) {
            $alerts[] = [
                'title' => 'มีสินค้าค้างสต๊อกนานเกิน 30 วัน ' . $staleStock . ' รายการ',
                'detail' => 'แนะนำให้ลดราคาหรือคืนสินค้าให้ผู้ฝาก',
                'action' => 'ไปที่รายงานสรุป',
                'target' => 'reports',
                'level' => 'info',
            ];
        }

        $lowStore = $this->db->query(
            'SELECT COUNT(*) FROM products WHERE consignor_id IS NULL AND qty_stock <= 3 AND qty_sold > 0'
        )->fetchColumn();
        if ((int) $lowStore > 0) {
            $alerts[] = [
                'title' => 'สินค้าของร้านใกล้หมดสต๊อก ' . $lowStore . ' รายการ',
                'detail' => 'เหลือไม่เกิน 3 ชิ้น ควรสั่งซื้อเพิ่ม',
                'action' => 'ไปที่สต๊อกสินค้าของร้าน',
                'target' => 'inventory',
                'level' => 'warn',
            ];
        }

        return $alerts;
    }

    /**
     * Sales per day (week: Mon–Sun, month: every day) or per month (year) for
     * the period containing $date, plus the previous period's total.
     *
     * @return array{period:string, start:string, end:string, prev_date:string, next_date:?string,
     *               buckets:array<int,array{key:string,value:float,store:float,consign:float,qty:int,bills:int}>,
     *               total:float, qty:int, bills:int, prev_total:float}
     */
    public function salesSeries(string $period, string $date): array
    {
        $period = in_array($period, ['week', 'month', 'year'], true) ? $period : 'week';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new \DateTimeImmutable('today');
        [$start, $end, $step, $prev] = match ($period) {
            'week' => [$d->modify('monday this week'), $d->modify('monday this week')->modify('+7 days'), '+1 day', '-7 days'],
            'month' => [$d->modify('first day of this month'), $d->modify('first day of next month'), '+1 day', '-1 month'],
            'year' => [$d->setDate((int) $d->format('Y'), 1, 1), $d->setDate((int) $d->format('Y') + 1, 1, 1), '+1 month', '-1 year'],
        };
        $keyFormat = $period === 'year' ? 'Y-m' : 'Y-m-d';
        $sqlKey = $period === 'year' ? "DATE_FORMAT(s.business_date, '%Y-%m')" : 's.business_date';

        $stmt = $this->db->prepare(
            "SELECT {$sqlKey} AS k,
                    COALESCE(SUM(si.qty * si.unit_price), 0) AS value,
                    COALESCE(SUM(CASE WHEN si.consignor_id IS NULL THEN si.qty * si.unit_price ELSE 0 END), 0) AS store,
                    COALESCE(SUM(si.qty), 0) AS qty,
                    COUNT(DISTINCT s.id) AS bills
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE s.business_date >= ? AND s.business_date < ?
             GROUP BY k"
        );
        $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[(string) $r['k']] = $r;
        }

        $buckets = [];
        for ($t = $start; $t < $end; $t = $t->modify($step)) {
            $k = $t->format($keyFormat);
            $r = $rows[$k] ?? null;
            $value = round((float) ($r['value'] ?? 0), 2);
            $store = round((float) ($r['store'] ?? 0), 2);
            $buckets[] = [
                'key' => $k,
                'value' => $value,
                'store' => $store,
                'consign' => round($value - $store, 2),
                'qty' => (int) ($r['qty'] ?? 0),
                'bills' => (int) ($r['bills'] ?? 0),
            ];
        }

        $prevStart = $start->modify($prev);
        $prevTotal = $this->db->prepare(
            'SELECT COALESCE(SUM(si.qty * si.unit_price), 0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE s.business_date >= ? AND s.business_date < ?'
        );
        $prevTotal->execute([$prevStart->format('Y-m-d'), $start->format('Y-m-d')]);

        $today = new \DateTimeImmutable('today');
        return [
            'period' => $period,
            'start' => $start->format('Y-m-d'),
            'end' => $end->modify('-1 day')->format('Y-m-d'),
            'prev_date' => $prevStart->format('Y-m-d'),
            // No browsing into the future.
            'next_date' => $end <= $today ? $end->format('Y-m-d') : null,
            'buckets' => $buckets,
            'total' => round(array_sum(array_column($buckets, 'value')), 2),
            'qty' => array_sum(array_column($buckets, 'qty')),
            'bills' => array_sum(array_column($buckets, 'bills')),
            'prev_total' => round((float) $prevTotal->fetchColumn(), 2),
        ];
    }

    public function topSellers7Days(int $limit = 5): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.name, SUM(si.qty) AS qty
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             JOIN products p ON p.id = si.product_id
             WHERE s.sale_datetime >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY p.id, p.name ORDER BY qty DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
