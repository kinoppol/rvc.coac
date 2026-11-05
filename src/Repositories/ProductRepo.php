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

    /** Store product a pack barcode belongs to, with that pack's size. */
    public function findByPackBarcode(string $barcode): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, b.pack_size AS pack_size FROM product_pack_barcodes b JOIN products p ON p.id = b.product_id
             WHERE b.barcode = ? AND p.consignor_id IS NULL LIMIT 1'
        );
        $stmt->execute([$barcode]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array{barcode:string,pack_size:int}> */
    public function packBarcodes(int $productId): array
    {
        // SELECT * so this still works before migration 0039 adds sale_price.
        $stmt = $this->db->prepare('SELECT * FROM product_pack_barcodes WHERE product_id = ? ORDER BY pack_size ASC, id ASC');
        $stmt->execute([$productId]);
        return array_map(fn ($r) => [
            'barcode' => $r['barcode'], 'pack_size' => (int) $r['pack_size'],
            'sale_price' => isset($r['sale_price']) ? (float) $r['sale_price'] : null,
        ], $stmt->fetchAll());
    }

    /** Links a pack barcode to a product (moving it if it pointed elsewhere). */
    public function savePackBarcode(int $productId, string $barcode, int $packSize): void
    {
        $this->db->prepare(
            'INSERT INTO product_pack_barcodes (product_id, barcode, pack_size, created_at, last_seen_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), pack_size = VALUES(pack_size), last_seen_at = NOW()'
        )->execute([$productId, $barcode, $packSize]);
        // The Android POS fetches only products changed since its last sync (DeviceController::catalog).
        $this->db->prepare('UPDATE products SET updated_at = NOW() WHERE id = ?')->execute([$productId]);
    }

    /**
     * Sets a store product's piece barcode and its full list of pack barcodes.
     * A barcode may identify only one thing: the piece of one product, or one pack.
     *
     * @param array<int,array{barcode:string,pack_size:int,sale_price?:?float}> $packs sale_price: whole-pack price, null = not sold whole
     */
    /**
     * Name (null = keep), piece barcode and pack barcodes of a store product.
     * With $allowConsigned a consignment product may be edited too, piece barcode only.
     */
    /** Sets the piece sale price of a store product (admin / manager, from the inventory editor). */
    public function setSalePrice(int $productId, float $price, ?int $userId, ?string $ip): void
    {
        $old = $this->find($productId);
        if (!$old || $old['consignor_id'] !== null) {
            throw new \RuntimeException('กำหนดราคาขายได้เฉพาะสินค้าของร้าน');
        }
        $price = round($price, 2);
        if ($price <= 0 || $price > 1000000) {
            throw new \RuntimeException('ราคาขายไม่ถูกต้อง');
        }
        if (abs((float) $old['sale_price'] - $price) < 0.005) {
            return;
        }
        $this->db->prepare('UPDATE products SET sale_price = ?, updated_at = NOW() WHERE id = ?')->execute([$price, $productId]);
        $this->audit->log('products', $productId, 'update', ['sale_price' => (float) $old['sale_price']], ['sale_price' => $price], $userId, $ip);
    }

    /**
     * A store product booked at cost 0 (stock that was on the shelf, cost not known) gets the
     * cost found out later: the product's piece cost, and every past sale line of it still at
     * cost 0 (POS and imported sales alike), so the profit of past days is counted right.
     * A cost set this way can be corrected while no purchase has given the product its cost:
     * the sale lines at the old cost (a sale books cost × qty) move to the new one too.
     *
     * @return array{lines:int, qty:int, cost:float}
     */
    public function setKnownCost(int $productId, float $cost, ?int $userId, ?string $ip): array
    {
        $old = $this->find($productId);
        if (!$old || $old['consignor_id'] !== null) {
            throw new \RuntimeException('ระบุต้นทุนได้เฉพาะสินค้าของร้าน');
        }
        $oldCost = round((float) $old['cost_price'], 2);
        if ($oldCost >= 0.005 && $this->hasPurchaseCost($productId)) {
            throw new \RuntimeException('ต้นทุนของสินค้านี้คิดจากใบซื้อแล้ว แก้ที่ใบซื้อแทน (ระบุ/แก้ต้นทุนเองได้เฉพาะสินค้าที่ยังไม่มีใบซื้อ)');
        }
        $cost = round($cost, 2);
        if ($cost <= 0 || $cost > 1000000) {
            throw new \RuntimeException('ต้นทุน/ชิ้นไม่ถูกต้อง');
        }
        if (abs($cost - $oldCost) < 0.005) {
            return ['lines' => 0, 'qty' => 0, 'cost' => 0.0, 'old_cost' => $oldCost];
        }
        // Lines still at cost 0, and (a correction) the lines booked at the old cost.
        $match = 'product_id = ? AND consignor_id IS NULL AND qty > 0 AND (cost_amount = 0 OR (? > 0 AND cost_amount = ROUND(qty * ?, 2)))';
        $params = [$productId, $oldCost, $oldCost];
        $stmt = $this->db->prepare("SELECT COUNT(*) AS line_count, COALESCE(SUM(qty), 0) AS qty FROM sale_items WHERE $match");
        $stmt->execute($params);
        $past = $stmt->fetch();
        $this->db->prepare(
            "UPDATE sale_items SET cost_amount = ROUND(qty * ?, 2), fee_amount = ROUND(qty * unit_price - qty * ?, 2) WHERE $match"
        )->execute([$cost, $cost, ...$params]);
        $this->db->prepare('UPDATE products SET cost_price = ?, updated_at = NOW() WHERE id = ?')->execute([$cost, $productId]);
        $out = ['lines' => (int) $past['line_count'], 'qty' => (int) $past['qty'], 'cost' => round((int) $past['qty'] * $cost, 2), 'old_cost' => $oldCost];
        $this->audit->log('products', $productId, 'update', ['cost_price' => $oldCost], [
            'cost_price' => $cost,
            'note' => $oldCost > 0
                ? 'แก้ต้นทุนที่ระบุเอง และปรับต้นทุนของยอดขายย้อนหลังตามต้นทุนใหม่'
                : 'ระบุต้นทุนที่สืบทราบภายหลัง และเติมต้นทุนให้ยอดขายย้อนหลังที่ต้นทุนเป็น 0',
            'past_sales' => $out,
        ], $userId, $ip);
        return $out;
    }

    /** The product's cost comes from a purchase (a received purchase line of it). */
    private function hasPurchaseCost(int $productId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
             WHERE pi.product_id = ? AND pi.item_type = 'stock' AND pd.status = 'active' LIMIT 1"
        );
        $stmt->execute([$productId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Admin correction of a consigned product's prices: what the consignor gets per piece and
     * the shelf price. The store's fee becomes the difference, so later sales credit the
     * consignor exactly $cost per piece; past sales and ledger entries are left as they were.
     * $calc is FeeCalculator::priceFor($cost), kept as is when the shelf price matches it.
     */
    public function setConsignPrice(int $productId, float $cost, float $price, array $calc, ?int $userId, ?string $ip): void
    {
        $old = $this->find($productId);
        if (!$old || $old['consignor_id'] === null) {
            throw new \RuntimeException('ไม่พบสินค้าฝากขาย');
        }
        $cost = round($cost, 2);
        $price = round($price, 2);
        if ($price <= 0 || $price > 1000000) {
            throw new \RuntimeException('ราคาขายไม่ถูกต้อง');
        }
        if ($cost < 0 || $cost > $price) {
            throw new \RuntimeException('ราคาที่ผู้ฝากได้ต้องอยู่ระหว่าง 0 ถึงราคาขาย');
        }
        if (abs((float) $old['cost_price'] - $cost) < 0.005 && abs((float) $old['sale_price'] - $price) < 0.005) {
            return;
        }
        [$fee, $round] = abs((float) $calc['price'] - $price) < 0.005 ? [(float) $calc['fee'], (float) $calc['round']] : [round($price - $cost, 2), 0.0];
        if ($round > 0 && $old['rounding_beneficiary'] !== 'store') {
            // The consignor keeps the rounding: their share is $cost + $round, so fold it into the fee instead.
            [$fee, $round] = [round($price - $cost, 2), 0.0];
        }
        $this->db->prepare('UPDATE products SET cost_price = ?, sale_price = ?, fee_per_unit = ?, round_per_unit = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$cost, $price, $fee, $round, $productId]);
        $this->audit->log('products', $productId, 'update',
            ['cost_price' => (float) $old['cost_price'], 'sale_price' => (float) $old['sale_price'], 'fee_per_unit' => (float) $old['fee_per_unit'], 'round_per_unit' => (float) $old['round_per_unit']],
            ['cost_price' => $cost, 'sale_price' => $price, 'fee_per_unit' => $fee, 'round_per_unit' => $round], $userId, $ip);
    }

    /**
     * @param array{barcode?:?string, packs?:string[]}|null $orig what the editor showed when it was opened.
     *        Given, barcodes are only changed where the user changed them: a piece barcode left as it
     *        was keeps whatever is saved now (e.g. one linked on a phone meanwhile), one changed over a
     *        piece barcode that changed meanwhile is refused, and only pack barcodes the editor showed
     *        can be removed. Null: the lists given are the whole new state.
     */
    public function updateBarcodes(int $productId, ?string $barcode, array $packs, ?int $userId, ?string $ip, ?string $name = null, bool $allowConsigned = false, ?array $orig = null): void
    {
        $old = $this->find($productId);
        if (!$old || ($old['consignor_id'] !== null && !$allowConsigned)) {
            throw new \RuntimeException('ไม่พบสินค้าของร้าน');
        }
        if ($old['consignor_id'] !== null && array_filter($packs, fn ($p) => trim((string) ($p['barcode'] ?? '')) !== '')) {
            throw new \RuntimeException('สินค้าฝากขายใช้ได้เฉพาะบาร์โค้ดชิ้น');
        }
        $name = $name === null ? $old['name'] : trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw new \RuntimeException('กรุณากรอกชื่อสินค้า');
        }
        $name = mb_substr($name, 0, 191);
        $barcode = trim((string) $barcode) ?: null;
        $removable = null; // pack barcodes this save may remove (null: any)
        if ($orig !== null) {
            $origCode = trim((string) ($orig['barcode'] ?? '')) ?: null;
            $current = trim((string) $old['barcode']) ?: null;
            if ($barcode === $origCode) {
                $barcode = $current; // not touched in the editor: keep what is saved now
            } elseif ($current !== $origCode) {
                throw new \RuntimeException(sprintf(
                    'บาร์โค้ดชิ้นของสินค้านี้ถูกเปลี่ยนเป็น %s ระหว่างที่เปิดหน้าแก้ไขอยู่ (เช่น ผูกจากมือถือ) กรุณาปิดแล้วเปิดหน้าแก้ไขใหม่ แล้วแก้อีกครั้ง',
                    $current ?? '(ไม่มี)'
                ));
            }
            $removable = array_map(fn ($c) => trim((string) $c), (array) ($orig['packs'] ?? []));
        }
        $clean = [];
        $noCode = []; // pack size => price: packs sold whole that have no barcode of their own
        foreach ($packs as $p) {
            $code = trim((string) ($p['barcode'] ?? ''));
            $size = (int) ($p['pack_size'] ?? 0);
            // Price for selling the whole pack (blank = the pack is not sold whole).
            $price = $p['sale_price'] ?? null;
            $price = $price === null || $price === '' ? null : round((float) $price, 2);
            $label = $code !== '' ? "บาร์โค้ดแพ็ค {$code}" : 'แพ็คที่ไม่มีบาร์โค้ด';
            if ($code === '' && $price === null) {
                continue; // an empty row
            }
            if ($size < 2 || $size > 1000) {
                throw new \RuntimeException("{$label}: จำนวนชิ้นต่อแพ็คต้องอยู่ระหว่าง 2–1000");
            }
            if ($price !== null && ($price <= 0 || $price > 1000000)) {
                throw new \RuntimeException("{$label}: ราคาขายยกแพ็คไม่ถูกต้อง");
            }
            if ($code === '') {
                if (isset($noCode[$size])) {
                    throw new \RuntimeException("แพ็ค {$size} ชิ้นที่ไม่มีบาร์โค้ดซ้ำกัน");
                }
                $noCode[$size] = $price;
                continue;
            }
            if ($code === $barcode || isset($clean[$code])) {
                throw new \RuntimeException("บาร์โค้ด {$code} ซ้ำกัน");
            }
            $clean[$code] = ['size' => $size, 'price' => $price];
        }
        $check = function (string $sql, array $params): ?string {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $name = $stmt->fetchColumn();
            return $name === false ? null : (string) $name;
        };
        foreach (array_filter([$barcode, ...array_keys($clean)]) as $code) {
            $owner = $check('SELECT name FROM products WHERE barcode = ? AND id <> ? LIMIT 1', [$code, $productId])
                ?? $check('SELECT p.name FROM product_pack_barcodes b JOIN products p ON p.id = b.product_id WHERE b.barcode = ? AND b.product_id <> ? LIMIT 1', [$code, $productId]);
            if ($owner !== null) {
                throw new \RuntimeException("บาร์โค้ด {$code} ใช้กับสินค้า \"{$owner}\" อยู่แล้ว");
            }
            $this->assertNotHeld((string) $code, $productId);
        }

        $oldPacks = $this->packBarcodes($productId);
        $this->db->prepare('UPDATE products SET name = ?, barcode = ?, updated_at = NOW() WHERE id = ?')->execute([$name, $barcode, $productId]);
        // Numeric barcodes become int array keys; compare as strings.
        $keep = array_map('strval', array_keys($clean));
        $del = $this->db->prepare('DELETE FROM product_pack_barcodes WHERE product_id = ? AND barcode = ?');
        foreach ($oldPacks as $p) {
            // A pack barcode added since the editor was opened (not in $removable) stays.
            if ($p['barcode'] !== null && !in_array($p['barcode'], $keep, true)
                && ($removable === null || in_array((string) $p['barcode'], $removable, true))) {
                $del->execute([$productId, $p['barcode']]);
            }
        }
        // Packs without a barcode have nothing to match on: replace them all.
        $this->db->prepare('DELETE FROM product_pack_barcodes WHERE product_id = ? AND barcode IS NULL')->execute([$productId]);
        $insNoCode = $this->db->prepare('INSERT INTO product_pack_barcodes (product_id, barcode, pack_size, sale_price, created_at) VALUES (?, NULL, ?, ?, NOW())');
        foreach ($noCode as $size => $price) {
            try {
                $insNoCode->execute([$productId, $size, $price]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1048) { // barcode still NOT NULL
                    throw new \RuntimeException('ยังบันทึกแพ็คที่ไม่มีบาร์โค้ดไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0040) ก่อน');
                }
                throw $e;
            }
        }
        $upsert = $this->db->prepare(
            'INSERT INTO product_pack_barcodes (product_id, barcode, pack_size, sale_price, created_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE pack_size = VALUES(pack_size), sale_price = VALUES(sale_price)'
        );
        foreach ($clean as $code => $p) {
            $upsert->execute([$productId, (string) $code, $p['size'], $p['price']]);
        }
        $this->audit->log(
            'products', $productId, 'update',
            ['name' => $old['name'], 'barcode' => $old['barcode'], 'pack_barcodes' => $oldPacks],
            ['name' => $name, 'barcode' => $barcode, 'pack_barcodes' => $this->packBarcodes($productId)],
            $userId, $ip
        );
    }

    /**
     * Links one more barcode to a product: the piece barcode (pack size 1,
     * replacing the old one) or, for store goods only, a pack barcode.
     */
    public function assignBarcode(int $productId, string $code, int $packSize, ?int $userId, ?string $ip, bool $replace = false): void
    {
        $old = $this->find($productId);
        if (!$old) {
            throw new \RuntimeException('ไม่พบสินค้า');
        }
        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 64) {
            throw new \RuntimeException('บาร์โค้ดไม่ถูกต้อง');
        }
        // Taking the piece barcode away (replaced, or moved to a pack) only when the user said so.
        $oldCode = trim((string) $old['barcode']);
        if (!$replace && $oldCode !== '' && $oldCode !== $code && $packSize === 1) {
            throw new \App\Support\NeedsConfirmation(
                "«{$old['name']}» มีบาร์โค้ดชิ้น {$oldCode} อยู่แล้ว ถ้าผูก {$code} เป็นบาร์โค้ดชิ้น บาร์โค้ดเดิมจะถูกแทนที่ (สแกน {$oldCode} ที่ POS จะไม่พบสินค้านี้)"
            );
        }
        if (!$replace && $oldCode === $code && $packSize > 1) {
            throw new \App\Support\NeedsConfirmation(
                "{$code} เป็นบาร์โค้ดชิ้นของ «{$old['name']}» อยู่ ถ้าเปลี่ยนเป็นบาร์โค้ดแพ็ค สินค้านี้จะไม่มีบาร์โค้ดชิ้น"
            );
        }
        if ($packSize === 1) {
            if ($old['barcode'] === $code) {
                return;
            }
            $packs = $old['consignor_id'] === null ? $this->packBarcodes($productId) : [];
            $packs = array_values(array_filter($packs, fn ($p) => $p['barcode'] !== $code));
        } else {
            if ($old['consignor_id'] !== null) {
                throw new \RuntimeException('สินค้าฝากขายใช้ได้เฉพาะบาร์โค้ดชิ้น');
            }
            $packs = array_values(array_filter($this->packBarcodes($productId), fn ($p) => $p['barcode'] !== $code));
            $packs[] = ['barcode' => $code, 'pack_size' => $packSize];
        }
        $barcode = $packSize === 1 ? $code : ($old['barcode'] === $code ? null : $old['barcode']);
        if ($old['consignor_id'] === null) {
            $this->updateBarcodes($productId, $barcode, $packs, $userId, $ip);
            return;
        }
        $stmt = $this->db->prepare(
            'SELECT name FROM products WHERE barcode = ? AND id <> ?
             UNION SELECT p.name FROM product_pack_barcodes b JOIN products p ON p.id = b.product_id WHERE b.barcode = ? LIMIT 1'
        );
        $stmt->execute([$code, $productId, $code]);
        $owner = $stmt->fetchColumn();
        if ($owner !== false) {
            throw new \RuntimeException("บาร์โค้ด {$code} ใช้กับสินค้า \"{$owner}\" อยู่แล้ว");
        }
        $this->assertNotHeld($code, $productId);
        $this->db->prepare('UPDATE products SET barcode = ?, updated_at = NOW() WHERE id = ?')->execute([$code, $productId]);
        $this->audit->log('products', $productId, 'update', ['barcode' => $old['barcode']], ['barcode' => $code], $userId, $ip);
    }

    /** Sets the product photo (an attachment token). */
    /**
     * A store product that is really a consignor's goods (never went through receiving):
     * hand it to the consignor. The sale price stays; the consignor gets $costPrice per piece
     * and the rest is the store's fee. Sales already made stay store sales.
     */
    public function convertToConsignment(int $productId, int $consignorId, float $costPrice, ?int $userId, ?string $ip): void
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $old = $stmt->fetch();
        if (!$old) {
            throw new \RuntimeException('ไม่พบสินค้า');
        }
        if ($old['consignor_id'] !== null) {
            throw new \RuntimeException('สินค้านี้เป็นสินค้าฝากขายอยู่แล้ว');
        }
        $salePrice = (float) $old['sale_price'];
        $costPrice = round($costPrice, 2);
        if ($costPrice < 0 || $costPrice > $salePrice) {
            throw new \RuntimeException('ราคาที่ผู้ฝากได้ต้องไม่ติดลบและไม่เกินราคาขาย ฿' . number_format($salePrice, 2));
        }
        $new = [
            'consignor_id' => $consignorId,
            'cost_price' => $costPrice,
            'fee_per_unit' => round($salePrice - $costPrice, 2),
            'round_per_unit' => 0,
            'rounding_beneficiary' => 'store',
            'listing_fee_status' => 'waived',
            'listing_fee_amount' => 0,
        ];
        $this->db->prepare(
            'UPDATE products SET consignor_id = ?, cost_price = ?, fee_per_unit = ?, round_per_unit = ?,
                rounding_beneficiary = ?, listing_fee_status = ?, listing_fee_amount = ? WHERE id = ?'
        )->execute([...array_values($new), $productId]);
        $this->audit->log(
            'products', $productId, 'update',
            array_intersect_key($old, $new),
            $new + ['note' => 'เปลี่ยนสินค้าของร้านเป็นสินค้าฝากขาย'],
            $userId, $ip
        );
    }

    /**
     * Merge duplicate product rows into one (the kept row, usually the older one): every
     * document line, POS mapping and pack barcode is moved to it, quantities are summed and
     * the duplicates are deleted. A listing fee charged for a duplicate is credited back,
     * since the item was never a new listing. Call inside a transaction.
     *
     * @param int[] $dupIds
     * @return array{merged:int, refunded:float}
     */
    public function merge(int $keepId, array $dupIds, \App\Services\LedgerService $ledger, \App\Services\DocumentNumberService $docs, ?int $userId, ?string $ip, ?string $useBarcode = null): array
    {
        $dupIds = array_values(array_unique(array_filter(array_map('intval', $dupIds), fn ($id) => $id > 0 && $id !== $keepId)));
        if (!$dupIds) {
            throw new \RuntimeException('กรุณาเลือกรายการที่จะรวมอย่างน้อย 2 รายการ');
        }
        $ids = [$keepId, ...$dupIds];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM products WHERE id IN ($in) FOR UPDATE");
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[(int) $r['id']] = $r;
        }
        if (count($rows) !== count($ids)) {
            throw new \RuntimeException('ไม่พบสินค้าบางรายการ กรุณารีเฟรชหน้า');
        }
        $keep = $rows[$keepId];
        foreach ($dupIds as $id) {
            if ($rows[$id]['consignor_id'] !== $keep['consignor_id']) {
                throw new \RuntimeException('รวมได้เฉพาะสินค้าของผู้ฝากขายรายเดียวกัน (หรือสินค้าของร้านด้วยกัน)');
            }
        }

        // Before the documents move over: which row was bought / received last.
        $latest = $rows[$this->latestCostRow($rows)];

        // Piece barcode: one barcode (or the same on every row) is simply kept. Different ones:
        // the row bought / received last wins, the others are held for an admin to check.
        $codes = [];
        foreach ($this->rowsByLatest($rows) as $id) {
            $c = trim((string) $rows[$id]['barcode']);
            if ($c !== '' && !isset($codes[$c])) {
                $codes[$c] = $id;
            }
        }
        // Different ones: the user picks which goes on ($useBarcode); none of them is lost.
        if (count($codes) > 1) {
            $useBarcode = trim((string) $useBarcode);
            if ($useBarcode === '') {
                throw new \App\Support\NeedsConfirmation('รายการที่จะรวมมีบาร์โค้ดชิ้นหลายอัน (' . implode(', ', array_map('strval', array_keys($codes))) . ') กรุณาเลือกบาร์โค้ดที่จะใช้ต่อ');
            }
            if (!array_key_exists($useBarcode, $codes)) {
                throw new \RuntimeException("บาร์โค้ด {$useBarcode} ไม่ใช่บาร์โค้ดของรายการที่จะรวม กรุณารีเฟรชหน้า");
            }
            $codes = [$useBarcode => $codes[$useBarcode]] + $codes;
        }
        $barcode = $codes ? (string) array_key_first($codes) : null;
        $held = array_slice($codes, 1, null, true);

        // Photo: the latest one taken goes on; every other photo is kept as a backup (not deleted).
        $photos = $this->photosByLatest($rows);
        $photo = $photos ? (string) array_key_first($photos) : null;
        $photoBackups = array_slice($photos, 1, null, true);

        $refunded = 0.0;
        $sum = ['qty_received' => 0, 'qty_sold' => 0, 'qty_returned' => 0, 'qty_stock' => 0];
        foreach ($dupIds as $id) {
            $dup = $rows[$id];
            foreach ($sum as $col => $_) {
                $sum[$col] += (int) $dup[$col];
            }
            foreach (['receive_items', 'return_items', 'sale_items', 'purchase_items', 'stock_count_items',
                      'stock_count_photos', 'pos_item_mappings', 'product_pack_barcodes'] as $table) {
                $this->db->prepare("UPDATE $table SET product_id = ? WHERE product_id = ?")->execute([$keepId, $id]);
            }
            // Product split documents (migration 0061): pieces split into / out of the duplicate.
            if (\App\Support\Db::hasColumn($this->db, 'product_splits', 'id')) {
                $this->db->prepare('UPDATE product_split_items SET product_id = ? WHERE product_id = ?')->execute([$keepId, $id]);
                $this->db->prepare('UPDATE product_splits SET source_product_id = ? WHERE source_product_id = ?')->execute([$keepId, $id]);
            }
            // Damaged goods written off the duplicate (migration 0066).
            if (\App\Support\Db::hasColumn($this->db, 'damaged_goods', 'id')) {
                $this->db->prepare('UPDATE damaged_goods SET product_id = ? WHERE product_id = ?')->execute([$keepId, $id]);
            }
            // Backups the duplicate already had come along (before migration 0059 there are none).
            try {
                $this->db->prepare('UPDATE IGNORE product_photo_backups SET product_id = ? WHERE product_id = ?')->execute([$keepId, $id]);
            } catch (\PDOException $e) {
            }
            if ($dup['consignor_id'] !== null && $dup['listing_fee_status'] === 'charged'
                && (float) $dup['listing_fee_amount'] > 0 && !(int) $dup['listing_fee_refunded']) {
                $ledger->append(
                    (int) $dup['consignor_id'],
                    'listing_fee_refund',
                    'คืนค่าทำข้อมูล (รายการซ้ำ รวมเข้ากับรายการเดิม) - ' . $dup['name'],
                    $docs->next('LFR', 'ledger_entries', 'ref_no'),
                    (float) $dup['listing_fee_amount'],
                    0,
                    $userId
                );
                $refunded += (float) $dup['listing_fee_amount'];
            }
            $this->db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
            $this->audit->log('products', $id, 'delete', $dup, ['merged_into' => $keepId], $userId, $ip);
        }

        $this->db->prepare(
            'UPDATE products SET qty_received = qty_received + ?, qty_sold = qty_sold + ?, qty_returned = qty_returned + ?,
                qty_stock = qty_stock + ?, barcode = ?, image_token = ?, updated_at = NOW() WHERE id = ?'
        )->execute([...array_values($sum), $barcode, $photo, $keepId]);
        foreach ($photoBackups as $token => $fromId) {
            $this->backupPhoto($keepId, (string) $token, $fromId, "รวมรายการซ้ำ: รูปของรายการ {$fromId} (ใช้รูปล่าสุดแทน)", $userId, true);
        }
        if ($held) {
            try {
                $ins = $this->db->prepare(
                    'INSERT INTO held_barcodes (barcode, product_id, from_product_id, note, user_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), from_product_id = VALUES(from_product_id), note = VALUES(note)'
                );
                foreach ($held as $code => $fromId) {
                    $ins->execute([(string) $code, $keepId, $fromId, mb_substr("รวมรายการซ้ำ: บาร์โค้ดชิ้นของรายการ {$fromId} ไม่ตรงกับ {$barcode} ที่ใช้ต่อ", 0, 255), $userId]);
                }
            } catch (\PDOException $e) {
                throw new \RuntimeException('บาร์โค้ดชิ้นของรายการที่จะรวมไม่ตรงกัน ต้องเก็บไว้ตรวจสอบ: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0055) ก่อน');
            }
        }

        // Cost goes on from the most recently bought / received row (suppliers' prices change).
        // Past costs stay where they were recorded: each sale line keeps its own cost of goods
        // sold, and each purchase / receipt line its unit cost (all now on the kept row).
        $price = $keep['consignor_id'] === null
            ? ['cost_price' => $latest['cost_price']]
            // A consignor's price set belongs together: what they get, the fee and the shelf price.
            : array_intersect_key($latest, array_flip(['cost_price', 'fee_per_unit', 'round_per_unit', 'rounding_beneficiary', 'sale_price']));
        $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($price)));
        $this->db->prepare("UPDATE products SET $set WHERE id = ?")->execute([...array_values($price), $keepId]);

        $this->audit->log('products', $keepId, 'update', $keep, $this->find($keepId) + [
            'merged_from' => $dupIds,
            'cost_from' => (int) $latest['id'],
            'held_barcodes' => array_map('strval', array_keys($held)),
            'photo_backups' => array_map('strval', array_keys($photoBackups)),
            'cost_history' => array_map(fn ($r) => ['id' => (int) $r['id'], 'cost_price' => $r['cost_price'], 'sale_price' => $r['sale_price']], array_values($rows)),
        ], $userId, $ip);

        return ['merged' => count($dupIds), 'refunded' => round($refunded, 2), 'barcode' => $barcode, 'held' => array_map('strval', array_keys($held)),
                'photo_backups' => count($photoBackups)];
    }

    /**
     * Photos of rows being merged, the latest taken first: token => product id.
     *
     * @param array<int, array> $rows id => product row
     * @return array<string,int>
     */
    private function photosByLatest(array $rows): array
    {
        $stmt = $this->db->prepare('SELECT created_at FROM attachments WHERE token = ?');
        $keys = [];
        foreach ($rows as $id => $r) {
            $token = (string) ($r['image_token'] ?? '');
            if ($token === '' || isset($keys[$token])) {
                continue;
            }
            $stmt->execute([$token]);
            $at = (string) ($stmt->fetchColumn() ?: ($r['updated_at'] ?? ''));
            $keys[$token] = [$at . '|' . str_pad((string) $id, 12, '0', STR_PAD_LEFT), (int) $id];
        }
        uasort($keys, fn ($a, $b) => strcmp($b[0], $a[0]));
        return array_map(fn ($k) => $k[1], $keys);
    }

    /**
     * Keeps an older photo of a product. $required: the caller cannot go on without it (a merge
     * would otherwise lose the photo); else a missing table (before migration 0059) is let be.
     */
    private function backupPhoto(int $productId, string $token, ?int $fromId, string $note, ?int $userId, bool $required): void
    {
        try {
            $this->db->prepare(
                'INSERT IGNORE INTO product_photo_backups (product_id, image_token, from_product_id, note, user_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
            )->execute([$productId, $token, $fromId, mb_substr($note, 0, 255), $userId]);
        } catch (\PDOException $e) {
            if ($required) {
                throw new \RuntimeException('สินค้าที่จะรวมมีรูปมากกว่า 1 รูป ต้องเก็บรูปเก่าเป็นรูปสำรอง: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0059) ก่อน');
            }
        }
    }

    /** Backup photos of a product, the latest taken first ([] before migration 0059). */
    public function photoBackups(int $productId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT b.image_token, b.from_product_id, b.note, b.created_at, a.created_at AS taken_at
                 FROM product_photo_backups b LEFT JOIN attachments a ON a.token = b.image_token
                 WHERE b.product_id = ? ORDER BY COALESCE(a.created_at, b.created_at) DESC, b.id DESC'
            );
            $stmt->execute([$productId]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function isPhotoBackup(int $productId, string $token): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT 1 FROM product_photo_backups WHERE product_id = ? AND image_token = ?');
            $stmt->execute([$productId, $token]);
            return (bool) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            return false;
        }
    }

    /** A backup photo becomes the product's photo again; the current one becomes a backup. */
    public function restorePhoto(int $productId, string $token, ?int $userId, ?string $ip): void
    {
        $old = $this->find($productId);
        if (!$old) {
            throw new \RuntimeException('ไม่พบสินค้า');
        }
        if (!$this->isPhotoBackup($productId, $token)) {
            throw new \RuntimeException('ไม่พบรูปสำรองนี้ของสินค้า');
        }
        if ($old['image_token']) {
            $this->backupPhoto($productId, (string) $old['image_token'], null, 'รูปเดิม ก่อนเปลี่ยนกลับไปใช้รูปสำรอง', $userId, true);
        }
        $this->db->prepare('DELETE FROM product_photo_backups WHERE product_id = ? AND image_token = ?')->execute([$productId, $token]);
        $this->db->prepare('UPDATE products SET image_token = ?, updated_at = NOW() WHERE id = ?')->execute([$token, $productId]);
        $this->audit->log('products', $productId, 'update', ['image_token' => $old['image_token']], ['image_token' => $token, 'photo_restored' => true], $userId, $ip);
    }

    /**
     * Of rows being merged, the one bought / received last (store goods: the latest purchase
     * receipt date; consignment: the day the lot was listed), the newer row on a tie. The
     * inventory page picks the same row to show the cost that will be used.
     *
     * @param array<int, array> $rows id => product row
     */
    private function latestCostRow(array $rows): int
    {
        return $this->rowsByLatest($rows)[0];
    }

    /** @param array<int, array> $rows id => product row @return int[] ids, bought / received last first */
    private function rowsByLatest(array $rows): array
    {
        $stmt = $this->db->prepare(
            'SELECT MAX(pd.receipt_date) FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id WHERE pi.product_id = ?'
        );
        $keys = [];
        foreach ($rows as $id => $r) {
            if ($r['consignor_id'] === null) {
                $stmt->execute([$id]);
                $day = (string) ($stmt->fetchColumn() ?: '');
            } else {
                $day = substr((string) $r['created_at'], 0, 10);
            }
            $keys[(int) $id] = $day . '|' . str_pad((string) $id, 12, '0', STR_PAD_LEFT);
        }
        arsort($keys, SORT_STRING);
        return array_keys($keys);
    }

    /** The product a barcode is held for (put aside at a merge), or null; null too before migration 0055. */
    public function heldBy(string $barcode): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT h.*, p.name FROM held_barcodes h LEFT JOIN products p ON p.id = h.product_id WHERE h.barcode = ?');
            $stmt->execute([trim($barcode)]);
            return $stmt->fetch() ?: null;
        } catch (\PDOException $e) {
            return null;
        }
    }

    private function assertNotHeld(string $code, int $productId): void
    {
        $h = $this->heldBy($code);
        if ($h !== null && (int) $h['product_id'] !== $productId) {
            throw new \RuntimeException("บาร์โค้ด {$code} ถูกเก็บไว้ตรวจสอบที่สินค้า \"{$h['name']}\" (จากการรวมรายการซ้ำ) ใช้กับสินค้าอื่นไม่ได้จนกว่าผู้ดูแลระบบจะตรวจสอบและลบออก");
        }
    }

    /** Barcodes held for checking, by product id. @return array<int, array<int, array>> */
    public function heldBarcodes(): array
    {
        try {
            $rows = $this->db->query('SELECT barcode, product_id, from_product_id, note, created_at FROM held_barcodes ORDER BY created_at')->fetchAll();
        } catch (\PDOException $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['product_id']][] = $r;
        }
        return $out;
    }

    /**
     * Marks a product as one that really has no barcode (or takes the mark back), so it is
     * told apart from one whose barcode has not been collected yet. Needs migration 0065.
     */
    public function setNoBarcode(int $productId, bool $none, ?int $userId, ?string $ip): void
    {
        if (!\App\Support\Db::hasColumn($this->db, 'products', 'no_barcode')) {
            throw new \RuntimeException('ยังทำเครื่องหมายไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0065) ก่อน');
        }
        $old = $this->find($productId);
        if (!$old) {
            throw new \RuntimeException('ไม่พบสินค้า กรุณารีเฟรชหน้า');
        }
        if ($none && trim((string) $old['barcode']) !== '') {
            throw new \RuntimeException("สินค้านี้มีบาร์โค้ดชิ้น {$old['barcode']} อยู่แล้ว");
        }
        $this->db->prepare('UPDATE products SET no_barcode = ?, updated_at = NOW() WHERE id = ?')->execute([$none ? 1 : 0, $productId]);
        $this->audit->log('products', $productId, 'update', ['no_barcode' => (int) ($old['no_barcode'] ?? 0)], ['no_barcode' => $none ? 1 : 0], $userId, $ip);
    }

    /** An admin has checked a held barcode (a mistyped entry, say): it is free to use again. */
    public function releaseHeld(int $productId, string $barcode, ?int $userId, ?string $ip): void
    {
        $h = $this->heldBy($barcode);
        if ($h === null || (int) $h['product_id'] !== $productId) {
            throw new \RuntimeException('ไม่พบบาร์โค้ดที่เก็บไว้ตรวจสอบนี้');
        }
        $this->db->prepare('DELETE FROM held_barcodes WHERE barcode = ?')->execute([$h['barcode']]);
        $this->audit->log('products', $productId, 'update', ['held_barcode' => $h['barcode'], 'note' => $h['note']], ['held_barcode' => null], $userId, $ip);
    }

    /**
     * Sales of a consignment product made while it was still recorded as the store's
     * (before convertToConsignment): move them to the consignor and credit the ledger,
     * the same way a sale of it would today. Call inside a transaction.
     *
     * @return array{lines:int, qty:int, sale:float, fee:float}
     */
    public function claimStoreSales(int $productId, \App\Services\LedgerService $ledger, \App\Services\DocumentNumberService $docs, ?int $userId, ?string $ip): array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product || $product['consignor_id'] === null) {
            throw new \RuntimeException('สินค้านี้ยังไม่ใช่สินค้าฝากขาย');
        }
        $stmt = $this->db->prepare('SELECT id, qty, unit_price FROM sale_items WHERE product_id = ? AND consignor_id IS NULL FOR UPDATE');
        $stmt->execute([$productId]);
        $lines = $stmt->fetchAll();
        $out = ['lines' => count($lines), 'qty' => 0, 'sale' => 0.0, 'fee' => 0.0];
        if (!$lines) {
            return $out;
        }

        $consignorId = (int) $product['consignor_id'];
        $feePerUnit = (float) $product['fee_per_unit'] + ($product['rounding_beneficiary'] === 'store' ? (float) $product['round_per_unit'] : 0);
        $upd = $this->db->prepare('UPDATE sale_items SET consignor_id = ?, fee_amount = ?, cost_amount = ? WHERE id = ?');
        foreach ($lines as $l) {
            $qty = (int) $l['qty'];
            $unit = (float) $l['unit_price'];
            $upd->execute([$consignorId, round($feePerUnit * $qty, 2), round(($unit - $feePerUnit) * $qty, 2), $l['id']]);
            $out['qty'] += $qty;
            $out['sale'] += $unit * $qty;
            $out['fee'] += $feePerUnit * $qty;
        }
        $out['sale'] = round($out['sale'], 2);
        $out['fee'] = round($out['fee'], 2);

        $ledger->append(
            $consignorId, 'sale',
            "ขายสินค้าก่อนเปลี่ยนเป็นสินค้าฝากขาย {$out['qty']} ชิ้น - {$product['name']}",
            $docs->next('ADJ', 'ledger_entries', 'ref_no'),
            $out['sale'], 0, $userId
        );
        if ($out['fee'] > 0) {
            $ledger->append(
                $consignorId, 'fee',
                "หักค่าฝากขาย {$out['qty']} ชิ้น - {$product['name']}",
                $docs->next('FEE', 'ledger_entries', 'ref_no'),
                0, $out['fee'], $userId
            );
        }
        $this->audit->log(
            'products', $productId, 'update', null,
            ['note' => 'โอนยอดขายก่อนเปลี่ยนเป็นสินค้าฝากขายเข้าบัญชีผู้ฝาก', 'consignor_id' => $consignorId] + $out,
            $userId, $ip
        );
        return $out;
    }

    public function setImage(int $productId, string $token, ?int $userId, ?string $ip): void
    {
        $old = $this->find($productId);
        if (!$old) {
            throw new \RuntimeException('ไม่พบสินค้า');
        }
        // The photo it replaces is kept as a backup.
        if ($old['image_token'] && $old['image_token'] !== $token) {
            $this->backupPhoto($productId, (string) $old['image_token'], null, 'รูปเดิม ก่อนถ่ายรูปใหม่', $userId, false);
        }
        $this->db->prepare('UPDATE products SET image_token = ?, updated_at = NOW() WHERE id = ?')->execute([$token, $productId]);
        $this->audit->log('products', $productId, 'update', ['image_token' => $old['image_token']], ['image_token' => $token], $userId, $ip);
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
                 qty_received, qty_stock, mfg_date, expiry_date, listing_fee_status, listing_fee_amount, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $data['consignor_id'],
            $data['name'],
            // A barcode held for checking after a merge is not handed to a new product.
            isset($data['barcode']) && $data['barcode'] !== '' && $this->heldBy((string) $data['barcode']) === null ? $data['barcode'] : null,
            $data['cost_price'],
            $data['sale_price'],
            $data['fee_per_unit'],
            $data['round_per_unit'] ?? 0,
            $data['rounding_beneficiary'] ?? 'store',
            $data['qty'],
            $data['qty'],
            $data['mfg_date'] ?? null,
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
