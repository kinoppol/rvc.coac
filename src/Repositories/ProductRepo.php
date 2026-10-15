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
                 qty_received, qty_stock, expiry_date, listing_fee_status, listing_fee_amount, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
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
