<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // An order is drafted first (items can still be added, e.g. from the stock page),
        // then marked as placed with the supplier ('ordered', waiting for delivery).
        $db->exec("ALTER TABLE purchase_documents MODIFY status ENUM('draft','ordered','active','cancelled') NOT NULL DEFAULT 'active'");
    }

    public function down(PDO $db): void
    {
        $db->exec("UPDATE purchase_documents SET status = 'ordered' WHERE status = 'draft'");
        $db->exec("ALTER TABLE purchase_documents MODIFY status ENUM('ordered','active','cancelled') NOT NULL DEFAULT 'active'");
    }
};
