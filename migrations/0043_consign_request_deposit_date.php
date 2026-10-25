<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // The day the consignor brings the goods. Goods made fresh each day may be
        // requested the day before (up to 3 days ahead); made on or before that day.
        $db->exec('ALTER TABLE consign_requests ADD COLUMN deposit_date DATE NULL AFTER note');
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE consign_requests DROP COLUMN deposit_date');
    }
};
