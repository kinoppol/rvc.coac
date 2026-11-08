<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // An admin's correction of a consignor's balance, with its reason: no money moves.
        $db->exec("ALTER TABLE ledger_entries MODIFY type
            ENUM('open','sale','fee','listing_fee','listing_fee_refund','withdrawal','withdrawal_cancel','adjust') NOT NULL");
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM ledger_entries WHERE type = 'adjust'");
        $db->exec("ALTER TABLE ledger_entries MODIFY type
            ENUM('open','sale','fee','listing_fee','listing_fee_refund','withdrawal','withdrawal_cancel') NOT NULL");
    }
};
