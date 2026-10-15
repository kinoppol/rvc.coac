<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS consignors (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(20) NOT NULL UNIQUE,
                name VARCHAR(191) NOT NULL,
                type VARCHAR(100) NOT NULL,
                department VARCHAR(150) NULL,
                phone VARCHAR(30) NULL,
                id_card VARCHAR(20) NULL,
                pay_channel VARCHAR(50) NOT NULL DEFAULT 'cash',
                pay_account VARCHAR(100) NULL,
                advisor_teacher VARCHAR(150) NULL,
                status ENUM('active','pending_docs') NOT NULL DEFAULT 'pending_docs',
                portal_token VARCHAR(64) NULL UNIQUE,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_name (name),
                KEY idx_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS consignors');
    }
};
