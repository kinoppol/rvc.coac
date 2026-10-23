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

    /** Card keys the dashboard can open in detail. */
    public const DETAILS = ['sales_today', 'fee_today', 'outstanding', 'consign_stock', 'purchases', 'store_sales_today', 'store_stock'];

    /**
     * What lies behind one KPI card, as tables for the detail dialog:
     * ['title', 'note', 'link' => route, 'sections' => [['title', 'columns' => [[key, label, type]], 'rows']]].
     * type: text | money | qty | date | datetime.
     */
    public function detail(string $key): array
    {
        $today = date('Y-m-d');
        $q = function (string $sql, array $params = []): array {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        };
        $section = fn (string $title, array $columns, array $rows) => ['title' => $title, 'columns' => $columns, 'rows' => $rows];

        return match ($key) {
            'sales_today' => [
                'title' => 'ยอดขายวันนี้', 'link' => 'drawer',
                'sections' => [
                    $section('แยกตามวิธีชำระเงิน', [['label', 'วิธีชำระ', 'text'], ['bills', 'บิล', 'qty'], ['amount', 'ยอดเงิน', 'money']], $q(
                        "SELECT CASE sp.method WHEN 'cash' THEN 'เงินสด' WHEN 'transfer' THEN 'โอนเงิน' WHEN 'qr' THEN 'สแกน QR-Code' ELSE 'บัตรเครดิต' END AS label,
                                COUNT(DISTINCT sp.sale_id) AS bills, SUM(sp.amount) AS amount
                         FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id WHERE s.business_date = ?
                         GROUP BY sp.method
                         UNION ALL
                         SELECT 'ไม่ทราบ (นำเข้าจาก POS)', COUNT(*), COALESCE(SUM(total_value), 0) FROM sales s
                         WHERE s.business_date = ? AND NOT EXISTS (SELECT 1 FROM sale_payments sp WHERE sp.sale_id = s.id)
                         HAVING COUNT(*) > 0",
                        [$today, $today]
                    )),
                    $section('แยกตามสินค้า', [['product', 'สินค้า', 'text'], ['owner', 'เจ้าของ', 'text'], ['qty', 'ชิ้น', 'qty'], ['value', 'ยอดขาย', 'money']], $q(
                        "SELECT p.name AS product, COALESCE(c.name, 'สินค้าของร้าน') AS owner, SUM(si.qty) AS qty, SUM(si.qty * si.unit_price) AS value
                         FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN products p ON p.id = si.product_id
                         LEFT JOIN consignors c ON c.id = si.consignor_id
                         WHERE s.business_date = ? GROUP BY p.id, p.name, c.name ORDER BY value DESC",
                        [$today]
                    )),
                ],
            ],
            'fee_today' => [
                'title' => 'ค่าฝากขายที่ร้านได้รับวันนี้', 'link' => 'passbook',
                'sections' => [$section('แยกตามผู้ฝากขาย', [['code', 'รหัส', 'text'], ['consignor', 'ผู้ฝากขาย', 'text'], ['sales', 'ยอดขาย', 'money'], ['fee', 'ค่าฝากขาย', 'money'], ['listing_fee', 'ค่าทำข้อมูล', 'money']], $q(
                    "SELECT c.code, c.name AS consignor,
                            SUM(CASE WHEN l.type = 'sale' THEN l.amount_in ELSE 0 END) AS sales,
                            SUM(CASE WHEN l.type = 'fee' THEN l.amount_out ELSE 0 END) AS fee,
                            SUM(CASE WHEN l.type = 'listing_fee' THEN l.amount_out WHEN l.type = 'listing_fee_refund' THEN -l.amount_in ELSE 0 END) AS listing_fee
                     FROM ledger_entries l JOIN consignors c ON c.id = l.consignor_id
                     WHERE l.entry_date = ? AND l.type IN ('sale', 'fee', 'listing_fee', 'listing_fee_refund')
                     GROUP BY c.id, c.code, c.name ORDER BY fee DESC",
                    [$today]
                ))],
            ],
            'outstanding' => [
                'title' => 'ยอดคงเหลือค้างจ่ายผู้ฝาก', 'link' => 'withdraw',
                'sections' => [$section('ผู้ฝากที่มีเงินคงเหลือ', [['code', 'รหัส', 'text'], ['consignor', 'ผู้ฝากขาย', 'text'], ['pay_channel', 'ช่องทางรับเงิน', 'text'], ['last_entry', 'เคลื่อนไหวล่าสุด', 'date'], ['balance', 'คงเหลือ', 'money']], $q(
                    "SELECT c.code, c.name AS consignor, c.pay_channel, t.entry_date AS last_entry, t.balance_after AS balance
                     FROM (SELECT consignor_id, entry_date, balance_after, ROW_NUMBER() OVER (PARTITION BY consignor_id ORDER BY id DESC) rn FROM ledger_entries) t
                     JOIN consignors c ON c.id = t.consignor_id
                     WHERE t.rn = 1 AND t.balance_after > 0 ORDER BY t.balance_after DESC"
                ))],
            ],
            'consign_stock' => [
                'title' => 'สินค้าฝากขายในสต๊อก', 'link' => 'ret',
                'sections' => [
                    $section('แยกตามผู้ฝากขาย', [['code', 'รหัส', 'text'], ['consignor', 'ผู้ฝากขาย', 'text'], ['items', 'รายการ', 'qty'], ['qty', 'ชิ้น', 'qty'], ['value', 'มูลค่าตามราคาขาย', 'money']], $q(
                        'SELECT c.code, c.name AS consignor, COUNT(*) AS items, SUM(p.qty_stock) AS qty, SUM(p.qty_stock * p.sale_price) AS value
                         FROM products p JOIN consignors c ON c.id = p.consignor_id WHERE p.qty_stock > 0
                         GROUP BY c.id, c.code, c.name ORDER BY value DESC'
                    )),
                    $section('รายการสินค้า', [['product', 'สินค้า', 'text'], ['consignor', 'ผู้ฝาก', 'text'], ['received', 'รับฝาก', 'date'], ['expiry_date', 'หมดอายุ', 'date'], ['qty', 'ชิ้น', 'qty'], ['value', 'มูลค่า', 'money']], $q(
                        'SELECT p.name AS product, c.name AS consignor, DATE(p.created_at) AS received, p.expiry_date, p.qty_stock AS qty, p.qty_stock * p.sale_price AS value
                         FROM products p JOIN consignors c ON c.id = p.consignor_id WHERE p.qty_stock > 0
                         ORDER BY p.expiry_date IS NULL, p.expiry_date, c.name, p.name LIMIT 300'
                    )),
                ],
            ],
            'purchases' => [
                'title' => 'ต้นทุนซื้อสินค้าทั้งหมด', 'link' => 'purchase',
                'sections' => [
                    $section('แยกตามร้าน/ผู้ขาย', [['supplier', 'ร้าน/ผู้ขาย', 'text'], ['docs', 'ใบซื้อ', 'qty'], ['stock', 'สินค้าขาย', 'money'], ['supply', 'วัสดุใช้ในร้าน', 'money'], ['total', 'รวม', 'money']], $q(
                        "SELECT pd.supplier_name AS supplier, COUNT(DISTINCT pd.id) AS docs,
                                SUM(CASE WHEN pi.item_type = 'supply' THEN 0 ELSE pi.line_total END) AS stock,
                                SUM(CASE WHEN pi.item_type = 'supply' THEN pi.line_total ELSE 0 END) AS supply, SUM(pi.line_total) AS total
                         FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
                         WHERE pd.status = 'active' GROUP BY pd.supplier_name ORDER BY total DESC"
                    )),
                    $section('ใบซื้อล่าสุด', [['date', 'วันที่', 'date'], ['doc_no', 'เลขที่', 'text'], ['supplier', 'ร้าน/ผู้ขาย', 'text'], ['total_qty', 'ชิ้น', 'qty'], ['total', 'ยอดสุทธิ', 'money']], $q(
                        "SELECT COALESCE(receipt_date, DATE(created_at)) AS date, doc_no, supplier_name AS supplier, total_qty, total
                         FROM purchase_documents WHERE status = 'active' ORDER BY id DESC LIMIT 30"
                    )),
                ],
            ],
            'store_sales_today' => [
                'title' => 'ขายสินค้าของร้านวันนี้', 'link' => 'inventory',
                'sections' => [$section('แยกตามสินค้า', [['product', 'สินค้า', 'text'], ['qty', 'ชิ้น', 'qty'], ['sales', 'ยอดขาย', 'money'], ['cost', 'ต้นทุน', 'money'], ['profit', 'กำไรขั้นต้น', 'money']], $q(
                    'SELECT p.name AS product, SUM(si.qty) AS qty, SUM(si.qty * si.unit_price) AS sales, SUM(si.cost_amount) AS cost,
                            SUM(si.qty * si.unit_price) - SUM(si.cost_amount) AS profit
                     FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN products p ON p.id = si.product_id
                     WHERE si.consignor_id IS NULL AND s.business_date = ? GROUP BY p.id, p.name ORDER BY sales DESC',
                    [$today]
                ))],
            ],
            'store_stock' => [
                'title' => 'สต๊อกสินค้าของร้าน', 'link' => 'inventory',
                'sections' => [
                    $section('ใกล้หมด (เหลือไม่เกิน 3 ชิ้น)', [['product', 'สินค้า', 'text'], ['qty', 'คงเหลือ', 'qty'], ['sold_30d', 'ขาย 30 วัน', 'qty'], ['cost_price', 'ทุน/ชิ้น', 'money']], $q(
                        'SELECT p.name AS product, p.qty_stock AS qty, p.cost_price,
                                (SELECT COALESCE(SUM(si.qty), 0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                                  WHERE si.product_id = p.id AND s.business_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS sold_30d
                         FROM products p WHERE p.consignor_id IS NULL AND p.qty_stock <= 3
                         ORDER BY sold_30d DESC, p.qty_stock ASC LIMIT 100'
                    )),
                    $section('มูลค่าทุนคงเหลือสูงสุด', [['product', 'สินค้า', 'text'], ['qty', 'คงเหลือ', 'qty'], ['cost_price', 'ทุน/ชิ้น', 'money'], ['value', 'มูลค่าทุน', 'money']], $q(
                        'SELECT name AS product, qty_stock AS qty, cost_price, qty_stock * cost_price AS value
                         FROM products WHERE consignor_id IS NULL AND qty_stock > 0 ORDER BY value DESC LIMIT 50'
                    )),
                ],
            ],
            default => throw new \RuntimeException('ไม่พบรายละเอียดนี้'),
        };
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
        $period = in_array($period, ['day', 'week', 'month', 'year'], true) ? $period : 'week';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new \DateTimeImmutable('today');
        [$start, $end, $step, $prev] = match ($period) {
            'day' => [$d, $d->modify('+1 day'), '+1 hour', '-1 day'],
            'week' => [$d->modify('monday this week'), $d->modify('monday this week')->modify('+7 days'), '+1 day', '-7 days'],
            'month' => [$d->modify('first day of this month'), $d->modify('first day of next month'), '+1 day', '-1 month'],
            'year' => [$d->setDate((int) $d->format('Y'), 1, 1), $d->setDate((int) $d->format('Y') + 1, 1, 1), '+1 month', '-1 year'],
        };
        $keyFormat = $period === 'year' ? 'Y-m' : 'Y-m-d';
        $sqlKey = match ($period) {
            'year' => "DATE_FORMAT(s.business_date, '%Y-%m')",
            // One day by the hour the sale was made (a sale after closing booked on this day keeps its own hour).
            'day' => "LPAD(HOUR(s.sale_datetime), 2, '0')",
            default => 's.business_date',
        };

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

        $keys = [];
        if ($period === 'day') {
            // Store hours, widened to any hour that has sales.
            $settings = new SettingsService($this->db);
            $open = (int) substr((string) ($settings->get('store_open_time') ?: '07:00'), 0, 2);
            $closeTime = (string) ($settings->get('store_close_time') ?: '18:00');
            $close = (int) substr($closeTime, 0, 2) - (substr($closeTime, 3, 2) === '00' ? 1 : 0);
            $hours = array_map('intval', array_keys($rows));
            for ($h = min([$open, ...$hours]); $h <= max([$close, ...$hours]); $h++) {
                $keys[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT);
            }
        } else {
            for ($t = $start; $t < $end; $t = $t->modify($step)) {
                $keys[] = $t->format($keyFormat);
            }
        }
        $buckets = [];
        foreach ($keys as $k) {
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
