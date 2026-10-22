<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Recording a receipt on an earlier date (goods taken in while the system
        // was offline): admin and store manager only, not cashiers.
        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['admin', 'manager'] as $role) {
            $perm->execute([$role, 'receive.backdate']);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key = 'receive.backdate'");
    }
};
