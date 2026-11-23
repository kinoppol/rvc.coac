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
 * percent = `value` % off each set; item = each item names its own baht off a piece (migration 0091),
 * and on consigned goods that discount comes out of the consignor's share.
 *
 * The web POS asks for a quote while the cart changes and the sale is recorded with the same
 * figures. The Android POS keeps the promotions (devicePromos) and works them out itself, offline
 * too, with the same rules; it sends each line's discount (checkDevice). Imported sales get none.
 */
class PromoService
{
    public const KINDS = ['price' => 'ราคาชุด', 'amount' => 'ลดบาทต่อชุด', 'percent' => 'ลด % ต่อชุด', 'item' => 'ระบุส่วนลดรายสินค้า'];

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

    /** Whether items can carry their own discount (migration 0091). */
    private function itemOffs(): bool
    {
        return Db::hasColumn($this->db, 'promo_bundle_items', 'discount');
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
        $offCol = $this->itemOffs() ? 'i.discount' : 'NULL AS discount';
        $items = $this->db->query(
            "SELECT i.slot_id, i.product_id, i.sale_group_id, {$offCol},
                    COALESCE(p.name, g.name) AS name, COALESCE(p.sale_price, g.sale_price) AS sale_price,
                    p.barcode, p.consignor_id, p.qty_stock,
                    (SELECT COUNT(*) FROM products m WHERE g.id IS NOT NULL AND m.sale_group_id = g.id) AS members,
                    (SELECT COALESCE(SUM(GREATEST(m.qty_stock, 0)), 0) FROM products m WHERE g.id IS NOT NULL AND m.sale_group_id = g.id) AS group_stock
             FROM promo_bundle_items i
             LEFT JOIN products p ON p.id = i.product_id
             LEFT JOIN sale_groups g ON g.id = i.sale_group_id
             ORDER BY i.id"
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
                'discount' => $it['discount'] !== null ? (float) $it['discount'] : null,
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
        if ($kind === 'item' && !$this->itemOffs()) {
            throw new \RuntimeException('ยังระบุส่วนลดรายสินค้าไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0091) ก่อน');
        }
        // Kind 'item' has no figure of its own: the discounts are on its items.
        $value = $kind === 'item' ? 1 : ($in['value'] ?? null);
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new \RuntimeException($kind === 'price' ? 'ราคาชุดต้องมากกว่า 0' : 'ส่วนลดต้องมากกว่า 0');
        }
        $value = $kind === 'item' ? 0.0 : round((float) $value, 2);
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
        $slots = $this->cleanSlots((array) ($in['slots'] ?? []), $kind);

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
            $withOff = $this->itemOffs();
            $insItem = $this->db->prepare($withOff
                ? 'INSERT INTO promo_bundle_items (slot_id, product_id, sale_group_id, discount) VALUES (?, ?, ?, ?)'
                : 'INSERT INTO promo_bundle_items (slot_id, product_id, sale_group_id) VALUES (?, ?, ?)');
            foreach ($slots as $pos => $s) {
                $insSlot->execute([$id, $pos, $s['label'], $s['qty']]);
                $slotId = (int) $this->db->lastInsertId();
                foreach ($s['items'] as $it) {
                    $row = [$slotId, $it['product_id'], $it['sale_group_id']];
                    $insItem->execute($withOff ? [...$row, $it['discount'] ?? null] : $row);
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
    private function cleanSlots(array $slots, string $kind = 'price'): array
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
                $off = null;
                if ($kind === 'item') {
                    $raw = $it['discount'] ?? 0;
                    if ($raw === '' || $raw === null) {
                        $raw = 0;
                    }
                    if (!is_numeric($raw) || (float) $raw < 0) {
                        throw new \RuntimeException('ส่วนลดต่อชิ้นต้องเป็นตัวเลขไม่ติดลบ');
                    }
                    $off = round((float) $raw, 2);
                }
                if ($gid > 0) {
                    $items['g' . $gid] = ['product_id' => null, 'sale_group_id' => $gid, 'discount' => $off];
                } elseif ($pid > 0) {
                    $items['p' . $pid] = ['product_id' => $pid, 'sale_group_id' => null, 'discount' => $off];
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
        if ($kind === 'item') {
            $this->assertItemOffs($out);
        }
        return $out;
    }

    /**
     * Kind 'item': some item must give a discount, none more than its price, and on consigned goods
     * none more than the consignor's share of a piece (they bear it).
     */
    private function assertItemOffs(array $slots): void
    {
        $any = false;
        $product = $this->db->prepare('SELECT name, sale_price, consignor_id, fee_per_unit, round_per_unit, rounding_beneficiary FROM products WHERE id = ?');
        $group = $this->db->prepare('SELECT name, sale_price FROM sale_groups WHERE id = ?');
        $members = $this->db->prepare('SELECT name, consignor_id, fee_per_unit, round_per_unit, rounding_beneficiary FROM products WHERE sale_group_id = ? AND consignor_id IS NOT NULL');
        $share = fn (float $price, array $p) => round($price - (float) $p['fee_per_unit'] - ($p['rounding_beneficiary'] === 'store' ? (float) $p['round_per_unit'] : 0), 2);
        foreach ($slots as $s) {
            foreach ($s['items'] as $it) {
                $off = (float) $it['discount'];
                $any = $any || $off > 0;
                if ($off <= 0) {
                    continue;
                }
                if ($it['product_id']) {
                    $product->execute([$it['product_id']]);
                    $p = $product->fetch();
                    $price = (float) $p['sale_price'];
                    $name = $p['name'];
                    $consigned = $p['consignor_id'] !== null ? [$p] : [];
                } else {
                    $group->execute([$it['sale_group_id']]);
                    $g = $group->fetch();
                    $price = (float) $g['sale_price'];
                    $name = $g['name'];
                    $members->execute([$it['sale_group_id']]);
                    $consigned = $members->fetchAll();
                }
                if ($off > $price) {
                    throw new \RuntimeException("ส่วนลดของ \"{$name}\" เกินราคาขาย ฿" . number_format($price, 2));
                }
                foreach ($consigned as $c) {
                    $most = $share($price, $c);
                    if ($off > $most) {
                        throw new \RuntimeException("ส่วนลดของ \"{$name}\" เกินส่วนแบ่งของผู้ฝาก (ลดได้ไม่เกิน ฿" . number_format(max(0, $most), 2) . ' ต่อชิ้น เพราะผู้ฝากรับภาระส่วนลด)');
                    }
                }
            }
        }
        if (!$any) {
            throw new \RuntimeException('ระบุส่วนลดต่อชิ้นอย่างน้อย 1 รายการ');
        }
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
        return $this->withSlots($stmt->fetchAll());
    }

    /** Promotion rows with their slots (qty, label, item keys and names); rows without slots are left out. */
    private function withSlots(array $promos): array
    {
        if (!$promos) {
            return [];
        }
        $ids = array_map(fn ($p) => (int) $p['id'], $promos);
        $offCol = $this->itemOffs() ? 'i.discount' : 'NULL AS discount';
        $stmt = $this->db->prepare(
            "SELECT s.promo_id, s.id, s.qty, s.label, i.product_id, i.sale_group_id, {$offCol}, COALESCE(p.name, g.name) AS item_name
             FROM promo_bundle_slots s
             JOIN promo_bundle_items i ON i.slot_id = s.id
             LEFT JOIN products p ON p.id = i.product_id
             LEFT JOIN sale_groups g ON g.id = i.sale_group_id
             WHERE s.promo_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY s.position, s.id'
        );
        $stmt->execute($ids);
        $slots = [];
        foreach ($stmt->fetchAll() as $r) {
            $slot = &$slots[(int) $r['promo_id']][(int) $r['id']];
            $slot['qty'] = (int) $r['qty'];
            $slot['label'] = $r['label'];
            $slot['names'][] = (string) $r['item_name'];
            $key = $r['sale_group_id'] !== null ? 'g' . $r['sale_group_id'] : 'p' . $r['product_id'];
            $slot['keys'][] = $key;
            // Kind 'item': baht off a piece, by item key (absent otherwise, so it never encodes as []).
            if ($r['discount'] !== null) {
                $slot['offs'][$key] = (float) $r['discount'];
            }
            unset($slot);
        }
        $out = [];
        foreach ($promos as $p) {
            if (!empty($slots[(int) $p['id']])) {
                $out[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'kind' => $p['kind'], 'value' => (float) $p['value'],
                    'starts_on' => $p['starts_on'], 'ends_on' => $p['ends_on'], 'slots' => array_values($slots[(int) $p['id']])];
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
        $itemOff = []; // line => the part from kind 'item' promotions (the consignor bears it)
        foreach ($promos as $promo) {
            $sets = 0;
            $promoDiscount = 0.0;
            while (true) {
                $try = $left;
                $taken = [];
                $named = []; // kind 'item': line => baht its pieces in this set take off
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
                            if ($promo['kind'] === 'item') {
                                $named[$i] = ($named[$i] ?? 0) + $n * min((float) ($slot['offs'][$lines[$i]['key']] ?? 0), (float) $lines[$i]['price']);
                            }
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
                    'item' => array_sum($named),
                    default => 0,
                }, 2);
                if ($off < 0.01) {
                    break;
                }
                $left = $try;
                $sets++;
                $promoDiscount += $off;
                foreach ($taken as $i => $n) {
                    if ($promo['kind'] === 'item') {
                        if (($named[$i] ?? 0) > 0) {
                            $raw[$i][$promo['id']] = ($raw[$i][$promo['id']] ?? 0) + $named[$i];
                            $itemOff[$i] = ($itemOff[$i] ?? 0) + $named[$i];
                        }
                        continue;
                    }
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
        foreach ($rounded as $i => $r) {
            $rounded[$i]['item_off'] = min(round($itemOff[$i] ?? 0, 2), $r['discount']);
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

    /**
     * GET /sales/promos: the promotions running now, for the POS to show which goods are on a
     * promotion and what a cart still lacks for one (slots with their item keys and names).
     */
    public function posList(): array
    {
        if (!self::ready($this->db)) {
            return [];
        }
        return $this->running(date($this->timed() ? 'Y-m-d H:i:s' : 'Y-m-d'));
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
                $items[$i]['promo_item_off'] = $d['item_off'] ?? 0.0;
            }
        }
        return [$items, $res['total'], $res['applied']];
    }

    // ---------- The Android POS ----------

    /**
     * GET /api/device/v1/promos: every promotion that is on and not over yet (also those not started:
     * the app works offline and checks the start / end itself), with the products of each sale group
     * they name (the app sells a group's members one by one; any of them fills the group's slot).
     * starts_on / ends_on: "Y-m-d H:i:s", or "Y-m-d" before migration 0087 (the whole day).
     */
    public function devicePromos(): array
    {
        if (!self::ready($this->db)) {
            return ['promos' => [], 'groups' => (object) [], 'ready' => false];
        }
        $now = date($this->timed() ? 'Y-m-d H:i:s' : 'Y-m-d');
        $stmt = $this->db->prepare('SELECT * FROM promo_bundles WHERE active = 1 AND (ends_on IS NULL OR ends_on >= ?) ORDER BY id');
        $stmt->execute([$now]);
        $promos = $this->withSlots($stmt->fetchAll());
        $gids = [];
        foreach ($promos as $p) {
            foreach ($p['slots'] as $s) {
                foreach ($s['keys'] as $k) {
                    if ($k[0] === 'g') {
                        $gids[(int) substr($k, 1)] = true;
                    }
                }
            }
        }
        $groups = [];
        if ($gids && SaleGroupService::ready($this->db)) {
            $ids = array_keys($gids);
            $stmt = $this->db->prepare('SELECT id, sale_group_id FROM products WHERE sale_group_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $r) {
                $groups[(string) $r['sale_group_id']][] = (int) $r['id'];
            }
        }
        return ['promos' => $promos, 'groups' => (object) $groups, 'ready' => true];
    }

    /**
     * A sale from the Android POS: its lines with the discount the device gave (promo_discount baht
     * off the whole line, promo_id), kept as sold — the customer has paid. Returns [items, warnings]:
     * a warning when the server's own figure at the time of sale differs (promotion changed, or the
     * device had not loaded it yet). Before migration 0086 the discounts cannot be kept: dropped, with a warning.
     * @param array $items [{product_id, qty, pack_size?, unit_price?}] as the sale is booked
     * @param array $sent the device's lines (same order), with promo_discount / promo_id
     */
    public function checkDevice(array $items, array $sent, string $soldAt): array
    {
        $given = 0.0;
        foreach ($items as $i => $it) {
            $line = (array) ($sent[$i] ?? []);
            $d = isset($line['promo_discount']) && is_numeric($line['promo_discount']) ? round((float) $line['promo_discount'], 2) : 0.0;
            if ($d <= 0 || (int) ($it['pack_size'] ?? 1) > 1) {
                continue;
            }
            $items[$i]['promo_discount'] = $d;
            $items[$i]['promo_id'] = (int) ($line['promo_id'] ?? 0) ?: null;
            // The part from a promotion with per-item discounts: the consignor bears it.
            $items[$i]['promo_item_off'] = isset($line['promo_item_off']) && is_numeric($line['promo_item_off'])
                ? max(0.0, min(round((float) $line['promo_item_off'], 2), $d)) : 0.0;
            $given += $d;
        }
        $given = round($given, 2);
        if (!self::ready($this->db)) {
            if ($given > 0) {
                foreach ($items as &$it) {
                    unset($it['promo_discount'], $it['promo_id'], $it['promo_item_off']);
                }
                unset($it);
                return [$items, ['อุปกรณ์ให้ส่วนลดโปรโมชั่น ฿' . number_format($given, 2) . ' แต่เซิร์ฟเวอร์ยังไม่ได้รัน migration 0086 จึงบันทึกเป็นราคาปกติ']];
            }
            return [$items, []];
        }
        // What the server would give: the device's lines by product (or its sale group) at the price sold.
        $pids = array_values(array_unique(array_map(fn ($it) => (int) $it['product_id'], $items)));
        $info = [];
        if ($pids) {
            $grp = SaleGroupService::ready($this->db) ? 'sale_group_id' : 'NULL AS sale_group_id';
            $stmt = $this->db->prepare("SELECT id, sale_price, {$grp} FROM products WHERE id IN (" . implode(',', array_fill(0, count($pids), '?')) . ')');
            $stmt->execute($pids);
            foreach ($stmt->fetchAll() as $r) {
                $info[(int) $r['id']] = $r;
            }
        }
        $lines = [];
        foreach ($items as $i => $it) {
            $p = $info[(int) $it['product_id']] ?? null;
            if (!$p || (int) ($it['pack_size'] ?? 1) > 1) {
                continue;
            }
            $key = $p['sale_group_id'] !== null ? 'g' . $p['sale_group_id'] : 'p' . $p['id'];
            $lines[$i] = ['key' => $key, 'qty' => (int) $it['qty'], 'price' => isset($it['unit_price']) ? round((float) $it['unit_price'], 2) : (float) $p['sale_price']];
        }
        $at = $this->timed() ? $soldAt : substr($soldAt, 0, 10);
        $server = $lines ? self::compute($lines, $this->running($at))['total'] : 0.0;
        $warnings = [];
        if (abs($server - $given) >= 0.005) {
            $warnings[] = 'ส่วนลดโปรโมชั่นบนอุปกรณ์ ฿' . number_format($given, 2) . ' ไม่ตรงกับที่เซิร์ฟเวอร์คำนวณ ฿' . number_format($server, 2)
                . ' (บันทึกตามที่ขายจริงบนอุปกรณ์)';
        }
        return [$items, $warnings];
    }
}
