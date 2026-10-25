<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Photos taken while counting (one per save, kept for checking the count). Files are
        // removed 30 days later; the row stays with token NULL so the record shows there was one.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS stock_count_photos (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                stock_count_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                token VARCHAR(32) NULL,
                note VARCHAR(100) NULL,
                created_by INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_scp_count (stock_count_id, product_id),
                KEY idx_scp_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS stock_count_photos');
    }
};
