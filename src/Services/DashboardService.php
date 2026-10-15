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
             FROM sales WHERE DATE(sale_datetime) = ?'
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
             FROM products WHERE qty_stock > 0'
        )->fetch();

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
            "SELECT COUNT(*) c FROM products WHERE qty_stock > 0 AND expiry_date IS NOT NULL
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
            "SELECT COUNT(*) FROM products WHERE qty_stock > 0 AND created_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)"
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

        return $alerts;
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
