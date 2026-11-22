<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Db;
use PDO;

/**
 * Store members and their points (migration 0088).
 *
 * Every student and staff member in the RVC-ONE directory (oa_users) is a member without signing up.
 * The POS names the buyer by national ID, student code or any phone number from RMS; a sale with no
 * buyer is a general customer (ลูกค้าทั่วไป) and earns nothing. A buyer earns 1 point for every
 * member_baht_per_point baht paid (fractions dropped), and member_welcome_points on their first purchase.
 *
 * Points come off goods set up in point_redeems (PointRedeemService). "ใช้แต้ม" on a cart line holds
 * the points at once (member_point_holds) so they cannot be spent twice; the sale uses the hold,
 * and a cart that is cleared or changed gives the points back.
 */
class MemberService
{
    public const KINDS = [
        'welcome' => 'แต้มสมาชิกซื้อครั้งแรก', 'earn' => 'ได้แต้มจากการซื้อ', 'redeem' => 'ใช้แต้มเป็นส่วนลด',
        'survey' => 'ตอบแบบสอบถาม', 'adjust' => 'ปรับแต้มโดยผู้ดูแล', 'refund' => 'คืนแต้ม',
    ];

    public function __construct(private PDO $db, private ?AuditLogger $audit = null)
    {
    }

    public static function ready(PDO $db): bool
    {
        return Db::hasColumn($db, 'sales', 'member_id');
    }

    private function assertReady(): void
    {
        if (!self::ready($this->db)) {
            throw new \RuntimeException('ยังใช้ระบบสมาชิกไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0088) ที่เมนู ระบบ ก่อน');
        }
    }

    /** @return array{enabled:bool, baht_per_point:float, welcome_points:int, hold_minutes:int} */
    public function settings(): array
    {
        $s = new SettingsService($this->db);
        return [
            'enabled' => $s->get('member_points_enabled') !== '0',
            'baht_per_point' => max(0.01, (float) $s->get('member_baht_per_point')),
            'welcome_points' => max(0, (int) $s->get('member_welcome_points')),
            'hold_minutes' => max(5, (int) $s->get('member_hold_minutes')),
        ];
    }

    public function saveSettings(array $in, int $userId): array
    {
        $baht = round((float) ($in['baht_per_point'] ?? 0), 2);
        $welcome = (int) ($in['welcome_points'] ?? -1);
        if ($baht < 1 || $baht > 100000) {
            throw new \RuntimeException('ยอดซื้อต่อ 1 แต้มต้องอยู่ระหว่าง 1–100,000 บาท');
        }
        if ($welcome < 0 || $welcome > 100000) {
            throw new \RuntimeException('แต้มเริ่มต้นต้องเป็น 0 ขึ้นไป');
        }
        (new SettingsService($this->db))->update([
            'member_points_enabled' => !empty($in['enabled']) ? '1' : '0',
            'member_baht_per_point' => rtrim(rtrim(number_format($baht, 2, '.', ''), '0'), '.'),
            'member_welcome_points' => (string) $welcome,
        ], $userId);
        return $this->settings();
    }

    // ---------- Finding a member ----------

    /** A valid Thai national ID: 13 digits with its check digit. */
    public static function isNationalId(string $s): bool
    {
        if (!preg_match('/^\d{13}$/', $s)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $s[$i] * (13 - $i);
        }
        return (11 - $sum % 11) % 10 === (int) $s[12];
    }

    /** What the POS search box may hold besides a barcode: a national ID or a 10-digit phone number. */
    public static function refKind(string $s): ?string
    {
        if (self::isNationalId($s)) {
            return 'national_id';
        }
        return preg_match('/^0\d{9}$/', $s) ? 'phone' : null;
    }

    public static function maskNationalId(?string $nid): ?string
    {
        return $nid && strlen($nid) === 13 ? substr($nid, 0, 1) . '-' . substr($nid, 1, 4) . '-xxxxx-' . substr($nid, 10, 2) . '-' . substr($nid, 12) : null;
    }

    public static function maskPhone(?string $p): ?string
    {
        return $p && strlen($p) >= 9 ? substr($p, 0, 3) . str_repeat('x', strlen($p) - 6) . substr($p, -3) : null;
    }

    /**
     * People matching what the cashier typed: the exact national ID, student code or phone number,
     * else (letters) a part of the name. Suspended accounts are left out.
     * @return array<int,array> [{oa_user_id, name, user_type, department, student_code, national_id_masked, matched_by}]
     */
    public function search(string $q, int $limit = 10): array
    {
        $this->assertReady();
        $q = trim($q);
        $digits = preg_replace('/[\s-]/', '', $q);
        if ($q === '') {
            return [];
        }
        $rows = [];
        if (preg_match('/^\d{4,15}$/', $digits)) {
            $stmt = $this->db->prepare(
                "SELECT o.*, 'national_id' AS matched_by FROM oa_users o WHERE o.national_id = ? AND o.status = 'active'
                 UNION SELECT o.*, 'phone' FROM oa_users o JOIN oa_user_phones ph ON ph.oa_user_id = o.oa_user_id WHERE ph.phone = ? AND o.status = 'active'
                 UNION SELECT o.*, 'student_code' FROM oa_users o WHERE o.student_code = ? AND o.status = 'active'
                 LIMIT " . max(1, $limit)
            );
            $stmt->execute([$digits, $digits, $q]);
            $rows = $stmt->fetchAll();
        } elseif (mb_strlen($q) >= 2) {
            $stmt = $this->db->prepare(
                "SELECT o.*, IF(o.student_code = ?, 'student_code', 'name') AS matched_by FROM oa_users o
                 WHERE o.status = 'active' AND (o.student_code = ? OR CONCAT(o.first_name, ' ', o.last_name) LIKE ?)
                 ORDER BY o.student_code = ? DESC, o.first_name, o.last_name LIMIT " . max(1, $limit)
            );
            $stmt->execute([$q, $q, '%' . $q . '%', $q]);
            $rows = $stmt->fetchAll();
        }
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            if (isset($seen[$r['oa_user_id']])) {
                continue;
            }
            $seen[$r['oa_user_id']] = true;
            $out[] = self::personOut($r) + ['matched_by' => $r['matched_by']];
        }
        return $out;
    }

    public static function personOut(array $o): array
    {
        return [
            'oa_user_id' => (int) $o['oa_user_id'],
            'name' => trim($o['first_name'] . ' ' . $o['last_name']),
            'user_type' => $o['user_type'] === 'student' ? 'student' : 'staff',
            'type_label' => $o['user_type'] === 'student' ? 'นักเรียน/นักศึกษา' : 'ครูและบุคลากร',
            'department' => $o['department'],
            'student_code' => $o['student_code'] ?? null,
            'national_id_masked' => self::maskNationalId($o['national_id'] ?? null),
        ];
    }

    /** The points account of an RVC-ONE person, opened on first use. Keyed by national ID when RVC-ONE sent it. */
    public function ensure(int $oaUserId): int
    {
        $this->assertReady();
        $stmt = $this->db->prepare('SELECT oa_user_id, national_id, status FROM oa_users WHERE oa_user_id = ?');
        $stmt->execute([$oaUserId]);
        $o = $stmt->fetch();
        if (!$o) {
            throw new \RuntimeException('ไม่พบสมาชิกในทะเบียน RVC-ONE');
        }
        $nid = $o['national_id'] ?: null;
        $find = $this->db->prepare('SELECT id, national_id, oa_user_id FROM members WHERE oa_user_id = ?' . ($nid ? ' OR national_id = ?' : '') . ' ORDER BY national_id = ? DESC LIMIT 1');
        $find->execute($nid ? [$oaUserId, $nid, $nid] : [$oaUserId, '']);
        $m = $find->fetch();
        if ($m) {
            if ($nid && $m['national_id'] === null) {
                $this->db->prepare('UPDATE members SET national_id = ? WHERE id = ?')->execute([$nid, $m['id']]);
            }
            return (int) $m['id'];
        }
        try {
            $this->db->prepare('INSERT INTO members (national_id, oa_user_id, points, created_at) VALUES (?, ?, 0, NOW())')->execute([$nid, $oaUserId]);
            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
            $find->execute($nid ? [$oaUserId, $nid, $nid] : [$oaUserId, '']);
            return (int) $find->fetchColumn();
        }
    }

    public function member(int $memberId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, o.first_name, o.last_name, o.user_type, o.department, o.student_code, o.national_id AS oa_national_id, o.status
             FROM members m LEFT JOIN oa_users o ON o.oa_user_id = m.oa_user_id WHERE m.id = ?'
        );
        $stmt->execute([$memberId]);
        return $stmt->fetch() ?: null;
    }

    /** What the POS shows of the buyer: name, points and what they can still spend, and surveys open to them. */
    public function card(int $memberId): array
    {
        $this->releaseExpired();
        $m = $this->member($memberId);
        if (!$m) {
            throw new \RuntimeException('ไม่พบสมาชิก');
        }
        $held = $this->heldPoints($memberId);
        $settings = $this->settings();
        return [
            'member_id' => (int) $m['id'], 'oa_user_id' => $m['oa_user_id'] !== null ? (int) $m['oa_user_id'] : null,
            'name' => trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: 'สมาชิก',
            'user_type' => $m['user_type'] === 'student' ? 'student' : 'staff',
            'type_label' => $m['user_type'] === 'student' ? 'นักเรียน/นักศึกษา' : 'ครูและบุคลากร',
            'department' => $m['department'], 'student_code' => $m['student_code'],
            'national_id_masked' => self::maskNationalId($m['national_id']),
            'points' => (int) $m['points'], 'held' => $held, 'available' => (int) $m['points'] - $held,
            'first_purchase' => !$m['welcome_given'] && $settings['enabled'] ? $settings['welcome_points'] : 0,
            'baht_per_point' => $settings['baht_per_point'], 'earning' => $settings['enabled'],
            'surveys' => (new SurveyService($this->db))->openFor($memberId),
        ];
    }

    /** A person picked at the POS (by oa_user_id), with their points account opened if new. */
    public function cardFor(int $oaUserId): array
    {
        return $this->card($this->ensure($oaUserId));
    }

    // ---------- Points ----------

    /**
     * Changes a balance by $points (negative to take off) and writes it in the member's history.
     * Runs inside the caller's transaction when there is one. Returns the new balance.
     */
    public function credit(int $memberId, string $kind, int $points, array $ref = [], ?int $userId = null): int
    {
        if ($points === 0) {
            return (int) $this->db->query('SELECT points FROM members WHERE id = ' . $memberId)->fetchColumn();
        }
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare('SELECT points FROM members WHERE id = ? FOR UPDATE');
            $stmt->execute([$memberId]);
            $balance = $stmt->fetchColumn();
            if ($balance === false) {
                throw new \RuntimeException('ไม่พบสมาชิก');
            }
            $balance = (int) $balance + $points;
            $this->db->prepare('UPDATE members SET points = ? WHERE id = ?')->execute([$balance, $memberId]);
            $this->db->prepare(
                'INSERT INTO member_points (member_id, kind, points, balance_after, sale_id, survey_id, note, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $memberId, $kind, $points, $balance, $ref['sale_id'] ?? null, $ref['survey_id'] ?? null,
                isset($ref['note']) ? mb_substr((string) $ref['note'], 0, 300) : null, $userId, $ref['at'] ?? date('Y-m-d H:i:s'),
            ]);
            if ($own) {
                $this->db->commit();
            }
            return $balance;
        } catch (\Throwable $e) {
            if ($own) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Admin / manager: adds or takes off points by hand, with a reason. */
    public function adjust(int $memberId, int $points, string $note, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $note = trim($note);
        if ($points === 0) {
            throw new \RuntimeException('กรุณาระบุจำนวนแต้มที่ปรับ (บวกเพื่อเพิ่ม ลบเพื่อหัก)');
        }
        if ($note === '') {
            throw new \RuntimeException('กรุณาระบุเหตุผลในการปรับแต้ม');
        }
        $this->db->beginTransaction();
        try {
            $m = $this->member($memberId);
            if (!$m) {
                throw new \RuntimeException('ไม่พบสมาชิก');
            }
            if ($points < 0 && (int) $m['points'] - $this->heldPoints($memberId) + $points < 0) {
                throw new \RuntimeException('หักได้ไม่เกินแต้มที่ใช้ได้ (' . ((int) $m['points'] - $this->heldPoints($memberId)) . ' แต้ม)');
            }
            $balance = $this->credit($memberId, 'adjust', $points, ['note' => $note], $userId);
            $this->audit?->log('members', $memberId, 'update', ['points' => (int) $m['points']], ['points' => $balance, 'adjust' => $points, 'note' => $note], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->card($memberId);
    }

    // ---------- Holding points for a cart line ----------

    /** Holds left behind (a browser closed mid-sale) give their points back once they run out. */
    public function releaseExpired(): void
    {
        $this->db->exec("UPDATE member_point_holds SET status = 'released', closed_at = NOW() WHERE status = 'held' AND expires_at < NOW()");
    }

    public function heldPoints(int $memberId): int
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(points), 0) FROM member_point_holds WHERE member_id = ? AND status = 'held' AND expires_at >= NOW()");
        $stmt->execute([$memberId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * "ใช้แต้ม" on a cart line: as many points as the member can spend on it, up to the most the
     * redeem rule takes off its pieces (and never more than the line is worth). The points are held
     * until the sale goes through or the cashier takes them off.
     * @param array{member_id:int, product_id?:int, group_id?:int, qty:int, unit_price?:float, line_value?:float, points?:int} $in
     *        line_value: what the line comes to after a bundle promotion (the POS knows it); points: spend at most this many
     */
    public function hold(array $in, int $userId, ?int $deviceId = null): array
    {
        $this->assertReady();
        $memberId = (int) ($in['member_id'] ?? 0);
        $qty = (int) ($in['qty'] ?? 0);
        $pid = (int) ($in['product_id'] ?? 0);
        $gid = (int) ($in['group_id'] ?? 0);
        if ($memberId <= 0 || $qty <= 0 || ($pid <= 0 && $gid <= 0)) {
            throw new \RuntimeException('ข้อมูลการใช้แต้มไม่ครบ');
        }
        $redeems = new PointRedeemService($this->db);
        $rule = $redeems->ruleFor($gid > 0 ? null : $pid, $gid > 0 ? $gid : null, date('Y-m-d H:i:s'));
        if (!$rule) {
            throw new \RuntimeException('สินค้านี้ไม่ได้ร่วมรายการใช้แต้มเป็นส่วนลด (หรือหมดเขตแล้ว)');
        }
        if ($gid > 0) {
            $stmt = $this->db->prepare('SELECT sale_price FROM sale_groups WHERE id = ?');
            $stmt->execute([$gid]);
        } else {
            $stmt = $this->db->prepare('SELECT sale_price FROM products WHERE id = ?');
            $stmt->execute([$pid]);
        }
        $price = $stmt->fetchColumn();
        if ($price === false) {
            throw new \RuntimeException('ไม่พบสินค้า');
        }
        $price = isset($in['unit_price']) && is_numeric($in['unit_price']) && $gid <= 0 ? (float) $in['unit_price'] : (float) $price;
        $cap = round($price * $qty, 2);
        if (isset($in['line_value']) && is_numeric($in['line_value'])) {
            $cap = min($cap, max(0.0, round((float) $in['line_value'], 2)));
        }
        if ($rule['max_discount'] !== null) {
            $cap = min($cap, round($rule['max_discount'] * $qty, 2));
        }

        $this->db->beginTransaction();
        try {
            // One member's holds one at a time, so two tills cannot both spend the same points.
            $stmt = $this->db->prepare('SELECT points FROM members WHERE id = ? FOR UPDATE');
            $stmt->execute([$memberId]);
            $balance = $stmt->fetchColumn();
            if ($balance === false) {
                throw new \RuntimeException('ไม่พบสมาชิก');
            }
            $this->releaseExpired();
            $available = (int) $balance - $this->heldPoints($memberId);
            if (isset($in['points']) && is_numeric($in['points']) && (int) $in['points'] > 0) {
                $available = min($available, (int) $in['points']);
            }
            $steps = (int) min(floor($available / $rule['points']), floor(round($cap / $rule['baht'], 6)));
            if ($steps <= 0) {
                $this->db->rollBack();
                $need = $rule['points'];
                throw new \RuntimeException($cap < $rule['baht']
                    ? 'ราคาสินค้ารายการนี้ต่ำกว่าส่วนลดขั้นต่ำของการใช้แต้ม'
                    : "แต้มไม่พอ (ใช้ได้ {$available} แต้ม ต้องใช้อย่างน้อย {$need} แต้ม)");
            }
            $points = $steps * $rule['points'];
            $discount = round($steps * $rule['baht'], 2);
            $minutes = $this->settings()['hold_minutes'];
            $this->db->prepare(
                "INSERT INTO member_point_holds (member_id, redeem_id, product_id, sale_group_id, qty, points, discount, status, device_id, created_by, created_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'held', ?, ?, NOW(), NOW() + INTERVAL {$minutes} MINUTE)"
            )->execute([$memberId, $rule['id'], $pid ?: null, $gid ?: null, $qty, $points, $discount, $deviceId, $userId]);
            $id = (int) $this->db->lastInsertId();
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return [
            'hold_id' => $id, 'points' => $points, 'discount' => $discount, 'redeem_id' => $rule['id'], 'redeem_name' => $rule['name'],
            'member' => $this->card($memberId),
        ];
    }

    /** Gives held points back (taken off a line, the cart cleared, another buyer named). */
    public function release(int $memberId, array $holdIds): array
    {
        $this->assertReady();
        $ids = array_values(array_filter(array_map('intval', $holdIds), fn ($v) => $v > 0));
        if ($ids) {
            $this->db->prepare(
                "UPDATE member_point_holds SET status = 'released', closed_at = NOW()
                 WHERE member_id = ? AND status = 'held' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'
            )->execute([$memberId, ...$ids]);
        }
        return $this->card($memberId);
    }

    // ---------- Sales ----------

    /**
     * Before SaleService books a sale: each line that used points (points_hold_id) gets its discount
     * (points_discount) from the hold. The discount never runs past the line's price after a bundle promotion.
     * Online, a hold that is gone (expired, given back) stops the sale for the cashier to press "ใช้แต้ม" again.
     * Offline (the Android POS) the customer has paid: the discount stands as the device gave it.
     * @return array{0:array, 1:array, 2:string[]} [items, holds by line index, warnings]
     */
    public function applyToSale(array $items, int $memberId, bool $offline): array
    {
        $holds = [];
        $warnings = [];
        foreach ($items as $i => $line) {
            $hid = (int) ($line['points_hold_id'] ?? 0);
            if ($hid <= 0) {
                unset($items[$i]['points_discount']);
                continue;
            }
            $stmt = $this->db->prepare('SELECT * FROM member_point_holds WHERE id = ? FOR UPDATE');
            $stmt->execute([$hid]);
            $h = $stmt->fetch();
            $valid = $h && (int) $h['member_id'] === $memberId && $h['status'] === 'held';
            if (!$valid && !$offline) {
                throw new PointsHoldException('สิทธิ์ใช้แต้มของสินค้าบางรายการหมดอายุหรือถูกยกเลิกแล้ว — กด "ใช้แต้ม" อีกครั้งแล้วชำระเงินใหม่');
            }
            $sent = isset($line['points_discount']) && is_numeric($line['points_discount']) ? round((float) $line['points_discount'], 2) : null;
            $discount = $valid ? (float) $h['discount'] : (float) ($sent ?? 0);
            $points = $valid ? (int) $h['points'] : (int) ($line['points_used'] ?? 0);
            if (!$valid) {
                $warnings[] = 'สิทธิ์ใช้แต้มที่อุปกรณ์จองไว้หมดอายุก่อนส่งข้อมูล หักแต้มตามที่ขายจริง ' . $points . ' แต้ม';
            }
            if ($discount <= 0) {
                unset($items[$i]['points_discount']);
                continue;
            }
            $items[$i]['points_discount'] = $discount;
            $holds[$i] = ['id' => $valid ? $hid : null, 'points' => $points, 'discount' => $discount];
        }
        return [$items, $holds, $warnings];
    }

    /**
     * After the sale lines are written: the points it used and earned, and the welcome points of a
     * member's first purchase. $used = [line index => {id, points, discount}] with discount = what
     * the line really took off (it may be less than held when a promotion took the rest).
     */
    public function settleSale(int $saleId, int $memberId, float $paid, array $used, int $userId, string $saleAt, ?string $docNo = null): array
    {
        $settings = $this->settings();
        $stmt = $this->db->prepare('SELECT * FROM members WHERE id = ? FOR UPDATE');
        $stmt->execute([$memberId]);
        $m = $stmt->fetch();
        if (!$m) {
            throw new \RuntimeException('ไม่พบสมาชิก');
        }
        $pointsUsed = 0;
        $discount = 0.0;
        foreach ($used as $u) {
            $pts = (int) $u['points'];
            // A promotion took part of the line: only the points worth what was taken off are spent.
            if ($u['held_discount'] > 0 && $u['discount'] < $u['held_discount'] - 0.004) {
                $pts = (int) ceil(round($pts * $u['discount'] / $u['held_discount'], 6));
            }
            $pointsUsed += $pts;
            $discount += $u['discount'];
            if ($u['id']) {
                $this->db->prepare("UPDATE member_point_holds SET status = 'used', sale_id = ?, points = ?, discount = ?, closed_at = NOW() WHERE id = ?")
                    ->execute([$saleId, $pts, $u['discount'], $u['id']]);
            }
        }
        $ref = ['sale_id' => $saleId, 'at' => $saleAt];
        $label = $docNo ? " {$docNo}" : '';
        if ($pointsUsed > 0) {
            $this->credit($memberId, 'redeem', -$pointsUsed, $ref + ['note' => 'ส่วนลด ฿' . number_format($discount, 2) . $label], $userId);
        }
        $earned = $settings['enabled'] ? (int) floor(round($paid / $settings['baht_per_point'], 6)) : 0;
        if ($earned > 0) {
            $this->credit($memberId, 'earn', $earned, $ref + ['note' => 'ซื้อ ฿' . number_format($paid, 2) . $label], $userId);
        }
        $welcome = 0;
        if (!$m['welcome_given'] && $settings['enabled']) {
            $welcome = $settings['welcome_points'];
            if ($welcome > 0) {
                $this->credit($memberId, 'welcome', $welcome, $ref + ['note' => 'ซื้อครั้งแรก' . $label], $userId);
            }
        }
        $this->db->prepare(
            'UPDATE members SET welcome_given = IF(? = 1, 1, welcome_given), first_purchase_at = COALESCE(first_purchase_at, ?), last_purchase_at = GREATEST(COALESCE(last_purchase_at, ?), ?) WHERE id = ?'
        )->execute([$settings['enabled'] ? 1 : 0, $saleAt, $saleAt, $saleAt, $memberId]);
        $this->db->prepare('UPDATE sales SET member_id = ?, points_earned = ?, points_used = ?, points_discount = ? WHERE id = ?')
            ->execute([$memberId, $earned + $welcome, $pointsUsed, round($discount, 2), $saleId]);
        $balance = (int) $this->db->query('SELECT points FROM members WHERE id = ' . $memberId)->fetchColumn();
        return ['member_id' => $memberId, 'earned' => $earned, 'welcome' => $welcome, 'used' => $pointsUsed, 'discount' => round($discount, 2), 'balance' => $balance];
    }
}
