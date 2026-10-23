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
                       IF(p.consignor_id IS NULL,
                          (SELECT MAX(pd.receipt_date) FROM purchase_items pi
                             JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
                            WHERE pi.product_id = p.id),
                          DATE(p.created_at)) AS last_purchase
                FROM products p
                LEFT JOIN consignors c ON c.id = p.consignor_id
                LEFT JOIN (
                    SELECT product_id, SUM(qty * unit_price) AS sales, SUM(cost_amount) AS cogs
                    FROM sale_items GROUP BY product_id
                ) sl ON sl.product_id = p.id
                WHERE " . ($withConsigned ? '(p.consignor_id IS NULL OR p.qty_stock <> 0)' : 'p.consignor_id IS NULL');
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
            // Profit after expenses bought for use in the business (bags, straws, ...).
            'net_profit' => round($grossProfit - (float) $bought['supply_cost'], 2),
        ];
    }
}
