<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS ledger_entries (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                consignor_id INT UNSIGNED NOT NULL,
                entry_date DATE NOT NULL,
                description VARCHAR(255) NOT NULL,
                ref_no VARCHAR(30) NOT NULL,
                type ENUM('open','sale','fee','listing_fee','listing_fee_refund','withdrawal') NOT NULL,
                amount_in DECIMAL(12,2) NOT NULL DEFAULT 0,
                amount_out DECIMAL(12,2) NOT NULL DEFAULT 0,
                balance_after DECIMAL(12,2) NOT NULL DEFAULT 0,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_consignor (consignor_id),
                KEY idx_date (entry_date),
                CONSTRAINT fk_ledger_consignor FOREIGN KEY (consignor_id) REFERENCES consignors(id),
                CONSTRAINT fk_ledger_user FOREIGN KEY (created_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS withdrawals (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                doc_no VARCHAR(30) NOT NULL UNIQUE,
                consignor_id INT UNSIGNED NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                method ENUM('cash','promptpay','bank_transfer') NOT NULL DEFAULT 'cash',
                status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
                requested_by INT UNSIGNED NOT NULL,
                approved_by INT UNSIGNED NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                paid_at DATETIME NULL,
                KEY idx_consignor (consignor_id),
                KEY idx_status (status),
                CONSTRAINT fk_wd_consignor FOREIGN KEY (consignor_id) REFERENCES consignors(id),
                CONSTRAINT fk_wd_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
                CONSTRAINT fk_wd_approved_by FOREIGN KEY (approved_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS withdrawals');
        $db->exec('DROP TABLE IF EXISTS ledger_entries');
    }
};
