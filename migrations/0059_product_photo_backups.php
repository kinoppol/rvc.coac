<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Older photos of a product, kept when duplicates are merged (the latest photo goes on,
        // the others land here) or a new photo replaces the current one. Nothing is deleted:
        // a backup can be put back as the product's photo.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS product_photo_backups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                image_token CHAR(32) NOT NULL,
                from_product_id INT UNSIGNED NULL,
                note VARCHAR(255) NULL,
                user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_photo_backup (product_id, image_token),
                KEY idx_photo_backup_token (image_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS product_photo_backups');
    }
};
