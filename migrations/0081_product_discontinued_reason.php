<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Why a product was discontinued and who did it: managers may discontinue goods too, and
        // must say why. Cleared when the product comes back on sale.
        $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME IN ('discontinued_reason','discontinued_by')")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('discontinued_reason', $cols, true)) {
            $db->exec('ALTER TABLE products ADD COLUMN discontinued_reason VARCHAR(500) NULL');
        }
        if (!in_array('discontinued_by', $cols, true)) {
            $db->exec('ALTER TABLE products ADD COLUMN discontinued_by INT UNSIGNED NULL');
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE products DROP COLUMN discontinued_reason, DROP COLUMN discontinued_by');
    }
};
