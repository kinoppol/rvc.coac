<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class ReportService
{
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
             WHERE DATE(rd.created_at) BETWEEN ? AND ?
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
             WHERE DATE(rd.created_at) BETWEEN ? AND ?
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

    public function feeRevenue(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT entry_date AS date,
                    SUM(CASE WHEN type='fee' THEN amount_in ELSE 0 END) AS consignment_fee,
                    SUM(CASE WHEN type='listing_fee' THEN amount_in ELSE 0 END) AS listing_fee,
                    SUM(CASE WHEN type='listing_fee_refund' THEN amount_out ELSE 0 END) AS listing_fee_refund
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
             WHERE COALESCE(pd.receipt_date, DATE(pd.created_at)) BETWEEN ? AND ?
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
            'month' => [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')],
            'term' => [$today->modify('-4 months')->format('Y-m-d'), $today->format('Y-m-d')],
            'custom' => [$from ?: $today->format('Y-m-d'), $to ?: $today->format('Y-m-d')],
            default => [$today->format('Y-m-d'), $today->format('Y-m-d')], // today
        };
    }
}
