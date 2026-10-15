<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS settings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS settings_audit_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL,
                old_value TEXT NULL,
                new_value TEXT NULL,
                changed_by INT UNSIGNED NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_key (setting_key),
                CONSTRAINT fk_sal_user FOREIGN KEY (changed_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $defaults = [
            'fee_mode' => 'fixed',
            'fee_fixed' => '2',
            'fee_percent' => '10',
            'rounding_mode' => 'whole_baht',
            'rounding_beneficiary' => 'store',
            'listing_fee' => '5',
            'free_listings' => '5',
            'waive_qty' => '20',
        ];
        $stmt = $db->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($defaults as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS settings_audit_log');
        $db->exec('DROP TABLE IF EXISTS settings');
    }
};
