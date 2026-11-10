<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A sale whose payment method was changed after it was saved: by the cashier within a few
        // minutes (a wrong button), or by an admin. payment_original = how it was paid first.
        $cols = fn (string $c) => (bool) $db->query("SHOW COLUMNS FROM sales LIKE '{$c}'")->fetch();
        if (!$cols('payment_edited_at')) {
            $db->exec('ALTER TABLE sales ADD COLUMN payment_edited_at DATETIME NULL');
        }
        if (!$cols('payment_edited_by')) {
            $db->exec('ALTER TABLE sales ADD COLUMN payment_edited_by INT UNSIGNED NULL');
        }
        if (!$cols('payment_original')) {
            $db->exec('ALTER TABLE sales ADD COLUMN payment_original VARCHAR(255) NULL');
        }
    }

    public function down(PDO $db): void
    {
        foreach (['payment_original', 'payment_edited_by', 'payment_edited_at'] as $c) {
            if ($db->query("SHOW COLUMNS FROM sales LIKE '{$c}'")->fetch()) {
                $db->exec("ALTER TABLE sales DROP COLUMN {$c}");
            }
        }
    }
};
