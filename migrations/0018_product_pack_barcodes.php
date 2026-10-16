<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // One product can arrive in several packs (24, 30, 50 pieces...), each
        // with its own pack barcode. products.barcode stays the single-piece
        // barcode scanned at the POS; pack barcodes live here.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS product_pack_barcodes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                barcode VARCHAR(64) NOT NULL,
                pack_size INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NULL,
                UNIQUE KEY uq_pack_barcode (barcode),
                KEY idx_pack_product (product_id),
                CONSTRAINT fk_pack_barcode_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // Every pack barcode seen on a purchase; the latest purchase wins when
        // the same barcode was linked to more than one product.
        $db->exec(<<<SQL
            INSERT IGNORE INTO product_pack_barcodes (product_id, barcode, pack_size, created_at, last_seen_at)
            SELECT pi.product_id, pi.barcode, pi.pack_size, pd.created_at, pd.created_at
            FROM purchase_items pi
            JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
            JOIN products p ON p.id = pi.product_id AND p.consignor_id IS NULL
            WHERE pi.item_type = 'stock' AND pi.pack_size > 1 AND pi.barcode IS NOT NULL AND pi.barcode <> ''
            ORDER BY pi.id DESC
        SQL);
        $db->exec(<<<SQL
            INSERT IGNORE INTO product_pack_barcodes (product_id, barcode, pack_size)
            SELECT id, pack_barcode, pack_size FROM products
            WHERE pack_barcode IS NOT NULL AND pack_barcode <> '' AND pack_size > 1
        SQL);
        // A pack barcode that ended up as the product's piece barcode is not one.
        $db->exec(<<<SQL
            UPDATE products p JOIN product_pack_barcodes b ON b.product_id = p.id AND b.barcode = p.barcode
            SET p.barcode = NULL
        SQL);

        $db->exec('ALTER TABLE products DROP KEY idx_pack_barcode, DROP COLUMN pack_size, DROP COLUMN pack_barcode');
    }

    public function down(PDO $db): void
    {
        $db->exec(<<<SQL
            ALTER TABLE products
                ADD COLUMN pack_barcode VARCHAR(64) NULL AFTER barcode,
                ADD COLUMN pack_size INT NULL AFTER pack_barcode,
                ADD KEY idx_pack_barcode (pack_barcode)
        SQL);
        $db->exec(<<<SQL
            UPDATE products p JOIN (
                SELECT b.product_id, b.barcode, b.pack_size FROM product_pack_barcodes b
                WHERE b.id = (SELECT MAX(b2.id) FROM product_pack_barcodes b2 WHERE b2.product_id = b.product_id)
            ) x ON x.product_id = p.id
            SET p.pack_barcode = x.barcode, p.pack_size = x.pack_size
        SQL);
        $db->exec('DROP TABLE IF EXISTS product_pack_barcodes');
    }
};
