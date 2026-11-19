<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Month-end closing: the month's figures as they were when it was closed (income, expenses,
        // losses and mistakes, money over / short, stock lost or found in counts) and the store goods
        // left at cost, carried to the next month as its opening stock. Records stay editable; the
        // page warns when the figures now differ from the snapshot.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS monthly_closings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                month CHAR(7) NOT NULL UNIQUE,
                figures MEDIUMTEXT NOT NULL,
                opening_stock_value DECIMAL(14,2) NOT NULL DEFAULT 0,
                closing_stock_value DECIMAL(14,2) NOT NULL DEFAULT 0,
                net_profit DECIMAL(14,2) NOT NULL DEFAULT 0,
                note VARCHAR(500) NULL,
                closed_by INT UNSIGNED NOT NULL,
                closed_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS monthly_closings');
    }
};
