<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS receive_documents (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                doc_no VARCHAR(30) NOT NULL UNIQUE,
                consignor_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                total_qty INT NOT NULL DEFAULT 0,
                total_value DECIMAL(12,2) NOT NULL DEFAULT 0,
                listing_fee_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                waived_count INT NOT NULL DEFAULT 0,
                waived_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_consignor (consignor_id),
                CONSTRAINT fk_recv_consignor FOREIGN KEY (consignor_id) REFERENCES consignors(id),
                CONSTRAINT fk_recv_user FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS receive_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                receive_document_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                cost_price DECIMAL(10,2) NOT NULL,
                sale_price DECIMAL(10,2) NOT NULL,
                fee_per_unit DECIMAL(10,2) NOT NULL,
                qty INT NOT NULL,
                listing_fee_charged DECIMAL(10,2) NOT NULL DEFAULT 0,
                is_new_listing TINYINT(1) NOT NULL DEFAULT 1,
                KEY idx_doc (receive_document_id),
                KEY idx_product (product_id),
                CONSTRAINT fk_recvitem_doc FOREIGN KEY (receive_document_id) REFERENCES receive_documents(id) ON DELETE CASCADE,
                CONSTRAINT fk_recvitem_product FOREIGN KEY (product_id) REFERENCES products(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS return_documents (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                doc_no VARCHAR(30) NOT NULL UNIQUE,
                consignor_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                recipient_name VARCHAR(150) NULL,
                total_qty INT NOT NULL DEFAULT 0,
                total_value DECIMAL(12,2) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_consignor (consignor_id),
                CONSTRAINT fk_ret_consignor FOREIGN KEY (consignor_id) REFERENCES consignors(id),
                CONSTRAINT fk_ret_user FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS return_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                return_document_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                qty INT NOT NULL,
                sale_price DECIMAL(10,2) NOT NULL,
                KEY idx_doc (return_document_id),
                KEY idx_product (product_id),
                CONSTRAINT fk_retitem_doc FOREIGN KEY (return_document_id) REFERENCES return_documents(id) ON DELETE CASCADE,
                CONSTRAINT fk_retitem_product FOREIGN KEY (product_id) REFERENCES products(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS return_items');
        $db->exec('DROP TABLE IF EXISTS return_documents');
        $db->exec('DROP TABLE IF EXISTS receive_items');
        $db->exec('DROP TABLE IF EXISTS receive_documents');
    }
};
