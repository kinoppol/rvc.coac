<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A pack may be priced for selling whole even when it has no barcode of its own
        // (picked with "+ ยกแพ็ค" in the POS cart). The unique key still holds: MySQL
        // allows any number of NULLs in it.
        $db->exec('ALTER TABLE product_pack_barcodes MODIFY barcode VARCHAR(64) NULL');
    }

    public function down(PDO $db): void
    {
        $db->exec('DELETE FROM product_pack_barcodes WHERE barcode IS NULL');
        $db->exec('ALTER TABLE product_pack_barcodes MODIFY barcode VARCHAR(64) NOT NULL');
    }
};
