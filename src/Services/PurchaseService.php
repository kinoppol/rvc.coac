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
     * barcode (product's own or one seen on an earlier purchase of it), then
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
                ?? $first('SELECT * FROM products WHERE pack_barcode = ? AND consignor_id IS NULL ORDER BY id DESC LIMIT 1', [$barcode])
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
        $pack = isset($item['pack_size']) && (int) $item['pack_size'] >= 1 ? min((int) $item['pack_size'], 1000) : $guessPack;
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
            'barcode' => trim((string) ($item['barcode'] ?? '')) ?: null,
            'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : null,
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
            $type = $l['item_type'] ?? $previous ?? 'stock';
            $match = $type === 'stock' ? $this->matchProduct($l['product_id'], $l['barcode'], $l['product_name'], $l['receipt_name']) : null;
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
            $l['item_type'] ??= $this->previousType($l['barcode'], $l['receipt_name'], $l['product_name']) ?? 'stock';
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
        $vat = round((float) ($header['vat'] ?? 0), 2);
        $total = isset($header['total']) && (float) $header['total'] > 0 ? round((float) $header['total'], 2) : round($subtotal - $discount, 2);

        $this->db->beginTransaction();
        try {
            $docNo = $this->docs->next('PO', 'purchase_documents', 'doc_no');
            $ins = $this->db->prepare(
                'INSERT INTO purchase_documents
                    (doc_no, supplier_name, supplier_tax_id, receipt_no, receipt_date, subtotal, discount, vat, total, total_qty, note, source, user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $ins->execute([
                $docNo, mb_substr($supplier, 0, 191),
                trim((string) ($header['supplier_tax_id'] ?? '')) ?: null,
                trim((string) ($header['receipt_no'] ?? '')) ?: null,
                $receiptDate ?: null,
                $subtotal, $discount, $vat, $total,
                array_sum(array_map(fn ($l) => $l['item_type'] === 'stock' ? $l['units'] : 0, $lines)),
                trim((string) ($header['note'] ?? '')) ?: null,
                $source === 'ai' ? 'ai' : 'manual',
                $userId,
            ]);
            $docId = (int) $this->db->lastInsertId();

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
                // it is kept separately (pack_barcode) for reference and matching.
                $pieceBarcode = $l['pack_size'] === 1 ? $l['barcode'] : null;
                $packBarcode = $l['pack_size'] > 1 ? $l['barcode'] : null;
                $packSize = $l['pack_size'] > 1 ? $l['pack_size'] : null;
                $match = $this->matchProduct($l['product_id'], $l['barcode'], $l['product_name'], $l['receipt_name']);
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
                        'UPDATE products SET cost_price = ?, sale_price = ?, barcode = COALESCE(barcode, ?),
                                pack_barcode = COALESCE(?, pack_barcode), pack_size = COALESCE(?, pack_size), updated_at = NOW()
                         WHERE id = ?'
                    );
                    $upd->execute([$avgCost, $salePrice, $pieceBarcode, $packBarcode, $packSize, $productId]);
                    $this->audit->log('products', $productId, 'update', $old, $this->products->find($productId), $userId, $ip);
                    $isNew = false;
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
                    if ($packBarcode !== null || $packSize !== null) {
                        $this->db->prepare('UPDATE products SET pack_barcode = ?, pack_size = ? WHERE id = ?')
                            ->execute([$packBarcode, $packSize, $productId]);
                    }
                    $isNew = true;
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

            $this->attachments->linkToPurchase($attachments, $docId);
            $this->audit->log('purchase_documents', $docId, 'create', null, [
                'doc_no' => $docNo, 'supplier_name' => $supplier, 'total' => $total, 'items' => count($lines), 'source' => $source,
            ], $userId, $ip);

            $this->db->commit();
            $supplyTotal = round(array_sum(array_map(fn ($l) => $l['item_type'] === 'supply' ? $l['line_total'] : 0, $lines)), 2);
            return ['id' => $docId, 'doc_no' => $docNo, 'subtotal' => $subtotal, 'supply_total' => $supplyTotal, 'total' => $total, 'items' => $result];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function recent(int $limit = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT pd.*, u.full_name AS user_name,
                    (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_document_id = pd.id) AS item_count,
                    (SELECT COALESCE(SUM(pi.line_total), 0) FROM purchase_items pi WHERE pi.purchase_document_id = pd.id AND pi.item_type = 'supply') AS supply_total,
                    (SELECT COUNT(*) FROM attachments a WHERE a.purchase_document_id = pd.id) AS image_count
             FROM purchase_documents pd LEFT JOIN users u ON u.id = pd.user_id
             ORDER BY pd.id DESC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
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
            'SELECT pi.*, p.name AS product_name, p.qty_stock, p.pack_barcode FROM purchase_items pi LEFT JOIN products p ON p.id = pi.product_id
             WHERE pi.purchase_document_id = ? ORDER BY pi.id ASC'
        );
        $items->execute([$id]);
        $doc['items'] = $items->fetchAll();
        $doc['images'] = array_map([$this->attachments, 'publicInfo'], $this->attachments->forPurchase($id));
        return $doc;
    }
}
