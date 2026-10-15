<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Goods bought by the pack (e.g. "X12") are stocked and sold per piece:
        // qty/unit_cost stay as printed on the receipt (packs), units/piece_cost are per piece.
        $db->exec(<<<SQL
            ALTER TABLE purchase_items
                ADD COLUMN pack_size INT NOT NULL DEFAULT 1 AFTER unit_cost,
                ADD COLUMN units INT NOT NULL DEFAULT 0 AFTER pack_size,
                ADD COLUMN piece_cost DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER units,
                ADD COLUMN barcode VARCHAR(64) NULL AFTER receipt_name,
                ADD KEY idx_barcode (barcode)
        SQL);
        $db->exec('UPDATE purchase_items SET units = qty, piece_cost = unit_cost WHERE units = 0');
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE purchase_items DROP KEY idx_barcode, DROP COLUMN barcode, DROP COLUMN piece_cost, DROP COLUMN units, DROP COLUMN pack_size');
    }
};
