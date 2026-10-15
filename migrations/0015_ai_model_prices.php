<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Provider list price (USD per 1M tokens) so "auto" mode can route each
        // request to the cheapest model that can handle it (photos vs. text).
        $db->exec(<<<SQL
            ALTER TABLE ai_models
                ADD COLUMN price_in DECIMAL(12,4) NULL AFTER supports_vision,
                ADD COLUMN price_out DECIMAL(12,4) NULL AFTER price_in
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE ai_models DROP COLUMN price_out, DROP COLUMN price_in');
    }
};
