<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Goods bought in packs are sold by the piece, but a pack may also be sold whole
        // at its own price (NULL = not sold as a pack). A pack sold whole is booked as its
        // pieces at the pack price per piece, so the price per piece needs more decimals
        // (130 / 12 = 10.8333); pack_size keeps which pack the line was sold as.
        $db->exec('ALTER TABLE product_pack_barcodes ADD COLUMN sale_price DECIMAL(10,2) NULL AFTER pack_size');
        $db->exec('ALTER TABLE sale_items MODIFY unit_price DECIMAL(12,4) NOT NULL, ADD COLUMN pack_size INT NULL AFTER qty');
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE sale_items DROP COLUMN pack_size, MODIFY unit_price DECIMAL(10,2) NOT NULL');
        $db->exec('ALTER TABLE product_pack_barcodes DROP COLUMN sale_price');
    }
};
