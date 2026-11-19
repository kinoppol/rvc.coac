<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Bundle promotions start and end at a time of day, not only on a day (e.g. a lunch-hour deal).
        // An end set as a day so far ran to the end of that day: it ends at 23:59:59 now.
        $db->exec('ALTER TABLE promo_bundles MODIFY starts_on DATETIME NULL, MODIFY ends_on DATETIME NULL');
        $db->exec("UPDATE promo_bundles SET ends_on = DATE_ADD(ends_on, INTERVAL 86399 SECOND) WHERE ends_on IS NOT NULL AND TIME(ends_on) = '00:00:00'");
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE promo_bundles MODIFY starts_on DATE NULL, MODIFY ends_on DATE NULL');
    }
};
