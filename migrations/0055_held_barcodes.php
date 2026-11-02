<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Piece barcodes put aside when duplicate products were merged with different barcodes:
        // the merged product goes on with the latest row's barcode, the others wait here for an
        // admin to check. Until removed, no other product may take one of them.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS held_barcodes (
                barcode VARCHAR(64) NOT NULL PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                from_product_id INT UNSIGNED NULL,
                note VARCHAR(255) NULL,
                user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                KEY idx_held_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS held_barcodes');
    }
};
