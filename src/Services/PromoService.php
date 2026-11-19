<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Db;
use PDO;

/**
 * Bundle promotions (migration 0086): goods bought together sell for less. A promotion has slots;
 * each slot needs a number of pieces taken from any of its items, an item being one product or a
 * whole sale group (any of its members). "Any drink + any snack for ฿25" is two slots of several
 * items each; "2 pieces of the same snack for ฿15" is one slot of 2 pieces.
 *
 * kind: price = each full set sells for `value`; amount = `value` baht off each set;
 * percent = `value` % off each set.
 *
 * Only the web POS applies them (it asks for a quote while the cart changes and the sale is
 * recorded with the same figures); the Android app and imported sales are left as they were.
 */
class PromoService
{
    public const KINDS = ['price' => 'ราคาชุด', 'amount' => 'ลดบาทต่อชุด', 'percent' => 'ลด % ต่อชุด'];

    public function __construct(private PDO $db, private ?AuditLogger $audit = null)
    {
    }

    public static function ready(PDO $db): bool
    {
        return Db::hasColumn($db, 'sales', 'promo_discount');
    }

    /** Whether a promotion's start / end carry a time of day (migration 0087); before it, days only. */
    private function timed(): bool
    {
        static $timed = null;
        if ($timed === null) {
            $stmt = $this->db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promo_bundles' AND COLUMN_NAME = 'ends_on'");
            $timed = strtolower((string) $stmt->fetchColumn()) === 'datetime';
        }
        return $timed;
    }

    private function assertReady(): void
    {
        if (!self::ready($this->db)) {
            throw new \RuntimeException('ยังใช้โปรโมชั่นจับคู่สินค้าไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0086) ก่อน');
        }
    }

    // ---------- Managing promotions ----------

    /** Every promotion with its slots and items (names, prices, stock), newest first. */
    public function all(): array
    {
        if (!self::ready($this->db)) {
            return [];
        }
        $promos = $this->db->query(
            'SELECT b.*, u.full_name AS creator FROM promo_bundles b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.active DESC, b.id DESC'
        )->fetchAll();
        if (!$promos) {
            return [];
        }
        $slots = $this->db->query('SELECT * FROM promo_bundle_slots ORDER BY promo_id, position, id')->fetchAll();
        $items = $this->db->query(
            'SELECT i.slot_id, i.product_id, i.sale_group_id,
                    COALESCE(p.name, g.name) AS name, COALESCE(p.sale_price, g.sale_price) AS sale_price,
                    p.barcode, p.consignor_id, p.qty_stock,
                    (SELECT COUNT(*) FROM products m WHERE g.id IS NOT NULL AND m.sale_group_id = g.id) AS members,
                    (SELECT COALESCE(SUM(GREATEST(m.qty_stock, 0)), 0) FROM products m WHERE g.id IS NOT NULL AND m.sale_group_id = g.id) AS group_stock
             FROM promo_bundle_items i
             LEFT JOIN products p ON p.id = i.product_id
             LEFT JOIN sale_groups g ON g.id = i.sale_group_id
             ORDER BY i.id'
        )->fetchAll();
        $bySlot = [];
        foreach ($items as $it) {
            $isGroup = $it['sale_group_id'] !== null;
            $bySlot[(int) $it['slot_id']][] = [
                'product_id' => $isGroup ? null : (int) $it['product_id'],
                'sale_group_id' => $isGroup ? (int) $it['sale_group_id'] : null,
                'name' => $it['name'] ?? '(ไม่พบสินค้า — ถูกลบแล้ว)',
                'sale_price' => $it['sale_price'] !== null ? (float) $it['sale_price'] : null,
                'barcode' => $it['barcode'],
                'consigned' => !$isGroup && $it['consignor_id'] !== null,
                'qty_stock' => $isGroup ? (int) $it['group_stock'] : (int) $it['qty_stock'],
                'members' => $isGroup ? (int) $it['members'] : null,
            ];
        }
        $byPromo = [];
        foreach ($slots as $s) {
            $byPromo[(int) $s['promo_id']][] = [
                'id' => (int) $s['id'], 'label' => $s['label'], 'qty' => (int) $s['qty'], 'items' => $bySlot[(int) $s['id']] ?? [],
            ];
        }
        $now = date($this->timed() ? 'Y-m-d H:i:s' : 'Y-m-d');
        $used = $this->usage();
        foreach ($promos as &$p) {
            $p['id'] = (int) $p['id'];
            $p['value'] = (float) $p['value'];
            $p['active'] = (bool) $p['active'];
            $p['slots'] = $byPromo[$p['id']] ?? [];
            // The regular price of one set at its dearest / cheapest, to show what a set saves.
            $hi = $lo = 0.0;
            foreach ($p['slots'] as $s) {
                $prices = array_filter(array_map(fn ($i) => $i['sale_price'], $s['items']), fn ($v) => $v !== null);
                $hi += $prices ? max($prices) * $s['qty'] : 0;
                $lo += $prices ? min($prices) * $s['qty'] : 0;
            }
            $p['regular_max'] = round($hi, 2);
            $p['regular_min'] = round($lo, 2);
            $p['status'] = !$p['active'] ? 'off'
                : ($p['starts_on'] && $p['starts_on'] > $now ? 'upcoming'
                : ($p['ends_on'] && $p['ends_on'] < $now ? 'ended' : 'on'));
            $p['used_bills'] = $used[$p['id']]['bills'] ?? 0;
            $p['used_discount'] = $used[$p['id']]['discount'] ?? 0.0;
        }
        unset($p);
        return $promos;
    }

    /** Bills and baht given per promotion so far. */
    private function usage(): array
    {
        $rows = $this->db->query(
            'SELECT promo_id, COUNT(DISTINCT sale_id) AS bills, SUM(promo_discount) AS discount
             FROM sale_items WHERE promo_id IS NOT NULL GROUP BY promo_id'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['promo_id']] = ['bills' => (int) $r['bills'], 'discount' => round((float) $r['discount'], 2)];
        }
        return $out;
    }

    /**
     * POST /promos (id = null) or PUT /promos/{id}:
     * {name, kind, value, starts_on?, ends_on?, active?, note?, slots: [{label?, qty, items: [{product_id}|{sale_group_id}]}]}
     */
    public function save(?int $id, array $in, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 200);
        if ($name === '') {
            throw new \RuntimeException('กรุณาตั้งชื่อโปรโมชั่น');
        }
        $kind = (string) ($in['kind'] ?? 'price');
        if (!isset(self::KINDS[$kind])) {
            throw new \RuntimeException('รูปแบบส่วนลดไม่ถูกต้อง');
        }
        $value = $in['value'] ?? null;
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new \RuntimeException($kind === 'price' ? 'ราคาชุดต้องมากกว่า 0' : 'ส่วนลดต้องมากกว่า 0');
        }
        $value = round((float) $value, 2);
        if ($kind === 'percent' && $value >= 100) {
            throw new \RuntimeException('ส่วนลดเป็นเปอร์เซ็นต์ต้องน้อยกว่า 100');
        }
        // A day and time ("2026-11-20T11:30"); a day alone starts at its first minute and ends at its last.
        $timed = $this->timed();
        $date = function ($v, string $label, bool $end) use ($timed): ?string {
            $v = trim(str_replace('T', ' ', (string) ($v ?? '')));
            if ($v === '') {
                return null;
            }
            foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d'] as $f) {
                $d = \DateTimeImmutable::createFromFormat($f, $v);
                if ($d && $d->format(substr($f, 1)) === $v) {
                    if ($f === '!Y-m-d') {
                        $d = $end ? $d->setTime(23, 59, 59) : $d;
                    } elseif ($end && $f === '!Y-m-d H:i') {
                        $d = $d->setTime((int) $d->format('H'), (int) $d->format('i'), 59);
                    }
                    return $d->format($timed ? 'Y-m-d H:i:s' : 'Y-m-d');
                }
            }
            throw new \RuntimeException("{$label}ไม่ถูกต้อง");
        };
        $starts = $date($in['starts_on'] ?? null, 'วันเวลาเริ่มโปรโมชั่น', false);
        $ends = $date($in['ends_on'] ?? null, 'วันเวลาสิ้นสุดโปรโมชั่น', true);
        if ($starts && $ends && $ends < $starts) {
            throw new \RuntimeException('วันเวลาสิ้นสุดต้องไม่ก่อนวันเวลาเริ่มโปรโมชั่น');
        }
        if (!$timed && ((string) ($in['starts_on'] ?? '') . (string) ($in['ends_on'] ?? '')) !== '' && preg_match('/\d:\d/', (string) ($in['starts_on'] ?? '') . (string) ($in['ends_on'] ?? ''))) {
            throw new \RuntimeException('ยังกำหนดเวลาเริ่ม/สิ้นสุดไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0087) ก่อน');
        }
        $active = !array_key_exists('active', $in) || !empty($in['active']);
        $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 500) ?: null;
        $slots = $this->cleanSlots((array) ($in['slots'] ?? []));

        $this->db->beginTransaction();
        try {
            $before = null;
            if ($id === null) {
                $this->db->prepare(
                    'INSERT INTO promo_bundles (name, kind, value, starts_on, ends_on, active, note, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                )->execute([$name, $kind, $value, $starts, $ends, (int) $active, $note, $userId]);
                $id = (int) $this->db->lastInsertId();
            } else {
                $stmt = $this->db->prepare('SELECT * FROM promo_bundles WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $before = $stmt->fetch();
                if (!$before) {
                    throw new \RuntimeException('ไม่พบโปรโมชั่นนี้');
                }
                $before['slots'] = $this->slotsOf($id);
                $this->db->prepare(
                    'UPDATE promo_bundles SET name = ?, kind = ?, value = ?, starts_on = ?, ends_on = ?, active = ?, note = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$name, $kind, $value, $starts, $ends, (int) $active, $note, $id]);
                $this->db->prepare('DELETE i FROM promo_bundle_items i JOIN promo_bundle_slots s ON s.id = i.slot_id WHERE s.promo_id = ?')->execute([$id]);
                $this->db->prepare('DELETE FROM promo_bundle_slots WHERE promo_id = ?')->execute([$id]);
            }
            $insSlot = $this->db->prepare('INSERT INTO promo_bundle_slots (promo_id, position, label, qty) VALUES (?, ?, ?, ?)');
            $insItem = $this->db->prepare('INSERT INTO promo_bundle_items (slot_id, product_id, sale_group_id) VALUES (?, ?, ?)');
            foreach ($slots as $pos => $s) {
                $insSlot->execute([$id, $pos, $s['label'], $s['qty']]);
                $slotId = (int) $this->db->lastInsertId();
                foreach ($s['items'] as $it) {
                    $insItem->execute([$slotId, $it['product_id'], $it['sale_group_id']]);
                }
            }
            $after = ['name' => $name, 'kind' => $kind, 'value' => $value, 'starts_on' => $starts, 'ends_on' => $ends, 'active' => $active, 'note' => $note, 'slots' => $slots];
            $this->audit?->log('promo_bundles', $id, $before ? 'update' : 'create', $before, $after, $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['id' => $id];
    }

    /** Slots as saved: label, qty, items each a product or a sale group (a group's members go by the group). */
    private function cleanSlots(array $slots): array
    {
        $out = [];
        foreach ($slots as $s) {
            $s = (array) $s;
            $qty = (int) ($s['qty'] ?? 1);
            if ($qty < 1 || $qty > 99) {
                throw new \RuntimeException('จำนวนชิ้นในแต่ละช่องต้องอยู่ระหว่าง 1–99');
            }
            $items = [];
            foreach ((array) ($s['items'] ?? []) as $it) {
                $it = (array) $it;
                $gid = (int) ($it['sale_group_id'] ?? 0);
                $pid = (int) ($it['product_id'] ?? 0);
                if ($gid > 0) {
                    $items['g' . $gid] = ['product_id' => null, 'sale_group_id' => $gid];
                } elseif ($pid > 0) {
                    $items['p' . $pid] = ['product_id' => $pid, 'sale_group_id' => null];
                }
            }
            if (!$items) {
                throw new \RuntimeException('แต่ละช่องของโปรโมชั่นต้องมีสินค้าอย่างน้อย 1 รายการ');
            }
            $out[] = ['label' => mb_substr(trim((string) ($s['label'] ?? '')), 0, 100) ?: null, 'qty' => $qty, 'items' => array_values($items)];
        }
        if (!$out) {
            throw new \RuntimeException('เพิ่มสินค้าที่จะจับคู่อย่างน้อย 1 ช่อง');
        }
        if (array_sum(array_column($out, 'qty')) < 2) {
            throw new \RuntimeException('โปรโมชั่นจับคู่ต้องมีสินค้ารวมอย่างน้อย 2 ชิ้นต่อชุด (เช่น 2 ช่อง ช่องละ 1 ชิ้น หรือ 1 ช่อง 2 ชิ้น)');
        }
        // Every product / group must exist; a product sold in a sale group goes by its group.
        $pids = [];
        $gids = [];
        foreach ($out as $s) {
            foreach ($s['items'] as $it) {
                $it['product_id'] ? $pids[] = $it['product_id'] : $gids[] = $it['sale_group_id'];
            }
        }
        if ($pids) {
            $pids = array_values(array_unique($pids));
            $grp = SaleGroupService::ready($this->db) ? ', p.sale_group_id, g.name AS group_name' : ', NULL AS sale_group_id, NULL AS group_name';
            $join = SaleGroupService::ready($this->db) ? ' LEFT JOIN sale_groups g ON g.id = p.sale_group_id' : '';
            $stmt = $this->db->prepare("SELECT p.id, p.name{$grp} FROM products p{$join} WHERE p.id IN (" . implode(',', array_fill(0, count($pids), '?')) . ')');
            $stmt->execute($pids);
            $rows = $stmt->fetchAll();
            if (count($rows) !== count($pids)) {
                throw new \RuntimeException('ไม่พบสินค้าบางรายการ กรุณารีเฟรช');
            }
            foreach ($rows as $r) {
                if ($r['sale_group_id'] !== null) {
                    throw new \RuntimeException("\"{$r['name']}\" ขายหน้า POS ในกลุ่มขาย \"{$r['group_name']}\" — ให้เลือกกลุ่มขายนั้นแทนสินค้าแต่ละรายการ");
                }
            }
        }
        if ($gids) {
            $gids = array_values(array_unique($gids));
            $this->assertGroupsExist($gids);
        }
        return $out;
    }

    private function assertGroupsExist(array $gids): void
    {
        if (!SaleGroupService::ready($this->db)) {
            throw new \RuntimeException('ยังไม่มีกลุ่มขายหน้า POS (migration 0084)');
        }
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM sale_groups WHERE id IN (' . implode(',', array_fill(0, count($gids), '?')) . ')');
        $stmt->execute($gids);
        if ((int) $stmt->fetchColumn() !== count($gids)) {
            throw new \RuntimeException('ไม่พบกลุ่มขายบางกลุ่ม (อาจถูกยกเลิกแล้ว) กรุณารีเฟรช');
        }
    }

    private function slotsOf(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.label, s.qty, i.product_id, i.sale_group_id FROM promo_bundle_slots s
             LEFT JOIN promo_bundle_items i ON i.slot_id = s.id WHERE s.promo_id = ? ORDER BY s.position, s.id, i.id'
        );
        $stmt->execute([$id]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $k = (int) $r['id'];
            $out[$k] ??= ['label' => $r['label'], 'qty' => (int) $r['qty'], 'items' => []];
            if ($r['product_id'] !== null || $r['sale_group_id'] !== null) {
                $out[$k]['items'][] = ['product_id' => $r['product_id'] !== null ? (int) $r['product_id'] : null, 'sale_group_id' => $r['sale_group_id'] !== null ? (int) $r['sale_group_id'] : null];
            }
        }
        return array_values($out);
    }

    /** POST /promos/{id}/active {active} */
    public function setActive(int $id, bool $active, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $stmt = $this->db->prepare('SELECT active FROM promo_bundles WHERE id = ?');
        $stmt->execute([$id]);
        $was = $stmt->fetchColumn();
        if ($was === false) {
            throw new \RuntimeException('ไม่พบโปรโมชั่นนี้');
        }
        $this->db->prepare('UPDATE promo_bundles SET active = ?, updated_at = NOW() WHERE id = ?')->execute([(int) $active, $id]);
        $this->audit?->log('promo_bundles', $id, 'update', ['active' => (bool) $was], ['active' => $active], $userId, $ip);
        return ['id' => $id, 'active' => $active];
    }

    /** POST /promos/{id}/delete — bills that used it keep their discount (and promo_id). */
    public function delete(int $id, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $stmt = $this->db->prepare('SELECT * FROM promo_bundles WHERE id = ?');
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) {
            throw new \RuntimeException('ไม่พบโปรโมชั่นนี้');
        }
        $this->db->beginTransaction();
        try {
            $p['slots'] = $this->slotsOf($id);
            $this->db->prepare('DELETE i FROM promo_bundle_items i JOIN promo_bundle_slots s ON s.id = i.slot_id WHERE s.promo_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM promo_bundle_slots WHERE promo_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM promo_bundles WHERE id = ?')->execute([$id]);
            $this->audit?->log('promo_bundles', $id, 'delete', $p, null, $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['id' => $id];
    }

    /**
     * GET /promos/items?q=: what a slot can hold, as the POS sells it: products (not discontinued,
     * not in a sale group) by name or barcode, and sale groups by name.
     */
    public function searchItems(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $like = '%' . $q . '%';
        $groups = SaleGroupService::ready($this->db);
        $where = ($groups ? 'p.sale_group_id IS NULL AND ' : '') . (Db::hasColumn($this->db, 'products', 'discontinued_at') ? 'p.discontinued_at IS NULL AND ' : '');
        $stmt = $this->db->prepare(
            "SELECT p.id, p.name, p.barcode, p.sale_price, p.qty_stock, p.consignor_id, COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name
             FROM products p LEFT JOIN consignors c ON c.id = p.consignor_id
             WHERE {$where}(p.name LIKE ? OR p.barcode = ?) ORDER BY p.qty_stock > 0 DESC, p.name LIMIT 30"
        );
        $stmt->execute([$like, $q]);
        $out = array_map(fn ($r) => [
            'product_id' => (int) $r['id'], 'sale_group_id' => null, 'name' => $r['name'], 'barcode' => $r['barcode'],
            'sale_price' => (float) $r['sale_price'], 'qty_stock' => (int) $r['qty_stock'],
            'consigned' => $r['consignor_id'] !== null, 'consignor_name' => $r['consignor_name'], 'members' => null,
        ], $stmt->fetchAll());
        if ($groups) {
            // A group also turns up when one of its members matches (they are sold as the group).
            $stmt = $this->db->prepare(
                'SELECT g.id, g.name, g.sale_price, COUNT(p.id) AS members, COALESCE(SUM(GREATEST(p.qty_stock, 0)), 0) AS qty_stock
                 FROM sale_groups g LEFT JOIN products p ON p.sale_group_id = g.id
                 WHERE g.name LIKE ? OR EXISTS (SELECT 1 FROM products x WHERE x.sale_group_id = g.id AND (x.name LIKE ? OR x.barcode = ?))
                 GROUP BY g.id, g.name, g.sale_price ORDER BY g.name LIMIT 20'
            );
            $stmt->execute([$like, $like, $q]);
            foreach ($stmt->fetchAll() as $g) {
                array_unshift($out, [
                    'product_id' => null, 'sale_group_id' => (int) $g['id'], 'name' => $g['name'], 'barcode' => null,
                    'sale_price' => (float) $g['sale_price'], 'qty_stock' => (int) $g['qty_stock'],
                    'consigned' => false, 'consignor_name' => 'กลุ่มขายหน้า POS', 'members' => (int) $g['members'],
                ]);
            }
        }
        return $out;
    }

    // ---------- Applying promotions to a cart ----------

    /** Promotions running at $now (a day before migration 0087), with each slot's item keys ('p12' / 'g3'), oldest first. */
    private function running(string $day): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM promo_bundles WHERE active = 1 AND (starts_on IS NULL OR starts_on <= ?) AND (ends_on IS NULL OR ends_on >= ?) ORDER BY id'
        );
        $stmt->execute([$day, $day]);
        $promos = $stmt->fetchAll();
        if (!$promos) {
            return [];
        }
        $ids = array_map(fn ($p) => (int) $p['id'], $promos);
        $stmt = $this->db->prepare(
            'SELECT s.promo_id, s.id, s.qty, i.product_id, i.sale_group_id FROM promo_bundle_slots s
             JOIN promo_bundle_items i ON i.slot_id = s.id
             WHERE s.promo_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY s.position, s.id'
        );
        $stmt->execute($ids);
        $slots = [];
        foreach ($stmt->fetchAll() as $r) {
            $slot = &$slots[(int) $r['promo_id']][(int) $r['id']];
            $slot['qty'] = (int) $r['qty'];
            $slot['keys'][] = $r['sale_group_id'] !== null ? 'g' . $r['sale_group_id'] : 'p' . $r['product_id'];
            unset($slot);
        }
        $out = [];
        foreach ($promos as $p) {
            if (!empty($slots[(int) $p['id']])) {
                $out[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'kind' => $p['kind'], 'value' => (float) $p['value'], 'slots' => array_values($slots[(int) $p['id']])];
            }
        }
        return $out;
    }

    /**
     * Works out the bundle discounts of a cart.
     * @param array<int|string, array{key:string, qty:int, price:float}> $lines key 'p<product id>' or 'g<group id>'; qty in pieces; price per piece
     * @return array{total:float, lines:array<int|string, array{discount:float, promo_id:int}>, applied:array<int, array{promo_id:int, name:string, sets:int, discount:float}>}
     *
     * Promotions are taken oldest first; pieces used by one are not used again. Each set takes,
     * slot by slot, the dearest pieces still free (the customer gets the most off); a set that
     * would save nothing is not made. A set's discount is shared over its pieces by their price.
     */
    public function apply(array $lines, ?string $day = null): array
    {
        $result = ['total' => 0.0, 'lines' => [], 'applied' => []];
        if (!self::ready($this->db) || !$lines) {
            return $result;
        }
        return self::compute($lines, $this->running($day ?? date($this->timed() ? 'Y-m-d H:i:s' : 'Y-m-d')));
    }

    /**
     * apply() without the database: $promos as running() gives them
     * ([{id, name, kind, value, slots: [{qty, keys: ['p12', 'g3']}]}], in the order they are tried).
     */
    public static function compute(array $lines, array $promos): array
    {
        $result = ['total' => 0.0, 'lines' => [], 'applied' => []];
        if (!$promos || !$lines) {
            return $result;
        }
        $left = [];
        foreach ($lines as $i => $l) {
            if ((int) $l['qty'] > 0 && (float) $l['price'] > 0) {
                $left[$i] = (int) $l['qty'];
            }
        }
        // Dearest first; equal prices in cart order.
        $order = array_keys($left);
        usort($order, fn ($a, $b) => ((float) $lines[$b]['price'] <=> (float) $lines[$a]['price']) ?: (array_search($a, array_keys($lines), true) <=> array_search($b, array_keys($lines), true)));

        $raw = []; // line => [promo id => discount (unrounded)]
        foreach ($promos as $promo) {
            $sets = 0;
            $promoDiscount = 0.0;
            while (true) {
                $try = $left;
                $taken = [];
                foreach ($promo['slots'] as $slot) {
                    $need = $slot['qty'];
                    foreach ($order as $i) {
                        if ($need === 0) {
                            break;
                        }
                        if ($try[$i] > 0 && in_array($lines[$i]['key'], $slot['keys'], true)) {
                            $n = min($need, $try[$i]);
                            $try[$i] -= $n;
                            $need -= $n;
                            $taken[$i] = ($taken[$i] ?? 0) + $n;
                        }
                    }
                    if ($need > 0) {
                        break 2;
                    }
                }
                $regular = 0.0;
                foreach ($taken as $i => $n) {
                    $regular += (float) $lines[$i]['price'] * $n;
                }
                $off = round(match ($promo['kind']) {
                    'price' => $regular - $promo['value'],
                    'amount' => min($promo['value'], $regular),
                    'percent' => $regular * $promo['value'] / 100,
                    default => 0,
                }, 2);
                if ($off < 0.01) {
                    break;
                }
                $left = $try;
                $sets++;
                $promoDiscount += $off;
                foreach ($taken as $i => $n) {
                    $raw[$i][$promo['id']] = ($raw[$i][$promo['id']] ?? 0) + $off * ((float) $lines[$i]['price'] * $n) / $regular;
                }
            }
            if ($sets > 0) {
                $result['applied'][] = ['promo_id' => $promo['id'], 'name' => $promo['name'], 'sets' => $sets, 'discount' => round($promoDiscount, 2)];
            }
        }
        if (!$result['applied']) {
            return $result;
        }
        // Line discounts to the satang, adding up to the promotions' own figures.
        $total = round(array_sum(array_column($result['applied'], 'discount')), 2);
        $rounded = [];
        foreach ($raw as $i => $per) {
            arsort($per);
            $rounded[$i] = ['discount' => round(array_sum($per), 2), 'promo_id' => (int) array_key_first($per)];
        }
        $diff = round($total - array_sum(array_column($rounded, 'discount')), 2);
        if (abs($diff) >= 0.005) {
            uasort($rounded, fn ($a, $b) => $b['discount'] <=> $a['discount']);
            $big = array_key_first($rounded);
            $rounded[$big]['discount'] = round($rounded[$big]['discount'] + $diff, 2);
        }
        $result['total'] = $total;
        $result['lines'] = $rounded;
        return $result;
    }

    /**
     * POS sale lines as apply() needs them: a group line ({group_id, qty}) by its group and price,
     * a piece line ({product_id, qty}) by its product and price. Whole packs (pack_size > 1) keep
     * their pack price and take no part. Lines are keyed by their index in $items.
     */
    public function cartLines(array $items): array
    {
        $pids = [];
        $gids = [];
        foreach ($items as $l) {
            if ((int) ($l['group_id'] ?? 0) > 0) {
                $gids[] = (int) $l['group_id'];
            } elseif ((int) ($l['product_id'] ?? 0) > 0 && (int) ($l['pack_size'] ?? 1) <= 1) {
                $pids[] = (int) $l['product_id'];
            }
        }
        $prices = [];
        if ($pids) {
            $pids = array_values(array_unique($pids));
            $stmt = $this->db->prepare('SELECT id, sale_price FROM products WHERE id IN (' . implode(',', array_fill(0, count($pids), '?')) . ')');
            $stmt->execute($pids);
            foreach ($stmt->fetchAll() as $r) {
                $prices['p' . $r['id']] = (float) $r['sale_price'];
            }
        }
        if ($gids && SaleGroupService::ready($this->db)) {
            $gids = array_values(array_unique($gids));
            $stmt = $this->db->prepare('SELECT id, sale_price FROM sale_groups WHERE id IN (' . implode(',', array_fill(0, count($gids), '?')) . ')');
            $stmt->execute($gids);
            foreach ($stmt->fetchAll() as $r) {
                $prices['g' . $r['id']] = (float) $r['sale_price'];
            }
        }
        $lines = [];
        foreach ($items as $i => $l) {
            $gid = (int) ($l['group_id'] ?? 0);
            if ($gid > 0) {
                $key = 'g' . $gid;
            } elseif ((int) ($l['pack_size'] ?? 1) <= 1 && (int) ($l['product_id'] ?? 0) > 0) {
                $key = 'p' . (int) $l['product_id'];
            } else {
                continue;
            }
            if (!isset($prices[$key])) {
                continue;
            }
            $price = isset($l['unit_price']) && $gid <= 0 ? round((float) $l['unit_price'], 2) : $prices[$key];
            $lines[$i] = ['key' => $key, 'qty' => (int) ($l['qty'] ?? 0), 'price' => $price];
        }
        return $lines;
    }

    /** POST /sales/promo-quote: the discounts of a POS cart, per cart line (by index). */
    public function quote(array $items): array
    {
        $res = $this->apply($this->cartLines($items));
        return ['total' => $res['total'], 'lines' => $res['lines'], 'applied' => $res['applied']];
    }

    /**
     * Sale lines with their discount added (promo_discount = baht off the whole line, promo_id),
     * for SaleService::record. Returns [items, total discount, applied promotions].
     */
    public function annotate(array $items): array
    {
        $res = $this->apply($this->cartLines($items));
        foreach ($res['lines'] as $i => $d) {
            if ($d['discount'] > 0) {
                $items[$i]['promo_discount'] = $d['discount'];
                $items[$i]['promo_id'] = $d['promo_id'];
            }
        }
        return [$items, $res['total'], $res['applied']];
    }
}
