<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Wages paid to staff (cashiers, store managers): a business expense taken off
        // the store's profit. Cash paid out of the POS drawer records the drawer's day
        // (drawer_date) so that day's expected cash goes down by the same amount.
        // A wrong entry is cancelled (kept, with a reason), never deleted.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS wage_payments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                doc_no VARCHAR(30) NOT NULL UNIQUE,
                user_id INT UNSIGNED NOT NULL,
                pay_date DATE NOT NULL,
                period_from DATE NULL,
                period_to DATE NULL,
                wage_type ENUM('day','hour','fixed') NOT NULL DEFAULT 'day',
                units DECIMAL(10,2) NULL,
                rate DECIMAL(12,2) NULL,
                amount DECIMAL(12,2) NOT NULL,
                method ENUM('cash','transfer') NOT NULL DEFAULT 'cash',
                drawer_date DATE NULL,
                note VARCHAR(500) NULL,
                created_by INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                cancelled_at DATETIME NULL,
                cancelled_by INT UNSIGNED NULL,
                cancel_reason VARCHAR(500) NULL,
                KEY idx_wage_user (user_id, pay_date),
                KEY idx_wage_date (pay_date),
                KEY idx_wage_drawer (drawer_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['admin', 'manager'] as $role) {
            $perm->execute([$role, 'wages.manage']);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key = 'wages.manage'");
        $db->exec('DROP TABLE IF EXISTS wage_payments');
    }
};
