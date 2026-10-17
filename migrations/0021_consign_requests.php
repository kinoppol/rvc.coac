<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A consignor lists what they plan to bring; staff check the goods and
        // turn it into a receive document (possibly with corrections).
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS consign_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                request_no VARCHAR(30) NOT NULL UNIQUE,
                consignor_id INT UNSIGNED NOT NULL,
                created_by INT UNSIGNED NOT NULL,
                status ENUM('pending','received','rejected','cancelled') NOT NULL DEFAULT 'pending',
                note VARCHAR(500) NULL,
                review_note VARCHAR(500) NULL,
                receive_document_id INT UNSIGNED NULL,
                reviewed_by INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_creq_status (status, id),
                KEY idx_creq_consignor (consignor_id),
                CONSTRAINT fk_creq_consignor FOREIGN KEY (consignor_id) REFERENCES consignors(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS consign_request_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                request_id INT UNSIGNED NOT NULL,
                name VARCHAR(191) NOT NULL,
                cost_price DECIMAL(10,2) NOT NULL,
                qty INT NOT NULL,
                barcode VARCHAR(64) NULL,
                expiry_date DATE NULL,
                KEY idx_creqitem_req (request_id),
                CONSTRAINT fk_creqitem_req FOREIGN KEY (request_id) REFERENCES consign_requests(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS consign_request_items');
        $db->exec('DROP TABLE IF EXISTS consign_requests');
    }
};
