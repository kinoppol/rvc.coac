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
        // Marked as really having no barcode (migration 0065; none marked before it).
        $noBarcode = \App\Support\Db::hasColumn($this->db, 'products', 'no_barcode') ? 'p.no_barcode' : '0';
        $discontinued = \App\Support\Db::hasColumn($this->db, 'products', 'discontinued_at') ? 'p.discontinued_at' : 'NULL'; // migration 0067
        // Promotion price selling now, the normal price kept aside, the latest promotion price (migration 0070).
        $promo = \App\Support\Db::hasColumn($this->db, 'products', 'on_promo') ? 'p.on_promo, p.regular_price, p.promo_price' : '0 AS on_promo, NULL AS regular_price, NULL AS promo_price';
        $sql = "SELECT p.id, p.name, p.barcode, {$noBarcode} AS no_barcode, {$discontinued} AS discontinued_at, p.image_token, p.consignor_id, c.name AS consignor_name,
                       (SELECT a.created_at FROM attachments a WHERE a.token = p.image_token) AS image_at,
                       p.qty_received, p.qty_sold, p.qty_returned, p.qty_stock,
                       p.cost_price, p.sale_price, {$promo},
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
                WHERE " . (!$withConsigned ? 'p.consignor_id IS NULL'
                    // A search finds consigned lots at 0 too (sold out / returned stays in stock records);
                    // the plain list keeps to lots still holding stock.
                    : ($search !== '' ? '1 = 1' : '(p.consignor_id IS NULL OR p.qty_stock <> 0 OR sl.store_qty > 0)'));
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
            $stmt = $this->db->prepare("SELECT * FROM product_pack_barcodes WHERE product_id IN ($in) ORDER BY pack_size ASC, id ASC");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $b) {
                $packs[(int) $b['product_id']][] = [
                    'barcode' => $b['barcode'], 'pack_size' => (int) $b['pack_size'],
                    'sale_price' => isset($b['sale_price']) ? (float) $b['sale_price'] : null,
                ];
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
     * Documents that moved stock: one product, or every store product when null.
     * Stock card in document order: the date printed on each document (a receipt keyed in a
     * week late still sits on its own day), then receipts before issues on the same day.
     * qty is +in / -out; an approved count sets the balance to what was counted (counted_qty):
     * the counted figure is the stock on hand, whatever the documents before it add up to.
     */
    private function stockEvents(?int $productId): array
    {
        $in = $productId === null ? 'SELECT id FROM products WHERE consignor_id IS NULL' : '?';
        $splits = \App\Services\ProductSplitService::available($this->db);
        $damages = \App\Services\DamageService::available($this->db);
        $stmt = $this->db->prepare($sql = sprintf(
            "SELECT * FROM (
                SELECT pi.product_id, pd.created_at AS at, COALESCE(pd.receipt_date, DATE(pd.created_at)) AS date, 'purchase' AS kind,
                       pd.doc_no, pd.supplier_name AS party, u.full_name AS user_name, pi.units AS qty,
                       pi.piece_cost AS unit_cost, ROUND(pi.units * pi.piece_cost, 2) AS value, NULL AS sale_price,
                       NULL AS counted_qty, NULL AS system_qty, 1 AS ord, pi.id AS rid
                FROM purchase_items pi
                JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
                LEFT JOIN users u ON u.id = pd.user_id
                WHERE pi.product_id IN (%1\$s) AND pi.item_type = 'stock' AND COALESCE(pd.status, 'active') = 'active'
              UNION ALL
                SELECT ri.product_id, rd.created_at, DATE(rd.created_at), 'receive', rd.doc_no, c.name, u.full_name, ri.qty,
                       ri.cost_price, ROUND(ri.qty * ri.cost_price, 2), NULL, NULL, NULL, 1, ri.id
                FROM receive_items ri
                JOIN receive_documents rd ON rd.id = ri.receive_document_id
                LEFT JOIN consignors c ON c.id = rd.consignor_id
                LEFT JOIN users u ON u.id = rd.user_id
                WHERE ri.product_id IN (%1\$s)%2\$s
              UNION ALL
                SELECT si.product_id, s.created_at, COALESCE(s.business_date, DATE(s.sale_datetime)),
                       IF(EXISTS (SELECT 1 FROM pos_imports pim WHERE pim.sale_id = s.id), 'pos', 'sale'),
                       s.doc_no, NULL, u.full_name, -si.qty,
                       IF(si.qty <> 0, ROUND(si.cost_amount / si.qty, 2), NULL), -si.cost_amount, si.unit_price, NULL, NULL, 2, si.id
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                LEFT JOIN users u ON u.id = s.cashier_id
                WHERE si.product_id IN (%1\$s)
              UNION ALL
                SELECT ri.product_id, rd.created_at, DATE(rd.created_at), 'return', rd.doc_no, COALESCE(NULLIF(rd.recipient_name, ''), c.name), u.full_name,
                       -ri.qty, NULL, NULL, NULL, NULL, NULL, 2, ri.id
                FROM return_items ri
                JOIN return_documents rd ON rd.id = ri.return_document_id
                LEFT JOIN consignors c ON c.id = rd.consignor_id
                LEFT JOIN users u ON u.id = rd.user_id
                WHERE ri.product_id IN (%1\$s)%3\$s
              UNION ALL
                SELECT sci.product_id, sc.approved_at, sc.count_date, 'count', sc.doc_no, NULL, u.full_name, sci.diff_qty,
                       NULL, NULL, NULL, sci.counted_qty, sci.system_qty, 3, sci.id
                FROM stock_count_items sci
                JOIN stock_counts sc ON sc.id = sci.stock_count_id
                LEFT JOIN users u ON u.id = sc.approved_by
                WHERE sci.product_id IN (%1\$s) AND sc.status = 'approved' AND sci.counted_qty IS NOT NULL
              %4\$s
              %5\$s
             ) m ORDER BY product_id, date ASC, ord ASC, at ASC, rid ASC",
            $productId === null ? 'SELECT id FROM products WHERE consignor_id IS NULL' : '?',
            // Cancelled receive / return documents (migration 0050) moved nothing.
            \App\Support\Db::hasColumn($this->db, 'receive_documents', 'cancelled_at') ? ' AND rd.cancelled_at IS NULL' : '',
            \App\Support\Db::hasColumn($this->db, 'return_documents', 'cancelled_at') ? ' AND rd.cancelled_at IS NULL' : '',
            // Splits (migration 0063): pieces out of the source product, into each piece product.
            $splits ? "UNION ALL
                SELECT sp.source_product_id, sp.created_at, DATE(sp.created_at), 'split', sp.doc_no, NULL, u.full_name, -sp.qty,
                       sp.unit_cost, -ROUND(sp.qty * sp.unit_cost, 2), NULL, NULL, NULL, 2, sp.id
                FROM product_splits sp
                LEFT JOIN users u ON u.id = sp.user_id
                WHERE sp.source_product_id IN ($in) AND sp.cancelled_at IS NULL
              UNION ALL
                SELECT spi.product_id, sp.created_at, DATE(sp.created_at), 'split', sp.doc_no, src.name, u.full_name, spi.qty,
                       spi.unit_cost, ROUND(spi.qty * spi.unit_cost, 2), NULL, NULL, NULL, 1, spi.id
                FROM product_split_items spi
                JOIN product_splits sp ON sp.id = spi.split_id
                JOIN products src ON src.id = sp.source_product_id
                LEFT JOIN users u ON u.id = sp.user_id
                WHERE spi.product_id IN ($in) AND sp.cancelled_at IS NULL" : '',
            // Damaged goods written off (migration 0066), on the day they were found.
            $damages ? "UNION ALL
                SELECT dg.product_id, dg.created_at, dg.damage_date, 'damage', dg.doc_no, " . \App\Services\DamageService::reasonSql('dg.reason') . ",
                       u.full_name, -dg.qty, dg.unit_cost, -ROUND(dg.qty * dg.unit_cost, 2), NULL, NULL, NULL, 2, dg.id
                FROM damaged_goods dg
                LEFT JOIN users u ON u.id = dg.user_id
                WHERE dg.product_id IN ($in) AND dg.cancelled_at IS NULL" : ''
        ));
        $stmt->execute($productId === null ? [] : array_fill(0, substr_count($sql, '?'), $productId));
        return $stmt->fetchAll();
    }

    /**
     * One product with every document that moved its stock, oldest first, and the balance
     * after each: purchases and consignment receipts in, sales and returns to the consignor
     * out, approved stock counts adjust it by what they found short or over. Stock that came in
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
        $packs = (new \App\Repositories\ProductRepo($this->db, new AuditLogger($this->db)))->packBarcodes($productId);

        $events = $this->stockEvents($productId);

        $cost = (float) $p['cost_price'];
        $row = fn (array $r) => $r + [
            'at' => null, 'date' => null, 'doc_no' => null, 'party' => null, 'user_name' => null,
            'unit_cost' => null, 'sale_price' => null, 'note' => null,
        ];
        $docIn = 0;
        foreach ($events as $e) {
            // Pieces split in from a pack are counted in the piece product's qty_received.
            if ($e['kind'] === 'purchase' || $e['kind'] === 'receive' || ($e['kind'] === 'split' && (int) $e['qty'] > 0)) {
                $docIn += (int) $e['qty'];
            }
        }
        $rows = [];
        $balance = 0;
        // Stock that came in with no receiving document, when the product was created.
        $untracked = (int) $p['qty_received'] - $docIn;
        if ($untracked !== 0) {
            $balance = $untracked;
            $created = substr((string) $p['created_at'], 0, 10);
            $rows[] = $row([
                'at' => $p['created_at'], 'date' => $events ? min($created, (string) $events[0]['date']) : $created, 'kind' => 'opening',
                'qty' => $untracked, 'unit_cost' => $cost, 'value' => round($untracked * $cost, 2), 'balance' => $balance,
                'note' => 'รับเข้าตอนสร้างสินค้า ไม่มีเอกสารรับเข้า (เช่น สินค้าที่สร้างจากการนำเข้ายอดขาย POS)',
            ]);
        }
        $cancelled = $this->cancelledEvents($productId);
        foreach ($events as $i => $e) {
            // A count sets the balance; its movement is whatever it takes to get there.
            $qty = $e['kind'] === 'count' ? (int) $e['counted_qty'] - $balance : (int) $e['qty'];
            $balance += $qty;
            $note = null;
            if ($e['kind'] === 'count') {
                $note = 'นับได้ ' . (int) $e['counted_qty'] . ' · ในระบบตอนนับ ' . (int) $e['system_qty'];
            } elseif ($e['sale_price'] !== null) {
                $note = 'ราคาขาย ฿' . number_format((float) $e['sale_price'], 2);
            }
            $unit = $e['unit_cost'] !== null ? (float) $e['unit_cost'] : $cost;
            $rows[] = $row([
                'at' => $e['at'], 'date' => $e['date'], 'kind' => $e['kind'], 'doc_no' => $e['doc_no'], 'party' => $e['party'],
                'user_name' => $e['user_name'], 'qty' => $qty, 'unit_cost' => $unit,
                'value' => $e['value'] !== null ? (float) $e['value'] : round($qty * $unit, 2),
                'sale_price' => $e['sale_price'], 'balance' => $balance, 'note' => $note,
            ]);
            if ($e['kind'] === 'count') {
                foreach ($this->countAdjustments($events, $i, $cancelled) as $a) {
                    $balance += $a['qty'];
                    $rows[] = $row($a + ['unit_cost' => $cost, 'value' => round($a['qty'] * $cost, 2), 'balance' => $balance]);
                }
            }
        }
        $gap = (int) $p['qty_stock'] - $balance;
        if ($gap !== 0) {
            $balance += $gap;
            $rows[] = $row([
                'date' => date('Y-m-d'), 'kind' => 'adjust', 'reason' => 'unknown', 'qty' => $gap, 'unit_cost' => $cost, 'value' => round($gap * $cost, 2),
                'balance' => $balance, 'note' => 'ส่วนต่างที่ไม่มีเอกสารอ้างอิง ปรับให้ตรงกับยอดคงเหลือในระบบ — ไม่ทราบว่าเกิดเมื่อไหร่และใครทำ'
                    . ' (ไม่พบเอกสารที่อธิบายได้ เช่น ยอดที่เปลี่ยนก่อนมีการบันทึกประวัติ) แก้ให้ตรงของจริงด้วยการตรวจนับ',
            ]);
        }
        foreach ($rows as &$r) {
            $r['qty_in'] = max($r['qty'], 0);
            $r['qty_out'] = max(-$r['qty'], 0);
            // Keyed in on a later day than the document's own date.
            $r['late'] = $r['at'] !== null && !in_array($r['kind'], ['opening', 'adjust'], true)
                && substr((string) $r['at'], 0, 10) > (string) $r['date'];
        }
        unset($r);

        // Stock card summary: opening + received - sold - returned +/- adjustments = closing (qty and cost).
        $totals = [];
        foreach (['opening', 'received', 'sold', 'returned', 'damaged', 'adjusted', 'split'] as $k) {
            $totals[$k] = ['qty' => 0, 'value' => 0.0];
        }
        $bucket = ['opening' => 'opening', 'purchase' => 'received', 'receive' => 'received', 'sale' => 'sold', 'pos' => 'sold',
                   'return' => 'returned', 'count' => 'adjusted', 'adjust' => 'adjusted', 'split' => 'split', 'damage' => 'damaged'];
        foreach ($rows as $r) {
            $k = $bucket[$r['kind']];
            $sign = in_array($k, ['sold', 'returned', 'damaged'], true) ? -1 : 1;
            $totals[$k]['qty'] += $sign * $r['qty'];
            $totals[$k]['value'] = round($totals[$k]['value'] + $sign * $r['value'], 2);
        }
        $totals['closing'] = ['qty' => (int) $p['qty_stock'], 'value' => round((int) $p['qty_stock'] * $cost, 2)];

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
            'price_changes' => $this->priceChanges($productId),
            'purchase_prices' => $p['consignor_id'] === null ? $this->purchasePrices($productId) : [],
        ];
    }

    /**
     * Why the stock card needs adjusting right after the count at $events[$i]. The card puts a count
     * on its own document date, but the system set the stock to the counted figure when the count
     * was approved. So, against the moment of approval:
     *  - late: documents dated on/before the count day but keyed in after approval moved the stock
     *    after it (the card lets the count swallow them). Purchases are left out: one dated on or
     *    before a count keeps the counted stock (PurchaseService::keepCountedStock);
     *  - early: documents dated after the count day but keyed in before approval had already moved
     *    the stock that approval then overwrote (the card would take them off a second time);
     *  - cancelled: documents keyed in before approval and cancelled after it (the card shows neither).
     * Each comes back as an adjust row: when (approval), who approved, why, and the documents behind it.
     */
    private function countAdjustments(array $events, int $i, array $cancelled): array
    {
        $c = $events[$i];
        if ($c['at'] === null) {
            return [];
        }
        $at = (string) $c['at'];
        $cause = fn (array $e) => [
            'kind' => $e['kind'], 'doc_no' => $e['doc_no'], 'date' => $e['date'], 'at' => $e['at'],
            'user_name' => $e['user_name'], 'qty' => (int) $e['qty'],
        ];
        $late = $early = [];
        foreach ($events as $j => $e) {
            if ($e['kind'] === 'count' || $e['at'] === null) {
                continue;
            }
            if ($j < $i && (string) $e['at'] > $at && $e['kind'] !== 'purchase') {
                $late[] = $cause($e);
            } elseif ($j > $i && (string) $e['at'] < $at) {
                $early[] = $cause($e);
            }
        }
        $undone = array_values(array_filter($cancelled, fn ($e) => (string) $e['at'] < $at && (string) $e['cancelled_at'] > $at));

        $base = [
            'at' => $c['at'], 'date' => $c['date'], 'kind' => 'adjust', 'doc_no' => $c['doc_no'], 'doc_kind' => 'count',
            'user_name' => $c['user_name'], 'count_date' => $c['date'],
        ];
        $out = [];
        $sum = fn (array $list) => array_sum(array_column($list, 'qty'));
        if ($late && ($q = $sum($late)) !== 0) {
            $out[] = $base + ['reason' => 'late', 'qty' => $q, 'causes' => $late,
                'note' => 'มีเอกสารลงวันที่ก่อนหรือวันเดียวกับวันนับ แต่บันทึกเข้าระบบหลังอนุมัติตรวจนับ: ยอดที่นับได้ยังไม่รวมรายการเหล่านี้ ระบบจึงปรับคงเหลือต่อจากยอดที่นับ'];
        }
        if ($early && ($q = -$sum($early)) !== 0) {
            $out[] = $base + ['reason' => 'early', 'qty' => $q, 'causes' => $early,
                'note' => 'มีเอกสารลงวันที่หลังวันนับ แต่บันทึกเข้าระบบก่อนอนุมัติตรวจนับ: ตอนอนุมัติระบบตั้งคงเหลือเป็นยอดที่นับได้ ทับรายการเหล่านี้ไปแล้ว จึงไม่นับซ้ำ'];
        }
        if ($undone && ($q = -$sum($undone)) !== 0) {
            $out[] = $base + ['reason' => 'cancelled', 'qty' => $q, 'causes' => $undone,
                'note' => 'มีเอกสารที่บันทึกก่อนอนุมัติตรวจนับแต่ถูกยกเลิกหลังอนุมัติ: ยอดที่นับได้รวมผลของเอกสารนั้นแล้ว การยกเลิกจึงปรับคงเหลือหลังนับ'];
        }
        return $out;
    }

    /**
     * Cancelled documents of one product that once moved its stock: when they were keyed in and
     * cancelled, by whom, why, and what they had done to the stock (qty, +in / -out).
     * Purchases are left out (cancelling one dated on or before a count keeps the counted stock).
     */
    private function cancelledEvents(int $productId): array
    {
        $has = fn (string $t, string $c) => \App\Support\Db::hasColumn($this->db, $t, $c);
        $parts = [];
        $params = [];
        if ($has('pos_imports', 'cancelled_items')) {
            $stmt = $this->db->prepare(
                "SELECT pi.cancelled_doc_no AS doc_no, pi.sale_date AS date, pi.created_at AS at, pi.cancelled_at, pi.cancel_reason,
                        pi.cancelled_items, u.full_name AS user_name, cu.full_name AS cancelled_by
                 FROM pos_imports pi LEFT JOIN users u ON u.id = pi.user_id LEFT JOIN users cu ON cu.id = pi.cancelled_by
                 WHERE pi.status = 'cancelled' AND pi.cancelled_at IS NOT NULL AND (pi.cancelled_items LIKE ? OR pi.cancelled_items LIKE ?)"
            );
            $stmt->execute(["%\"product_id\":{$productId},%", "%\"product_id\":\"{$productId}\"%"]);
            foreach ($stmt->fetchAll() as $r) {
                $qty = 0;
                foreach ((array) json_decode((string) $r['cancelled_items'], true) as $it) {
                    if ((int) ($it['product_id'] ?? 0) === $productId) {
                        $qty += (int) ($it['qty'] ?? 0);
                    }
                }
                if ($qty !== 0) {
                    $parts[] = ['kind' => 'pos', 'doc_no' => $r['doc_no'], 'date' => $r['date'], 'at' => $r['at'], 'user_name' => $r['user_name'],
                        'qty' => -$qty, 'cancelled_at' => $r['cancelled_at'], 'cancelled_by' => $r['cancelled_by'], 'cancel_reason' => $r['cancel_reason']];
                }
            }
        }
        $sql = [];
        if ($has('receive_documents', 'cancelled_at')) {
            $sql[] = "SELECT 'receive' AS kind, rd.doc_no, DATE(rd.created_at) AS date, rd.created_at AS at, u.full_name AS user_name, ri.qty,
                             rd.cancelled_at, cu.full_name AS cancelled_by, rd.cancel_reason
                      FROM receive_items ri JOIN receive_documents rd ON rd.id = ri.receive_document_id
                      LEFT JOIN users u ON u.id = rd.user_id LEFT JOIN users cu ON cu.id = rd.cancelled_by
                      WHERE ri.product_id = ? AND rd.cancelled_at IS NOT NULL";
            $params[] = $productId;
        }
        if ($has('return_documents', 'cancelled_at')) {
            $sql[] = "SELECT 'return', rd.doc_no, DATE(rd.created_at), rd.created_at, u.full_name, -ri.qty, rd.cancelled_at, cu.full_name, rd.cancel_reason
                      FROM return_items ri JOIN return_documents rd ON rd.id = ri.return_document_id
                      LEFT JOIN users u ON u.id = rd.user_id LEFT JOIN users cu ON cu.id = rd.cancelled_by
                      WHERE ri.product_id = ? AND rd.cancelled_at IS NOT NULL";
            $params[] = $productId;
        }
        if (\App\Services\ProductSplitService::available($this->db)) {
            $sql[] = "SELECT 'split', sp.doc_no, DATE(sp.created_at), sp.created_at, u.full_name, -sp.qty, sp.cancelled_at, cu.full_name, sp.cancel_reason
                      FROM product_splits sp LEFT JOIN users u ON u.id = sp.user_id LEFT JOIN users cu ON cu.id = sp.cancelled_by
                      WHERE sp.source_product_id = ? AND sp.cancelled_at IS NOT NULL";
            $sql[] = "SELECT 'split', sp.doc_no, DATE(sp.created_at), sp.created_at, u.full_name, spi.qty, sp.cancelled_at, cu.full_name, sp.cancel_reason
                      FROM product_split_items spi JOIN product_splits sp ON sp.id = spi.split_id
                      LEFT JOIN users u ON u.id = sp.user_id LEFT JOIN users cu ON cu.id = sp.cancelled_by
                      WHERE spi.product_id = ? AND sp.cancelled_at IS NOT NULL";
            array_push($params, $productId, $productId);
        }
        if (\App\Services\DamageService::available($this->db)) {
            $sql[] = "SELECT 'damage', dg.doc_no, dg.damage_date, dg.created_at, u.full_name, -dg.qty, dg.cancelled_at, cu.full_name, dg.cancel_reason
                      FROM damaged_goods dg LEFT JOIN users u ON u.id = dg.user_id LEFT JOIN users cu ON cu.id = dg.cancelled_by
                      WHERE dg.product_id = ? AND dg.cancelled_at IS NOT NULL";
            $params[] = $productId;
        }
        if ($sql) {
            $stmt = $this->db->prepare(implode(' UNION ALL ', $sql));
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) {
                $parts[] = ['kind' => $r['kind'], 'doc_no' => $r['doc_no'], 'date' => $r['date'], 'at' => $r['at'], 'user_name' => $r['user_name'],
                    'qty' => (int) $r['qty'], 'cancelled_at' => $r['cancelled_at'], 'cancelled_by' => $r['cancelled_by'], 'cancel_reason' => $r['cancel_reason']];
            }
        }
        return $parts;
    }

    /**
     * Every purchase of a store product (cancelled, drafts and orders not delivered left out),
     * newest first, under the supplier's main name, for comparing what each supplier charged.
     */
    public function purchasePrices(int $productId): array
    {
        [$join, $main] = (new SupplierService($this->db))->sql('pd.supplier_name', 'pd.supplier_tax_id');
        $stmt = $this->db->prepare(
            "SELECT pd.doc_no, COALESCE(pd.receipt_date, DATE(pd.created_at)) AS date, {$main} AS supplier, pd.supplier_name AS typed_name,
                    pi.qty, pi.pack_size, pi.units, pi.unit_cost, pi.piece_cost
             FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id {$join}
             WHERE pi.product_id = ? AND pi.item_type = 'stock' AND pd.status = 'active'
             ORDER BY date DESC, pi.id DESC"
        );
        $stmt->execute([$productId]);
        return array_map(fn ($r) => [
            'doc_no' => $r['doc_no'], 'date' => $r['date'], 'supplier' => (string) $r['supplier'], 'typed_name' => (string) $r['typed_name'],
            'qty' => (int) $r['qty'], 'pack_size' => (int) $r['pack_size'], 'units' => (int) $r['units'],
            'unit_cost' => (float) $r['unit_cost'], 'piece_cost' => (float) $r['piece_cost'],
        ], $stmt->fetchAll());
    }

    /**
     * Price changes of a product from the audit log, newest first. Each sale keeps the price
     * it was sold at (sale_items), so these only tell when the shelf price changed.
     * @return array<int,array{at:string, user_name:?string, old_price:?float, new_price:?float, old_cost:?float, new_cost:?float}>
     */
    private function priceChanges(int $productId): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.id, a.user_id, a.created_at, a.old_data, a.new_data, u.full_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.table_name = 'products' AND a.record_id = ? AND a.action = 'update' AND a.new_data LIKE '%price%'
             ORDER BY a.id DESC LIMIT 50"
        );
        $stmt->execute([$productId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $old = (array) json_decode((string) $r['old_data'], true);
            $new = (array) json_decode((string) $r['new_data'], true);
            $num = fn (array $a, string $k) => isset($a[$k]) ? (float) $a[$k] : null;
            $change = [
                'at' => $r['created_at'], 'user_name' => $r['full_name'],
                'old_price' => $num($old, 'sale_price'), 'new_price' => $num($new, 'sale_price'),
                'old_cost' => $num($old, 'cost_price'), 'new_cost' => $num($new, 'cost_price'),
                'source' => isset($new['source']) ? (string) $new['source'] : null,
                'reason' => isset($new['reason']) ? (string) $new['reason'] : (isset($new['note']) ? (string) $new['note'] : null),
                'promo' => isset($new['on_promo']) && (int) $new['on_promo'] === 1,
                'regular_price' => !empty($new['on_promo']) ? $num($new, 'regular_price') : null,
                // The document behind the change, opened from the history (kind as /inventory/document takes it).
                'doc_kind' => null, 'doc_no' => null,
            ];
            $moved = fn ($a, $b) => $a !== null && $b !== null && abs($a - $b) >= 0.005;
            if (!$moved($change['old_price'], $change['new_price']) && !$moved($change['old_cost'], $change['new_cost'])) {
                continue;
            }
            $out[] = $this->priceSource($change, $r, $old, $new, $productId);
        }
        return $out;
    }

    /**
     * Where a price change came from: the cause kept with it, or (changes recorded before the cause
     * was kept) what the log tells — a purchase with this product saved in the same moment, the
     * stock page's price box, a merge or a split.
     */
    private function priceSource(array $c, array $r, array $old, array $new, int $productId): array
    {
        $note = (string) ($new['note'] ?? '');
        if (preg_match('/^ใบซื้อ\s+(\S+)/u', (string) $c['source'], $m)) {
            return ['doc_kind' => 'purchase', 'doc_no' => $m[1]] + $c;
        }
        if ($c['source'] !== null) {
            return $c;
        }
        if (isset($new['merged_from'])) {
            return ['source' => 'รวมสินค้าซ้ำ'] + $c;
        }
        if (preg_match('/^แยกสินค้า\s+(\S+?):/u', $note, $m)) {
            return ['source' => 'แยกสินค้า ' . $m[1], 'doc_kind' => 'split', 'doc_no' => $m[1]] + $c;
        }
        // Only the price was logged: the price box of the stock page (or a consigned product's prices).
        if (!isset($old['qty_stock']) && (array_keys($old) === ['sale_price'] || isset($old['fee_per_unit']))) {
            return ['source' => isset($old['fee_per_unit']) ? 'แก้ราคาสินค้าฝากขาย' : 'แก้ราคาในหน้าจัดการสต๊อก'] + $c;
        }
        // A purchase holding this product, saved (created, edited or received) right after: its log follows the product's.
        $stmt = $this->db->prepare(
            "SELECT pd.doc_no, pa.action, pa.new_data FROM audit_logs pa JOIN purchase_documents pd ON pd.id = pa.record_id
             WHERE pa.table_name = 'purchase_documents' AND pa.id > ? AND pa.action IN ('create', 'update')
               AND pa.created_at BETWEEN ? AND ? + INTERVAL 300 SECOND
               AND (EXISTS (SELECT 1 FROM purchase_items pi WHERE pi.purchase_document_id = pd.id AND pi.product_id = ?)
                    OR pd.cancelled_items LIKE ? OR pd.cancelled_items LIKE ?)
             ORDER BY pa.id ASC LIMIT 1"
        );
        $stmt->execute([(int) $r['id'], $r['created_at'], $r['created_at'], $productId, "%\"product_id\":{$productId},%", "%\"product_id\":\"{$productId}\"%"]);
        if ($d = $stmt->fetch()) {
            $data = (array) json_decode((string) $d['new_data'], true);
            $what = $d['action'] === 'create' ? 'บันทึกใบซื้อ'
                : (($data['status'] ?? '') === 'active' ? 'รับสินค้าตามใบสั่งซื้อ' : 'แก้ไขใบซื้อ (ใช้ราคาขายตามใบซื้อนั้น)');
            return ['source' => 'ใบซื้อ ' . $d['doc_no'], 'doc_kind' => 'purchase', 'doc_no' => $d['doc_no'], 'reason' => $c['reason'] ?? $what] + $c;
        }
        // Consigned goods: a receiving document saved by the same person right after.
        $stmt = $this->db->prepare(
            "SELECT new_data FROM audit_logs
             WHERE id > ? AND table_name = 'receive_documents' AND action = 'create'
               AND user_id <=> ? AND created_at BETWEEN ? AND ? + INTERVAL 300 SECOND
             ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([(int) $r['id'], $r['user_id'], $r['created_at'], $r['created_at']]);
        if (($row = $stmt->fetchColumn()) !== false && ($no = (string) (json_decode((string) $row, true)['doc_no'] ?? '')) !== '') {
            return ['source' => 'ใบรับฝาก ' . $no, 'doc_kind' => 'receive', 'doc_no' => $no] + $c;
        }
        return $c;
    }

    /**
     * Store products whose stock figures may be off because documents were keyed in late
     * or out of order. Per product, the balance is run twice: by the date on each document
     * and by the moment it was recorded. Flags:
     *  - duplicate: a POS import created the product with stock (no receiving document) for
     *    goods a purchase dated on or before that day had already brought in;
     *  - negative: sold more than had come in by that document date (a purchase missing or
     *    dated wrong);
     *  - reordered: recorded in that order the stock went below 0, by document date it did not
     *    (late entries swapped the order);
     *  - gap: the documents do not add up to the stock in the system.
     */
    public function orderCheck(): array
    {
        $products = $this->db->query(
            'SELECT id, name, qty_received, qty_stock, created_at FROM products WHERE consignor_id IS NULL ORDER BY name'
        )->fetchAll();
        $byProduct = [];
        foreach ($this->stockEvents(null) as $e) {
            $byProduct[(int) $e['product_id']][] = $e;
        }

        $out = [];
        foreach ($products as $p) {
            $events = $byProduct[(int) $p['id']] ?? [];
            $created = substr((string) $p['created_at'], 0, 10);
            $docIn = 0;
            $inByCreation = 0;
            $late = 0;
            foreach ($events as $e) {
                if ($e['kind'] === 'purchase' || $e['kind'] === 'receive' || ($e['kind'] === 'split' && (int) $e['qty'] > 0)) {
                    $docIn += (int) $e['qty'];
                    if ((string) $e['date'] <= $created) {
                        $inByCreation += (int) $e['qty'];
                    }
                }
                if ($e['at'] !== null && substr((string) $e['at'], 0, 10) > (string) $e['date']) {
                    $late++;
                }
            }
            $opening = (int) $p['qty_received'] - $docIn;

            // Document order (as stockEvents returns it), then recording order.
            $minDoc = null;
            $balance = $opening;
            foreach ($events as $e) {
                $balance = $e['kind'] === 'count' ? (int) $e['counted_qty'] : $balance + (int) $e['qty'];
                if ($balance < 0 && ($minDoc === null || $balance < $minDoc['balance'])) {
                    $minDoc = ['balance' => $balance, 'date' => $e['date'], 'doc_no' => $e['doc_no']];
                }
            }
            $docEnd = $balance;
            $byTime = $events;
            usort($byTime, fn ($a, $b) => [(string) $a['at'], $a['ord'], $a['rid']] <=> [(string) $b['at'], $b['ord'], $b['rid']]);
            $minRec = null;
            $balance = $opening;
            foreach ($byTime as $e) {
                $balance = $e['kind'] === 'count' ? (int) $e['counted_qty'] : $balance + (int) $e['qty'];
                if ($balance < 0 && ($minRec === null || $balance < $minRec['balance'])) {
                    $minRec = ['balance' => $balance, 'at' => $e['at'], 'doc_no' => $e['doc_no']];
                }
            }

            $issues = [];
            $dup = $opening > 0 ? min($opening, $inByCreation) : 0;
            if ($dup > 0) {
                $issues[] = ['code' => 'duplicate', 'qty' => $dup, 'text' => sprintf(
                    'รับเข้าซ้ำได้ %d ชิ้น: สินค้าถูกสร้างจากการนำเข้า POS พร้อมสต๊อก %d ชิ้น แต่มีใบซื้อ/รับเข้าลงวันที่ก่อนหรือวันเดียวกันที่บันทึกภายหลัง',
                    $dup, $opening
                )];
            }
            if ($minDoc !== null) {
                $issues[] = ['code' => 'negative', 'qty' => $minDoc['balance'], 'date' => $minDoc['date'], 'doc_no' => $minDoc['doc_no'], 'text' => sprintf(
                    'ตามวันที่เอกสาร ขายเกินกว่าที่รับเข้า (คงเหลือติดลบ %d ที่ %s) อาจขาดใบซื้อหรือลงวันที่ใบซื้อผิด',
                    $minDoc['balance'], $minDoc['doc_no']
                )];
            } elseif ($minRec !== null) {
                $issues[] = ['code' => 'reordered', 'qty' => $minRec['balance'], 'doc_no' => $minRec['doc_no'], 'text' => sprintf(
                    'บันทึกสลับลำดับ: ตามลำดับที่บันทึกสต๊อกเคยติดลบ %d (ที่ %s) แต่ตามวันที่เอกสารไม่ติดลบ — มีเอกสารรับเข้าที่บันทึกล่าช้า',
                    $minRec['balance'], $minRec['doc_no']
                )];
            }
            $gap = (int) $p['qty_stock'] - $docEnd;
            if ($gap !== 0) {
                $issues[] = ['code' => 'gap', 'qty' => $gap, 'text' => sprintf(
                    'ยอดคงเหลือในระบบ %d ไม่ตรงกับยอดตามเอกสาร %d (ต่าง %+d) โดยไม่มีเอกสารอ้างอิง',
                    (int) $p['qty_stock'], $docEnd, $gap
                )];
            }
            if ($issues) {
                $out[] = [
                    'id' => (int) $p['id'], 'name' => $p['name'], 'qty_stock' => (int) $p['qty_stock'], 'doc_balance' => $docEnd,
                    'late_count' => $late, 'issues' => $issues,
                ];
            }
        }
        return $out;
    }

    /**
     * For building purchase orders from stock: per product, every supplier it was bought from
     * with that supplier's latest line (pack, cost), cheapest piece cost first, plus the
     * orders still waiting for delivery that already include it.
     * @param int[] $ids store product ids
     */
    public function supplierOptions(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, name, barcode, qty_stock, cost_price, sale_price FROM products WHERE consignor_id IS NULL AND id IN ($in) ORDER BY name");
        $stmt->execute($ids);
        $products = [];
        foreach ($stmt->fetchAll() as $p) {
            $products[(int) $p['id']] = $p + ['suppliers' => [], 'pending' => []];
        }

        // One supplier per main name: names merged as aliases count as the same supplier (migration 0074).
        $suppliers = new SupplierService($this->db);
        [$join, $main, $tax] = $suppliers->sql('pd.supplier_name', 'pd.supplier_tax_id');
        $stmt = $this->db->prepare(
            "SELECT * FROM (
                SELECT pi.product_id, {$main} AS supplier_name, {$tax} AS supplier_tax_id, pi.receipt_name, pi.barcode, pi.qty, pi.pack_size,
                       pi.unit_cost, pi.piece_cost, pd.doc_no, COALESCE(pd.receipt_date, DATE(pd.created_at)) AS last_date,
                       COUNT(*) OVER (PARTITION BY pi.product_id, {$main}) AS times,
                       MIN(pi.piece_cost) OVER (PARTITION BY pi.product_id, {$main}) AS min_piece_cost,
                       ROW_NUMBER() OVER (PARTITION BY pi.product_id, {$main}
                                          ORDER BY COALESCE(pd.receipt_date, DATE(pd.created_at)) DESC, pi.id DESC) AS rn
                FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id {$join}
                WHERE pi.product_id IN ($in) AND pi.item_type = 'stock' AND pd.status = 'active' AND pi.piece_cost > 0
             ) x WHERE rn = 1 ORDER BY product_id, piece_cost ASC, last_date DESC"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $s) {
            $pid = (int) $s['product_id'];
            if (isset($products[$pid])) {
                $products[$pid]['suppliers'][] = [
                    'supplier_name' => $s['supplier_name'], 'supplier_tax_id' => $s['supplier_tax_id'], 'receipt_name' => $s['receipt_name'],
                    'barcode' => $s['barcode'], 'qty' => (int) $s['qty'], 'pack_size' => (int) $s['pack_size'],
                    'unit_cost' => (float) $s['unit_cost'], 'piece_cost' => (float) $s['piece_cost'],
                    'doc_no' => $s['doc_no'], 'last_date' => $s['last_date'], 'times' => (int) $s['times'],
                    'min_piece_cost' => (float) $s['min_piece_cost'],
                ];
            }
        }

        // Draft orders and orders not delivered yet (migrations 0041/0042) that already list the product.
        try {
            $orders = $this->db->query("SELECT doc_no, supplier_name, status, order_items FROM purchase_documents WHERE status IN ('draft', 'ordered')")->fetchAll();
        } catch (\PDOException $e) {
            $orders = [];
        }
        foreach ($orders as $o) {
            foreach ((array) (json_decode((string) $o['order_items'], true) ?: []) as $line) {
                $pid = (int) ($line['product_id'] ?? 0);
                if (isset($products[$pid])) {
                    $products[$pid]['pending'][] = ['doc_no' => $o['doc_no'], 'supplier_name' => $suppliers->mainName((string) $o['supplier_name']), 'status' => $o['status']];
                }
            }
        }
        return array_values($products);
    }

    /**
     * Store products running low (this many pieces or fewer, not discontinued), each with the
     * suppliers it was bought from (cheapest per piece first, see supplierOptions), what sold in
     * the last 30 days and orders already waiting for it — for the purchase list page.
     */
    public function lowStock(int $threshold): array
    {
        $discontinued = \App\Support\Db::hasColumn($this->db, 'products', 'discontinued_at') ? ' AND p.discontinued_at IS NULL' : ''; // migration 0067
        $stmt = $this->db->prepare(
            "SELECT p.id,
                    (SELECT COALESCE(SUM(si.qty), 0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                      WHERE si.product_id = p.id AND s.business_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS sold_30d,
                    (SELECT MAX(s.business_date) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.product_id = p.id) AS last_sold
             FROM products p WHERE p.consignor_id IS NULL AND p.qty_stock <= ?{$discontinued}"
        );
        $stmt->execute([$threshold]);
        $extra = [];
        foreach ($stmt->fetchAll() as $r) {
            $extra[(int) $r['id']] = ['sold_30d' => (float) $r['sold_30d'], 'last_sold' => $r['last_sold']];
        }
        if (!$extra) {
            return [];
        }
        $rows = $this->supplierOptions(array_keys($extra));
        foreach ($rows as &$r) {
            $r += $extra[(int) $r['id']];
        }
        unset($r);
        // Selling fastest first, then the emptiest.
        usort($rows, fn ($a, $b) => [$b['sold_30d'], (float) $a['qty_stock'], $a['name']] <=> [$a['sold_30d'], (float) $b['qty_stock'], $b['name']]);
        return $rows;
    }

    /**
     * The document behind a stock movement, shaped for the drill-down dialog
     * ({title, note, sections: [{title, columns, rows}]}). Null when it does not exist.
     */
    public function document(string $kind, string $docNo): ?array
    {
        $one = function (string $sql) use ($docNo) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$docNo]);
            return $stmt->fetch() ?: null;
        };
        $all = function (string $sql, int $id) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$id]);
            return $stmt->fetchAll();
        };
        $date = fn ($d) => $d ? date('d/m/', strtotime((string) $d)) . ((int) date('Y', strtotime((string) $d)) + 543) : '-';
        $dateTime = fn ($d) => $d ? $date($d) . ' ' . date('H:i', strtotime((string) $d)) : '-';

        switch ($kind) {
            case 'purchase':
                $d = $one("SELECT pd.*, u.full_name FROM purchase_documents pd LEFT JOIN users u ON u.id = pd.user_id WHERE pd.doc_no = ?");
                if (!$d) {
                    return null;
                }
                $items = $all(
                    "SELECT COALESCE(p.name, pi.receipt_name) AS product, pi.receipt_name, IF(pi.item_type = 'supply', 'วัสดุใช้ในร้าน', 'สินค้า') AS type,
                            pi.qty, pi.pack_size, pi.units, pi.piece_cost, pi.line_total, pi.sale_price
                     FROM purchase_items pi LEFT JOIN products p ON p.id = pi.product_id WHERE pi.purchase_document_id = ? ORDER BY pi.id",
                    (int) $d['id']
                );
                return [
                    'title' => 'ใบซื้อสินค้าเข้าร้าน ' . $d['doc_no'],
                    'link' => 'purchase',
                    'note' => sprintf(
                        'ผู้ขาย %s · ใบเสร็จเลขที่ %s ลงวันที่ %s · ยอดรวม ฿%s (ส่วนลด ฿%s, VAT ฿%s) · บันทึกโดย %s เมื่อ %s%s%s',
                        $d['supplier_name'], $d['receipt_no'] ?: '-', $date($d['receipt_date']), number_format((float) $d['total'], 2),
                        number_format((float) $d['discount'], 2), number_format((float) $d['vat'], 2), $d['full_name'] ?? '-', $dateTime($d['created_at']),
                        ($d['status'] ?? 'active') === 'cancelled' ? ' · ยกเลิกแล้ว' : '', $d['note'] ? ' · หมายเหตุ: ' . $d['note'] : ''
                    ),
                    'sections' => [['title' => 'รายการในใบเสร็จ', 'columns' => [
                        ['product', 'สินค้า', 'text'], ['receipt_name', 'ชื่อในใบเสร็จ', 'text'], ['type', 'ประเภท', 'text'], ['qty', 'จำนวน', 'qty'],
                        ['pack_size', 'ชิ้น/แพ็ค', 'qty'], ['units', 'รวมชิ้น', 'qty'], ['piece_cost', 'ทุน/ชิ้น', 'money'], ['line_total', 'รวมเงิน', 'money'],
                    ], 'rows' => $items]],
                ];

            case 'sale':
            case 'pos':
                $d = $one(
                    "SELECT s.*, u.full_name, pim.source_label FROM sales s LEFT JOIN users u ON u.id = s.cashier_id
                     LEFT JOIN pos_imports pim ON pim.sale_id = s.id WHERE s.doc_no = ?"
                );
                if (!$d) {
                    return null;
                }
                $items = $all(
                    "SELECT p.name AS product, COALESCE(c.name, 'สินค้าของร้าน') AS owner, si.qty, si.unit_price,
                            ROUND(si.qty * si.unit_price, 2) AS amount, si.cost_amount
                     FROM sale_items si JOIN products p ON p.id = si.product_id LEFT JOIN consignors c ON c.id = si.consignor_id
                     WHERE si.sale_id = ? ORDER BY si.id",
                    (int) $d['id']
                );
                $pay = $all('SELECT method, amount FROM sale_payments WHERE sale_id = ? ORDER BY id', (int) $d['id']);
                $methods = ['cash' => 'เงินสด', 'transfer' => 'โอน', 'qr' => 'QR', 'card' => 'บัตร'];
                return [
                    'title' => ($d['source_label'] !== null || $kind === 'pos' ? 'ยอดขายนำเข้าจาก POS ' : 'บิลขาย ') . $d['doc_no'],
                    'link' => $kind === 'pos' ? 'posimport' : null,
                    'note' => sprintf(
                        'วันที่ขาย %s · เวลาขาย %s · ยอดรวม ฿%s%s · บันทึกโดย %s เมื่อ %s%s%s',
                        $date($d['business_date'] ?? $d['sale_datetime']), $dateTime($d['sale_datetime']), number_format((float) $d['total_value'], 2),
                        $pay ? ' (' . implode(', ', array_map(fn ($p) => ($methods[$p['method']] ?? $p['method']) . ' ฿' . number_format((float) $p['amount'], 2), $pay)) . ')' : '',
                        $d['full_name'] ?? '-', $dateTime($d['created_at']),
                        !empty($d['after_hours']) ? ' · ขายหลังปิดร้าน' : '', $d['source_label'] ? ' · ไฟล์: ' . $d['source_label'] : ''
                    ),
                    'sections' => [['title' => 'รายการขาย', 'columns' => [
                        ['product', 'สินค้า', 'text'], ['owner', 'เจ้าของ', 'text'], ['qty', 'จำนวน', 'qty'], ['unit_price', 'ราคา/ชิ้น', 'money'],
                        ['amount', 'รวมเงิน', 'money'], ['cost_amount', 'ต้นทุนขาย', 'money'],
                    ], 'rows' => $items]],
                ];

            case 'receive':
                $d = $one(
                    "SELECT rd.*, u.full_name, c.code, c.name AS consignor FROM receive_documents rd LEFT JOIN users u ON u.id = rd.user_id
                     LEFT JOIN consignors c ON c.id = rd.consignor_id WHERE rd.doc_no = ?"
                );
                if (!$d) {
                    return null;
                }
                return [
                    'title' => 'ใบรับฝากสินค้า ' . $d['doc_no'] . (!empty($d['cancelled_at']) ? ' (ยกเลิกแล้ว)' : ''),
                    'link' => 'receive',
                    'note' => (!empty($d['cancelled_at']) ? 'ยกเลิกเมื่อ ' . $dateTime($d['cancelled_at']) . ' เหตุผล: ' . $d['cancel_reason'] . ' · ' : '') . sprintf(
                        'ผู้ฝาก %s - %s · วันที่รับ %s · รวม %s ชิ้น มูลค่า ฿%s · ค่าทำข้อมูล ฿%s · บันทึกโดย %s',
                        $d['code'], $d['consignor'], $dateTime($d['created_at']), number_format((int) $d['total_qty']),
                        number_format((float) $d['total_value'], 2), number_format((float) $d['listing_fee_total'], 2), $d['full_name'] ?? '-'
                    ),
                    'sections' => [['title' => 'รายการรับฝาก', 'columns' => [
                        ['product', 'สินค้า', 'text'], ['qty', 'จำนวน', 'qty'], ['cost', 'ผู้ฝากได้/ชิ้น', 'money'], ['sale_price', 'ราคาขาย', 'money'],
                        ['fee_per_unit', 'ค่าฝากขาย/ชิ้น', 'money'], ['listing_fee_charged', 'ค่าทำข้อมูล', 'money'],
                    ], 'rows' => $all(
                        'SELECT p.name AS product, ri.qty, ri.cost_price AS cost, ri.sale_price, ri.fee_per_unit, ri.listing_fee_charged
                         FROM receive_items ri JOIN products p ON p.id = ri.product_id WHERE ri.receive_document_id = ? ORDER BY ri.id',
                        (int) $d['id']
                    )]],
                ];

            case 'return':
                $d = $one(
                    "SELECT rd.*, u.full_name, c.code, c.name AS consignor FROM return_documents rd LEFT JOIN users u ON u.id = rd.user_id
                     LEFT JOIN consignors c ON c.id = rd.consignor_id WHERE rd.doc_no = ?"
                );
                if (!$d) {
                    return null;
                }
                return [
                    'title' => 'ใบคืนสินค้าผู้ฝาก ' . $d['doc_no'] . (!empty($d['cancelled_at']) ? ' (ยกเลิกแล้ว)' : ''),
                    'link' => 'ret',
                    'note' => (!empty($d['cancelled_at']) ? 'ยกเลิกเมื่อ ' . $dateTime($d['cancelled_at']) . ' เหตุผล: ' . $d['cancel_reason'] . ' · ' : '') . sprintf(
                        'ผู้ฝาก %s - %s · ผู้รับคืน %s · วันที่คืน %s · รวม %s ชิ้น · บันทึกโดย %s',
                        $d['code'], $d['consignor'], $d['recipient_name'] ?: '-', $dateTime($d['created_at']),
                        number_format((int) $d['total_qty']), $d['full_name'] ?? '-'
                    ),
                    'sections' => [['title' => 'รายการคืน', 'columns' => [
                        ['product', 'สินค้า', 'text'], ['qty', 'จำนวน', 'qty'], ['sale_price', 'ราคาขาย', 'money'], ['amount', 'มูลค่า', 'money'],
                    ], 'rows' => $all(
                        'SELECT p.name AS product, ri.qty, ri.sale_price, ROUND(ri.qty * ri.sale_price, 2) AS amount
                         FROM return_items ri JOIN products p ON p.id = ri.product_id WHERE ri.return_document_id = ? ORDER BY ri.id',
                        (int) $d['id']
                    )]],
                ];

            case 'split':
                if (!\App\Services\ProductSplitService::available($this->db)) {
                    return null;
                }
                $d = $one(
                    "SELECT sp.*, p.name AS source_name, u.full_name, cu.full_name AS canceller FROM product_splits sp
                     JOIN products p ON p.id = sp.source_product_id LEFT JOIN users u ON u.id = sp.user_id
                     LEFT JOIN users cu ON cu.id = sp.cancelled_by WHERE sp.doc_no = ?"
                );
                if (!$d) {
                    return null;
                }
                return [
                    'title' => 'ใบแยกสินค้า ' . $d['doc_no'] . ($d['cancelled_at'] ? ' (ยกเลิกแล้ว)' : ''),
                    'note' => ($d['cancelled_at'] ? 'ยกเลิกเมื่อ ' . $dateTime($d['cancelled_at']) . ' โดย ' . ($d['canceller'] ?? '-') . ' เหตุผล: ' . $d['cancel_reason'] . ' · ' : '') . sprintf(
                        'แยกจาก %s จำนวน %s ชิ้น ต้นทุน ฿%s/ชิ้น · บันทึกโดย %s เมื่อ %s%s',
                        $d['source_name'], number_format((int) $d['qty']), number_format((float) $d['unit_cost'], 2),
                        $d['full_name'] ?? '-', $dateTime($d['created_at']), $d['note'] ? ' · หมายเหตุ: ' . $d['note'] : ''
                    ),
                    'sections' => [['title' => 'สินค้าที่แยกออกมา', 'columns' => [
                        ['product', 'สินค้า', 'text'], ['barcode', 'บาร์โค้ดชิ้น', 'text'], ['created', 'ที่มา', 'text'],
                        ['qty', 'จำนวน', 'qty'], ['unit_cost', 'ทุน/ชิ้น', 'money'], ['value', 'มูลค่าทุน', 'money'],
                    ], 'rows' => $all(
                        "SELECT p.name AS product, p.barcode, IF(i.created_product, 'สร้างใหม่', 'สินค้าที่มีอยู่') AS created, i.qty, i.unit_cost,
                                ROUND(i.qty * i.unit_cost, 2) AS value
                         FROM product_split_items i JOIN products p ON p.id = i.product_id WHERE i.split_id = ? ORDER BY i.id",
                        (int) $d['id']
                    )]],
                ];

            case 'damage':
                if (!\App\Services\DamageService::available($this->db)) {
                    return null;
                }
                $d = $one(
                    "SELECT dg.*, p.name AS product, p.barcode, pd.doc_no AS purchase_doc_no, u.full_name, cu.full_name AS canceller
                     FROM damaged_goods dg JOIN products p ON p.id = dg.product_id
                     LEFT JOIN purchase_documents pd ON pd.id = dg.purchase_document_id
                     LEFT JOIN users u ON u.id = dg.user_id LEFT JOIN users cu ON cu.id = dg.cancelled_by WHERE dg.doc_no = ?"
                );
                if (!$d) {
                    return null;
                }
                $reason = \App\Services\DamageService::REASONS[$d['reason']] ?? $d['reason'];
                return [
                    'title' => 'ใบบันทึกสินค้าเสียหาย ' . $d['doc_no'] . ($d['cancelled_at'] ? ' (ยกเลิกแล้ว)' : ''),
                    'note' => ($d['cancelled_at'] ? 'ยกเลิกเมื่อ ' . $dateTime($d['cancelled_at']) . ' โดย ' . ($d['canceller'] ?? '-') . ' เหตุผล: ' . $d['cancel_reason'] . ' · ' : '') . sprintf(
                        'วันที่พบ %s · สาเหตุ %s%s · บันทึกโดย %s เมื่อ %s%s',
                        $date($d['damage_date']), $reason,
                        $d['purchase_doc_no'] ? ' · มาจากใบซื้อ ' . $d['purchase_doc_no'] . ($d['supplier_name'] ? ' (' . $d['supplier_name'] . ')' : '') : '',
                        $d['full_name'] ?? '-', $dateTime($d['created_at']), $d['note'] ? ' · หมายเหตุ: ' . $d['note'] : ''
                    ),
                    'sections' => [['title' => 'สินค้าที่ตัดสต๊อก', 'columns' => [
                        ['product', 'สินค้า', 'text'], ['barcode', 'บาร์โค้ด', 'text'], ['reason', 'สาเหตุ', 'text'],
                        ['qty', 'จำนวน', 'qty'], ['unit_cost', 'ทุน/ชิ้น', 'money'], ['value', 'มูลค่าทุนที่เสียหาย', 'money'],
                    ], 'rows' => [[
                        'product' => $d['product'], 'barcode' => $d['barcode'], 'reason' => $reason, 'qty' => (int) $d['qty'],
                        'unit_cost' => (float) $d['unit_cost'], 'value' => round((int) $d['qty'] * (float) $d['unit_cost'], 2),
                    ]]]],
                ];

            case 'count':
                $d = $one(
                    "SELECT sc.*, cu.full_name AS creator, au.full_name AS approver FROM stock_counts sc
                     LEFT JOIN users cu ON cu.id = sc.created_by LEFT JOIN users au ON au.id = sc.approved_by WHERE sc.doc_no = ?"
                );
                if (!$d) {
                    return null;
                }
                $items = $all(
                    "SELECT p.name AS product, COALESCE(c.name, 'สินค้าของร้าน') AS owner, sci.system_qty, sci.counted_qty, sci.diff_qty,
                            ROUND(sci.diff_qty * p.cost_price, 2) AS diff_cost, sci.note
                     FROM stock_count_items sci JOIN products p ON p.id = sci.product_id LEFT JOIN consignors c ON c.id = p.consignor_id
                     WHERE sci.stock_count_id = ? AND sci.counted_qty IS NOT NULL ORDER BY (sci.diff_qty <> 0) DESC, p.name",
                    (int) $d['id']
                );
                $statuses = ['draft' => 'กำลังนับ', 'submitted' => 'ส่งรายงานแล้ว', 'approved' => 'อนุมัติแล้ว', 'cancelled' => 'ยกเลิก'];
                return [
                    'title' => 'รอบตรวจนับสต๊อก ' . $d['doc_no'],
                    'note' => sprintf(
                        'วันที่นับ %s · สถานะ %s · เปิดรอบโดย %s · อนุมัติโดย %s เมื่อ %s',
                        $date($d['count_date']), $statuses[$d['status']] ?? $d['status'], $d['creator'] ?? '-', $d['approver'] ?? '-', $dateTime($d['approved_at'])
                    ),
                    'sections' => [
                        ['title' => 'สินค้าที่นับไม่ตรงกับระบบ', 'columns' => [
                            ['product', 'สินค้า', 'text'], ['owner', 'เจ้าของ', 'text'], ['system_qty', 'ในระบบ', 'qty'], ['counted_qty', 'นับได้', 'qty'],
                            ['diff_qty', 'ผลต่าง', 'qty'], ['diff_cost', 'มูลค่าทุนผลต่าง', 'money'], ['note', 'หมายเหตุ', 'text'],
                        ], 'rows' => array_values(array_filter($items, fn ($r) => (int) $r['diff_qty'] !== 0))],
                        ['title' => 'สินค้าที่นับตรง', 'total' => false, 'columns' => [
                            ['product', 'สินค้า', 'text'], ['owner', 'เจ้าของ', 'text'], ['counted_qty', 'นับได้', 'qty'],
                        ], 'rows' => array_values(array_filter($items, fn ($r) => (int) $r['diff_qty'] === 0))],
                    ],
                ];
        }
        return null;
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
        $damage = \App\Services\DamageService::available($this->db)
            ? (float) $this->db->query('SELECT COALESCE(SUM(qty * unit_cost), 0) FROM damaged_goods WHERE cancelled_at IS NULL')->fetchColumn()
            : 0.0;
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
            'damage_cost' => round($damage, 2),
            'net_profit' => round($grossProfit - (float) $bought['supply_cost'] - $wages - $damage, 2),
        ];
    }
}
