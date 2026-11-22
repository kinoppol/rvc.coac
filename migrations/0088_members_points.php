<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        $has = fn (string $t, string $c) => (bool) $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' AND COLUMN_NAME = '{$c}'")->fetchColumn();

        // Store members are the students and staff of the RVC-ONE directory (oa_users): no sign-up.
        // RVC-ONE sends what it holds from RMS when this app is granted the field: national ID,
        // student code and phone numbers. A person is found at the POS by any of them.
        if (!$has('oa_users', 'national_id')) {
            $db->exec('ALTER TABLE oa_users ADD COLUMN national_id VARCHAR(13) NULL, ADD COLUMN student_code VARCHAR(20) NULL,
                ADD KEY idx_oa_users_national_id (national_id), ADD KEY idx_oa_users_student_code (student_code)');
        }
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS oa_user_phones (
                phone VARCHAR(15) NOT NULL,
                oa_user_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (phone, oa_user_id),
                KEY idx_oa_user_phones_user (oa_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // A member's points account, opened the first time they are named as the buyer.
        // national_id is the key; oa_user_id stands in while RVC-ONE has not sent the ID.
        // points = the balance; points held for a cart in progress are in member_point_holds.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS members (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                national_id VARCHAR(13) NULL,
                oa_user_id INT UNSIGNED NULL,
                points INT NOT NULL DEFAULT 0,
                welcome_given TINYINT(1) NOT NULL DEFAULT 0,
                first_purchase_at DATETIME NULL,
                last_purchase_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_members_national_id (national_id),
                UNIQUE KEY uq_members_oa_user (oa_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        // Every change of a balance. kind: welcome | earn | redeem | survey | adjust | refund
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS member_points (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                member_id INT UNSIGNED NOT NULL,
                kind VARCHAR(12) NOT NULL,
                points INT NOT NULL,
                balance_after INT NOT NULL,
                sale_id INT UNSIGNED NULL,
                survey_id INT UNSIGNED NULL,
                note VARCHAR(300) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                KEY idx_member_points_member (member_id, id),
                KEY idx_member_points_sale (sale_id),
                KEY idx_member_points_kind (kind, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        // Points set aside for one cart line ("ใช้แต้ม" at the POS) so they cannot be spent twice.
        // status: held → used (the sale went through) | released (taken off, cart cleared, expired)
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS member_point_holds (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                member_id INT UNSIGNED NOT NULL,
                redeem_id INT UNSIGNED NULL,
                product_id INT UNSIGNED NULL,
                sale_group_id INT UNSIGNED NULL,
                qty INT NOT NULL DEFAULT 1,
                points INT NOT NULL,
                discount DECIMAL(12,2) NOT NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'held',
                sale_id INT UNSIGNED NULL,
                device_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                closed_at DATETIME NULL,
                KEY idx_point_holds_member (member_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        // Goods a member may take points off: the price of each piece drops `baht` for every `points`
        // spent, at most max_discount baht a piece. A product or a whole sale group (migration 0084).
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS point_redeems (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                points INT NOT NULL DEFAULT 1,
                baht DECIMAL(12,2) NOT NULL DEFAULT 1,
                max_discount DECIMAL(12,2) NULL,
                starts_on DATETIME NULL,
                ends_on DATETIME NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                note VARCHAR(500) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS point_redeem_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                redeem_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NULL,
                sale_group_id INT UNSIGNED NULL,
                KEY idx_redeem_items_redeem (redeem_id),
                KEY idx_redeem_items_product (product_id),
                KEY idx_redeem_items_group (sale_group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // Short questionnaires that give extra points once answered (once per member).
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS surveys (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                description VARCHAR(1000) NULL,
                points INT NOT NULL DEFAULT 0,
                starts_on DATETIME NULL,
                ends_on DATETIME NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        // kind: choice (one of options) | multi (any of options) | rating (1–5) | text
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS survey_questions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                survey_id INT UNSIGNED NOT NULL,
                position INT NOT NULL DEFAULT 0,
                kind VARCHAR(10) NOT NULL DEFAULT 'choice',
                label VARCHAR(500) NOT NULL,
                options TEXT NULL,
                required TINYINT(1) NOT NULL DEFAULT 1,
                KEY idx_survey_questions_survey (survey_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS survey_responses (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                survey_id INT UNSIGNED NOT NULL,
                member_id INT UNSIGNED NOT NULL,
                answers MEDIUMTEXT NOT NULL,
                points INT NOT NULL DEFAULT 0,
                recorded_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_survey_member (survey_id, member_id),
                KEY idx_survey_responses_member (member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // The buyer of a sale (NULL = ลูกค้าทั่วไป, no points) and its points.
        if (!$has('sales', 'member_id')) {
            $db->exec('ALTER TABLE sales ADD COLUMN member_id INT UNSIGNED NULL, ADD COLUMN points_earned INT NOT NULL DEFAULT 0,
                ADD COLUMN points_used INT NOT NULL DEFAULT 0, ADD COLUMN points_discount DECIMAL(12,2) NOT NULL DEFAULT 0,
                ADD KEY idx_sales_member (member_id, sale_datetime)');
        }
        // What a line got off by points (unit_price is already the price after it, as with promo_discount).
        if (!$has('sale_items', 'points_discount')) {
            $db->exec('ALTER TABLE sale_items ADD COLUMN points_discount DECIMAL(12,2) NULL');
        }

        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['admin', 'manager', 'executive'] as $role) {
            $perm->execute([$role, 'members.view']);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key = 'members.view'");
        $db->exec('ALTER TABLE sale_items DROP COLUMN points_discount');
        $db->exec('ALTER TABLE sales DROP KEY idx_sales_member, DROP COLUMN member_id, DROP COLUMN points_earned, DROP COLUMN points_used, DROP COLUMN points_discount');
        foreach (['survey_responses', 'survey_questions', 'surveys', 'point_redeem_items', 'point_redeems', 'member_point_holds', 'member_points', 'members', 'oa_user_phones'] as $t) {
            $db->exec("DROP TABLE IF EXISTS {$t}");
        }
        $db->exec('ALTER TABLE oa_users DROP KEY idx_oa_users_national_id, DROP KEY idx_oa_users_student_code, DROP COLUMN national_id, DROP COLUMN student_code');
    }
};
