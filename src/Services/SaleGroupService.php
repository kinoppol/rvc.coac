<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Db;
use PDO;

/**
 * Sale groups (migration 0084): store goods with no barcode that look alike and cost the same are
 * bought in as separate products, but the POS sells them as one item. Each piece sold is taken from
 * the member with the most stock left (ties: the first one), so the members run down in turn until
 * all of them are out. A sale still records one line per member product, so stock, cost and the
 * stock card stay per product.
 */
class SaleGroupService
{
    public function __construct(private PDO $db, private AuditLogger $audit)
    {
    }

    public static function ready(PDO $db): bool
    {
        return Db::hasColumn($db, 'products', 'sale_group_id');
    }

    private function assertReady(): void
    {
        if (!self::ready($this->db)) {
            throw new \RuntimeException('ยังใช้กลุ่มขายหน้า POS ไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0084) ก่อน');
        }
    }

    /** Every group with its members (stock, cost, price), for managing them. */
    public function all(): array
    {
        if (!self::ready($this->db)) {
            return [];
        }
        $groups = $this->db->query('SELECT g.*, u.full_name AS creator FROM sale_groups g LEFT JOIN users u ON u.id = g.created_by ORDER BY g.name')->fetchAll();
        $members = $this->db->query(
            'SELECT id, name, barcode, sale_group_id, qty_stock, cost_price, sale_price, image_token FROM products
             WHERE sale_group_id IS NOT NULL ORDER BY name'
        )->fetchAll();
        $by = [];
        foreach ($members as $m) {
            $by[(int) $m['sale_group_id']][] = $m;
        }
        foreach ($groups as &$g) {
            $g['members'] = $by[(int) $g['id']] ?? [];
            $g['qty_stock'] = array_sum(array_map(fn ($m) => max(0, (int) $m['qty_stock']), $g['members']));
        }
        unset($g);
        return $groups;
    }

    /**
     * Products that may be grouped: store goods (not consigned), not in another group, all with
     * the same cost per piece.
     * @return array<int, array> locked product rows
     */
    private function lockMembers(array $productIds, ?int $groupId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$ids) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
        if (count($rows) !== count($ids)) {
            throw new \RuntimeException('ไม่พบสินค้าบางรายการ กรุณารีเฟรช');
        }
        foreach ($rows as $p) {
            if ($p['consignor_id'] !== null) {
                throw new \RuntimeException("\"{$p['name']}\" เป็นสินค้าฝากขาย จัดกลุ่มขายได้เฉพาะสินค้าของร้าน");
            }
            if ($p['sale_group_id'] !== null && (int) $p['sale_group_id'] !== $groupId) {
                throw new \RuntimeException("\"{$p['name']}\" อยู่ในกลุ่มขายอื่นแล้ว เอาออกจากกลุ่มนั้นก่อน");
            }
        }
        return $rows;
    }

    /** Members must cost the same per piece (to the satang). */
    private static function assertSameCost(array $rows): void
    {
        $costs = [];
        foreach ($rows as $p) {
            $costs[number_format((float) $p['cost_price'], 2, '.', '')][] = $p['name'];
        }
        if (count($costs) > 1) {
            $list = implode(' · ', array_map(fn ($c, $names) => '฿' . $c . ': ' . implode(', ', $names), array_keys($costs), $costs));
            throw new \RuntimeException('สินค้าในกลุ่มขายต้องมีต้นทุนต่อชิ้นเท่ากัน — ' . $list);
        }
    }

    private static function name(mixed $v): string
    {
        $name = mb_substr(trim((string) $v), 0, 200);
        if ($name === '') {
            throw new \RuntimeException('กรุณาตั้งชื่อกลุ่มที่แสดงหน้า POS');
        }
        return $name;
    }

    private static function price(mixed $v): float
    {
        if (!is_numeric($v) || (float) $v <= 0) {
            throw new \RuntimeException('ราคาขายของกลุ่มต้องมากกว่า 0');
        }
        return round((float) $v, 2);
    }

    /** POST /sale-groups {name, sale_price, product_ids (2+)} */
    public function create(array $in, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $name = self::name($in['name'] ?? '');
        $price = self::price($in['sale_price'] ?? null);
        $this->db->beginTransaction();
        try {
            $rows = $this->lockMembers((array) ($in['product_ids'] ?? []), null);
            if (count($rows) < 2) {
                throw new \RuntimeException('เลือกสินค้าอย่างน้อย 2 รายการเพื่อจัดกลุ่มขาย');
            }
            self::assertSameCost($rows);
            $this->db->prepare('INSERT INTO sale_groups (name, sale_price, created_by, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')
                ->execute([$name, $price, $userId]);
            $id = (int) $this->db->lastInsertId();
            $ids = array_map(fn ($p) => (int) $p['id'], $rows);
            $this->db->prepare('UPDATE products SET sale_group_id = ?, updated_at = NOW() WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
                ->execute([$id, ...$ids]);
            $this->audit->log('sale_groups', $id, 'create', null, ['name' => $name, 'sale_price' => $price, 'product_ids' => $ids], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['id' => $id];
    }

    /** PUT /sale-groups/{id} {name?, sale_price?, add?: product ids, remove?: product ids} */
    public function update(int $id, array $in, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM sale_groups WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $g = $stmt->fetch();
            if (!$g) {
                throw new \RuntimeException('ไม่พบกลุ่มขาย');
            }
            $name = array_key_exists('name', $in) ? self::name($in['name']) : $g['name'];
            $price = array_key_exists('sale_price', $in) ? self::price($in['sale_price']) : (float) $g['sale_price'];
            $remove = array_values(array_filter(array_map('intval', (array) ($in['remove'] ?? []))));
            $add = (array) ($in['add'] ?? []);

            $stmt = $this->db->prepare('SELECT * FROM products WHERE sale_group_id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $current = $stmt->fetchAll();
            $added = $this->lockMembers($add, $id);
            $keep = array_filter($current, fn ($p) => !in_array((int) $p['id'], $remove, true));
            $after = $keep;
            foreach ($added as $p) {
                if (!in_array((int) $p['id'], array_map(fn ($x) => (int) $x['id'], $after), true)) {
                    $after[] = $p;
                }
            }
            if (count($after) < 2) {
                throw new \RuntimeException('กลุ่มขายต้องมีสินค้าอย่างน้อย 2 รายการ — ถ้าไม่ใช้แล้วให้ยกเลิกกลุ่มแทน');
            }
            if ($added) {
                self::assertSameCost($after);
            }
            $this->db->prepare('UPDATE sale_groups SET name = ?, sale_price = ?, updated_at = NOW() WHERE id = ?')->execute([$name, $price, $id]);
            if ($remove) {
                $this->db->prepare('UPDATE products SET sale_group_id = NULL, updated_at = NOW() WHERE sale_group_id = ? AND id IN (' . implode(',', array_fill(0, count($remove), '?')) . ')')
                    ->execute([$id, ...$remove]);
            }
            $addIds = array_map(fn ($p) => (int) $p['id'], $added);
            if ($addIds) {
                $this->db->prepare('UPDATE products SET sale_group_id = ?, updated_at = NOW() WHERE id IN (' . implode(',', array_fill(0, count($addIds), '?')) . ')')
                    ->execute([$id, ...$addIds]);
            }
            $this->audit->log('sale_groups', $id, 'update',
                ['name' => $g['name'], 'sale_price' => (float) $g['sale_price'], 'product_ids' => array_map(fn ($p) => (int) $p['id'], $current)],
                ['name' => $name, 'sale_price' => $price, 'product_ids' => array_values(array_map(fn ($p) => (int) $p['id'], $after))], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['id' => $id];
    }

    /** POST /sale-groups/{id}/delete — the members go back to being sold one by one. */
    public function delete(int $id, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM sale_groups WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $g = $stmt->fetch();
            if (!$g) {
                throw new \RuntimeException('ไม่พบกลุ่มขาย');
            }
            $this->db->prepare('UPDATE products SET sale_group_id = NULL, updated_at = NOW() WHERE sale_group_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM sale_groups WHERE id = ?')->execute([$id]);
            $this->audit->log('sale_groups', $id, 'delete', ['name' => $g['name'], 'sale_price' => (float) $g['sale_price']], null, $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['id' => $id];
    }

    /**
     * The POS items of groups whose name matches $q (all groups when $q is ''), shaped like the
     * POS product rows: id = -group id, qty_stock = the members' stock (none counted below zero).
     */
    public function posItems(string $q, bool $withEmpty): array
    {
        if (!self::ready($this->db)) {
            return [];
        }
        $where = $q === '' ? '1 = 1' : 'g.name LIKE ?';
        $stmt = $this->db->prepare(
            "SELECT g.id, g.name, g.sale_price,
                    SUM(GREATEST(p.qty_stock, 0)) AS qty_stock, COUNT(p.id) AS members,
                    SUBSTRING_INDEX(GROUP_CONCAT(IF(p.image_token IS NULL, NULL, CONCAT(p.id, ':', p.image_token)) ORDER BY p.qty_stock DESC), ',', 1) AS photo,
                    (SELECT COALESCE(SUM(si.qty), 0) FROM sale_items si JOIN sales sa ON sa.id = si.sale_id JOIN products x ON x.id = si.product_id
                      WHERE x.sale_group_id = g.id AND sa.sale_datetime >= NOW() - INTERVAL 90 DAY) AS sold_qty
             FROM sale_groups g JOIN products p ON p.sale_group_id = g.id" . (Db::hasColumn($this->db, 'products', 'discontinued_at') ? ' AND p.discontinued_at IS NULL' : '') . "
             WHERE {$where}
             GROUP BY g.id, g.name, g.sale_price"
        );
        $stmt->execute($q === '' ? [] : ['%' . $q . '%']);
        $out = [];
        foreach ($stmt->fetchAll() as $g) {
            if (!$withEmpty && (int) $g['qty_stock'] < 1) {
                continue;
            }
            [$photoId, $token] = $g['photo'] ? explode(':', (string) $g['photo'], 2) : [null, null];
            $out[] = [
                'id' => -(int) $g['id'], 'group_id' => (int) $g['id'], 'is_group' => true, 'members' => (int) $g['members'],
                'name' => $g['name'], 'barcode' => null, 'sale_price' => (float) $g['sale_price'], 'qty_stock' => (int) $g['qty_stock'],
                'image_token' => $token, 'photo_id' => $photoId !== null ? (int) $photoId : null, 'has_thumb' => true,
                'consignor_id' => null, 'consignor_name' => 'สินค้าของร้าน', 'sold_qty' => (int) $g['sold_qty'],
                'discontinued' => false, 'packs' => [],
            ];
        }
        return $out;
    }

    /**
     * Sale lines with group_id become one line per member product: each piece goes to the member
     * with the most stock left (ties: the lower id), so the members run down in turn. Without
     * $allowShort the group must hold enough pieces; with it, pieces past what is held still go
     * to the member with the most left (stock below zero, spread over the members).
     * Call inside the sale's transaction: the members are locked.
     */
    public function expand(array $items, bool $allowShort): array
    {
        $out = [];
        foreach ($items as $line) {
            $gid = (int) ($line['group_id'] ?? 0);
            if ($gid <= 0) {
                $out[] = $line;
                continue;
            }
            $this->assertReady();
            $stmt = $this->db->prepare('SELECT * FROM sale_groups WHERE id = ?');
            $stmt->execute([$gid]);
            $g = $stmt->fetch();
            if (!$g) {
                throw new \RuntimeException('ไม่พบกลุ่มสินค้านี้ (อาจถูกยกเลิกกลุ่มแล้ว) กรุณารีเฟรชหน้าขาย');
            }
            $disc = Db::hasColumn($this->db, 'products', 'discontinued_at') ? ' AND discontinued_at IS NULL' : '';
            $stmt = $this->db->prepare("SELECT id, name, qty_stock FROM products WHERE sale_group_id = ?{$disc} ORDER BY id FOR UPDATE");
            $stmt->execute([$gid]);
            $members = $stmt->fetchAll();
            if (!$members) {
                throw new \RuntimeException("กลุ่ม \"{$g['name']}\" ไม่มีสินค้าที่ขายได้");
            }
            $qty = (int) ($line['qty'] ?? 0);
            if ($qty <= 0) {
                throw new \RuntimeException('จำนวนขายไม่ถูกต้อง');
            }
            // What this sale already takes from the members (the same group twice in one cart).
            $left = [];
            foreach ($members as $m) {
                $taken = 0;
                foreach ($out as $o) {
                    if ((int) ($o['product_id'] ?? 0) === (int) $m['id']) {
                        $taken += (int) $o['qty'] * max(1, (int) ($o['pack_size'] ?? 1));
                    }
                }
                $left[(int) $m['id']] = (int) $m['qty_stock'] - $taken;
            }
            $held = array_sum(array_map(fn ($n) => max(0, $n), $left));
            if ($qty > $held && !$allowShort) {
                throw new \RuntimeException("จำนวนขายเกินสต๊อกคงเหลือของ {$g['name']} (เหลือรวม {$held} ชิ้น)");
            }
            $take = [];
            for ($i = 0; $i < $qty; $i++) {
                $best = null;
                foreach ($left as $pid => $n) {
                    if ($best === null || $n > $left[$best]) {
                        $best = $pid;
                    }
                }
                $left[$best]--;
                $take[$best] = ($take[$best] ?? 0) + 1;
            }
            foreach ($take as $pid => $n) {
                $out[] = ['product_id' => $pid, 'qty' => $n, 'unit_price' => (float) $g['sale_price'], 'group_id' => $gid];
            }
        }
        return $out;
    }
}
