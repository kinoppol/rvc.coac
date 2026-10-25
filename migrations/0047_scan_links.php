<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A phone used as a barcode scanner for a computer: the computer opens a link
        // (shown as a QR-Code), a signed-in phone scans it, and every barcode the phone
        // reads is queued here for the computer to pick up. Links expire after a few hours.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS scan_links (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code CHAR(32) NOT NULL UNIQUE,
                user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                phone_user_id INT UNSIGNED NULL,
                phone_seen_at DATETIME NULL,
                closed_at DATETIME NULL,
                KEY idx_scan_link_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS scan_link_codes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                link_id INT UNSIGNED NOT NULL,
                barcode VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_scan_code_link (link_id, id),
                CONSTRAINT fk_scan_code_link FOREIGN KEY (link_id) REFERENCES scan_links(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS scan_link_codes');
        $db->exec('DROP TABLE IF EXISTS scan_links');
    }
};
