<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConsignRequestRepo;
use App\Repositories\ConsignorRepo;
use App\Repositories\ProductRepo;
use PDO;

class ReceiveService
{
    public function __construct(
        private PDO $db,
        private ProductRepo $products,
        private ConsignorRepo $consignors,
        private FeeCalculator $fees,
        private ListingFeeService $listingFees,
        private LedgerService $ledger,
        private DocumentNumberService $docs,
        private AuditLogger $audit
    ) {
    }

    /**
     * @param array<int,array{name:string, cost_price:float, qty:int, barcode?:string, expiry_date?:string}> $items
     */
    public function receive(int $consignorId, array $items, int $userId, ?string $ip, ?int $requestId = null): array
    {
        if (empty($items)) {
            throw new \RuntimeException('ไม่มีรายการรับฝาก');
        }
        if (!$this->consignors->find($consignorId)) {
            throw new \RuntimeException('ไม่พบผู้ฝากขาย');
        }

        $plan = $this->listingFees->planForBatch($consignorId, count($items));
        $beneficiary = $this->fees->roundingBeneficiary();

        $this->db->beginTransaction();
        try {
            $docNo = $this->docs->next('RC', 'receive_documents', 'doc_no');

            $insDoc = $this->db->prepare(
                'INSERT INTO receive_documents
                    (doc_no, consignor_id, user_id, total_qty, total_value, listing_fee_total, waived_count, waived_amount, created_at)
                 VALUES (?, ?, ?, 0, 0, ?, ?, ?, NOW())'
            );
            $insDoc->execute([
                $docNo, $consignorId, $userId,
                $plan['charged_total'], $plan['waived_count'], $plan['waived_total'],
            ]);
            $docId = (int) $this->db->lastInsertId();

            $insItem = $this->db->prepare(
                'INSERT INTO receive_items
                    (receive_document_id, product_id, cost_price, sale_price, fee_per_unit, qty, listing_fee_charged, is_new_listing)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
            );

            $totalQty = 0;
            $totalValue = 0.0;

            foreach ($items as $i => $item) {
                $calc = $this->fees->priceFor((float) $item['cost_price']);
                $isWaived = $i < $plan['waived_count'];
                $listingFeeCharged = $isWaived ? 0.0 : $plan['fee'];

                $productId = $this->products->create([
                    'consignor_id' => $consignorId,
                    'name' => $item['name'],
                    'barcode' => $item['barcode'] ?? null,
                    'cost_price' => $item['cost_price'],
                    'sale_price' => $calc['price'],
                    'fee_per_unit' => $calc['fee'],
                    'round_per_unit' => $calc['round'],
                    'rounding_beneficiary' => $beneficiary,
                    'qty' => $item['qty'],
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'listing_fee_status' => $isWaived ? 'waived' : 'charged',
                    'listing_fee_amount' => $listingFeeCharged,
                ], $userId, $ip);

                $insItem->execute([
                    $docId, $productId, $item['cost_price'], $calc['price'], $calc['fee'],
                    $item['qty'], $listingFeeCharged,
                ]);

                $totalQty += (int) $item['qty'];
                $totalValue += $calc['price'] * (int) $item['qty'];
            }

            $updDoc = $this->db->prepare('UPDATE receive_documents SET total_qty = ?, total_value = ? WHERE id = ?');
            $updDoc->execute([$totalQty, round($totalValue, 2), $docId]);

            // Received from a consignor's request: close it in the same transaction.
            if ($requestId !== null
                && !(new ConsignRequestRepo($this->db, $this->audit))->close($requestId, 'received', $docId, null, $userId, $ip)) {
                throw new \RuntimeException('คำขอฝากนี้ถูกดำเนินการไปแล้ว กรุณารีเฟรชหน้า');
            }

            if ($plan['charged_total'] > 0) {
                $refNo = $this->docs->next('LF', 'ledger_entries', 'ref_no');
                $from = $plan['waived_count'] + 1;
                $to = count($items);
                $this->ledger->append(
                    $consignorId,
                    'listing_fee',
                    "ค่าทำข้อมูลรายการที่ {$from}-{$to}",
                    $refNo,
                    0,
                    $plan['charged_total'],
                    $userId
                );
            }

            $this->audit->log('receive_documents', $docId, 'create', null, [
                'doc_no' => $docNo, 'total_qty' => $totalQty, 'total_value' => round($totalValue, 2),
            ], $userId, $ip);

            $this->db->commit();
            return ['id' => $docId, 'doc_no' => $docNo, 'total_qty' => $totalQty, 'total_value' => round($totalValue, 2), 'listing_fee_plan' => $plan];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
