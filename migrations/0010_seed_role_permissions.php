<?php

declare(strict_types=1);


return new class {
    private function matrix(): array
    {
        $all = [
            'dashboard.view', 'consignors.view', 'consignors.create',
            'receive.create', 'return.create', 'passbook.view',
            'withdraw.create', 'withdraw.approve',
            'stockcount.create', 'stockcount.submit', 'stockcount.approve',
            'reports.view', 'reports.export',
            'settings.view', 'settings.edit',
            'migrations.manage', 'audit.view', 'sales.create',
        ];

        return [
            'admin' => $all,
            'manager' => [
                'dashboard.view', 'consignors.view', 'consignors.create',
                'receive.create', 'return.create', 'passbook.view',
                'withdraw.create', 'withdraw.approve',
                'stockcount.create', 'stockcount.submit', 'stockcount.approve',
                'reports.view', 'reports.export', 'sales.create',
            ],
            'advisor' => [
                'dashboard.view', 'reports.view', 'passbook.view',
            ],
            'cashier' => [
                'dashboard.view', 'consignors.view', 'consignors.create',
                'receive.create', 'return.create', 'passbook.view',
                'withdraw.create', 'stockcount.create', 'stockcount.submit',
                'sales.create',
            ],
        ];
    }

    public function up(PDO $db): void
    {
        $stmt = $db->prepare(
            'INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)'
        );
        foreach ($this->matrix() as $role => $perms) {
            foreach ($perms as $perm) {
                $stmt->execute([$role, $perm]);
            }
        }
    }

    public function down(PDO $db): void
    {
        $roles = array_keys($this->matrix());
        $in = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $db->prepare("DELETE FROM role_permissions WHERE role IN ($in)");
        $stmt->execute($roles);
    }
};
