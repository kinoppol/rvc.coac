<?php

declare(strict_types=1);

use App\Support\Db;

return new class {
    public function up(PDO $db): void
    {
        // Goods the store already had before it used the system: their cost is the store's capital,
        // not profit. old_stock_cost is the cost of one piece of them.
        if (!Db::hasColumn($db, 'products', 'old_stock')) {
            $db->exec('ALTER TABLE products ADD COLUMN old_stock TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN old_stock_cost DECIMAL(12,2) NULL,
                ADD COLUMN old_stock_by INT UNSIGNED NULL,
                ADD COLUMN old_stock_at DATETIME NULL');
        }
    }

    public function down(PDO $db): void
    {
        if (Db::hasColumn($db, 'products', 'old_stock')) {
            $db->exec('ALTER TABLE products DROP COLUMN old_stock, DROP COLUMN old_stock_cost, DROP COLUMN old_stock_by, DROP COLUMN old_stock_at');
        }
    }
};
