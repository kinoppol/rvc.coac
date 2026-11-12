<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepo;
use App\Support\Db;
use PDO;

/**
 * Corrections of the "ปรับยอด" rows of a stock card (SA-..., migration 0076). The card works those
 * rows out (InventoryService::countAdjustments and the gap at the end); a correction moves the
 * product's qty_stock to undo one of them (qty = -adjustment) or to change how much it should be,
 * keeping who did it, when and why. It can be edited (the stock moves by the difference) or
 * cancelled (the stock moves back). Every change is in the audit log.
 */
class StockAdjustmentService
{
    public const CAUSES = [
        'late' => 'เอกสารบันทึกหลังอนุมัติตรวจนับ',
        'early' => 'เอกสารบันทึกก่อนอนุมัติตรวจนับ',
        'cancelled' => 'เอกสารยกเลิกหลังอนุมัติตรวจนับ',
        'unknown' => 'ส่วนต่างที่ไม่มีเอกสารอ้างอิง',
    ];

    private ProductRepo $products;

    public function __construct(private PDO $db, private DocumentNumberService $docs, private AuditLogger $audit)
    {
        $this->products = new ProductRepo($db, $audit);
    }

    public static function available(PDO $db): bool
    {
        return Db::hasColumn($db, 'stock_adjustments', 'id');
    }

    private function assertAvailable(): void
    {
        if (!self::available($this->db)) {
            throw new \RuntimeException('ยังแก้ไขการปรับยอดไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0076) ก่อน');
        }
    }

    private static function note(mixed $v, string $what): string
    {
        $note = mb_substr(trim((string) $v), 0, 255);
        if ($note === '') {
            throw new \RuntimeException("กรุณาระบุเหตุผลที่{$what}");
        }
        return $note;
    }

    private static function qty(mixed $v): int
    {
        if (!is_numeric($v) || (int) $v != $v || (int) $v === 0) {
            throw new \RuntimeException('จำนวนที่ปรับต้องเป็นจำนวนเต็มและไม่เป็น 0');
        }
        return (int) $v;
    }

    /** Active corrections of one product, by id, with the count they belong to and who made / last changed them. */
    public function forProduct(int $productId): array
    {
        if (!self::available($this->db)) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT sa.*, sc.doc_no AS count_doc_no, u.full_name AS user_name, uu.full_name AS updated_by_name
             FROM stock_adjustments sa
             LEFT JOIN stock_counts sc ON sc.id = sa.stock_count_id
             LEFT JOIN users u ON u.id = sa.user_id LEFT JOIN users uu ON uu.id = sa.updated_by
             WHERE sa.product_id = ? AND sa.cancelled_at IS NULL'
        );
        $stmt->execute([$productId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'], 'doc_no' => $r['doc_no'], 'cause' => $r['cause'], 'count_doc_no' => $r['count_doc_no'],
                'original_qty' => (int) $r['original_qty'], 'qty' => (int) $r['qty'], 'note' => $r['note'],
                'user_name' => $r['user_name'], 'created_at' => $r['created_at'],
                'updated_by_name' => $r['updated_by_name'], 'updated_at' => $r['updated_at'],
                'edited' => $r['updated_by'] !== null,
            ];
        }
        return $out;
    }

    /**
     * POST /inventory/{id}/stock-adjustments {cause, count_doc_no?, original_qty, qty, note}
     * cause late / early / cancelled: an adjustment the card made after that count (one correction
     * each; edit it to change it). cause unknown: the gap at the end of the card, dated today.
     */
    public function create(int $productId, array $in, int $userId, ?string $ip): array
    {
        $this->assertAvailable();
        $cause = (string) ($in['cause'] ?? '');
        if (!isset(self::CAUSES[$cause])) {
            throw new \RuntimeException('ไม่รู้จักประเภทการปรับยอด');
        }
        $qty = self::qty($in['qty'] ?? null);
        $note = self::note($in['note'] ?? '', 'แก้ไข/ยกเลิกการปรับยอด');
        $original = (int) ($in['original_qty'] ?? 0);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $p = $stmt->fetch();
            if (!$p) {
                throw new \RuntimeException('ไม่พบสินค้า');
            }
            $countId = null;
            $date = date('Y-m-d');
            if ($cause !== 'unknown') {
                $stmt = $this->db->prepare(
                    "SELECT sc.id, sc.count_date FROM stock_counts sc
                     WHERE sc.doc_no = ? AND sc.status = 'approved'
                       AND EXISTS (SELECT 1 FROM stock_count_items sci WHERE sci.stock_count_id = sc.id AND sci.product_id = ?)"
                );
                $stmt->execute([(string) ($in['count_doc_no'] ?? ''), $productId]);
                $sc = $stmt->fetch();
                if (!$sc) {
                    throw new \RuntimeException('ไม่พบรอบตรวจนับที่อนุมัติแล้วของสินค้านี้ กรุณารีเฟรช');
                }
                $countId = (int) $sc['id'];
                $date = (string) $sc['count_date'];
                $dup = $this->db->prepare('SELECT doc_no FROM stock_adjustments WHERE product_id = ? AND stock_count_id = ? AND cause = ? AND cancelled_at IS NULL');
                $dup->execute([$productId, $countId, $cause]);
                if ($d = $dup->fetchColumn()) {
                    throw new \RuntimeException("การปรับยอดนี้แก้ไปแล้วที่ {$d} — แก้ไขหรือยกเลิกรายการนั้นแทน");
                }
            }
            $docNo = $this->docs->next('SA', 'stock_adjustments', 'doc_no');
            $this->db->prepare(
                'INSERT INTO stock_adjustments (doc_no, product_id, stock_count_id, cause, adjust_date, original_qty, qty, note, user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([$docNo, $productId, $countId, $cause, $date, $original, $qty, $note, $userId]);
            $id = (int) $this->db->lastInsertId();
            $this->moveStock($p, $qty, "แก้การปรับยอด {$docNo}: " . self::CAUSES[$cause] . " คงเหลือ " . sprintf('%+d', $qty) . " ชิ้น ({$note})", $userId, $ip);
            $this->audit->log('stock_adjustments', $id, 'create', null, [
                'doc_no' => $docNo, 'product_id' => $productId, 'stock_count_id' => $countId, 'cause' => $cause,
                'adjust_date' => $date, 'original_qty' => $original, 'qty' => $qty, 'note' => $note,
            ], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['id' => $id, 'doc_no' => $docNo, 'product_id' => $productId];
    }

    /** PUT /inventory/stock-adjustments/{id} {qty, note}: the stock moves by the difference. */
    public function update(int $id, array $in, int $userId, ?string $ip): array
    {
        $this->assertAvailable();
        $qty = self::qty($in['qty'] ?? null);
        $note = self::note($in['note'] ?? '', 'แก้ไข');
        $this->db->beginTransaction();
        try {
            $sa = $this->lockActive($id);
            $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $sa['product_id']]);
            $p = $stmt->fetch();
            $delta = $qty - (int) $sa['qty'];
            if ($delta === 0 && $note === $sa['note']) {
                throw new \RuntimeException('ไม่มีอะไรเปลี่ยน');
            }
            $this->db->prepare('UPDATE stock_adjustments SET qty = ?, note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$qty, $note, $userId, $id]);
            if ($delta !== 0) {
                $this->moveStock($p, $delta, "แก้ไข {$sa['doc_no']}: จาก " . sprintf('%+d', (int) $sa['qty']) . ' เป็น ' . sprintf('%+d', $qty) . " ชิ้น ({$note})", $userId, $ip);
            }
            $this->audit->log('stock_adjustments', $id, 'update', ['qty' => (int) $sa['qty'], 'note' => $sa['note']], ['qty' => $qty, 'note' => $note], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['id' => $id, 'product_id' => (int) $sa['product_id']];
    }

    /** POST /inventory/stock-adjustments/{id}/cancel {reason}: the stock moves back. */
    public function cancel(int $id, string $reason, int $userId, ?string $ip): array
    {
        $this->assertAvailable();
        $reason = self::note($reason, 'ยกเลิก');
        $this->db->beginTransaction();
        try {
            $sa = $this->lockActive($id);
            $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $sa['product_id']]);
            $p = $stmt->fetch();
            $this->moveStock($p, -(int) $sa['qty'], "ยกเลิก {$sa['doc_no']}: คงเหลือ " . sprintf('%+d', -(int) $sa['qty']) . " ชิ้น ({$reason})", $userId, $ip);
            $this->db->prepare('UPDATE stock_adjustments SET cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$userId, $reason, $id]);
            $this->audit->log('stock_adjustments', $id, 'cancel', ['cancelled_at' => null], ['cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => $reason], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['id' => $id, 'product_id' => (int) $sa['product_id']];
    }

    private function lockActive(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM stock_adjustments WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $sa = $stmt->fetch();
        if (!$sa) {
            throw new \RuntimeException('ไม่พบรายการแก้การปรับยอด');
        }
        if ($sa['cancelled_at'] !== null) {
            throw new \RuntimeException('รายการนี้ยกเลิกไปแล้ว');
        }
        return $sa;
    }

    private function moveStock(array $p, int $qty, string $note, int $userId, ?string $ip): void
    {
        $productId = (int) $p['id'];
        $this->db->prepare('UPDATE products SET qty_stock = qty_stock + ?, updated_at = NOW() WHERE id = ?')->execute([$qty, $productId]);
        $this->audit->log('products', $productId, 'update', $p, $this->products->find($productId) + ['note' => $note], $userId, $ip);
    }
}
