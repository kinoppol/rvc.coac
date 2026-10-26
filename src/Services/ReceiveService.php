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
     * @param array<int,array{name:string, cost_price:float, qty:int, mfg_date:string, barcode?:string, expiry_date?:string}> $items
     * @param string|null $receiveDate Y-m-d of an earlier day the goods were received (backdated entry); null = now
     */
    public function receive(int $consignorId, array $items, int $userId, ?string $ip, ?int $requestId = null, ?string $receiveDate = null): array
    {
        // Backdated: keep the current time of day on the chosen date.
        $at = $receiveDate !== null ? new \DateTimeImmutable($receiveDate . ' ' . date('H:i:s')) : new \DateTimeImmutable('now');
        $atSql = $at->format('Y-m-d H:i:s');

        if (empty($items)) {
            throw new \RuntimeException('ไม่มีรายการรับฝาก');
        }
        $items = ConsignRequestRepo::cleanItems($items, 500);
        if (!$this->consignors->find($consignorId)) {
            throw new \RuntimeException('ไม่พบผู้ฝากขาย');
        }

        $plan = $this->listingFees->planForBatch($consignorId, count($items));
        $beneficiary = $this->fees->roundingBeneficiary();

        $this->db->beginTransaction();
        try {
            $docNo = $this->docs->next('RC', 'receive_documents', 'doc_no', $at);

            $insDoc = $this->db->prepare(
                'INSERT INTO receive_documents
                    (doc_no, consignor_id, user_id, total_qty, total_value, listing_fee_total, waived_count, waived_amount, created_at)
                 VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?)'
            );
            $insDoc->execute([
                $docNo, $consignorId, $userId,
                $plan['charged_total'], $plan['waived_count'], $plan['waived_total'], $atSql,
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
                    'mfg_date' => $item['mfg_date'],
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'listing_fee_status' => $isWaived ? 'waived' : 'charged',
                    'listing_fee_amount' => $listingFeeCharged,
                ], $userId, $ip);
                if ($receiveDate !== null) {
                    $this->db->prepare('UPDATE products SET created_at = ? WHERE id = ?')->execute([$atSql, $productId]);
                }

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
                    $userId,
                    $at->format('Y-m-d')
                );
            }

            $this->audit->log('receive_documents', $docId, 'create', null, [
                'doc_no' => $docNo, 'total_qty' => $totalQty, 'total_value' => round($totalValue, 2),
            ] + ($receiveDate !== null ? ['backdated_to' => $receiveDate] : []), $userId, $ip);

            $this->db->commit();
            return ['id' => $docId, 'doc_no' => $docNo, 'total_qty' => $totalQty, 'total_value' => round($totalValue, 2), 'listing_fee_plan' => $plan];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Recent receive documents (newest first), optionally matching a doc no., consignor or product name. */
    public function documents(string $q = '', int $limit = 50): array
    {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $stmt = $this->db->prepare(
            "SELECT rd.id, rd.doc_no, rd.created_at, rd.total_qty, rd.total_value, rd.listing_fee_total,
                    c.code AS consignor_code, c.name AS consignor_name, u.full_name AS user_name,
                    (SELECT COUNT(*) FROM receive_items ri WHERE ri.receive_document_id = rd.id) AS items,
                    (SELECT GROUP_CONCAT(p.name ORDER BY ri.id SEPARATOR ', ') FROM receive_items ri JOIN products p ON p.id = ri.product_id
                      WHERE ri.receive_document_id = rd.id) AS names
             FROM receive_documents rd
             JOIN consignors c ON c.id = rd.consignor_id
             LEFT JOIN users u ON u.id = rd.user_id
             WHERE ? = '' OR rd.doc_no LIKE ? OR c.name LIKE ? OR c.code LIKE ?
                OR EXISTS (SELECT 1 FROM receive_items ri JOIN products p ON p.id = ri.product_id WHERE ri.receive_document_id = rd.id AND p.name LIKE ?)
             ORDER BY rd.id DESC
             LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute([$q, $like, $like, $like, $like]);
        return $stmt->fetchAll();
    }

    /** One receive document with its lines and each lot's current figures (sold, returned, in stock). */
    public function document(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT rd.*, c.code AS consignor_code, c.name AS consignor_name, u.full_name AS user_name
             FROM receive_documents rd JOIN consignors c ON c.id = rd.consignor_id LEFT JOIN users u ON u.id = rd.user_id
             WHERE rd.id = ?'
        );
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc) {
            return null;
        }
        $items = $this->db->prepare(
            'SELECT ri.id, ri.product_id, ri.qty, ri.cost_price, ri.sale_price, ri.fee_per_unit, ri.listing_fee_charged,
                    p.name, p.barcode, p.mfg_date, p.expiry_date, p.qty_sold, p.qty_returned, p.qty_stock
             FROM receive_items ri JOIN products p ON p.id = ri.product_id
             WHERE ri.receive_document_id = ? ORDER BY ri.id'
        );
        $items->execute([$id]);
        $doc['items'] = $items->fetchAll();
        return $doc;
    }

    /**
     * Admin correction of one received line: name, barcode, quantity, prices and dates.
     * The quantity moves the lot's stock by the difference (it cannot drop below what has
     * already left the lot); prices apply to sales from now on; listing fees stay as charged.
     * @param array{name?:string, barcode?:string, qty?:int, cost_price?:float, sale_price?:float, mfg_date?:string, expiry_date?:string} $data
     */
    public function updateItem(int $itemId, array $data, int $userId, ?string $ip): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM receive_items WHERE id = ? FOR UPDATE');
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if (!$item) {
                throw new \RuntimeException('ไม่พบรายการรับฝาก');
            }
            $productId = (int) $item['product_id'];
            $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            $date = function (string $label, mixed $v): ?string {
                $v = trim((string) $v);
                if ($v === '') {
                    return null;
                }
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                if (!$d || $d->format('Y-m-d') !== $v) {
                    throw new \RuntimeException("{$label}ไม่ถูกต้อง");
                }
                return $v;
            };
            $mfg = array_key_exists('mfg_date', $data) ? $date('วันที่ผลิต', $data['mfg_date']) : $product['mfg_date'];
            $expiry = array_key_exists('expiry_date', $data) ? $date('วันหมดอายุ', $data['expiry_date']) : $product['expiry_date'];
            if ($mfg !== null && $expiry !== null && $expiry < $mfg) {
                throw new \RuntimeException('วันหมดอายุต้องไม่ก่อนวันที่ผลิต');
            }

            // Name and piece barcode. Another lot of this consignor may share the barcode;
            // anyone else's product may not.
            $name = array_key_exists('name', $data) ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $data['name'])), 0, 191) : $product['name'];
            if ($name === '') {
                throw new \RuntimeException('กรุณากรอกชื่อสินค้า');
            }
            $barcode = array_key_exists('barcode', $data) ? (trim((string) $data['barcode']) ?: null) : $product['barcode'];
            if ($barcode !== null && $barcode !== $product['barcode']) {
                $clash = $this->db->prepare(
                    'SELECT p.name FROM products p WHERE p.barcode = ? AND p.id <> ? AND NOT (p.consignor_id <=> ?)
                     UNION SELECT p.name FROM product_pack_barcodes b JOIN products p ON p.id = b.product_id WHERE b.barcode = ? LIMIT 1'
                );
                $clash->execute([$barcode, $productId, $product['consignor_id'], $barcode]);
                if (($owner = $clash->fetchColumn()) !== false) {
                    throw new \RuntimeException("บาร์โค้ด {$barcode} ใช้กับสินค้า \"{$owner}\" อยู่แล้ว");
                }
            }
            $this->db->prepare('UPDATE products SET name = ?, barcode = ? WHERE id = ?')->execute([$name, $barcode, $productId]);

            $qty = array_key_exists('qty', $data) ? (int) $data['qty'] : (int) $item['qty'];
            if ($qty < 1 || $qty > 100000) {
                throw new \RuntimeException('จำนวนต้องอย่างน้อย 1 ชิ้น');
            }
            $delta = $qty - (int) $item['qty'];
            if ((int) $product['qty_stock'] + $delta < 0) {
                $gone = (int) $item['qty'] - (int) $product['qty_stock'];
                throw new \RuntimeException("ล็อตนี้ขาย/คืน/ปรับยอดไปแล้ว {$gone} ชิ้น จำนวนรับฝากต้องไม่น้อยกว่า {$gone}");
            }
            if ($delta !== 0) {
                $this->db->prepare('UPDATE products SET qty_received = qty_received + ?, qty_stock = qty_stock + ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$delta, $delta, $productId]);
            }
            $this->db->prepare('UPDATE products SET mfg_date = ?, expiry_date = ? WHERE id = ?')->execute([$mfg, $expiry, $productId]);

            if (isset($data['cost_price'], $data['sale_price'])) {
                $cost = (float) $data['cost_price'];
                $this->products->setConsignPrice($productId, $cost, (float) $data['sale_price'], $this->fees->priceFor($cost), $userId, $ip);
            }
            $p = $this->products->find($productId);
            $this->db->prepare('UPDATE receive_items SET qty = ?, cost_price = ?, sale_price = ?, fee_per_unit = ? WHERE id = ?')
                ->execute([$qty, $p['cost_price'], $p['sale_price'], round((float) $p['sale_price'] - (float) $p['cost_price'], 2), $itemId]);

            $docId = (int) $item['receive_document_id'];
            $this->db->prepare(
                'UPDATE receive_documents rd SET
                    total_qty = (SELECT COALESCE(SUM(qty), 0) FROM receive_items WHERE receive_document_id = rd.id),
                    total_value = (SELECT COALESCE(SUM(qty * sale_price), 0) FROM receive_items WHERE receive_document_id = rd.id)
                 WHERE rd.id = ?'
            )->execute([$docId]);

            $this->audit->log('receive_items', $itemId, 'update',
                ['qty' => (int) $item['qty'], 'cost_price' => (float) $item['cost_price'], 'sale_price' => (float) $item['sale_price'],
                 'name' => $product['name'], 'barcode' => $product['barcode'], 'mfg_date' => $product['mfg_date'], 'expiry_date' => $product['expiry_date']],
                ['qty' => $qty, 'cost_price' => (float) $p['cost_price'], 'sale_price' => (float) $p['sale_price'],
                 'name' => $p['name'], 'barcode' => $p['barcode'], 'mfg_date' => $mfg, 'expiry_date' => $expiry],
                $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->document($docId);
    }
}
