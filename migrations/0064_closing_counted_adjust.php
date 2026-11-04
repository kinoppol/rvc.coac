<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // The person summing up the day may correct the cash counted in the drawer at closing
        // (the staff's count stays as it was in cash_drawers). Every change is a row: the latest
        // one counts, amount NULL = the correction was taken back.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS closing_counted_adjusts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                business_date DATE NOT NULL,
                amount DECIMAL(12,2) NULL,
                reason VARCHAR(255) NULL,
                user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_counted_adjust_date (business_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS closing_counted_adjusts');
    }
};
