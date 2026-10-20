<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepo;
use PDO;

/**
 * Saves one day's sales report from an external POS after the user reviewed
 * the pairing: new products are created, every pair (or "skip") is
 * remembered for the next import, and the lines are recorded as one sale
 * dated on the report's day.
 */
class PosImportService
{
    private const ORIGINS = ['ai', 'rule', 'manual', 'new'];

    public function __construct(
        private PDO $db,
        private ProductRepo $products,
        private SaleService $sales,
        private AuditLogger $audit
    ) {
    }

    /**
     * @param array<int,array{pos_name:string,pos_category?:?string,qty?:int,net?:float,cost?:?float,action:string,
     *        product_id?:int,origin?:string,new_product?:array{name?:string,consignor_id?:?int,cost_price?:float,sale_price?:float}}> $lines
     */
    public function commit(string $saleDate, array $lines, bool $allowShortStock, string $sourceLabel, int $userId, ?string $ip): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || !checkdate((int) substr($saleDate, 5, 2), (int) substr($saleDate, 8, 2), (int) substr($saleDate, 0, 4))) {
            throw new \RuntimeException('กรุณาระบุวันที่ขายให้ถูกต้อง');
        }
        if ($saleDate > date('Y-m-d')) {
            throw new \RuntimeException('วันที่ขายต้องไม่เกินวันนี้');
        }
        if (!$lines) {
            throw new \RuntimeException('ไม่มีรายการให้นำเข้า');
        }

        $this->db->beginTransaction();
        try {
            $items = [];
            $skipped = 0;
            $newCount = 0;
            foreach (array_values($lines) as $n => $line) {
                $label = 'รายการที่ ' . ($n + 1);
                $posName = trim((string) ($line['pos_name'] ?? ''));
                if ($posName === '') {
                    throw new \RuntimeException("{$label}: ไม่มีชื่อสินค้าจาก POS");
                }
                $label .= " «{$posName}»";
                $action = (string) ($line['action'] ?? '');
                $category = trim((string) ($line['pos_category'] ?? '')) ?: null;
                $sku = PosMatcher::skuKey(isset($line['pos_sku']) ? (string) $line['pos_sku'] : null) !== null
                    ? mb_substr(trim((string) $line['pos_sku']), 0, 64) : null;
                $origin = (string) ($line['origin'] ?? 'manual');

                if ($action === 'skip') {
                    $this->remember($posName, $sku, $category, null, 'skip', $origin, $userId);
                    $skipped++;
                    continue;
                }

                $qty = (int) ($line['qty'] ?? 0);
                $net = round((float) ($line['net'] ?? 0), 2);
                if ($qty <= 0) {
                    throw new \RuntimeException("{$label}: กรุณาระบุจำนวนที่ขาย");
                }
                if ($net < 0) {
                    throw new \RuntimeException("{$label}: ยอดขายติดลบ");
                }
                $unitPrice = round($net / $qty, 2);

                if ($action === 'new') {
                    $productId = $this->createProduct($line, $posName, $qty, $unitPrice, $userId, $ip, $label);
                    $origin = 'new';
                    $newCount++;
                } elseif ($action === 'product') {
                    $productId = (int) ($line['product_id'] ?? 0);
                    if (!$productId || !$this->products->find($productId)) {
                        throw new \RuntimeException("{$label}: ไม่พบสินค้าที่จับคู่ไว้");
                    }
                } else {
                    throw new \RuntimeException("{$label}: ยังไม่ได้เลือกว่าจะจับคู่ เพิ่มเป็นสินค้าใหม่ หรือไม่นำเข้า");
                }

                $this->remember($posName, $sku, $category, $productId, 'product', $origin, $userId);
                $items[] = ['product_id' => $productId, 'qty' => $qty, 'unit_price' => $unitPrice];
            }

            if (!$items) {
                // Only "skip" choices: they are remembered, but there is no sale to record.
                $this->db->commit();
                return ['sale' => null, 'skipped' => $skipped, 'new_products' => 0, 'lines' => 0];
            }

            $saleAt = $saleDate === date('Y-m-d') ? date('Y-m-d H:i:s') : $saleDate . ' 23:59:00';
            $sale = $this->sales->record($items, $userId, $ip, [
                'sale_datetime' => $saleAt,
                'allow_short_stock' => $allowShortStock,
                'note' => 'นำเข้าจาก POS',
            ]);

            $this->db->prepare(
                'INSERT INTO pos_imports (sale_date, sale_id, source_label, line_count, skipped_count, new_products, total_qty, total_value, user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $saleDate, $sale['id'], mb_substr($sourceLabel, 0, 191) ?: null, count($items), $skipped, $newCount,
                $sale['total_qty'], $sale['total_value'], $userId,
            ]);
            $importId = (int) $this->db->lastInsertId();
            $this->audit->log('pos_imports', $importId, 'create', null, [
                'sale_date' => $saleDate, 'doc_no' => $sale['doc_no'], 'lines' => count($items), 'skipped' => $skipped,
                'new_products' => $newCount, 'total_value' => $sale['total_value'],
            ], $userId, $ip);

            $this->db->commit();
            return ['sale' => $sale, 'skipped' => $skipped, 'new_products' => $newCount, 'lines' => count($items)];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * A product that exists in the external POS but not here. It is created
     * with exactly the sold quantity as received, so the sale leaves it at 0.
     */
    private function createProduct(array $line, string $posName, int $qty, float $unitPrice, int $userId, ?string $ip, string $label): int
    {
        $np = (array) ($line['new_product'] ?? []);
        $name = trim((string) ($np['name'] ?? '')) ?: $posName;
        $consignorId = !empty($np['consignor_id']) ? (int) $np['consignor_id'] : null;
        if ($consignorId !== null) {
            $stmt = $this->db->prepare('SELECT id FROM consignors WHERE id = ?');
            $stmt->execute([$consignorId]);
            if (!$stmt->fetchColumn()) {
                throw new \RuntimeException("{$label}: ไม่พบผู้ฝากขายที่เลือก");
            }
        }
        $posCost = isset($line['cost']) && $line['cost'] !== null ? round((float) $line['cost'] / $qty, 2) : 0.0;
        $cost = isset($np['cost_price']) && $np['cost_price'] !== '' ? round((float) $np['cost_price'], 2) : $posCost;
        $price = isset($np['sale_price']) && (float) $np['sale_price'] > 0 ? round((float) $np['sale_price'], 2) : $unitPrice;
        if ($cost < 0 || $price <= 0) {
            throw new \RuntimeException("{$label}: ราคาทุนหรือราคาขายของสินค้าใหม่ไม่ถูกต้อง");
        }
        // Consignment goods: the consignor gets the cost, the store keeps the difference.
        $fee = $consignorId !== null ? max(round($price - $cost, 2), 0) : 0;

        return $this->products->create([
            'consignor_id' => $consignorId,
            'name' => mb_substr($name, 0, 191),
            'barcode' => null,
            'cost_price' => $cost,
            'sale_price' => $price,
            'fee_per_unit' => $fee,
            'round_per_unit' => 0,
            'rounding_beneficiary' => 'store',
            'qty' => $qty,
            'listing_fee_status' => 'waived',
            'listing_fee_amount' => 0,
        ], $userId, $ip);
    }

    /**
     * Saves (or updates) the pair so the next import reuses it: the pair of the
     * same SKU, else the pair of the same name (an older name-only pair gets the
     * SKU), else a new one.
     */
    private function remember(string $posName, ?string $sku, ?string $category, ?int $productId, string $action, string $origin, int $userId): void
    {
        $source = in_array($origin, self::ORIGINS, true) ? $origin : 'manual';
        $key = PosMatcher::key($posName);
        $existing = null;
        if ($sku !== null) {
            $stmt = $this->db->prepare('SELECT * FROM pos_item_mappings WHERE pos_sku = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$sku]);
            $existing = $stmt->fetch() ?: null;
        }
        if (!$existing) {
            $stmt = $this->db->prepare('SELECT * FROM pos_item_mappings WHERE pos_key = ?');
            $stmt->execute([$key]);
            $byName = $stmt->fetch() ?: null;
            if ($byName && ($sku === null || $byName['pos_sku'] === null || PosMatcher::skuKey($byName['pos_sku']) === PosMatcher::skuKey($sku))) {
                $existing = $byName;
            } elseif ($byName) {
                // Same name, another SKU: a different item, kept as its own pair.
                $key = mb_substr($key, 0, 120) . '#' . PosMatcher::skuKey($sku);
            }
        }
        $category = $category !== null ? mb_substr($category, 0, 191) : null;
        if ($existing) {
            // An unchanged pair keeps how it was first made.
            $same = (int) $existing['product_id'] === (int) $productId && $existing['action'] === $action;
            $this->db->prepare(
                'UPDATE pos_item_mappings SET pos_sku = COALESCE(?, pos_sku), pos_name = ?, pos_category = ?, product_id = ?, action = ?,
                    source = ?, times_used = times_used + 1, last_used_at = NOW() WHERE id = ?'
            )->execute([$sku, mb_substr($posName, 0, 191), $category, $productId, $action, $same ? $existing['source'] : $source, $existing['id']]);
            return;
        }
        $this->db->prepare(
            'INSERT INTO pos_item_mappings
                (pos_key, pos_sku, pos_name, pos_category, product_id, action, source, times_used, created_by, created_at, updated_at, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW(), NOW())'
        )->execute([$key, $sku, mb_substr($posName, 0, 191), $category, $productId, $action, $source, $userId]);
    }

    /**
     * Cancels an import: its sale is removed, the goods go back into stock,
     * the consignors' passbook entries of that sale are taken out (later
     * balances recomputed), and products it created are deleted if nothing
     * else used them. The import row stays, marked cancelled, with a snapshot
     * of its lines. Saved pairs are kept (a pair of a deleted product goes with it).
     */
    public function cancel(int $id, string $reason, int $userId, ?string $ip): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่ยกเลิก');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM pos_imports WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $import = $stmt->fetch();
            if (!$import) {
                throw new \RuntimeException('ไม่พบรายการนำเข้า');
            }
            if ($import['status'] === 'cancelled') {
                throw new \RuntimeException('การนำเข้านี้ถูกยกเลิกไปแล้ว');
            }
            $items = $this->find($id)['items'];
            $sale = null;
            if ($import['sale_id'] !== null) {
                $stmt = $this->db->prepare('SELECT * FROM sales WHERE id = ? FOR UPDATE');
                $stmt->execute([$import['sale_id']]);
                $sale = $stmt->fetch() ?: null;
            }

            $created = [];
            if ($sale) {
                $this->removeLedgerEntries($sale, $items);
                foreach ($items as $it) {
                    $productId = (int) $it['product_id'];
                    $old = $this->products->find($productId);
                    $this->db->prepare(
                        'UPDATE products SET qty_sold = qty_sold - ?, qty_stock = qty_stock + ?, updated_at = NOW() WHERE id = ?'
                    )->execute([(int) $it['qty'], (int) $it['qty'], $productId]);
                    $this->audit->log('products', $productId, 'update', $old, $this->products->find($productId), $userId, $ip);
                    if ((int) $it['is_new']) {
                        $created[$productId] = true;
                    }
                }
                $this->db->prepare('DELETE FROM sale_items WHERE sale_id = ?')->execute([$sale['id']]);
                $this->db->prepare('DELETE FROM sales WHERE id = ?')->execute([$sale['id']]);
                $this->audit->log('sales', (int) $sale['id'], 'delete',
                    ['doc_no' => $sale['doc_no'], 'total_qty' => (int) $sale['total_qty'], 'total_value' => (float) $sale['total_value']],
                    ['reason' => 'ยกเลิกการนำเข้ายอดขายจาก POS: ' . $reason], $userId, $ip);
            }
            $removed = $this->removeCreatedProducts(array_keys($created), $userId, $ip);

            $snapshot = array_map(fn ($it) => array_intersect_key($it, array_flip([
                'product_id', 'product_name', 'pos_names', 'pos_skus', 'owner', 'qty', 'unit_price', 'line_total', 'fee_amount', 'is_new',
            ])), $items);
            $this->db->prepare(
                "UPDATE pos_imports SET status = 'cancelled', sale_id = NULL, cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?,
                    cancelled_items = ?, cancelled_doc_no = ? WHERE id = ?"
            )->execute([$userId, mb_substr($reason, 0, 255), json_encode($snapshot, JSON_UNESCAPED_UNICODE), $sale['doc_no'] ?? null, $id]);
            $this->audit->log('pos_imports', $id, 'cancel',
                ['sale_date' => $import['sale_date'], 'doc_no' => $sale['doc_no'] ?? null, 'total_value' => (float) $import['total_value']],
                ['status' => 'cancelled', 'reason' => $reason, 'removed_products' => $removed], $userId, $ip);
            $this->db->commit();
            return [
                'id' => $id, 'doc_no' => $sale['doc_no'] ?? null, 'lines' => count($items),
                'units' => array_sum(array_map(fn ($it) => (int) $it['qty'], $items)), 'removed_products' => $removed,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Takes out the passbook entries a sale posted: per consignor the sale
     * entry (ref = the sale's doc no), the fee entry right after it, and a
     * listing-fee refund that the sale triggered for one of its products.
     * The later balances of those consignors are then recomputed.
     */
    private function removeLedgerEntries(array $sale, array $items): void
    {
        $stmt = $this->db->prepare("SELECT * FROM ledger_entries WHERE ref_no = ? AND type = 'sale' ORDER BY id ASC");
        $stmt->execute([$sale['doc_no']]);
        $remove = [];
        $from = [];
        $fee = $this->db->prepare(
            "SELECT id FROM ledger_entries WHERE consignor_id = ? AND id > ? AND type = 'fee' AND description LIKE 'หักค่าฝากขาย%'
             AND created_by <=> ? AND created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 10 SECOND) ORDER BY id ASC LIMIT 1"
        );
        foreach ($stmt->fetchAll() as $e) {
            $cid = (int) $e['consignor_id'];
            $this->db->prepare('SELECT id FROM consignors WHERE id = ? FOR UPDATE')->execute([$cid]);
            $remove[] = (int) $e['id'];
            $from[$cid] = min($from[$cid] ?? PHP_INT_MAX, (int) $e['id']);
            $fee->execute([$cid, $e['id'], $e['created_by'], $e['created_at'], $e['created_at']]);
            if ($feeId = $fee->fetchColumn()) {
                $remove[] = (int) $feeId;
            }
        }

        // Listing-fee refunds are posted while the sale is written, before its sale entries.
        $waive = (int) ((new SettingsService($this->db))->get('waive_qty') ?? 20);
        $lfr = $this->db->prepare(
            "SELECT id FROM ledger_entries WHERE consignor_id = ? AND type = 'listing_fee_refund' AND description LIKE ?
             AND created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 10 SECOND) ORDER BY id DESC LIMIT 1"
        );
        foreach ($items as $it) {
            $p = $this->products->find((int) $it['product_id']);
            if (!$p || $p['consignor_id'] === null || !(int) $p['listing_fee_refunded'] || (int) $p['qty_sold'] - (int) $it['qty'] > $waive) {
                continue;
            }
            $lfr->execute([$p['consignor_id'], '%- ' . $p['name'], $sale['created_at'], $sale['created_at']]);
            if ($lfrId = $lfr->fetchColumn()) {
                $cid = (int) $p['consignor_id'];
                $remove[] = (int) $lfrId;
                $from[$cid] = min($from[$cid] ?? PHP_INT_MAX, (int) $lfrId);
                $this->db->prepare('UPDATE products SET listing_fee_refunded = 0 WHERE id = ?')->execute([$p['id']]);
            }
        }
        if (!$remove) {
            return;
        }
        $this->db->prepare('DELETE FROM ledger_entries WHERE id IN (' . implode(',', array_fill(0, count($remove), '?')) . ')')->execute($remove);

        $prev = $this->db->prepare('SELECT balance_after FROM ledger_entries WHERE consignor_id = ? AND id < ? ORDER BY id DESC LIMIT 1');
        $later = $this->db->prepare('SELECT id, amount_in, amount_out FROM ledger_entries WHERE consignor_id = ? AND id > ? ORDER BY id ASC');
        $upd = $this->db->prepare('UPDATE ledger_entries SET balance_after = ? WHERE id = ?');
        foreach ($from as $cid => $firstId) {
            $prev->execute([$cid, $firstId]);
            $balance = (float) ($prev->fetchColumn() ?: 0);
            $later->execute([$cid, $firstId]);
            foreach ($later->fetchAll() as $e) {
                $balance = round($balance + (float) $e['amount_in'] - (float) $e['amount_out'], 2);
                $upd->execute([$balance, $e['id']]);
            }
        }
    }

    /** Products the import created, deleted when nothing else sold, received or returned them. */
    private function removeCreatedProducts(array $productIds, int $userId, ?string $ip): int
    {
        $removed = 0;
        $used = $this->db->prepare('SELECT COUNT(*) FROM sale_items WHERE product_id = ?');
        foreach ($productIds as $productId) {
            $p = $this->products->find((int) $productId);
            $used->execute([$productId]);
            if (!$p || (int) $used->fetchColumn() > 0 || (int) $p['qty_sold'] !== 0 || (int) $p['qty_returned'] !== 0
                || (int) $p['qty_stock'] !== (int) $p['qty_received']) {
                continue;
            }
            try {
                $this->db->exec('SAVEPOINT pos_remove_product');
                $this->db->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
                $this->db->exec('RELEASE SAVEPOINT pos_remove_product');
                $this->audit->log('products', (int) $productId, 'delete', $p, null, $userId, $ip);
                $removed++;
            } catch (\PDOException $e) {
                // Referenced elsewhere (e.g. a stock count): keep the product.
                $this->db->exec('ROLLBACK TO SAVEPOINT pos_remove_product');
            }
        }
        return $removed;
    }

    public function recent(int $limit = 30): array
    {
        $stmt = $this->db->prepare(
            'SELECT pi.*, COALESCE(s.doc_no, pi.cancelled_doc_no) AS doc_no, u.full_name AS user_name
             FROM pos_imports pi LEFT JOIN sales s ON s.id = pi.sale_id LEFT JOIN users u ON u.id = pi.user_id
             ORDER BY pi.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * One import with the sale lines it recorded. The POS names come from the
     * saved pairs; is_new = the product was created by this import.
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT pi.*, COALESCE(s.doc_no, pi.cancelled_doc_no) AS doc_no, s.sale_datetime, u.full_name AS user_name,
                    cu.full_name AS cancelled_by_name
             FROM pos_imports pi LEFT JOIN sales s ON s.id = pi.sale_id LEFT JOIN users u ON u.id = pi.user_id
             LEFT JOIN users cu ON cu.id = pi.cancelled_by
             WHERE pi.id = ?'
        );
        $stmt->execute([$id]);
        $import = $stmt->fetch();
        if (!$import) {
            return null;
        }
        $import['items'] = [];
        if ($import['status'] === 'cancelled') {
            $import['items'] = json_decode((string) $import['cancelled_items'], true) ?: [];
        } elseif ($import['sale_id'] !== null) {
            $items = $this->db->prepare(
                "SELECT si.id, si.product_id, si.qty, si.unit_price, si.fee_amount, si.cost_amount,
                        p.name AS product_name, p.qty_stock, COALESCE(c.name, 'สินค้าของร้าน') AS owner,
                        (SELECT GROUP_CONCAT(m.pos_name ORDER BY m.pos_name SEPARATOR ', ')
                         FROM pos_item_mappings m WHERE m.product_id = si.product_id AND m.action = 'product') AS pos_names,
                        (SELECT GROUP_CONCAT(DISTINCT m.pos_sku ORDER BY m.pos_sku SEPARATOR ', ')
                         FROM pos_item_mappings m WHERE m.product_id = si.product_id AND m.action = 'product' AND m.pos_sku IS NOT NULL) AS pos_skus,
                        (ABS(TIMESTAMPDIFF(SECOND, p.created_at, ?)) <= 300) AS is_new
                 FROM sale_items si JOIN products p ON p.id = si.product_id LEFT JOIN consignors c ON c.id = p.consignor_id
                 WHERE si.sale_id = ? ORDER BY si.id ASC"
            );
            $items->execute([$import['created_at'], $import['sale_id']]);
            $import['items'] = array_map(fn ($r) => $r + [
                'line_total' => round((float) $r['unit_price'] * (int) $r['qty'], 2),
            ], $items->fetchAll());
        }
        return $import;
    }

    /** Earlier imports of the same day, so the user is warned before importing it twice. */
    public function forDate(string $date): array
    {
        $stmt = $this->db->prepare(
            "SELECT pi.id, pi.created_at, pi.line_count, pi.total_value, s.doc_no, u.full_name AS user_name
             FROM pos_imports pi LEFT JOIN sales s ON s.id = pi.sale_id LEFT JOIN users u ON u.id = pi.user_id
             WHERE pi.sale_date = ? AND pi.status = 'active' ORDER BY pi.id ASC"
        );
        $stmt->execute([$date]);
        return $stmt->fetchAll();
    }

    public function mappings(string $q = ''): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.id, m.pos_name, m.pos_sku, m.pos_category, m.action, m.source, m.times_used, m.last_used_at, m.updated_at,
                    p.id AS product_id, p.name AS product_name, COALESCE(c.name, IF(p.id IS NULL, NULL, 'สินค้าของร้าน')) AS consignor_name
             FROM pos_item_mappings m
             LEFT JOIN products p ON p.id = m.product_id LEFT JOIN consignors c ON c.id = p.consignor_id
             WHERE ? = '' OR m.pos_name LIKE ? OR p.name LIKE ? OR m.pos_sku LIKE ?
             ORDER BY m.pos_name ASC LIMIT 500"
        );
        $like = '%' . $q . '%';
        $stmt->execute([$q, $like, $like, $like]);
        return $stmt->fetchAll();
    }

    public function deleteMapping(int $id, int $userId, ?string $ip): void
    {
        $stmt = $this->db->prepare('SELECT * FROM pos_item_mappings WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            throw new \RuntimeException('ไม่พบคู่ที่จับไว้');
        }
        $this->db->prepare('DELETE FROM pos_item_mappings WHERE id = ?')->execute([$id]);
        $this->audit->log('pos_item_mappings', $id, 'delete', $old, null, $userId, $ip);
    }
}
