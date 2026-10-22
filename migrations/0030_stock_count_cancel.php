<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A count round that has not been approved can be cancelled; stock is left untouched.
        $db->exec(<<<SQL
            ALTER TABLE stock_counts
                MODIFY status ENUM('draft','submitted','approved','cancelled') NOT NULL DEFAULT 'draft',
                ADD COLUMN cancelled_by INT UNSIGNED NULL AFTER approved_at,
                ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_by
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM stock_counts WHERE status = 'cancelled'");
        $db->exec(<<<SQL
            ALTER TABLE stock_counts
                DROP COLUMN cancelled_at,
                DROP COLUMN cancelled_by,
                MODIFY status ENUM('draft','submitted','approved') NOT NULL DEFAULT 'draft'
        SQL);
    }
};
