<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Corrections of a stock-card adjustment (SA-...): the stock card works out "ปรับยอด" rows
        // where the system stock and the documents part ways (a count approved after documents
        // dated around it, documents cancelled after a count, or a gap nobody can explain). A
        // correction moves qty_stock by qty to undo or change one of them, with who, when and why;
        // it can be edited (the stock moves by the difference) or cancelled (moved back).
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS stock_adjustments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                doc_no VARCHAR(30) NOT NULL UNIQUE,
                product_id INT UNSIGNED NOT NULL,
                stock_count_id INT UNSIGNED NULL,
                cause VARCHAR(20) NOT NULL,
                adjust_date DATE NOT NULL,
                original_qty INT NOT NULL DEFAULT 0,
                qty INT NOT NULL,
                note VARCHAR(255) NOT NULL,
                user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL,
                cancelled_at DATETIME NULL,
                cancelled_by INT UNSIGNED NULL,
                cancel_reason VARCHAR(255) NULL,
                KEY idx_sa_product (product_id),
                KEY idx_sa_count (stock_count_id),
                CONSTRAINT fk_sa_product FOREIGN KEY (product_id) REFERENCES products(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS stock_adjustments');
    }
};
