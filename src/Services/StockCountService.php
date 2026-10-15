<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class StockCountService
{
    public function __construct(private PDO $db, private DocumentNumberService $docs, private AuditLogger $audit)
    {
    }

    public function start(int $userId): int
    {
        $docNo = $this->docs->next('SC', 'stock_counts', 'doc_no');
        $this->db->beginTransaction();
        try {
            $ins = $this->db->prepare(
                'INSERT INTO stock_counts (doc_no, count_date, status, created_by, created_at) VALUES (?, CURDATE(), \'draft\', ?, NOW())'
            );
            $ins->execute([$docNo, $userId]);
            $countId = (int) $this->db->lastInsertId();

            $products = $this->db->query(
                'SELECT id, qty_stock FROM products WHERE qty_stock > 0 ORDER BY name ASC'
            )->fetchAll();

            $itemStmt = $this->db->prepare(
                'INSERT INTO stock_count_items (stock_count_id, product_id, system_qty) VALUES (?, ?, ?)'
            );
            foreach ($products as $p) {
                $itemStmt->execute([$countId, $p['id'], $p['qty_stock']]);
            }

            $this->db->commit();
            return $countId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param array<int,array{item_id:int, counted_qty:int, note?:string}> $items */
    public function saveCounts(int $stockCountId, array $items): void
    {
        $upd = $this->db->prepare(
            'UPDATE stock_count_items SET counted_qty = ?, diff_qty = ? - system_qty, note = ? WHERE id = ? AND stock_count_id = ?'
        );
        foreach ($items as $item) {
            $counted = (int) $item['counted_qty'];
            $upd->execute([$counted, $counted, $item['note'] ?? null, (int) $item['item_id'], $stockCountId]);
        }
    }

    public function diffSummary(int $stockCountId): array
    {
        $stmt = $this->db->prepare(
            "SELECT sci.*, p.name, p.sale_price, COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name
             FROM stock_count_items sci
             JOIN products p ON p.id = sci.product_id
             LEFT JOIN consignors c ON c.id = p.consignor_id
             WHERE sci.stock_count_id = ?
             ORDER BY p.name ASC"
        );
        $stmt->execute([$stockCountId]);
        $items = $stmt->fetchAll();

        $diffCount = 0;
        $diffValue = 0.0;
        foreach ($items as $item) {
            if ($item['diff_qty'] !== null && (int) $item['diff_qty'] !== 0) {
                $diffCount++;
                $diffValue += ((int) $item['diff_qty']) * (float) $item['sale_price'];
            }
        }

        return ['items' => $items, 'diff_count' => $diffCount, 'diff_value' => round($diffValue, 2)];
    }

    public function submit(int $stockCountId): void
    {
        $summary = $this->diffSummary($stockCountId);
        if ($summary['diff_count'] === 0) {
            throw new \RuntimeException('ไม่พบผลต่าง ไม่สามารถส่งรายงานได้');
        }
        $stmt = $this->db->prepare(
            "UPDATE stock_counts SET status='submitted', submitted_at=NOW() WHERE id = ?"
        );
        $stmt->execute([$stockCountId]);
    }

    public function saveDraft(int $stockCountId): void
    {
        // Already draft by default; explicit no-op endpoint kept for UI symmetry with "submit".
    }

    /** Manager/admin approves the diff report; adjusts product stock to match physical count. */
    public function approve(int $stockCountId, int $userId, ?string $ip): void
    {
        $summary = $this->diffSummary($stockCountId);
        $this->db->beginTransaction();
        try {
            foreach ($summary['items'] as $item) {
                if ($item['diff_qty'] === null || (int) $item['diff_qty'] === 0) {
                    continue;
                }
                $before = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
                $before->execute([$item['product_id']]);
                $old = $before->fetch();

                $upd = $this->db->prepare('UPDATE products SET qty_stock = ? WHERE id = ?');
                $upd->execute([(int) $item['counted_qty'], $item['product_id']]);

                $new = $old;
                $new['qty_stock'] = (int) $item['counted_qty'];
                $this->audit->log('products', (int) $item['product_id'], 'update', $old, $new, $userId, $ip);
            }

            $stmt = $this->db->prepare(
                "UPDATE stock_counts SET status='approved', approved_by=?, approved_at=NOW() WHERE id = ?"
            );
            $stmt->execute([$userId, $stockCountId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function get(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM stock_counts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function list(int $limit = 20): array
    {
        $stmt = $this->db->prepare('SELECT * FROM stock_counts ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
