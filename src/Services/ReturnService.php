<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepo;
use PDO;

class ReturnService
{
    public function __construct(
        private PDO $db,
        private ProductRepo $products,
        private DocumentNumberService $docs,
        private AuditLogger $audit
    ) {
    }

    /** @param array<int,array{product_id:int, qty:int}> $items */
    public function returnGoods(int $consignorId, array $items, ?string $recipientName, int $userId, ?string $ip): array
    {
        if (empty($items)) {
            throw new \RuntimeException('ไม่มีรายการคืนสินค้า');
        }

        $this->db->beginTransaction();
        try {
            $docNo = $this->docs->next('RT', 'return_documents', 'doc_no');
            $insDoc = $this->db->prepare(
                'INSERT INTO return_documents (doc_no, consignor_id, user_id, recipient_name, total_qty, total_value, created_at)
                 VALUES (?, ?, ?, ?, 0, 0, NOW())'
            );
            $insDoc->execute([$docNo, $consignorId, $userId, $recipientName]);
            $docId = (int) $this->db->lastInsertId();

            $insItem = $this->db->prepare(
                'INSERT INTO return_items (return_document_id, product_id, qty, sale_price) VALUES (?, ?, ?, ?)'
            );

            $totalQty = 0;
            $totalValue = 0.0;

            foreach ($items as $line) {
                $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
                $stmt->execute([$line['product_id']]);
                $product = $stmt->fetch();
                if (!$product || (int) $product['consignor_id'] !== $consignorId) {
                    throw new \RuntimeException('ไม่พบสินค้าของผู้ฝากรายนี้');
                }
                $qty = (int) $line['qty'];
                if ($qty <= 0 || $qty > (int) $product['qty_stock']) {
                    throw new \RuntimeException('คืนได้ไม่เกินจำนวนคงเหลือของ ' . $product['name']);
                }

                $insItem->execute([$docId, $product['id'], $qty, $product['sale_price']]);
                $this->products->applyReturn((int) $product['id'], $qty, $userId, $ip);

                $totalQty += $qty;
                $totalValue += $qty * (float) $product['sale_price'];
            }

            $upd = $this->db->prepare('UPDATE return_documents SET total_qty = ?, total_value = ? WHERE id = ?');
            $upd->execute([$totalQty, round($totalValue, 2), $docId]);

            $this->audit->log('return_documents', $docId, 'create', null, [
                'doc_no' => $docNo, 'total_qty' => $totalQty, 'total_value' => round($totalValue, 2),
            ], $userId, $ip);

            $this->db->commit();
            return ['id' => $docId, 'doc_no' => $docNo, 'total_qty' => $totalQty, 'total_value' => round($totalValue, 2)];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Recent return documents (newest first), optionally matching a doc no., consignor, recipient or product name. */
    public function documents(string $q = '', int $limit = 50): array
    {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $stmt = $this->db->prepare(
            "SELECT rd.id, rd.doc_no, rd.created_at, rd.recipient_name, rd.total_qty, rd.total_value, rd.cancelled_at, rd.cancel_reason,
                    c.code AS consignor_code, c.name AS consignor_name, u.full_name AS user_name,
                    (SELECT COUNT(*) FROM return_items ri WHERE ri.return_document_id = rd.id) AS items,
                    (SELECT GROUP_CONCAT(p.name ORDER BY ri.id SEPARATOR ', ') FROM return_items ri JOIN products p ON p.id = ri.product_id
                      WHERE ri.return_document_id = rd.id) AS names
             FROM return_documents rd
             JOIN consignors c ON c.id = rd.consignor_id
             LEFT JOIN users u ON u.id = rd.user_id
             WHERE ? = '' OR rd.doc_no LIKE ? OR c.name LIKE ? OR c.code LIKE ? OR rd.recipient_name LIKE ?
                OR EXISTS (SELECT 1 FROM return_items ri JOIN products p ON p.id = ri.product_id WHERE ri.return_document_id = rd.id AND p.name LIKE ?)
             ORDER BY rd.id DESC
             LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute([$q, $like, $like, $like, $like, $like]);
        return $stmt->fetchAll();
    }

    /** One return document with its lines and each lot's current stock. */
    public function document(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT rd.*, c.code AS consignor_code, c.name AS consignor_name, u.full_name AS user_name, cu.full_name AS cancelled_by_name
             FROM return_documents rd JOIN consignors c ON c.id = rd.consignor_id LEFT JOIN users u ON u.id = rd.user_id
             LEFT JOIN users cu ON cu.id = rd.cancelled_by
             WHERE rd.id = ?'
        );
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc) {
            return null;
        }
        $items = $this->db->prepare(
            'SELECT ri.id, ri.product_id, ri.qty, ri.sale_price, p.name, p.barcode, p.expiry_date, p.qty_stock
             FROM return_items ri JOIN products p ON p.id = ri.product_id
             WHERE ri.return_document_id = ? ORDER BY ri.id'
        );
        $items->execute([$id]);
        $doc['items'] = $items->fetchAll();
        return $doc;
    }

    /** Admin: cancels a return with a reason; its goods go back into the store's stock (the document stays). */
    public function cancel(int $docId, string $reason, int $userId, ?string $ip): array
    {
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่ยกเลิก');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM return_documents WHERE id = ? FOR UPDATE');
            $stmt->execute([$docId]);
            $doc = $stmt->fetch();
            if (!$doc) {
                throw new \RuntimeException('ไม่พบใบคืนสินค้า');
            }
            if (!array_key_exists('cancelled_at', $doc)) {
                throw new \RuntimeException('ยังยกเลิกใบคืนสินค้าไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0050) ก่อน');
            }
            if ($doc['cancelled_at'] !== null) {
                throw new \RuntimeException('ใบคืนสินค้านี้ถูกยกเลิกไปแล้ว');
            }
            $stmt = $this->db->prepare('SELECT product_id, qty FROM return_items WHERE return_document_id = ?');
            $stmt->execute([$docId]);
            $back = $this->db->prepare(
                'UPDATE products SET qty_returned = GREATEST(qty_returned - ?, 0), qty_stock = qty_stock + ?, updated_at = NOW() WHERE id = ?'
            );
            foreach ($stmt->fetchAll() as $line) {
                $back->execute([(int) $line['qty'], (int) $line['qty'], (int) $line['product_id']]);
            }
            $this->db->prepare('UPDATE return_documents SET cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?')
                ->execute([$userId, $reason, $docId]);
            $this->audit->log('return_documents', $docId, 'cancel',
                ['doc_no' => $doc['doc_no'], 'total_qty' => (int) $doc['total_qty'], 'total_value' => (float) $doc['total_value']],
                ['cancel_reason' => $reason], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->document($docId);
    }
}
