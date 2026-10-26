<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // An admin may cancel a withdrawal with a reason. A paid one is put back into the
        // consignor's account by a 'withdrawal_cancel' ledger entry (the original stays).
        $db->exec('ALTER TABLE withdrawals
            ADD COLUMN cancelled_by INT UNSIGNED NULL AFTER paid_at,
            ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_by,
            ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_at');
        $db->exec("ALTER TABLE ledger_entries MODIFY type
            ENUM('open','sale','fee','listing_fee','listing_fee_refund','withdrawal','withdrawal_cancel') NOT NULL");
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM ledger_entries WHERE type = 'withdrawal_cancel'");
        $db->exec("ALTER TABLE ledger_entries MODIFY type
            ENUM('open','sale','fee','listing_fee','listing_fee_refund','withdrawal') NOT NULL");
        $db->exec('ALTER TABLE withdrawals DROP COLUMN cancel_reason, DROP COLUMN cancelled_at, DROP COLUMN cancelled_by');
    }
};
