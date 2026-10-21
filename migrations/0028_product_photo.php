<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A photo of the product taken while counting stock on a phone; the
        // image itself is an attachment (storage/uploads/products/...).
        $db->exec('ALTER TABLE products ADD COLUMN image_token CHAR(32) NULL AFTER barcode');
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE products DROP COLUMN image_token');
    }
};
