<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Handles the ค่าทำข้อมูล (listing/data-entry fee) rules: a free quota of
 * the consignor's first N listings, then a flat fee per new listing, with an
 * automatic refund once a listing sells past a quantity threshold.
 */
class ListingFeeService
{
    public function __construct(private PDO $db, private SettingsService $settings)
    {
    }

    public function existingListingCount(int $consignorId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM products WHERE consignor_id = ?');
        $stmt->execute([$consignorId]);
        return (int) $stmt->fetchColumn();
    }

    public function freeLeft(int $consignorId): int
    {
        $freeListings = (int) ($this->settings->get('free_listings') ?? 5);
        return max(0, $freeListings - $this->existingListingCount($consignorId));
    }

    /**
     * @param int $newListingCount how many *new* products are in this receive batch
     * @return array{fee: float, waived_count: int, charged_count: int, waived_total: float, charged_total: float}
     */
    public function planForBatch(int $consignorId, int $newListingCount): array
    {
        $fee = (float) ($this->settings->get('listing_fee') ?? 5);
        $freeLeft = $this->freeLeft($consignorId);
        $waivedCount = min($newListingCount, $freeLeft);
        $chargedCount = max(0, $newListingCount - $freeLeft);

        return [
            'fee' => $fee,
            'waived_count' => $waivedCount,
            'charged_count' => $chargedCount,
            'waived_total' => round($waivedCount * $fee, 2),
            'charged_total' => round($chargedCount * $fee, 2),
        ];
    }

    public function waiveQty(): int
    {
        return (int) ($this->settings->get('waive_qty') ?? 20);
    }

    /**
     * Call after a sale updates products.qty_sold. If a product crosses the
     * waive threshold and its listing fee was charged (not yet refunded),
     * credit the fee back to the consignor via a ledger entry.
     */
    public function maybeRefund(int $productId, LedgerService $ledger, DocumentNumberService $docs, ?int $userId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            return;
        }

        $alreadyRefunded = (bool) $product['listing_fee_refunded'];
        $wasCharged = $product['listing_fee_status'] === 'charged' && (float) $product['listing_fee_amount'] > 0;
        if ($alreadyRefunded || !$wasCharged) {
            return;
        }

        if ((int) $product['qty_sold'] <= $this->waiveQty()) {
            return;
        }

        $refNo = $docs->next('LFR', 'ledger_entries', 'ref_no');
        $ledger->append(
            (int) $product['consignor_id'],
            'listing_fee_refund',
            'คืนค่าทำข้อมูล (ขายได้เกิน ' . $this->waiveQty() . ' ชิ้น) - ' . $product['name'],
            $refNo,
            (float) $product['listing_fee_amount'],
            0,
            $userId
        );

        $upd = $this->db->prepare('UPDATE products SET listing_fee_refunded = 1 WHERE id = ?');
        $upd->execute([$productId]);
    }
}
