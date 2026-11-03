<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepo;
use PDO;

/**
 * Android POS devices (pos_devices, migration 0059), paired like a TV with a phone:
 *  1. the app asks for a pairing code (start) and shows it, keeping a secret only it knows;
 *  2. an admin types the code on the อุปกรณ์ POS page and picks the account the device sells as (approve);
 *  3. the app, polling with its secret, receives its device token (status).
 * The token is shown once and only its SHA-256 is kept. Until the app first uses it, polling
 * again issues a fresh one (in case a reply was lost); after that the pairing secret is gone.
 *
 * A device may be shared by several sellers (pos_device_users, migration 0062): each picks
 * their profile on the device, with a PIN when one is set, and the app names who is working
 * in X-Device-User. pos_devices.user_id is the default profile. How often the app sends and
 * fetches in the background is the device's sync level, set from the web page or the app.
 */
class PosDeviceService
{
    public const CODE_MINUTES = 10;
    // No 0/O, 1/I/L: the code is read off a screen and typed by hand.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const MAX_PENDING_PER_IP = 10;

    public const NOT_MIGRATED = 'ยังใช้งานอุปกรณ์ POS ไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0061 และ 0062) ที่หน้าจัดการฐานข้อมูลก่อน';

    public const SYNC_DEFAULT = 'medium';
    /**
     * Background sync levels, in seconds: sales = how long a finished sale may wait before it is
     * sent (0 = right away), catalog = product changes fetched, ping = server check. Whatever the
     * level, the app also sends at once when FLUSH_PENDING sales are waiting, at closing the
     * drawer, when switching profile and when the user taps sync.
     */
    public const SYNC_LEVELS = [
        'realtime' => ['label' => 'ทันที', 'sales' => 0, 'catalog' => 60, 'ping' => 30],
        'high' => ['label' => 'ถี่', 'sales' => 30, 'catalog' => 180, 'ping' => 60],
        'medium' => ['label' => 'ปานกลาง', 'sales' => 120, 'catalog' => 600, 'ping' => 120],
        'low' => ['label' => 'น้อย', 'sales' => 600, 'catalog' => 1800, 'ping' => 300],
        'minimal' => ['label' => 'ประหยัดที่สุด', 'sales' => 1800, 'catalog' => 7200, 'ping' => 900],
    ];
    public const FLUSH_PENDING = 20;

    public function __construct(private PDO $db, private AuditLogger $audit)
    {
    }

    /** True when $e only means migration 0061 has not been run (pos_devices is missing). */
    public static function notMigrated(\Throwable $e): bool
    {
        return $e instanceof \PDOException && in_array($e->getCode(), ['42S02', '42S22'], true);
    }

    /** The levels with a Thai description, for the web page and the app. */
    public static function syncLevels(): array
    {
        $out = [];
        foreach (self::SYNC_LEVELS as $key => $l) {
            $out[] = [
                'key' => $key, 'label' => $l['label'], 'sales_seconds' => $l['sales'], 'catalog_seconds' => $l['catalog'], 'ping_seconds' => $l['ping'],
                'description' => ($l['sales'] ? 'ส่งยอดขายทุก ' . self::span($l['sales']) : 'ส่งยอดขายทันทีที่ขาย')
                    . ' · อัปเดตสินค้าทุก ' . self::span($l['catalog']) . ' · ตรวจเซิร์ฟเวอร์ทุก ' . self::span($l['ping']),
            ];
        }
        return $out;
    }

    /** What the app needs to run the device's level. */
    public static function syncConfig(array $device): array
    {
        $key = isset(self::SYNC_LEVELS[$device['sync_level'] ?? '']) ? $device['sync_level'] : self::SYNC_DEFAULT;
        $l = self::SYNC_LEVELS[$key];
        return [
            'level' => $key, 'label' => $l['label'], 'sales_seconds' => $l['sales'], 'catalog_seconds' => $l['catalog'], 'ping_seconds' => $l['ping'],
            'flush_pending' => self::FLUSH_PENDING, 'changed_at' => $device['sync_level_at'] ?? null, 'changed_by' => $device['sync_level_by'] ?? null,
        ];
    }

    private static function span(int $s): string
    {
        return $s < 60 ? "{$s} วินาที" : ($s < 3600 ? ($s / 60) . ' นาที' : ($s / 3600) . ' ชั่วโมง');
    }

    /** @return array{pairing_id:int, pair_code:string, pair_secret:string, expires_at:string, expires_in:int, poll_seconds:int} */
    public function start(array $info, ?string $ip): array
    {
        $this->db->exec("DELETE FROM pos_devices WHERE status = 'pending' AND pair_expires_at < NOW() - INTERVAL 1 DAY");
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pos_devices WHERE status = 'pending' AND created_ip <=> ? AND pair_expires_at > NOW()");
        $stmt->execute([$ip]);
        if ((int) $stmt->fetchColumn() >= self::MAX_PENDING_PER_IP) {
            throw new \RuntimeException('ขอรหัสจับคู่บ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่');
        }
        $name = $this->clip($info['device_name'] ?? null, 100) ?? 'Android POS';
        $secret = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::CODE_MINUTES * 60);
        $check = $this->db->prepare("SELECT 1 FROM pos_devices WHERE pair_code = ? AND status = 'pending' AND pair_expires_at > NOW()");
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $check->execute([$code]);
        } while ($check->fetchColumn());
        $this->db->prepare(
            "INSERT INTO pos_devices (name, model, platform, app_version, status, pair_code, pair_secret_hash, pair_expires_at, created_ip, created_at)
             VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, NOW())"
        )->execute([
            $name, $this->clip($info['model'] ?? null, 100), $this->clip($info['platform'] ?? null, 50),
            $this->clip($info['app_version'] ?? null, 30), $code, hash('sha256', $secret), $expires, $ip,
        ]);
        return [
            'pairing_id' => (int) $this->db->lastInsertId(), 'pair_code' => $code, 'pair_secret' => $secret,
            'expires_at' => $expires, 'expires_in' => self::CODE_MINUTES * 60, 'poll_seconds' => 3,
        ];
    }

    /**
     * The app polls with its pairing secret.
     * @return array{status:string, token?:string, device?:array}
     *         pending | active (with a token) | expired | revoked
     */
    public function status(int $pairingId, string $secret): array
    {
        $stmt = $this->db->prepare('SELECT * FROM pos_devices WHERE id = ?');
        $stmt->execute([$pairingId]);
        $d = $stmt->fetch();
        if (!$d || $d['pair_secret_hash'] === null || !hash_equals((string) $d['pair_secret_hash'], hash('sha256', $secret))) {
            // Wrong secret, or the token was already taken up by the app.
            return ['status' => 'expired'];
        }
        if ($d['status'] === 'revoked') {
            return ['status' => 'revoked'];
        }
        if ($d['status'] === 'pending') {
            return ['status' => strtotime((string) $d['pair_expires_at']) > time() ? 'pending' : 'expired'];
        }
        $token = 'pd' . $d['id'] . '_' . bin2hex(random_bytes(24));
        $this->db->prepare('UPDATE pos_devices SET token_hash = ? WHERE id = ?')->execute([hash('sha256', $token), $d['id']]);
        return ['status' => 'active', 'token' => $token, 'device' => $this->publicView($this->find((int) $d['id']))];
    }

    /** Admin: pairs the pending device showing $code, for the sellers $userIds (the first is the default profile). */
    public function approve(string $code, string $name, array $userIds, ?string $syncLevel, int $adminId, ?string $ip): array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $stmt = $this->db->prepare("SELECT * FROM pos_devices WHERE pair_code = ? AND status = 'pending' AND pair_expires_at > NOW() ORDER BY id DESC LIMIT 1");
        $stmt->execute([$code]);
        $d = $stmt->fetch();
        if (!$d) {
            throw new \RuntimeException('ไม่พบรหัสจับคู่นี้ หรือรหัสหมดอายุแล้ว ให้กดขอรหัสใหม่บนอุปกรณ์');
        }
        $userIds = $this->assertSellers($userIds);
        $level = $this->level($syncLevel ?: self::SYNC_DEFAULT);
        $name = $this->clip($name, 100) ?? $d['name'];
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "UPDATE pos_devices SET status = 'active', name = ?, user_id = ?, sync_level = ?, sync_level_at = NOW(), sync_level_by = 'web',
                    pair_code = NULL, paired_at = NOW(), paired_by = ? WHERE id = ?"
            )->execute([$name, $userIds[0], $level, $adminId, $d['id']]);
            $this->saveMembers((int) $d['id'], $userIds, $adminId);
            $this->audit->log('pos_devices', (int) $d['id'], 'update', ['status' => 'pending'],
                ['status' => 'active', 'name' => $name, 'user_ids' => $userIds, 'sync_level' => $level, 'model' => $d['model']], $adminId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->adminView($this->find((int) $d['id']));
    }

    public function update(int $id, array $fields, int $adminId, ?string $ip): array
    {
        $d = $this->find($id);
        if (!$d || $d['status'] === 'pending') {
            throw new \RuntimeException('ไม่พบอุปกรณ์');
        }
        $name = $this->clip($fields['name'] ?? null, 100) ?? $d['name'];
        $before = array_column($this->members($id), 'id');
        $userIds = $before;
        if (isset($fields['user_ids']) || isset($fields['user_id'])) {
            $wanted = isset($fields['user_ids']) ? (array) $fields['user_ids'] : [$fields['user_id']];
            // Sellers already on the device stay allowed even if they could not be added today.
            $userIds = $this->assertSellers($wanted, $before);
        }
        $level = isset($fields['sync_level']) ? $this->level((string) $fields['sync_level']) : $d['sync_level'];
        $default = in_array((int) $d['user_id'], $userIds, true) ? (int) $d['user_id'] : $userIds[0];
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE pos_devices SET name = ?, user_id = ? WHERE id = ?')->execute([$name, $default, $id]);
            if ($level !== $d['sync_level']) {
                $this->db->prepare("UPDATE pos_devices SET sync_level = ?, sync_level_at = NOW(), sync_level_by = 'web' WHERE id = ?")->execute([$level, $id]);
            }
            $this->saveMembers($id, $userIds, $adminId);
            $this->audit->log('pos_devices', $id, 'update', ['name' => $d['name'], 'user_ids' => $before, 'sync_level' => $d['sync_level']],
                ['name' => $name, 'user_ids' => $userIds, 'sync_level' => $level], $adminId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->adminView($this->find($id));
    }

    /** Admin: sets (4–6 digits) or clears ('') the PIN a seller types to switch to their profile on the device. */
    public function setPin(int $deviceId, int $userId, string $pin, int $adminId, ?string $ip): void
    {
        $pin = trim($pin);
        if ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) {
            throw new \RuntimeException('PIN ต้องเป็นตัวเลข 4–6 หลัก');
        }
        if (!in_array($userId, array_column($this->members($deviceId), 'id'), true)) {
            throw new \RuntimeException('บัญชีนี้ไม่ได้เป็นผู้ขายของอุปกรณ์นี้');
        }
        $salt = $pin === '' ? null : bin2hex(random_bytes(16));
        $this->db->prepare('UPDATE pos_device_users SET pin_salt = ?, pin_hash = ? WHERE device_id = ? AND user_id = ?')
            ->execute([$salt, $salt ? self::pinHash($salt, $pin) : null, $deviceId, $userId]);
        $this->audit->log('pos_device_users', $deviceId, 'update', null, ['event' => $pin === '' ? 'pin_clear' : 'pin_set', 'user_id' => $userId], $adminId, $ip);
    }

    /** The app checks a PIN offline the same way: lowercase hex SHA-256 of salt + PIN. */
    public static function pinHash(string $salt, string $pin): string
    {
        return hash('sha256', $salt . $pin);
    }

    /** The app changed the level (maybe offline, at $changedAt by its clock): the later change wins. */
    public function setSyncLevelFromApp(array $device, string $level, ?string $changedAt): array
    {
        $level = $this->level($level);
        $at = time();
        if ($changedAt) {
            $t = strtotime($changedAt);
            $at = $t !== false ? min($t, time()) : time();
        }
        $current = $device['sync_level_at'] ? strtotime((string) $device['sync_level_at']) : 0;
        if ($at >= $current && $level !== $device['sync_level']) {
            $this->db->prepare("UPDATE pos_devices SET sync_level = ?, sync_level_at = ?, sync_level_by = 'app' WHERE id = ?")
                ->execute([$level, date('Y-m-d H:i:s', $at), $device['id']]);
        }
        return self::syncConfig($this->find((int) $device['id']));
    }

    /**
     * Sellers of the device, with their account. $withRemoved also gives those taken off it
     * (their offline sales are still booked).
     * @return array<int, array{id:int, username:string, full_name:string, role:string, pin_salt:?string, pin_hash:?string, removed:bool, account:array}>
     */
    public function members(int $deviceId, bool $withRemoved = false): array
    {
        $stmt = $this->db->prepare(
            'SELECT u.*, du.user_id, du.pin_salt, du.pin_hash, du.removed_at
             FROM pos_device_users du JOIN users u ON u.id = du.user_id
             WHERE du.device_id = ?' . ($withRemoved ? '' : ' AND du.removed_at IS NULL') . ' ORDER BY du.id'
        );
        $stmt->execute([$deviceId]);
        return array_map(fn ($r) => [
            'id' => (int) $r['user_id'], 'username' => $r['username'], 'full_name' => $r['full_name'], 'role' => $r['role'],
            'pin_salt' => $r['pin_salt'], 'pin_hash' => $r['pin_hash'], 'removed' => $r['removed_at'] !== null, 'account' => $r,
        ], $stmt->fetchAll());
    }

    /**
     * The account a request (or a sale) of the device works as: $userId, or the default profile.
     * Sales made offline may name a seller since taken off the device ($allowRemoved).
     * @return array{user?:array, removed?:bool, error?:string, code?:string}
     */
    public function seller(array $device, ?int $userId, bool $allowRemoved = false): array
    {
        $userId = $userId ?: (int) $device['user_id'];
        $member = null;
        foreach ($this->members((int) $device['id'], true) as $m) {
            if ($m['id'] === $userId) {
                $member = $m;
            }
        }
        if (!$member || ($member['removed'] && !$allowRemoved)) {
            return ['error' => 'บัญชีนี้ไม่ได้เป็นผู้ขายของอุปกรณ์นี้ ให้เลือกโปรไฟล์ใหม่ (ผู้ดูแลระบบเพิ่มผู้ขายได้ที่หน้าอุปกรณ์ POS)', 'code' => 'user_not_on_device'];
        }
        $user = $member['account'];
        if (!UserRepo::canSignIn($user)) {
            return ['error' => "บัญชี {$user['full_name']} ถูกปิดใช้งาน ให้ผู้ดูแลระบบเลือกผู้ขายใหม่", 'code' => 'user_inactive'];
        }
        // A device always sells as staff, never as the account's consignor side.
        $roles = array_values(array_filter((new UserRepo($this->db))->rolesOf($user), fn ($r) => $r !== UserRepo::CONSIGNOR));
        if (!$roles) {
            return ['error' => "บัญชี {$user['full_name']} ไม่ใช่บัญชีพนักงาน", 'code' => 'user_inactive'];
        }
        unset($user['password_hash'], $user['pin_salt'], $user['pin_hash'], $user['removed_at'], $user['user_id']);
        $user['role'] = $roles[0];
        $user['roles'] = $roles;
        return ['user' => $user, 'removed' => $member['removed']];
    }

    /** Changes when the sellers, their PINs or the sync level do: the app then fetches /me again. */
    public function configHash(array $device): string
    {
        $members = array_map(fn ($m) => [$m['id'], $m['pin_hash'], $m['account']['active'], $m['role'], $m['full_name']], $this->members((int) $device['id']));
        return sha1(json_encode([$members, $device['user_id'], $device['sync_level'], $device['name']]));
    }

    /** The device's token stops working at once; its sales already sent stay. */
    public function revoke(int $id, int $adminId, ?string $ip): void
    {
        $d = $this->find($id);
        if (!$d) {
            throw new \RuntimeException('ไม่พบอุปกรณ์');
        }
        if ($d['status'] === 'pending') {
            $this->db->prepare('DELETE FROM pos_devices WHERE id = ?')->execute([$id]);
            return;
        }
        $this->db->prepare(
            "UPDATE pos_devices SET status = 'revoked', token_hash = NULL, pair_secret_hash = NULL, revoked_at = NOW(), revoked_by = ? WHERE id = ?"
        )->execute([$adminId, $id]);
        $this->audit->log('pos_devices', $id, 'cancel', ['status' => $d['status']], ['status' => 'revoked'], $adminId, $ip);
    }

    /** The active device holding $token, or null. */
    public function byToken(string $token): ?array
    {
        if (!preg_match('/^pd\d+_[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM pos_devices WHERE token_hash = ? AND status = 'active'");
        $stmt->execute([hash('sha256', $token)]);
        return $stmt->fetch() ?: null;
    }

    /** A request with the token: the pairing secret is no longer needed; last seen at most once a minute. */
    public function touch(array $device, ?string $ip, array $info): void
    {
        $version = $this->clip($info['app_version'] ?? null, 30);
        $pending = isset($info['pending']) && is_numeric($info['pending']) ? max(0, (int) $info['pending']) : null;
        $userId = isset($info['user_id']) ? (int) $info['user_id'] : null;
        $stale = $device['last_seen_at'] === null || strtotime((string) $device['last_seen_at']) < time() - 60;
        if (!$stale && $device['pair_secret_hash'] === null && ($version === null || $version === $device['app_version'])
            && ($pending === null || $pending === (int) $device['pending_count']) && ($userId === null || $userId === (int) $device['current_user_id'])) {
            return;
        }
        $this->db->prepare(
            'UPDATE pos_devices SET last_seen_at = NOW(), last_ip = ?, pair_secret_hash = NULL,
                app_version = COALESCE(?, app_version), pending_count = COALESCE(?, pending_count), current_user_id = COALESCE(?, current_user_id) WHERE id = ?'
        )->execute([$ip, $version, $pending, $userId, $device['id']]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT d.*, u.full_name AS user_name, u.username AS user_username, u.role AS user_role, a.full_name AS paired_by_name
             FROM pos_devices d LEFT JOIN users u ON u.id = d.user_id LEFT JOIN users a ON a.id = d.paired_by WHERE d.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Paired and revoked devices, with how many of their sales failed to book. */
    public function all(): array
    {
        $rows = $this->db->query(
            "SELECT d.id, d.name, d.model, d.platform, d.app_version, d.status, d.user_id, d.created_at, d.paired_at,
                    d.last_seen_at, d.last_ip, d.last_sync_at, d.pending_count, d.revoked_at,
                    d.sync_level, d.sync_level_at, d.sync_level_by, d.current_user_id, cu.full_name AS current_user_name,
                    u.full_name AS user_name, u.role AS user_role, a.full_name AS paired_by_name,
                    (SELECT COUNT(*) FROM pos_device_sales s WHERE s.device_id = d.id AND s.status = 'failed') AS failed_count,
                    (SELECT COUNT(*) FROM pos_device_sales s WHERE s.device_id = d.id AND s.status = 'synced') AS synced_count
             FROM pos_devices d
             LEFT JOIN users u ON u.id = d.user_id
             LEFT JOIN users a ON a.id = d.paired_by
             LEFT JOIN users cu ON cu.id = d.current_user_id
             WHERE d.status <> 'pending'
             ORDER BY d.status = 'revoked', d.name, d.id"
        )->fetchAll();
        foreach ($rows as &$r) {
            foreach (['id', 'user_id', 'current_user_id', 'pending_count', 'failed_count', 'synced_count'] as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
            $r['users'] = array_map(fn ($m) => ['id' => $m['id'], 'full_name' => $m['full_name'], 'role' => $m['role'], 'has_pin' => $m['pin_hash'] !== null],
                $this->members($r['id']));
            $r['sync'] = self::syncConfig($r);
            // Counted as online while seen within a little over its server-check interval.
            $r['online_seconds'] = max(300, (int) ceil($r['sync']['ping_seconds'] * 2.5));
        }
        return $rows;
    }

    /** Active accounts that may sell (sales.create) and are not read-only: who a device may sell as. */
    public function sellers(): array
    {
        $users = new UserRepo($this->db);
        $out = [];
        foreach ($users->all() as $u) {
            if (!$u['active'] || ($u['oa_status'] ?? null) === 'suspended' || in_array($u['role'], ['consignor', 'executive'], true)) {
                continue;
            }
            if ($users->hasPermission($u['role'], 'sales.create')) {
                $out[] = ['id' => (int) $u['id'], 'full_name' => $u['full_name'], 'username' => $u['username'], 'role' => $u['role']];
            }
        }
        return $out;
    }

    /** What the app may see of its own record. */
    public function publicView(?array $d): array
    {
        return $d ? [
            'id' => (int) $d['id'], 'name' => $d['name'], 'status' => $d['status'],
            'user' => $d['user_id'] ? ['id' => (int) $d['user_id'], 'full_name' => $d['user_name'], 'username' => $d['user_username'], 'role' => $d['user_role']] : null,
            'paired_at' => $d['paired_at'], 'sync' => self::syncConfig($d),
        ] : [];
    }

    /** A device record for the admin page: never the token or pairing secret hashes. */
    private function adminView(array $d): array
    {
        unset($d['token_hash'], $d['pair_secret_hash'], $d['pair_code']);
        return $d;
    }

    /** @return int[] the ids, in order, without repeats; at least one */
    private function assertSellers(array $userIds, array $keep = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$ids) {
            throw new \RuntimeException('กรุณาเลือกพนักงานขายของอุปกรณ์อย่างน้อย 1 คน');
        }
        $allowed = array_column($this->sellers(), 'id');
        foreach ($ids as $id) {
            if (!in_array($id, $allowed, true) && !in_array($id, $keep, true)) {
                throw new \RuntimeException('มีบัญชีที่ใช้ขายสินค้าไม่ได้ (ต้องเป็นบัญชีที่เปิดใช้งานและมีสิทธิ์ขายสินค้า)');
            }
        }
        return $ids;
    }

    /** Adds (or brings back) the sellers in $userIds and takes the others off the device. */
    private function saveMembers(int $deviceId, array $userIds, int $adminId): void
    {
        $add = $this->db->prepare(
            'INSERT INTO pos_device_users (device_id, user_id, added_at, added_by) VALUES (?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE added_at = IF(removed_at IS NULL, added_at, NOW()), added_by = IF(removed_at IS NULL, added_by, VALUES(added_by)), removed_at = NULL'
        );
        foreach ($userIds as $id) {
            $add->execute([$deviceId, $id, $adminId]);
        }
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $this->db->prepare("UPDATE pos_device_users SET removed_at = NOW() WHERE device_id = ? AND removed_at IS NULL AND user_id NOT IN ({$in})")
            ->execute([$deviceId, ...$userIds]);
    }

    private function level(string $level): string
    {
        if (!isset(self::SYNC_LEVELS[$level])) {
            throw new \RuntimeException('ระดับความถี่ในการส่งข้อมูลไม่ถูกต้อง');
        }
        return $level;
    }

    private function clip(mixed $v, int $len): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        return $v === '' ? null : mb_substr($v, 0, $len);
    }
}
