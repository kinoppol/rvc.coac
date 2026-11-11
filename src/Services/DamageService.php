<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepo;
use App\Support\Db;
use PDO;

/**
 * Damaged goods: pieces of a store product written off because they cannot be sold (broken in a
 * big pack, spoiled while stored, expired...). Each record (DG-...) takes them out of stock at
 * the product's cost per piece of the day, with the reason and, when known, the purchase they
 * came in with, so the loss can be reported per reason, product and supplier.
 */
class DamageService
{
    public const REASONS = [
        'received' => 'ชำรุดมาจากผู้ขาย / ในแพ็ค',
        'handling' => 'เสียหายระหว่างเก็บ / จัดวาง',
        'expired' => 'หมดอายุ / เสื่อมสภาพ',
        'other' => 'อื่น ๆ',
    ];

    private ProductRepo $products;

    public function __construct(private PDO $db, private DocumentNumberService $docs, private AuditLogger $audit)
    {
        $this->products = new ProductRepo($db, $audit);
    }

    public static function available(PDO $db): bool
    {
        return Db::hasColumn($db, 'damaged_goods', 'id');
    }

    /** SQL CASE giving the Thai label of damaged_goods.reason (alias $col). */
    public static function reasonSql(string $col): string
    {
        $parts = [];
        foreach (self::REASONS as $k => $label) {
            $parts[] = "WHEN '$k' THEN '$label'";
        }
        return "CASE $col " . implode(' ', $parts) . " ELSE $col END";
    }

    private function assertAvailable(): void
    {
        if (!self::available($this->db)) {
            throw new \RuntimeException('ยังบันทึกสินค้าเสียหายไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0066) ก่อน');
        }
    }

    /** The product, the purchases it came in with (to say which one the damage came from) and its damage history. */
    public function info(int $productId): array
    {
        $this->assertAvailable();
        $p = $this->products->find($productId);
        if (!$p) {
            throw new \RuntimeException('ไม่พบสินค้า');
        }
        $stmt = $this->db->prepare(
            "SELECT pd.id, pd.doc_no, pd.supplier_name, COALESCE(pd.receipt_date, DATE(pd.created_at)) AS date,
                    SUM(pi.units) AS units, MAX(pi.piece_cost) AS piece_cost
             FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
             WHERE pi.product_id = ? AND pi.item_type = 'stock' AND COALESCE(pd.status, 'active') = 'active'
             GROUP BY pd.id, pd.doc_no, pd.supplier_name, date
             ORDER BY date DESC, pd.id DESC LIMIT 15"
        );
        $stmt->execute([$productId]);
        $purchases = array_map(fn ($r) => [
            'id' => (int) $r['id'], 'doc_no' => $r['doc_no'], 'supplier_name' => $r['supplier_name'], 'date' => $r['date'],
            'units' => (int) $r['units'], 'piece_cost' => (float) $r['piece_cost'],
        ], $stmt->fetchAll());
        return [
            'product' => [
                'id' => (int) $p['id'], 'name' => $p['name'], 'barcode' => $p['barcode'], 'consignor_id' => $p['consignor_id'],
                'qty_stock' => (int) $p['qty_stock'], 'cost_price' => (float) $p['cost_price'], 'sale_price' => (float) $p['sale_price'],
            ],
            'reasons' => self::REASONS,
            'purchases' => $purchases,
            'history' => $this->rows('d.product_id = ?', [$productId], 30),
        ];
    }

    /** Damage records matching $where, newest first. */
    private function rows(string $where, array $params, int $limit = 1000): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.*, p.name AS product_name, p.barcode, pd.doc_no AS purchase_doc_no, u.full_name AS user_name, cu.full_name AS canceller
             FROM damaged_goods d
             JOIN products p ON p.id = d.product_id
             LEFT JOIN purchase_documents pd ON pd.id = d.purchase_document_id
             LEFT JOIN users u ON u.id = d.user_id
             LEFT JOIN users cu ON cu.id = d.cancelled_by
             WHERE $where ORDER BY d.damage_date DESC, d.id DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute($params);
        return array_map(fn ($d) => [
            'id' => (int) $d['id'], 'doc_no' => $d['doc_no'], 'product_id' => (int) $d['product_id'],
            'product_name' => $d['product_name'], 'barcode' => $d['barcode'],
            'qty' => (int) $d['qty'], 'unit_cost' => (float) $d['unit_cost'], 'value' => round((int) $d['qty'] * (float) $d['unit_cost'], 2),
            'damage_date' => $d['damage_date'], 'reason' => $d['reason'], 'reason_label' => self::REASONS[$d['reason']] ?? $d['reason'],
            'purchase_document_id' => $d['purchase_document_id'] !== null ? (int) $d['purchase_document_id'] : null,
            'purchase_doc_no' => $d['purchase_doc_no'], 'supplier_name' => $d['supplier_name'], 'note' => $d['note'],
            'user_name' => $d['user_name'], 'created_at' => $d['created_at'],
            'cancelled_at' => $d['cancelled_at'], 'canceller' => $d['canceller'], 'cancel_reason' => $d['cancel_reason'],
        ], $stmt->fetchAll());
    }

    /**
     * Writes damaged pieces off a store product's stock. Call outside a transaction.
     * @return array the record, as in the history
     */
    public function record(int $productId, array $in, int $userId, ?string $ip): array
    {
        $this->assertAvailable();
        $qty = filter_var($in['qty'] ?? null, FILTER_VALIDATE_INT);
        if ($qty === false || $qty < 1 || $qty > 100000) {
            throw new \RuntimeException('กรุณาระบุจำนวนชิ้นที่เสียหาย (จำนวนเต็มตั้งแต่ 1)');
        }
        $reason = (string) ($in['reason'] ?? '');
        if (!isset(self::REASONS[$reason])) {
            throw new \RuntimeException('กรุณาเลือกสาเหตุที่เสียหาย');
        }
        $date = (string) ($in['damage_date'] ?? '');
        if ($date === '') {
            $date = date('Y-m-d');
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new \RuntimeException('วันที่พบสินค้าเสียหายไม่ถูกต้อง');
        }
        if ($date > date('Y-m-d')) {
            throw new \RuntimeException('วันที่พบสินค้าเสียหายต้องไม่เกินวันนี้');
        }
        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in['note'] ?? ''))), 0, 255);
        if ($reason === 'other' && $note === '') {
            throw new \RuntimeException('สาเหตุ "อื่น ๆ" กรุณาระบุรายละเอียดในหมายเหตุ');
        }
        $purchaseId = (int) ($in['purchase_document_id'] ?? 0);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $p = $stmt->fetch();
            if (!$p) {
                throw new \RuntimeException('ไม่พบสินค้า');
            }
            if ($p['consignor_id'] !== null) {
                throw new \RuntimeException('บันทึกสินค้าเสียหายได้เฉพาะสินค้าของร้าน (สินค้าฝากขายที่เสียหายให้คืนผู้ฝาก)');
            }
            if ($qty > (int) $p['qty_stock']) {
                throw new \RuntimeException('ตัดได้ไม่เกินยอดคงเหลือ ' . number_format((int) $p['qty_stock']) . ' ชิ้น (ระบุมา ' . number_format($qty) . ' ชิ้น) — ถ้ายอดในระบบไม่ตรงกับของจริง ให้ตรวจนับสต๊อกก่อน');
            }
            $supplier = null;
            if ($purchaseId > 0) {
                $q = $this->db->prepare(
                    "SELECT pd.id, pd.supplier_name FROM purchase_documents pd
                     WHERE pd.id = ? AND COALESCE(pd.status, 'active') = 'active'
                       AND EXISTS (SELECT 1 FROM purchase_items pi WHERE pi.purchase_document_id = pd.id AND pi.product_id = ?)"
                );
                $q->execute([$purchaseId, $productId]);
                $pd = $q->fetch();
                if (!$pd) {
                    throw new \RuntimeException('ไม่พบใบซื้อที่เลือก (หรือไม่มีสินค้านี้ในใบซื้อนั้น) กรุณารีเฟรช');
                }
                $supplier = $pd['supplier_name'];
            } else {
                $purchaseId = null;
            }

            $cost = round((float) $p['cost_price'], 2);
            $docNo = $this->docs->next('DG', 'damaged_goods', 'doc_no');
            $this->db->prepare(
                'INSERT INTO damaged_goods (doc_no, product_id, qty, unit_cost, damage_date, reason, purchase_document_id, supplier_name, note, user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([$docNo, $productId, $qty, $cost, $date, $reason, $purchaseId, $supplier, $note !== '' ? $note : null, $userId]);
            $id = (int) $this->db->lastInsertId();

            $this->db->prepare('UPDATE products SET qty_stock = qty_stock - ?, updated_at = NOW() WHERE id = ?')->execute([$qty, $productId]);
            $this->audit->log('products', $productId, 'update', $p, $this->products->find($productId) + [
                'note' => "สินค้าเสียหาย {$docNo}: ตัดสต๊อก {$qty} ชิ้น (" . self::REASONS[$reason] . ')',
            ], $userId, $ip);
            $this->audit->log('damaged_goods', $id, 'create', null, [
                'doc_no' => $docNo, 'product_id' => $productId, 'qty' => $qty, 'unit_cost' => $cost, 'damage_date' => $date,
                'reason' => $reason, 'purchase_document_id' => $purchaseId, 'supplier_name' => $supplier, 'note' => $note,
            ], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->rows('d.id = ?', [$id], 1)[0];
    }

    /** Undoes a damage record: the pieces go back into stock. */
    public function cancel(int $id, string $reason, int $userId, ?string $ip): int
    {
        $this->assertAvailable();
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่ยกเลิก');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM damaged_goods WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $d = $stmt->fetch();
            if (!$d) {
                throw new \RuntimeException('ไม่พบรายการสินค้าเสียหาย');
            }
            if ($d['cancelled_at'] !== null) {
                throw new \RuntimeException('รายการนี้ยกเลิกไปแล้ว');
            }
            $productId = (int) $d['product_id'];
            $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $p = $stmt->fetch();
            $this->db->prepare('UPDATE products SET qty_stock = qty_stock + ?, updated_at = NOW() WHERE id = ?')->execute([(int) $d['qty'], $productId]);
            $this->audit->log('products', $productId, 'update', $p, $this->products->find($productId) + [
                'note' => "ยกเลิกสินค้าเสียหาย {$d['doc_no']}: คืนเข้าสต๊อก {$d['qty']} ชิ้น",
            ], $userId, $ip);
            $this->db->prepare('UPDATE damaged_goods SET cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$userId, $reason, $id]);
            $this->audit->log('damaged_goods', $id, 'cancel', ['cancelled_at' => null], ['cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => $reason], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $productId;
    }

    /**
     * Statistics of damaged goods found in a period (by damage date), cancelled records left out
     * of the figures: totals, per reason, per product (with the share of what was bought in), per
     * supplier (with the share of what was bought from them in the period), per month, and the
     * records themselves (cancelled ones included, marked).
     */
    public function report(string $from, string $to): array
    {
        $this->assertAvailable();
        $records = $this->rows('d.damage_date BETWEEN ? AND ?', [$from, $to], 2000);
        $active = array_values(array_filter($records, fn ($r) => $r['cancelled_at'] === null));

        $sum = fn (array $list) => [
            'records' => count($list),
            'qty' => array_sum(array_column($list, 'qty')),
            'value' => round(array_sum(array_column($list, 'value')), 2),
        ];
        $group = function (array $list, callable $key) use ($sum): array {
            $g = [];
            foreach ($list as $r) {
                $g[$key($r)][] = $r;
            }
            return array_map(fn ($items) => $sum($items) + ['first' => $items[0]], $g);
        };

        $byReason = [];
        foreach ($group($active, fn ($r) => $r['reason']) as $k => $v) {
            unset($v['first']);
            $byReason[] = ['reason' => $k, 'label' => self::REASONS[$k] ?? $k] + $v;
        }
        usort($byReason, fn ($a, $b) => $b['value'] <=> $a['value']);

        // Pieces bought in over the period, to tell how much of what came in was lost.
        $bought = [];
        $stmt = $this->db->prepare(
            "SELECT pi.product_id, pd.supplier_name, SUM(pi.units) AS units
             FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
             WHERE pi.item_type = 'stock' AND COALESCE(pd.status, 'active') = 'active'
               AND COALESCE(pd.receipt_date, DATE(pd.created_at)) BETWEEN ? AND ?
             GROUP BY pi.product_id, pd.supplier_name"
        );
        $stmt->execute([$from, $to]);
        $boughtSupplier = [];
        // Names merged under one main name count as one supplier (migration 0074).
        $suppliers = new SupplierService($this->db);
        $mainOf = fn ($n) => trim((string) $n) === '' ? '' : $suppliers->mainName((string) $n);
        foreach ($stmt->fetchAll() as $b) {
            $bought[(int) $b['product_id']] = ($bought[(int) $b['product_id']] ?? 0) + (int) $b['units'];
            $boughtSupplier[$mainOf($b['supplier_name'])] = ($boughtSupplier[$mainOf($b['supplier_name'])] ?? 0) + (int) $b['units'];
        }
        $rate = fn (int $lost, int $in) => $in > 0 ? round($lost / $in * 100, 1) : null;

        $byProduct = [];
        foreach ($group($active, fn ($r) => $r['product_id']) as $pid => $v) {
            $first = $v['first'];
            unset($v['first']);
            $reasons = array_count_values(array_column(array_filter($active, fn ($r) => $r['product_id'] === $pid), 'reason'));
            arsort($reasons);
            $byProduct[] = [
                'product_id' => $pid, 'product_name' => $first['product_name'], 'barcode' => $first['barcode'],
                'bought_qty' => $bought[$pid] ?? 0, 'rate' => $rate($v['qty'], $bought[$pid] ?? 0),
                'main_reason' => self::REASONS[array_key_first($reasons)] ?? '',
            ] + $v;
        }
        usort($byProduct, fn ($a, $b) => $b['value'] <=> $a['value'] ?: $b['qty'] <=> $a['qty']);

        $bySupplier = [];
        foreach ($group($active, fn ($r) => $mainOf($r['supplier_name'] ?? '')) as $name => $v) {
            unset($v['first']);
            $name = (string) $name;
            $bySupplier[] = [
                'supplier_name' => $name !== '' ? $name : null,
                'bought_qty' => $name !== '' ? ($boughtSupplier[$name] ?? 0) : null,
                'rate' => $name !== '' ? $rate($v['qty'], $boughtSupplier[$name] ?? 0) : null,
            ] + $v;
        }
        usort($bySupplier, fn ($a, $b) => ($a['supplier_name'] === null) <=> ($b['supplier_name'] === null) ?: $b['value'] <=> $a['value']);

        $byMonth = [];
        foreach ($group($active, fn ($r) => substr($r['damage_date'], 0, 7)) as $m => $v) {
            unset($v['first']);
            $byMonth[] = ['month' => $m] + $v;
        }
        usort($byMonth, fn ($a, $b) => strcmp($a['month'], $b['month']));

        return [
            'from' => $from, 'to' => $to,
            'totals' => $sum($active) + ['products' => count($byProduct), 'cancelled' => count($records) - count($active)],
            'by_reason' => $byReason,
            'by_product' => $byProduct,
            'by_supplier' => $bySupplier,
            'by_month' => $byMonth,
            'records' => $records,
        ];
    }
}
