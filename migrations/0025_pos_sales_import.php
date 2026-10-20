<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Item names in an external POS report have no barcode or code that this
        // system knows, so each name is paired with a product once and reused on
        // later imports. action 'skip' = a POS item deliberately not imported.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pos_item_mappings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                pos_key VARCHAR(191) NOT NULL UNIQUE,
                pos_name VARCHAR(191) NOT NULL,
                pos_category VARCHAR(191) NULL,
                product_id INT UNSIGNED NULL,
                action ENUM('product','skip') NOT NULL DEFAULT 'product',
                source ENUM('ai','rule','manual','new') NOT NULL DEFAULT 'manual',
                times_used INT NOT NULL DEFAULT 0,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_used_at DATETIME NULL,
                KEY idx_posmap_product (product_id),
                CONSTRAINT fk_posmap_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // One row per imported day-report; the sale it produced holds the lines.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pos_imports (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                sale_date DATE NOT NULL,
                sale_id INT UNSIGNED NULL,
                source_label VARCHAR(191) NULL,
                line_count INT NOT NULL DEFAULT 0,
                skipped_count INT NOT NULL DEFAULT 0,
                new_products INT NOT NULL DEFAULT 0,
                total_qty INT NOT NULL DEFAULT 0,
                total_value DECIMAL(12,2) NOT NULL DEFAULT 0,
                user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_posimp_date (sale_date),
                CONSTRAINT fk_posimp_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
                CONSTRAINT fk_posimp_user FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec("ALTER TABLE ai_usage_logs MODIFY channel ENUM('chat','receipt_extract','pos_match') NOT NULL DEFAULT 'chat'");

        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['admin', 'manager'] as $role) {
            $perm->execute([$role, 'sales.import']);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key = 'sales.import'");
        $db->exec("UPDATE ai_usage_logs SET channel = 'chat' WHERE channel = 'pos_match'");
        $db->exec("ALTER TABLE ai_usage_logs MODIFY channel ENUM('chat','receipt_extract') NOT NULL DEFAULT 'chat'");
        $db->exec('DROP TABLE IF EXISTS pos_imports');
        $db->exec('DROP TABLE IF EXISTS pos_item_mappings');
    }
};
