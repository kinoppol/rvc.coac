<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // The accounting day of a sale. A sale made after the store has closed
        // (or on a closed day) counts toward the next opening day, so daily sales
        // summaries stay closed; sale_datetime keeps the real time, which is what
        // stock counts are checked against, and after_hours marks such sales.
        $db->exec(<<<SQL
            ALTER TABLE sales
                ADD COLUMN business_date DATE NULL AFTER sale_datetime,
                ADD COLUMN after_hours TINYINT(1) NOT NULL DEFAULT 0 AFTER business_date
        SQL);
        $db->exec('UPDATE sales SET business_date = DATE(sale_datetime)');
        $db->exec(<<<SQL
            ALTER TABLE sales
                MODIFY business_date DATE NOT NULL,
                ADD KEY idx_business_date (business_date)
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE sales DROP KEY idx_business_date, DROP COLUMN after_hours, DROP COLUMN business_date');
    }
};
