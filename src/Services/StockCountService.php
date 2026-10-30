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
             WHERE sci.id = ? AND sci.stock_count_id = ?'
        );
        foreach ($items as $item) {
            $raw = $item['counted_qty'] ?? null;
            $counted = $raw === null || $raw === '' ? null : max(0, (int) $raw);
            $upd->execute([$counted, $counted, $item['note'] ?? null, (int) $item['item_id'], $stockCountId]);
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
        if (!$sc || !in_array($sc['status'], ['draft', 'submitted'], true)) {
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
        if (!$sc || $sc['status'] !== 'draft') {
            throw new \RuntimeException('รอบตรวจนับนี้ส่งรายงานแล้ว แก้ไขจำนวนไม่ได้');
        }
        if ($counted < 0 || $counted > 1000000) {
            throw new \RuntimeException('จำนวนนับไม่ถูกต้อง');
        }
        $info = $this->productInfo($stockCountId, $productId);
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
        if (!$sc || $sc['status'] !== 'submitted') {
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
        if (!$sc) {
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

    /** True while a counting round is open (draft or waiting for approval). */
    public function hasOpenRound(): bool
    {
        return (bool) $this->db->query("SELECT 1 FROM stock_counts WHERE status IN ('draft','submitted') LIMIT 1")->fetchColumn();
    }

    /**
     * Count one product on the spot when no round is open: the stock is set at once and the
     * count is kept as its own approved one-line round, so the stock card shows it like any count.
     *
     * @return array{doc_no:string, system_qty:int, counted_qty:int, diff_qty:int}
     */
    public function quickCount(int $productId, int $counted, ?string $note, int $userId, ?string $ip, ?string $photo = null): array
    {
        if ($counted < 0) {
            throw new \RuntimeException('จำนวนที่นับต้องไม่ติดลบ');
        }
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
            $system = (int) $old['qty_stock'];
            $docNo = $this->docs->next('SC', 'stock_counts', 'doc_no');
            $this->db->prepare(
                "INSERT INTO stock_counts (doc_no, count_date, status, created_by, created_at, submitted_at, approved_by, approved_at)
                 VALUES (?, CURDATE(), 'approved', ?, NOW(), NOW(), ?, NOW())"
            )->execute([$docNo, $userId, $userId]);
            $countId = (int) $this->db->lastInsertId();
            $this->db->prepare(
                'INSERT INTO stock_count_items (stock_count_id, product_id, system_qty, counted_qty, diff_qty, note) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$countId, $productId, $system, $counted, $counted - $system, $note !== null && $note !== '' ? mb_substr($note, 0, 255) : 'นับทันทีด้วยมือถือ']);
            if ($photo !== null && $photo !== '') {
                $row = (new AttachmentService($this->db))->saveDataUrl($photo, $userId, 'counts');
                $this->db->prepare('INSERT INTO stock_count_photos (stock_count_id, product_id, token, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
                    ->execute([$countId, $productId, $row['token'], $note !== null && $note !== '' ? mb_substr($note, 0, 100) : null, $userId]);
            }
            if ($counted !== $system) {
                $this->db->prepare('UPDATE products SET qty_stock = ? WHERE id = ?')->execute([$counted, $productId]);
                $this->audit->log('products', $productId, 'update', $old, ['qty_stock' => $counted, 'stock_count' => $docNo], $userId, $ip);
            }
            $this->audit->log('stock_counts', $countId, 'create', null, ['doc_no' => $docNo, 'quick' => true, 'product_id' => $productId, 'system_qty' => $system, 'counted_qty' => $counted], $userId, $ip);
            $this->db->commit();
            return ['doc_no' => $docNo, 'system_qty' => $system, 'counted_qty' => $counted, 'diff_qty' => $counted - $system];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
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
        $stmt = $this->db->prepare('SELECT * FROM stock_counts ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
