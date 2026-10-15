<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS sales (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                doc_no VARCHAR(30) NOT NULL UNIQUE,
                cashier_id INT UNSIGNED NOT NULL,
                sale_datetime DATETIME NOT NULL,
                total_qty INT NOT NULL DEFAULT 0,
                total_value DECIMAL(12,2) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_datetime (sale_datetime),
                CONSTRAINT fk_sales_cashier FOREIGN KEY (cashier_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS sale_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                sale_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                consignor_id INT UNSIGNED NOT NULL,
                qty INT NOT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                cost_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                KEY idx_sale (sale_id),
                KEY idx_product (product_id),
                KEY idx_consignor (consignor_id),
                CONSTRAINT fk_saleitem_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
                CONSTRAINT fk_saleitem_product FOREIGN KEY (product_id) REFERENCES products(id),
                CONSTRAINT fk_saleitem_consignor FOREIGN KEY (consignor_id) REFERENCES consignors(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS sale_items');
        $db->exec('DROP TABLE IF EXISTS sales');
    }
};
