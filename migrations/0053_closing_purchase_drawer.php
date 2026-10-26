<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Goods bought for the store are paid from that day's cash drawer unless the
        // person summing up the day marks one as paid with other money (e.g. profit
        // taken out on an earlier day). One row per purchase whose source was set.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS closing_purchase_flags (
                purchase_id INT UNSIGNED NOT NULL PRIMARY KEY,
                from_drawer TINYINT(1) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS closing_purchase_flags');
    }
};
