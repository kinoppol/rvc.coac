<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A manager or admin correcting a cashier's sale themself (no request, no waiting): kept in
        // sale_edit_requests as an approved row with direct = 1, so the reason, who did it, and the
        // sale before / after stay on record next to the cashiers' own requests.
        if (!$db->query("SHOW COLUMNS FROM sale_edit_requests LIKE 'direct'")->fetch()) {
            $db->exec('ALTER TABLE sale_edit_requests ADD COLUMN direct TINYINT(1) NOT NULL DEFAULT 0');
            $db->exec('ALTER TABLE sale_edit_requests ADD KEY idx_sale_edit_direct (direct, requested_at)');
        }
    }

    public function down(PDO $db): void
    {
        if ($db->query("SHOW COLUMNS FROM sale_edit_requests LIKE 'direct'")->fetch()) {
            $db->exec('ALTER TABLE sale_edit_requests DROP KEY idx_sale_edit_direct');
            $db->exec('ALTER TABLE sale_edit_requests DROP COLUMN direct');
        }
    }
};
