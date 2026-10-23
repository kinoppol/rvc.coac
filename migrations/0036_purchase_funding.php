<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Where the money for a purchase came from. Goods bought with invested money
        // add to the business's capital (งบลงทุน); goods bought with the business's own
        // profit do not.
        $db->exec("ALTER TABLE purchase_documents ADD COLUMN funding ENUM('capital','profit') NOT NULL DEFAULT 'capital' AFTER source");
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE purchase_documents DROP COLUMN funding');
    }
};
