<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Production date, required when a consignor lists goods or staff receive them.
        $db->exec('ALTER TABLE products ADD COLUMN mfg_date DATE NULL AFTER qty_stock');
        $db->exec('ALTER TABLE consign_request_items ADD COLUMN mfg_date DATE NULL AFTER barcode');
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE consign_request_items DROP COLUMN mfg_date');
        $db->exec('ALTER TABLE products DROP COLUMN mfg_date');
    }
};
