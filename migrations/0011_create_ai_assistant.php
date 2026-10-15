<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS ai_providers (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                preset VARCHAR(40) NOT NULL DEFAULT 'custom',
                base_url VARCHAR(255) NOT NULL,
                api_key_enc TEXT NULL,
                extra_headers TEXT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS ai_models (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                provider_id INT UNSIGNED NOT NULL,
                model_id VARCHAR(191) NOT NULL,
                display_name VARCHAR(191) NULL,
                supports_tools TINYINT(1) NOT NULL DEFAULT 1,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_provider_model (provider_id, model_id),
                CONSTRAINT fk_ai_models_provider FOREIGN KEY (provider_id) REFERENCES ai_providers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // ai.use: open the floating assistant; ai.manage: configure providers/models.
        $stmt = $db->prepare(
            'INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)'
        );
        foreach (['admin', 'manager', 'advisor', 'cashier'] as $role) {
            $stmt->execute([$role, 'ai.use']);
        }
        $stmt->execute(['admin', 'ai.manage']);
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key IN ('ai.use','ai.manage')");
        $db->exec('DROP TABLE IF EXISTS ai_models');
        $db->exec('DROP TABLE IF EXISTS ai_providers');
    }
};
