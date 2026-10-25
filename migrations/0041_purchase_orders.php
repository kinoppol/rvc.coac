<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Goods ordered from a supplier who delivers them later: the purchase is saved as
        // 'ordered' with its lines kept as entered (order_items, JSON) and no stock moves.
        // When the goods arrive the order is confirmed: its lines are applied like a new
        // purchase and it becomes 'active', recorded (created_at / user_id) at that moment.
        $db->exec("ALTER TABLE purchase_documents
            MODIFY status ENUM('ordered','active','cancelled') NOT NULL DEFAULT 'active',
            ADD COLUMN order_items MEDIUMTEXT NULL AFTER cancelled_items,
            ADD COLUMN ordered_at DATETIME NULL AFTER order_items,
            ADD COLUMN ordered_by INT UNSIGNED NULL AFTER ordered_at");
    }

    public function down(PDO $db): void
    {
        $db->exec("UPDATE purchase_documents SET status = 'cancelled' WHERE status = 'ordered'");
        $db->exec("ALTER TABLE purchase_documents
            DROP COLUMN ordered_by, DROP COLUMN ordered_at, DROP COLUMN order_items,
            MODIFY status ENUM('active','cancelled') NOT NULL DEFAULT 'active'");
    }
};
