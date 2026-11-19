<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Sale groups: small store goods with no barcode that look alike and cost the same (e.g. the
        // flavours of one sweet). They are bought in as separate products, but the POS shows the group
        // as one item; each piece sold is taken from the member with the most stock left, in turn,
        // until every member has run out (SaleGroupService::allocate).
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS sale_groups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                sale_price DECIMAL(12,2) NOT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $has = $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'sale_group_id'")->fetchColumn();
        if (!$has) {
            $db->exec('ALTER TABLE products ADD COLUMN sale_group_id INT UNSIGNED NULL, ADD KEY idx_products_sale_group (sale_group_id)');
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE products DROP KEY idx_products_sale_group, DROP COLUMN sale_group_id');
        $db->exec('DROP TABLE IF EXISTS sale_groups');
    }
};
