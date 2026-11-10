<?php

declare(strict_types=1);


return new class {
    public function up(PDO $db): void
    {
        // A manufacturer's promotion: one purchase round sells at a lower price.
        // on_promo = sale_price is that promotion price now; regular_price = the normal price
        // meanwhile (NULL when not on promotion: sale_price is the normal price then).
        // promo_price = the latest promotion price, offered again on the next purchase.
        $db->exec(<<<SQL
            ALTER TABLE products
                ADD COLUMN on_promo TINYINT(1) NOT NULL DEFAULT 0 AFTER sale_price,
                ADD COLUMN regular_price DECIMAL(10,2) NULL AFTER on_promo,
                ADD COLUMN promo_price DECIMAL(10,2) NULL AFTER regular_price
        SQL);
        // Which purchase lines were bought for a promotion, and the normal price at the time.
        $db->exec(<<<SQL
            ALTER TABLE purchase_items
                ADD COLUMN is_promo TINYINT(1) NOT NULL DEFAULT 0 AFTER sale_price,
                ADD COLUMN regular_price DECIMAL(10,2) NULL AFTER is_promo
        SQL);
    }

    public function down(PDO $db): void
    {
        // Products on promotion go back to their normal price.
        $db->exec('UPDATE products SET sale_price = regular_price WHERE on_promo = 1 AND regular_price IS NOT NULL');
        $db->exec('ALTER TABLE purchase_items DROP COLUMN regular_price, DROP COLUMN is_promo');
        $db->exec('ALTER TABLE products DROP COLUMN promo_price, DROP COLUMN regular_price, DROP COLUMN on_promo');
    }
};
