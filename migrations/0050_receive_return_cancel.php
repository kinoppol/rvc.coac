<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // An admin may cancel a receive document (nothing of it sold/returned yet) with a reason.
        // Its lots drop to zero stock and any listing fee comes back as a 'listing_fee_refund' entry.
        $db->exec('ALTER TABLE receive_documents
            ADD COLUMN cancelled_by INT UNSIGNED NULL AFTER created_at,
            ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_by,
            ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_at');
        // Likewise a return to a consignor: its goods go back into stock.
        $db->exec('ALTER TABLE return_documents
            ADD COLUMN cancelled_by INT UNSIGNED NULL AFTER created_at,
            ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_by,
            ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_at');
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE return_documents DROP COLUMN cancel_reason, DROP COLUMN cancelled_at, DROP COLUMN cancelled_by');
        $db->exec('ALTER TABLE receive_documents DROP COLUMN cancel_reason, DROP COLUMN cancelled_at, DROP COLUMN cancelled_by');
    }
};
