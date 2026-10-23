<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // The cash drawer of one accounting day: the cash counted before selling
        // (opening_cash) and the count at closing, checked against cash sales.
        // Declared late (after cash sales were made), the count included that cash:
        // counted_at_open keeps what was counted and opening_cash has it taken off.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS cash_drawers (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                business_date DATE NOT NULL,
                opening_cash DECIMAL(12,2) NOT NULL,
                counted_at_open DECIMAL(12,2) NOT NULL,
                cash_sales_before_open DECIMAL(12,2) NOT NULL DEFAULT 0,
                last_sale_id_at_open INT UNSIGNED NOT NULL DEFAULT 0,
                late_open TINYINT(1) NOT NULL DEFAULT 0,
                opened_by INT UNSIGNED NOT NULL,
                opened_at DATETIME NOT NULL,
                open_note VARCHAR(500) NULL,
                closed_by INT UNSIGNED NULL,
                closed_at DATETIME NULL,
                counted_cash DECIMAL(12,2) NULL,
                expected_cash DECIMAL(12,2) NULL,
                cash_diff DECIMAL(12,2) NULL,
                close_note VARCHAR(500) NULL,
                UNIQUE KEY uq_drawer_date (business_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        // What staff did with the drawer, kept for reviewing their work: opening on
        // time or late, each "skip for now", closing, and payment-method corrections
        // (subject_user_id = the cashier whose sale was corrected).
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS cash_drawer_events (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                business_date DATE NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                subject_user_id INT UNSIGNED NULL,
                event VARCHAR(30) NOT NULL,
                amount DECIMAL(12,2) NULL,
                detail VARCHAR(500) NULL,
                created_at DATETIME NOT NULL,
                KEY idx_drawer_event_date (business_date),
                KEY idx_drawer_event_user (user_id, created_at),
                KEY idx_drawer_event_subject (subject_user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        // Correcting the opening count, re-closing a day and fixing payment methods:
        // admin and store manager only.
        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['admin', 'manager'] as $role) {
            $perm->execute([$role, 'drawer.manage']);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key = 'drawer.manage'");
        $db->exec('DROP TABLE IF EXISTS cash_drawer_events');
        $db->exec('DROP TABLE IF EXISTS cash_drawers');
    }
};
