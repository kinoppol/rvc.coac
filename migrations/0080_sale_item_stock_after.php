<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // The product's stock left right after each sale line, so sales that took the stock below
        // zero (e.g. goods without a barcode sold as the wrong product) are marked in the sales history.
        if (!$db->query("SHOW COLUMNS FROM sale_items LIKE 'stock_after'")->fetch()) {
            $db->exec('ALTER TABLE sale_items ADD COLUMN stock_after INT NULL');
        }
        $this->backfill($db);
    }

    /**
     * Past sales: every sale took its pieces off the product with an audit row (old / new product),
     * written in the same moment as the sale. Match each line with the first unused audit row of its
     * product that took the same number of pieces within two minutes of the sale; lines with no match
     * stay empty (not marked).
     */
    private function backfill(PDO $db): void
    {
        @set_time_limit(0);
        $products = $db->query(
            'SELECT DISTINCT si.product_id FROM sale_items si WHERE si.stock_after IS NULL'
        )->fetchAll(PDO::FETCH_COLUMN);
        $lines = $db->prepare(
            'SELECT si.id, si.qty, UNIX_TIMESTAMP(s.created_at) AS at
             FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE si.product_id = ? AND si.stock_after IS NULL ORDER BY s.created_at, si.id'
        );
        $audits = $db->prepare(
            "SELECT id, old_data, new_data, UNIX_TIMESTAMP(created_at) AS at FROM audit_logs
             WHERE table_name = 'products' AND record_id = ? AND action = 'update' ORDER BY id"
        );
        $set = $db->prepare('UPDATE sale_items SET stock_after = ? WHERE id = ?');

        foreach ($products as $productId) {
            $lines->execute([$productId]);
            $todo = $lines->fetchAll();
            if (!$todo) {
                continue;
            }
            // The product's sale takings: qty_sold went up and qty_stock down by the same number.
            $audits->execute([$productId]);
            $takes = [];
            while ($a = $audits->fetch()) {
                $old = json_decode((string) $a['old_data'], true);
                $new = json_decode((string) $a['new_data'], true);
                if (!is_array($old) || !is_array($new) || !isset($old['qty_sold'], $new['qty_sold'], $old['qty_stock'], $new['qty_stock'])) {
                    continue;
                }
                $sold = (int) $new['qty_sold'] - (int) $old['qty_sold'];
                if ($sold > 0 && (int) $old['qty_stock'] - (int) $new['qty_stock'] === $sold) {
                    $takes[] = ['at' => (int) $a['at'], 'qty' => $sold, 'left' => (int) $new['qty_stock']];
                }
            }
            $i = 0;
            $n = count($takes);
            foreach ($todo as $line) {
                $at = (int) $line['at'];
                while ($i < $n && $takes[$i]['at'] < $at - 2) {
                    $i++;
                }
                for ($j = $i; $j < $n && $takes[$j]['at'] <= $at + 120; $j++) {
                    if ($takes[$j]['qty'] === (int) $line['qty']) {
                        $set->execute([$takes[$j]['left'], $line['id']]);
                        array_splice($takes, $j, 1);
                        $n--;
                        break;
                    }
                }
            }
        }
    }

    public function down(PDO $db): void
    {
        if ($db->query("SHOW COLUMNS FROM sale_items LIKE 'stock_after'")->fetch()) {
            $db->exec('ALTER TABLE sale_items DROP COLUMN stock_after');
        }
    }
};
