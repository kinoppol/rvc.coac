<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AuditLogger;
use PDO;

class ProductRepo
{
    public function __construct(private PDO $db, private AuditLogger $audit)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByBarcode(string $barcode): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE barcode = ? LIMIT 1');
        $stmt->execute([$barcode]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Store product a pack barcode belongs to, with that pack's size. */
    public function findByPackBarcode(string $barcode): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, b.pack_size AS pack_size FROM product_pack_barcodes b JOIN products p ON p.id = b.product_id
             WHERE b.barcode = ? AND p.consignor_id IS NULL LIMIT 1'
        );
        $stmt->execute([$barcode]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array{barcode:string,pack_size:int}> */
    public function packBarcodes(int $productId): array
    {
        // SELECT * so this still works before migration 0039 adds sale_price.
        $stmt = $this->db->prepare('SELECT * FROM product_pack_barcodes WHERE product_id = ? ORDER BY pack_size ASC, id ASC');
        $stmt->execute([$productId]);
        return array_map(fn ($r) => [
            'barcode' => $r['barcode'], 'pack_size' => (int) $r['pack_size'],
            'sale_price' => isset($r['sale_price']) ? (float) $r['sale_price'] : null,
        ], $stmt->fetchAll());
    }

    /** Links a pack barcode to a product (moving it if it pointed elsewhere). */
    public function savePackBarcode(int $productId, string $barcode, int $packSize): void
    {
        $this->db->prepare(
            'INSERT INTO product_pack_barcodes (product_id, barcode, pack_size, created_at, last_seen_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), pack_size = VALUES(pack_size), last_seen_at = NOW()'
        )->execute([$productId, $barcode, $packSize]);
    }

    /**
     * Sets a store product's piece barcode and its full list of pack barcodes.
     * A barcode may identify only one thing: the piece of one product, or one pack.
     *
     * @param array<int,array{barcode:string,pack_size:int,sale_price?:?float}> $packs sale_price: whole-pack price, null = not sold whole
     */
    /**
     * Name (null = keep), piece barcode and pack barcodes of a store product.
     * With $allowConsigned a consignment product may be edited too, piece barcode only.
     */
    public function updateBarcodes(int $productId, ?string $barcode, array $packs, ?int $userId, ?string $ip, ?string $name = null, bool $allowConsigned = false): void
    {
        $old = $this->find($productId);
        if (!$old || ($old['consignor_id'] !== null && !$allowConsigned)) {
            throw new \RuntimeException('ไม่พบสินค้าของร้าน');
        }
        if ($old['consignor_id'] !== null && array_filter($packs, fn ($p) => trim((string) ($p['barcode'] ?? '')) !== '')) {
            throw new \RuntimeException('สินค้าฝากขายใช้ได้เฉพาะบาร์โค้ดชิ้น');
        }
        $name = $name === null ? $old['name'] : trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw new \RuntimeException('กรุณากรอกชื่อสินค้า');
        }
        $name = mb_substr($name, 0, 191);
        $barcode = trim((string) $barcode) ?: null;
        $clean = [];
        $noCode = []; // pack size => price: packs sold whole that have no barcode of their own
        foreach ($packs as $p) {
            $code = trim((string) ($p['barcode'] ?? ''));
            $size = (int) ($p['pack_size'] ?? 0);
            // Price for selling the whole pack (blank = the pack is not sold whole).
            $price = $p['sale_price'] ?? null;
            $price = $price === null || $price === '' ? null : round((float) $price, 2);
            $label = $code !== '' ? "บาร์โค้ดแพ็ค {$code}" : 'แพ็คที่ไม่มีบาร์โค้ด';
            if ($code === '' && $price === null) {
                continue; // an empty row
            }
            if ($size < 2 || $size > 1000) {
                throw new \RuntimeException("{$label}: จำนวนชิ้นต่อแพ็คต้องอยู่ระหว่าง 2–1000");
            }
            if ($price !== null && ($price <= 0 || $price > 1000000)) {
                throw new \RuntimeException("{$label}: ราคาขายยกแพ็คไม่ถูกต้อง");
            }
            if ($code === '') {
                if (isset($noCode[$size])) {
                    throw new \RuntimeException("แพ็ค {$size} ชิ้นที่ไม่มีบาร์โค้ดซ้ำกัน");
                }
                $noCode[$size] = $price;
                continue;
            }
            if ($code === $barcode || isset($clean[$code])) {
                throw new \RuntimeException("บาร์โค้ด {$code} ซ้ำกัน");
            }
            $clean[$code] = ['size' => $size, 'price' => $price];
        }
        $check = function (string $sql, array $params): ?string {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $name = $stmt->fetchColumn();
            return $name === false ? null : (string) $name;
        };
        foreach (array_filter([$barcode, ...array_keys($clean)]) as $code) {
            $owner = $check('SELECT name FROM products WHERE barcode = ? AND id <> ? LIMIT 1', [$code, $productId])
                ?? $check('SELECT p.name FROM product_pack_barcodes b JOIN products p ON p.id = b.product_id WHERE b.barcode = ? AND b.product_id <> ? LIMIT 1', [$code, $productId]);
            if ($owner !== null) {
                throw new \RuntimeException("บาร์โค้ด {$code} ใช้กับสินค้า \"{$owner}\" อยู่แล้ว");
            }
        }

        $oldPacks = $this->packBarcodes($productId);
        $this->db->prepare('UPDATE products SET name = ?, barcode = ?, updated_at = NOW() WHERE id = ?')->execute([$name, $barcode, $productId]);
        // Numeric barcodes become int array keys; compare as strings.
        $keep = array_map('strval', array_keys($clean));
        $del = $this->db->prepare('DELETE FROM product_pack_barcodes WHERE product_id = ? AND barcode = ?');
        foreach ($oldPacks as $p) {
            if ($p['barcode'] !== null && !in_array($p['barcode'], $keep, true)) {
                $del->execute([$productId, $p['barcode']]);
            }
        }
        // Packs without a barcode have nothing to match on: replace them all.
        $this->db->prepare('DELETE FROM product_pack_barcodes WHERE product_id = ? AND barcode IS NULL')->execute([$productId]);
        $insNoCode = $this->db->prepare('INSERT INTO product_pack_barcodes (product_id, barcode, pack_size, sale_price, created_at) VALUES (?, NULL, ?, ?, NOW())');
        foreach ($noCode as $size => $price) {
            try {
                $insNoCode->execute([$productId, $size, $price]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1048) { // barcode still NOT NULL
                    throw new \RuntimeException('ยังบันทึกแพ็คที่ไม่มีบาร์โค้ดไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0040) ก่อน');
                }
                throw $e;
            }
        }
        $upsert = $this->db->prepare(
            'INSERT INTO product_pack_barcodes (product_id, barcode, pack_size, sale_price, created_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE pack_size = VALUES(pack_size), sale_price = VALUES(sale_price)'
        );
        foreach ($clean as $code => $p) {
            $upsert->execute([$productId, (string) $code, $p['size'], $p['price']]);
        }
        $this->audit->log(
            'products', $productId, 'update',
            ['name' => $old['name'], 'barcode' => $old['barcode'], 'pack_barcodes' => $oldPacks],
            ['name' => $name, 'barcode' => $barcode, 'pack_barcodes' => $this->packBarcodes($productId)],
            $userId, $ip
        );
    }

    /**
     * Links one more barcode to a product: the piece barcode (pack size 1,
     * replacing the old one) or, for store goods only, a pack barcode.
     */
    public function assignBarcode(int $productId, string $code, int $packSize, ?int $userId, ?string $ip): void
    {
        $old = $this->find($productId);
        if (!$old) {
            throw new \RuntimeException('ไม่พบสินค้า');
        }
        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 64) {
            throw new \RuntimeException('บาร์โค้ดไม่ถูกต้อง');
        }
        if ($packSize === 1) {
            if ($old['barcode'] === $code) {
                return;
            }
            $packs = $old['consignor_id'] === null ? $this->packBarcodes($productId) : [];
            $packs = array_values(array_filter($packs, fn ($p) => $p['barcode'] !== $code));
        } else {
            if ($old['consignor_id'] !== null) {
                throw new \RuntimeException('สินค้าฝากขายใช้ได้เฉพาะบาร์โค้ดชิ้น');
            }
            $packs = array_values(array_filter($this->packBarcodes($productId), fn ($p) => $p['barcode'] !== $code));
            $packs[] = ['barcode' => $code, 'pack_size' => $packSize];
        }
        $barcode = $packSize === 1 ? $code : ($old['barcode'] === $code ? null : $old['barcode']);
        if ($old['consignor_id'] === null) {
            $this->updateBarcodes($productId, $barcode, $packs, $userId, $ip);
            return;
        }
        $stmt = $this->db->prepare(
            'SELECT name FROM products WHERE barcode = ? AND id <> ?
             UNION SELECT p.name FROM product_pack_barcodes b JOIN products p ON p.id = b.product_id WHERE b.barcode = ? LIMIT 1'
        );
        $stmt->execute([$code, $productId, $code]);
        $owner = $stmt->fetchColumn();
        if ($owner !== false) {
            throw new \RuntimeException("บาร์โค้ด {$code} ใช้กับสินค้า \"{$owner}\" อยู่แล้ว");
        }
        $this->db->prepare('UPDATE products SET barcode = ?, updated_at = NOW() WHERE id = ?')->execute([$code, $productId]);
        $this->audit->log('products', $productId, 'update', ['barcode' => $old['barcode']], ['barcode' => $code], $userId, $ip);
    }

    /** Sets the product photo (an attachment token). */
    /**
     * A store product that is really a consignor's goods (never went through receiving):
     * hand it to the consignor. The sale price stays; the consignor gets $costPrice per piece
     * and the rest is the store's fee. Sales already made stay store sales.
     */
    public function convertToConsignment(int $productId, int $consignorId, float $costPrice, ?int $userId, ?string $ip): void
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $old = $stmt->fetch();
        if (!$old) {
            throw new \RuntimeException('ไม่พบสินค้า');
        }
        if ($old['consignor_id'] !== null) {
            throw new \RuntimeException('สินค้านี้เป็นสินค้าฝากขายอยู่แล้ว');
        }
        $salePrice = (float) $old['sale_price'];
        $costPrice = round($costPrice, 2);
        if ($costPrice < 0 || $costPrice > $salePrice) {
            throw new \RuntimeException('ราคาที่ผู้ฝากได้ต้องไม่ติดลบและไม่เกินราคาขาย ฿' . number_format($salePrice, 2));
        }
        $new = [
            'consignor_id' => $consignorId,
            'cost_price' => $costPrice,
            'fee_per_unit' => round($salePrice - $costPrice, 2),
            'round_per_unit' => 0,
            'rounding_beneficiary' => 'store',
            'listing_fee_status' => 'waived',
            'listing_fee_amount' => 0,
        ];
        $this->db->prepare(
            'UPDATE products SET consignor_id = ?, cost_price = ?, fee_per_unit = ?, round_per_unit = ?,
                rounding_beneficiary = ?, listing_fee_status = ?, listing_fee_amount = ? WHERE id = ?'
        )->execute([...array_values($new), $productId]);
        $this->audit->log(
            'products', $productId, 'update',
            array_intersect_key($old, $new),
            $new + ['note' => 'เปลี่ยนสินค้าของร้านเป็นสินค้าฝากขาย'],
            $userId, $ip
        );
    }

    /**
     * Sales of a consignment product made while it was still recorded as the store's
     * (before convertToConsignment): move them to the consignor and credit the ledger,
     * the same way a sale of it would today. Call inside a transaction.
     *
     * @return array{lines:int, qty:int, sale:float, fee:float}
     */
    public function claimStoreSales(int $productId, \App\Services\LedgerService $ledger, \App\Services\DocumentNumberService $docs, ?int $userId, ?string $ip): array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product || $product['consignor_id'] === null) {
            throw new \RuntimeException('สินค้านี้ยังไม่ใช่สินค้าฝากขาย');
        }
        $stmt = $this->db->prepare('SELECT id, qty, unit_price FROM sale_items WHERE product_id = ? AND consignor_id IS NULL FOR UPDATE');
        $stmt->execute([$productId]);
        $lines = $stmt->fetchAll();
        $out = ['lines' => count($lines), 'qty' => 0, 'sale' => 0.0, 'fee' => 0.0];
        if (!$lines) {
            return $out;
        }

        $consignorId = (int) $product['consignor_id'];
        $feePerUnit = (float) $product['fee_per_unit'] + ($product['rounding_beneficiary'] === 'store' ? (float) $product['round_per_unit'] : 0);
        $upd = $this->db->prepare('UPDATE sale_items SET consignor_id = ?, fee_amount = ?, cost_amount = ? WHERE id = ?');
        foreach ($lines as $l) {
            $qty = (int) $l['qty'];
            $unit = (float) $l['unit_price'];
            $upd->execute([$consignorId, round($feePerUnit * $qty, 2), round(($unit - $feePerUnit) * $qty, 2), $l['id']]);
            $out['qty'] += $qty;
            $out['sale'] += $unit * $qty;
            $out['fee'] += $feePerUnit * $qty;
        }
        $out['sale'] = round($out['sale'], 2);
        $out['fee'] = round($out['fee'], 2);

        $ledger->append(
            $consignorId, 'sale',
            "ขายสินค้าก่อนเปลี่ยนเป็นสินค้าฝากขาย {$out['qty']} ชิ้น - {$product['name']}",
            $docs->next('ADJ', 'ledger_entries', 'ref_no'),
            $out['sale'], 0, $userId
        );
        if ($out['fee'] > 0) {
            $ledger->append(
                $consignorId, 'fee',
                "หักค่าฝากขาย {$out['qty']} ชิ้น - {$product['name']}",
                $docs->next('FEE', 'ledger_entries', 'ref_no'),
                0, $out['fee'], $userId
            );
        }
        $this->audit->log(
            'products', $productId, 'update', null,
            ['note' => 'โอนยอดขายก่อนเปลี่ยนเป็นสินค้าฝากขายเข้าบัญชีผู้ฝาก', 'consignor_id' => $consignorId] + $out,
            $userId, $ip
        );
        return $out;
    }

    public function setImage(int $productId, string $token, ?int $userId, ?string $ip): void
    {
        $old = $this->find($productId);
        if (!$old) {
            throw new \RuntimeException('ไม่พบสินค้า');
        }
        $this->db->prepare('UPDATE products SET image_token = ?, updated_at = NOW() WHERE id = ?')->execute([$token, $productId]);
        $this->audit->log('products', $productId, 'update', ['image_token' => $old['image_token']], ['image_token' => $token], $userId, $ip);
    }

    public function forConsignor(int $consignorId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE consignor_id = ? ORDER BY name ASC');
        $stmt->execute([$consignorId]);
        return $stmt->fetchAll();
    }

    public function inStockForConsignor(int $consignorId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE consignor_id = ? AND qty_stock > 0 ORDER BY name ASC');
        $stmt->execute([$consignorId]);
        return $stmt->fetchAll();
    }

    public function create(array $data, ?int $userId, ?string $ip): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO products
                (consignor_id, name, barcode, cost_price, sale_price, fee_per_unit, round_per_unit, rounding_beneficiary,
                 qty_received, qty_stock, mfg_date, expiry_date, listing_fee_status, listing_fee_amount, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $data['consignor_id'],
            $data['name'],
            $data['barcode'] ?? null,
            $data['cost_price'],
            $data['sale_price'],
            $data['fee_per_unit'],
            $data['round_per_unit'] ?? 0,
            $data['rounding_beneficiary'] ?? 'store',
            $data['qty'],
            $data['qty'],
            $data['mfg_date'] ?? null,
            $data['expiry_date'] ?? null,
            $data['listing_fee_status'],
            $data['listing_fee_amount'],
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->audit->log('products', $id, 'create', null, $this->find($id), $userId, $ip);
        return $id;
    }

    public function addStock(int $productId, int $qty, ?int $userId, ?string $ip): void
    {
        $old = $this->find($productId);
        $stmt = $this->db->prepare(
            'UPDATE products SET qty_received = qty_received + ?, qty_stock = qty_stock + ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$qty, $qty, $productId]);
        $this->audit->log('products', $productId, 'update', $old, $this->find($productId), $userId, $ip);
    }

    public function applyReturn(int $productId, int $qty, ?int $userId, ?string $ip): void
    {
        $old = $this->find($productId);
        $stmt = $this->db->prepare(
            'UPDATE products SET qty_returned = qty_returned + ?, qty_stock = qty_stock - ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$qty, $qty, $productId]);
        $this->audit->log('products', $productId, 'update', $old, $this->find($productId), $userId, $ip);
    }

    public function applySale(int $productId, int $qty, ?int $userId, ?string $ip): void
    {
        $old = $this->find($productId);
        $stmt = $this->db->prepare(
            'UPDATE products SET qty_sold = qty_sold + ?, qty_stock = qty_stock - ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$qty, $qty, $productId]);
        $this->audit->log('products', $productId, 'update', $old, $this->find($productId), $userId, $ip);
    }

    public function topSellers(int $days = 7, int $limit = 5): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.name, SUM(si.qty) AS qty
             FROM sale_items si
             JOIN sales s ON s.id = si.sale_id
             JOIN products p ON p.id = si.product_id
             WHERE s.sale_datetime >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY p.id, p.name
             ORDER BY qty DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $days, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
