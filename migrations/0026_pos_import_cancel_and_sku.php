<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A cancelled import keeps its row; its sale is removed (stock, ledger and
        // reports no longer count it) and its lines are kept as a JSON snapshot.
        $db->exec(<<<SQL
            ALTER TABLE pos_imports
                ADD COLUMN status ENUM('active','cancelled') NOT NULL DEFAULT 'active' AFTER sale_id,
                ADD COLUMN cancelled_at DATETIME NULL AFTER created_at,
                ADD COLUMN cancelled_by INT UNSIGNED NULL AFTER cancelled_at,
                ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_by,
                ADD COLUMN cancelled_items MEDIUMTEXT NULL AFTER cancel_reason,
                ADD COLUMN cancelled_doc_no VARCHAR(32) NULL AFTER cancelled_items
        SQL);

        // Newer POS reports carry a SKU: a pair is found by it first (the name may
        // change in the POS), and by name for reports without one.
        $db->exec(<<<SQL
            ALTER TABLE pos_item_mappings
                ADD COLUMN pos_sku VARCHAR(64) NULL AFTER pos_key,
                ADD KEY idx_posmap_sku (pos_sku)
        SQL);

        $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)')
            ->execute(['admin', 'sales.import.cancel']);
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key = 'sales.import.cancel'");
        $db->exec('ALTER TABLE pos_item_mappings DROP KEY idx_posmap_sku, DROP COLUMN pos_sku');
        $db->exec('ALTER TABLE pos_imports DROP COLUMN cancelled_doc_no, DROP COLUMN cancelled_items, DROP COLUMN cancel_reason, DROP COLUMN cancelled_by, DROP COLUMN cancelled_at, DROP COLUMN status');
    }
};
