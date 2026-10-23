<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Signing in a shared screen (the POS) by scanning its QR-Code with a phone that
        // is already signed in. Only a hash of the code is kept; a code works once, for
        // 5 minutes, and only for the browser session that showed it.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS qr_logins (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                requester_ip VARCHAR(45) NULL,
                requester_agent VARCHAR(255) NULL,
                approved_by INT UNSIGNED NULL,
                approved_at DATETIME NULL,
                approver_ip VARCHAR(45) NULL,
                used_at DATETIME NULL,
                UNIQUE KEY uq_qr_code (code_hash),
                KEY idx_qr_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS qr_logins');
    }
};
