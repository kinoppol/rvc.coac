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
        // A round approved after an item count waiting for a manager would be undone by certifying it.
        if ($waiting = $this->pendingItemCount()) {
            throw new \RuntimeException("มีการนับรายการรอผู้จัดการรับรอง {$waiting} รายการ ให้ผู้จัดการรับรองหรือไม่รับรองให้เสร็จก่อนจึงเปิดรอบตรวจนับได้");
        }
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
            $this->addConsignedLines($countId);

            $this->db->commit();
            return $countId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * A blank (null) count means the item was not counted: it keeps no count and
     * its stock is left as it is. Not every item has to be counted.
     *
     * @param array<int,array{item_id:int, counted_qty:?int, note?:string}> $items
     */
    public function saveCounts(int $stockCountId, array $items): void
    {
        $upd = $this->db->prepare(
            'UPDATE stock_count_items sci JOIN products p ON p.id = sci.product_id
             SET sci.system_qty = p.qty_stock, sci.counted_qty = ?, sci.diff_qty = ? - p.qty_stock, sci.note = ?
             WHERE sci.id = ? AND sci.stock_count_id = ? AND (sci.note IS NULL OR sci.note NOT LIKE ?)'
        );
        foreach ($items as $item) {
            $raw = $item['counted_qty'] ?? null;
            $counted = $raw === null || $raw === '' ? null : max(0, (int) $raw);
            $upd->execute([$counted, $counted, $item['note'] ?? null, (int) $item['item_id'], $stockCountId, self::RETURNED_NOTE . '%']);
        }
    }

    /** How the note of a line closed by a return to the consignor begins. */
    public const RETURNED_NOTE = 'คืนสินค้าให้ผู้ฝาก';

    private static function isReturnedLine(?string $note): bool
    {
        return $note !== null && str_starts_with($note, self::RETURNED_NOTE);
    }

    /**
     * Goods handed back to the consignor are final, whatever an open round counted: the product's
     * line in each open round closes at 0 with no difference, so approving the round leaves the
     * stock alone and the line cannot be counted again in that round. Goods received after the
     * return keep their stock and are counted from the next round.
     */
    public function closeReturnedLines(int $productId, string $note): void
    {
        $find = $this->db->prepare('SELECT 1 FROM stock_count_items WHERE stock_count_id = ? AND product_id = ? LIMIT 1');
        $add = $this->db->prepare('INSERT INTO stock_count_items (stock_count_id, product_id, system_qty, counted_qty, diff_qty, note) VALUES (?, ?, 0, 0, 0, ?)');
        $close = $this->db->prepare('UPDATE stock_count_items SET system_qty = 0, counted_qty = 0, diff_qty = 0, note = ? WHERE stock_count_id = ? AND product_id = ?');
        $this->cancelPendingItems($productId, $note);
        $item = self::itemReady($this->db) ? " AND kind <> 'item'" : '';
        $rounds = $this->db->query("SELECT id FROM stock_counts WHERE status IN ('draft','submitted'){$item}")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rounds as $round) {
            $find->execute([$round, $productId]);
            if ($find->fetchColumn()) {
                $close->execute([$note, $round, $productId]);
            } else {
                $add->execute([$round, $productId, $note]);
            }
        }
    }

    /**
     * A draft round lists every consigned product still on sale, even sold out, so the count can
     * find one that is on the shelf though the system says none. Discontinued ones stay out
     * unless they somehow hold stock.
     */
    public function addConsignedLines(int $stockCountId): void
    {
        $onSale = \App\Support\Db::hasColumn($this->db, 'products', 'discontinued_at')
            ? 'AND (p.discontinued_at IS NULL OR p.qty_stock > 0)' : '';
        $this->db->prepare(
            "INSERT INTO stock_count_items (stock_count_id, product_id, system_qty)
             SELECT sc.id, p.id, p.qty_stock
             FROM stock_counts sc
             JOIN products p ON p.consignor_id IS NOT NULL {$onSale}
             WHERE sc.id = ? AND sc.status = 'draft'
               AND NOT EXISTS (SELECT 1 FROM stock_count_items x WHERE x.stock_count_id = sc.id AND x.product_id = p.id)"
        )->execute([$stockCountId]);
    }

    /** Products marked as discontinued, store goods and consigned, for showing them on request. */
    public function discontinued(): array
    {
        if (!\App\Support\Db::hasColumn($this->db, 'products', 'discontinued_at')) {
            return [];
        }
        return $this->db->query(
            "SELECT p.id, p.name, p.barcode, p.qty_stock, p.consignor_id, p.discontinued_at, COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name
             FROM products p LEFT JOIN consignors c ON c.id = p.consignor_id
             WHERE p.discontinued_at IS NOT NULL ORDER BY p.name ASC"
        )->fetchAll();
    }

    /**
     * Marks a product (store goods or consigned) as discontinued, or back on sale. Goods still
     * held must be sold, returned or counted out first. Its lines in open rounds go, unless they carry a difference.
     */
    public function setDiscontinued(int $productId, bool $discontinued, int $userId, ?string $ip): void
    {
        if (!\App\Support\Db::hasColumn($this->db, 'products', 'discontinued_at')) {
            throw new \RuntimeException('ยังตั้งเลิกจำหน่ายไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0067) ก่อน');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id, name, consignor_id, qty_stock, discontinued_at FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) {
                throw new \RuntimeException('ไม่พบสินค้า');
            }
            if ($discontinued) {
                if ((int) $product['qty_stock'] > 0) {
                    throw new \RuntimeException("\"{$product['name']}\" ยังเหลือ {$product['qty_stock']} ชิ้น ต้องไม่มีคงเหลือก่อนจึงตั้งเลิกจำหน่ายได้ (ขายให้หมด คืนผู้ฝาก ตัดสินค้าเสียหาย หรือตรวจนับ)");
                }
                $open = "SELECT sci.id FROM stock_count_items sci JOIN stock_counts sc ON sc.id = sci.stock_count_id
                         WHERE sci.product_id = ? AND sc.status IN ('draft','submitted')";
                $stmt = $this->db->prepare($open . ' AND sci.diff_qty <> 0 LIMIT 1');
                $stmt->execute([$productId]);
                if ($stmt->fetchColumn()) {
                    throw new \RuntimeException("\"{$product['name']}\" มีผลต่างในรอบตรวจนับที่เปิดอยู่ ลบจำนวนที่นับออกหรือปิดรอบก่อนจึงตั้งเลิกจำหน่ายได้");
                }
                $stmt = $this->db->prepare($open);
                $stmt->execute([$productId]);
                $del = $this->db->prepare('DELETE FROM stock_count_items WHERE id = ?');
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $lineId) {
                    $del->execute([$lineId]);
                }
            }
            $this->db->prepare('UPDATE products SET discontinued_at = ' . ($discontinued ? 'NOW()' : 'NULL') . ', updated_at = NOW() WHERE id = ?')
                ->execute([$productId]);
            $this->audit->log('products', $productId, 'update', ['discontinued' => $product['discontinued_at'] !== null], ['discontinued' => $discontinued], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * A draft round keeps the system quantity of lines not counted yet in step with the
     * stock now: sales imported after the round was opened (a late POS import) would
     * otherwise leave it showing what was there when the round started.
     */
    public function refreshSystemQty(int $stockCountId): void
    {
        $this->db->prepare(
            "UPDATE stock_count_items sci
             JOIN stock_counts sc ON sc.id = sci.stock_count_id AND sc.status = 'draft'
             JOIN products p ON p.id = sci.product_id
             SET sci.system_qty = p.qty_stock
             WHERE sci.stock_count_id = ? AND sci.counted_qty IS NULL AND sci.system_qty <> p.qty_stock"
        )->execute([$stockCountId]);
    }

    /** Days a counting photo is kept before its file is removed. */
    public const PHOTO_DAYS = 30;

    /**
     * Keeps a photo taken while counting a product (already shrunk by the browser to a
     * size that still shows clearly full screen on a computer).
     */
    public function addPhoto(int $stockCountId, int $productId, string $dataUrl, ?string $note, int $userId): string
    {
        $sc = $this->get($stockCountId);
        if (!$sc || self::isItem($sc) || !in_array($sc['status'], ['draft', 'submitted'], true)) {
            throw new \RuntimeException('รอบตรวจนับนี้อนุมัติหรือยกเลิกไปแล้ว เพิ่มรูปไม่ได้');
        }
        $row = (new AttachmentService($this->db))->saveDataUrl($dataUrl, $userId, 'counts');
        $this->db->prepare('INSERT INTO stock_count_photos (stock_count_id, product_id, token, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$stockCountId, $productId, $row['token'], $note !== null ? mb_substr($note, 0, 100) : null, $userId]);
        return $row['token'];
    }

    /** product id => [{token (null once removed), note, created_at}], oldest first. */
    public function photosFor(int $stockCountId): array
    {
        try {
            $stmt = $this->db->prepare('SELECT product_id, token, note, created_at FROM stock_count_photos WHERE stock_count_id = ? ORDER BY id');
            $stmt->execute([$stockCountId]);
        } catch (\PDOException $e) {
            return []; // migration 0044 not run yet
        }
        $out = [];
        foreach ($stmt->fetchAll() as $p) {
            $out[(int) $p['product_id']][] = ['token' => $p['token'], 'note' => $p['note'], 'created_at' => $p['created_at']];
        }
        return $out;
    }

    /** Removes the files of counting photos older than PHOTO_DAYS (the rows stay, token NULL). */
    public function purgeOldPhotos(): int
    {
        try {
            $stmt = $this->db->prepare('SELECT id, token FROM stock_count_photos WHERE token IS NOT NULL AND created_at < NOW() - INTERVAL ? DAY LIMIT 500');
            $stmt->execute([self::PHOTO_DAYS]);
            $old = $stmt->fetchAll();
        } catch (\PDOException $e) {
            return 0;
        }
        $files = new AttachmentService($this->db);
        foreach ($old as $p) {
            $a = $files->findByToken((string) $p['token']);
            if ($a) {
                try {
                    @unlink($files->absolutePath($a));
                } catch (\RuntimeException $e) {
                    // already gone
                }
                @unlink($files->thumbPath($a['token']));
                $this->db->prepare('DELETE FROM attachments WHERE id = ?')->execute([$a['id']]);
            }
            $this->db->prepare('UPDATE stock_count_photos SET token = NULL WHERE id = ?')->execute([$p['id']]);
        }
        return count($old);
    }

    public function diffSummary(int $stockCountId): array
    {
        $stmt = $this->db->prepare(
            "SELECT sci.*, p.name, p.sale_price, p.barcode, p.image_token, p.consignor_id, COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name
             FROM stock_count_items sci
             JOIN products p ON p.id = sci.product_id
             LEFT JOIN consignors c ON c.id = p.consignor_id
             WHERE sci.stock_count_id = ?
             ORDER BY p.name ASC"
        );
        $stmt->execute([$stockCountId]);
        $items = $stmt->fetchAll();
        $photos = $this->photosFor($stockCountId);
        foreach ($items as &$item) {
            $item['photos'] = $photos[(int) $item['product_id']] ?? [];
            $item['returned'] = self::isReturnedLine($item['note']);
        }
        unset($item);

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

    /**
     * Product matched by a scanned or typed code: its piece barcode first, then
     * a store pack barcode (pack_size > 1), with its line in this count if any.
     */
    public function lookup(int $stockCountId, string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id FROM products WHERE barcode = ? LIMIT 1');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        $packSize = 1;
        if ($id === false) {
            $stmt = $this->db->prepare(
                'SELECT b.product_id, b.pack_size FROM product_pack_barcodes b JOIN products p ON p.id = b.product_id
                 WHERE b.barcode = ? AND p.consignor_id IS NULL LIMIT 1'
            );
            $stmt->execute([$code]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $id = $row['product_id'];
            $packSize = (int) $row['pack_size'];
        }
        return $this->productInfo($stockCountId, (int) $id) + ['scanned_pack_size' => $packSize];
    }

    /** Products by name or barcode, for counting goods without a barcode or linking one. */
    public function search(int $stockCountId, string $q, int $limit = 30): array
    {
        $q = trim($q);
        $stmt = $this->db->prepare(
            "SELECT p.id FROM products p
             WHERE p.name LIKE ? OR p.barcode = ?
                OR EXISTS (SELECT 1 FROM product_pack_barcodes b WHERE b.product_id = p.id AND b.barcode = ?)
             ORDER BY (p.qty_stock > 0) DESC, p.name ASC LIMIT " . (int) $limit
        );
        $stmt->execute(['%' . $q . '%', $q, $q]);
        return array_map(fn ($id) => $this->productInfo($stockCountId, (int) $id), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** One product with its pack barcodes and its line (item) in the given count. */
    public function productInfo(int $stockCountId, int $productId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.id, p.name, p.barcode, p.image_token, p.consignor_id, p.qty_stock, p.sale_price,
                    COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name
             FROM products p LEFT JOIN consignors c ON c.id = p.consignor_id WHERE p.id = ?"
        );
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            throw new \RuntimeException('ไม่พบสินค้า');
        }
        $stmt = $this->db->prepare('SELECT barcode, pack_size FROM product_pack_barcodes WHERE product_id = ? ORDER BY pack_size ASC, id ASC');
        $stmt->execute([$productId]);
        $product['pack_barcodes'] = array_map(fn ($r) => ['barcode' => $r['barcode'], 'pack_size' => (int) $r['pack_size']], $stmt->fetchAll());
        $stmt = $this->db->prepare('SELECT * FROM stock_count_items WHERE stock_count_id = ? AND product_id = ? LIMIT 1');
        $stmt->execute([$stockCountId, $productId]);
        $product['item'] = $stmt->fetch() ?: null;
        return $product;
    }

    /**
     * Records the physical count of one product. A product the count did not
     * list (system stock was 0) is added with its current system quantity.
     */
    public function setCount(int $stockCountId, int $productId, int $counted): array
    {
        $sc = $this->get($stockCountId);
        if (!$sc || self::isItem($sc) || $sc['status'] !== 'draft') {
            throw new \RuntimeException('รอบตรวจนับนี้ส่งรายงานแล้ว แก้ไขจำนวนไม่ได้');
        }
        if ($counted < 0 || $counted > 1000000) {
            throw new \RuntimeException('จำนวนนับไม่ถูกต้อง');
        }
        $info = $this->productInfo($stockCountId, $productId);
        if ($info['item'] && self::isReturnedLine($info['item']['note'])) {
            throw new \RuntimeException('สินค้านี้คืนให้ผู้ฝากแล้วระหว่างรอบนี้ ยอดเป็นที่สิ้นสุด นับเพิ่มในรอบนี้ไม่ได้');
        }
        if (!$info['item']) {
            $this->db->prepare('INSERT INTO stock_count_items (stock_count_id, product_id, system_qty) VALUES (?, ?, ?)')
                ->execute([$stockCountId, $productId, (int) $info['qty_stock']]);
        }
        $this->db->prepare(
            'UPDATE stock_count_items sci JOIN products p ON p.id = sci.product_id
             SET sci.system_qty = p.qty_stock, sci.counted_qty = ?, sci.diff_qty = ? - p.qty_stock
             WHERE sci.stock_count_id = ? AND sci.product_id = ?'
        )->execute([$counted, $counted, $stockCountId, $productId]);
        return $this->productInfo($stockCountId, $productId);
    }

    public function submit(int $stockCountId): void
    {
        $sc = $this->get($stockCountId);
        if (!$sc || self::isItem($sc) || $sc['status'] !== 'draft') {
            throw new \RuntimeException('ส่งรายงานได้เฉพาะรอบตรวจนับที่กำลังนับอยู่');
        }
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
        $sc = $this->get($stockCountId);
        if (self::isItem($sc)) {
            throw new \RuntimeException('การนับรายการต้องรับรองจากรายการรอผู้จัดการรับรอง');
        }
        if (!$sc || $sc['status'] !== 'submitted') {
            throw new \RuntimeException('รอบตรวจนับนี้ยังไม่ได้ส่งรายงาน หรืออนุมัติ/ยกเลิกไปแล้ว');
        }
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
                // Keep on the line what the stock really was and how much it moved, so the
                // stock card adds up even when sales were recorded between counting and approval.
                $this->db->prepare('UPDATE stock_count_items SET system_qty = ?, diff_qty = ? WHERE id = ?')
                    ->execute([(int) $old['qty_stock'], (int) $item['counted_qty'] - (int) $old['qty_stock'], $item['id']]);

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

    /**
     * Sends a submitted round back to draft so counting can continue (phone mode,
     * more lines, fixes). Counts, photos and barcodes recorded so far are kept.
     */
    public function reopen(int $stockCountId, int $userId, ?string $ip): void
    {
        $sc = $this->get($stockCountId);
        if (!$sc || self::isItem($sc) || $sc['status'] !== 'submitted') {
            throw new \RuntimeException('ส่งกลับไปนับต่อได้เฉพาะรอบที่ส่งรายงานแล้วและยังไม่อนุมัติ');
        }
        $this->db->prepare(
            "UPDATE stock_counts SET status='draft', submitted_at=NULL WHERE id = ?"
        )->execute([$stockCountId]);
        $this->audit->log('stock_counts', $stockCountId, 'update', ['status' => 'submitted'], ['status' => 'draft'], $userId, $ip);
    }

    /**
     * Cancels a round that has not been approved yet; product stock is not changed.
     * Barcodes and photos linked during the count were already saved on the product
     * records (see StockCountController::assignBarcode/savePhoto) and are kept.
     */
    public function cancel(int $stockCountId, int $userId, ?string $ip): void
    {
        $sc = $this->get($stockCountId);
        if (!$sc || self::isItem($sc)) {
            throw new \RuntimeException('ไม่พบรอบตรวจนับ');
        }
        if (!in_array($sc['status'], ['draft', 'submitted'], true)) {
            throw new \RuntimeException('รอบตรวจนับนี้อนุมัติหรือยกเลิกไปแล้ว ยกเลิกไม่ได้');
        }
        $this->db->prepare(
            "UPDATE stock_counts SET status='cancelled', cancelled_by=?, cancelled_at=NOW() WHERE id = ?"
        )->execute([$userId, $stockCountId]);
        $this->audit->log('stock_counts', $stockCountId, 'cancel', ['status' => $sc['status']], ['status' => 'cancelled'], $userId, $ip);
    }

    /** True while a counting round is open (draft or waiting for approval). Item counts are not rounds. */
    public function hasOpenRound(): bool
    {
        $item = self::itemReady($this->db) ? " AND kind <> 'item'" : '';
        return (bool) $this->db->query("SELECT 1 FROM stock_counts WHERE status IN ('draft','submitted'){$item} LIMIT 1")->fetchColumn();
    }

    /** A difference of more than this many pieces waits for a manager to certify it. */
    public const ITEM_APPROVAL_OVER = 10;

    /** Why a counted quantity differs from the system (required with any difference). */
    public const DIFF_REASONS = [
        'lost' => 'สูญหาย / หาไม่พบ ไม่ทราบสาเหตุ',
        'damaged' => 'ชำรุด เสียหาย หมดอายุ ที่ยังไม่ได้ตัดสต๊อก',
        'sale_error' => 'ขายแล้วไม่ได้บันทึก หรือสแกนขายผิดรายการ',
        'receive_error' => 'รับสินค้า/ซื้อเข้า ไม่ได้บันทึกหรือบันทึกจำนวนผิด',
        'used' => 'นำไปใช้ในร้าน แจก หรือแถม',
        'found' => 'พบสินค้าเพิ่ม (เก็บไว้ที่อื่น)',
        'miscount' => 'การนับครั้งก่อนผิด',
        'other' => 'อื่น ๆ (ระบุในรายละเอียด)',
    ];

    /** Shortest explanation of a difference accepted. */
    public const DIFF_DETAIL_MIN = 5;

    /** Migration 0077 (item counts with their details) has been run. */
    public static function itemReady(PDO $db): bool
    {
        return \App\Support\Db::hasColumn($db, 'stock_counts', 'kind') && \App\Support\Db::hasColumn($db, 'stock_count_items', 'diff_reason');
    }

    private static function isItem(?array $sc): bool
    {
        return $sc !== null && ($sc['kind'] ?? 'round') === 'item';
    }

    private static function clip(mixed $v, int $len): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $len);
    }

    /** Item counts waiting for a manager. */
    public function pendingItemCount(): int
    {
        if (!self::itemReady($this->db)) {
            return 0;
        }
        return (int) $this->db->query("SELECT COUNT(*) FROM stock_counts WHERE kind = 'item' AND status = 'submitted'")->fetchColumn();
    }

    /**
     * Count one product on its own, no round needed (none may be open though: the round would
     * count it too). The count keeps where and how it was done, who did it and when, and what the
     * system said then; a difference needs a reason and an explanation. Up to ITEM_APPROVAL_OVER
     * pieces off, the stock is set at once (an approved one-line count, shown on the stock card
     * like any count); more than that waits for a manager to certify it (see approveItem).
     *
     * @param array{place?:?string, method?:?string, note?:?string, reason?:?string, reason_detail?:?string} $detail
     * @return array{id:int, doc_no:string, system_qty:int, counted_qty:int, diff_qty:int, pending:bool}
     */
    public function itemCount(int $productId, int $counted, array $detail, int $userId, ?string $ip, ?string $photo = null): array
    {
        if (!self::itemReady($this->db)) {
            throw new \RuntimeException('ยังนับรายการแบบใหม่ไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0077) ก่อน');
        }
        if ($counted < 0 || $counted > 1000000) {
            throw new \RuntimeException('จำนวนที่นับไม่ถูกต้อง');
        }
        $place = self::clip($detail['place'] ?? null, 100);
        $method = self::clip($detail['method'] ?? null, 100);
        $note = self::clip($detail['note'] ?? null, 255);
        $reason = self::clip($detail['reason'] ?? null, 20);
        $reasonDetail = self::clip($detail['reason_detail'] ?? null, 500);
        $this->db->beginTransaction();
        try {
            if ($this->hasOpenRound()) {
                throw new \RuntimeException('มีรอบตรวจนับเปิดอยู่ กรุณานับในรอบนั้นแทน');
            }
            $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $old = $stmt->fetch();
            if (!$old) {
                throw new \RuntimeException('ไม่พบสินค้า');
            }
            $stmt = $this->db->prepare(
                "SELECT sc.doc_no FROM stock_counts sc JOIN stock_count_items sci ON sci.stock_count_id = sc.id
                 WHERE sc.kind = 'item' AND sc.status = 'submitted' AND sci.product_id = ? LIMIT 1"
            );
            $stmt->execute([$productId]);
            if ($waiting = $stmt->fetchColumn()) {
                throw new \RuntimeException("\"{$old['name']}\" มีการนับ {$waiting} รอผู้จัดการรับรองอยู่ ให้ผู้จัดการรับรองหรือไม่รับรองก่อน (หรือผู้นับถอนการนับนั้น) แล้วจึงนับใหม่");
            }
            $system = (int) $old['qty_stock'];
            $diff = $counted - $system;
            if ($diff === 0) {
                $reason = $reasonDetail = null;
            } else {
                // Code 1: the counter has to explain the difference (the phone then asks for it).
                if ($reason === null || !isset(self::DIFF_REASONS[$reason])) {
                    throw new \RuntimeException(sprintf('นับได้ %d ในระบบ %d (%s%d) กรุณาเลือกสาเหตุที่ยอดต่างจากระบบ', $counted, $system, $diff > 0 ? '+' : '', $diff), 1);
                }
                if ($reasonDetail === null || mb_strlen($reasonDetail) < self::DIFF_DETAIL_MIN) {
                    throw new \RuntimeException('กรุณาระบุรายละเอียดว่าทำไมยอดที่นับต่างจากระบบ (อย่างน้อย ' . self::DIFF_DETAIL_MIN . ' ตัวอักษร)', 1);
                }
            }
            $pending = abs($diff) > self::ITEM_APPROVAL_OVER;
            $docNo = $this->docs->next('SC', 'stock_counts', 'doc_no');
            $this->db->prepare(
                $pending
                    ? "INSERT INTO stock_counts (doc_no, kind, count_date, status, created_by, created_at, submitted_at) VALUES (?, 'item', CURDATE(), 'submitted', ?, NOW(), NOW())"
                    : "INSERT INTO stock_counts (doc_no, kind, count_date, status, created_by, created_at, submitted_at, approved_by, approved_at) VALUES (?, 'item', CURDATE(), 'approved', ?, NOW(), NOW(), ?, NOW())"
            )->execute($pending ? [$docNo, $userId] : [$docNo, $userId, $userId]);
            $countId = (int) $this->db->lastInsertId();
            $summary = $diff === 0 ? 'นับรายการ: ตรงกับระบบ'
                : 'นับรายการ: ' . self::DIFF_REASONS[$reason] . ' — ' . $reasonDetail;
            $this->db->prepare(
                'INSERT INTO stock_count_items (stock_count_id, product_id, system_qty, counted_qty, diff_qty, note,
                    counted_by, counted_at, count_system_qty, count_qty, count_place, count_method, count_note, diff_reason, diff_detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$countId, $productId, $system, $counted, $diff, mb_substr($summary, 0, 255),
                $userId, $system, $counted, $place, $method, $note, $reason, $reasonDetail]);
            if ($photo !== null && $photo !== '') {
                $row = (new AttachmentService($this->db))->saveDataUrl($photo, $userId, 'counts');
                $this->db->prepare('INSERT INTO stock_count_photos (stock_count_id, product_id, token, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
                    ->execute([$countId, $productId, $row['token'], $method, $userId]);
            }
            if (!$pending && $diff !== 0) {
                $this->db->prepare('UPDATE products SET qty_stock = ? WHERE id = ?')->execute([$counted, $productId]);
                $this->audit->log('products', $productId, 'update', $old, ['qty_stock' => $counted, 'stock_count' => $docNo], $userId, $ip);
            }
            $this->audit->log('stock_counts', $countId, 'create', null, [
                'doc_no' => $docNo, 'kind' => 'item', 'status' => $pending ? 'submitted' : 'approved', 'product_id' => $productId,
                'system_qty' => $system, 'counted_qty' => $counted, 'diff_qty' => $diff, 'place' => $place, 'method' => $method,
                'note' => $note, 'reason' => $reason, 'reason_detail' => $reasonDetail,
            ], $userId, $ip);
            $this->db->commit();
            return ['id' => $countId, 'doc_no' => $docNo, 'system_qty' => $system, 'counted_qty' => $counted, 'diff_qty' => $diff, 'pending' => $pending];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** An item count waiting for a manager, locked, with its line. */
    private function pendingItem(int $stockCountId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM stock_counts WHERE id = ? FOR UPDATE');
        $stmt->execute([$stockCountId]);
        $sc = $stmt->fetch() ?: null;
        if (!self::isItem($sc)) {
            throw new \RuntimeException('ไม่พบการนับรายการนี้');
        }
        if ($sc['status'] !== 'submitted') {
            throw new \RuntimeException("การนับ {$sc['doc_no']} รับรองหรือยกเลิกไปแล้ว");
        }
        $stmt = $this->db->prepare('SELECT * FROM stock_count_items WHERE stock_count_id = ? LIMIT 1');
        $stmt->execute([$stockCountId]);
        $line = $stmt->fetch();
        if (!$line) {
            throw new \RuntimeException('ไม่พบรายการสินค้าในการนับนี้');
        }
        return [$sc, $line];
    }

    /**
     * A manager certifies an item count that was too far off to take effect by itself. Goods may
     * have been sold or received since it was counted, so the stock moves by the difference found
     * then (not to the number counted): what is on the shelf now. The line keeps the count as it
     * stands on the stock card from now on (dated today: the system before, the stock after); what
     * was counted and what the system said then stay in count_qty / count_system_qty.
     * Nobody certifies their own count, except an admin.
     */
    public function approveItem(int $stockCountId, int $userId, string $role, ?string $note, ?string $ip): array
    {
        $this->db->beginTransaction();
        try {
            [$sc, $line] = $this->pendingItem($stockCountId);
            if ((int) $line['counted_by'] === $userId && $role !== 'admin') {
                throw new \RuntimeException('ผู้นับรับรองการนับของตัวเองไม่ได้ ต้องให้ผู้จัดการคนอื่นเป็นผู้รับรอง');
            }
            $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$line['product_id']]);
            $old = $stmt->fetch();
            $now = (int) $old['qty_stock'];
            $diff = (int) $line['count_qty'] - (int) $line['count_system_qty'];
            $after = max(0, $now + $diff);
            $this->db->prepare('UPDATE stock_count_items SET system_qty = ?, counted_qty = ?, diff_qty = ? WHERE id = ?')
                ->execute([$now, $after, $after - $now, $line['id']]);
            if ($after !== $now) {
                $this->db->prepare('UPDATE products SET qty_stock = ? WHERE id = ?')->execute([$after, $line['product_id']]);
                $this->audit->log('products', (int) $line['product_id'], 'update', $old, ['qty_stock' => $after, 'stock_count' => $sc['doc_no']], $userId, $ip);
            }
            $note = self::clip($note, 255);
            $this->db->prepare(
                "UPDATE stock_counts SET status = 'approved', count_date = CURDATE(), approved_by = ?, approved_at = NOW(), review_note = ? WHERE id = ?"
            )->execute([$userId, $note, $stockCountId]);
            $this->audit->log('stock_counts', $stockCountId, 'approve', ['status' => 'submitted'], [
                'status' => 'approved', 'stock_before' => $now, 'stock_after' => $after, 'review_note' => $note,
            ], $userId, $ip);
            $this->db->commit();
            return ['doc_no' => $sc['doc_no'], 'stock_before' => $now, 'stock_after' => $after];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * An item count waiting for a manager is turned down by one (or withdrawn by whoever counted
     * it): the stock is left as it is, and the reason is kept.
     */
    public function rejectItem(int $stockCountId, int $userId, bool $asApprover, ?string $reason, ?string $ip): array
    {
        $reason = self::clip($reason, 255);
        if ($reason === null) {
            throw new \RuntimeException('กรุณาระบุเหตุผล');
        }
        $this->db->beginTransaction();
        try {
            [$sc, $line] = $this->pendingItem($stockCountId);
            if (!$asApprover && (int) $line['counted_by'] !== $userId) {
                throw new \RuntimeException('เฉพาะผู้นับหรือผู้จัดการเท่านั้นที่ยกเลิกการนับนี้ได้');
            }
            $this->db->prepare(
                "UPDATE stock_counts SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?"
            )->execute([$userId, $reason, $stockCountId]);
            $this->audit->log('stock_counts', $stockCountId, 'cancel', ['status' => 'submitted'], [
                'status' => 'cancelled', 'by' => (int) $line['counted_by'] === $userId ? 'counter' : 'approver', 'reason' => $reason,
            ], $userId, $ip);
            $this->db->commit();
            return ['doc_no' => $sc['doc_no']];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Item counts with everything recorded about them: those waiting for a manager first, then
     * the latest ones (optionally one product's only).
     */
    public function itemCounts(?int $productId = null, int $limit = 60): array
    {
        if (!self::itemReady($this->db)) {
            return ['ready' => false, 'pending' => [], 'recent' => []];
        }
        $where = "sc.kind = 'item'";
        $args = [];
        if ($productId !== null) {
            $where .= ' AND sci.product_id = ?';
            $args[] = $productId;
        }
        $stmt = $this->db->prepare(
            "SELECT sc.id, sc.doc_no, sc.status, sc.count_date, sc.created_at, sc.approved_at, sc.review_note, sc.cancelled_at, sc.cancel_reason,
                    sci.product_id, sci.system_qty, sci.counted_qty, sci.diff_qty, sci.counted_by, sci.counted_at,
                    sci.count_system_qty, sci.count_qty, sci.count_place, sci.count_method, sci.count_note, sci.diff_reason, sci.diff_detail,
                    p.name, p.barcode, p.image_token, p.qty_stock, p.sale_price, p.consignor_id, COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name,
                    cu.full_name AS counter_name, au.full_name AS approver_name, xu.full_name AS canceller_name
             FROM stock_counts sc
             JOIN stock_count_items sci ON sci.stock_count_id = sc.id
             JOIN products p ON p.id = sci.product_id
             LEFT JOIN consignors c ON c.id = p.consignor_id
             LEFT JOIN users cu ON cu.id = COALESCE(sci.counted_by, sc.created_by)
             LEFT JOIN users au ON au.id = sc.approved_by
             LEFT JOIN users xu ON xu.id = sc.cancelled_by
             WHERE {$where}
             ORDER BY (sc.status = 'submitted') DESC, sc.id DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        $photos = $this->db->prepare('SELECT token, note, created_at FROM stock_count_photos WHERE stock_count_id = ? ORDER BY id');
        foreach ($rows as &$r) {
            $photos->execute([$r['id']]);
            $r['photos'] = $photos->fetchAll();
            $r['diff_at_count'] = $r['count_qty'] !== null ? (int) $r['count_qty'] - (int) $r['count_system_qty'] : (int) $r['diff_qty'];
            $r['reason_label'] = $r['diff_reason'] !== null ? (self::DIFF_REASONS[$r['diff_reason']] ?? $r['diff_reason']) : null;
            // Waiting: what the stock becomes if certified now.
            $r['stock_if_approved'] = $r['status'] === 'submitted' ? max(0, (int) $r['qty_stock'] + $r['diff_at_count']) : null;
        }
        unset($r);
        return [
            'ready' => true,
            'pending' => array_values(array_filter($rows, fn ($r) => $r['status'] === 'submitted')),
            'recent' => array_values(array_filter($rows, fn ($r) => $r['status'] !== 'submitted')),
        ];
    }

    /** Item counts of a product waiting for a manager are dropped when its goods go back to the consignor. */
    private function cancelPendingItems(int $productId, string $reason): void
    {
        if (!self::itemReady($this->db)) {
            return;
        }
        $this->db->prepare(
            "UPDATE stock_counts sc JOIN stock_count_items sci ON sci.stock_count_id = sc.id
             SET sc.status = 'cancelled', sc.cancelled_at = NOW(), sc.cancel_reason = ?
             WHERE sc.kind = 'item' AND sc.status = 'submitted' AND sci.product_id = ?"
        )->execute([mb_substr($reason, 0, 255), $productId]);
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
        // Rounds only: item counts are listed by itemCounts().
        $item = self::itemReady($this->db) ? " WHERE kind <> 'item'" : '';
        $stmt = $this->db->prepare("SELECT * FROM stock_counts{$item} ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
