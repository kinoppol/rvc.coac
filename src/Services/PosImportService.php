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
                $origin = (string) ($line['origin'] ?? 'manual');

                if ($action === 'skip') {
                    $this->remember($posName, $category, null, 'skip', $origin, $userId);
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

                $this->remember($posName, $category, $productId, 'product', $origin, $userId);
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

    /** Saves (or updates) the pair so the next import of this name reuses it. */
    private function remember(string $posName, ?string $category, ?int $productId, string $action, string $origin, int $userId): void
    {
        $source = in_array($origin, self::ORIGINS, true) ? $origin : 'manual';
        // `source` is assigned first: MySQL evaluates the update list left to right,
        // and an unchanged pair keeps how it was first made.
        $this->db->prepare(
            'INSERT INTO pos_item_mappings
                (pos_key, pos_name, pos_category, product_id, action, source, times_used, created_by, created_at, updated_at, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                source = IF(product_id <=> VALUES(product_id) AND action = VALUES(action), source, VALUES(source)),
                pos_name = VALUES(pos_name), pos_category = VALUES(pos_category),
                product_id = VALUES(product_id), action = VALUES(action),
                times_used = times_used + 1, last_used_at = NOW()'
        )->execute([
            PosMatcher::key($posName), mb_substr($posName, 0, 191), $category !== null ? mb_substr($category, 0, 191) : null,
            $productId, $action, $source, $userId,
        ]);
    }

    public function recent(int $limit = 30): array
    {
        $stmt = $this->db->prepare(
            'SELECT pi.*, s.doc_no, u.full_name AS user_name
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
            'SELECT pi.*, s.doc_no, s.sale_datetime, u.full_name AS user_name
             FROM pos_imports pi LEFT JOIN sales s ON s.id = pi.sale_id LEFT JOIN users u ON u.id = pi.user_id
             WHERE pi.id = ?'
        );
        $stmt->execute([$id]);
        $import = $stmt->fetch();
        if (!$import) {
            return null;
        }
        $import['items'] = [];
        if ($import['sale_id'] !== null) {
            $items = $this->db->prepare(
                "SELECT si.id, si.product_id, si.qty, si.unit_price, si.fee_amount, si.cost_amount,
                        p.name AS product_name, p.qty_stock, COALESCE(c.name, 'สินค้าของร้าน') AS owner,
                        (SELECT GROUP_CONCAT(m.pos_name ORDER BY m.pos_name SEPARATOR ', ')
                         FROM pos_item_mappings m WHERE m.product_id = si.product_id AND m.action = 'product') AS pos_names,
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
            'SELECT pi.id, pi.created_at, pi.line_count, pi.total_value, s.doc_no, u.full_name AS user_name
             FROM pos_imports pi LEFT JOIN sales s ON s.id = pi.sale_id LEFT JOIN users u ON u.id = pi.user_id
             WHERE pi.sale_date = ? ORDER BY pi.id ASC'
        );
        $stmt->execute([$date]);
        return $stmt->fetchAll();
    }

    public function mappings(string $q = ''): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.id, m.pos_name, m.pos_category, m.action, m.source, m.times_used, m.last_used_at, m.updated_at,
                    p.id AS product_id, p.name AS product_name, COALESCE(c.name, IF(p.id IS NULL, NULL, 'สินค้าของร้าน')) AS consignor_name
             FROM pos_item_mappings m
             LEFT JOIN products p ON p.id = m.product_id LEFT JOIN consignors c ON c.id = p.consignor_id
             WHERE ? = '' OR m.pos_name LIKE ? OR p.name LIKE ?
             ORDER BY m.pos_name ASC LIMIT 500"
        );
        $like = '%' . $q . '%';
        $stmt->execute([$q, $like, $like]);
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
