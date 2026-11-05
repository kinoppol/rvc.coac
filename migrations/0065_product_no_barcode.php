<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A product that really has no barcode (made in-house, sold loose): marked so it is told
        // apart from one whose barcode simply has not been collected yet.
        $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'no_barcode'")->fetchColumn();
        if (!$exists) {
            $db->exec('ALTER TABLE products ADD COLUMN no_barcode TINYINT(1) NOT NULL DEFAULT 0 AFTER barcode');
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE products DROP COLUMN no_barcode');
    }
};
