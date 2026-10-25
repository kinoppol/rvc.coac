<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepo;
use PDO;

/**
 * Purchases of store-owned goods (ใบซื้อสินค้าเข้าร้าน). Each line either
 * restocks an existing store product (matched by id, barcode or exact name)
 * or creates a new one with consignor_id = NULL. Receipt prices are the cost.
 */
class PurchaseService
{
    public function __construct(
        private PDO $db,
        private ProductRepo $products,
        private SettingsService $settings,
        private DocumentNumberService $docs,
        private AttachmentService $attachments,
        private AuditLogger $audit
    ) {
    }

    public function markupPercent(): float
    {
        return (float) ($this->settings->get('purchase_markup_percent') ?? 20);
    }

    /** Default selling price: cost + markup, rounded like consignment prices. */
    public function suggestedPrice(float $unitCost): float
    {
        $raw = $unitCost * (1 + $this->markupPercent() / 100);
        return match ($this->settings->get('rounding_mode') ?? 'whole_baht') {
            'quarter_baht' => round(ceil(($raw - 1e-9) * 4) / 4, 2),
            'none' => round($raw, 2),
            default => (float) ceil($raw - 1e-9),
        };
    }

    /**
     * Finds the store-owned product a receipt line refers to: explicit id, then
     * barcode (piece barcode, a known pack barcode, or one seen on an earlier
     * purchase of it), then
     * per-piece product name, then the same receipt wording as before.
     */
    public function matchProduct(?int $productId, ?string $barcode, string $productName, string $receiptName = ''): ?array
    {
        $first = function (string $sql, array $params): ?array {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch() ?: null;
        };
        if ($productId && ($row = $first('SELECT * FROM products WHERE id = ? AND consignor_id IS NULL', [$productId]))) {
            return $row;
        }
        $barcode = trim((string) $barcode);
        if ($barcode !== '') {
            $row = $first('SELECT * FROM products WHERE barcode = ? AND consignor_id IS NULL ORDER BY id DESC LIMIT 1', [$barcode])
                ?? $this->products->findByPackBarcode($barcode)
                ?? $first(
                    'SELECT p.* FROM purchase_items pi JOIN products p ON p.id = pi.product_id
                     WHERE pi.barcode = ? AND p.consignor_id IS NULL ORDER BY pi.id DESC LIMIT 1',
                    [$barcode]
                );
            if ($row) {
                return $row;
            }
        }
        foreach (array_unique(array_filter([trim($productName), trim($receiptName)])) as $name) {
            $row = $first('SELECT * FROM products WHERE name = ? AND consignor_id IS NULL ORDER BY id DESC LIMIT 1', [$name])
                ?? $first(
                    'SELECT p.* FROM purchase_items pi JOIN products p ON p.id = pi.product_id
                     WHERE pi.receipt_name = ? AND p.consignor_id IS NULL ORDER BY pi.id DESC LIMIT 1',
                    [$name]
                );
            if ($row) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Whether this receipt line was recorded as a business-use supply last
     * time (same barcode or wording), so the same choice is suggested again.
     */
    public function previousType(?string $barcode, string $receiptName, string $productName): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT item_type FROM purchase_items
             WHERE (barcode IS NOT NULL AND barcode = ?) OR receipt_name = ? OR receipt_name = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([trim((string) $barcode), trim($receiptName), trim($productName)]);
        $type = $stmt->fetchColumn();
        return $type === false ? null : (string) $type;
    }

    /**
     * Normalises one receipt line. qty/unit_cost are as printed (packs); goods
     * bought by the pack are stocked, costed and priced per piece.
     */
    private function line(array $item): array
    {
        $receiptName = trim((string) ($item['name'] ?? ''));
        [$guessName, $guessPack] = ReceiptExtractor::splitPack($receiptName);
        $barcode = trim((string) ($item['barcode'] ?? ''));
        $pack = isset($item['pack_size']) && (int) $item['pack_size'] > 1 ? min((int) $item['pack_size'], 1000) : $guessPack;
        // A known pack barcode tells how many pieces are in it, even when the receipt does not.
        if ($pack <= 1 && $barcode !== '' && ($known = $this->products->findByPackBarcode($barcode))) {
            $pack = (int) $known['pack_size'];
        }
        $productName = trim((string) ($item['product_name'] ?? ''));
        if ($productName === '') {
            $productName = $pack > 1 ? $guessName : $receiptName;
        }
        $qty = (int) ($item['qty'] ?? 0);
        $packCost = 0.0;
        if (isset($item['unit_cost']) && (float) $item['unit_cost'] > 0) {
            $packCost = round((float) $item['unit_cost'], 2);
        } elseif (isset($item['line_total']) && (float) $item['line_total'] > 0 && $qty > 0) {
            $packCost = round((float) $item['line_total'] / $qty, 2);
        }
        $lineTotal = isset($item['line_total']) && (float) $item['line_total'] > 0
            ? round((float) $item['line_total'], 2)
            : round($packCost * max($qty, 0), 2);
        $units = max($qty, 0) * $pack;
        $type = $item['item_type'] ?? null;
        return [
            // 'supply' = bought for use in the business: cost only, never stocked or priced.
            'item_type' => in_array($type, ['stock', 'supply'], true) ? $type : null,
            'receipt_name' => $receiptName !== '' ? $receiptName : $productName,
            'product_name' => $productName,
            'barcode' => $barcode !== '' ? $barcode : null,
            'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : null,
            // The user said this line is not any existing product: skip matching and create one.
            'new_product' => !empty($item['new_product']),
            'qty' => $qty,
            'pack_size' => $pack,
            'units' => $units,
            'pack_cost' => $packCost,
            'line_total' => $lineTotal,
            // Line total already reflects per-line discounts.
            'piece_cost' => $units > 0 ? round($lineTotal / $units, 2) : 0.0,
            'sale_price' => isset($item['sale_price']) && (float) $item['sale_price'] > 0 ? round((float) $item['sale_price'], 2) : null,
        ];
    }

    /** Preview for the review form: which lines restock existing goods, and suggested per-piece prices. */
    public function preview(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $l = $this->line((array) $item);
            $previous = $this->previousType($l['barcode'], $l['receipt_name'], $l['product_name']);
            // Goods are for sale unless the user marks the line; previous_type is only a hint.
            $type = $l['item_type'] ?? 'stock';
            $match = $type === 'stock' && !$l['new_product'] ? $this->matchProduct($l['product_id'], $l['barcode'], $l['product_name'], $l['receipt_name']) : null;
            $out[] = [
                'item_type' => $type,
                'previous_type' => $previous,
                'match' => $match ? [
                    'product_id' => (int) $match['id'], 'name' => $match['name'],
                    'sale_price' => (float) $match['sale_price'], 'qty_stock' => (int) $match['qty_stock'],
                    'cost_price' => (float) $match['cost_price'],
                ] : null,
                'pack_size' => $l['pack_size'],
                'units' => $l['units'],
                'piece_cost' => $l['piece_cost'],
                'suggested_price' => $l['piece_cost'] > 0 ? $this->suggestedPrice($l['piece_cost']) : 0,
            ];
        }
        return $out;
    }

    /**
     * @param array $header supplier_name, supplier_tax_id, receipt_no, receipt_date, discount, vat, total, note
     * @param array<int,array{name:string,product_name?:string,qty:int,pack_size?:int,unit_cost?:float,line_total?:float,sale_price?:float,barcode?:string,product_id?:int}> $items
     *        sale_price is per piece; qty/unit_cost are per pack as printed on the receipt
     * @param array[] $attachments rows from AttachmentService::ownedBy()
     */
    public function create(array $header, array $items, array $attachments, string $source, int $userId, ?string $ip): array
    {
        [$h, $lines] = $this->validate($header, $items);

        $this->db->beginTransaction();
        try {
            $docNo = $this->docs->next('PO', 'purchase_documents', 'doc_no');
            $ins = $this->db->prepare(
                'INSERT INTO purchase_documents
                    (doc_no, supplier_name, supplier_tax_id, receipt_no, receipt_date, subtotal, discount, vat, total, total_qty, note, source, user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $ins->execute([
                $docNo, $h['supplier_name'], $h['supplier_tax_id'], $h['receipt_no'], $h['receipt_date'],
                $h['subtotal'], $h['discount'], $h['vat'], $h['total'], $h['total_qty'], $h['note'],
                $source === 'ai' ? 'ai' : 'manual',
                $userId,
            ]);
            $docId = (int) $this->db->lastInsertId();
            $this->saveFunding($docId, $h['funding']);
            $result = $this->applyLines($docId, $lines, [], $userId, $ip);

            $this->attachments->linkToPurchase($attachments, $docId);
            $this->audit->log('purchase_documents', $docId, 'create', null, [
                'doc_no' => $docNo, 'supplier_name' => $h['supplier_name'], 'total' => $h['total'], 'items' => count($lines), 'source' => $source,
            ], $userId, $ip);

            $this->db->commit();
            return ['id' => $docId, 'doc_no' => $docNo, 'subtotal' => $h['subtotal'], 'supply_total' => $this->supplyTotal($lines), 'total' => $h['total'], 'items' => $result];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Goods ordered from a supplier who delivers later: saved with its lines as entered
     * (order_items) and no stock or cost change until the delivery is confirmed.
     * $draft: not placed with the supplier yet (items may still be added; placeOrder() later).
     */
    public function createOrder(array $header, array $items, array $attachments, string $source, int $userId, ?string $ip, bool $draft = false): array
    {
        $status = $draft ? 'draft' : 'ordered';
        [$h, $lines] = $this->validate($header, $items);

        $this->db->beginTransaction();
        try {
            $docNo = $this->docs->next('PO', 'purchase_documents', 'doc_no');
            $this->db->prepare(
                "INSERT INTO purchase_documents
                    (doc_no, supplier_name, supplier_tax_id, receipt_no, receipt_date, subtotal, discount, vat, total, total_qty, note, source,
                     status, order_items, ordered_at, ordered_by, user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $docNo, $h['supplier_name'], $h['supplier_tax_id'], $h['receipt_no'], $h['receipt_date'],
                $h['subtotal'], $h['discount'], $h['vat'], $h['total'], $h['total_qty'], $h['note'],
                $source === 'ai' ? 'ai' : 'manual', $status, json_encode(array_values($items), JSON_UNESCAPED_UNICODE),
                $draft ? null : date('Y-m-d H:i:s'), $userId, $userId,
            ]);
            $docId = (int) $this->db->lastInsertId();
            $this->saveFunding($docId, $h['funding']);
            $this->attachments->linkToPurchase($attachments, $docId);
            $this->audit->log('purchase_documents', $docId, 'create', null, [
                'doc_no' => $docNo, 'status' => $status, 'supplier_name' => $h['supplier_name'], 'total' => $h['total'], 'items' => count($lines),
            ], $userId, $ip);
            $this->db->commit();
            return ['id' => $docId, 'doc_no' => $docNo, 'ordered' => true, 'status' => $status, 'total' => $h['total'], 'items' => count($lines), 'total_qty' => $h['total_qty']];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Changes an order that has not been delivered yet (no stock is involved). */
    private function updateOrder(array $doc, array $h, array $items, array $keepTokens, array $newAttachments, int $userId, ?string $ip): array
    {
        $this->db->prepare(
            'UPDATE purchase_documents SET supplier_name = ?, supplier_tax_id = ?, receipt_no = ?, receipt_date = ?,
                subtotal = ?, discount = ?, vat = ?, total = ?, total_qty = ?, note = ?, order_items = ? WHERE id = ?'
        )->execute([
            $h['supplier_name'], $h['supplier_tax_id'], $h['receipt_no'], $h['receipt_date'], $h['subtotal'], $h['discount'],
            $h['vat'], $h['total'], $h['total_qty'], $h['note'], json_encode(array_values($items), JSON_UNESCAPED_UNICODE), $doc['id'],
        ]);
        $this->saveFunding((int) $doc['id'], $h['funding']);
        $this->relinkAttachments((int) $doc['id'], $keepTokens, $newAttachments);
        $this->audit->log('purchase_documents', (int) $doc['id'], 'update',
            ['doc_no' => $doc['doc_no'], 'status' => $doc['status'], 'total' => (float) $doc['total']],
            ['doc_no' => $doc['doc_no'], 'status' => $doc['status'], 'total' => $h['total'], 'items' => count($items)], $userId, $ip);
        return ['id' => (int) $doc['id'], 'doc_no' => $doc['doc_no'], 'ordered' => true, 'status' => $doc['status'], 'total' => $h['total'], 'items' => count($items), 'total_qty' => $h['total_qty']];
    }

    /** The supplier's latest draft order, to add more items to instead of starting another. */
    public function draftFor(string $supplierName): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM purchase_documents WHERE status = 'draft' AND supplier_name = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([trim($supplierName)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Adds lines to a draft order: a product already on it (same pack) gets the quantity
     * added and the new cost; anything else is appended. Totals are worked out again.
     */
    public function appendToDraft(int $id, array $items, int $userId, ?string $ip): array
    {
        $this->db->beginTransaction();
        try {
            [$doc] = $this->lockActive($id, 'เพิ่มรายการ');
            if ($doc['status'] !== 'draft') {
                throw new \RuntimeException("ใบสั่งซื้อ {$doc['doc_no']} ไม่ใช่ร่างแล้ว เพิ่มรายการไม่ได้");
            }
            $stmt = $this->db->prepare('SELECT order_items FROM purchase_documents WHERE id = ?');
            $stmt->execute([$id]);
            $lines = json_decode((string) $stmt->fetchColumn(), true) ?: [];
            foreach ($items as $it) {
                $at = null;
                foreach ($lines as $k => $l) {
                    if (!empty($it['product_id']) && (int) ($l['product_id'] ?? 0) === (int) $it['product_id']
                        && (int) ($l['pack_size'] ?? 1) === (int) ($it['pack_size'] ?? 1)) {
                        $at = $k;
                        break;
                    }
                }
                if ($at === null) {
                    $lines[] = $it;
                    continue;
                }
                $lines[$at]['qty'] = (int) $lines[$at]['qty'] + (int) $it['qty'];
                $lines[$at]['unit_cost'] = (float) $it['unit_cost'];
                $lines[$at]['line_total'] = round($lines[$at]['qty'] * (float) $it['unit_cost'], 2);
            }
            $header = array_intersect_key($doc, array_flip(['supplier_name', 'supplier_tax_id', 'receipt_no', 'receipt_date', 'discount', 'vat', 'note', 'funding']));
            [$h] = $this->validate(['total' => 0] + $header, $lines);
            $keep = array_column($this->attachments->forPurchase($id), 'token');
            $result = $this->updateOrder($doc, $h, $lines, $keep, [], $userId, $ip);
            $this->db->commit();
            return $result + ['merged' => true];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** A draft order was sent to the supplier: it now waits for delivery. */
    public function placeOrder(int $id, int $userId, ?string $ip): array
    {
        $this->db->beginTransaction();
        try {
            [$doc] = $this->lockActive($id, 'ยืนยันสั่งซื้อ');
            if ($doc['status'] !== 'draft') {
                throw new \RuntimeException("ใบสั่งซื้อ {$doc['doc_no']} ไม่ใช่ร่าง");
            }
            $this->db->prepare("UPDATE purchase_documents SET status = 'ordered', ordered_at = NOW(), ordered_by = ? WHERE id = ?")->execute([$userId, $id]);
            $this->audit->log('purchase_documents', $id, 'update', ['doc_no' => $doc['doc_no'], 'status' => 'draft'],
                ['doc_no' => $doc['doc_no'], 'status' => 'ordered', 'note' => 'ยืนยันสั่งซื้อแล้ว รอรับของ'], $userId, $ip);
            $this->db->commit();
            return ['id' => $id, 'doc_no' => $doc['doc_no'], 'status' => 'ordered'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * The ordered goods arrived: the lines (as checked against the delivery) go into stock
     * like a new purchase, and the document becomes an ordinary purchase recorded now.
     */
    private function receiveOrder(array $doc, array $h, array $lines, array $keepTokens, array $newAttachments, int $userId, ?string $ip): array
    {
        $id = (int) $doc['id'];
        $result = $this->applyLines($id, $lines, [], $userId, $ip);
        $this->db->prepare(
            "UPDATE purchase_documents SET supplier_name = ?, supplier_tax_id = ?, receipt_no = ?, receipt_date = ?,
                subtotal = ?, discount = ?, vat = ?, total = ?, total_qty = ?, note = ?, status = 'active', user_id = ?, created_at = NOW()
             WHERE id = ?"
        )->execute([
            $h['supplier_name'], $h['supplier_tax_id'], $h['receipt_no'], $h['receipt_date'], $h['subtotal'], $h['discount'],
            $h['vat'], $h['total'], $h['total_qty'], $h['note'], $userId, $id,
        ]);
        $this->saveFunding($id, $h['funding']);
        $this->relinkAttachments($id, $keepTokens, $newAttachments);
        $this->audit->log('purchase_documents', $id, 'update',
            ['doc_no' => $doc['doc_no'], 'status' => 'ordered', 'total' => (float) $doc['total']],
            ['doc_no' => $doc['doc_no'], 'status' => 'active', 'note' => 'ยืนยันรับสินค้าตามใบสั่งซื้อเข้าสต๊อก', 'total' => $h['total'], 'items' => count($lines)],
            $userId, $ip);
        return ['id' => $id, 'doc_no' => $doc['doc_no'], 'received' => true, 'subtotal' => $h['subtotal'], 'supply_total' => $this->supplyTotal($lines), 'total' => $h['total'], 'items' => $result];
    }

    /**
     * Edits a saved purchase in place (same doc no.): the old lines are taken
     * back out of stock (and out of the moving-average cost), then the edited
     * lines are applied as on create. Stock may end up negative when the goods
     * were already sold; a product counted on or after the receipt date keeps
     * its counted stock (see keepCountedStock).
     * @param string[] $keepTokens photos already on this purchase to keep
     * @param array[] $newAttachments rows from AttachmentService::ownedBy() to add
     */
    public function update(int $id, array $header, array $items, array $keepTokens, array $newAttachments, int $userId, ?string $ip, bool $receive = false): array
    {
        [$h, $lines] = $this->validate($header, $items);

        $this->db->beginTransaction();
        try {
            [$doc, $old] = $this->lockActive($id, 'แก้ไข');
            if (in_array($doc['status'], ['draft', 'ordered'], true)) {
                if ($receive && $doc['status'] === 'draft') {
                    throw new \RuntimeException("ใบสั่งซื้อ {$doc['doc_no']} ยังเป็นร่าง กด ยืนยันสั่งซื้อแล้ว ก่อนรับสินค้า");
                }
                $result = $receive
                    ? $this->receiveOrder($doc, $h, $lines, $keepTokens, $newAttachments, $userId, $ip)
                    : $this->updateOrder($doc, $h, $items, $keepTokens, $newAttachments, $userId, $ip);
                $this->db->commit();
                return $result;
            }

            // 1) Undo the old lines, 2) apply the edited ones.
            $stockBefore = $this->stockOf(array_column($old['items'], 'product_id'));
            $createdHere = $this->reverseLines($old['items'], $userId, $ip);
            $this->db->prepare('DELETE FROM purchase_items WHERE purchase_document_id = ?')->execute([$id]);
            $result = $this->applyLines($id, $lines, $createdHere, $userId, $ip);

            // 3) Stock may go below zero (the goods were already sold). A product counted on or
            //    after the receipt date keeps the counted stock.
            foreach ($result as $r) {
                // A product only the edited lines bring in: its stock before is now minus what they added.
                if ($r['type'] === 'stock' && $r['product_id'] && !isset($stockBefore[(int) $r['product_id']]) && !$r['new']) {
                    $added = array_sum(array_map(fn ($x) => $x['product_id'] === $r['product_id'] ? (int) $x['units'] : 0, $result));
                    $stockBefore[(int) $r['product_id']] = (int) $this->products->find((int) $r['product_id'])['qty_stock'] - $added;
                }
            }
            $docDate = min((string) ($doc['receipt_date'] ?: substr((string) $doc['created_at'], 0, 10)), (string) ($h['receipt_date'] ?: date('Y-m-d')));
            $this->keepCountedStock($stockBefore, $docDate, $userId, $ip);

            // 4) Products this purchase created that are no longer on it and never moved are removed.
            $this->removeUnusedCreated($createdHere, array_column($result, 'product_id'), $userId, $ip);

            // 5) Header, totals and photos.
            $this->db->prepare(
                'UPDATE purchase_documents SET supplier_name = ?, supplier_tax_id = ?, receipt_no = ?, receipt_date = ?,
                    subtotal = ?, discount = ?, vat = ?, total = ?, total_qty = ?, note = ? WHERE id = ?'
            )->execute([
                $h['supplier_name'], $h['supplier_tax_id'], $h['receipt_no'], $h['receipt_date'],
                $h['subtotal'], $h['discount'], $h['vat'], $h['total'], $h['total_qty'], $h['note'], $id,
            ]);
            $this->saveFunding($id, $h['funding']);
            $this->relinkAttachments($id, $keepTokens, $newAttachments);

            $summary = fn ($d, $n) => [
                'supplier_name' => $d['supplier_name'], 'receipt_no' => $d['receipt_no'], 'receipt_date' => $d['receipt_date'],
                'discount' => (float) $d['discount'], 'total' => (float) $d['total'], 'items' => $n,
            ];
            $this->audit->log('purchase_documents', $id, 'update', $summary($doc, count($old['items'])), $summary($h, count($lines)), $userId, $ip);

            $this->db->commit();
            return ['id' => $id, 'doc_no' => $doc['doc_no'], 'subtotal' => $h['subtotal'], 'supply_total' => $this->supplyTotal($lines), 'total' => $h['total'], 'items' => $result];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Cancels a purchase: its goods go back out of stock (and out of the
     * moving-average cost) and its lines stop counting in reports and totals.
     * The document stays, marked cancelled, with a snapshot of its lines.
     * Stock may end up negative when some of the goods were already sold.
     */
    public function cancel(int $id, string $reason, int $userId, ?string $ip): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่ยกเลิก');
        }
        $this->db->beginTransaction();
        try {
            [$doc, $old] = $this->lockActive($id, 'ยกเลิก');
            // An order never delivered moved no stock: nothing to take back.
            $ordered = in_array($doc['status'], ['draft', 'ordered'], true);
            $stockBefore = $ordered ? [] : $this->stockOf(array_column($old['items'], 'product_id'));
            $createdHere = $ordered ? [] : $this->reverseLines($old['items'], $userId, $ip);
            // Stock may go below zero; a product counted on or after the receipt date keeps the counted stock.
            $this->keepCountedStock($stockBefore, (string) ($doc['receipt_date'] ?: substr((string) $doc['created_at'], 0, 10)), $userId, $ip);
            $snapshot = array_map(fn ($it) => array_intersect_key($it, array_flip([
                'product_id', 'product_name', 'item_type', 'receipt_name', 'barcode', 'qty', 'unit_cost', 'pack_size', 'units',
                'piece_cost', 'line_total', 'sale_price', 'is_new_product',
            ])), $old['items']); // an order not delivered: its ordered lines (find() shapes them the same way)
            $this->db->prepare('DELETE FROM purchase_items WHERE purchase_document_id = ?')->execute([$id]);
            $this->removeUnusedCreated($createdHere, [], $userId, $ip);
            $this->db->prepare(
                'UPDATE purchase_documents SET status = ?, cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?, cancelled_items = ? WHERE id = ?'
            )->execute(['cancelled', $userId, mb_substr($reason, 0, 255), json_encode($snapshot, JSON_UNESCAPED_UNICODE), $id]);
            $this->audit->log('purchase_documents', $id, 'cancel',
                ['doc_no' => $doc['doc_no'], 'total' => (float) $doc['total'], 'items' => count($old['items'])],
                ['status' => 'cancelled', 'reason' => $reason], $userId, $ip);
            $this->db->commit();
            return ['id' => $id, 'doc_no' => $doc['doc_no'], 'ordered' => $ordered, 'items' => count($old['items']), 'units' => $ordered ? 0 : array_sum(array_map(
                fn ($it) => $it['item_type'] === 'stock' ? (int) $it['units'] : 0, $old['items']
            ))];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array{0:array,1:array} [locked document row, find() result]; only active purchases can change. */
    /**
     * Changes only the receipt evidence (photos / .md files) of a saved purchase.
     * @param string[] $keepTokens files already on this purchase to keep
     * @param array[] $newAttachments rows from AttachmentService::ownedBy() to add
     */
    public function setAttachments(int $id, array $keepTokens, array $newAttachments, int $userId, ?string $ip): void
    {
        $this->db->beginTransaction();
        try {
            [$doc] = $this->lockActive($id, 'แนบหลักฐาน');
            $before = array_column($this->attachments->forPurchase($id), 'token');
            $this->relinkAttachments($id, $keepTokens, $newAttachments);
            $after = array_column($this->attachments->forPurchase($id), 'token');
            if ($before !== $after) {
                $this->audit->log('purchase_documents', $id, 'update', ['doc_no' => $doc['doc_no'], 'attachments' => count($before)], ['doc_no' => $doc['doc_no'], 'attachments' => count($after)], $userId, $ip);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Unlinks this purchase's files not in $keepTokens, then links the new ones. */
    private function relinkAttachments(int $id, array $keepTokens, array $newAttachments): void
    {
        $keep = array_flip(array_map('strval', $keepTokens));
        $unlink = $this->db->prepare('UPDATE attachments SET purchase_document_id = NULL WHERE id = ?');
        foreach ($this->attachments->forPurchase($id) as $a) {
            if (!isset($keep[$a['token']])) {
                $unlink->execute([$a['id']]);
            }
        }
        $this->attachments->linkToPurchase($newAttachments, $id);
    }

    private function lockActive(int $id, string $action): array
    {
        $stmt = $this->db->prepare('SELECT * FROM purchase_documents WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc) {
            throw new \RuntimeException('ไม่พบใบซื้อ');
        }
        if (($doc['status'] ?? 'active') === 'cancelled') {
            throw new \RuntimeException("ใบซื้อ {$doc['doc_no']} ถูกยกเลิกแล้ว {$action}ไม่ได้");
        }
        return [$doc, $this->find($id)];
    }

    /**
     * Takes a purchase's stock lines back out of stock and out of the
     * moving-average cost. Returns the products this purchase created.
     * @return array<int,true>
     */
    private function reverseLines(array $items, int $userId, ?string $ip): array
    {
        $createdHere = [];
        foreach ($items as $it) {
            if ($it['item_type'] !== 'stock' || !$it['product_id']) {
                continue;
            }
            $productId = (int) $it['product_id'];
            if ((int) $it['is_new_product']) {
                $createdHere[$productId] = true;
            }
            $p = $this->products->find($productId);
            $units = (int) $it['units'];
            $left = (int) $p['qty_stock'] - $units;
            $cost = $left > 0
                ? max(0, round(((int) $p['qty_stock'] * (float) $p['cost_price'] - $units * (float) $it['piece_cost']) / $left, 2))
                : (float) $p['cost_price'];
            $this->products->addStock($productId, -$units, $userId, $ip);
            $this->db->prepare('UPDATE products SET cost_price = ?, updated_at = NOW() WHERE id = ?')->execute([$cost, $productId]);
        }
        return $createdHere;
    }

    /** @return array<int,int> product id => qty_stock now */
    private function stockOf(array $productIds): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', array_filter($productIds))) as $productId) {
            $p = $this->products->find($productId);
            if ($p) {
                $out[$productId] = (int) $p['qty_stock'];
            }
        }
        return $out;
    }

    /**
     * A product whose stock was counted (approved count dated on or after $docDate) already
     * had its stock fixed to what was on the shelf, which included this purchase as it really
     * was. Changing the purchase then must not move the stock: the change goes into that
     * count's line instead (the system quantity it was compared with, and so its difference).
     * @param array<int,int> $stockBefore product id => qty_stock before the purchase changed
     */
    private function keepCountedStock(array $stockBefore, string $docDate, int $userId, ?string $ip): void
    {
        $count = $this->db->prepare(
            "SELECT sci.id FROM stock_count_items sci JOIN stock_counts sc ON sc.id = sci.stock_count_id
             WHERE sci.product_id = ? AND sc.status = 'approved' AND sci.counted_qty IS NOT NULL AND sc.count_date >= ?
             ORDER BY sc.count_date DESC, sc.approved_at DESC, sci.id DESC LIMIT 1"
        );
        foreach ($stockBefore as $productId => $before) {
            $p = $this->products->find($productId);
            $delta = $p ? (int) $p['qty_stock'] - $before : 0;
            if ($delta === 0) {
                continue;
            }
            $count->execute([$productId, $docDate]);
            $itemId = $count->fetchColumn();
            if ($itemId === false) {
                continue;
            }
            $this->db->prepare('UPDATE products SET qty_stock = ?, updated_at = NOW() WHERE id = ?')->execute([$before, $productId]);
            $this->db->prepare('UPDATE stock_count_items SET system_qty = system_qty + ?, diff_qty = diff_qty - ? WHERE id = ?')
                ->execute([$delta, $delta, $itemId]);
            $this->audit->log('products', $productId, 'update', $p, $this->products->find($productId) + [
                'note' => 'แก้ไข/ยกเลิกใบซื้อที่ลงวันที่ก่อนการตรวจนับ: คงสต๊อกตามที่นับ ส่วนต่าง ' . $delta . ' ชิ้นไปอยู่ในผลต่างของรอบตรวจนับ',
            ], $userId, $ip);
        }
    }

    /** Deletes products this purchase created that it no longer uses, if nothing else ever touched them. */
    private function removeUnusedCreated(array $createdHere, array $stillUsedIds, int $userId, ?string $ip): void
    {
        $stillUsed = array_flip(array_map('intval', array_filter($stillUsedIds)));
        foreach (array_keys($createdHere) as $productId) {
            $p = isset($stillUsed[$productId]) ? null : $this->products->find($productId);
            if ($p && (int) $p['qty_stock'] === 0 && (int) $p['qty_received'] === 0 && (int) $p['qty_sold'] === 0) {
                try {
                    // Its pack barcodes go with it (ON DELETE CASCADE).
                    $this->db->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
                    $this->audit->log('products', $productId, 'delete', $p, null, $userId, $ip);
                } catch (\PDOException $e) {
                    // Still referenced elsewhere (e.g. another purchase): keep it with zero stock.
                }
            }
        }
    }

    /** @return array{0:array,1:array} [clean header with totals, normalised lines] */
    private function validate(array $header, array $items): array
    {
        $supplier = trim((string) ($header['supplier_name'] ?? ''));
        if ($supplier === '') {
            throw new \RuntimeException('กรุณาระบุชื่อร้าน/ผู้ขาย');
        }
        $lines = [];
        foreach ($items as $i => $item) {
            $l = $this->line((array) $item);
            if ($l['product_name'] === '' || $l['qty'] <= 0 || $l['pack_cost'] <= 0) {
                throw new \RuntimeException('รายการที่ ' . ($i + 1) . ' ข้อมูลไม่ครบ (ต้องมีชื่อ จำนวน และราคาทุน)');
            }
            $l['item_type'] ??= 'stock';
            $lines[] = $l;
        }
        if (!$lines) {
            throw new \RuntimeException('ไม่มีรายการสินค้าในใบซื้อ');
        }

        $receiptDate = trim((string) ($header['receipt_date'] ?? ''));
        if ($receiptDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate)) {
            throw new \RuntimeException('วันที่ในใบเสร็จต้องอยู่ในรูปแบบ ปปปป-ดด-วว');
        }
        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $discount = round(abs((float) ($header['discount'] ?? 0)), 2);
        return [[
            'supplier_name' => mb_substr($supplier, 0, 191),
            'supplier_tax_id' => trim((string) ($header['supplier_tax_id'] ?? '')) ?: null,
            'receipt_no' => trim((string) ($header['receipt_no'] ?? '')) ?: null,
            'receipt_date' => $receiptDate ?: null,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'vat' => round((float) ($header['vat'] ?? 0), 2),
            'total' => isset($header['total']) && (float) $header['total'] > 0 ? round((float) $header['total'], 2) : round($subtotal - $discount, 2),
            'total_qty' => array_sum(array_map(fn ($l) => $l['item_type'] === 'stock' ? $l['units'] : 0, $lines)),
            'note' => trim((string) ($header['note'] ?? '')) ?: null,
            'funding' => ($header['funding'] ?? 'capital') === 'profit' ? 'profit' : 'capital',
        ], $lines];
    }

    /** Bought with invested money (counts toward งบลงทุน) or with the business's profit. */
    private function saveFunding(int $id, string $funding): void
    {
        try {
            $this->db->prepare('UPDATE purchase_documents SET funding = ? WHERE id = ?')->execute([$funding, $id]);
        } catch (\PDOException $e) {
            // migration 0036 not run yet: every purchase counts as invested money
        }
    }

    private function supplyTotal(array $lines): float
    {
        return round(array_sum(array_map(fn ($l) => $l['item_type'] === 'supply' ? $l['line_total'] : 0, $lines)), 2);
    }

    /**
     * Writes a purchase's lines: restocks matched products (moving-average cost)
     * or creates new ones. $createdHere = products this purchase created before
     * an edit; they stay marked as new.
     */
    private function applyLines(int $docId, array $lines, array $createdHere, int $userId, ?string $ip): array
    {
        $insItem = $this->db->prepare(
            'INSERT INTO purchase_items
                (purchase_document_id, product_id, item_type, receipt_name, barcode, qty, unit_cost, pack_size, units, piece_cost, line_total, sale_price, is_new_product)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $result = [];
        foreach ($lines as $l) {
            if ($l['item_type'] === 'supply') {
                $insItem->execute([
                    $docId, null, 'supply', mb_substr($l['receipt_name'], 0, 191), $l['barcode'], $l['qty'], $l['pack_cost'],
                    $l['pack_size'], $l['units'], $l['piece_cost'], $l['line_total'], 0, 0,
                ]);
                $result[] = [
                    'product_id' => null, 'type' => 'supply', 'name' => $l['product_name'], 'qty' => $l['qty'], 'pack_size' => $l['pack_size'],
                    'units' => $l['units'], 'piece_cost' => $l['piece_cost'], 'line_total' => $l['line_total'], 'sale_price' => null, 'new' => false,
                ];
                continue;
            }
            // A pack's barcode is not the barcode of the single piece sold at the POS;
            // each pack size has its own, kept in product_pack_barcodes.
            $pieceBarcode = $l['pack_size'] === 1 ? $l['barcode'] : null;
            $packBarcode = $l['pack_size'] > 1 ? $l['barcode'] : null;
            $match = $l['new_product'] ? null : $this->matchProduct($l['product_id'], $l['barcode'], $l['product_name'], $l['receipt_name']);
            if ($match === null && $pieceBarcode !== null && $this->pieceBarcodeTaken($pieceBarcode)) {
                // A barcode another product already sells under stays with it; set the new one's later.
                $pieceBarcode = null;
            }
            if ($match) {
                $productId = (int) $match['id'];
                $salePrice = $l['sale_price'] ?? (float) $match['sale_price'];
                // Moving weighted-average cost: blend what is on the shelf with this lot.
                $before = $this->products->find($productId);
                $onHand = max((int) $before['qty_stock'], 0);
                $avgCost = $onHand + $l['units'] > 0
                    ? round(($onHand * (float) $before['cost_price'] + $l['units'] * $l['piece_cost']) / ($onHand + $l['units']), 2)
                    : $l['piece_cost'];
                $this->products->addStock($productId, $l['units'], $userId, $ip);
                $old = $this->products->find($productId);
                $upd = $this->db->prepare(
                    'UPDATE products SET cost_price = ?, sale_price = ?, barcode = COALESCE(barcode, ?), updated_at = NOW()
                     WHERE id = ?'
                );
                $upd->execute([$avgCost, $salePrice, $pieceBarcode, $productId]);
                $this->audit->log('products', $productId, 'update', $old, $this->products->find($productId), $userId, $ip);
                $isNew = isset($createdHere[$productId]);
            } else {
                $salePrice = $l['sale_price'] ?? $this->suggestedPrice($l['piece_cost']);
                $productId = $this->products->create([
                    'consignor_id' => null,
                    'name' => mb_substr($l['product_name'], 0, 191),
                    'barcode' => $pieceBarcode,
                    'cost_price' => $l['piece_cost'],
                    'sale_price' => $salePrice,
                    'fee_per_unit' => 0,
                    'round_per_unit' => 0,
                    'rounding_beneficiary' => 'store',
                    'qty' => $l['units'],
                    'listing_fee_status' => 'waived',
                    'listing_fee_amount' => 0,
                ], $userId, $ip);
                $isNew = true;
            }
            if ($packBarcode !== null) {
                $this->products->savePackBarcode($productId, $packBarcode, $l['pack_size']);
            }
            $insItem->execute([
                $docId, $productId, 'stock', mb_substr($l['receipt_name'], 0, 191), $l['barcode'], $l['qty'], $l['pack_cost'],
                $l['pack_size'], $l['units'], $l['piece_cost'], $l['line_total'], $salePrice, $isNew ? 1 : 0,
            ]);
            $result[] = [
                'product_id' => $productId, 'type' => 'stock', 'name' => $l['product_name'], 'qty' => $l['qty'], 'pack_size' => $l['pack_size'],
                'units' => $l['units'], 'piece_cost' => $l['piece_cost'], 'sale_price' => $salePrice, 'new' => $isNew,
            ];
        }
        return $result;
    }

    private function pieceBarcodeTaken(string $barcode): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM products WHERE barcode = ? LIMIT 1');
        $stmt->execute([$barcode]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * The purchase list, newest first, a page at a time.
     * @param array{q?:string, status?:string, from?:?string, to?:?string, page?:int, per?:int} $f
     *        status: '' = everything except cancelled, or one status; from/to: date on the receipt
     * @return array{data:array, total:int, counts:array<string,int>}
     */
    public function search(array $f): array
    {
        $where = [];
        $params = [];
        $date = 'COALESCE(pd.receipt_date, DATE(pd.created_at))';
        if (!empty($f['from']) && !empty($f['to'])) {
            $where[] = "$date BETWEEN ? AND ?";
            array_push($params, $f['from'], $f['to']);
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            // Doc no., supplier, receipt no. or a product on it.
            $where[] = "(pd.doc_no LIKE ? OR pd.supplier_name LIKE ? OR pd.receipt_no LIKE ? OR pd.note LIKE ?
                OR EXISTS (SELECT 1 FROM purchase_items pi LEFT JOIN products p ON p.id = pi.product_id
                           WHERE pi.purchase_document_id = pd.id AND (pi.receipt_name LIKE ? OR p.name LIKE ? OR pi.barcode = ?)))";
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $q);
        }
        $base = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        // Counts per status for the filter chips (same search and dates).
        $stmt = $this->db->prepare("SELECT pd.status, COUNT(*) AS n FROM purchase_documents pd $base GROUP BY pd.status");
        $stmt->execute($params);
        $counts = array_map('intval', array_column($stmt->fetchAll(), 'n', 'status'));

        $status = (string) ($f['status'] ?? '');
        if (in_array($status, ['draft', 'ordered', 'active', 'cancelled'], true)) {
            $where[] = 'pd.status = ?';
            $params[] = $status;
        } else {
            $where[] = "pd.status <> 'cancelled'";
        }
        $cond = 'WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM purchase_documents pd $cond");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $per = max(1, min(100, (int) ($f['per'] ?? 10)));
        $page = max(1, (int) ($f['page'] ?? 1));
        return ['data' => $this->recent($per, ($page - 1) * $per, $cond, $params), 'total' => $total, 'counts' => $counts];
    }

    public function recent(int $limit = 30, int $offset = 0, string $cond = '', array $params = []): array
    {
        $stmt = $this->db->prepare(
            "SELECT pd.*, u.full_name AS user_name,
                    (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_document_id = pd.id) AS item_count,
                    (SELECT COALESCE(SUM(pi.line_total), 0) FROM purchase_items pi WHERE pi.purchase_document_id = pd.id AND pi.item_type = 'supply') AS supply_total,
                    (SELECT COUNT(*) FROM attachments a WHERE a.purchase_document_id = pd.id AND a.mime LIKE 'image/%') AS image_count,
                    (SELECT COUNT(*) FROM attachments a WHERE a.purchase_document_id = pd.id AND a.mime NOT LIKE 'image/%') AS file_count
             FROM purchase_documents pd LEFT JOIN users u ON u.id = pd.user_id
             {$cond}
             ORDER BY pd.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);
        return array_map(function ($d) {
            // A cancelled purchase has no lines left; show the counts from its snapshot.
            // An order not delivered yet has no lines either: count from what was ordered.
            $snapshot = match ($d['status'] ?? 'active') {
                'cancelled' => (string) $d['cancelled_items'],
                'draft', 'ordered' => (string) ($d['order_items'] ?? ''),
                default => null,
            };
            if ($snapshot !== null) {
                $items = json_decode($snapshot, true) ?: [];
                $d['item_count'] = count($items);
                $d['supply_total'] = round(array_sum(array_map(fn ($i) => ($i['item_type'] ?? 'stock') === 'supply' ? (float) $i['line_total'] : 0, $items)), 2);
            }
            unset($d['cancelled_items'], $d['order_items']);
            return $d;
        }, $stmt->fetchAll());
    }

    /** Ordered lines (as the form sent them) in the shape of purchase_items rows, for showing. */
    private function orderLinesForView(array $lines): array
    {
        return array_map(function ($l) {
            $qty = (int) ($l['qty'] ?? 0);
            $size = max(1, (int) ($l['pack_size'] ?? 1));
            $total = (float) ($l['line_total'] ?? 0);
            $units = $qty * $size;
            return [
                'product_id' => $l['product_id'] ?? null, 'product_name' => $l['product_name'] ?? null,
                'item_type' => ($l['item_type'] ?? 'stock') === 'supply' ? 'supply' : 'stock',
                'receipt_name' => (string) ($l['name'] ?? ''), 'barcode' => $l['barcode'] ?? '', 'qty' => $qty,
                'unit_cost' => (float) ($l['unit_cost'] ?? 0), 'pack_size' => $size, 'units' => $units,
                'piece_cost' => $units > 0 ? round($total / $units, 2) : 0, 'line_total' => $total,
                'sale_price' => $l['sale_price'] ?? null, 'is_new_product' => empty($l['product_id']) ? 1 : 0,
            ];
        }, $lines);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT pd.*, u.full_name AS user_name FROM purchase_documents pd LEFT JOIN users u ON u.id = pd.user_id WHERE pd.id = ?');
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc) {
            return null;
        }
        $items = $this->db->prepare(
            'SELECT pi.*, p.name AS product_name, p.qty_stock FROM purchase_items pi LEFT JOIN products p ON p.id = pi.product_id
             WHERE pi.purchase_document_id = ? ORDER BY pi.id ASC'
        );
        $items->execute([$id]);
        $doc['items'] = $items->fetchAll();
        if (($doc['status'] ?? 'active') === 'cancelled') {
            $doc['items'] = json_decode((string) $doc['cancelled_items'], true) ?: [];
            $by = $this->db->prepare('SELECT full_name FROM users WHERE id = ?');
            $by->execute([(int) $doc['cancelled_by']]);
            $doc['cancelled_by_name'] = $by->fetchColumn() ?: null;
        }
        if (in_array($doc['status'] ?? 'active', ['draft', 'ordered'], true)) {
            // The lines as entered (the form reloads them) and as they will go into stock.
            $doc['order_lines'] = json_decode((string) $doc['order_items'], true) ?: [];
            $doc['items'] = $this->orderLinesForView($doc['order_lines']);
            $by = $this->db->prepare('SELECT full_name FROM users WHERE id = ?');
            $by->execute([(int) $doc['ordered_by']]);
            $doc['ordered_by_name'] = $by->fetchColumn() ?: null;
        }
        unset($doc['cancelled_items'], $doc['order_items']);
        $doc['images'] = array_map([$this->attachments, 'publicInfo'], $this->attachments->forPurchase($id));
        return $doc;
    }
}
