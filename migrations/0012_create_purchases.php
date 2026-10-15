<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // Store-owned goods live in `products` with consignor_id = NULL, so they
        // share POS, stock counts and barcode lookup with consignment goods.
        $db->exec('ALTER TABLE products MODIFY consignor_id INT UNSIGNED NULL');
        $db->exec('ALTER TABLE sale_items MODIFY consignor_id INT UNSIGNED NULL');

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS purchase_documents (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                doc_no VARCHAR(30) NOT NULL UNIQUE,
                supplier_name VARCHAR(191) NOT NULL,
                supplier_tax_id VARCHAR(20) NULL,
                receipt_no VARCHAR(60) NULL,
                receipt_date DATE NULL,
                subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
                discount DECIMAL(12,2) NOT NULL DEFAULT 0,
                vat DECIMAL(12,2) NOT NULL DEFAULT 0,
                total DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_qty INT NOT NULL DEFAULT 0,
                note VARCHAR(255) NULL,
                source ENUM('manual','ai') NOT NULL DEFAULT 'manual',
                user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_receipt_date (receipt_date),
                KEY idx_supplier (supplier_name),
                CONSTRAINT fk_purchase_user FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS purchase_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                purchase_document_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                receipt_name VARCHAR(191) NOT NULL,
                qty INT NOT NULL,
                unit_cost DECIMAL(10,2) NOT NULL,
                line_total DECIMAL(12,2) NOT NULL,
                sale_price DECIMAL(10,2) NOT NULL,
                is_new_product TINYINT(1) NOT NULL DEFAULT 1,
                KEY idx_doc (purchase_document_id),
                KEY idx_product (product_id),
                CONSTRAINT fk_puritem_doc FOREIGN KEY (purchase_document_id) REFERENCES purchase_documents(id) ON DELETE CASCADE,
                CONSTRAINT fk_puritem_product FOREIGN KEY (product_id) REFERENCES products(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // Uploaded images (receipt photos). Linked to a purchase once it is saved.
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS attachments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                token CHAR(32) NOT NULL UNIQUE,
                file_path VARCHAR(255) NOT NULL,
                mime VARCHAR(50) NOT NULL,
                size_bytes INT UNSIGNED NOT NULL,
                width INT UNSIGNED NULL,
                height INT UNSIGNED NULL,
                purchase_document_id INT UNSIGNED NULL,
                sort_order INT NOT NULL DEFAULT 0,
                uploaded_by INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_purchase (purchase_document_id),
                CONSTRAINT fk_att_purchase FOREIGN KEY (purchase_document_id) REFERENCES purchase_documents(id) ON DELETE SET NULL,
                CONSTRAINT fk_att_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $db->exec('ALTER TABLE ai_models ADD COLUMN supports_vision TINYINT(1) NOT NULL DEFAULT 0 AFTER supports_tools');

        $stmt = $db->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)');
        $stmt->execute(['purchase_markup_percent', '20']);

        $perm = $db->prepare('INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES (?, ?, 1)');
        foreach (['admin', 'manager'] as $role) {
            $perm->execute([$role, 'purchase.create']);
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM role_permissions WHERE permission_key = 'purchase.create'");
        $db->exec("DELETE FROM settings WHERE setting_key = 'purchase_markup_percent'");
        $db->exec('ALTER TABLE ai_models DROP COLUMN supports_vision');
        $db->exec('DROP TABLE IF EXISTS attachments');
        $db->exec('DROP TABLE IF EXISTS purchase_items');
        $db->exec('DROP TABLE IF EXISTS purchase_documents');
        // Store-owned products/sales have no consignor; they must be removed
        // before consignor_id can be made NOT NULL again.
        $db->exec('DELETE FROM sale_items WHERE consignor_id IS NULL');
        $db->exec('ALTER TABLE sale_items MODIFY consignor_id INT UNSIGNED NOT NULL');
        $db->exec('DELETE FROM products WHERE consignor_id IS NULL');
        $db->exec('ALTER TABLE products MODIFY consignor_id INT UNSIGNED NOT NULL');
    }
};
