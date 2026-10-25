<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Who went and bought the goods (a staff member, manager or admin), which may not be
        // the person who keyed the purchase in (user_id).
        $db->exec('ALTER TABLE purchase_documents ADD COLUMN buyer_id INT UNSIGNED NULL AFTER user_id');
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE purchase_documents DROP COLUMN buyer_id');
    }
};
