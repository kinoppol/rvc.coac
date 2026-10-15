<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(60) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                full_name VARCHAR(191) NOT NULL,
                role ENUM('admin','manager','advisor','cashier') NOT NULL DEFAULT 'cashier',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS role_permissions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                role ENUM('admin','manager','advisor','cashier') NOT NULL,
                permission_key VARCHAR(100) NOT NULL,
                allowed TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY uq_role_perm (role, permission_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS role_permissions');
        $db->exec('DROP TABLE IF EXISTS users');
    }
};
