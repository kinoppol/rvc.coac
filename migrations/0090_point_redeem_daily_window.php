<?php

declare(strict_types=1);

use App\Support\Db;

return new class {
    public function up(PDO $db): void
    {
        // A daily time-of-day window for a point-redeem rule (e.g. 09:00–15:00): points may be spent on
        // its goods only within these hours each day, so members who redeem (a slower checkout) are kept
        // out of the busy times. NULL on either side = no daily limit (the rule runs all day, as before,
        // within its starts_on/ends_on lifetime).
        if (!Db::hasColumn($db, 'point_redeems', 'daily_from')) {
            $db->exec('ALTER TABLE point_redeems
                ADD COLUMN daily_from TIME NULL AFTER ends_on,
                ADD COLUMN daily_to TIME NULL AFTER daily_from');
        }
    }

    public function down(PDO $db): void
    {
        if (Db::hasColumn($db, 'point_redeems', 'daily_from')) {
            $db->exec('ALTER TABLE point_redeems DROP COLUMN daily_from, DROP COLUMN daily_to');
        }
    }
};
