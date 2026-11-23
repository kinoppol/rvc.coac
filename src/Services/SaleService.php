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
     * @param array<int,array{product_id:int, qty:int, unit_price?:float, pack_size?:int}> $items unit_price defaults to the product's
     *        sale price; pack_size > 1 sells whole packs (qty in pieces) at that pack's sale price
     * @param array{sale_datetime?:string, business_date?:string, after_hours?:bool, allow_short_stock?:bool, note?:string} $opts
     *        sale_datetime for sales recorded after the fact (imported from an external POS); business_date is the
     *        accounting day (defaults to the day of sale_datetime; later for a sale made after the store closed);
     *        allow_short_stock lets stock go below zero
     */
    public function record(array $items, int $cashierId, ?string $ip, array $opts = []): array
    {
        if (empty($items)) {
            throw new \RuntimeException('ไม่มีรายการขาย');
        }
        $saleAt = $opts['sale_datetime'] ?? date('Y-m-d H:i:s');
        $businessDate = $opts['business_date'] ?? substr($saleAt, 0, 10);
        $afterHours = !empty($opts['after_hours']);
        $note = isset($opts['note']) ? " ({$opts['note']})" : '';

        // Callers that also write other rows (e.g. a POS import) keep their own transaction.
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            // Bundle promotions (migration 0086), web POS only: worked out on the cart as the cashier saw it
            // (a sale group as one line), then carried onto the member lines below.
            $promoTotal = 0.0;
            if (!empty($opts['promos']) && PromoService::ready($this->db)) {
                [$items, $promoTotal] = (new PromoService($this->db))->annotate($items);
                if (isset($opts['expect_discount']) && abs($promoTotal - (float) $opts['expect_discount']) >= 0.005) {
                    throw new PromoChangedException('ส่วนลดโปรโมชั่นเปลี่ยนไป (ตอนนี้ลด ฿' . number_format($promoTotal, 2)
                        . ') — ตรวจยอดในตะกร้าแล้วชำระเงินอีกครั้ง');
                }
            }
            // The buyer (migration 0088): lines that used points take their discount from the points held for them.
            $memberId = (int) ($opts['member_id'] ?? 0);
            $memberId = $memberId > 0 && MemberService::ready($this->db) ? $memberId : 0;
            $members = $memberId ? new MemberService($this->db, $this->audit) : null;
            $holds = [];
            $pointWarnings = [];
            if ($members) {
                [$items, $holds, $pointWarnings] = $members->applyToSale(
                    $items,
                    $memberId,
                    !empty($opts['offline']),
                    isset($opts['member_verify']) ? (string) $opts['member_verify'] : null,
                    !array_key_exists('can_verify', $opts) || !empty($opts['can_verify'])
                );
            } else {
                foreach ($items as $i => $l) {
                    unset($items[$i]['points_discount']);
                }
            }
            foreach ($items as $i => $l) {
                $items[$i]['_line'] = $i;
            }
            $pointsByLine = [];
            // A sale group (migration 0084) sold as one item: one line per member product, taken in turn.
            $items = (new SaleGroupService($this->db, $this->audit))->expand($items, !empty($opts['allow_short_stock']));
            $docNo = $this->docs->next('SL', 'sales', 'doc_no');
            $totalQty = 0;
            $totalValue = 0.0;
            $lineDiscounts = 0.0;

            $insSale = $this->db->prepare(
                'INSERT INTO sales (doc_no, cashier_id, sale_datetime, business_date, after_hours, total_qty, total_value, created_at)
                 VALUES (?, ?, ?, ?, ?, 0, 0, NOW())'
            );
            $insSale->execute([$docNo, $cashierId, $saleAt, $businessDate, (int) $afterHours]);
            $saleId = (int) $this->db->lastInsertId();

            $insItem = $this->db->prepare(
                'INSERT INTO sale_items (sale_id, product_id, consignor_id, qty, unit_price, fee_amount, cost_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            // Group fee/credit postings per consignor so each sale produces one
            // ledger credit + one ledger debit per consignor, as in the mockup.
            $perConsignor = [];
            $insPack = null; // sale_items with pack_size (migration 0039), prepared on the first pack line

            foreach ($items as $line) {
                $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
                $stmt->execute([$line['product_id']]);
                $product = $stmt->fetch();
                if (!$product) {
                    throw new \RuntimeException('ไม่พบสินค้า');
                }
                $qty = (int) $line['qty'];
                // Discontinued goods hold no stock on record: one still on the shelf can be sold by its barcode.
                $mayShort = !empty($opts['allow_short_stock']) || !empty($product['discontinued_at']);
                if ($qty <= 0 || ($qty > (int) $product['qty_stock'] && !$mayShort)) {
                    throw new \RuntimeException('จำนวนขายเกินสต๊อกคงเหลือของ ' . $product['name']);
                }

                $promoOff = round((float) ($line['promo_discount'] ?? 0), 2);
                $pointsOff = round((float) ($line['points_discount'] ?? 0), 2);
                $fig = $this->lineFigures($product, $qty, isset($line['unit_price']) ? (float) $line['unit_price'] : null, (int) ($line['pack_size'] ?? 1), $promoOff + $pointsOff);
                // The line's price caps both discounts: the promotion first, points take what is left.
                $promoPart = min($promoOff, $fig['discount']);
                $pointsPart = round($fig['discount'] - $promoPart, 2);
                if ($pointsOff > 0 && isset($line['_line'])) {
                    $pointsByLine[$line['_line']] = ($pointsByLine[$line['_line']] ?? 0) + $pointsPart;
                }
                $unitPrice = $fig['unit_price'];
                $packSize = $fig['pack_size'];
                $feeOutPerUnit = $fig['fee_per_unit'];
                $isStoreOwned = $product['consignor_id'] === null;

                $row = [
                    $saleId,
                    $product['id'],
                    $product['consignor_id'],
                    $qty,
                    $unitPrice,
                    $fig['fee_amount'],
                    $fig['cost_amount'],
                ];
                if ($packSize > 1) {
                    $insPack ??= $this->db->prepare(
                        'INSERT INTO sale_items (sale_id, product_id, consignor_id, qty, unit_price, fee_amount, cost_amount, pack_size)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insPack->execute([...$row, $packSize]);
                } else {
                    $insItem->execute($row);
                }
                $itemId = (int) $this->db->lastInsertId();
                if ($promoPart > 0) {
                    $this->db->prepare('UPDATE sale_items SET promo_discount = ?, promo_id = ? WHERE id = ?')
                        ->execute([$promoPart, $line['promo_id'] ?? null, $itemId]);
                }
                if ($pointsPart > 0) {
                    $this->db->prepare('UPDATE sale_items SET points_discount = ? WHERE id = ?')->execute([$pointsPart, $itemId]);
                }

                $left = $this->products->applySale((int) $product['id'], $qty, $cashierId, $ip);
                self::noteStockAfter($this->db, $itemId, $left);

                $totalQty += $qty;
                // A promotion's price per piece may run past the satang: the line comes to its regular price less its discount.
                $lineValue = $fig['discount'] > 0 ? $fig['regular_price'] * $qty - $fig['discount'] : $unitPrice * $qty;
                $totalValue += $lineValue;
                $lineDiscounts += $promoPart;

                if ($isStoreOwned) {
                    continue;
                }

                $cid = (int) $product['consignor_id'];
                $perConsignor[$cid]['sale_value'] = ($perConsignor[$cid]['sale_value'] ?? 0) + $lineValue;
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
                    $businessDate
                );
                if (abs($agg['fee_value']) >= 0.005) {
                    // Below zero only when a promotion took more off consigned goods than the store's fee:
                    // the store bears the discount, the consignor still gets their full share.
                    $feeRef = $this->docs->next('FEE', 'ledger_entries', 'ref_no');
                    $this->ledger->append(
                        $consignorId,
                        'fee',
                        $agg['fee_value'] > 0 ? "หักค่าฝากขาย {$agg['qty']} ชิ้น" : "ส่วนลดโปรโมชั่นที่ร้านรับภาระเกินค่าฝากขาย {$agg['qty']} ชิ้น",
                        $feeRef,
                        0,
                        round($agg['fee_value'], 2),
                        $cashierId,
                        $businessDate
                    );
                }
            }

            // The Android POS works out its promotions itself (offline too) and sends each line's discount.
            if (empty($opts['promos']) && $lineDiscounts > 0) {
                $promoTotal = round($lineDiscounts, 2);
            }
            $upd = $this->db->prepare('UPDATE sales SET total_qty = ?, total_value = ? WHERE id = ?');
            $upd->execute([$totalQty, round($totalValue, 2), $saleId]);
            if ($promoTotal > 0) {
                $this->db->prepare('UPDATE sales SET promo_discount = ? WHERE id = ?')->execute([round($promoTotal, 2), $saleId]);
            }

            $change = 0.0;
            $paymentWarning = null;
            if (isset($opts['payments'])) {
                [$change, $paymentWarning] = $this->recordPayments($saleId, round($totalValue, 2), $opts['payments']);
            }

            $memberResult = null;
            if ($members) {
                $used = [];
                foreach ($holds as $i => $h) {
                    $used[] = ['id' => $h['id'], 'points' => $h['points'], 'held_discount' => $h['discount'], 'discount' => round($pointsByLine[$i] ?? 0, 2)];
                }
                $memberResult = $members->settleSale($saleId, $memberId, round($totalValue, 2), $used, $cashierId, $saleAt, $docNo)
                    + ['warnings' => $pointWarnings];
            }

            $this->audit->log('sales', $saleId, 'create', null, [
                'doc_no' => $docNo, 'total_qty' => $totalQty, 'total_value' => round($totalValue, 2),
                'business_date' => $businessDate, 'after_hours' => $afterHours,
            ] + ($promoTotal > 0 ? ['promo_discount' => round($promoTotal, 2)] : []), $cashierId, $ip);

            if ($own) {
                $this->db->commit();
            }
            return [
                'id' => $saleId, 'doc_no' => $docNo, 'total_qty' => $totalQty, 'total_value' => round($totalValue, 2),
                'change' => $change, 'business_date' => $businessDate, 'after_hours' => $afterHours,
                'payment_warning' => $paymentWarning, 'promo_discount' => round($promoTotal, 2), 'member' => $memberResult,
            ];
        } catch (\Throwable $e) {
            if ($own) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Keeps the product's stock left right after a sale line (sale_items.stock_after, migration 0080),
     * so sales that took the stock below zero can be found in the sales history.
     */
    public static function noteStockAfter(PDO $db, int $saleItemId, int $left): void
    {
        if ($saleItemId > 0 && \App\Support\Db::hasColumn($db, 'sale_items', 'stock_after')) {
            $db->prepare('UPDATE sale_items SET stock_after = ? WHERE id = ?')->execute([$left, $saleItemId]);
        }
    }

    /**
     * Price, fee and cost of selling $qty pieces of $product. $unitPrice defaults to the
     * product's sale price; $packSize > 1 sells whole packs at that pack's sale price.
     * For a consignor's goods fee_per_unit is what the store keeps per piece; for the
     * store's own goods it is the gross margin per piece.
     * @return array{unit_price:float, pack_size:int, fee_per_unit:float, fee_amount:float, cost_amount:float}
     */
    public function lineFigures(array $product, int $qty, ?float $unitPrice, int $packSize = 1, float $discount = 0.0): array
    {
        $unitPrice = $unitPrice !== null ? round($unitPrice, 2) : (float) $product['sale_price'];
        $regular = $unitPrice;
        // A whole pack: qty is still in pieces, each at the pack price per piece.
        if ($packSize > 1) {
            $pack = $this->db->prepare('SELECT sale_price FROM product_pack_barcodes WHERE product_id = ? AND pack_size = ? AND sale_price IS NOT NULL LIMIT 1');
            $pack->execute([$product['id'], $packSize]);
            $packPrice = $pack->fetchColumn();
            if ($packPrice === false || $qty % $packSize !== 0) {
                throw new \RuntimeException("{$product['name']} ไม่มีราคาขายยกแพ็ค {$packSize} ชิ้น");
            }
            $unitPrice = round((float) $packPrice / $packSize, 4);
            $regular = $unitPrice;
            $discount = 0.0; // a whole pack has its own price: no bundle promotion
        }
        // A bundle promotion (PromoService): $discount baht off the whole line.
        $discount = $qty > 0 ? min(max(0.0, round($discount, 2)), round($regular * $qty, 2)) : 0.0;
        $perUnitOff = $qty > 0 ? $discount / $qty : 0.0;
        if ($discount > 0) {
            $unitPrice = round($regular - $perUnitOff, 4);
        }

        if ($product['consignor_id'] === null) {
            // Store-owned goods (from purchase docs): no consignor to credit;
            // fee_amount records the store's gross margin instead.
            $feeOutPerUnit = $unitPrice - (float) $product['cost_price'];
            $costAmount = round((float) $product['cost_price'] * $qty, 2);
        } else {
            $beneficiary = $product['rounding_beneficiary'];
            $feeOutPerUnit = (float) $product['fee_per_unit'] + ($beneficiary === 'store' ? (float) $product['round_per_unit'] : 0);
            // The consignor's share stays that of the regular price: a promotion comes out of the store's fee.
            $costAmount = round(($regular - $feeOutPerUnit) * $qty, 2);
            $feeOutPerUnit -= $perUnitOff;
        }
        return [
            'unit_price' => $unitPrice, 'pack_size' => max(1, $packSize), 'fee_per_unit' => $feeOutPerUnit,
            'fee_amount' => round($feeOutPerUnit * $qty, 2), 'cost_amount' => $costAmount,
            'discount' => $discount, 'regular_price' => $regular,
        ];
    }

    /**
     * Change of this much or more is not believable: no one hands over a whole extra ฿1,000 note (the largest).
     * Such a cash figure is a slip at the till — typically a barcode scanned into the cash box while paying.
     */
    public const MAX_CHANGE = 1000.0;

    /**
     * Saves how a POS sale was paid. Non-cash amounts are taken as exact; cash
     * covers the rest and may be more than that (the difference is change).
     * The cash received only works out the change: the accounts take the cash due. A cash figure
     * giving change of MAX_CHANGE or more is taken as a slip and not kept (received = due, no change),
     * so the sale is still saved; the warning says what was keyed in.
     * @param array{methods:string[], mixed:bool, lines:array<int,array{method:string, amount:float}>} $payments
     * @return array{0:float, 1:?string} change to give back, and a warning when the cash figure was dropped
     */
    private function recordPayments(int $saleId, float $total, array $payments): array
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
        $warning = null;
        if ($change >= self::MAX_CHANGE) {
            $warning = 'จำนวนเงินสดที่รับมาผิดปกติ ฿' . number_format($cash, 2) . ' (อาจสแกนบาร์โค้ดเข้าช่องรับเงิน) — บันทึกเป็นรับเงินพอดี ฿'
                . number_format($cashDue, 2) . ' ไม่มีเงินทอน (ยอดขายและเงินเข้าลิ้นชักไม่เปลี่ยน)';
            $cash = $cashDue;
            $change = 0.0;
        }
        $this->db->prepare('UPDATE sales SET cash_received = ?, change_amount = ? WHERE id = ?')
            ->execute([isset($byMethod['cash']) ? $cash : null, $change, $saleId]);
        return [$change, $warning];
    }
}
