<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS products (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                consignor_id INT UNSIGNED NOT NULL,
                name VARCHAR(191) NOT NULL,
                barcode VARCHAR(64) NULL,
                cost_price DECIMAL(10,2) NOT NULL,
                sale_price DECIMAL(10,2) NOT NULL,
                fee_per_unit DECIMAL(10,2) NOT NULL DEFAULT 0,
                round_per_unit DECIMAL(10,2) NOT NULL DEFAULT 0,
                rounding_beneficiary ENUM('store','consignor') NOT NULL DEFAULT 'store',
                qty_received INT NOT NULL DEFAULT 0,
                qty_sold INT NOT NULL DEFAULT 0,
                qty_returned INT NOT NULL DEFAULT 0,
                qty_stock INT NOT NULL DEFAULT 0,
                expiry_date DATE NULL,
                listing_fee_status ENUM('waived','charged') NOT NULL DEFAULT 'charged',
                listing_fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                listing_fee_refunded TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_consignor (consignor_id),
                KEY idx_barcode (barcode),
                KEY idx_name (name),
                CONSTRAINT fk_products_consignor FOREIGN KEY (consignor_id) REFERENCES consignors(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS products');
    }
};
