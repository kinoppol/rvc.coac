<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A consignor payout made from the daily report pays for chosen sale lines: each line
        // remembers the payout that paid it, so it is not offered again (until that payout is
        // cancelled). The payout keeps the sales period and what those sales came to.
        $db->exec('ALTER TABLE sale_items ADD COLUMN withdrawal_id INT UNSIGNED NULL, ADD KEY idx_sale_items_withdrawal (withdrawal_id)');
        $db->exec(
            'ALTER TABLE withdrawals
                ADD COLUMN sales_from DATE NULL,
                ADD COLUMN sales_to DATE NULL,
                ADD COLUMN sales_amount DECIMAL(12,2) NULL,
                ADD COLUMN note VARCHAR(255) NULL'
        );
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE sale_items DROP KEY idx_sale_items_withdrawal, DROP COLUMN withdrawal_id');
        $db->exec('ALTER TABLE withdrawals DROP COLUMN sales_from, DROP COLUMN sales_to, DROP COLUMN sales_amount, DROP COLUMN note');
    }
};
