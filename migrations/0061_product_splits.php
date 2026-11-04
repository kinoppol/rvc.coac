<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A pack bought as one product that holds pieces with their own barcodes (mixed flavours,
        // colours...): part of its stock is split off into separate products, each with its own
        // piece barcode and price. The split is the document that moves the stock: out of the
        // source product, into each piece product, at the source's cost per piece.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS product_splits (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                doc_no VARCHAR(30) NOT NULL UNIQUE,
                source_product_id INT UNSIGNED NOT NULL,
                qty INT NOT NULL,
                unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
                note VARCHAR(255) NULL,
                user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                cancelled_at DATETIME NULL,
                cancelled_by INT UNSIGNED NULL,
                cancel_reason VARCHAR(255) NULL,
                KEY idx_split_source (source_product_id),
                CONSTRAINT fk_split_source FOREIGN KEY (source_product_id) REFERENCES products(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS product_split_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                split_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                qty INT NOT NULL,
                unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
                created_product TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                KEY idx_split_item_split (split_id),
                KEY idx_split_item_product (product_id),
                CONSTRAINT fk_split_item_split FOREIGN KEY (split_id) REFERENCES product_splits(id) ON DELETE CASCADE,
                CONSTRAINT fk_split_item_product FOREIGN KEY (product_id) REFERENCES products(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS product_split_items');
        $db->exec('DROP TABLE IF EXISTS product_splits');
    }
};
