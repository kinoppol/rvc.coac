<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // The mobile app for everyone who is not selling (docs/android-mobile-app.md): each phone or
        // tablet is paired by its owner on the web and works as that user, nobody else.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS mobile_devices (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                name VARCHAR(100) NOT NULL,
                model VARCHAR(100) NULL,
                platform VARCHAR(50) NULL,
                app_version VARCHAR(30) NULL,
                status ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
                pair_code CHAR(6) NULL,
                pair_secret_hash CHAR(64) NULL,
                pair_expires_at DATETIME NULL,
                token_hash CHAR(64) NULL,
                created_ip VARCHAR(45) NULL,
                created_at DATETIME NOT NULL,
                paired_at DATETIME NULL,
                last_seen_at DATETIME NULL,
                last_ip VARCHAR(45) NULL,
                last_sync_at DATETIME NULL,
                revoked_at DATETIME NULL,
                revoked_by INT UNSIGNED NULL,
                UNIQUE KEY uq_mobile_device_token (token_hash),
                KEY idx_mobile_device_code (pair_code, status),
                KEY idx_mobile_device_user (user_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS mobile_devices');
    }
};
