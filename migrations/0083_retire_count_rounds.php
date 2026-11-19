<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Counting the whole store in open / closed rounds (report, approval) was removed: stock is
        // counted one product at a time. Rounds still open (draft or waiting for approval) are closed
        // as cancelled — the stock is not changed by them. Item counts (kind 'item') are not touched.
        $has = fn (string $col) => (bool) $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_counts' AND COLUMN_NAME = '{$col}'")->fetchColumn();
        $set = "status = 'cancelled', cancelled_at = NOW()";
        if ($has('cancel_reason')) {
            $set .= ", cancel_reason = 'เลิกใช้การตรวจนับแบบรอบ: ปิดรอบโดยไม่ปรับสต๊อก'";
        }
        $where = "status IN ('draft', 'submitted')" . ($has('kind') ? " AND kind <> 'item'" : '');
        $db->exec("UPDATE stock_counts SET {$set} WHERE {$where}");
    }

    public function down(PDO $db): void
    {
        // The rounds closed here cannot be told apart reliably; nothing to undo.
    }
};
