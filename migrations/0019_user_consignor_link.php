<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A user can also be a consignor (their own passbook/products). Role
        // 'consignor' is for people who are only consignors, with no staff role.
        $db->exec("ALTER TABLE users MODIFY role ENUM('admin','manager','advisor','cashier','consignor') NOT NULL DEFAULT 'cashier'");
        $db->exec(<<<SQL
            ALTER TABLE consignors
                ADD COLUMN user_id INT UNSIGNED NULL AFTER id,
                ADD UNIQUE KEY uq_consignors_user_id (user_id)
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE consignors DROP KEY uq_consignors_user_id, DROP COLUMN user_id');
        $db->exec("UPDATE users SET role = 'cashier', active = 0 WHERE role = 'consignor'");
        $db->exec("ALTER TABLE users MODIFY role ENUM('admin','manager','advisor','cashier') NOT NULL DEFAULT 'cashier'");
    }
};
