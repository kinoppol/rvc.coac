<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepo;
use App\Repositories\WithdrawalRepo;
use App\Support\Db;
use PDO;

/**
 * Paying a consignor from the daily report for the sales not yet paid out in a period:
 * the amount follows the chosen sale lines (the consignor's share of each), the person
 * summing up may pay a different amount with a reason (the account may go negative when
 * the store answers for lost goods), may set what is left of the consignor's goods, and
 * may hand back all that is left of some products (a return document, stock down to 0).
 */
class ConsignorPayoutService
{
    public function __construct(private PDO $db, private AuditLogger $audit, private DocumentNumberService $docs)
    {
    }

    public function ready(): bool
    {
        return Db::hasColumn($this->db, 'sale_items', 'withdrawal_id');
    }

    /**
     * The consignor's sales between two business days not paid out yet, per product, with
     * their balance and the goods they still have on the shelf.
     */
    public function unpaid(int $consignorId, string $from, string $to): array
    {
        $ready = $this->ready();
        $stmt = $this->db->prepare(
            "SELECT si.product_id, p.name, SUM(si.qty) AS qty, ROUND(SUM(si.cost_amount), 2) AS share,
                    ROUND(SUM(si.qty * si.unit_price), 2) AS sales, COUNT(*) AS line_count,
                    MIN(COALESCE(s.business_date, DATE(s.sale_datetime))) AS first_day,
                    MAX(COALESCE(s.business_date, DATE(s.sale_datetime))) AS last_day
             FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN products p ON p.id = si.product_id
             WHERE si.consignor_id = ? AND COALESCE(s.business_date, DATE(s.sale_datetime)) BETWEEN ? AND ?"
            . ($ready ? ' AND si.withdrawal_id IS NULL' : '') . '
             GROUP BY si.product_id, p.name ORDER BY p.name'
        );
        $stmt->execute([$consignorId, $from, $to]);
        $products = $this->db->prepare('SELECT id, name, qty_stock, sale_price, cost_price FROM products WHERE consignor_id = ? AND qty_stock <> 0 ORDER BY name');
        $products->execute([$consignorId]);
        return [
            'ready' => $ready,
            'from' => $from,
            'to' => $to,
            'balance' => (new LedgerService($this->db))->balanceOf($consignorId),
            'sales' => array_map(fn ($r) => [
                'product_id' => (int) $r['product_id'], 'qty' => (int) $r['qty'], 'share' => (float) $r['share'],
                'sales' => (float) $r['sales'], 'line_count' => (int) $r['line_count'],
            ] + $r, $stmt->fetchAll()),
            'stock' => $products->fetchAll(),
        ];
    }

    /**
     * @param array{consignor_id:int, from:string, to:string, product_ids:int[], amount:float, method:string,
     *              reason?:string, stock?:array<int, array{product_id:int, qty:int}>, returns?:int[]} $d
     * @return int the withdrawal id
     */
    public function pay(array $d, int $userId, ?string $ip): int
    {
        if (!$this->ready()) {
            throw new \RuntimeException('ยังจ่ายตามรายการขายไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0057) ก่อน');
        }
        $consignorId = (int) $d['consignor_id'];
        $amount = round((float) $d['amount'], 2);
        $method = (string) $d['method'];
        $reason = mb_substr(trim((string) ($d['reason'] ?? '')), 0, 150);
        [$from, $to] = [(string) $d['from'], (string) $d['to']];
        if ($amount <= 0) {
            throw new \RuntimeException('จำนวนเงินที่จ่ายต้องมากกว่า 0');
        }
        $productIds = array_values(array_unique(array_filter(array_map('intval', (array) ($d['product_ids'] ?? [])))));
        $stock = [];
        foreach ((array) ($d['stock'] ?? []) as $s) {
            $qty = (int) ($s['qty'] ?? -1);
            if ($qty < 0) {
                throw new \RuntimeException('จำนวนคงเหลือที่ปรับต้องไม่ติดลบ');
            }
            $stock[(int) ($s['product_id'] ?? 0)] = $qty;
        }
        $returns = array_values(array_unique(array_filter(array_map('intval', (array) ($d['returns'] ?? [])))));
        if ($stock && (new StockCountService($this->db, $this->docs, $this->audit))->hasOpenRound()) {
            throw new \RuntimeException('มีรอบตรวจนับสต๊อกเปิดอยู่ ปรับยอดคงเหลือได้ในรอบนั้นแทน');
        }

        $this->db->beginTransaction();
        try {
            // The chosen sale lines, locked so two payouts cannot take the same line.
            $lines = [];
            if ($productIds) {
                $in = implode(',', array_fill(0, count($productIds), '?'));
                $stmt = $this->db->prepare(
                    "SELECT si.id, si.cost_amount FROM sale_items si JOIN sales s ON s.id = si.sale_id
                     WHERE si.consignor_id = ? AND si.withdrawal_id IS NULL AND si.product_id IN ($in)
                       AND COALESCE(s.business_date, DATE(s.sale_datetime)) BETWEEN ? AND ? FOR UPDATE"
                );
                $stmt->execute([$consignorId, ...$productIds, $from, $to]);
                $lines = $stmt->fetchAll();
            }
            $salesAmount = round(array_sum(array_map(fn ($l) => (float) $l['cost_amount'], $lines)), 2);
            $differs = abs($amount - $salesAmount) >= 0.005;
            if ($differs && $reason === '') {
                throw new \RuntimeException('ยอดที่จ่ายไม่ตรงกับยอดขาย (฿' . number_format($salesAmount, 2) . ') กรุณาระบุเหตุผล');
            }

            $period = $from === $to ? self::thaiDate($from) : self::thaiDate($from) . ' - ' . self::thaiDate($to);
            $note = mb_substr("ยอดขาย {$period} ฿" . number_format($salesAmount, 2) . ($differs ? " · จ่าย ฿" . number_format($amount, 2) . ": {$reason}" : ''), 0, 255);
            $repo = new WithdrawalRepo($this->db, $this->audit, $this->docs, new LedgerService($this->db));
            // Over the balance is allowed here with the reason (the store answers for lost goods).
            $id = $repo->insertPaid($consignorId, $amount, $method, $userId, $ip, $reason !== '' ? $reason : null, $note);
            $this->db->prepare('UPDATE withdrawals SET sales_from = ?, sales_to = ?, sales_amount = ?, note = ? WHERE id = ?')
                ->execute([$from, $to, $salesAmount, $note, $id]);
            if ($lines) {
                $ids = array_column($lines, 'id');
                $in = implode(',', array_fill(0, count($ids), '?'));
                $this->db->prepare("UPDATE sale_items SET withdrawal_id = ? WHERE id IN ($in)")->execute([$id, ...$ids]);
            }
            if ($stock) {
                $this->adjustStock($consignorId, $stock, $id, $reason, $userId, $ip);
            }
            if ($returns) {
                $this->returnAll($consignorId, $returns, $id, $note, $userId, $ip);
            }
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** All that is left of the chosen products goes back to the consignor: one return document, stock 0. */
    private function returnAll(int $consignorId, array $productIds, int $withdrawalId, string $note, int $userId, ?string $ip): void
    {
        $in = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->db->prepare("SELECT id, name, qty_stock FROM products WHERE id IN ($in) AND consignor_id = ? FOR UPDATE");
        $stmt->execute([...$productIds, $consignorId]);
        $rows = $stmt->fetchAll();
        if (count($rows) !== count($productIds)) {
            throw new \RuntimeException('คืนสินค้าได้เฉพาะสินค้าของผู้ฝากขายรายนี้');
        }
        $items = [];
        foreach ($rows as $p) {
            if ((int) $p['qty_stock'] > 0) {
                $items[] = ['product_id' => (int) $p['id'], 'qty' => (int) $p['qty_stock']];
            }
        }
        if (!$items) {
            return; // nothing left on the shelf to hand back
        }
        $c = $this->db->prepare('SELECT name FROM consignors WHERE id = ?');
        $c->execute([$consignorId]);
        $doc = (new ReturnService($this->db, new ProductRepo($this->db, $this->audit), $this->docs, $this->audit))
            ->returnGoods($consignorId, $items, ($c->fetchColumn() ?: null), $userId, $ip);
        $this->db->prepare('UPDATE withdrawals SET note = ? WHERE id = ?')
            ->execute([mb_substr("{$note} · คืนสินค้า {$doc['doc_no']} {$doc['total_qty']} ชิ้น", 0, 255), $withdrawalId]);
    }

    /** What is left of the consignor's goods, set with the payout: one approved stock count. */
    private function adjustStock(int $consignorId, array $stock, int $withdrawalId, string $reason, int $userId, ?string $ip): void
    {
        $in = implode(',', array_fill(0, count($stock), '?'));
        $stmt = $this->db->prepare("SELECT * FROM products WHERE id IN ($in) AND consignor_id = ? FOR UPDATE");
        $stmt->execute([...array_keys($stock), $consignorId]);
        $rows = $stmt->fetchAll();
        if (count($rows) !== count($stock)) {
            throw new \RuntimeException('ปรับยอดได้เฉพาะสินค้าของผู้ฝากขายรายนี้');
        }
        $wd = $this->db->prepare('SELECT doc_no FROM withdrawals WHERE id = ?');
        $wd->execute([$withdrawalId]);
        $wdNo = (string) $wd->fetchColumn();
        $docNo = $this->docs->next('SC', 'stock_counts', 'doc_no');
        $this->db->prepare(
            "INSERT INTO stock_counts (doc_no, count_date, status, created_by, created_at, submitted_at, approved_by, approved_at)
             VALUES (?, CURDATE(), 'approved', ?, NOW(), NOW(), ?, NOW())"
        )->execute([$docNo, $userId, $userId]);
        $countId = (int) $this->db->lastInsertId();
        $item = $this->db->prepare('INSERT INTO stock_count_items (stock_count_id, product_id, system_qty, counted_qty, diff_qty, note) VALUES (?, ?, ?, ?, ?, ?)');
        $upd = $this->db->prepare('UPDATE products SET qty_stock = ?, updated_at = NOW() WHERE id = ?');
        $note = mb_substr("ปรับยอดตอนจ่ายเงินผู้ฝาก {$wdNo}" . ($reason !== '' ? ": {$reason}" : ''), 0, 255);
        foreach ($rows as $p) {
            $system = (int) $p['qty_stock'];
            $counted = $stock[(int) $p['id']];
            $item->execute([$countId, $p['id'], $system, $counted, $counted - $system, $note]);
            if ($counted !== $system) {
                $upd->execute([$counted, $p['id']]);
                $this->audit->log('products', (int) $p['id'], 'update', ['qty_stock' => $system], ['qty_stock' => $counted, 'stock_count' => $docNo, 'withdrawal' => $wdNo], $userId, $ip);
            }
        }
        $this->audit->log('stock_counts', $countId, 'create', null, ['doc_no' => $docNo, 'withdrawal' => $wdNo, 'items' => $stock], $userId, $ip);
    }

    private static function thaiDate(string $iso): string
    {
        $m = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        [$y, $mo, $d] = array_map('intval', explode('-', $iso));
        return "{$d} {$m[$mo]} " . ($y + 543);
    }
}
