<?php

declare(strict_types=1);


return new class {
    /**
     * ผู้บริหาร: sees every report and record of the store but changes nothing. Some pages are
     * read through a permission that also allows changes (e.g. the wage list needs wages.manage);
     * ReadOnlyGuard turns away every change made under this role, whatever its permissions.
     */
    private const PERMS = [
        'dashboard.view', 'reports.view', 'reports.export', 'consignors.view', 'passbook.view',
        'closing.submit', 'wages.manage', 'capital.manage', 'purchase.create', 'withdraw.create',
        'sales.create', 'audit.view',
    ];

    public function up(PDO $db): void
    {
        $db->exec("ALTER TABLE users MODIFY role ENUM('admin','manager','advisor','cashier','consignor','closer','executive') NOT NULL DEFAULT 'cashier'");
        $db->exec("ALTER TABLE role_permissions MODIFY role ENUM('admin','manager','advisor','cashier','consignor','closer','executive') NOT NULL");
        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (self::PERMS as $key) {
            $perm->execute(['executive', $key]);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE role = 'executive'");
        $db->exec("UPDATE users SET role = 'advisor' WHERE role = 'executive'");
        $db->exec("ALTER TABLE role_permissions MODIFY role ENUM('admin','manager','advisor','cashier','consignor','closer') NOT NULL");
        $db->exec("ALTER TABLE users MODIFY role ENUM('admin','manager','advisor','cashier','consignor','closer') NOT NULL DEFAULT 'cashier'");
    }
};
