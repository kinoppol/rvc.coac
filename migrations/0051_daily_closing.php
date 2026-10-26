<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // ผู้สรุปบัญชีรายวัน: checks one accounting day on their duty weekday, sums it up
        // and hands the day's cash over to the college.
        $db->exec("ALTER TABLE users MODIFY role ENUM('admin','manager','advisor','cashier','consignor','closer') NOT NULL DEFAULT 'cashier'");
        $db->exec("ALTER TABLE role_permissions MODIFY role ENUM('admin','manager','advisor','cashier','consignor','closer') NOT NULL");

        // Duty roster: who sums up the days falling on each weekday (ISO 1 = Monday .. 7 = Sunday).
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS closing_duties (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                weekday TINYINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                UNIQUE KEY uq_duty (weekday, user_id),
                CONSTRAINT fk_duty_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // One summary per accounting day: the figures as checked (snapshot), the cash
        // handed to the college and its receipt; an admin / manager may confirm it.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS daily_closings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                business_date DATE NOT NULL,
                closer_id INT UNSIGNED NOT NULL,
                total_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
                cash_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
                transfer_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
                card_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
                unknown_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
                cash_out DECIMAL(12,2) NOT NULL DEFAULT 0,
                expected_send DECIMAL(12,2) NOT NULL DEFAULT 0,
                cash_sent DECIMAL(12,2) NOT NULL DEFAULT 0,
                sent_to VARCHAR(150) NULL,
                receipt_no VARCHAR(60) NULL,
                note VARCHAR(500) NULL,
                snapshot TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                verified_by INT UNSIGNED NULL,
                verified_at DATETIME NULL,
                UNIQUE KEY uq_closing_date (business_date),
                KEY idx_closing_closer (closer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['dashboard.view', 'reports.view', 'closing.submit'] as $key) {
            $perm->execute(['closer', $key]);
        }
        // Admin / manager: see every day, set the roster, confirm or correct a summary.
        foreach (['admin', 'manager'] as $role) {
            $perm->execute([$role, 'closing.submit']);
            $perm->execute([$role, 'closing.manage']);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key IN ('closing.submit', 'closing.manage') OR role = 'closer'");
        $db->exec('DROP TABLE IF EXISTS daily_closings');
        $db->exec('DROP TABLE IF EXISTS closing_duties');
        $db->exec("UPDATE users SET role = 'cashier' WHERE role = 'closer'");
        $db->exec("ALTER TABLE role_permissions MODIFY role ENUM('admin','manager','advisor','cashier','consignor') NOT NULL");
        $db->exec("ALTER TABLE users MODIFY role ENUM('admin','manager','advisor','cashier','consignor') NOT NULL DEFAULT 'cashier'");
    }
};
