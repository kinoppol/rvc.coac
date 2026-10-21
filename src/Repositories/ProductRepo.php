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
        $stmt = $this->db->prepare('SELECT barcode, pack_size FROM product_pack_barcodes WHERE product_id = ? ORDER BY pack_size ASC, id ASC');
        $stmt->execute([$productId]);
        return array_map(fn ($r) => ['barcode' => $r['barcode'], 'pack_size' => (int) $r['pack_size']], $stmt->fetchAll());
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
     * @param array<int,array{barcode:string,pack_size:int}> $packs
     */
    /** Name (null = keep), piece barcode and pack barcodes of a store product. */
    public function updateBarcodes(int $productId, ?string $barcode, array $packs, ?int $userId, ?string $ip, ?string $name = null): void
    {
        $old = $this->find($productId);
        if (!$old || $old['consignor_id'] !== null) {
            throw new \RuntimeException('ไม่พบสินค้าของร้าน');
        }
        $name = $name === null ? $old['name'] : trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw new \RuntimeException('กรุณากรอกชื่อสินค้า');
        }
        $name = mb_substr($name, 0, 191);
        $barcode = trim((string) $barcode) ?: null;
        $clean = [];
        foreach ($packs as $p) {
            $code = trim((string) ($p['barcode'] ?? ''));
            $size = (int) ($p['pack_size'] ?? 0);
            if ($code === '') {
                continue;
            }
            if ($size < 2 || $size > 1000) {
                throw new \RuntimeException("บาร์โค้ดแพ็ค {$code}: จำนวนชิ้นต่อแพ็คต้องอยู่ระหว่าง 2–1000");
            }
            if ($code === $barcode || isset($clean[$code])) {
                throw new \RuntimeException("บาร์โค้ด {$code} ซ้ำกัน");
            }
            $clean[$code] = $size;
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
            if (!in_array($p['barcode'], $keep, true)) {
                $del->execute([$productId, $p['barcode']]);
            }
        }
        $upsert = $this->db->prepare(
            'INSERT INTO product_pack_barcodes (product_id, barcode, pack_size, created_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE pack_size = VALUES(pack_size)'
        );
        foreach ($clean as $code => $size) {
            $upsert->execute([$productId, (string) $code, $size]);
        }
        $this->audit->log(
            'products', $productId, 'update',
            ['name' => $old['name'], 'barcode' => $old['barcode'], 'pack_barcodes' => $oldPacks],
            ['name' => $name, 'barcode' => $barcode, 'pack_barcodes' => $this->packBarcodes($productId)],
            $userId, $ip
        );
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
