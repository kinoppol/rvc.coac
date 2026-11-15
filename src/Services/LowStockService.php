<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Db;
use PDO;

/**
 * When a store product counts as running low. Each product may have its own line
 * (products.low_stock_at, migration 0078) worked out from what it sold: the average sold per
 * day the shop was open (a day with any sale) over the last N days, times the days the stock
 * should last, rounded up. Products with no line use the shop-wide setting low_stock_threshold.
 */
final class LowStockService
{
    public const MAX_AVG_DAYS = 90;
    public const MAX_COVER_DAYS = 60;

    public function __construct(private PDO $db)
    {
    }

    public static function ready(PDO $db): bool
    {
        return Db::hasColumn($db, 'products', 'low_stock_at');
    }

    /**
     * SQL for a product's running-low line (alias p), with one ? for the shop-wide threshold.
     */
    public static function lineSql(PDO $db): string
    {
        return self::ready($db) ? 'COALESCE(p.low_stock_at, ?)' : '?';
    }

    /** The assistant's saved settings: days of sales to average, days the stock should last, line for products with no sales. */
    public function params(): array
    {
        $s = new SettingsService($this->db);
        return [
            'avg_days' => max(1, (int) $s->get('low_stock_avg_days')),
            'cover_days' => max(1, (int) $s->get('low_stock_cover_days')),
            'no_sales' => max(0, (int) $s->get('low_stock_no_sales')),
        ];
    }

    /**
     * The suggested line for every store product on sale. The days counted end yesterday
     * (today is not over); the shop's open days are the days in them with any sale at all.
     *
     * @return array{params: array, from: string, to: string, open_days: int, ready: bool, rows: array}
     */
    public function suggest(int $avgDays, int $coverDays, int $noSales): array
    {
        $avgDays = max(1, min(self::MAX_AVG_DAYS, $avgDays));
        $coverDays = max(1, min(self::MAX_COVER_DAYS, $coverDays));
        $noSales = max(0, min(100000, $noSales));
        $to = date('Y-m-d', strtotime('-1 day'));
        $from = date('Y-m-d', strtotime("-{$avgDays} days"));

        $stmt = $this->db->prepare('SELECT COUNT(DISTINCT business_date) FROM sales WHERE business_date BETWEEN ? AND ?');
        $stmt->execute([$from, $to]);
        $openDays = (int) $stmt->fetchColumn();

        $ready = self::ready($this->db);
        $current = $ready ? 'p.low_stock_at' : 'NULL';
        $onSale = Db::hasColumn($this->db, 'products', 'discontinued_at') ? 'AND p.discontinued_at IS NULL' : '';
        $stmt = $this->db->prepare(
            "SELECT p.id, p.name, p.barcode, p.qty_stock, {$current} AS low_stock_at,
                    COALESCE(x.sold, 0) AS sold
             FROM products p
             LEFT JOIN (
                SELECT si.product_id, SUM(si.qty) AS sold
                FROM sale_items si JOIN sales s ON s.id = si.sale_id
                WHERE s.business_date BETWEEN ? AND ?
                GROUP BY si.product_id
             ) x ON x.product_id = p.id
             WHERE p.consignor_id IS NULL {$onSale}
             ORDER BY sold DESC, p.name ASC"
        );
        $stmt->execute([$from, $to]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $sold = (float) $r['sold'];
            $perDay = $sold > 0 && $openDays > 0 ? $sold / $openDays : 0.0;
            // A product that sold keeps at least 1; one that did not uses the line for no sales.
            $line = $perDay > 0 ? max(1, (int) ceil(round($perDay * $coverDays, 6))) : $noSales;
            $rows[] = [
                'id' => (int) $r['id'], 'name' => $r['name'], 'barcode' => $r['barcode'],
                'qty_stock' => (int) $r['qty_stock'],
                'low_stock_at' => $r['low_stock_at'] === null ? null : (int) $r['low_stock_at'],
                'sold' => $sold, 'per_day' => round($perDay, 2), 'suggested' => $line,
            ];
        }
        return [
            'params' => ['avg_days' => $avgDays, 'cover_days' => $coverDays, 'no_sales' => $noSales],
            'from' => $from, 'to' => $to, 'open_days' => $openDays, 'ready' => $ready, 'rows' => $rows,
        ];
    }

    /**
     * Saves each product's line (null = back to the shop-wide setting) and remembers the
     * assistant's settings for next time.
     *
     * @param array<int, array{id:int, low_stock_at:?int}> $items
     */
    public function apply(array $items, array $params, int $userId): int
    {
        if (!self::ready($this->db)) {
            throw new \RuntimeException('ยังบันทึกเกณฑ์รายสินค้าไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0078) ก่อน');
        }
        $upd = $this->db->prepare('UPDATE products SET low_stock_at = ? WHERE id = ? AND consignor_id IS NULL');
        $n = 0;
        $this->db->beginTransaction();
        try {
            foreach ($items as $it) {
                $v = $it['low_stock_at'] ?? null;
                $v = $v === null || $v === '' ? null : max(0, min(100000, (int) $v));
                $upd->execute([$v, (int) ($it['id'] ?? 0)]);
                $n += $upd->rowCount();
            }
            (new SettingsService($this->db))->update([
                'low_stock_avg_days' => (string) max(1, min(self::MAX_AVG_DAYS, (int) ($params['avg_days'] ?? 14))),
                'low_stock_cover_days' => (string) max(1, min(self::MAX_COVER_DAYS, (int) ($params['cover_days'] ?? 3))),
                'low_stock_no_sales' => (string) max(0, min(100000, (int) ($params['no_sales'] ?? 5))),
            ], $userId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $n;
    }
}
