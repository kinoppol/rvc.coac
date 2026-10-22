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
     * @param array<int,array{product_id:int, qty:int, unit_price?:float}> $items unit_price defaults to the product's sale price
     * @param array{sale_datetime?:string, allow_short_stock?:bool, note?:string} $opts sale_datetime for sales recorded
     *        after the fact (imported from an external POS); allow_short_stock lets stock go below zero
     */
    public function record(array $items, int $cashierId, ?string $ip, array $opts = []): array
    {
        if (empty($items)) {
            throw new \RuntimeException('ไม่มีรายการขาย');
        }
        $saleAt = $opts['sale_datetime'] ?? date('Y-m-d H:i:s');
        $note = isset($opts['note']) ? " ({$opts['note']})" : '';

        // Callers that also write other rows (e.g. a POS import) keep their own transaction.
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $docNo = $this->docs->next('SL', 'sales', 'doc_no');
            $totalQty = 0;
            $totalValue = 0.0;

            $insSale = $this->db->prepare(
                'INSERT INTO sales (doc_no, cashier_id, sale_datetime, total_qty, total_value, created_at)
                 VALUES (?, ?, ?, 0, 0, NOW())'
            );
            $insSale->execute([$docNo, $cashierId, $saleAt]);
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
                if ($qty <= 0 || ($qty > (int) $product['qty_stock'] && empty($opts['allow_short_stock']))) {
                    throw new \RuntimeException('จำนวนขายเกินสต๊อกคงเหลือของ ' . $product['name']);
                }

                $unitPrice = isset($line['unit_price']) ? round((float) $line['unit_price'], 2) : (float) $product['sale_price'];
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
                    "ขายสินค้า {$agg['lines']} รายการ {$agg['qty']} ชิ้น{$note}",
                    $docNo,
                    round($agg['sale_value'], 2),
                    0,
                    $cashierId,
                    substr($saleAt, 0, 10)
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
                        $cashierId,
                        substr($saleAt, 0, 10)
                    );
                }
            }

            $upd = $this->db->prepare('UPDATE sales SET total_qty = ?, total_value = ? WHERE id = ?');
            $upd->execute([$totalQty, round($totalValue, 2), $saleId]);

            $change = 0.0;
            if (isset($opts['payments'])) {
                $change = $this->recordPayments($saleId, round($totalValue, 2), $opts['payments']);
            }

            $this->audit->log('sales', $saleId, 'create', null, [
                'doc_no' => $docNo, 'total_qty' => $totalQty, 'total_value' => round($totalValue, 2),
            ], $cashierId, $ip);

            if ($own) {
                $this->db->commit();
            }
            return ['id' => $saleId, 'doc_no' => $docNo, 'total_qty' => $totalQty, 'total_value' => round($totalValue, 2), 'change' => $change];
        } catch (\Throwable $e) {
            if ($own) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Saves how a POS sale was paid. Non-cash amounts are taken as exact; cash
     * covers the rest and may be more than that (the difference is change).
     * @param array{methods:string[], mixed:bool, lines:array<int,array{method:string, amount:float}>} $payments
     * @return float change to give back
     */
    private function recordPayments(int $saleId, float $total, array $payments): float
    {
        $byMethod = [];
        foreach ($payments['lines'] as $p) {
            $method = (string) ($p['method'] ?? '');
            $amount = round((float) ($p['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            if (!in_array($method, $payments['methods'], true)) {
                throw new \RuntimeException('ร้านไม่ได้เปิดรับชำระด้วยวิธีนี้');
            }
            $byMethod[$method] = ($byMethod[$method] ?? 0) + $amount;
        }
        if (!$byMethod) {
            throw new \RuntimeException('กรุณาระบุการชำระเงิน');
        }
        if (count($byMethod) > 1 && !$payments['mixed']) {
            throw new \RuntimeException('ร้านไม่ได้เปิดรับการชำระแบบผสม');
        }
        $cash = $byMethod['cash'] ?? 0.0;
        $nonCash = round(array_sum($byMethod) - $cash, 2);
        if ($nonCash > $total + 0.004) {
            throw new \RuntimeException('ยอดชำระที่ไม่ใช่เงินสดเกินยอดขาย');
        }
        $cashDue = round($total - $nonCash, 2);
        if ($cash < $cashDue - 0.004) {
            throw new \RuntimeException('ยอดชำระยังไม่ครบ ขาดอีก ' . number_format($cashDue - $cash, 2));
        }

        $ins = $this->db->prepare('INSERT INTO sale_payments (sale_id, method, amount) VALUES (?, ?, ?)');
        foreach ($byMethod as $method => $amount) {
            $applied = $method === 'cash' ? $cashDue : $amount;
            if ($applied > 0) {
                $ins->execute([$saleId, $method, $applied]);
            }
        }
        $change = isset($byMethod['cash']) ? round($cash - $cashDue, 2) : 0.0;
        $this->db->prepare('UPDATE sales SET cash_received = ?, change_amount = ? WHERE id = ?')
            ->execute([isset($byMethod['cash']) ? $cash : null, $change, $saleId]);
        return $change;
    }
}
