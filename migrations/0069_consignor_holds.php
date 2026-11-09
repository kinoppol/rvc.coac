<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A consignor paid by holding: the money leaves the day's drawer for the account the store
        // manager keeps for consignors, and waits there until the consignor asks for it.
        $db->exec("ALTER TABLE withdrawals MODIFY method ENUM('cash','promptpay','bank_transfer','hold') NOT NULL DEFAULT 'cash'");
        // The passbook notes when held money reaches the consignor; it was already taken off the balance.
        $db->exec("ALTER TABLE ledger_entries MODIFY type
            ENUM('open','sale','fee','listing_fee','listing_fee_refund','withdrawal','withdrawal_cancel','adjust','hold_release') NOT NULL");
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS consignor_hold_payouts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                doc_no VARCHAR(30) NOT NULL UNIQUE,
                consignor_id INT UNSIGNED NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                method ENUM('cash','promptpay','bank_transfer') NOT NULL,
                note VARCHAR(255) NULL,
                user_id INT UNSIGNED NOT NULL,
                paid_at DATETIME NOT NULL,
                cancelled_by INT UNSIGNED NULL,
                cancelled_at DATETIME NULL,
                cancel_reason VARCHAR(255) NULL,
                KEY idx_hold_payout_consignor (consignor_id),
                KEY idx_hold_payout_paid (paid_at),
                CONSTRAINT fk_hold_payout_consignor FOREIGN KEY (consignor_id) REFERENCES consignors(id),
                CONSTRAINT fk_hold_payout_user FOREIGN KEY (user_id) REFERENCES users(id),
                CONSTRAINT fk_hold_payout_cancel FOREIGN KEY (cancelled_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        // Each time the account was checked against the bank: what the system expected as of that
        // day and what the account really held.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS consignor_hold_checks (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                check_date DATE NOT NULL,
                expected_amount DECIMAL(12,2) NOT NULL,
                actual_amount DECIMAL(12,2) NOT NULL,
                note VARCHAR(255) NULL,
                user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_hold_check_date (check_date),
                CONSTRAINT fk_hold_check_user FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        // ReadOnlyGuard keeps the executive role to looking.
        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['admin', 'manager', 'executive'] as $role) {
            $perm->execute([$role, 'hold.manage']);
        }
    }

    public function down(PDO $db): void
    {
        if ((int) $db->query("SELECT COUNT(*) FROM withdrawals WHERE method = 'hold'")->fetchColumn() > 0) {
            throw new \RuntimeException('มีรายการพักเงินผู้ฝากขายอยู่แล้ว ย้อน migration นี้ไม่ได้');
        }
        $db->exec("DELETE FROM role_permissions WHERE permission_key = 'hold.manage'");
        $db->exec('DROP TABLE IF EXISTS consignor_hold_checks');
        $db->exec('DROP TABLE IF EXISTS consignor_hold_payouts');
        $db->exec("DELETE FROM ledger_entries WHERE type = 'hold_release'");
        $db->exec("ALTER TABLE ledger_entries MODIFY type
            ENUM('open','sale','fee','listing_fee','listing_fee_refund','withdrawal','withdrawal_cancel','adjust') NOT NULL");
        $db->exec("ALTER TABLE withdrawals MODIFY method ENUM('cash','promptpay','bank_transfer') NOT NULL DEFAULT 'cash'");
    }
};
