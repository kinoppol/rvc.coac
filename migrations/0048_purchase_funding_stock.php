<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // 'stock': goods already in the store (left over from before, never bought through
        // the system) brought into stock by an admin. No money was spent, so they count
        // toward neither งบลงทุน nor purchases paid from profit.
        $db->exec("ALTER TABLE purchase_documents MODIFY COLUMN funding ENUM('capital','profit','stock') NOT NULL DEFAULT 'capital'");
    }

    public function down(PDO $db): void
    {
        $db->exec("UPDATE purchase_documents SET funding = 'capital' WHERE funding = 'stock'");
        $db->exec("ALTER TABLE purchase_documents MODIFY COLUMN funding ENUM('capital','profit') NOT NULL DEFAULT 'capital'");
    }
};
