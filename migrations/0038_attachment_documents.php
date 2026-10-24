<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Receipt evidence may also be a Markdown file (e.g. one imported through the
        // assistant), not only a photo; keep the name it was uploaded with.
        $db->exec('ALTER TABLE attachments ADD COLUMN original_name VARCHAR(191) NULL AFTER file_path');
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE attachments DROP COLUMN original_name');
    }
};
