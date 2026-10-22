<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // How each POS sale was paid; a mixed payment has one row per method.
        // Cash rows hold the amount applied to the bill (received minus change).
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS sale_payments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                sale_id INT UNSIGNED NOT NULL,
                method ENUM('cash','transfer','qr','card') NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                KEY idx_payment_sale (sale_id),
                KEY idx_payment_method (method),
                CONSTRAINT fk_payment_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $db->exec(<<<SQL
            ALTER TABLE sales
                ADD COLUMN cash_received DECIMAL(12,2) NULL AFTER total_value,
                ADD COLUMN change_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cash_received
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE sales DROP COLUMN change_amount, DROP COLUMN cash_received');
        $db->exec('DROP TABLE IF EXISTS sale_payments');
    }
};
