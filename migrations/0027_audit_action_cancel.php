<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Cancelling a purchase or a POS import is logged as its own action.
        $db->exec("ALTER TABLE audit_logs MODIFY action ENUM('create','update','delete','cancel') NOT NULL");
    }

    public function down(PDO $db): void
    {
        $db->exec("UPDATE audit_logs SET action = 'update' WHERE action = 'cancel'");
        $db->exec("ALTER TABLE audit_logs MODIFY action ENUM('create','update','delete') NOT NULL");
    }
};
