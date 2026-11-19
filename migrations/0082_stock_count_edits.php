<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // An admin or a manager may correct what was counted on an approved count line (a wrong
        // number typed): who changed it, when, why, and what was counted at first.
        $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_count_items'
                            AND COLUMN_NAME IN ('original_counted_qty','edited_by','edited_at','edit_reason')")->fetchAll(PDO::FETCH_COLUMN);
        $add = [];
        foreach ([
            'original_counted_qty' => 'INT NULL',
            'edited_by' => 'INT UNSIGNED NULL',
            'edited_at' => 'DATETIME NULL',
            'edit_reason' => 'VARCHAR(255) NULL',
        ] as $col => $type) {
            if (!in_array($col, $cols, true)) {
                $add[] = "ADD COLUMN {$col} {$type}";
            }
        }
        if ($add) {
            $db->exec('ALTER TABLE stock_count_items ' . implode(', ', $add));
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE stock_count_items DROP COLUMN edit_reason, DROP COLUMN edited_at, DROP COLUMN edited_by, DROP COLUMN original_counted_qty');
    }
};
