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
     * Documents that moved stock: one product, or every store product when null.
     * Stock card in document order: the date printed on each document (a receipt keyed in a
     * week late still sits on its own day), then receipts before issues on the same day.
     * qty is +in / -out; a count's qty is its adjustment (counted - system qty when counted).
     */
    private function stockEvents(?int $productId): array
    {
        $stmt = $this->db->prepare(sprintf(
            "SELECT * FROM (
                SELECT pi.product_id, pd.created_at AS at, COALESCE(pd.receipt_date, DATE(pd.created_at)) AS date, 'purchase' AS kind,
                       pd.doc_no, pd.supplier_name AS party, u.full_name AS user_name, pi.units AS qty,
                       pi.piece_cost AS unit_cost, ROUND(pi.units * pi.piece_cost, 2) AS value, NULL AS sale_price,
                       NULL AS counted_qty, NULL AS system_qty, 1 AS ord, pi.id AS rid
                FROM purchase_items pi
                JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
                LEFT JOIN users u ON u.id = pd.user_id
                WHERE pi.product_id IN (%1$s) AND pi.item_type = 'stock' AND COALESCE(pd.status, 'active') = 'active'
              UNION ALL
                SELECT ri.product_id, rd.created_at, DATE(rd.created_at), 'receive', rd.doc_no, c.name, u.full_name, ri.qty,
                       ri.cost_price, ROUND(ri.qty * ri.cost_price, 2), NULL, NULL, NULL, 1, ri.id
                FROM receive_items ri
                JOIN receive_documents rd ON rd.id = ri.receive_document_id
                LEFT JOIN consignors c ON c.id = rd.consignor_id
                LEFT JOIN users u ON u.id = rd.user_id
                WHERE ri.product_id IN (%1$s)
              UNION ALL
                SELECT si.product_id, s.created_at, COALESCE(s.business_date, DATE(s.sale_datetime)),
                       IF(EXISTS (SELECT 1 FROM pos_imports pim WHERE pim.sale_id = s.id), 'pos', 'sale'),
                       s.doc_no, NULL, u.full_name, -si.qty,
                       IF(si.qty <> 0, ROUND(si.cost_amount / si.qty, 2), NULL), -si.cost_amount, si.unit_price, NULL, NULL, 2, si.id
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                LEFT JOIN users u ON u.id = s.cashier_id
                WHERE si.product_id IN (%1$s)
              UNION ALL
                SELECT ri.product_id, rd.created_at, DATE(rd.created_at), 'return', rd.doc_no, COALESCE(NULLIF(rd.recipient_name, ''), c.name), u.full_name,
                       -ri.qty, NULL, NULL, NULL, NULL, NULL, 2, ri.id
                FROM return_items ri
                JOIN return_documents rd ON rd.id = ri.return_document_id
                LEFT JOIN consignors c ON c.id = rd.consignor_id
                LEFT JOIN users u ON u.id = rd.user_id
                WHERE ri.product_id IN (%1$s)
              UNION ALL
                SELECT sci.product_id, sc.approved_at, sc.count_date, 'count', sc.doc_no, NULL, u.full_name, sci.diff_qty,
                       NULL, NULL, NULL, sci.counted_qty, sci.system_qty, 3, sci.id
                FROM stock_count_items sci
                JOIN stock_counts sc ON sc.id = sci.stock_count_id
                LEFT JOIN users u ON u.id = sc.approved_by
                WHERE sci.product_id IN (%1$s) AND sc.status = 'approved' AND sci.counted_qty IS NOT NULL AND sci.diff_qty <> 0
             ) m ORDER BY product_id, date ASC, ord ASC, at ASC, rid ASC",
            $productId === null ? 'SELECT id FROM products WHERE consignor_id IS NULL' : '?'
        ));
        $stmt->execute($productId === null ? [] : array_fill(0, 5, $productId));
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
        $stmt = $this->db->prepare('SELECT barcode, pack_size FROM product_pack_barcodes WHERE product_id = ? ORDER BY pack_size ASC, id ASC');
        $stmt->execute([$productId]);
        $packs = $stmt->fetchAll();

        $events = $this->stockEvents($productId);

        $cost = (float) $p['cost_price'];
        $row = fn (array $r) => $r + [
            'at' => null, 'date' => null, 'doc_no' => null, 'party' => null, 'user_name' => null,
            'unit_cost' => null, 'sale_price' => null, 'note' => null,
        ];
        $docIn = 0;
        foreach ($events as $e) {
            if ($e['kind'] === 'purchase' || $e['kind'] === 'receive') {
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
        foreach ($events as $e) {
            $qty = (int) $e['qty'];
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
        }
        $gap = (int) $p['qty_stock'] - $balance;
        if ($gap !== 0) {
            $balance += $gap;
            $rows[] = $row([
                'date' => date('Y-m-d'), 'kind' => 'adjust', 'qty' => $gap, 'unit_cost' => $cost, 'value' => round($gap * $cost, 2),
                'balance' => $balance, 'note' => 'ส่วนต่างที่ไม่มีเอกสารอ้างอิง ปรับให้ตรงกับยอดคงเหลือในระบบ',
            ]);
        }
        foreach ($rows as &$r) {
            $r['qty_in'] = max($r['qty'], 0);
            $r['qty_out'] = max(-$r['qty'], 0);
            // Keyed in on a later day than the document's own date.
            $r['late'] = $r['at'] !== null && $r['kind'] !== 'opening' && substr((string) $r['at'], 0, 10) > (string) $r['date'];
        }
        unset($r);

        // Stock card summary: opening + received - sold - returned +/- adjustments = closing (qty and cost).
        $totals = [];
        foreach (['opening', 'received', 'sold', 'returned', 'adjusted'] as $k) {
            $totals[$k] = ['qty' => 0, 'value' => 0.0];
        }
        $bucket = ['opening' => 'opening', 'purchase' => 'received', 'receive' => 'received', 'sale' => 'sold', 'pos' => 'sold',
                   'return' => 'returned', 'count' => 'adjusted', 'adjust' => 'adjusted'];
        foreach ($rows as $r) {
            $k = $bucket[$r['kind']];
            $sign = in_array($k, ['sold', 'returned'], true) ? -1 : 1;
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
        ];
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
                if ($e['kind'] === 'purchase' || $e['kind'] === 'receive') {
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
                $balance += (int) $e['qty'];
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
                $balance += (int) $e['qty'];
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
                    'title' => 'ใบรับฝากสินค้า ' . $d['doc_no'],
                    'link' => 'receive',
                    'note' => sprintf(
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
                    'title' => 'ใบคืนสินค้าผู้ฝาก ' . $d['doc_no'],
                    'link' => 'ret',
                    'note' => sprintf(
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
