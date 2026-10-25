<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A cashier's request to correct a POS sale they made (within a few minutes of it,
        // when the admin allows it). The sale stays as it was until a manager or admin
        // approves; before/after hold the sale's lines and payments as JSON, so what was
        // asked for and what it replaced stay on record whichever way it went.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS sale_edit_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                sale_id INT UNSIGNED NOT NULL,
                requested_by INT UNSIGNED NOT NULL,
                requested_at DATETIME NOT NULL,
                reason VARCHAR(500) NOT NULL,
                before_data MEDIUMTEXT NOT NULL,
                after_data MEDIUMTEXT NOT NULL,
                total_before DECIMAL(12,2) NOT NULL,
                total_after DECIMAL(12,2) NOT NULL,
                status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
                reviewed_by INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                review_note VARCHAR(500) NULL,
                KEY idx_sale_edit_sale (sale_id),
                KEY idx_sale_edit_status (status, requested_at),
                KEY idx_sale_edit_user (requested_by, requested_at),
                CONSTRAINT fk_sale_edit_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['admin', 'manager'] as $role) {
            $perm->execute([$role, 'sales.edit.approve']);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key = 'sales.edit.approve'");
        $db->exec('DROP TABLE IF EXISTS sale_edit_requests');
    }
};
