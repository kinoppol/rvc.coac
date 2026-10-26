<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Consignors paid in cash are paid from that day's cash drawer unless the person
        // summing up the day marks one as paid with other money. One row per payout whose
        // source was set.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS closing_payout_flags (
                withdrawal_id INT UNSIGNED NOT NULL PRIMARY KEY,
                from_drawer TINYINT(1) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // Cash left in the drawer as change for the next day (the rest is handed over).
        $db->exec('ALTER TABLE daily_closings ADD COLUMN float_kept DECIMAL(12,2) NULL AFTER cash_out');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS closing_payout_flags');
        $db->exec('ALTER TABLE daily_closings DROP COLUMN float_kept');
    }
};
