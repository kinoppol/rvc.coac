<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // One reading of the server's disk a day (the first request of the day records it, or
        // bin/disk-usage.php from a scheduled task): how full the disk is and how much of it is
        // this system's own data, so an admin can see the daily growth and plan for more space.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS disk_usage_daily (
                day DATE NOT NULL PRIMARY KEY,
                disk_total BIGINT UNSIGNED NOT NULL,
                disk_free BIGINT UNSIGNED NOT NULL,
                uploads_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uploads_files INT UNSIGNED NOT NULL DEFAULT 0,
                logs_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                other_storage_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                db_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                app_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                recorded_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS disk_usage_daily');
    }
};
