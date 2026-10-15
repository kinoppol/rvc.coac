<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS stock_counts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                doc_no VARCHAR(30) NOT NULL UNIQUE,
                count_date DATE NOT NULL,
                status ENUM('draft','submitted','approved') NOT NULL DEFAULT 'draft',
                created_by INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                submitted_at DATETIME NULL,
                approved_by INT UNSIGNED NULL,
                approved_at DATETIME NULL,
                KEY idx_status (status),
                CONSTRAINT fk_sc_created_by FOREIGN KEY (created_by) REFERENCES users(id),
                CONSTRAINT fk_sc_approved_by FOREIGN KEY (approved_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS stock_count_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                stock_count_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                system_qty INT NOT NULL,
                counted_qty INT NULL,
                diff_qty INT NULL,
                note VARCHAR(255) NULL,
                KEY idx_count (stock_count_id),
                KEY idx_product (product_id),
                CONSTRAINT fk_sci_count FOREIGN KEY (stock_count_id) REFERENCES stock_counts(id) ON DELETE CASCADE,
                CONSTRAINT fk_sci_product FOREIGN KEY (product_id) REFERENCES products(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS stock_count_items');
        $db->exec('DROP TABLE IF EXISTS stock_counts');
    }
};
