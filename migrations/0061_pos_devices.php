<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Android POS devices, paired like a TV: the app shows a short code, an admin
        // types it on the อุปกรณ์ POS page and picks the account the device sells as.
        // The app then holds a device token (only its SHA-256 is kept here).
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pos_devices (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                model VARCHAR(100) NULL,
                platform VARCHAR(50) NULL,
                app_version VARCHAR(30) NULL,
                status ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
                pair_code CHAR(6) NULL,
                pair_secret_hash CHAR(64) NULL,
                pair_expires_at DATETIME NULL,
                token_hash CHAR(64) NULL,
                user_id INT UNSIGNED NULL,
                created_ip VARCHAR(45) NULL,
                created_at DATETIME NOT NULL,
                paired_at DATETIME NULL,
                paired_by INT UNSIGNED NULL,
                last_seen_at DATETIME NULL,
                last_ip VARCHAR(45) NULL,
                last_sync_at DATETIME NULL,
                pending_count INT NOT NULL DEFAULT 0,
                revoked_at DATETIME NULL,
                revoked_by INT UNSIGNED NULL,
                UNIQUE KEY uq_pos_device_token (token_hash),
                KEY idx_pos_device_code (pair_code),
                KEY idx_pos_device_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        // Every sale a device sends, keyed by the id the device gave it: sending the same
        // sale again (a lost reply, a retry after the internet came back) never books it
        // twice. A sale the server could not book stays here as failed for an admin.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pos_device_sales (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                device_id INT UNSIGNED NOT NULL,
                client_uuid CHAR(36) NOT NULL,
                status ENUM('synced','failed','resolved') NOT NULL,
                sale_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                sold_at DATETIME NULL,
                offline TINYINT(1) NOT NULL DEFAULT 0,
                total_value DECIMAL(12,2) NULL,
                payload MEDIUMTEXT NOT NULL,
                error VARCHAR(500) NULL,
                warnings TEXT NULL,
                attempts INT NOT NULL DEFAULT 1,
                received_at DATETIME NOT NULL,
                synced_at DATETIME NULL,
                resolved_by INT UNSIGNED NULL,
                resolved_at DATETIME NULL,
                resolve_note VARCHAR(500) NULL,
                UNIQUE KEY uq_device_sale (device_id, client_uuid),
                KEY idx_device_sale_status (status),
                KEY idx_device_sale_sale (sale_id),
                CONSTRAINT fk_device_sale_device FOREIGN KEY (device_id) REFERENCES pos_devices(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['admin', 'manager'] as $role) {
            $perm->execute([$role, 'devices.manage']);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key = 'devices.manage'");
        $db->exec('DROP TABLE IF EXISTS pos_device_sales');
        $db->exec('DROP TABLE IF EXISTS pos_devices');
    }
};
