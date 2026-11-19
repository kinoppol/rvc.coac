<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Bundle promotions: products bought together sell for less. A promotion has one or more
        // slots ("ช่อง"); each slot needs a number of pieces taken from any of its items, and an item
        // is a product or a whole sale group (migration 0084), so "any drink + any snack" works.
        // kind: price = the whole set sells for value; amount = value baht off each set;
        // percent = value % off each set. The web POS applies them (PromoService::apply).
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS promo_bundles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                kind VARCHAR(10) NOT NULL DEFAULT 'price',
                value DECIMAL(12,2) NOT NULL,
                starts_on DATE NULL,
                ends_on DATE NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                note VARCHAR(500) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS promo_bundle_slots (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                promo_id INT UNSIGNED NOT NULL,
                position INT NOT NULL DEFAULT 0,
                label VARCHAR(100) NULL,
                qty INT NOT NULL DEFAULT 1,
                KEY idx_promo_slots_promo (promo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS promo_bundle_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                slot_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NULL,
                sale_group_id INT UNSIGNED NULL,
                KEY idx_promo_items_slot (slot_id),
                KEY idx_promo_items_product (product_id),
                KEY idx_promo_items_group (sale_group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        // What a sale line got off from a promotion (unit_price is already the price after it),
        // and the bill's total discount.
        $has = fn (string $t, string $c) => (bool) $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' AND COLUMN_NAME = '{$c}'")->fetchColumn();
        if (!$has('sale_items', 'promo_discount')) {
            $db->exec('ALTER TABLE sale_items ADD COLUMN promo_discount DECIMAL(12,2) NULL, ADD COLUMN promo_id INT UNSIGNED NULL, ADD KEY idx_sale_items_promo (promo_id)');
        }
        if (!$has('sales', 'promo_discount')) {
            $db->exec('ALTER TABLE sales ADD COLUMN promo_discount DECIMAL(12,2) NOT NULL DEFAULT 0');
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE sales DROP COLUMN promo_discount');
        $db->exec('ALTER TABLE sale_items DROP KEY idx_sale_items_promo, DROP COLUMN promo_id, DROP COLUMN promo_discount');
        $db->exec('DROP TABLE IF EXISTS promo_bundle_items');
        $db->exec('DROP TABLE IF EXISTS promo_bundle_slots');
        $db->exec('DROP TABLE IF EXISTS promo_bundles');
    }
};
