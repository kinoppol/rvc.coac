<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                table_name VARCHAR(100) NOT NULL,
                record_id INT UNSIGNED NOT NULL,
                action ENUM('create','update','delete') NOT NULL,
                old_data LONGTEXT NULL,
                new_data LONGTEXT NULL,
                user_id INT UNSIGNED NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_table_record (table_name, record_id),
                KEY idx_user (user_id),
                KEY idx_created (created_at),
                CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS audit_logs');
    }
};
