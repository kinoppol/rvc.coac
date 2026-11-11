<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A supplier is still named on each purchase as typed (supplier_name, kept as on the receipt).
        // The same supplier typed several ways is brought under one main name: the other spellings
        // become its aliases (ชื่อเล่น), so prices, orders and the name list treat them as one.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS suppliers (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(191) NOT NULL,
                tax_id VARCHAR(30) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_supplier_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS supplier_aliases (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NOT NULL,
                alias VARCHAR(191) NOT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_supplier_alias (alias),
                KEY idx_supplier_alias_supplier (supplier_id),
                CONSTRAINT fk_supplier_alias_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS supplier_aliases');
        $db->exec('DROP TABLE IF EXISTS suppliers');
    }
};
