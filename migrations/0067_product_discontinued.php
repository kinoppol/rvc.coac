<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A consigned product the consignor no longer brings in: it leaves the stock count and the
        // POS while it holds no stock, and comes back by itself when goods are received again.
        $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'discontinued_at'")->fetchColumn();
        if (!$exists) {
            $db->exec('ALTER TABLE products ADD COLUMN discontinued_at DATETIME NULL');
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE products DROP COLUMN discontinued_at');
    }
};
