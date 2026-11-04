<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepo;
use App\Support\Db;
use PDO;

/**
 * Splitting a store product into separate products: a pack bought as one item whose pieces carry
 * barcodes of their own (mixed flavours, colours...). Part of the source's stock moves to each
 * piece product (a new one, or one that already exists) at the source's cost per piece, recorded
 * as a split document (SP-...) so both stock cards show where the pieces went / came from.
 */
class ProductSplitService
{
    private ProductRepo $products;

    public function __construct(private PDO $db, private DocumentNumberService $docs, private AuditLogger $audit)
    {
        $this->products = new ProductRepo($db, $audit);
    }

    public static function available(PDO $db): bool
    {
        return Db::hasColumn($db, 'product_splits', 'id');
    }

    private function assertAvailable(): void
    {
        if (!self::available($this->db)) {
            throw new \RuntimeException('ยังแยกสินค้าไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0061) ก่อน');
        }
    }

    /**
     * The source product, the piece products of its latest split (to split the next pack the same
     * way) and its split history, newest first.
     */
    public function info(int $sourceId): array
    {
        $this->assertAvailable();
        $p = $this->products->find($sourceId);
        if (!$p) {
            throw new \RuntimeException('ไม่พบสินค้า');
        }
        $history = $this->history($sourceId);
        $template = [];
        foreach ($history as $h) {
            if ($h['cancelled_at'] === null) {
                foreach ($h['items'] as $it) {
                    $template[] = [
                        'product_id' => $it['product_id'], 'name' => $it['name'], 'barcode' => $it['barcode'],
                        'sale_price' => $it['sale_price'], 'qty_stock' => $it['qty_stock'], 'qty' => $it['qty'],
                    ];
                }
                break;
            }
        }
        return [
            'product' => [
                'id' => (int) $p['id'], 'name' => $p['name'], 'barcode' => $p['barcode'], 'consignor_id' => $p['consignor_id'],
                'qty_stock' => (int) $p['qty_stock'], 'cost_price' => (float) $p['cost_price'], 'sale_price' => (float) $p['sale_price'],
            ],
            'template' => $template,
            'history' => $history,
        ];
    }

    /** Splits of one source product with their lines, newest first. */
    private function history(int $sourceId): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, u.full_name AS user_name FROM product_splits s LEFT JOIN users u ON u.id = s.user_id
             WHERE s.source_product_id = ? ORDER BY s.id DESC LIMIT 30'
        );
        $stmt->execute([$sourceId]);
        $splits = $stmt->fetchAll();
        if (!$splits) {
            return [];
        }
        $ids = array_column($splits, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT i.split_id, i.product_id, i.qty, i.unit_cost, i.created_product, p.name, p.barcode, p.sale_price, p.qty_stock
             FROM product_split_items i JOIN products p ON p.id = i.product_id WHERE i.split_id IN ($in) ORDER BY i.id"
        );
        $stmt->execute($ids);
        $items = [];
        foreach ($stmt->fetchAll() as $i) {
            $items[(int) $i['split_id']][] = [
                'product_id' => (int) $i['product_id'], 'name' => $i['name'], 'barcode' => $i['barcode'],
                'qty' => (int) $i['qty'], 'unit_cost' => (float) $i['unit_cost'], 'created_product' => (bool) $i['created_product'],
                'sale_price' => (float) $i['sale_price'], 'qty_stock' => (int) $i['qty_stock'],
            ];
        }
        return array_map(function ($s) use ($items) {
            $lines = $items[(int) $s['id']] ?? [];
            $short = array_values(array_filter($lines, fn ($l) => $l['qty_stock'] < $l['qty']));
            return [
                'id' => (int) $s['id'], 'doc_no' => $s['doc_no'], 'qty' => (int) $s['qty'], 'unit_cost' => (float) $s['unit_cost'],
                'note' => $s['note'], 'user_name' => $s['user_name'], 'created_at' => $s['created_at'],
                'cancelled_at' => $s['cancelled_at'], 'cancel_reason' => $s['cancel_reason'],
                // Undoing takes the pieces back: each piece product must still hold them.
                'cancellable' => $s['cancelled_at'] === null && !$short,
                'cancel_block' => $short ? 'ขายหรือตัดสต๊อกชิ้นย่อยไปแล้ว: ' . implode(', ', array_map(fn ($l) => "{$l['name']} เหลือ {$l['qty_stock']}/{$l['qty']}", $short)) : null,
                'items' => $lines,
            ];
        }, $splits);
    }

    /**
     * Moves pieces out of a store product into separate products. Call outside a transaction.
     *
     * @param array<int,array{product_id?:int|null, name?:string, barcode?:string, sale_price?:float|string, qty:int|string}> $rows
     *        product_id = an existing store product; otherwise a new product is made from name / barcode / sale_price
     * @return array the split, as in the history
     */
    public function split(int $sourceId, array $rows, string $note, int $userId, ?string $ip): array
    {
        $this->assertAvailable();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$sourceId]);
            $source = $stmt->fetch();
            if (!$source) {
                throw new \RuntimeException('ไม่พบสินค้า');
            }
            if ($source['consignor_id'] !== null) {
                throw new \RuntimeException('แยกได้เฉพาะสินค้าของร้าน');
            }

            $lines = [];
            $codes = [];
            $existing = [];
            foreach ($rows as $n => $r) {
                $r = (array) $r;
                $no = $n + 1;
                $qty = filter_var($r['qty'] ?? null, FILTER_VALIDATE_INT);
                $pid = (int) ($r['product_id'] ?? 0);
                $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($r['name'] ?? ''))), 0, 191);
                if ($qty === false || $qty === 0) {
                    if ($pid === 0 && $name === '') {
                        continue; // an empty row
                    }
                    throw new \RuntimeException("แถวที่ {$no}: กรุณาระบุจำนวนชิ้นที่แยก");
                }
                if ($qty < 0 || $qty > 100000) {
                    throw new \RuntimeException("แถวที่ {$no}: จำนวนชิ้นไม่ถูกต้อง");
                }
                if ($pid > 0) {
                    if ($pid === $sourceId) {
                        throw new \RuntimeException("แถวที่ {$no}: แยกเข้าสินค้าตัวเดิมไม่ได้");
                    }
                    if (isset($existing[$pid])) {
                        throw new \RuntimeException("แถวที่ {$no}: เลือกสินค้าซ้ำกับแถวอื่น");
                    }
                    $stmt->execute([$pid]);
                    $target = $stmt->fetch();
                    if (!$target) {
                        throw new \RuntimeException("แถวที่ {$no}: ไม่พบสินค้าที่เลือก กรุณารีเฟรช");
                    }
                    if ($target['consignor_id'] !== null) {
                        throw new \RuntimeException("แถวที่ {$no}: \"{$target['name']}\" เป็นสินค้าฝากขาย แยกเข้าได้เฉพาะสินค้าของร้าน");
                    }
                    $existing[$pid] = true;
                    $lines[] = ['product' => $target, 'qty' => $qty];
                    continue;
                }
                if ($name === '') {
                    throw new \RuntimeException("แถวที่ {$no}: กรุณากรอกชื่อสินค้า");
                }
                $price = round((float) ($r['sale_price'] ?? 0), 2);
                if (!($price > 0) || $price > 1000000) {
                    throw new \RuntimeException("แถวที่ {$no}: ราคาขายต้องมากกว่า 0");
                }
                $code = trim((string) ($r['barcode'] ?? ''));
                if (mb_strlen($code) > 64) {
                    throw new \RuntimeException("แถวที่ {$no}: บาร์โค้ดยาวเกินไป");
                }
                if ($code !== '') {
                    if (isset($codes[$code])) {
                        throw new \RuntimeException("บาร์โค้ด {$code} ซ้ำกันในรายการที่แยก");
                    }
                    $codes[$code] = true;
                    $this->assertBarcodeFree($code, $no);
                }
                $lines[] = ['product' => null, 'name' => $name, 'barcode' => $code, 'sale_price' => $price, 'qty' => $qty];
            }
            if (!$lines) {
                throw new \RuntimeException('กรุณาเพิ่มสินค้าที่จะแยกอย่างน้อย 1 รายการ');
            }
            $total = array_sum(array_column($lines, 'qty'));
            if ($total > (int) $source['qty_stock']) {
                throw new \RuntimeException("แยกได้ไม่เกินยอดคงเหลือ " . number_format((int) $source['qty_stock']) . " ชิ้น (ระบุมา " . number_format($total) . " ชิ้น)");
            }

            $cost = round((float) $source['cost_price'], 2);
            $docNo = $this->docs->next('SP', 'product_splits', 'doc_no');
            $this->db->prepare(
                'INSERT INTO product_splits (doc_no, source_product_id, qty, unit_cost, note, user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([$docNo, $sourceId, $total, $cost, mb_substr(trim($note), 0, 255) ?: null, $userId]);
            $splitId = (int) $this->db->lastInsertId();

            $this->db->prepare('UPDATE products SET qty_stock = qty_stock - ?, updated_at = NOW() WHERE id = ?')->execute([$total, $sourceId]);
            $this->audit->log('products', $sourceId, 'update', $source, $this->products->find($sourceId) + [
                'note' => "แยกสินค้า {$docNo}: แยกออก {$total} ชิ้น",
            ], $userId, $ip);

            $ins = $this->db->prepare(
                'INSERT INTO product_split_items (split_id, product_id, qty, unit_cost, created_product, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
            );
            foreach ($lines as $l) {
                if ($l['product'] === null) {
                    $pid = $this->products->create([
                        'consignor_id' => null, 'name' => $l['name'], 'barcode' => $l['barcode'] !== '' ? $l['barcode'] : null,
                        'cost_price' => $cost, 'sale_price' => $l['sale_price'], 'fee_per_unit' => 0, 'round_per_unit' => 0,
                        'rounding_beneficiary' => 'store', 'qty' => $l['qty'], 'mfg_date' => $source['mfg_date'] ?? null,
                        'expiry_date' => $source['expiry_date'] ?? null, 'listing_fee_status' => 'waived', 'listing_fee_amount' => 0,
                    ], $userId, $ip);
                    $ins->execute([$splitId, $pid, $l['qty'], $cost, 1]);
                    continue;
                }
                $t = $l['product'];
                $pid = (int) $t['id'];
                // Moving weighted-average cost, as for a purchase: what is on the shelf blended with these pieces.
                $onHand = max((int) $t['qty_stock'], 0);
                $avg = $onHand + $l['qty'] > 0
                    ? round(($onHand * (float) $t['cost_price'] + $l['qty'] * $cost) / ($onHand + $l['qty']), 2)
                    : $cost;
                $this->db->prepare(
                    'UPDATE products SET qty_received = qty_received + ?, qty_stock = qty_stock + ?, cost_price = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$l['qty'], $l['qty'], $avg, $pid]);
                $this->audit->log('products', $pid, 'update', $t, $this->products->find($pid) + [
                    'note' => "แยกสินค้า {$docNo}: รับ {$l['qty']} ชิ้นจาก \"{$source['name']}\"",
                ], $userId, $ip);
                $ins->execute([$splitId, $pid, $l['qty'], $cost, 0]);
            }
            $this->audit->log('product_splits', $splitId, 'create', null, [
                'doc_no' => $docNo, 'source_product_id' => $sourceId, 'qty' => $total, 'unit_cost' => $cost,
                'items' => array_map(fn ($l) => ['product_id' => $l['product']['id'] ?? null, 'name' => $l['product']['name'] ?? $l['name'], 'qty' => $l['qty']], $lines),
            ], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        foreach ($this->history($sourceId) as $h) {
            if ($h['id'] === $splitId) {
                return $h;
            }
        }
        return ['id' => $splitId, 'doc_no' => $docNo];
    }

    /**
     * Undoes a split: the pieces go back to the source product. Each piece product must still hold
     * what it got (nothing sold out of it). Products the split created stay, with no stock.
     */
    public function cancel(int $splitId, string $reason, int $userId, ?string $ip): void
    {
        $this->assertAvailable();
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่ยกเลิก');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM product_splits WHERE id = ? FOR UPDATE');
            $stmt->execute([$splitId]);
            $split = $stmt->fetch();
            if (!$split) {
                throw new \RuntimeException('ไม่พบเอกสารแยกสินค้า');
            }
            if ($split['cancelled_at'] !== null) {
                throw new \RuntimeException('เอกสารนี้ยกเลิกไปแล้ว');
            }
            $stmt = $this->db->prepare('SELECT * FROM product_split_items WHERE split_id = ? ORDER BY id');
            $stmt->execute([$splitId]);
            $items = $stmt->fetchAll();
            $lock = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $sourceId = (int) $split['source_product_id'];
            $lock->execute([$sourceId]);
            $source = $lock->fetch();
            foreach ($items as $it) {
                $lock->execute([(int) $it['product_id']]);
                $p = $lock->fetch();
                if ((int) $p['qty_stock'] < (int) $it['qty']) {
                    throw new \RuntimeException("ยกเลิกไม่ได้: \"{$p['name']}\" เหลือ {$p['qty_stock']} ชิ้น น้อยกว่าที่แยกมา {$it['qty']} ชิ้น (ขายหรือตัดสต๊อกไปแล้ว)");
                }
                $this->db->prepare('UPDATE products SET qty_received = qty_received - ?, qty_stock = qty_stock - ?, updated_at = NOW() WHERE id = ?')
                    ->execute([(int) $it['qty'], (int) $it['qty'], (int) $it['product_id']]);
                $this->audit->log('products', (int) $p['id'], 'update', $p, $this->products->find((int) $p['id']) + [
                    'note' => "ยกเลิกการแยกสินค้า {$split['doc_no']}: คืน {$it['qty']} ชิ้นให้ \"{$source['name']}\"",
                ], $userId, $ip);
            }
            $this->db->prepare('UPDATE products SET qty_stock = qty_stock + ?, updated_at = NOW() WHERE id = ?')->execute([(int) $split['qty'], $sourceId]);
            $this->audit->log('products', $sourceId, 'update', $source, $this->products->find($sourceId) + [
                'note' => "ยกเลิกการแยกสินค้า {$split['doc_no']}: รับคืน {$split['qty']} ชิ้น",
            ], $userId, $ip);
            $this->db->prepare('UPDATE product_splits SET cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$userId, $reason, $splitId]);
            $this->audit->log('product_splits', $splitId, 'update', ['cancelled_at' => null], ['cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => $reason], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function sourceOf(int $splitId): ?int
    {
        $this->assertAvailable();
        $stmt = $this->db->prepare('SELECT source_product_id FROM product_splits WHERE id = ?');
        $stmt->execute([$splitId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function assertBarcodeFree(string $code, int $no): void
    {
        $stmt = $this->db->prepare(
            'SELECT name FROM products WHERE barcode = ?
             UNION SELECT p.name FROM product_pack_barcodes b JOIN products p ON p.id = b.product_id WHERE b.barcode = ? LIMIT 1'
        );
        $stmt->execute([$code, $code]);
        $owner = $stmt->fetchColumn();
        if ($owner !== false) {
            throw new \RuntimeException("แถวที่ {$no}: บาร์โค้ด {$code} เป็นของสินค้า \"{$owner}\" อยู่แล้ว — เลือกสินค้านั้นแทนการสร้างใหม่");
        }
        $held = $this->products->heldBy($code);
        if ($held !== null) {
            throw new \RuntimeException("แถวที่ {$no}: บาร์โค้ด {$code} ถูกเก็บไว้ตรวจสอบที่สินค้า \"{$held['name']}\" (จากการรวมรายการซ้ำ)");
        }
    }
}
