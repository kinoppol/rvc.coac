<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Counting one product at a time with no round open (kind 'item'): each count is its own
        // one-line stock_counts record. It keeps how the count was done (where, how, who, when,
        // what the system said then) and, when it differs from the system, why. A difference of
        // more than StockCountService::ITEM_APPROVAL_OVER pieces waits (status 'submitted') for a
        // manager to certify it before the stock changes; the manager's note (or why it was
        // turned down) is kept with it.
        $db->exec(<<<SQL
            ALTER TABLE stock_counts
                ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'round' AFTER doc_no,
                ADD COLUMN review_note VARCHAR(255) NULL AFTER approved_at,
                ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_at,
                ADD KEY idx_sc_kind_status (kind, status)
        SQL);
        $db->exec(<<<SQL
            ALTER TABLE stock_count_items
                ADD COLUMN counted_by INT UNSIGNED NULL,
                ADD COLUMN counted_at DATETIME NULL,
                ADD COLUMN count_system_qty INT NULL,
                ADD COLUMN count_qty INT NULL,
                ADD COLUMN count_place VARCHAR(100) NULL,
                ADD COLUMN count_method VARCHAR(100) NULL,
                ADD COLUMN count_note VARCHAR(255) NULL,
                ADD COLUMN diff_reason VARCHAR(20) NULL,
                ADD COLUMN diff_detail VARCHAR(500) NULL
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec(<<<SQL
            ALTER TABLE stock_count_items
                DROP COLUMN diff_detail, DROP COLUMN diff_reason, DROP COLUMN count_note, DROP COLUMN count_method,
                DROP COLUMN count_place, DROP COLUMN count_qty, DROP COLUMN count_system_qty, DROP COLUMN counted_at, DROP COLUMN counted_by
        SQL);
        $db->exec("UPDATE stock_counts SET status = 'cancelled' WHERE kind = 'item' AND status = 'submitted'");
        $db->exec('ALTER TABLE stock_counts DROP KEY idx_sc_kind_status, DROP COLUMN cancel_reason, DROP COLUMN review_note, DROP COLUMN kind');
    }
};
