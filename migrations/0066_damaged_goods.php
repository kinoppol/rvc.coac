<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Damaged goods written off a store product (a broken piece in a big pack, spoiled,
        // expired...): each record (DG-...) takes the pieces out of stock at the cost per piece of
        // the day, with why and, when known, the purchase they came in with (stats per supplier).
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS damaged_goods (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                doc_no VARCHAR(30) NOT NULL UNIQUE,
                product_id INT UNSIGNED NOT NULL,
                qty INT NOT NULL,
                unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
                damage_date DATE NOT NULL,
                reason VARCHAR(20) NOT NULL DEFAULT 'other',
                purchase_document_id INT UNSIGNED NULL,
                supplier_name VARCHAR(191) NULL,
                note VARCHAR(255) NULL,
                user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                cancelled_at DATETIME NULL,
                cancelled_by INT UNSIGNED NULL,
                cancel_reason VARCHAR(255) NULL,
                KEY idx_damage_product (product_id),
                KEY idx_damage_date (damage_date),
                KEY idx_damage_purchase (purchase_document_id),
                CONSTRAINT fk_damage_product FOREIGN KEY (product_id) REFERENCES products(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS damaged_goods');
    }
};
