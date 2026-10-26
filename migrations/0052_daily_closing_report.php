<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // What actually reached each receiving account (transfer / QR / card), as checked
        // by the person summing up the day: JSON {method: amount}.
        $db->exec('ALTER TABLE daily_closings ADD COLUMN actual_accounts TEXT NULL AFTER cash_sent');

        // A step of the day's report left for later on purpose ("skip for now"):
        // paying wages, paying consignors, buying goods, closing the drawer.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS closing_skips (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                business_date DATE NOT NULL,
                item VARCHAR(30) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                note VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_closing_skip (business_date, item)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // The person summing up follows the report's links: paying consignors, paying
        // wages and recording goods bought for the store.
        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['passbook.view', 'withdraw.create', 'withdraw.approve', 'wages.manage', 'purchase.create'] as $key) {
            $perm->execute(['closer', $key]);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE role = 'closer' AND permission_key IN ('passbook.view', 'withdraw.create', 'withdraw.approve', 'wages.manage', 'purchase.create')");
        $db->exec('DROP TABLE IF EXISTS closing_skips');
        $db->exec('ALTER TABLE daily_closings DROP COLUMN actual_accounts');
    }
};
