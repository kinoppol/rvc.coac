<?php

declare(strict_types=1);

use App\Support\Db;

return new class {
    public function up(PDO $db): void
    {
        // A bundle promotion of kind 'item' names the baht off one piece of each of its items, so it is
        // clear which goods the discount comes from. NULL for the other kinds (shared by price, as before).
        if (!Db::hasColumn($db, 'promo_bundle_items', 'discount')) {
            $db->exec('ALTER TABLE promo_bundle_items ADD COLUMN discount DECIMAL(12,2) NULL');
        }
    }

    public function down(PDO $db): void
    {
        if (Db::hasColumn($db, 'promo_bundle_items', 'discount')) {
            $db->exec('ALTER TABLE promo_bundle_items DROP COLUMN discount');
        }
    }
};
