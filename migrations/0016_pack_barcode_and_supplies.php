<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // The barcode printed for the whole pack (not the single piece sold at
        // the POS) is kept on the product for reference and future matching.
        $db->exec(<<<SQL
            ALTER TABLE products
                ADD COLUMN pack_barcode VARCHAR(64) NULL AFTER barcode,
                ADD COLUMN pack_size INT NULL AFTER pack_barcode,
                ADD KEY idx_pack_barcode (pack_barcode)
        SQL);

        // Lines bought for use in the business (carrier bags, straws, ...) are
        // recorded as cost only: no product, no stock, no selling price.
        $db->exec(<<<SQL
            ALTER TABLE purchase_items
                ADD COLUMN item_type ENUM('stock','supply') NOT NULL DEFAULT 'stock' AFTER product_id,
                MODIFY product_id INT UNSIGNED NULL
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM purchase_items WHERE item_type = 'supply'");
        $db->exec('ALTER TABLE purchase_items DROP COLUMN item_type, MODIFY product_id INT UNSIGNED NOT NULL');
        $db->exec('ALTER TABLE products DROP KEY idx_pack_barcode, DROP COLUMN pack_size, DROP COLUMN pack_barcode');
    }
};
