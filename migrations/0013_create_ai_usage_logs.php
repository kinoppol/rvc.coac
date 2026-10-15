<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // One row per AI request, with the estimated charge frozen at the rate in effect.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS ai_usage_logs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                channel ENUM('chat','receipt_extract') NOT NULL DEFAULT 'chat',
                category ENUM('general','markdown','receipt') NOT NULL DEFAULT 'general',
                cost DECIMAL(10,2) NOT NULL DEFAULT 0,
                prompt TEXT NULL,
                model_row_id INT UNSIGNED NULL,
                model_name VARCHAR(191) NULL,
                provider_name VARCHAR(100) NULL,
                image_count INT NOT NULL DEFAULT 0,
                response_chars INT NOT NULL DEFAULT 0,
                tool_calls VARCHAR(255) NULL,
                status ENUM('ok','error') NOT NULL DEFAULT 'ok',
                error_message VARCHAR(500) NULL,
                duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                KEY idx_created (created_at),
                KEY idx_user_created (user_id, created_at),
                CONSTRAINT fk_aiusage_user FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $defaults = [
            'ai_cost_general' => '1',
            'ai_cost_markdown' => '3',
            'ai_cost_receipt' => '5',
            'ai_markdown_min_chars' => '1000',
            'ai_markdown_min_table_rows' => '10',
        ];
        $stmt = $db->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($defaults as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['admin', 'manager'] as $role) {
            $perm->execute([$role, 'ai.usage']);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key = 'ai.usage'");
        $db->exec("DELETE FROM settings WHERE setting_key IN ('ai_cost_general','ai_cost_markdown','ai_cost_receipt','ai_markdown_min_chars','ai_markdown_min_table_rows')");
        $db->exec('DROP TABLE IF EXISTS ai_usage_logs');
    }
};
