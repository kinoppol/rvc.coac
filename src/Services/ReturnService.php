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
}
