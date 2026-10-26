<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepo;
use PDO;

/**
 * Cashiers looking back over their own POS sales, and asking to correct one
 * (quantities, lines taken out or added, how it was paid) within a few minutes
 * of making it. A request only records the change; the sale, stock, consignor
 * passbooks and the cash drawer move when a manager or admin approves it.
 */
class SaleEditService
{
    public function __construct(
        private PDO $db,
        private SettingsService $settings,
        private AuditLogger $audit
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->get('sale_edit_enabled') === '1';
    }

    public function minutes(): int
    {
        return max(1, (int) $this->settings->get('sale_edit_minutes'));
    }

    /** Until when the cashier may still ask to correct $sale, or null when they may not. */
    public function editableUntil(array $sale): ?string
    {
        if (!$this->enabled() || !empty($sale['pos_import'])) {
            return null;
        }
        $until = strtotime((string) $sale['sale_datetime']) + $this->minutes() * 60;
        return $until > time() ? date('Y-m-d H:i:s', $until) : null;
    }

    /** POS sales a cashier made, by accounting day; POS imports are not theirs to correct. */
    /** POS sales of one cashier ($userId), or of every cashier (null), by accounting day. */
    public function mine(?int $userId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.id, s.doc_no, s.sale_datetime, s.business_date, s.after_hours, s.total_qty, s.total_value,
                    s.cashier_id, (SELECT u.full_name FROM users u WHERE u.id = s.cashier_id) AS cashier_name,
                    s.cash_received, s.change_amount,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS line_count,
                    (SELECT GROUP_CONCAT(DISTINCT sp.method ORDER BY sp.id) FROM sale_payments sp WHERE sp.sale_id = s.id) AS methods,
                    (SELECT r.status FROM sale_edit_requests r WHERE r.sale_id = s.id ORDER BY r.id DESC LIMIT 1) AS edit_status,
                    (SELECT COUNT(*) FROM sale_edit_requests r WHERE r.sale_id = s.id AND r.status = 'approved') AS edits_approved
             FROM sales s
             WHERE (? IS NULL OR s.cashier_id = ?) AND s.business_date BETWEEN ? AND ?
               AND NOT EXISTS (SELECT 1 FROM pos_imports pi WHERE pi.sale_id = s.id)
             ORDER BY s.sale_datetime DESC, s.id DESC LIMIT 1000"
        );
        $stmt->execute([$userId, $userId, $from, $to]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['methods'] = $r['methods'] ? explode(',', $r['methods']) : [];
            $r['editable_until'] = $r['edit_status'] === 'pending' ? null : $this->editableUntil($r);
        }
        unset($r);
        return $rows;
    }

    /** Staff who can sell (for the cashier picker), plus anyone with POS sales in the range. */
    public function cashiers(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.full_name, u.role,
                    (SELECT COUNT(*) FROM sales s WHERE s.cashier_id = u.id AND s.business_date BETWEEN ? AND ?
                       AND NOT EXISTS (SELECT 1 FROM pos_imports pi WHERE pi.sale_id = s.id)) AS sales
             FROM users u
             WHERE (u.active = 1 AND u.role IN ('admin', 'manager', 'advisor', 'cashier'))
                OR u.id IN (SELECT cashier_id FROM sales WHERE business_date BETWEEN ? AND ?)
             ORDER BY sales DESC, u.full_name"
        );
        $stmt->execute([$from, $to, $from, $to]);
        return $stmt->fetchAll();
    }

    /** A sale with its lines, payments and correction requests. */
    public function detail(int $saleId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, u.full_name AS cashier_name,
                    EXISTS (SELECT 1 FROM pos_imports pi WHERE pi.sale_id = s.id) AS pos_import
             FROM sales s LEFT JOIN users u ON u.id = s.cashier_id WHERE s.id = ?'
        );
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            return null;
        }
        $snap = $this->snapshot($saleId);
        $sale['items'] = $snap['items'];
        $sale['payments'] = $snap['payments'];
        $sale['requests'] = $this->requests(['sale_id' => $saleId]);
        $pending = array_filter($sale['requests'], fn ($r) => $r['status'] === 'pending');
        $sale['editable_until'] = $pending ? null : $this->editableUntil($sale);
        return $sale;
    }

    /**
     * The sale's lines and payments as a request records them (before / after).
     * @return array{items:array<int,array>, payments:array<int,array>, total_qty:int, total_value:float}
     */
    public function snapshot(int $saleId): array
    {
        $stmt = $this->db->prepare(
            'SELECT si.id AS sale_item_id, si.product_id, p.name, p.barcode, si.consignor_id, si.qty, si.pack_size, si.unit_price,
                    COALESCE(c.name, \'สินค้าของร้าน\') AS consignor_name
             FROM sale_items si
             JOIN products p ON p.id = si.product_id
             LEFT JOIN consignors c ON c.id = si.consignor_id
             WHERE si.sale_id = ? ORDER BY si.id'
        );
        $stmt->execute([$saleId]);
        $items = array_map(fn ($r) => [
            'sale_item_id' => (int) $r['sale_item_id'], 'product_id' => (int) $r['product_id'], 'name' => $r['name'],
            'barcode' => $r['barcode'], 'consignor_name' => $r['consignor_name'], 'qty' => (int) $r['qty'],
            'pack_size' => max(1, (int) $r['pack_size']), 'unit_price' => (float) $r['unit_price'],
            'line_total' => round((float) $r['unit_price'] * (int) $r['qty'], 2),
        ], $stmt->fetchAll());
        $stmt = $this->db->prepare('SELECT method, amount FROM sale_payments WHERE sale_id = ? ORDER BY id');
        $stmt->execute([$saleId]);
        $payments = array_map(fn ($p) => ['method' => $p['method'], 'amount' => (float) $p['amount']], $stmt->fetchAll());
        return [
            'items' => $items,
            'payments' => $payments,
            'total_qty' => array_sum(array_column($items, 'qty')),
            'total_value' => round(array_sum(array_column($items, 'line_total')), 2),
        ];
    }

    /**
     * The cashier asks to change their sale to $items / $payments.
     * @param array<int,array{sale_item_id?:int, product_id:int, qty:int, pack_size?:int}> $items the whole sale as it should be
     * @param array<int,array{method:string, amount:float}> $payments must add up to the new total
     */
    public function request(int $saleId, array $items, array $payments, string $reason, array $user, ?string $ip): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่ขอแก้ไข');
        }
        if (!$this->enabled()) {
            throw new \RuntimeException('ผู้ดูแลระบบปิดการแก้ไขการขายไว้');
        }
        $userId = (int) $user['id'];

        $this->db->beginTransaction();
        try {
            $sale = $this->detail($saleId);
            if (!$sale || (int) $sale['cashier_id'] !== $userId) {
                throw new \RuntimeException('ไม่พบรายการขายของคุณ');
            }
            $this->db->prepare('SELECT id FROM sales WHERE id = ? FOR UPDATE')->execute([$saleId]);
            if ($sale['pos_import']) {
                throw new \RuntimeException('ยอดขายที่นำเข้าจาก POS ภายนอกแก้ไขที่หน้านำเข้ายอดขาย');
            }
            if (array_filter($sale['requests'], fn ($r) => $r['status'] === 'pending')) {
                throw new \RuntimeException('การขายนี้มีคำขอแก้ไขรออนุมัติอยู่แล้ว');
            }
            if ($sale['editable_until'] === null) {
                throw new \RuntimeException("แก้ไขได้ภายใน {$this->minutes()} นาทีหลังขายเสร็จเท่านั้น");
            }

            $before = $this->snapshot($saleId);
            $after = $this->proposed($before, $items, $payments, $user['role'] ?? null);
            if ($this->sameSale($before, $after)) {
                throw new \RuntimeException('ไม่มีการเปลี่ยนแปลงจากรายการเดิม');
            }

            $this->db->prepare(
                'INSERT INTO sale_edit_requests (sale_id, requested_by, requested_at, reason, before_data, after_data, total_before, total_after, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending\')'
            )->execute([
                $saleId, $userId, date('Y-m-d H:i:s'), mb_substr($reason, 0, 500),
                json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($after, JSON_UNESCAPED_UNICODE),
                $before['total_value'], $after['total_value'],
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->audit->log('sale_edit_requests', $id, 'create', null, [
                'doc_no' => $sale['doc_no'], 'reason' => $reason, 'total_before' => $before['total_value'], 'total_after' => $after['total_value'],
            ], $userId, $ip);
            $this->db->commit();
            return ['id' => $id, 'doc_no' => $sale['doc_no']];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** The cashier takes back a request no one has looked at yet. */
    public function cancel(int $id, int $userId, ?string $ip): void
    {
        $req = $this->lockRequest($id);
        if ((int) $req['requested_by'] !== $userId) {
            throw new \RuntimeException('ยกเลิกได้เฉพาะคำขอของตัวเอง');
        }
        $this->db->prepare("UPDATE sale_edit_requests SET status = 'cancelled', reviewed_by = ?, reviewed_at = ? WHERE id = ?")
            ->execute([$userId, date('Y-m-d H:i:s'), $id]);
        $this->audit->log('sale_edit_requests', $id, 'cancel', ['status' => 'pending'], ['status' => 'cancelled'], $userId, $ip);
        $this->db->commit();
    }

    public function reject(int $id, string $note, int $userId, ?string $ip): void
    {
        $note = trim($note);
        if ($note === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลที่ไม่อนุมัติ');
        }
        $this->lockRequest($id);
        $this->db->prepare("UPDATE sale_edit_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?")
            ->execute([$userId, date('Y-m-d H:i:s'), mb_substr($note, 0, 500), $id]);
        $this->audit->log('sale_edit_requests', $id, 'update', ['status' => 'pending'], ['status' => 'rejected', 'note' => $note], $userId, $ip);
        $this->db->commit();
    }

    /**
     * Applies an approved request: lines change in place (qty 0 = taken out, new lines
     * added at the price asked), stock follows, each consignor's passbook gets correcting
     * entries on the sale's accounting day, and the payments are replaced (the cash
     * drawer's figures follow). Stock may go below zero: the goods already left the store.
     */
    public function approve(int $id, string $note, array $user, ?string $ip): array
    {
        $userId = (int) $user['id'];
        $req = $this->lockRequest($id);
        try {
            if ((int) $req['requested_by'] === $userId && ($user['role'] ?? null) !== 'admin') {
                throw new \RuntimeException('อนุมัติคำขอของตัวเองไม่ได้ ต้องให้ผู้จัดการคนอื่นหรือผู้ดูแลระบบอนุมัติ');
            }
            $stmt = $this->db->prepare('SELECT * FROM sales WHERE id = ? FOR UPDATE');
            $stmt->execute([$req['sale_id']]);
            $sale = $stmt->fetch();
            if (!$sale) {
                throw new \RuntimeException('ไม่พบรายการขาย');
            }
            $before = json_decode((string) $req['before_data'], true);
            $after = json_decode((string) $req['after_data'], true);
            $current = $this->snapshot((int) $sale['id']);
            if (!$this->sameSale($before, $current)) {
                throw new \RuntimeException('รายการขายนี้เปลี่ยนไปหลังจากยื่นคำขอแล้ว กรุณาไม่อนุมัติ แล้วให้พนักงานยื่นคำขอใหม่');
            }

            $audit = $this->audit;
            $products = new ProductRepo($this->db, $audit);
            $ledger = new LedgerService($this->db);
            $docs = new DocumentNumberService($this->db);
            $listingFees = new ListingFeeService($this->db, $this->settings);
            $sales = new SaleService($this->db, $products, $ledger, $docs, $listingFees, $audit);

            $stmt = $this->db->prepare('SELECT * FROM sale_items WHERE sale_id = ?');
            $stmt->execute([$sale['id']]);
            $old = [];
            foreach ($stmt->fetchAll() as $r) {
                $old[(int) $r['id']] = $r;
            }

            $perConsignor = []; // consignor id => [value, fee, added qty, removed qty]
            $track = function (?int $cid, float $value, float $fee, int $qty) use (&$perConsignor) {
                if ($cid === null) {
                    return;
                }
                $perConsignor[$cid] ??= ['value' => 0.0, 'fee' => 0.0, 'added' => 0, 'removed' => 0];
                $perConsignor[$cid]['value'] += $value;
                $perConsignor[$cid]['fee'] += $fee;
                $perConsignor[$cid][$qty >= 0 ? 'added' : 'removed'] += abs($qty);
            };
            $upd = $this->db->prepare('UPDATE sale_items SET qty = ?, fee_amount = ?, cost_amount = ? WHERE id = ?');
            $ins = $this->db->prepare(
                'INSERT INTO sale_items (sale_id, product_id, consignor_id, qty, pack_size, unit_price, fee_amount, cost_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($after['items'] as $line) {
                $qty = (int) $line['qty'];
                if (!empty($line['sale_item_id'])) {
                    $row = $old[(int) $line['sale_item_id']];
                    unset($old[(int) $line['sale_item_id']]);
                    $delta = $qty - (int) $row['qty'];
                    if ($delta === 0) {
                        continue;
                    }
                    $feePerUnit = (float) $row['fee_amount'] / max(1, (int) $row['qty']);
                    $costPerUnit = (float) $row['cost_amount'] / max(1, (int) $row['qty']);
                    $upd->execute([$qty, round($feePerUnit * $qty, 2), round($costPerUnit * $qty, 2), $row['id']]);
                    $products->applySale((int) $row['product_id'], $delta, $userId, $ip);
                    $track($row['consignor_id'] !== null ? (int) $row['consignor_id'] : null,
                        (float) $row['unit_price'] * $delta, $feePerUnit * $delta, $delta);
                    continue;
                }
                $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
                $stmt->execute([$line['product_id']]);
                $product = $stmt->fetch();
                if (!$product) {
                    throw new \RuntimeException('ไม่พบสินค้า ' . $line['name']);
                }
                $packSize = (int) $line['pack_size'];
                $fig = $sales->lineFigures($product, $qty, $packSize > 1 ? null : (float) $line['unit_price'], $packSize);
                $ins->execute([
                    $sale['id'], $product['id'], $product['consignor_id'], $qty, $packSize > 1 ? $packSize : null,
                    $fig['unit_price'], $fig['fee_amount'], $fig['cost_amount'],
                ]);
                $products->applySale((int) $product['id'], $qty, $userId, $ip);
                $track($product['consignor_id'] !== null ? (int) $product['consignor_id'] : null, $fig['unit_price'] * $qty, $fig['fee_per_unit'] * $qty, $qty);
                if ($product['consignor_id'] !== null) {
                    $listingFees->maybeRefund((int) $product['id'], $ledger, $docs, $userId);
                }
            }
            // Lines left out of the request are taken off the sale.
            $del = $this->db->prepare('DELETE FROM sale_items WHERE id = ?');
            foreach ($old as $row) {
                $qty = (int) $row['qty'];
                $del->execute([$row['id']]);
                $products->applySale((int) $row['product_id'], -$qty, $userId, $ip);
                $track($row['consignor_id'] !== null ? (int) $row['consignor_id'] : null,
                    -(float) $row['unit_price'] * $qty, -(float) $row['fee_amount'], -$qty);
            }

            $docNo = (string) $sale['doc_no'];
            $date = (string) $sale['business_date'];
            foreach ($perConsignor as $cid => $agg) {
                $value = round($agg['value'], 2);
                $fee = round($agg['fee'], 2);
                $what = trim(($agg['added'] ? "เพิ่ม {$agg['added']} ชิ้น " : '') . ($agg['removed'] ? "นำออก {$agg['removed']} ชิ้น" : ''));
                if ($value > 0) {
                    $ledger->append($cid, 'sale', "แก้ไขการขาย {$docNo}: {$what}", $docNo, $value, 0, $userId, $date);
                } elseif ($value < 0) {
                    $ledger->append($cid, 'sale', "แก้ไขการขาย {$docNo}: {$what}", $docNo, 0, -$value, $userId, $date);
                }
                if ($fee > 0) {
                    $ledger->append($cid, 'fee', "หักค่าฝากขาย (แก้ไขการขาย {$docNo})", $docs->next('FEE', 'ledger_entries', 'ref_no'), 0, $fee, $userId, $date);
                } elseif ($fee < 0) {
                    $ledger->append($cid, 'fee', "คืนค่าฝากขาย (แก้ไขการขาย {$docNo})", $docNo, -$fee, 0, $userId, $date);
                }
            }

            $totals = $this->snapshot((int) $sale['id']);
            $this->db->prepare('UPDATE sales SET total_qty = ?, total_value = ? WHERE id = ?')
                ->execute([$totals['total_qty'], $totals['total_value'], $sale['id']]);
            if (!$this->samePayments($totals['payments'], $after['payments']) || abs($totals['total_value'] - (float) $sale['total_value']) > 0.004) {
                (new CashDrawerService($this->db, $audit))->editPayments((int) $sale['id'], $after['payments'], $userId, $ip);
            }

            $this->db->prepare("UPDATE sale_edit_requests SET status = 'approved', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?")
                ->execute([$userId, date('Y-m-d H:i:s'), trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null, $id]);
            $audit->log('sales', (int) $sale['id'], 'update', $before, $this->snapshot((int) $sale['id']) + ['edit_request_id' => $id], $userId, $ip);
            $audit->log('sale_edit_requests', $id, 'update', ['status' => 'pending'], ['status' => 'approved'], $userId, $ip);
            $this->db->commit();
            return ['id' => $id, 'doc_no' => $docNo, 'total_value' => $totals['total_value']];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Correction requests, newest first, with the sale and the names involved.
     * @param array{status?:string, sale_id?:int, requested_by?:int} $filters
     */
    public function requests(array $filters = [], int $limit = 200): array
    {
        $where = [];
        $params = [];
        foreach (['status' => 'r.status', 'sale_id' => 'r.sale_id', 'requested_by' => 'r.requested_by'] as $key => $col) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $where[] = "{$col} = ?";
                $params[] = $filters[$key];
            }
        }
        $stmt = $this->db->prepare(
            'SELECT r.*, s.doc_no, s.sale_datetime, s.business_date, ru.full_name AS requested_by_name, vu.full_name AS reviewed_by_name
             FROM sale_edit_requests r
             JOIN sales s ON s.id = r.sale_id
             LEFT JOIN users ru ON ru.id = r.requested_by
             LEFT JOIN users vu ON vu.id = r.reviewed_by'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY r.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        return array_map(function ($r) {
            $r['before'] = json_decode((string) $r['before_data'], true);
            $r['after'] = json_decode((string) $r['after_data'], true);
            unset($r['before_data'], $r['after_data']);
            return $r;
        }, $stmt->fetchAll());
    }

    public function pendingCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM sale_edit_requests WHERE status = 'pending'")->fetchColumn();
    }

    /**
     * Checks the cashier's wanted lines and payments against the sale as it is.
     * Existing lines keep their price; new lines are priced as the POS would now.
     */
    private function proposed(array $before, array $items, array $payments, ?string $role): array
    {
        $byId = [];
        foreach ($before['items'] as $it) {
            $byId[$it['sale_item_id']] = $it;
        }
        $out = [];
        $seen = [];
        $sales = new SaleService(
            $this->db, new ProductRepo($this->db, $this->audit), new LedgerService($this->db),
            new DocumentNumberService($this->db), new ListingFeeService($this->db, $this->settings), $this->audit
        );
        foreach ($items as $line) {
            $qty = (int) ($line['qty'] ?? 0);
            if ($qty < 0) {
                throw new \RuntimeException('จำนวนต้องไม่ติดลบ');
            }
            if ($qty === 0) {
                continue;
            }
            $itemId = (int) ($line['sale_item_id'] ?? 0);
            if ($itemId) {
                $it = $byId[$itemId] ?? null;
                if (!$it || isset($seen[$itemId])) {
                    throw new \RuntimeException('รายการสินค้าไม่ตรงกับการขายนี้');
                }
                $seen[$itemId] = true;
                if ($qty % $it['pack_size'] !== 0) {
                    throw new \RuntimeException("{$it['name']} ขายยกแพ็ค จำนวนต้องเป็นทวีคูณของ {$it['pack_size']}");
                }
                $out[] = ['qty' => $qty, 'line_total' => round($it['unit_price'] * $qty, 2)] + $it;
                continue;
            }
            $stmt = $this->db->prepare(
                "SELECT p.*, COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name FROM products p
                 LEFT JOIN consignors c ON c.id = p.consignor_id WHERE p.id = ?"
            );
            $stmt->execute([(int) ($line['product_id'] ?? 0)]);
            $product = $stmt->fetch();
            if (!$product) {
                throw new \RuntimeException('ไม่พบสินค้า');
            }
            $packSize = max(1, (int) ($line['pack_size'] ?? 1));
            $fig = $sales->lineFigures($product, $qty, null, $packSize);
            $out[] = [
                'sale_item_id' => null, 'product_id' => (int) $product['id'], 'name' => $product['name'], 'barcode' => $product['barcode'],
                'consignor_name' => $product['consignor_name'], 'qty' => $qty, 'pack_size' => $packSize,
                'unit_price' => $fig['unit_price'], 'line_total' => round($fig['unit_price'] * $qty, 2),
            ];
        }
        if (!$out) {
            throw new \RuntimeException('การขายต้องมีสินค้าอย่างน้อย 1 รายการ (ถ้าต้องการยกเลิกทั้งบิล กรุณาแจ้งผู้จัดการ)');
        }
        $total = round(array_sum(array_column($out, 'line_total')), 2);

        $byMethod = [];
        $allowed = $this->settings->paymentMethods();
        foreach ($payments as $p) {
            $method = (string) ($p['method'] ?? '');
            $amount = round((float) ($p['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            if (!in_array($method, $allowed, true)) {
                throw new \RuntimeException('ร้านไม่ได้เปิดรับชำระด้วยวิธีนี้');
            }
            $byMethod[$method] = round(($byMethod[$method] ?? 0) + $amount, 2);
        }
        if (count($byMethod) > 1 && !$this->settings->posRoleAllows('pos_mixed_roles', $role)) {
            throw new \RuntimeException('ร้านไม่ได้เปิดรับการชำระแบบผสม');
        }
        if (!$byMethod || abs(array_sum($byMethod) - $total) > 0.004) {
            throw new \RuntimeException('ยอดรวมของวิธีชำระต้องเท่ากับยอดขายใหม่ ฿' . number_format($total, 2));
        }
        return [
            'items' => $out,
            'payments' => array_map(fn ($m, $a) => ['method' => $m, 'amount' => $a], array_keys($byMethod), $byMethod),
            'total_qty' => array_sum(array_column($out, 'qty')),
            'total_value' => $total,
        ];
    }

    private function sameSale(array $a, array $b): bool
    {
        $lines = fn (array $s) => array_map(fn ($it) => [(int) ($it['sale_item_id'] ?? 0), (int) $it['product_id'], (int) $it['qty'], (int) $it['pack_size']], $s['items']);
        return $lines($a) === $lines($b) && $this->samePayments($a['payments'], $b['payments']);
    }

    private function samePayments(array $a, array $b): bool
    {
        $norm = function (array $ps) {
            $out = [];
            foreach ($ps as $p) {
                $out[$p['method']] = round(($out[$p['method']] ?? 0) + (float) $p['amount'], 2);
            }
            ksort($out);
            return $out;
        };
        return $norm($a) == $norm($b);
    }

    /** Locks a pending request inside a new transaction (left open for the caller). */
    private function lockRequest(int $id): array
    {
        $this->db->beginTransaction();
        $stmt = $this->db->prepare('SELECT * FROM sale_edit_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $req = $stmt->fetch();
        if (!$req || $req['status'] !== 'pending') {
            $this->db->rollBack();
            throw new \RuntimeException($req ? 'คำขอนี้ได้รับการพิจารณาไปแล้ว' : 'ไม่พบคำขอแก้ไข');
        }
        return $req;
    }
}
