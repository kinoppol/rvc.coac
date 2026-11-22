<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Goods a member may take points off (point_redeems, migration 0088), shown as the second tab of the
 * promotions page. A rule names products or sale groups and its rate: every `points` points take
 * `baht` baht off, at most max_discount baht a piece. A product in more than one running rule uses
 * the oldest one, as with bundle promotions.
 */
class PointRedeemService
{
    public function __construct(private PDO $db, private ?AuditLogger $audit = null)
    {
    }

    private function assertReady(): void
    {
        if (!MemberService::ready($this->db)) {
            throw new \RuntimeException('ยังใช้แต้มสะสมไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0088) ก่อน');
        }
    }

    /** Every rule with its goods and what it has given so far, newest first. */
    public function all(): array
    {
        if (!MemberService::ready($this->db)) {
            return [];
        }
        $rules = $this->db->query('SELECT r.*, u.full_name AS creator FROM point_redeems r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.active DESC, r.id DESC')->fetchAll();
        if (!$rules) {
            return [];
        }
        $items = $this->db->query(
            'SELECT i.redeem_id, i.product_id, i.sale_group_id, COALESCE(p.name, g.name) AS name, COALESCE(p.sale_price, g.sale_price) AS sale_price,
                    p.barcode, p.consignor_id, p.qty_stock,
                    (SELECT COUNT(*) FROM products m WHERE g.id IS NOT NULL AND m.sale_group_id = g.id) AS members,
                    (SELECT COALESCE(SUM(GREATEST(m.qty_stock, 0)), 0) FROM products m WHERE g.id IS NOT NULL AND m.sale_group_id = g.id) AS group_stock
             FROM point_redeem_items i LEFT JOIN products p ON p.id = i.product_id LEFT JOIN sale_groups g ON g.id = i.sale_group_id ORDER BY i.id'
        )->fetchAll();
        $byRule = [];
        foreach ($items as $it) {
            $isGroup = $it['sale_group_id'] !== null;
            $byRule[(int) $it['redeem_id']][] = [
                'product_id' => $isGroup ? null : (int) $it['product_id'],
                'sale_group_id' => $isGroup ? (int) $it['sale_group_id'] : null,
                'name' => $it['name'] ?? '(ไม่พบสินค้า — ถูกลบแล้ว)',
                'sale_price' => $it['sale_price'] !== null ? (float) $it['sale_price'] : null,
                'barcode' => $it['barcode'], 'consigned' => !$isGroup && $it['consignor_id'] !== null,
                'qty_stock' => $isGroup ? (int) $it['group_stock'] : (int) $it['qty_stock'],
                'members' => $isGroup ? (int) $it['members'] : null,
            ];
        }
        $costs = $this->costs(
            array_values(array_filter(array_map(fn ($it) => $it['product_id'] !== null ? (int) $it['product_id'] : null, $items))),
            array_values(array_filter(array_map(fn ($it) => $it['sale_group_id'] !== null ? (int) $it['sale_group_id'] : null, $items)))
        );
        foreach ($byRule as &$list) {
            foreach ($list as &$it) {
                $it['cost'] = $costs[$it['sale_group_id'] ? 'g' . $it['sale_group_id'] : 'p' . $it['product_id']] ?? null;
            }
            unset($it);
        }
        unset($list);
        $used = [];
        foreach ($this->db->query("SELECT redeem_id, COUNT(DISTINCT sale_id) AS bills, SUM(points) AS points, SUM(discount) AS discount FROM member_point_holds WHERE status = 'used' GROUP BY redeem_id")->fetchAll() as $u) {
            $used[(int) $u['redeem_id']] = $u;
        }
        $now = date('Y-m-d H:i:s');
        return array_map(function ($r) use ($byRule, $used, $now) {
            $id = (int) $r['id'];
            return [
                'id' => $id, 'name' => $r['name'], 'points' => (int) $r['points'], 'baht' => (float) $r['baht'],
                'max_discount' => $r['max_discount'] !== null ? (float) $r['max_discount'] : null,
                'starts_on' => $r['starts_on'], 'ends_on' => $r['ends_on'], 'active' => (bool) $r['active'], 'note' => $r['note'],
                'creator' => $r['creator'], 'items' => $byRule[$id] ?? [],
                'status' => !$r['active'] ? 'off' : ($r['starts_on'] && $r['starts_on'] > $now ? 'upcoming' : ($r['ends_on'] && $r['ends_on'] < $now ? 'ended' : 'on')),
                'used_bills' => (int) ($used[$id]['bills'] ?? 0), 'used_points' => (int) ($used[$id]['points'] ?? 0),
                'used_discount' => round((float) ($used[$id]['discount'] ?? 0), 2),
            ];
        }, $rules);
    }

    /** POST /point-redeems (id = null) or PUT /point-redeems/{id}: {name, points, baht, max_discount?, starts_on?, ends_on?, active?, note?, items} */
    public function save(?int $id, array $in, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 200);
        if ($name === '') {
            throw new \RuntimeException('กรุณาตั้งชื่อรายการใช้แต้ม');
        }
        $points = (int) ($in['points'] ?? 0);
        $baht = round((float) ($in['baht'] ?? 0), 2);
        if ($points < 1 || $points > 100000) {
            throw new \RuntimeException('จำนวนแต้มต่อขั้นต้องเป็นจำนวนเต็ม 1 ขึ้นไป');
        }
        if ($baht <= 0 || $baht > 100000) {
            throw new \RuntimeException('ส่วนลดต่อขั้นต้องมากกว่า 0 บาท');
        }
        $max = $in['max_discount'] ?? null;
        $max = $max === null || $max === '' ? null : round((float) $max, 2);
        if ($max !== null && $max < $baht) {
            throw new \RuntimeException('ส่วนลดสูงสุดต่อชิ้นต้องไม่น้อยกว่าส่วนลดต่อขั้น (฿' . number_format($baht, 2) . ')');
        }
        $date = function ($v, string $label, bool $end): ?string {
            $v = trim(str_replace('T', ' ', (string) ($v ?? '')));
            if ($v === '') {
                return null;
            }
            foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d'] as $f) {
                $d = \DateTimeImmutable::createFromFormat($f, $v);
                if ($d && $d->format(substr($f, 1)) === $v) {
                    if ($f === '!Y-m-d' && $end) {
                        $d = $d->setTime(23, 59, 59);
                    } elseif ($f === '!Y-m-d H:i' && $end) {
                        $d = $d->setTime((int) $d->format('H'), (int) $d->format('i'), 59);
                    }
                    return $d->format('Y-m-d H:i:s');
                }
            }
            throw new \RuntimeException("{$label}ไม่ถูกต้อง");
        };
        $starts = $date($in['starts_on'] ?? null, 'วันเวลาเริ่ม', false);
        $ends = $date($in['ends_on'] ?? null, 'วันเวลาสิ้นสุด', true);
        if ($starts && $ends && $ends < $starts) {
            throw new \RuntimeException('วันเวลาสิ้นสุดต้องไม่ก่อนวันเวลาเริ่ม');
        }
        $active = !array_key_exists('active', $in) || !empty($in['active']);
        $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 500) ?: null;
        $items = $this->cleanItems((array) ($in['items'] ?? []));

        $this->db->beginTransaction();
        try {
            $before = null;
            if ($id === null) {
                $this->db->prepare(
                    'INSERT INTO point_redeems (name, points, baht, max_discount, starts_on, ends_on, active, note, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                )->execute([$name, $points, $baht, $max, $starts, $ends, (int) $active, $note, $userId]);
                $id = (int) $this->db->lastInsertId();
            } else {
                $stmt = $this->db->prepare('SELECT * FROM point_redeems WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $before = $stmt->fetch();
                if (!$before) {
                    throw new \RuntimeException('ไม่พบรายการใช้แต้มนี้');
                }
                $this->db->prepare('UPDATE point_redeems SET name = ?, points = ?, baht = ?, max_discount = ?, starts_on = ?, ends_on = ?, active = ?, note = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$name, $points, $baht, $max, $starts, $ends, (int) $active, $note, $id]);
                $this->db->prepare('DELETE FROM point_redeem_items WHERE redeem_id = ?')->execute([$id]);
            }
            $ins = $this->db->prepare('INSERT INTO point_redeem_items (redeem_id, product_id, sale_group_id) VALUES (?, ?, ?)');
            foreach ($items as $it) {
                $ins->execute([$id, $it['product_id'], $it['sale_group_id']]);
            }
            $this->audit?->log('point_redeems', $id, $before ? 'update' : 'create', $before, [
                'name' => $name, 'points' => $points, 'baht' => $baht, 'max_discount' => $max, 'starts_on' => $starts, 'ends_on' => $ends,
                'active' => $active, 'note' => $note, 'items' => $items,
            ], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['id' => $id, 'warnings' => $this->belowCost($items, $max)];
    }

    /**
     * What one piece costs the store: the purchase cost of its own goods, or the consignor's share of a
     * consigned product (the store bears any discount, as with promotions). A sale group: its dearest member.
     * @return array<string,float> 'p12' / 'g3' => cost per piece
     */
    public function costs(array $pids, array $gids): array
    {
        $out = [];
        $piece = "CASE WHEN p.consignor_id IS NULL THEN p.cost_price
                  ELSE p.sale_price - p.fee_per_unit - IF(p.rounding_beneficiary = 'store', p.round_per_unit, 0) END";
        if ($pids) {
            $stmt = $this->db->prepare("SELECT p.id, {$piece} AS cost FROM products p WHERE p.id IN (" . implode(',', array_fill(0, count($pids), '?')) . ')');
            $stmt->execute(array_values(array_unique($pids)));
            foreach ($stmt->fetchAll() as $r) {
                $out['p' . $r['id']] = round(max(0.0, (float) $r['cost']), 2);
            }
        }
        if ($gids && SaleGroupService::ready($this->db)) {
            $stmt = $this->db->prepare("SELECT p.sale_group_id, MAX({$piece}) AS cost FROM products p WHERE p.sale_group_id IN (" . implode(',', array_fill(0, count($gids), '?')) . ') GROUP BY p.sale_group_id');
            $stmt->execute(array_values(array_unique($gids)));
            foreach ($stmt->fetchAll() as $r) {
                $out['g' . $r['sale_group_id']] = round(max(0.0, (float) $r['cost']), 2);
            }
        }
        return $out;
    }

    /** GET /point-redeems/items?q= — products and sale groups to add, with their cost per piece. */
    public function searchItems(string $q): array
    {
        $items = (new PromoService($this->db))->searchItems($q);
        $costs = $this->costs(
            array_values(array_filter(array_column($items, 'product_id'))),
            array_values(array_filter(array_column($items, 'sale_group_id')))
        );
        foreach ($items as &$it) {
            $it['cost'] = $costs[$it['sale_group_id'] ? 'g' . $it['sale_group_id'] : 'p' . $it['product_id']] ?? null;
        }
        unset($it);
        return $items;
    }

    /** Goods whose price after the most points can take off falls below what they cost the store. */
    private function belowCost(array $items, ?float $max): array
    {
        $costs = $this->costs(
            array_values(array_filter(array_column($items, 'product_id'))),
            array_values(array_filter(array_column($items, 'sale_group_id')))
        );
        $warnings = [];
        foreach ($items as $it) {
            $key = $it['sale_group_id'] ? 'g' . $it['sale_group_id'] : 'p' . $it['product_id'];
            $stmt = $it['sale_group_id']
                ? $this->db->prepare('SELECT name, sale_price FROM sale_groups WHERE id = ?')
                : $this->db->prepare('SELECT name, sale_price FROM products WHERE id = ?');
            $stmt->execute([$it['sale_group_id'] ?: $it['product_id']]);
            $p = $stmt->fetch();
            if (!$p || !isset($costs[$key])) {
                continue;
            }
            $lowest = max(0.0, (float) $p['sale_price'] - ($max ?? (float) $p['sale_price']));
            if ($lowest < $costs[$key] - 0.004) {
                $warnings[] = "{$p['name']}: ราคาหลังใช้แต้มต่ำสุด ฿" . number_format($lowest, 2) . ' ต่ำกว่าต้นทุน ฿' . number_format($costs[$key], 2)
                    . ' (ขาดทุนสูงสุด ฿' . number_format($costs[$key] - $lowest, 2) . ' ต่อชิ้น)';
            }
        }
        return $warnings;
    }

    private function cleanItems(array $in): array
    {
        $items = [];
        foreach ($in as $it) {
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
            throw new \RuntimeException('เพิ่มสินค้าที่ร่วมรายการอย่างน้อย 1 รายการ');
        }
        $pids = array_values(array_filter(array_column($items, 'product_id')));
        if ($pids) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM products WHERE id IN (' . implode(',', array_fill(0, count($pids), '?')) . ')');
            $stmt->execute($pids);
            if ((int) $stmt->fetchColumn() !== count($pids)) {
                throw new \RuntimeException('ไม่พบสินค้าบางรายการ กรุณารีเฟรช');
            }
        }
        $gids = array_values(array_filter(array_column($items, 'sale_group_id')));
        if ($gids) {
            if (!SaleGroupService::ready($this->db)) {
                throw new \RuntimeException('ยังไม่มีกลุ่มขายหน้า POS (migration 0084)');
            }
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM sale_groups WHERE id IN (' . implode(',', array_fill(0, count($gids), '?')) . ')');
            $stmt->execute($gids);
            if ((int) $stmt->fetchColumn() !== count($gids)) {
                throw new \RuntimeException('ไม่พบกลุ่มขายบางกลุ่ม กรุณารีเฟรช');
            }
        }
        return array_values($items);
    }

    public function setActive(int $id, bool $active, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $stmt = $this->db->prepare('SELECT active FROM point_redeems WHERE id = ?');
        $stmt->execute([$id]);
        $was = $stmt->fetchColumn();
        if ($was === false) {
            throw new \RuntimeException('ไม่พบรายการใช้แต้มนี้');
        }
        $this->db->prepare('UPDATE point_redeems SET active = ?, updated_at = NOW() WHERE id = ?')->execute([(int) $active, $id]);
        $this->audit?->log('point_redeems', $id, 'update', ['active' => (bool) $was], ['active' => $active], $userId, $ip);
        return ['id' => $id, 'active' => $active];
    }

    /** Bills that used it keep their discount; points held for it right now stay valid until used or given back. */
    public function delete(int $id, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $stmt = $this->db->prepare('SELECT * FROM point_redeems WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) {
            throw new \RuntimeException('ไม่พบรายการใช้แต้มนี้');
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM point_redeem_items WHERE redeem_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM point_redeems WHERE id = ?')->execute([$id]);
            $this->audit?->log('point_redeems', $id, 'delete', $r, null, $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['id' => $id];
    }

    /**
     * Rules running at $at (or, with $upcoming, also those not started yet: the Android POS checks the
     * times itself), oldest first, each with its item keys ('p12' / 'g3').
     */
    public function running(string $at, bool $upcoming = false): array
    {
        if (!MemberService::ready($this->db)) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM point_redeems WHERE active = 1' . ($upcoming ? '' : ' AND (starts_on IS NULL OR starts_on <= ?)') . ' AND (ends_on IS NULL OR ends_on >= ?) ORDER BY id'
        );
        $stmt->execute($upcoming ? [$at] : [$at, $at]);
        $rules = $stmt->fetchAll();
        if (!$rules) {
            return [];
        }
        $ids = array_column($rules, 'id');
        $stmt = $this->db->prepare('SELECT redeem_id, product_id, sale_group_id FROM point_redeem_items WHERE redeem_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $keys = [];
        foreach ($stmt->fetchAll() as $it) {
            $keys[(int) $it['redeem_id']][] = $it['sale_group_id'] !== null ? 'g' . $it['sale_group_id'] : 'p' . $it['product_id'];
        }
        return array_map(fn ($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'points' => (int) $r['points'], 'baht' => (float) $r['baht'],
            'max_discount' => $r['max_discount'] !== null ? (float) $r['max_discount'] : null,
            'starts_on' => $r['starts_on'], 'ends_on' => $r['ends_on'], 'keys' => $keys[(int) $r['id']] ?? [],
        ], $rules);
    }

    /**
     * The rule for a POS item now: a product (also by the sale group it is sold in, as the Android POS
     * sells a group's members one by one) or a whole sale group.
     */
    public function ruleFor(?int $productId, ?int $groupId, string $at): ?array
    {
        $keys = [];
        if ($groupId) {
            $keys[] = 'g' . $groupId;
        }
        if ($productId) {
            $keys[] = 'p' . $productId;
            if (SaleGroupService::ready($this->db)) {
                $stmt = $this->db->prepare('SELECT sale_group_id FROM products WHERE id = ?');
                $stmt->execute([$productId]);
                $g = $stmt->fetchColumn();
                if ($g) {
                    $keys[] = 'g' . $g;
                }
            }
        }
        foreach ($this->running($at) as $r) {
            if (array_intersect($keys, $r['keys'])) {
                return $r;
            }
        }
        return null;
    }

    /** The Android POS: running and upcoming rules, with the products of each sale group they name. */
    public function deviceRules(): array
    {
        $rules = $this->running(date('Y-m-d H:i:s'), true);
        $gids = [];
        foreach ($rules as $r) {
            foreach ($r['keys'] as $k) {
                if ($k[0] === 'g') {
                    $gids[(int) substr($k, 1)] = true;
                }
            }
        }
        $groups = [];
        if ($gids && SaleGroupService::ready($this->db)) {
            $ids = array_keys($gids);
            $stmt = $this->db->prepare('SELECT id, sale_group_id FROM products WHERE sale_group_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $groups[(string) $row['sale_group_id']][] = (int) $row['id'];
            }
        }
        return ['rules' => $rules, 'groups' => (object) $groups];
    }
}
