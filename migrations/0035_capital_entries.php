<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Capital put into the business (and taken back out): not income, so it stays
        // out of the profit figures and is reported on its own (รายงานงบลงทุน).
        // A wrong entry is cancelled with a reason, never deleted.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS capital_entries (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                doc_no VARCHAR(30) NOT NULL UNIQUE,
                entry_date DATE NOT NULL,
                type ENUM('invest','withdraw') NOT NULL DEFAULT 'invest',
                investor VARCHAR(191) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                method ENUM('cash','transfer') NOT NULL DEFAULT 'cash',
                note VARCHAR(500) NULL,
                created_by INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                cancelled_at DATETIME NULL,
                cancelled_by INT UNSIGNED NULL,
                cancel_reason VARCHAR(500) NULL,
                KEY idx_capital_date (entry_date),
                KEY idx_capital_investor (investor)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['admin', 'manager'] as $role) {
            $perm->execute([$role, 'capital.manage']);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key = 'capital.manage'");
        $db->exec('DROP TABLE IF EXISTS capital_entries');
    }
};
