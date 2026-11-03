<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // An Android POS device may be shared by several sellers: each picks their profile on
        // the device to work (with a PIN when the admin set one). removed_at: taken off the
        // device — sales they made offline before that are still booked. pos_devices.user_id
        // stays the device's default profile.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pos_device_users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                device_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                pin_salt CHAR(32) NULL,
                pin_hash CHAR(64) NULL,
                added_at DATETIME NOT NULL,
                added_by INT UNSIGNED NULL,
                removed_at DATETIME NULL,
                UNIQUE KEY uq_pos_device_user (device_id, user_id),
                CONSTRAINT fk_pos_device_user_device FOREIGN KEY (device_id) REFERENCES pos_devices(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $db->exec(
            "INSERT IGNORE INTO pos_device_users (device_id, user_id, added_at, added_by)
             SELECT id, user_id, COALESCE(paired_at, created_at), paired_by FROM pos_devices WHERE user_id IS NOT NULL AND status <> 'pending'"
        );
        // How often the device sends and fetches in the background (PosDeviceService::SYNC_LEVELS),
        // set from the web page or the app — the later change wins. current_user_id: the profile
        // last working on the device.
        $db->exec(
            "ALTER TABLE pos_devices
                ADD COLUMN sync_level VARCHAR(10) NOT NULL DEFAULT 'medium',
                ADD COLUMN sync_level_at DATETIME NULL,
                ADD COLUMN sync_level_by VARCHAR(10) NULL,
                ADD COLUMN current_user_id INT UNSIGNED NULL"
        );
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE pos_devices DROP COLUMN sync_level, DROP COLUMN sync_level_at, DROP COLUMN sync_level_by, DROP COLUMN current_user_id');
        $db->exec('DROP TABLE IF EXISTS pos_device_users');
    }
};
