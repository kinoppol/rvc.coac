<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepo;
use App\Support\Db;
use PDO;

/**
 * Old stock: goods the store bought before it used the system (migration 0092). What they cost
 * is capital the store put in, not something it earned. For a product marked so:
 *  - the first approved count of it brings those goods into the books: its difference is capital,
 *    not goods found or lost (later counts count as any other);
 *  - pieces it was created with (no purchase, receipt or split behind them) are capital too;
 *  - sales of it take their cost like any sale.
 */
class OldStockService
{
    public function __construct(private PDO $db, private AuditLogger $audit)
    {
    }

    public static function ready(PDO $db): bool
    {
        return Db::hasColumn($db, 'products', 'old_stock');
    }

    /** SQL true for a count line (alias sci, its count sc) that is the product's first approved one. */
    private static function firstCount(): string
    {
        return "NOT EXISTS (SELECT 1 FROM stock_count_items e JOIN stock_counts ec ON ec.id = e.stock_count_id
                 WHERE e.product_id = sci.product_id AND e.counted_qty IS NOT NULL AND ec.status = 'approved'
                   AND (ec.approved_at < sc.approved_at OR (ec.approved_at = sc.approved_at AND e.id < sci.id)))";
    }

    /**
     * Condition to add to a query of count differences (aliases sci, sc, p) that leaves out the
     * ones which are old stock coming into the books.
     */
    public static function notCapital(PDO $db): string
    {
        return self::ready($db) ? ' AND NOT (p.old_stock = 1 AND ' . self::firstCount() . ')' : '';
    }

    /**
     * Marks a store product as old stock (with what one piece of it cost) or takes the mark off.
     * A product with no cost yet gets this one, and its sales booked at cost 0 are costed with it.
     *
     * @return array{old:bool, cost:float, sales_costed:int}
     */
    public function set(int $productId, bool $old, ?float $cost, int $userId, ?string $ip): array
    {
        if (!self::ready($this->db)) {
            throw new \RuntimeException('ยังระบุสินค้าเก่าไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0092) ก่อน');
        }
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $p = $stmt->fetch();
            if (!$p || $p['consignor_id'] !== null) {
                throw new \RuntimeException('ระบุเป็นสินค้าเก่าได้เฉพาะสินค้าของร้าน');
            }
            $costed = 0;
            if ($old) {
                $now = round((float) $p['cost_price'], 2);
                $cost = round($cost ?? $now, 2);
                if ($cost <= 0 || $cost > 1000000) {
                    throw new \RuntimeException("\"{$p['name']}\" ยังไม่ทราบต้นทุน กรุณาระบุต้นทุน/ชิ้นของสินค้าเก่า");
                }
                $bought = $this->db->prepare("SELECT 1 FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
                    WHERE pi.product_id = ? AND pi.item_type = 'stock' AND pd.status = 'active' LIMIT 1");
                $bought->execute([$productId]);
                if ($now < 0.005 || (!$bought->fetchColumn() && abs($now - $cost) >= 0.005)) {
                    // No cost from a purchase: this is the product's cost (past sales are costed with it).
                    $costed = (new ProductRepo($this->db, $this->audit))->setKnownCost($productId, $cost, $userId, $ip)['lines'];
                } else {
                    $upd = $this->db->prepare('UPDATE sale_items SET cost_amount = ROUND(qty * ?, 2), fee_amount = ROUND(qty * unit_price - qty * ?, 2)
                        WHERE product_id = ? AND consignor_id IS NULL AND qty > 0 AND cost_amount = 0');
                    $upd->execute([$cost, $cost, $productId]);
                    $costed = $upd->rowCount();
                }
            } else {
                $cost = null;
            }
            $this->db->prepare('UPDATE products SET old_stock = ?, old_stock_cost = ?, old_stock_by = ?, old_stock_at = NOW(), updated_at = NOW() WHERE id = ?')
                ->execute([$old ? 1 : 0, $cost, $userId, $productId]);
            $this->audit->log('products', $productId, 'update',
                ['old_stock' => (int) $p['old_stock'], 'old_stock_cost' => $p['old_stock_cost']],
                ['old_stock' => $old ? 1 : 0, 'old_stock_cost' => $cost, 'sales_costed' => $costed], $userId, $ip);
            if ($own) {
                $this->db->commit();
            }
            return ['old' => $old, 'cost' => (float) $cost, 'sales_costed' => $costed];
        } catch (\Throwable $e) {
            if ($own) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The same for several products at once, each at the cost it has now; those with no cost are
     * left for setting one by one.
     *
     * @return array{done:int, skipped:array<int,string>}
     */
    public function setMany(array $ids, bool $old, int $userId, ?string $ip): array
    {
        $done = 0;
        $skipped = [];
        $find = $this->db->prepare('SELECT name, cost_price, consignor_id FROM products WHERE id = ?');
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $find->execute([$id]);
            $p = $find->fetch();
            if (!$p || $p['consignor_id'] !== null) {
                continue;
            }
            if ($old && !((float) $p['cost_price'] > 0)) {
                $skipped[] = (string) $p['name'];
                continue;
            }
            $this->set($id, $old, null, $userId, $ip);
            $done++;
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * What old stock added to the capital between two dates (all products, or one): a row per
     * first count that found a difference, and per product created with pieces of its own.
     *
     * @return array<int,array{date:string, product_id:int, product:string, source:string, doc_no:?string, qty:int, cost_price:float, value:float}>
     */
    public function capital(?string $from = null, ?string $to = null, ?int $productId = null): array
    {
        if (!self::ready($this->db)) {
            return [];
        }
        $cost = 'COALESCE(p.old_stock_cost, p.cost_price)';
        $store = 'p.old_stock = 1 AND p.consignor_id IS NULL' . ($productId === null ? '' : ' AND p.id = ' . (int) $productId);
        $rows = $this->db->query(
            "SELECT sc.count_date AS date, p.id AS product_id, p.name AS product, 'การตรวจนับครั้งแรก' AS source, sc.doc_no,
                    sci.diff_qty AS qty, {$cost} AS cost_price
             FROM stock_count_items sci JOIN stock_counts sc ON sc.id = sci.stock_count_id JOIN products p ON p.id = sci.product_id
             WHERE {$store} AND sc.status = 'approved' AND sci.counted_qty IS NOT NULL AND sci.diff_qty <> 0 AND " . self::firstCount()
        )->fetchAll();
        // Pieces received with no document behind them (the stock card's opening balance).
        $split = ProductSplitService::available($this->db)
            ? ' - COALESCE((SELECT SUM(spi.qty) FROM product_split_items spi JOIN product_splits sp ON sp.id = spi.split_id WHERE spi.product_id = p.id AND sp.cancelled_at IS NULL), 0)'
            : '';
        $live = Db::hasColumn($this->db, 'receive_documents', 'cancelled_at') ? ' AND rd.cancelled_at IS NULL' : '';
        $opening = $this->db->query(
            "SELECT DATE(p.created_at) AS date, p.id AS product_id, p.name AS product, 'ยอดตั้งต้นตอนสร้างสินค้า' AS source, NULL AS doc_no,
                    p.qty_received
                    - COALESCE((SELECT SUM(pi.units) FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
                                 WHERE pi.product_id = p.id AND pi.item_type = 'stock' AND COALESCE(pd.status, 'active') = 'active'), 0)
                    - COALESCE((SELECT SUM(ri.qty) FROM receive_items ri JOIN receive_documents rd ON rd.id = ri.receive_document_id
                                 WHERE ri.product_id = p.id{$live}), 0){$split} AS qty,
                    {$cost} AS cost_price
             FROM products p WHERE {$store}"
        )->fetchAll();
        foreach ($opening as $r) {
            if ((int) $r['qty'] > 0) {
                $rows[] = $r;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $date = (string) $r['date'];
            if (($from !== null && $date < $from) || ($to !== null && $date > $to)) {
                continue;
            }
            $out[] = [
                'date' => $date, 'product_id' => (int) $r['product_id'], 'product' => (string) $r['product'], 'source' => (string) $r['source'],
                'doc_no' => $r['doc_no'], 'qty' => (int) $r['qty'], 'cost_price' => (float) $r['cost_price'],
                'value' => round((int) $r['qty'] * (float) $r['cost_price'], 2),
            ];
        }
        usort($out, fn ($a, $b) => [$a['date'], $a['product']] <=> [$b['date'], $b['product']]);
        return $out;
    }
}
