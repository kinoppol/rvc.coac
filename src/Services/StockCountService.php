<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class StockCountService
{
    public function __construct(private PDO $db, private DocumentNumberService $docs, private AuditLogger $audit)
    {
    }

    /** How the note of a line closed by a return to the consignor begins. */
    public const RETURNED_NOTE = 'คืนสินค้าให้ผู้ฝาก';

    private static function isReturnedLine(?string $note): bool
    {
        return $note !== null && str_starts_with($note, self::RETURNED_NOTE);
    }

    /**
     * Goods handed back to the consignor are final: item counts of the product still waiting for a
     * manager are dropped (they counted goods that have left). Counting in rounds was removed.
     */
    public function closeReturnedLines(int $productId, string $note): void
    {
        $this->cancelPendingItems($productId, $note);
    }

    /** The reason / who columns for discontinued goods exist (migration 0081). */
    public static function hasReason(PDO $db): bool
    {
        return \App\Support\Db::hasColumn($db, 'products', 'discontinued_reason');
    }

    /** Products marked as discontinued, store goods and consigned, for showing them on request. */
    public function discontinued(): array
    {
        if (!\App\Support\Db::hasColumn($this->db, 'products', 'discontinued_at')) {
            return [];
        }
        // Why and by whom (migration 0081; none recorded before it).
        $why = self::hasReason($this->db)
            ? 'p.discontinued_reason, du.full_name AS discontinued_by_name' : 'NULL AS discontinued_reason, NULL AS discontinued_by_name';
        $byJoin = self::hasReason($this->db) ? ' LEFT JOIN users du ON du.id = p.discontinued_by' : '';
        return $this->db->query(
            "SELECT p.id, p.name, p.barcode, p.qty_stock, p.consignor_id, p.discontinued_at, {$why}, COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name
             FROM products p LEFT JOIN consignors c ON c.id = p.consignor_id{$byJoin}
             WHERE p.discontinued_at IS NOT NULL ORDER BY p.name ASC"
        )->fetchAll();
    }

    /**
     * Marks a product (store goods or consigned) as discontinued, or back on sale. Goods still
     * held must be sold, returned or counted out first.
     * Discontinuing needs a reason, kept with the product (migration 0081) and in the audit log.
     */
    public function setDiscontinued(int $productId, bool $discontinued, int $userId, ?string $ip, string $reason = ''): void
    {
        if (!\App\Support\Db::hasColumn($this->db, 'products', 'discontinued_at')) {
            throw new \RuntimeException('ยังตั้งเลิกจำหน่ายไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0067) ก่อน');
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if ($discontinued && $reason === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่เลิกจำหน่าย');
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
            }
            if (self::hasReason($this->db)) {
                $this->db->prepare('UPDATE products SET discontinued_at = ' . ($discontinued ? 'NOW()' : 'NULL') . ', discontinued_reason = ?, discontinued_by = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$discontinued ? $reason : null, $discontinued ? $userId : null, $productId]);
            } else {
                $this->db->prepare('UPDATE products SET discontinued_at = ' . ($discontinued ? 'NOW()' : 'NULL') . ', updated_at = NOW() WHERE id = ?')
                    ->execute([$productId]);
            }
            $this->audit->log('products', $productId, 'update', ['discontinued' => $product['discontinued_at'] !== null],
                ['discontinued' => $discontinued] + ($discontinued ? ['discontinued_reason' => $reason] : []), $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Days a counting photo is kept before its file is removed. */
    public const PHOTO_DAYS = 30;

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

    /**
     * Per product in the round: the suppliers it was bought from (active purchases, under each
     * supplier's main name so aliases count as one), for filtering the count list by supplier.
     * @return array<int, string[]>
     */
    private function suppliersFor(int $stockCountId): array
    {
        [$join, $main] = (new SupplierService($this->db))->sql('pd.supplier_name', 'pd.supplier_tax_id');
        $stmt = $this->db->prepare(
            "SELECT DISTINCT pi.product_id, {$main} AS supplier
             FROM purchase_items pi
             JOIN purchase_documents pd ON pd.id = pi.purchase_document_id {$join}
             JOIN stock_count_items sci ON sci.product_id = pi.product_id AND sci.stock_count_id = ?
             WHERE pi.item_type = 'stock' AND pd.status = 'active' AND TRIM(COALESCE(pd.supplier_name, '')) <> ''
             ORDER BY supplier"
        );
        $stmt->execute([$stockCountId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['product_id']][] = (string) $r['supplier'];
        }
        return $out;
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
        $suppliers = $this->suppliersFor($stockCountId);
        foreach ($items as &$item) {
            $item['photos'] = $photos[(int) $item['product_id']] ?? [];
            $item['suppliers'] = $suppliers[(int) $item['product_id']] ?? [];
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

    /** Approved counts can be corrected (migration 0082). */
    public static function editReady(PDO $db): bool
    {
        return \App\Support\Db::hasColumn($db, 'stock_count_items', 'edit_reason');
    }

    /**
     * An admin or a manager corrects what was counted on an approved count line (a number typed
     * wrong), with a reason. The line's count and difference move by the change; so does the stock
     * now when this is the product's latest count (a later count set the stock again by itself, so
     * then the stock stays). What was counted at first, who changed it, when and why stay on the line.
     *
     * @return array{doc_no:string, name:string, counted_before:int, counted_after:int, stock_before:int, stock_after:int, latest:bool}
     */
    public function editCount(int $lineId, int $counted, string $reason, int $userId, string $role, ?string $ip): array
    {
        if (!in_array($role, ['admin', 'manager'], true)) {
            throw new \RuntimeException('เฉพาะผู้ดูแลระบบหรือผู้จัดการร้านเท่านั้น');
        }
        if (!self::editReady($this->db)) {
            throw new \RuntimeException('ยังแก้ไขการตรวจนับไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0082) ก่อน');
        }
        if ($counted < 0 || $counted > 1000000) {
            throw new \RuntimeException('จำนวนที่นับไม่ถูกต้อง');
        }
        $reason = self::clip($reason, 255);
        if ($reason === null) {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่แก้ไขการตรวจนับ');
        }
        $item = self::itemReady($this->db);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM stock_count_items WHERE id = ? FOR UPDATE');
            $stmt->execute([$lineId]);
            $line = $stmt->fetch();
            $sc = $line ? $this->get((int) $line['stock_count_id']) : null;
            if (!$line || !$sc || $line['counted_qty'] === null) {
                throw new \RuntimeException('ไม่พบรายการตรวจนับนี้');
            }
            if ($sc['status'] !== 'approved') {
                throw new \RuntimeException("การนับ {$sc['doc_no']} ยังไม่มีผล (รอรับรอง/ยกเลิก) แก้ไขได้เฉพาะการนับที่มีผลแล้ว");
            }
            $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $line['product_id']]);
            $p = $stmt->fetch();
            if (!$p) {
                throw new \RuntimeException('ไม่พบสินค้า');
            }
            // What the count shows as counted: what was counted then (item counts), else the line's number.
            $shown = $item && $line['count_qty'] !== null ? (int) $line['count_qty'] : (int) $line['counted_qty'];
            $delta = $counted - $shown;
            if ($delta === 0) {
                throw new \RuntimeException('จำนวนที่นับเท่าเดิม ไม่มีอะไรเปลี่ยน');
            }
            $latest = $this->isLatestCount($line, $sc);
            $this->db->prepare(
                'UPDATE stock_count_items SET counted_qty = counted_qty + ?, diff_qty = diff_qty + ?'
                . ($item ? ', count_qty = IF(count_qty IS NULL, NULL, count_qty + ?)' : '')
                . ', original_counted_qty = COALESCE(original_counted_qty, ?), edited_by = ?, edited_at = NOW(), edit_reason = ? WHERE id = ?'
            )->execute($item
                ? [$delta, $delta, $delta, $shown, $userId, $reason, $lineId]
                : [$delta, $delta, $shown, $userId, $reason, $lineId]);
            $before = (int) $p['qty_stock'];
            $after = $latest ? $before + $delta : $before;
            if ($after !== $before) {
                $this->db->prepare('UPDATE products SET qty_stock = ?, updated_at = NOW() WHERE id = ?')->execute([$after, (int) $p['id']]);
                $this->audit->log('products', (int) $p['id'], 'update', $p, ['qty_stock' => $after, 'stock_count' => $sc['doc_no'], 'note' => "แก้ไขการตรวจนับ: {$reason}"], $userId, $ip);
            }
            $this->audit->log('stock_count_items', $lineId, 'update',
                ['doc_no' => $sc['doc_no'], 'product_id' => (int) $p['id'], 'counted_qty' => $shown],
                ['doc_no' => $sc['doc_no'], 'product_id' => (int) $p['id'], 'counted_qty' => $counted, 'edit_reason' => $reason, 'stock_before' => $before, 'stock_after' => $after],
                $userId, $ip);
            $this->db->commit();
            return ['doc_no' => $sc['doc_no'], 'name' => $p['name'], 'counted_before' => $shown, 'counted_after' => $counted,
                'stock_before' => $before, 'stock_after' => $after, 'latest' => $latest];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** No approved count of the same product comes after this line on the stock card (which orders by count date, then approval time). */
    private function isLatestCount(array $line, array $sc): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM stock_count_items sci JOIN stock_counts sc ON sc.id = sci.stock_count_id
             WHERE sci.product_id = ? AND sci.id <> ? AND sc.status = 'approved' AND sci.counted_qty IS NOT NULL
               AND (sc.count_date > ? OR (sc.count_date = ? AND (sc.approved_at > ? OR (sc.approved_at = ? AND sci.id > ?))))
             LIMIT 1"
        );
        $at = (string) ($sc['approved_at'] ?? '');
        $stmt->execute([(int) $line['product_id'], (int) $line['id'], $sc['count_date'], $sc['count_date'], $at, $at, (int) $line['id']]);
        return !$stmt->fetchColumn();
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
    public function itemCount(int $productId, int $counted, array $detail, int $userId, ?string $ip, ?string $photo = null, bool $certified = false, ?bool $forcePending = null): array
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
        // Inside a caller's transaction (a group count) it joins it.
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
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
            // Certified: recorded by an admin / manager themself (ระบุว่าหมด), it takes effect at once.
            $pending = $forcePending ?? (!$certified && abs($diff) > self::ITEM_APPROVAL_OVER);
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
            if ($own) {
                $this->db->commit();
            }
            return ['id' => $countId, 'doc_no' => $docNo, 'system_qty' => $system, 'counted_qty' => $counted, 'diff_qty' => $diff, 'pending' => $pending];
        } catch (\Throwable $e) {
            if ($own) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * A sale group (migration 0084) is counted as one: the pieces of all its members together.
     * The count is shared out over the members by changing their stock as little as possible:
     * members below zero go to 0 first, then pieces short come off the member with the most left
     * and pieces over go to the member with the least, until the members add up to what was
     * counted. Each member gets its own item count (same reason, the group named in how it was
     * counted). A group difference over ITEM_APPROVAL_OVER pieces waits for a manager, member by member.
     *
     * @return array{doc_no:string, system_qty:int, counted_qty:int, diff_qty:int, pending:bool, qty_stock:int}
     */
    public function groupCount(int $groupId, int $counted, array $detail, int $userId, ?string $ip, ?string $photo = null): array
    {
        if (!self::itemReady($this->db) || !SaleGroupService::ready($this->db)) {
            throw new \RuntimeException('ยังนับกลุ่มขายไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0077 และ 0084) ก่อน');
        }
        if ($counted < 0 || $counted > 1000000) {
            throw new \RuntimeException('จำนวนที่นับไม่ถูกต้อง');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM sale_groups WHERE id = ?');
            $stmt->execute([$groupId]);
            $g = $stmt->fetch();
            if (!$g) {
                throw new \RuntimeException('ไม่พบกลุ่มขายนี้ (อาจถูกยกเลิกกลุ่มแล้ว)');
            }
            $stmt = $this->db->prepare('SELECT id, name, qty_stock FROM products WHERE sale_group_id = ? ORDER BY id FOR UPDATE');
            $stmt->execute([$groupId]);
            $members = $stmt->fetchAll();
            if (!$members) {
                throw new \RuntimeException("กลุ่ม \"{$g['name']}\" ไม่มีสินค้า");
            }
            $system = array_sum(array_map(fn ($m) => (int) $m['qty_stock'], $members));
            $diff = $counted - $system;
            $reason = self::clip($detail['reason'] ?? null, 20);
            $reasonDetail = self::clip($detail['reason_detail'] ?? null, 500);
            if ($diff !== 0) {
                if ($reason === null || !isset(self::DIFF_REASONS[$reason])) {
                    throw new \RuntimeException(sprintf('นับได้ %d ในระบบ %d (%s%d) กรุณาเลือกสาเหตุที่ยอดต่างจากระบบ', $counted, $system, $diff > 0 ? '+' : '', $diff), 1);
                }
                if ($reasonDetail === null || mb_strlen($reasonDetail) < self::DIFF_DETAIL_MIN) {
                    throw new \RuntimeException('กรุณาระบุรายละเอียดว่าทำไมยอดที่นับต่างจากระบบ (อย่างน้อย ' . self::DIFF_DETAIL_MIN . ' ตัวอักษร)', 1);
                }
            }
            // Share the count out: nobody below zero, then take from the most / give to the least.
            $target = [];
            foreach ($members as $m) {
                $target[(int) $m['id']] = max(0, (int) $m['qty_stock']);
            }
            $sum = array_sum($target);
            while ($sum > $counted) {
                $pid = array_keys($target, max($target))[0];
                $target[$pid]--;
                $sum--;
            }
            while ($sum < $counted) {
                $pid = array_keys($target, min($target))[0];
                $target[$pid]++;
                $sum++;
            }
            $pending = abs($diff) > self::ITEM_APPROVAL_OVER;
            $method = mb_substr(sprintf('นับรวมกลุ่ม %s: นับได้ %d ในระบบ %d', $g['name'], $counted, $system)
                . (!empty($detail['method']) ? ' · ' . $detail['method'] : ''), 0, 100);
            $docs = [];
            foreach ($members as $i => $m) {
                $mine = (int) $m['qty_stock'] !== $target[(int) $m['id']];
                $res = $this->itemCount((int) $m['id'], $target[(int) $m['id']], [
                    'place' => $detail['place'] ?? null,
                    'method' => $method,
                    'note' => $detail['note'] ?? null,
                    // The group matched but its members were evened out (one was below zero).
                    'reason' => $mine ? ($diff !== 0 ? $reason : 'other') : null,
                    'reason_detail' => $mine ? ($diff !== 0 ? $reasonDetail : 'ปรับยอดภายในกลุ่มขาย ยอดรวมที่นับตรงกับระบบ') : null,
                ], $userId, $ip, $i === 0 ? $photo : null, false, $mine ? $pending : false);
                $docs[] = $res['doc_no'];
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(qty_stock), 0) FROM products WHERE sale_group_id = ?');
        $stmt->execute([$groupId]);
        return [
            'doc_no' => $docs[0] . (count($docs) > 1 ? ' – ' . end($docs) : ''), 'system_qty' => $system, 'counted_qty' => $counted,
            'diff_qty' => $diff, 'pending' => $pending && $diff !== 0, 'qty_stock' => (int) $stmt->fetchColumn(),
        ];
    }

    /**
     * Counting: a group stands in for its members (by name, or a member found). Shaped like
     * productInfo: id = -group id, qty_stock = the members' stock added up.
     */
    public function groupInfo(int $groupId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT g.id, g.name, g.sale_price, COALESCE(SUM(p.qty_stock), 0) AS qty_stock, COUNT(p.id) AS members,
                    SUBSTRING_INDEX(GROUP_CONCAT(IF(p.image_token IS NULL, NULL, CONCAT(p.id, ':', p.image_token)) ORDER BY p.qty_stock DESC), ',', 1) AS photo
             FROM sale_groups g LEFT JOIN products p ON p.sale_group_id = g.id WHERE g.id = ? GROUP BY g.id, g.name, g.sale_price"
        );
        $stmt->execute([$groupId]);
        $g = $stmt->fetch();
        if (!$g) {
            return null;
        }
        [$photoId, $token] = $g['photo'] ? explode(':', (string) $g['photo'], 2) : [null, null];
        return [
            'id' => -(int) $g['id'], 'group_id' => (int) $g['id'], 'is_group' => true, 'members' => (int) $g['members'],
            'name' => $g['name'] . ' (กลุ่ม ' . (int) $g['members'] . ' รายการ)', 'barcode' => null, 'image_token' => $token,
            'photo_id' => $photoId !== null ? (int) $photoId : null, 'consignor_id' => null, 'qty_stock' => (int) $g['qty_stock'],
            'sale_price' => (float) $g['sale_price'], 'consignor_name' => 'สินค้าของร้าน · นับรวมทั้งกลุ่มขาย', 'pack_barcodes' => [],
        ];
    }

    /** Counting with no round: members of a sale group become their group (once), and groups match by name too. */
    public function asGroups(array $found, string $q = ''): array
    {
        if (!SaleGroupService::ready($this->db)) {
            return $found;
        }
        $out = [];
        $seen = [];
        $groupOf = $this->db->prepare('SELECT sale_group_id FROM products WHERE id = ?');
        $add = function (int $gid) use (&$out, &$seen) {
            if (!isset($seen[$gid]) && ($g = $this->groupInfo($gid))) {
                $seen[$gid] = true;
                $out[] = $g;
            }
        };
        if ($q !== '') {
            $stmt = $this->db->prepare('SELECT id FROM sale_groups WHERE name LIKE ? ORDER BY name LIMIT 10');
            $stmt->execute(['%' . $q . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                $add((int) $gid);
            }
        }
        foreach ($found as $p) {
            $groupOf->execute([(int) $p['id']]);
            $gid = $groupOf->fetchColumn();
            if ($gid) {
                $add((int) $gid);
            } else {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * An admin / manager marks a product as sold out (ระบุว่าหมด): the same as counting it at 0 —
     * an item count that takes effect at once, with the reason found (DIFF_REASONS) and an
     * explanation. Used for goods the system shows below zero (sold more than recorded, sold as
     * the wrong product) or with pieces left that are really gone.
     */
    public function markSoldOut(int $productId, ?string $reason, ?string $detail, int $userId, ?string $ip): array
    {
        $stmt = $this->db->prepare('SELECT name, qty_stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $p = $stmt->fetch();
        if (!$p) {
            throw new \RuntimeException('ไม่พบสินค้า');
        }
        if ((int) $p['qty_stock'] === 0) {
            throw new \RuntimeException("\"{$p['name']}\" คงเหลือเป็น 0 อยู่แล้ว");
        }
        return $this->itemCount($productId, 0, [
            'method' => 'ระบุว่าหมด (ไม่มีสินค้าเหลือ)',
            'reason' => $reason,
            'reason_detail' => $detail,
        ], $userId, $ip, null, true);
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
    public function itemCounts(?int $productId = null, int $limit = 60, ?string $from = null, ?string $to = null): array
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
        // The count report: counts made on these days (by when they were counted).
        if ($from !== null && $to !== null) {
            $where .= ' AND COALESCE(sci.counted_at, sc.created_at) >= ? AND COALESCE(sci.counted_at, sc.created_at) < DATE_ADD(?, INTERVAL 1 DAY)';
            $args[] = $from;
            $args[] = $to;
        }
        // Corrections of approved counts (migration 0082).
        $edit = self::editReady($this->db)
            ? 'sci.original_counted_qty, sci.edited_at, sci.edit_reason, eu.full_name AS editor_name'
            : 'NULL AS original_counted_qty, NULL AS edited_at, NULL AS edit_reason, NULL AS editor_name';
        $editJoin = self::editReady($this->db) ? ' LEFT JOIN users eu ON eu.id = sci.edited_by' : '';
        $stmt = $this->db->prepare(
            "SELECT sc.id, sc.doc_no, sc.status, sc.count_date, sc.created_at, sc.approved_at, sc.review_note, sc.cancelled_at, sc.cancel_reason,
                    sci.id AS line_id, {$edit}, sci.product_id, sci.system_qty, sci.counted_qty, sci.diff_qty, sci.counted_by, sci.counted_at,
                    sci.count_system_qty, sci.count_qty, sci.count_place, sci.count_method, sci.count_note, sci.diff_reason, sci.diff_detail,
                    p.name, p.barcode, p.image_token, p.qty_stock, p.sale_price, p.consignor_id, COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name,
                    cu.full_name AS counter_name, au.full_name AS approver_name, xu.full_name AS canceller_name
             FROM stock_counts sc
             JOIN stock_count_items sci ON sci.stock_count_id = sc.id
             JOIN products p ON p.id = sci.product_id
             LEFT JOIN consignors c ON c.id = p.consignor_id
             LEFT JOIN users cu ON cu.id = COALESCE(sci.counted_by, sc.created_by)
             LEFT JOIN users au ON au.id = sc.approved_by
             LEFT JOIN users xu ON xu.id = sc.cancelled_by{$editJoin}
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
            // Approved: whether correcting it moves the stock now (no later count of the product).
            $r['latest'] = $r['status'] === 'approved' && $this->isLatestCount(['id' => $r['line_id'], 'product_id' => $r['product_id']], $r);
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

    /**
     * When each product was last counted (an approved count that counted it: an item count, or a
     * line counted in a round) and how many times: product id => [last_at, times].
     */
    public function lastCounts(): array
    {
        $at = self::itemReady($this->db) ? 'COALESCE(sci.counted_at, sc.approved_at, sc.created_at)' : 'COALESCE(sc.approved_at, sc.created_at)';
        $rows = $this->db->query(
            "SELECT sci.product_id, MAX({$at}) AS last_at, COUNT(*) AS times
             FROM stock_count_items sci JOIN stock_counts sc ON sc.id = sci.stock_count_id
             WHERE sc.status = 'approved' AND sci.counted_qty IS NOT NULL
             GROUP BY sci.product_id"
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['product_id']] = ['last_at' => $r['last_at'], 'times' => (int) $r['times']];
        }
        return $out;
    }

    /**
     * Every count of one product, newest first: item counts (also those waiting or turned down)
     * and the round lines that counted it, with what the system said, what was counted, who and why.
     */
    public function productCounts(int $productId, int $limit = 200): array
    {
        $item = self::itemReady($this->db);
        $cols = $item
            ? 'sc.kind, sci.counted_at, sci.count_system_qty, sci.count_qty, sci.count_place, sci.count_method, sci.count_note,
               sci.diff_reason, sci.diff_detail, sc.review_note, sc.cancel_reason'
            : "'round' AS kind, NULL AS counted_at, NULL AS count_system_qty, NULL AS count_qty, NULL AS count_place, NULL AS count_method,
               NULL AS count_note, NULL AS diff_reason, NULL AS diff_detail, NULL AS review_note, NULL AS cancel_reason";
        $at = $item ? 'COALESCE(sci.counted_at, sc.approved_at, sc.created_at)' : 'COALESCE(sc.approved_at, sc.created_at)';
        $counter = $item ? 'COALESCE(sci.counted_by, sc.created_by)' : 'sc.created_by';
        $edit = self::editReady($this->db)
            ? 'sci.original_counted_qty, sci.edited_at, sci.edit_reason, eu.full_name AS editor_name'
            : 'NULL AS original_counted_qty, NULL AS edited_at, NULL AS edit_reason, NULL AS editor_name';
        $editJoin = self::editReady($this->db) ? ' LEFT JOIN users eu ON eu.id = sci.edited_by' : '';
        $which = $item ? "(sc.status = 'approved' OR (sc.kind = 'item' AND sc.status IN ('submitted', 'cancelled')))" : "sc.status = 'approved'";
        $stmt = $this->db->prepare(
            "SELECT sc.id, sci.id AS line_id, {$edit}, sc.doc_no, sc.status, sc.count_date, sc.approved_at, {$at} AS at, sci.system_qty, sci.counted_qty, sci.diff_qty, sci.note, {$cols},
                    cu.full_name AS counter_name, au.full_name AS approver_name
             FROM stock_count_items sci
             JOIN stock_counts sc ON sc.id = sci.stock_count_id
             LEFT JOIN users cu ON cu.id = {$counter}
             LEFT JOIN users au ON au.id = sc.approved_by{$editJoin}
             WHERE sci.product_id = ? AND sci.counted_qty IS NOT NULL AND {$which}
             ORDER BY at DESC, sc.id DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['system_at_count'] = $r['count_system_qty'] !== null ? (int) $r['count_system_qty'] : (int) $r['system_qty'];
            $r['counted_at_count'] = $r['count_qty'] !== null ? (int) $r['count_qty'] : (int) $r['counted_qty'];
            $r['diff_at_count'] = $r['counted_at_count'] - $r['system_at_count'];
            $r['reason_label'] = $r['diff_reason'] !== null ? (self::DIFF_REASONS[$r['diff_reason']] ?? $r['diff_reason']) : null;
        }
        unset($r);
        return $rows;
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
