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
     * One product with every document that moved its stock, oldest first, and the balance
     * after each: purchases and consignment receipts in, sales and returns to the consignor
     * out, approved stock counts set the balance to what was counted. Stock that came in
     * with no document (a product created by a POS import) opens the list; anything else
     * the documents do not explain closes it, so the last balance is always qty_stock.
     */
    public function movements(int $productId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*, c.name AS consignor_name, c.code AS consignor_code,
                    (SELECT MAX(s.business_date) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.product_id = p.id) AS last_sale
             FROM products p LEFT JOIN consignors c ON c.id = p.consignor_id WHERE p.id = ?"
        );
        $stmt->execute([$productId]);
        $p = $stmt->fetch();
        if (!$p) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT barcode, pack_size FROM product_pack_barcodes WHERE product_id = ? ORDER BY pack_size ASC, id ASC');
        $stmt->execute([$productId]);
        $packs = $stmt->fetchAll();

        // [at, date, kind, doc_no, party, user, qty (+in / -out) or counted, unit price, sort id]
        $stmt = $this->db->prepare(
            "SELECT * FROM (
                SELECT pd.created_at AS at, COALESCE(pd.receipt_date, DATE(pd.created_at)) AS date, 'purchase' AS kind,
                       pd.doc_no, pd.supplier_name AS party, u.full_name AS user_name, pi.units AS qty, pi.piece_cost AS unit_price,
                       NULL AS system_qty, 1 AS ord, pi.id AS rid
                FROM purchase_items pi
                JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
                LEFT JOIN users u ON u.id = pd.user_id
                WHERE pi.product_id = ? AND pi.item_type = 'stock' AND COALESCE(pd.status, 'active') = 'active'
              UNION ALL
                SELECT rd.created_at, DATE(rd.created_at), 'receive', rd.doc_no, c.name, u.full_name, ri.qty, ri.cost_price, NULL, 1, ri.id
                FROM receive_items ri
                JOIN receive_documents rd ON rd.id = ri.receive_document_id
                LEFT JOIN consignors c ON c.id = rd.consignor_id
                LEFT JOIN users u ON u.id = rd.user_id
                WHERE ri.product_id = ?
              UNION ALL
                SELECT sc.approved_at, sc.count_date, 'count', sc.doc_no, NULL, u.full_name, sci.counted_qty, NULL, sci.system_qty, 2, sci.id
                FROM stock_count_items sci
                JOIN stock_counts sc ON sc.id = sci.stock_count_id
                LEFT JOIN users u ON u.id = sc.approved_by
                WHERE sci.product_id = ? AND sc.status = 'approved' AND sci.counted_qty IS NOT NULL AND sci.diff_qty <> 0
              UNION ALL
                SELECT s.created_at, COALESCE(s.business_date, DATE(s.sale_datetime)),
                       IF(EXISTS (SELECT 1 FROM pos_imports pim WHERE pim.sale_id = s.id), 'pos', 'sale'),
                       s.doc_no, NULL, u.full_name, -si.qty, si.unit_price, NULL, 3, si.id
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                LEFT JOIN users u ON u.id = s.cashier_id
                WHERE si.product_id = ?
              UNION ALL
                SELECT rd.created_at, DATE(rd.created_at), 'return', rd.doc_no, COALESCE(NULLIF(rd.recipient_name, ''), c.name), u.full_name,
                       -ri.qty, ri.sale_price, NULL, 3, ri.id
                FROM return_items ri
                JOIN return_documents rd ON rd.id = ri.return_document_id
                LEFT JOIN consignors c ON c.id = rd.consignor_id
                LEFT JOIN users u ON u.id = rd.user_id
                WHERE ri.product_id = ?
             ) m ORDER BY at ASC, ord ASC, rid ASC"
        );
        $stmt->execute([$productId, $productId, $productId, $productId, $productId]);
        $events = $stmt->fetchAll();

        $docIn = 0;
        foreach ($events as $e) {
            if ($e['kind'] === 'purchase' || $e['kind'] === 'receive') {
                $docIn += (int) $e['qty'];
            }
        }
        $rows = [];
        $balance = 0;
        $untracked = (int) $p['qty_received'] - $docIn;
        if ($untracked !== 0) {
            $balance = $untracked;
            $rows[] = [
                'at' => $p['created_at'], 'date' => substr((string) $p['created_at'], 0, 10), 'kind' => 'opening', 'doc_no' => null,
                'party' => null, 'user_name' => null, 'qty_in' => max($untracked, 0), 'qty_out' => max(-$untracked, 0),
                'unit_price' => null, 'balance' => $balance,
                'note' => 'รับเข้าตอนสร้างสินค้า (ไม่มีเอกสารรับเข้า เช่น สินค้าที่สร้างจากการนำเข้ายอดขาย POS)',
            ];
        }
        foreach ($events as $e) {
            $qty = (int) $e['qty'];
            $note = null;
            if ($e['kind'] === 'count') {
                $note = 'นับได้ ' . $qty . ' (ระบบตอนนับ ' . (int) $e['system_qty'] . ')';
                $qty -= $balance; // a count sets the balance; the change is whatever it takes to get there
                $balance = (int) $e['qty'];
            } else {
                $balance += $qty;
            }
            $rows[] = [
                'at' => $e['at'], 'date' => $e['date'], 'kind' => $e['kind'], 'doc_no' => $e['doc_no'], 'party' => $e['party'],
                'user_name' => $e['user_name'], 'qty_in' => max($qty, 0), 'qty_out' => max(-$qty, 0),
                'unit_price' => $e['unit_price'], 'balance' => $balance, 'note' => $note,
            ];
        }
        $gap = (int) $p['qty_stock'] - $balance;
        if ($gap !== 0) {
            $balance += $gap;
            $rows[] = [
                'at' => null, 'date' => null, 'kind' => 'adjust', 'doc_no' => null, 'party' => null, 'user_name' => null,
                'qty_in' => max($gap, 0), 'qty_out' => max(-$gap, 0), 'unit_price' => null, 'balance' => $balance,
                'note' => 'ส่วนต่างที่ไม่มีเอกสารอ้างอิง (ปรับให้ตรงกับยอดคงเหลือปัจจุบัน)',
            ];
        }

        $totals = ['in' => 0, 'sold' => 0, 'returned' => 0, 'count_adjust' => 0];
        foreach ($rows as $r) {
            $net = $r['qty_in'] - $r['qty_out'];
            if (in_array($r['kind'], ['purchase', 'receive', 'opening'], true)) {
                $totals['in'] += $net;
            } elseif ($r['kind'] === 'sale' || $r['kind'] === 'pos') {
                $totals['sold'] -= $net;
            } elseif ($r['kind'] === 'return') {
                $totals['returned'] -= $net;
            } else {
                $totals['count_adjust'] += $net;
            }
        }

        $price = (float) $p['sale_price'];
        return [
            'product' => [
                'id' => (int) $p['id'], 'name' => $p['name'], 'barcode' => $p['barcode'], 'image_token' => $p['image_token'],
                'consignor_id' => $p['consignor_id'], 'consignor_name' => $p['consignor_name'], 'consignor_code' => $p['consignor_code'],
                'cost_price' => $p['cost_price'], 'sale_price' => $p['sale_price'],
                'margin_percent' => $price > 0 ? round(($price - (float) $p['cost_price']) / $price * 100, 1) : 0.0,
                'qty_received' => (int) $p['qty_received'], 'qty_sold' => (int) $p['qty_sold'],
                'qty_returned' => (int) $p['qty_returned'], 'qty_stock' => (int) $p['qty_stock'],
                'mfg_date' => $p['mfg_date'] ?? null, 'expiry_date' => $p['expiry_date'] ?? null,
                'created_at' => $p['created_at'], 'last_sale' => $p['last_sale'], 'pack_barcodes' => $packs,
            ],
            'totals' => $totals,
            'movements' => $rows,
        ];
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
