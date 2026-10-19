<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A cancelled purchase keeps its document; its lines are removed (so reports
        // and stock no longer count them) and kept as a JSON snapshot for viewing.
        $db->exec(<<<SQL
            ALTER TABLE purchase_documents
                ADD COLUMN status ENUM('active','cancelled') NOT NULL DEFAULT 'active' AFTER source,
                ADD COLUMN cancelled_at DATETIME NULL AFTER created_at,
                ADD COLUMN cancelled_by INT UNSIGNED NULL AFTER cancelled_at,
                ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_by,
                ADD COLUMN cancelled_items MEDIUMTEXT NULL AFTER cancel_reason
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE purchase_documents DROP COLUMN cancelled_items, DROP COLUMN cancel_reason, DROP COLUMN cancelled_by, DROP COLUMN cancelled_at, DROP COLUMN status');
    }
};
