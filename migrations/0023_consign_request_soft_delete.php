<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Deleted (cancelled) requests are hidden, restorable, and purged for good after 30 days.
        $db->exec('ALTER TABLE consign_requests ADD COLUMN deleted_at DATETIME NULL AFTER created_at, ADD KEY idx_creq_deleted (deleted_at)');
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE consign_requests DROP KEY idx_creq_deleted, DROP COLUMN deleted_at');
    }
};
