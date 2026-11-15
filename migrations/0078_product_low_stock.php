<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Each store product's own "running low" line: this many pieces or fewer count as running
        // low. Set by the low-stock assistant from what the product sold (average per day the shop
        // sold anything, times the days the stock should last); NULL = the shop-wide setting
        // low_stock_threshold.
        $db->exec('ALTER TABLE products ADD COLUMN low_stock_at INT UNSIGNED NULL');
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE products DROP COLUMN low_stock_at');
    }
};
