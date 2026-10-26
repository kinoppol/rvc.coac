<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class ReportService
{
    /** Thai column headings (screen and Excel) for the keys the reports return. */
    public const LABELS = [
        'date' => 'วันที่', 'period' => 'ช่วงเวลา', 'doc_no' => 'เลขที่', 'name' => 'สินค้า', 'product' => 'สินค้า', 'item' => 'รายการ',
        'consignor' => 'ผู้ฝากขาย', 'consignor_code' => 'รหัสผู้ฝาก', 'owner' => 'เจ้าของสินค้า', 'supplier' => 'ร้าน/ผู้ขาย',
        'employee' => 'พนักงาน', 'cashier' => 'พนักงานขาย', 'opened_by' => 'ผู้ระบุเงินตั้งต้น', 'closed_by' => 'ผู้ปิดยอด',
        'created_by' => 'ผู้สร้าง', 'imported_by' => 'ผู้นำเข้า', 'type' => 'ประเภท', 'method' => 'วิธีจ่าย', 'status' => 'สถานะ',
        'note' => 'หมายเหตุ', 'source' => 'แหล่งข้อมูล', 'barcode' => 'บาร์โค้ด', 'sold_at' => 'เวลาขายจริง', 'business_date' => 'ลงบัญชีวันที่',
        'received_date' => 'วันที่รับฝาก', 'expiry_date' => 'วันหมดอายุ',
        'qty' => 'จำนวน (ชิ้น)', 'qty_sold' => 'ขายได้ (ชิ้น)', 'qty_stock' => 'คงเหลือ (ชิ้น)', 'item_count' => 'จำนวนรายการ',
        'bills' => 'จำนวนบิล', 'units' => 'จำนวนวัน/ชม.', 'line_count' => 'จำนวนบรรทัด', 'skipped_count' => 'ข้าม (บรรทัด)',
        'new_product_count' => 'สินค้าใหม่', 'counted_count' => 'นับแล้ว (รายการ)', 'short_items' => 'ขาด (รายการ)', 'short_qty' => 'ขาด (ชิ้น)',
        'over_items' => 'เกิน (รายการ)', 'over_qty' => 'เกิน (ชิ้น)', 'skip_count' => 'ข้ามการระบุเงิน (ครั้ง)', 'after_hours_bills' => 'บิลนอกเวลา',
        'value' => 'มูลค่า (บาท)', 'sales_value' => 'ยอดขาย (บาท)', 'total_sales' => 'ยอดขายรวม', 'consign_sales' => 'ยอดขายสินค้าฝากขาย',
        'store_sales' => 'ยอดขายสินค้าของร้าน', 'total_fee' => 'ค่าฝากขาย', 'consign_fee' => 'รายได้ค่าฝากขาย', 'consignment_fee' => 'ค่าฝากขาย',
        'listing_fee' => 'ค่าทำข้อมูล', 'listing_fee_refund' => 'คืนค่าทำข้อมูล', 'listing_fee_net' => 'ค่าทำข้อมูลสุทธิ', 'net_revenue' => 'รายได้สุทธิ',
        'consignor_received' => 'ผู้ฝากได้รับ', 'total_cost' => 'ต้นทุนรวม', 'store_cost' => 'ต้นทุนสินค้าของร้านที่ขาย', 'unit_cost' => 'ทุน/ชิ้น',
        'cost_price' => 'ทุน/ชิ้น', 'sale_price' => 'ราคาขาย', 'rate_price' => 'อัตรา (บาท)', 'gross_profit' => 'กำไรขั้นต้น',
        'store_gross_profit' => 'กำไรขั้นต้นสินค้าของร้าน', 'total_income' => 'รายได้รวมของร้าน', 'supply_cost' => 'ค่าวัสดุใช้ในร้าน',
        'wage_cost' => 'ค่าแรง', 'net_profit' => 'กำไรสุทธิ', 'stock_cost_value' => 'มูลค่าทุนคงเหลือ', 'stock_retail_value' => 'มูลค่าตามราคาขาย',
        'consignor_value' => 'มูลค่าที่ผู้ฝากจะได้', 'cash' => 'เงินสด', 'transfer' => 'โอนเงิน', 'qr' => 'QR-Code', 'card' => 'บัตรเครดิต',
        'unknown_method' => 'ไม่ทราบวิธีชำระ (นำเข้า)', 'transfer_all' => 'เงินโอน (โอนเข้าบัญชี + QR-Code)',
        'pos_import_value' => 'นำเข้าจาก POS (ไม่แยกวิธีชำระ)', 'no_method' => 'ไม่ได้บันทึกวิธีชำระ', 'non_cash' => 'ไม่ใช่เงินสด', 'opening_cash' => 'เงินตั้งต้น', 'cash_sales' => 'เงินสดจากการขาย',
        'expected_cash' => 'เงินที่ควรมี', 'counted_cash' => 'นับได้ตอนปิด', 'cash_diff' => 'ขาด(−)/เกิน(+)', 'open_status' => 'ระบุเงินตั้งต้น',
        'paid_value' => 'จ่ายเงินแล้ว', 'opening_balance' => 'ยอดยกมา', 'fee_value' => 'ค่าฝาก/ค่าทำข้อมูล', 'closing_balance' => 'คงเหลือค้างจ่าย',
        'diff_value' => 'มูลค่าสินค้าขาด (ราคาขาย)', 'section' => 'หมวด', 'amount' => 'ช่วงที่เลือก (บาท)',
        'prev_amount' => 'ช่วงก่อนหน้า (บาท)', 'change_percent' => 'เปลี่ยนแปลง (%)', 'prev_period' => 'ช่วงก่อนหน้า', 'note' => 'หมายเหตุ / คำอธิบาย', 'investor' => 'ผู้ลงทุน / แหล่งเงินทุน', 'invested' => 'ลงทุนเพิ่ม',
        'withdrawn' => 'ถอนทุนคืน', 'capital_balance' => 'งบลงทุนคงเหลือ', 'capital_purchases' => 'ซื้อของด้วยเงินลงทุน',
        'other_invest' => 'ลงทุนเพิ่ม (อื่น ๆ)', 'profit_withdrawn' => 'เบิกเงินออกจากกำไร', 'profit_purchases' => 'ซื้อของด้วยเงินกำไร',
        'retained_profit' => 'กำไรสะสมคงเหลือ (หลังเบิก)',
    ];

    public function __construct(private PDO $db)
    {
    }

    public function receiveLog(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT rd.created_at AS date, rd.doc_no, c.name AS consignor, ri.qty, (ri.sale_price * ri.qty) AS value
             FROM receive_documents rd
             JOIN consignors c ON c.id = rd.consignor_id
             JOIN receive_items ri ON ri.receive_document_id = rd.id
             WHERE DATE(rd.created_at) BETWEEN ? AND ?' . (\App\Support\Db::hasColumn($this->db, 'receive_documents', 'cancelled_at') ? ' AND rd.cancelled_at IS NULL' : '') . '
             ORDER BY rd.created_at ASC'
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    public function returnLog(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT rd.created_at AS date, rd.doc_no, c.name AS consignor, ri.qty, (ri.sale_price * ri.qty) AS value
             FROM return_documents rd
             JOIN consignors c ON c.id = rd.consignor_id
             JOIN return_items ri ON ri.return_document_id = rd.id
             WHERE DATE(rd.created_at) BETWEEN ? AND ?' . (\App\Support\Db::hasColumn($this->db, 'return_documents', 'cancelled_at') ? ' AND rd.cancelled_at IS NULL' : '') . '
             ORDER BY rd.created_at ASC'
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    public function salesSummaryByConsignor(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(c.name, 'สินค้าของร้าน') AS consignor, COUNT(DISTINCT si.product_id) AS item_count,
                    SUM(si.qty) AS qty_sold, SUM(si.qty * si.unit_price) AS total_sales,
                    SUM(si.fee_amount) AS total_fee, SUM(si.cost_amount) AS consignor_received
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             LEFT JOIN consignors c ON c.id = si.consignor_id
             WHERE s.business_date BETWEEN ? AND ?
             GROUP BY c.id, c.name
             ORDER BY total_sales DESC"
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    public function saleDetailForConsignor(int $consignorId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.name, p.sale_price, SUM(si.qty) AS qty_sold, SUM(si.qty * si.unit_price) AS total_sales,
                    SUM(si.fee_amount) AS total_fee, p.qty_stock
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             JOIN products p ON p.id = si.product_id
             WHERE si.consignor_id = ? AND s.business_date BETWEEN ? AND ?
             GROUP BY p.id, p.name, p.sale_price, p.qty_stock
             ORDER BY total_sales DESC'
        );
        $stmt->execute([$consignorId, $from, $to]);
        return $stmt->fetchAll();
    }

    /** The store's fee income per day. The ledger is the consignor's account: fees are money out of it, refunds money in. */
    public function feeRevenue(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT entry_date AS date,
                    SUM(CASE WHEN type='fee' THEN amount_out ELSE 0 END) AS consignment_fee,
                    SUM(CASE WHEN type='listing_fee' THEN amount_out ELSE 0 END) AS listing_fee,
                    SUM(CASE WHEN type='listing_fee_refund' THEN amount_in ELSE 0 END) AS listing_fee_refund
             FROM ledger_entries
             WHERE entry_date BETWEEN ? AND ? AND type IN ('fee','listing_fee','listing_fee_refund')
             GROUP BY entry_date
             ORDER BY entry_date ASC"
        );
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['net_revenue'] = round(
                (float) $r['consignment_fee'] + (float) $r['listing_fee'] - (float) $r['listing_fee_refund'],
                2
            );
        }
        return $rows;
    }

    /**
     * Sales made outside store hours, line by line: the real time they happened
     * (what stock counts see) next to the accounting day they were booked on.
     * Picked when either date falls in the range.
     */
    public function afterHoursSales(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.sale_datetime AS sold_at, s.business_date, s.doc_no, u.full_name AS cashier,
                    p.name AS product, COALESCE(c.name, 'สินค้าของร้าน') AS owner,
                    si.qty, si.qty * si.unit_price AS sales_value
             FROM sales s
             JOIN sale_items si ON si.sale_id = s.id
             JOIN products p ON p.id = si.product_id
             LEFT JOIN consignors c ON c.id = si.consignor_id
             LEFT JOIN users u ON u.id = s.cashier_id
             WHERE s.after_hours = 1
               AND (DATE(s.sale_datetime) BETWEEN ? AND ? OR s.business_date BETWEEN ? AND ?)
             ORDER BY s.sale_datetime ASC, si.id ASC"
        );
        $stmt->execute([$from, $to, $from, $to]);
        return $stmt->fetchAll();
    }

    /** Store-owned goods: sales, cost of goods sold and gross profit per product. */
    public function storeSales(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.name AS product, SUM(si.qty) AS qty_sold, SUM(si.qty * si.unit_price) AS total_sales,
                    SUM(si.cost_amount) AS total_cost,
                    SUM(si.qty * si.unit_price) - SUM(si.cost_amount) AS gross_profit,
                    p.qty_stock
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             JOIN products p ON p.id = si.product_id
             WHERE si.consignor_id IS NULL AND s.business_date BETWEEN ? AND ?
             GROUP BY p.id, p.name, p.qty_stock
             ORDER BY gross_profit DESC'
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    /** Current stock of store-owned goods valued at cost and at retail price. */
    public function storeStock(): array
    {
        return $this->db->query(
            'SELECT name AS product, barcode, qty_stock, cost_price, sale_price,
                    ROUND(qty_stock * cost_price, 2) AS stock_cost_value,
                    ROUND(qty_stock * sale_price, 2) AS stock_retail_value
             FROM products WHERE consignor_id IS NULL ORDER BY name ASC'
        )->fetchAll();
    }

    /** Purchase lines (stock and supplies) in a period with their cost. */
    public function purchases(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(pd.receipt_date, DATE(pd.created_at)) AS date, pd.doc_no, pd.supplier_name AS supplier,
                    IF(pi.item_type = 'supply', 'ใช้ในร้าน', 'สินค้าขาย') AS type,
                    pi.receipt_name AS item, pi.units AS qty, pi.piece_cost AS unit_cost, pi.line_total AS total_cost
             FROM purchase_items pi
             JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
             WHERE pd.status = 'active' AND COALESCE(pd.receipt_date, DATE(pd.created_at)) BETWEEN ? AND ?
             ORDER BY date ASC, pd.id ASC"
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    /** Wages paid to staff in a period (business expense); cancelled payments are left out. */
    public function wages(string $from, string $to): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT w.pay_date AS date, w.doc_no, u.full_name AS employee,
                        CASE w.wage_type WHEN 'day' THEN 'รายวัน' WHEN 'hour' THEN 'รายชั่วโมง' ELSE 'เหมาจ่าย' END AS type,
                        CONCAT_WS(' – ', w.period_from, w.period_to) AS period, w.units, w.rate AS rate_price,
                        w.amount AS wage_cost, IF(w.method = 'cash', IF(w.drawer_date IS NULL, 'เงินสด', 'เงินสดจากลิ้นชัก'), 'โอนเงิน') AS method, w.note
                 FROM wage_payments w LEFT JOIN users u ON u.id = w.user_id
                 WHERE w.cancelled_at IS NULL AND w.pay_date BETWEEN ? AND ?
                 ORDER BY w.pay_date ASC, w.id ASC"
            );
            $stmt->execute([$from, $to]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            return []; // migration 0034 not run yet
        }
    }

    /**
     * Profit and loss per day / week / month: the store's income (consignment fees,
     * listing fees, margin on its own goods) less supplies bought for use and wages paid.
     */
    public function profit(string $from, string $to, string $group): array
    {
        $days = [];
        $add = function (string $d, string $k, float $v) use (&$days): void {
            $days[$d][$k] = ($days[$d][$k] ?? 0) + $v;
        };

        $stmt = $this->db->prepare(
            'SELECT s.business_date AS d, SUM(si.qty * si.unit_price) AS sales,
                    SUM(CASE WHEN si.consignor_id IS NULL THEN si.qty * si.unit_price ELSE 0 END) AS store_sales,
                    SUM(CASE WHEN si.consignor_id IS NULL THEN si.cost_amount ELSE 0 END) AS cogs,
                    SUM(CASE WHEN si.consignor_id IS NOT NULL THEN si.fee_amount ELSE 0 END) AS consign_fee,
                    COUNT(DISTINCT s.id) AS bills
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE s.business_date BETWEEN ? AND ? GROUP BY s.business_date'
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $r) {
            foreach (['sales', 'store_sales', 'cogs', 'consign_fee', 'bills'] as $k) {
                $add($r['d'], $k, (float) $r[$k]);
            }
        }
        $stmt = $this->db->prepare(
            "SELECT entry_date AS d, SUM(CASE WHEN type = 'listing_fee' THEN amount_out ELSE -amount_in END) AS listing
             FROM ledger_entries WHERE type IN ('listing_fee', 'listing_fee_refund') AND entry_date BETWEEN ? AND ? GROUP BY entry_date"
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $r) {
            $add($r['d'], 'listing', (float) $r['listing']);
        }
        $stmt = $this->db->prepare(
            "SELECT COALESCE(pd.receipt_date, DATE(pd.created_at)) AS d, SUM(pi.line_total) AS supply
             FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
             WHERE pi.item_type = 'supply' AND pd.status = 'active' AND COALESCE(pd.receipt_date, DATE(pd.created_at)) BETWEEN ? AND ?
             GROUP BY d"
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $r) {
            $add($r['d'], 'supply', (float) $r['supply']);
        }
        try {
            $stmt = $this->db->prepare('SELECT pay_date AS d, SUM(amount) AS wages FROM wage_payments WHERE cancelled_at IS NULL AND pay_date BETWEEN ? AND ? GROUP BY pay_date');
            $stmt->execute([$from, $to]);
            foreach ($stmt->fetchAll() as $r) {
                $add($r['d'], 'wages', (float) $r['wages']);
            }
        } catch (\PDOException $e) {
            // migration 0034 not run yet: no wages
        }

        $buckets = [];
        foreach (self::periods($from, $to, $group) as $key => $label) {
            $buckets[$key] = ['period' => $label];
        }
        foreach ($days as $d => $v) {
            $key = self::periodKey($d, $group);
            foreach ($v as $k => $amount) {
                $buckets[$key][$k] = ($buckets[$key][$k] ?? 0) + $amount;
            }
        }
        $rows = [];
        foreach ($buckets as $b) {
            $v = fn (string $k) => round((float) ($b[$k] ?? 0), 2);
            $storeProfit = round($v('store_sales') - $v('cogs'), 2);
            $income = round($v('consign_fee') + $v('listing') + $storeProfit, 2);
            $rows[] = [
                'period' => $b['period'],
                'bills' => (int) ($b['bills'] ?? 0),
                'total_sales' => $v('sales'),
                'consign_sales' => round($v('sales') - $v('store_sales'), 2),
                'consign_fee' => $v('consign_fee'),
                'listing_fee_net' => $v('listing'),
                'store_sales' => $v('store_sales'),
                'store_cost' => $v('cogs'),
                'store_gross_profit' => $storeProfit,
                'total_income' => $income,
                'supply_cost' => $v('supply'),
                'wage_cost' => $v('wages'),
                'net_profit' => round($income - $v('supply') - $v('wages'), 2),
            ];
        }
        return $rows;
    }

    /**
     * ผลประกอบการ (income statement) for the period next to the period of the same
     * length just before it: total income − total expenses = profit.
     * The consignors' share of their goods' sales is their money, so only the fees count as income.
     */
    public function statement(string $from, string $to): array
    {
        $days = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
        $prevTo = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $prevFrom = (new \DateTimeImmutable($from))->modify("-{$days} days")->format('Y-m-d');
        $sum = function (string $f, string $t): array {
            $out = [];
            foreach ($this->profit($f, $t, 'month') as $r) {
                foreach ($r as $k => $v) {
                    if ($k !== 'period') {
                        $out[$k] = ($out[$k] ?? 0) + $v;
                    }
                }
            }
            return $out;
        };
        $now = $sum($from, $to);
        $prev = $sum($prevFrom, $prevTo);

        $rows = [];
        $line = function (string $section, string $item, callable $value, string $key = '') use (&$rows, $now, $prev): void {
            $a = round($value($now, true), 2);
            $b = round($value($prev, false), 2);
            $rows[] = [
                'section' => $section, 'item' => $item, 'amount' => $a, 'prev_amount' => $b,
                'change_percent' => $b != 0.0 ? round(($a - $b) / abs($b) * 100, 1) : null,
                'note' => $this->statementNote($key ?: $item),
                'key' => $key, // statementSource() lists the records behind this line
            ];
        };
        $v = fn (string $k) => fn (array $s) => (float) ($s[$k] ?? 0);
        $income = fn (array $s) => (float) ($s['consign_fee'] ?? 0) + (float) ($s['listing_fee_net'] ?? 0) + (float) ($s['store_sales'] ?? 0);
        $expense = fn (array $s) => (float) ($s['store_cost'] ?? 0) + (float) ($s['supply_cost'] ?? 0) + (float) ($s['wage_cost'] ?? 0);

        $line('รายได้', 'ค่าฝากขายสินค้า', $v('consign_fee'), 'consign_fee');
        $line('รายได้', 'ค่าทำข้อมูลสินค้า (สุทธิหลังคืน)', $v('listing_fee_net'), 'listing_fee');
        $line('รายได้', 'ยอดขายสินค้าของร้าน', $v('store_sales'), 'store_sales');
        $line('รายได้', 'รวมรายได้', $income);
        $line('รายจ่าย', 'ต้นทุนสินค้าของร้านที่ขายไป', $v('store_cost'), 'store_cost');
        $line('รายจ่าย', 'ค่าวัสดุใช้ในร้าน', $v('supply_cost'), 'supply');
        $line('รายจ่าย', 'ค่าแรงพนักงาน', $v('wage_cost'), 'wages');
        $line('รายจ่าย', 'รวมรายจ่าย', $expense);
        $line('ผลประกอบการ', 'กำไร (ขาดทุน) สุทธิ', fn (array $s) => $income($s) - $expense($s));
        $line('ข้อมูลประกอบ', 'ยอดขายทั้งร้าน (รวมสินค้าฝากขาย)', $v('total_sales'), 'total_sales');
        $line('ข้อมูลประกอบ', 'ยอดขายสินค้าฝากขาย (เงินของผู้ฝาก หักค่าฝากแล้วจ่ายคืน)', $v('consign_sales'), 'consign_sales');
        $line('ข้อมูลประกอบ', 'จำนวนบิลขาย', $v('bills'), 'bills');
        // Capital is not income: shown for reference, with the profit it earned.
        $capital = new CapitalService($this->db, new AuditLogger($this->db), new DocumentNumberService($this->db));
        try {
            $capNow = $capital->balance($to);
            $capPrev = $capital->balance($prevTo);
        } catch (\PDOException $e) {
            $capNow = $capPrev = 0.0; // migration 0035 not run yet
        }
        if ($capNow != 0.0 || $capPrev != 0.0) {
            $line('ข้อมูลประกอบ', 'งบลงทุนคงเหลือ ณ สิ้นช่วง', fn (array $s, bool $isNow) => $isNow ? $capNow : $capPrev, 'capital');
            $line('ข้อมูลประกอบ', 'กำไรต่อเงินลงทุน (%)', fn (array $s, bool $isNow) => ($cap = $isNow ? $capNow : $capPrev) > 0 ? ($income($s) - $expense($s)) / $cap * 100 : 0);
        }
        foreach ($rows as &$r) {
            $r['prev_period'] = "{$prevFrom} – {$prevTo}";
        }
        return $rows;
    }

    /**
     * Money flows behind งบลงทุน, per day: goods bought with invested money (capital),
     * goods bought with the business's profit, other money invested, and money
     * withdrawn from the profit. Missing tables (migrations not run) count as none.
     * @return array<string,array<string,float>> date => [cap_purchase, profit_purchase, invest, withdraw]
     */
    public function capitalFlows(string $from, string $to): array
    {
        $days = [];
        $add = function (array $rows, string $key) use (&$days): void {
            foreach ($rows as $r) {
                $days[$r['d']][$key] = ($days[$r['d']][$key] ?? 0) + (float) $r['v'];
            }
        };
        $q = function (string $sql) use ($from, $to): array {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$from, $to]);
            return $stmt->fetchAll();
        };
        $date = 'COALESCE(receipt_date, DATE(created_at))';
        try {
            $add($q("SELECT {$date} AS d, SUM(total) AS v FROM purchase_documents WHERE status = 'active' AND funding = 'capital' AND {$date} BETWEEN ? AND ? GROUP BY d"), 'cap_purchase');
            $add($q("SELECT {$date} AS d, SUM(total) AS v FROM purchase_documents WHERE status = 'active' AND funding = 'profit' AND {$date} BETWEEN ? AND ? GROUP BY d"), 'profit_purchase');
        } catch (\PDOException $e) {
            // migration 0036 not run yet: every purchase was bought with invested money
            $add($q("SELECT {$date} AS d, SUM(total) AS v FROM purchase_documents WHERE status = 'active' AND {$date} BETWEEN ? AND ? GROUP BY d"), 'cap_purchase');
        }
        try {
            $add($q("SELECT entry_date AS d, SUM(amount) AS v FROM capital_entries WHERE cancelled_at IS NULL AND type = 'invest' AND entry_date BETWEEN ? AND ? GROUP BY entry_date"), 'invest');
            $add($q("SELECT entry_date AS d, SUM(amount) AS v FROM capital_entries WHERE cancelled_at IS NULL AND type = 'withdraw' AND entry_date BETWEEN ? AND ? GROUP BY entry_date"), 'withdraw');
        } catch (\PDOException $e) {
            // migration 0035 not run yet
        }
        return $days;
    }

    /** The first day anything happened (sales, purchases, capital): where running totals start. */
    public function firstDay(): string
    {
        $first = (string) $this->db->query(
            'SELECT LEAST(COALESCE((SELECT MIN(business_date) FROM sales), CURDATE()),
                          COALESCE((SELECT MIN(COALESCE(receipt_date, DATE(created_at))) FROM purchase_documents), CURDATE()))'
        )->fetchColumn();
        try {
            $cap = $this->db->query('SELECT MIN(entry_date) FROM capital_entries')->fetchColumn();
            if ($cap && $cap < $first) {
                $first = (string) $cap;
            }
        } catch (\PDOException $e) {
            // migration 0035 not run yet
        }
        return $first;
    }

    /**
     * รายงานงบลงทุน, one row per month after the balance brought forward: งบลงทุน grows with
     * goods bought with invested money (and other money invested) and shrinks as money is
     * withdrawn from the profit; next to it the month's net profit and the profit still in
     * the business after withdrawals.
     */
    public function capital(string $from, string $to): array
    {
        $first = min($this->firstDay(), $from);
        $before = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $capital = 0.0;
        $retained = 0.0;
        if ($first <= $before) {
            foreach ($this->capitalFlows($first, $before) as $f) {
                $capital += ($f['cap_purchase'] ?? 0) + ($f['invest'] ?? 0) - ($f['withdraw'] ?? 0);
                $retained -= $f['withdraw'] ?? 0;
            }
            foreach ($this->profit($first, $before, 'month') as $p) {
                $retained += $p['net_profit'];
            }
        }
        $buckets = [];
        foreach (self::periods($from, $to, 'month') as $key => $label) {
            $buckets[$key] = ['period' => $label, 'cap_purchase' => 0.0, 'invest' => 0.0, 'withdraw' => 0.0, 'profit_purchase' => 0.0,
                // the days of this month inside the report range: what capitalSource() lists
                '_from' => max("{$key}-01", $from), '_to' => min((new \DateTimeImmutable("{$key}-01"))->format('Y-m-t'), $to)];
        }
        foreach ($this->capitalFlows($from, $to) as $d => $f) {
            foreach ($f as $k => $v) {
                $buckets[self::periodKey($d, 'month')][$k] += $v;
            }
        }
        $profit = array_column($this->profit($from, $to, 'month'), 'net_profit');
        $rows = [[
            'period' => 'ยอดยกมา', 'capital_purchases' => null, 'other_invest' => null, 'profit_withdrawn' => null,
            'capital_balance' => round($capital, 2), 'net_profit' => null, 'profit_purchases' => null, 'retained_profit' => round($retained, 2),
        ]];
        foreach (array_values($buckets) as $i => $b) {
            $capital += $b['cap_purchase'] + $b['invest'] - $b['withdraw'];
            $retained += ($profit[$i] ?? 0) - $b['withdraw'];
            $rows[] = [
                'period' => $b['period'],
                'capital_purchases' => round($b['cap_purchase'], 2),
                'other_invest' => round($b['invest'], 2),
                'profit_withdrawn' => round($b['withdraw'], 2),
                'capital_balance' => round($capital, 2),
                'net_profit' => round($profit[$i] ?? 0, 2),
                'profit_purchases' => round($b['profit_purchase'], 2),
                'retained_profit' => round($retained, 2),
                '_from' => $b['_from'],
                '_to' => $b['_to'],
            ];
        }
        return $rows;
    }

    /** Figures of รายงานงบลงทุน that open the records they add up (capitalSource). */
    public const CAPITAL_DRILL = ['capital_purchases', 'other_invest', 'profit_withdrawn', 'net_profit', 'profit_purchases'];

    /**
     * Net profit as one table with income and expenses in their own columns (its รวม row
     * gives total income and total expenses), then total income − total expenses.
     */
    private function profitSections(string $from, string $to): array
    {
        $rows = [];
        $in = 0.0;
        $out = 0.0;
        foreach ($this->statement($from, $to) as $r) {
            if (!in_array($r['section'], ['รายได้', 'รายจ่าย'], true) || str_starts_with($r['item'], 'รวม')) {
                continue;
            }
            $isIncome = $r['section'] === 'รายได้';
            $rows[] = ['item' => $r['item'], 'note' => $r['note'], 'income' => $isIncome ? $r['amount'] : null, 'expense' => $isIncome ? null : $r['amount'],
                // the detail dialog opens the records behind this line (statementSource) on click
                '_source' => $r['key'] !== '' ? '/reports/statement/source?' . http_build_query(['line' => $r['key'], 'range' => 'custom', 'from' => $from, 'to' => $to]) : null];
            $isIncome ? $in += $r['amount'] : $out += $r['amount'];
        }
        $net = round($in - $out, 2);
        return [
            ['title' => 'รายรับ – รายจ่าย', 'rows' => $rows,
                'columns' => [['item', 'รายการ', 'text'], ['note', 'คำอธิบาย', 'text'], ['income', 'รายรับ', 'money'], ['expense', 'รายจ่าย', 'money']]],
            ['title' => $net < 0 ? 'ขาดทุนสุทธิ' : 'กำไรสุทธิ', 'total' => false,
                'columns' => [['item', 'รายการ', 'text'], ['amount', 'จำนวนเงิน', 'money']], 'rows' => [
                    ['item' => 'รวมรายรับ', 'amount' => round($in, 2)],
                    ['item' => 'หัก รวมรายจ่าย', 'amount' => round(-$out, 2)],
                    ['item' => $net < 0 ? 'ขาดทุนสุทธิ' : 'กำไรสุทธิ', 'amount' => $net],
                ]],
        ];
    }

    /** The records behind one figure of รายงานงบลงทุน for $from–$to, as tables for a detail dialog. */
    public function capitalSource(string $column, string $from, string $to): array
    {
        $section = fn (string $title, array $columns, array $rows) => ['title' => $title, 'columns' => $columns, 'rows' => $rows];
        $q = function (string $sql, array $params): array {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        };
        $date = 'COALESCE(pd.receipt_date, DATE(pd.created_at))';
        $purchases = function (string $funding) use ($q, $date, $from, $to): array {
            $sql = "SELECT {$date} AS date, pd.doc_no, pd.supplier_name AS supplier, pd.receipt_no,
                           (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_document_id = pd.id) AS items, pd.total
                    FROM purchase_documents pd
                    WHERE pd.status = 'active' AND {$date} BETWEEN ? AND ?";
            try {
                return $q($sql . ' AND pd.funding = ? ORDER BY date, pd.id', [$from, $to, $funding]);
            } catch (\PDOException $e) {
                return $funding === 'capital' ? $q($sql . ' ORDER BY date, pd.id', [$from, $to]) : []; // migration 0036 not run yet
            }
        };
        $entries = function (string $type) use ($q, $from, $to): array {
            try {
                return $q(
                    "SELECT entry_date AS date, doc_no, investor, IF(method = 'cash', 'เงินสด', 'โอนเงิน') AS method, note, amount
                     FROM capital_entries WHERE cancelled_at IS NULL AND type = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date, id",
                    [$type, $from, $to]
                );
            } catch (\PDOException $e) {
                return []; // migration 0035 not run yet
            }
        };
        $purchaseCols = [['date', 'วันที่ใบเสร็จ', 'date'], ['doc_no', 'เลขที่', 'text'], ['supplier', 'ร้าน/ผู้ขาย', 'text'], ['receipt_no', 'เลขที่ใบเสร็จ', 'text'], ['items', 'รายการ', 'qty'], ['total', 'ยอดสุทธิ', 'money']];
        $entryCols = [['date', 'วันที่', 'date'], ['doc_no', 'เลขที่', 'text'], ['investor', 'ผู้รับเงิน / ผู้ลงทุน', 'text'], ['method', 'วิธี', 'text'], ['note', 'หมายเหตุ', 'text'], ['amount', 'จำนวนเงิน', 'money']];
        [$title, $note, $sections, $link] = match ($column) {
            'capital_purchases' => ['ซื้อของด้วยเงินลงทุน', 'ใบซื้อที่ระบุว่าซื้อด้วยเงินลงทุน (ยอดสุทธิตามใบเสร็จ) นับเพิ่มในงบลงทุน', [$section('ใบซื้อ', $purchaseCols, $purchases('capital'))], 'purchase'],
            'profit_purchases' => ['ซื้อของด้วยเงินกำไร', 'ใบซื้อที่ระบุว่าซื้อด้วยเงินกำไรของกิจการ ไม่นับเพิ่มในงบลงทุน', [$section('ใบซื้อ', $purchaseCols, $purchases('profit'))], 'purchase'],
            'other_invest' => ['ลงทุนเพิ่ม (อื่น ๆ)', 'เงินลงทุนที่ไม่ได้ผ่านใบซื้อ', [$section('รายการลงทุนเพิ่ม', $entryCols, $entries('invest'))], 'capital'],
            'profit_withdrawn' => ['เบิกเงินออกจากกำไร', 'เงินที่เบิกออกจากกำไร หักออกจากงบลงทุนคงเหลือ', [$section('รายการเบิก', $entryCols, $entries('withdraw'))], 'capital'],
            'net_profit' => ['กำไรสุทธิ', 'รวมรายได้ − รวมรายจ่าย (เหมือนรายงานผลประกอบการ)', $this->profitSections($from, $to), null],
            default => throw new \RuntimeException('ไม่พบที่มาของตัวเลขนี้'),
        };
        return ['title' => "ที่มา: {$title}", 'note' => "{$note} · " . self::thaiDate($from) . ($from !== $to ? ' – ' . self::thaiDate($to) : ''), 'link' => $link, 'sections' => $sections];
    }

    /** What a statement line means, in plain words; total lines say how they add up. */
    private function statementNote(string $key): string
    {
        $settings = new SettingsService($this->db);
        return match ($key) {
            'consign_fee' => 'ส่วนที่ร้านหักไว้จากสินค้าฝากขายทุกชิ้นที่ขายได้ (ราคาขาย − ราคาที่ผู้ฝากได้) เป็นรายได้ของร้าน',
            'listing_fee' => 'ค่าทำข้อมูลสินค้าที่เก็บจากผู้ฝากตอนรับฝากสินค้ารายการใหม่ ('
                . number_format((float) ($settings->get('listing_fee') ?? 5), 2) . ' บาท/รายการ ผู้ฝากแต่ละรายฟรี '
                . (int) ($settings->get('free_listings') ?? 5) . ' รายการแรก) หักด้วยเงินที่คืนให้ผู้ฝากเมื่อสินค้ารายการนั้นขายได้เกิน '
                . (int) ($settings->get('waive_qty') ?? 20) . ' ชิ้น',
            'store_sales' => 'ยอดขายสินค้าที่ร้านซื้อมาขายเอง (ไม่รวมสินค้าฝากขาย)',
            'store_cost' => 'ต้นทุนของสินค้าของร้านเฉพาะชิ้นที่ขายออกไปในช่วงนี้ (ทุนเฉลี่ย ณ ตอนขาย)',
            'supply' => 'ของที่ซื้อมาใช้ในร้าน ไม่ได้ซื้อมาขาย เช่น ถุง หลอด นับตามวันที่ในใบเสร็จ',
            'wages' => 'ค่าแรงที่จ่ายให้พนักงานและผู้จัดการ นับตามวันที่จ่าย (ไม่รวมรายการที่ยกเลิก)',
            'total_sales' => 'ยอดขายทุกบิลทั้งสินค้าของร้านและสินค้าฝากขาย',
            'consign_sales' => 'ยอดขายสินค้าฝากขาย ส่วนนี้เป็นเงินของผู้ฝาก ร้านได้เฉพาะค่าฝากขาย จึงไม่นับเป็นรายได้',
            'bills' => 'จำนวนบิลขายทั้งหมดในช่วงนี้',
            'capital' => 'งบลงทุน = เงินซื้อของเข้ากิจการทุกรายการ (ยกเว้นที่ระบุว่าซื้อด้วยเงินกำไร) + เงินลงทุนอื่น − เงินที่เบิกออกจากกำไร ไม่ใช่รายได้ จึงไม่นับในกำไร',
            'กำไรต่อเงินลงทุน (%)' =>'กำไร (ขาดทุน) สุทธิของช่วงนี้ เทียบเป็นร้อยละของเงินลงทุน ณ สิ้นช่วง',
            'รวมรายได้' => 'ค่าฝากขาย + ค่าทำข้อมูลสุทธิ + ยอดขายสินค้าของร้าน',
            'รวมรายจ่าย' => 'ต้นทุนสินค้าของร้านที่ขายไป + ค่าวัสดุใช้ในร้าน + ค่าแรงพนักงาน',
            'กำไร (ขาดทุน) สุทธิ' => 'รวมรายได้ − รวมรายจ่าย',
            default => '',
        };
    }

    /**
     * The records behind one ผลประกอบการ line for the period, as tables for a detail
     * dialog (same shape as DashboardService::detail).
     */
    public function statementSource(string $key, string $from, string $to): array
    {
        $q = function (string $sql, array $params): array {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        };
        $section = fn (string $title, array $columns, array $rows) => ['title' => $title, 'columns' => $columns, 'rows' => $rows];
        $p = [$from, $to];
        $byProduct = fn (string $where) => $q(
            "SELECT p.name AS product, COALESCE(c.name, 'สินค้าของร้าน') AS owner, SUM(si.qty) AS qty,
                    SUM(si.qty * si.unit_price) AS sales, SUM(si.fee_amount) AS fee, SUM(si.cost_amount) AS cost
             FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN products p ON p.id = si.product_id
             LEFT JOIN consignors c ON c.id = si.consignor_id
             WHERE {$where} AND s.business_date BETWEEN ? AND ?
             GROUP BY p.id, p.name, c.name ORDER BY sales DESC",
            $p
        );
        $byConsignor = fn () => $q(
            'SELECT c.name AS consignor, SUM(si.qty) AS qty, SUM(si.qty * si.unit_price) AS sales, SUM(si.fee_amount) AS fee, SUM(si.cost_amount) AS cost
             FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN consignors c ON c.id = si.consignor_id
             WHERE s.business_date BETWEEN ? AND ? GROUP BY c.id, c.name ORDER BY sales DESC',
            $p
        );
        [$title, $sections, $link] = match ($key) {
            'consign_fee' => ['ค่าฝากขายสินค้า', [
                $section('แยกตามผู้ฝาก', [['consignor', 'ผู้ฝาก', 'text'], ['qty', 'ชิ้น', 'qty'], ['sales', 'ยอดขาย', 'money'], ['fee', 'ค่าฝากขาย', 'money']], $byConsignor()),
                $section('แยกตามสินค้า', [['product', 'สินค้า', 'text'], ['owner', 'ผู้ฝาก', 'text'], ['qty', 'ชิ้น', 'qty'], ['sales', 'ยอดขาย', 'money'], ['fee', 'ค่าฝากขาย', 'money']], $byProduct('si.consignor_id IS NOT NULL')),
            ], 'passbook'],
            'listing_fee' => ['ค่าทำข้อมูลสินค้า', [
                $section('รายการในสมุดบัญชีผู้ฝาก', [['date', 'วันที่', 'date'], ['ref_no', 'เลขอ้างอิง', 'text'], ['consignor', 'ผู้ฝาก', 'text'], ['description', 'รายการ', 'text'], ['charged', 'เก็บ', 'money'], ['refunded', 'คืน', 'money']], $q(
                    "SELECT l.entry_date AS date, l.ref_no, c.name AS consignor, l.description,
                            IF(l.type = 'listing_fee', l.amount_out, 0) AS charged, IF(l.type = 'listing_fee_refund', l.amount_in, 0) AS refunded
                     FROM ledger_entries l JOIN consignors c ON c.id = l.consignor_id
                     WHERE l.type IN ('listing_fee', 'listing_fee_refund') AND l.entry_date BETWEEN ? AND ? ORDER BY l.entry_date, l.id",
                    $p
                )),
            ], 'receive'],
            'store_sales', 'store_cost' => [$key === 'store_sales' ? 'ยอดขายสินค้าของร้าน' : 'ต้นทุนสินค้าของร้านที่ขายไป', [
                $section('แยกตามสินค้า', [['product', 'สินค้า', 'text'], ['qty', 'ชิ้น', 'qty'], ['sales', 'ยอดขาย', 'money'], ['cost', 'ต้นทุน', 'money']], $byProduct('si.consignor_id IS NULL')),
            ], 'inventory'],
            'supply' => ['ค่าวัสดุใช้ในร้าน', [
                $section('รายการซื้อ', [['date', 'วันที่ใบเสร็จ', 'date'], ['doc_no', 'เลขที่', 'text'], ['supplier', 'ร้าน/ผู้ขาย', 'text'], ['item', 'รายการ', 'text'], ['qty', 'จำนวน', 'qty'], ['total', 'รวม', 'money']], $q(
                    "SELECT COALESCE(pd.receipt_date, DATE(pd.created_at)) AS date, pd.doc_no, pd.supplier_name AS supplier, pi.receipt_name AS item, pi.units AS qty, pi.line_total AS total
                     FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
                     WHERE pi.item_type = 'supply' AND pd.status = 'active' AND COALESCE(pd.receipt_date, DATE(pd.created_at)) BETWEEN ? AND ?
                     ORDER BY date, pd.id",
                    $p
                )),
            ], 'purchase'],
            'wages' => ['ค่าแรงพนักงาน', [
                $section('รายการจ่ายค่าแรง', [['date', 'วันที่จ่าย', 'date'], ['doc_no', 'เลขที่', 'text'], ['employee', 'พนักงาน', 'text'], ['type', 'ประเภท', 'text'], ['amount', 'จำนวนเงิน', 'money']],
                    array_map(fn ($r) => ['date' => $r['date'], 'doc_no' => $r['doc_no'], 'employee' => $r['employee'], 'type' => $r['type'], 'amount' => $r['wage_cost']], $this->wages($from, $to))),
            ], 'wages'],
            'total_sales', 'bills' => [$key === 'bills' ? 'บิลขาย' : 'ยอดขายทั้งร้าน', [
                $section('แยกตามวัน', [['date', 'วันที่', 'date'], ['bills', 'บิล', 'qty'], ['sales', 'ยอดขาย', 'money']], $q(
                    'SELECT business_date AS date, COUNT(*) AS bills, SUM(total_value) AS sales FROM sales
                     WHERE business_date BETWEEN ? AND ? GROUP BY business_date ORDER BY business_date',
                    $p
                )),
            ], 'drawer'],
            'capital' => ['งบลงทุนในกิจการ', [
                $section('สรุปรายเดือน (ตั้งแต่เริ่มต้นถึงสิ้นช่วง)', [['period', 'เดือน', 'text'], ['capital_purchases', 'ซื้อของด้วยเงินลงทุน', 'money'], ['other_invest', 'ลงทุนอื่น', 'money'], ['profit_withdrawn', 'เบิกออกจากกำไร', 'money'], ['capital_balance', 'งบลงทุนคงเหลือ', 'money']],
                    array_slice($this->capital($this->firstDay(), $to), 1)),
            ], 'capital'],
            'consign_sales' => ['ยอดขายสินค้าฝากขาย', [
                $section('แยกตามผู้ฝาก', [['consignor', 'ผู้ฝาก', 'text'], ['qty', 'ชิ้น', 'qty'], ['sales', 'ยอดขาย', 'money'], ['fee', 'ค่าฝากขาย (ร้าน)', 'money'], ['cost', 'ผู้ฝากได้', 'money']], $byConsignor()),
            ], 'passbook'],
            default => throw new \RuntimeException('ไม่พบที่มาของรายการนี้'),
        };
        return ['title' => "ที่มา: {$title}", 'note' => $this->statementNote($key), 'link' => $link, 'sections' => $sections];
    }

    /** Sales per day / week / month by payment method (imported sales have none recorded). */
    public function payments(string $from, string $to, string $group): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.business_date AS d,
                    SUM(CASE WHEN sp.method = 'cash' THEN sp.amount ELSE 0 END) AS cash,
                    SUM(CASE WHEN sp.method = 'transfer' THEN sp.amount ELSE 0 END) AS transfer,
                    SUM(CASE WHEN sp.method = 'qr' THEN sp.amount ELSE 0 END) AS qr,
                    SUM(CASE WHEN sp.method = 'card' THEN sp.amount ELSE 0 END) AS card
             FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
             WHERE s.business_date BETWEEN ? AND ? GROUP BY s.business_date"
        );
        $stmt->execute([$from, $to]);
        $paid = [];
        foreach ($stmt->fetchAll() as $r) {
            $paid[$r['d']] = $r;
        }
        $stmt = $this->db->prepare(
            'SELECT business_date AS d, COUNT(*) AS bills, SUM(total_value) AS total,
                    SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM sale_payments sp WHERE sp.sale_id = s.id) THEN total_value ELSE 0 END) AS unknown
             FROM sales s WHERE business_date BETWEEN ? AND ? GROUP BY business_date'
        );
        $stmt->execute([$from, $to]);
        $buckets = [];
        foreach (self::periods($from, $to, $group) as $key => $label) {
            $buckets[$key] = ['period' => $label, 'bills' => 0, 'total_sales' => 0.0, 'cash' => 0.0, 'transfer' => 0.0, 'qr' => 0.0, 'card' => 0.0, 'unknown_method' => 0.0];
        }
        foreach ($stmt->fetchAll() as $r) {
            $k = self::periodKey($r['d'], $group);
            $p = $paid[$r['d']] ?? [];
            $buckets[$k]['bills'] += (int) $r['bills'];
            $buckets[$k]['total_sales'] = round($buckets[$k]['total_sales'] + (float) $r['total'], 2);
            foreach (['cash', 'transfer', 'qr', 'card'] as $m) {
                $buckets[$k][$m] = round($buckets[$k][$m] + (float) ($p[$m] ?? 0), 2);
            }
            $buckets[$k]['unknown_method'] = round($buckets[$k]['unknown_method'] + (float) $r['unknown'], 2);
        }
        return array_values($buckets);
    }

    /**
     * Daily sales split into cash and money transferred (bank transfer + QR-Code), one row
     * per day that had sales. An import from the external POS counts under the methods it
     * was entered with; older imports have no payment method, so they get their own column
     * and are part of the day's total.
     */
    public function dailyCashTransfer(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.business_date AS d, COUNT(*) AS bills,
                    SUM(IF(EXISTS (SELECT 1 FROM sale_payments sp WHERE sp.sale_id = s.id), 0, s.total_value)) AS total
             FROM sales s JOIN pos_imports pi ON pi.sale_id = s.id
             WHERE s.business_date BETWEEN ? AND ? GROUP BY s.business_date"
        );
        $stmt->execute([$from, $to]);
        $imported = [];
        foreach ($stmt->fetchAll() as $r) {
            $imported[self::thaiDate($r['d'])] = $r;
        }
        $out = [];
        foreach ($this->payments($from, $to, 'day') as $r) {
            if (!$r['bills']) {
                continue;
            }
            $pos = $imported[$r['period']] ?? null;
            $posValue = round((float) ($pos['total'] ?? 0), 2);
            $out[] = [
                'period' => $r['period'],
                'bills' => $r['bills'] - (int) ($pos['bills'] ?? 0),
                'cash' => $r['cash'],
                'transfer_all' => round($r['transfer'] + $r['qr'], 2),
                'card' => $r['card'],
                'pos_import_value' => $posValue,
                // Sales with no payment recorded that did not come from an import (older data).
                'no_method' => max(0.0, round($r['unknown_method'] - $posValue, 2)),
                'total_sales' => $r['total_sales'],
            ];
        }
        return $out;
    }

    /** Each day's cash drawer: opening count, cash in and out, closing count and the difference. */
    public function drawers(string $from, string $to): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT d.business_date AS date, d.opening_cash,
                        (SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
                          WHERE s.business_date = d.business_date AND sp.method = 'cash') AS cash_sales,
                        d.counted_cash, d.cash_diff,
                        IF(d.late_open, 'ระบุหลังเริ่มขาย', 'ก่อนขาย') AS open_status,
                        (SELECT COUNT(*) FROM cash_drawer_events e WHERE e.business_date = d.business_date AND e.event = 'skip') AS skip_count,
                        uo.full_name AS opened_by, uc.full_name AS closed_by, d.close_note AS note
                 FROM cash_drawers d
                 LEFT JOIN users uo ON uo.id = d.opened_by
                 LEFT JOIN users uc ON uc.id = d.closed_by
                 WHERE d.business_date BETWEEN ? AND ? ORDER BY d.business_date"
            );
            $stmt->execute([$from, $to]);
            $rows = $stmt->fetchAll();
        } catch (\PDOException $e) {
            return []; // migrations 0033 / 0034 not run yet
        }
        foreach ($rows as &$r) {
            // Closing counts the sales only: wages paid from the drawer come out afterwards.
            $expected = round((float) $r['opening_cash'] + (float) $r['cash_sales'], 2);
            $r = array_slice($r, 0, 3, true) + ['expected_cash' => $expected] + array_slice($r, 3, null, true);
        }
        return $rows;
    }

    /** Sales made by each staff member: bills, value and how they were paid. */
    public function salesByCashier(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(u.full_name, '-') AS cashier, COUNT(*) AS bills, SUM(s.total_qty) AS qty, SUM(s.total_value) AS total_sales,
                    SUM((SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp WHERE sp.sale_id = s.id AND sp.method = 'cash')) AS cash,
                    SUM((SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp WHERE sp.sale_id = s.id AND sp.method <> 'cash')) AS non_cash,
                    SUM(s.after_hours) AS after_hours_bills
             FROM sales s LEFT JOIN users u ON u.id = s.cashier_id
             WHERE s.business_date BETWEEN ? AND ?
             GROUP BY s.cashier_id, u.full_name ORDER BY total_sales DESC"
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    /** Money paid out to consignors (withdrawals) in a period. */
    public function withdrawals(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(COALESCE(w.paid_at, w.requested_at)) AS date, w.doc_no, c.code AS consignor_code, c.name AS consignor,
                    IF(w.status = 'cancelled', 0, w.amount) AS paid_value,
                    CASE w.method WHEN 'cash' THEN 'เงินสด' WHEN 'promptpay' THEN 'พร้อมเพย์' ELSE 'โอนเข้าบัญชี' END AS method,
                    CASE w.status WHEN 'paid' THEN 'จ่ายแล้ว' WHEN 'pending' THEN 'รออนุมัติ' ELSE 'ยกเลิก' END AS status
             FROM withdrawals w JOIN consignors c ON c.id = w.consignor_id
             WHERE DATE(COALESCE(w.paid_at, w.requested_at)) BETWEEN ? AND ?
             ORDER BY date, w.id"
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    /** Each consignor's account for the period and the balance still owed at its end. */
    public function balances(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.code AS consignor_code, c.name AS consignor,
                    COALESCE(SUM(CASE WHEN l.entry_date < ? THEN l.amount_in - l.amount_out END), 0) AS opening_balance,
                    COALESCE(SUM(CASE WHEN l.entry_date BETWEEN ? AND ? AND l.type = 'sale' THEN l.amount_in END), 0) AS sales_value,
                    COALESCE(SUM(CASE WHEN l.entry_date BETWEEN ? AND ? AND l.type IN ('fee', 'listing_fee') THEN l.amount_out
                                      WHEN l.entry_date BETWEEN ? AND ? AND l.type = 'listing_fee_refund' THEN -l.amount_in END), 0) AS fee_value,
                    COALESCE(SUM(CASE WHEN l.entry_date BETWEEN ? AND ? AND l.type = 'withdrawal' THEN l.amount_out
                                      WHEN l.entry_date BETWEEN ? AND ? AND l.type = 'withdrawal_cancel' THEN -l.amount_in END), 0) AS paid_value,
                    COALESCE(SUM(CASE WHEN l.entry_date <= ? THEN l.amount_in - l.amount_out END), 0) AS closing_balance
             FROM consignors c LEFT JOIN ledger_entries l ON l.consignor_id = c.id
             GROUP BY c.id, c.code, c.name
             HAVING opening_balance <> 0 OR sales_value <> 0 OR paid_value <> 0 OR closing_balance <> 0
             ORDER BY c.code"
        );
        $stmt->execute([$from, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $to]);
        return $stmt->fetchAll();
    }

    /** Consignors' goods still in the store, valued at the sale price and what the consignor gets. */
    public function consignStock(): array
    {
        return $this->db->query(
            'SELECT c.code AS consignor_code, c.name AS consignor, p.name AS product, p.barcode, DATE(p.created_at) AS received_date,
                    p.expiry_date, p.qty_stock, p.sale_price, ROUND(p.qty_stock * p.sale_price, 2) AS stock_retail_value,
                    ROUND(p.qty_stock * p.cost_price, 2) AS consignor_value
             FROM products p JOIN consignors c ON c.id = p.consignor_id
             WHERE p.qty_stock <> 0
             ORDER BY c.code, p.name'
        )->fetchAll();
    }

    /** Stock count rounds in a period and how far the counts were from the records. */
    public function stockCounts(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT sc.count_date AS date, sc.doc_no,
                    CASE sc.status WHEN 'draft' THEN 'กำลังนับ' WHEN 'submitted' THEN 'รออนุมัติ' WHEN 'approved' THEN 'อนุมัติแล้ว' ELSE 'ยกเลิก' END AS status,
                    COUNT(i.id) AS item_count, SUM(i.counted_qty IS NOT NULL) AS counted_count,
                    SUM(i.diff_qty < 0) AS short_items, COALESCE(-SUM(CASE WHEN i.diff_qty < 0 THEN i.diff_qty END), 0) AS short_qty,
                    SUM(i.diff_qty > 0) AS over_items, COALESCE(SUM(CASE WHEN i.diff_qty > 0 THEN i.diff_qty END), 0) AS over_qty,
                    COALESCE(SUM(CASE WHEN i.diff_qty < 0 THEN i.diff_qty * p.sale_price END), 0) AS diff_value,
                    u.full_name AS created_by
             FROM stock_counts sc
             LEFT JOIN stock_count_items i ON i.stock_count_id = sc.id
             LEFT JOIN products p ON p.id = i.product_id
             LEFT JOIN users u ON u.id = sc.created_by
             WHERE sc.count_date BETWEEN ? AND ?
             GROUP BY sc.id, sc.count_date, sc.doc_no, sc.status, u.full_name
             ORDER BY sc.count_date, sc.id"
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    /** Day reports imported from the outside POS. */
    public function posImports(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT pi.sale_date AS date, pi.source_label AS source, pi.line_count, pi.skipped_count, pi.new_products AS new_product_count,
                    pi.total_qty AS qty, pi.total_value AS sales_value, IF(pi.status = 'cancelled', 'ยกเลิก', 'นำเข้าแล้ว') AS status,
                    u.full_name AS imported_by
             FROM pos_imports pi LEFT JOIN users u ON u.id = pi.user_id
             WHERE pi.sale_date BETWEEN ? AND ? ORDER BY pi.sale_date, pi.id"
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    /** Monday-start weeks, calendar months or days between $from and $to: key => Thai label. */
    private static function periods(string $from, string $to, string $group): array
    {
        $out = [];
        $end = new \DateTimeImmutable($to);
        for ($d = new \DateTimeImmutable($from); $d <= $end; $d = $d->modify('+1 day')) {
            $key = self::periodKey($d->format('Y-m-d'), $group);
            if (isset($out[$key])) {
                continue;
            }
            $out[$key] = match ($group) {
                'month' => self::MONTHS[(int) $d->format('n') - 1] . ' ' . ((int) $d->format('Y') + 543),
                'week' => self::thaiDate(max($key, $from)) . ' – ' . self::thaiDate(min((new \DateTimeImmutable($key))->modify('+6 days')->format('Y-m-d'), $to)),
                default => self::thaiDate($key),
            };
        }
        return $out;
    }

    private static function periodKey(string $date, string $group): string
    {
        $d = new \DateTimeImmutable($date);
        return match ($group) {
            'month' => $d->format('Y-m'),
            'week' => $d->modify('monday this week')->format('Y-m-d'),
            default => $d->format('Y-m-d'),
        };
    }

    private const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    private static function thaiDate(string $ymd): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $ymd));
        return $d . ' ' . self::MONTHS[$m - 1] . ' ' . ($y + 543);
    }

    /** Writes a UTF-8(BOM) CSV that Excel opens directly, for the "ส่งออก Excel" buttons. */
    public function toCsv(array $rows, array $headers): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM so Excel renders Thai text correctly
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    public static function resolveRange(string $preset, ?string $from, ?string $to): array
    {
        $today = new \DateTimeImmutable('today');
        return match ($preset) {
            '7d' => [$today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'week' => [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d')],
            'last_month' => [$today->modify('first day of last month')->format('Y-m-d'), $today->modify('last day of last month')->format('Y-m-d')],
            'year' => [$today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d'), $today->format('Y-m-d')],
            'month' => [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')],
            'term' => [$today->modify('-4 months')->format('Y-m-d'), $today->format('Y-m-d')],
            'custom' => [$from ?: $today->format('Y-m-d'), $to ?: $today->format('Y-m-d')],
            default => [$today->format('Y-m-d'), $today->format('Y-m-d')], // today
        };
    }
}
