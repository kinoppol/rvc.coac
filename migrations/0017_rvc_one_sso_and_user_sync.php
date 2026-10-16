<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Link to the RVC-ONE account (SSO user.id = user-sync id). oa_status
        // mirrors RVC-ONE; a suspended account cannot sign in even when the
        // local "active" flag is on.
        $db->exec(<<<SQL
            ALTER TABLE users
                ADD COLUMN oa_user_id INT UNSIGNED NULL AFTER id,
                ADD COLUMN oa_status ENUM('active','suspended') NULL AFTER active,
                ADD COLUMN last_login_at DATETIME NULL AFTER oa_status,
                ADD UNIQUE KEY uq_users_oa_user_id (oa_user_id)
        SQL);

        // Directory mirrored from the RVC-ONE user-sync API. Only people that
        // are granted access get a row in `users`; everyone else stays here.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS oa_users (
                oa_user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                first_name VARCHAR(191) NOT NULL DEFAULT '',
                last_name VARCHAR(191) NOT NULL DEFAULT '',
                department VARCHAR(191) NULL,
                user_type VARCHAR(30) NULL,
                status ENUM('active','suspended') NOT NULL DEFAULT 'active',
                username VARCHAR(191) NULL,
                email VARCHAR(191) NULL,
                google_email VARCHAR(191) NULL,
                remote_updated_at DATETIME NULL,
                synced_at DATETIME NOT NULL,
                KEY idx_oa_users_type (user_type),
                KEY idx_oa_users_name (first_name, last_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // One row per sync run. The server_time of the latest successful run
        // is the "since" of the next incremental run.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS oa_sync_runs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                mode ENUM('full','incremental') NOT NULL,
                user_type VARCHAR(30) NULL,
                since_value VARCHAR(40) NULL,
                server_time VARCHAR(40) NULL,
                status ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
                pages INT UNSIGNED NOT NULL DEFAULT 0,
                fetched INT UNSIGNED NOT NULL DEFAULT 0,
                added INT UNSIGNED NOT NULL DEFAULT 0,
                updated INT UNSIGNED NOT NULL DEFAULT 0,
                suspended INT UNSIGNED NOT NULL DEFAULT 0,
                error VARCHAR(500) NULL,
                triggered_by INT UNSIGNED NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                KEY idx_oa_sync_status (status, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS oa_sync_runs');
        $db->exec('DROP TABLE IF EXISTS oa_users');
        $db->exec('ALTER TABLE users DROP KEY uq_users_oa_user_id, DROP COLUMN last_login_at, DROP COLUMN oa_status, DROP COLUMN oa_user_id');
    }
};
