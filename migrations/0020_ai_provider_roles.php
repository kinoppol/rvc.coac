<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Comma-separated roles allowed to use this provider's models; NULL = every role with ai.use.
        $db->exec('ALTER TABLE ai_providers ADD COLUMN allowed_roles VARCHAR(255) NULL AFTER enabled');
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE ai_providers DROP COLUMN allowed_roles');
    }
};
