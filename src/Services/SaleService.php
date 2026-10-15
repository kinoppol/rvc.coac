<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepo;
use PDO;

/**
 * Records a point-of-sale transaction. Not one of the 9 mocked-up screens,
 * but every dashboard/report/ledger figure in the design depends on sales
 * existing, so this small "บันทึกการขาย" capability drives them.
 */
class SaleService
{
    public function __construct(
        private PDO $db,
        private ProductRepo $products,
        private LedgerService $ledger,
        private DocumentNumberService $docs,
        private ListingFeeService $listingFees,
        private AuditLogger $audit
    ) {
    }

    /**
     * @param array<int,array{product_id:int, qty:int}> $items
     */
    public function record(array $items, int $cashierId, ?string $ip): array
    {
        if (empty($items)) {
            throw new \RuntimeException('ไม่มีรายการขาย');
        }

        $this->db->beginTransaction();
        try {
            $docNo = $this->docs->next('SL', 'sales', 'doc_no');
            $totalQty = 0;
            $totalValue = 0.0;

            $insSale = $this->db->prepare(
                'INSERT INTO sales (doc_no, cashier_id, sale_datetime, total_qty, total_value, created_at)
                 VALUES (?, ?, NOW(), 0, 0, NOW())'
            );
            $insSale->execute([$docNo, $cashierId]);
            $saleId = (int) $this->db->lastInsertId();

            $insItem = $this->db->prepare(
                'INSERT INTO sale_items (sale_id, product_id, consignor_id, qty, unit_price, fee_amount, cost_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            // Group fee/credit postings per consignor so each sale produces one
            // ledger credit + one ledger debit per consignor, as in the mockup.
            $perConsignor = [];

            foreach ($items as $line) {
                $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
                $stmt->execute([$line['product_id']]);
                $product = $stmt->fetch();
                if (!$product) {
                    throw new \RuntimeException('ไม่พบสินค้า');
                }
                $qty = (int) $line['qty'];
                if ($qty <= 0 || $qty > (int) $product['qty_stock']) {
                    throw new \RuntimeException('จำนวนขายเกินสต๊อกคงเหลือของ ' . $product['name']);
                }

                $unitPrice = (float) $product['sale_price'];
                $isStoreOwned = $product['consignor_id'] === null;

                if ($isStoreOwned) {
                    // Store-owned goods (from purchase docs): no consignor to credit;
                    // fee_amount records the store's gross margin instead.
                    $feeOutPerUnit = $unitPrice - (float) $product['cost_price'];
                    $costAmount = round((float) $product['cost_price'] * $qty, 2);
                } else {
                    $feePerUnit = (float) $product['fee_per_unit'];
                    $roundPerUnit = (float) $product['round_per_unit'];
                    $beneficiary = $product['rounding_beneficiary'];

                    $feeOutPerUnit = $feePerUnit + ($beneficiary === 'store' ? $roundPerUnit : 0);
                    $costAmount = round(($unitPrice - $feeOutPerUnit) * $qty, 2);
                }

                $insItem->execute([
                    $saleId,
                    $product['id'],
                    $product['consignor_id'],
                    $qty,
                    $unitPrice,
                    round($feeOutPerUnit * $qty, 2),
                    $costAmount,
                ]);

                $this->products->applySale((int) $product['id'], $qty, $cashierId, $ip);

                $totalQty += $qty;
                $totalValue += $unitPrice * $qty;

                if ($isStoreOwned) {
                    continue;
                }

                $cid = (int) $product['consignor_id'];
                $perConsignor[$cid]['sale_value'] = ($perConsignor[$cid]['sale_value'] ?? 0) + $unitPrice * $qty;
                $perConsignor[$cid]['fee_value'] = ($perConsignor[$cid]['fee_value'] ?? 0) + $feeOutPerUnit * $qty;
                $perConsignor[$cid]['qty'] = ($perConsignor[$cid]['qty'] ?? 0) + $qty;
                $perConsignor[$cid]['lines'] = ($perConsignor[$cid]['lines'] ?? 0) + 1;

                $this->listingFees->maybeRefund((int) $product['id'], $this->ledger, $this->docs, $cashierId);
            }

            foreach ($perConsignor as $consignorId => $agg) {
                $this->ledger->append(
                    $consignorId,
                    'sale',
                    "ขายสินค้า {$agg['lines']} รายการ {$agg['qty']} ชิ้น",
                    $docNo,
                    round($agg['sale_value'], 2),
                    0,
                    $cashierId
                );
                if ($agg['fee_value'] > 0) {
                    $feeRef = $this->docs->next('FEE', 'ledger_entries', 'ref_no');
                    $this->ledger->append(
                        $consignorId,
                        'fee',
                        "หักค่าฝากขาย {$agg['qty']} ชิ้น",
                        $feeRef,
                        0,
                        round($agg['fee_value'], 2),
                        $cashierId
                    );
                }
            }

            $upd = $this->db->prepare('UPDATE sales SET total_qty = ?, total_value = ? WHERE id = ?');
            $upd->execute([$totalQty, round($totalValue, 2), $saleId]);

            $this->audit->log('sales', $saleId, 'create', null, [
                'doc_no' => $docNo, 'total_qty' => $totalQty, 'total_value' => round($totalValue, 2),
            ], $cashierId, $ip);

            $this->db->commit();
            return ['id' => $saleId, 'doc_no' => $docNo, 'total_qty' => $totalQty, 'total_value' => round($totalValue, 2)];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
