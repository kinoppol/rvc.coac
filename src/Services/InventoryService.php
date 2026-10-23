<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Stock, cost and margin view of store-owned goods (consignor_id IS NULL).
 * Cost is the moving weighted-average maintained by PurchaseService; cost of
 * goods sold is frozen on each sale line (sale_items.cost_amount).
 */
class InventoryService
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param bool $withConsigned also list consignors' lots still holding stock (cost = what the
     *        consignor gets, profit = the store's fee, last_purchase = the day it was received)
     */
    public function products(string $search = '', bool $withConsigned = false): array
    {
        $sql = "SELECT p.id, p.name, p.barcode, p.image_token, p.consignor_id, c.name AS consignor_name,
                       p.qty_received, p.qty_sold, p.qty_returned, p.qty_stock,
                       p.cost_price, p.sale_price,
                       ROUND(p.qty_stock * p.cost_price, 2) AS stock_cost_value,
                       ROUND(p.qty_stock * p.sale_price, 2) AS stock_retail_value,
                       COALESCE(sl.sales, 0) AS sales_total, COALESCE(sl.cogs, 0) AS cogs_total,
                       COALESCE(sl.store_qty, 0) AS store_sold_qty,
                       IF(p.consignor_id IS NULL,
                          (SELECT MAX(pd.receipt_date) FROM purchase_items pi
                             JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
                            WHERE pi.product_id = p.id),
                          DATE(p.created_at)) AS last_purchase
                FROM products p
                LEFT JOIN consignors c ON c.id = p.consignor_id
                LEFT JOIN (
                    SELECT product_id, SUM(qty * unit_price) AS sales, SUM(cost_amount) AS cogs,
                           SUM(CASE WHEN consignor_id IS NULL THEN qty END) AS store_qty
                    FROM sale_items GROUP BY product_id
                ) sl ON sl.product_id = p.id
                WHERE " . ($withConsigned ? '(p.consignor_id IS NULL OR p.qty_stock <> 0 OR sl.store_qty > 0)' : 'p.consignor_id IS NULL');
        $params = [];
        if ($search !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.barcode = ? OR EXISTS (SELECT 1 FROM product_pack_barcodes b WHERE b.product_id = p.id AND b.barcode = ?))';
            $params = ['%' . $search . '%', $search, $search];
        }
        $sql .= ' ORDER BY p.name ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $packs = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT product_id, barcode, pack_size FROM product_pack_barcodes WHERE product_id IN ($in) ORDER BY pack_size ASC, id ASC");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $b) {
                $packs[(int) $b['product_id']][] = ['barcode' => $b['barcode'], 'pack_size' => (int) $b['pack_size']];
            }
        }
        foreach ($rows as &$r) {
            $r['pack_barcodes'] = $packs[(int) $r['id']] ?? [];
            $price = (float) $r['sale_price'];
            $r['margin_percent'] = $price > 0 ? round(($price - (float) $r['cost_price']) / $price * 100, 1) : 0.0;
            $r['profit_total'] = round((float) $r['sales_total'] - (float) $r['cogs_total'], 2);
        }
        return $rows;
    }

    /**
     * Store sales booked with no cost (sold before the goods were ever bought in, so the
     * product had no cost yet), per product, priced at the piece cost of the product's
     * first purchase. Products never bought in are left out: there is no cost to use.
     */
    public function zeroCostSales(): array
    {
        return $this->db->query(
            "SELECT p.id AS product_id, p.name AS product, COUNT(si.id) AS line_count, SUM(si.qty) AS qty,
                    SUM(si.qty * si.unit_price) AS sales, fp.piece_cost, ROUND(SUM(si.qty) * fp.piece_cost, 2) AS cost,
                    fp.doc_no AS first_purchase
             FROM sale_items si
             JOIN products p ON p.id = si.product_id
             JOIN (
                SELECT pi.product_id, pi.piece_cost, pd.doc_no,
                       ROW_NUMBER() OVER (PARTITION BY pi.product_id
                                          ORDER BY COALESCE(pd.receipt_date, DATE(pd.created_at)), pd.id, pi.id) AS rn
                FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
                WHERE pi.item_type = 'stock' AND pd.status = 'active' AND pi.piece_cost > 0
             ) fp ON fp.product_id = si.product_id AND fp.rn = 1
             WHERE si.consignor_id IS NULL AND si.cost_amount = 0 AND si.qty > 0
             GROUP BY p.id, p.name, fp.piece_cost, fp.doc_no
             ORDER BY cost DESC"
        )->fetchAll();
    }

    /**
     * Gives those sales the first purchase's piece cost (cost and margin of each sale line),
     * so gross profit stops counting them as all profit.
     * @return array{products:int, lines:int, qty:int, cost:float}
     */
    public function fillZeroCost(int $userId, ?string $ip): array
    {
        $rows = $this->zeroCostSales();
        $audit = new AuditLogger($this->db);
        $upd = $this->db->prepare(
            'UPDATE sale_items SET cost_amount = ROUND(qty * ?, 2), fee_amount = ROUND(qty * unit_price - qty * ?, 2)
             WHERE product_id = ? AND consignor_id IS NULL AND cost_amount = 0 AND qty > 0'
        );
        $out = ['products' => 0, 'lines' => 0, 'qty' => 0, 'cost' => 0.0];
        $this->db->beginTransaction();
        try {
            foreach ($rows as $r) {
                $upd->execute([$r['piece_cost'], $r['piece_cost'], $r['product_id']]);
                $out['products']++;
                $out['lines'] += $upd->rowCount();
                $out['qty'] += (int) $r['qty'];
                $out['cost'] = round($out['cost'] + (float) $r['cost'], 2);
                $audit->log('products', (int) $r['product_id'], 'update', null, [
                    'note' => 'เติมต้นทุนย้อนหลังให้ยอดขายที่ต้นทุนเป็น 0 จากใบซื้อแรก ' . $r['first_purchase'],
                    'piece_cost' => (float) $r['piece_cost'], 'qty' => (int) $r['qty'], 'cost' => (float) $r['cost'],
                ], $userId, $ip);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $out;
    }

    public function summary(): array
    {
        $stock = $this->db->query(
            'SELECT COUNT(*) AS items, COALESCE(SUM(qty_stock),0) AS units,
                    COALESCE(SUM(qty_stock * cost_price),0) AS cost_value,
                    COALESCE(SUM(qty_stock * sale_price),0) AS retail_value,
                    COALESCE(SUM(qty_stock <= 0),0) AS out_of_stock
             FROM products WHERE consignor_id IS NULL'
        )->fetch();
        $sales = $this->db->query(
            'SELECT COALESCE(SUM(qty * unit_price),0) AS sales, COALESCE(SUM(cost_amount),0) AS cogs, COALESCE(SUM(qty),0) AS qty
             FROM sale_items WHERE consignor_id IS NULL'
        )->fetch();
        $bought = $this->db->query(
            "SELECT COALESCE(SUM(CASE WHEN item_type='stock' THEN line_total END),0) AS stock_cost,
                    COALESCE(SUM(CASE WHEN item_type='supply' THEN line_total END),0) AS supply_cost
             FROM purchase_items"
        )->fetch();

        $grossProfit = round((float) $sales['sales'] - (float) $sales['cogs'], 2);
        return [
            'items' => (int) $stock['items'],
            'out_of_stock' => (int) $stock['out_of_stock'],
            'stock_units' => (int) $stock['units'],
            'stock_cost_value' => round((float) $stock['cost_value'], 2),
            'stock_retail_value' => round((float) $stock['retail_value'], 2),
            'expected_profit' => round((float) $stock['retail_value'] - (float) $stock['cost_value'], 2),
            'sales_total' => round((float) $sales['sales'], 2),
            'cogs_total' => round((float) $sales['cogs'], 2),
            'gross_profit' => $grossProfit,
            'qty_sold' => (int) $sales['qty'],
            'purchase_stock_cost' => round((float) $bought['stock_cost'], 2),
            'supply_cost' => round((float) $bought['supply_cost'], 2),
            'wage_cost' => $wages = (new WageService($this->db, new AuditLogger($this->db), new DocumentNumberService($this->db)))->total(),
            // Profit after expenses bought for use in the business (bags, straws, ...).
            'net_profit' => round($grossProfit - (float) $bought['supply_cost'] - $wages, 2),
        ];
    }
}
