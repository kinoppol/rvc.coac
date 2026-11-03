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
 */
class PosDeviceService
{
    public const CODE_MINUTES = 10;
    // No 0/O, 1/I/L: the code is read off a screen and typed by hand.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const MAX_PENDING_PER_IP = 10;

    public const NOT_MIGRATED = 'ยังใช้งานอุปกรณ์ POS ไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0061) ที่หน้าจัดการฐานข้อมูลก่อน';

    public function __construct(private PDO $db, private AuditLogger $audit)
    {
    }

    /** True when $e only means migration 0061 has not been run (pos_devices is missing). */
    public static function notMigrated(\Throwable $e): bool
    {
        return $e instanceof \PDOException && $e->getCode() === '42S02';
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

    /** Admin: pairs the pending device showing $code, selling as $userId. */
    public function approve(string $code, string $name, int $userId, int $adminId, ?string $ip): array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $stmt = $this->db->prepare("SELECT * FROM pos_devices WHERE pair_code = ? AND status = 'pending' AND pair_expires_at > NOW() ORDER BY id DESC LIMIT 1");
        $stmt->execute([$code]);
        $d = $stmt->fetch();
        if (!$d) {
            throw new \RuntimeException('ไม่พบรหัสจับคู่นี้ หรือรหัสหมดอายุแล้ว ให้กดขอรหัสใหม่บนอุปกรณ์');
        }
        $this->assertSeller($userId);
        $name = $this->clip($name, 100) ?? $d['name'];
        $this->db->prepare(
            "UPDATE pos_devices SET status = 'active', name = ?, user_id = ?, pair_code = NULL, paired_at = NOW(), paired_by = ? WHERE id = ?"
        )->execute([$name, $userId, $adminId, $d['id']]);
        $this->audit->log('pos_devices', (int) $d['id'], 'update', ['status' => 'pending'], ['status' => 'active', 'name' => $name, 'user_id' => $userId, 'model' => $d['model']], $adminId, $ip);
        return $this->adminView($this->find((int) $d['id']));
    }

    public function update(int $id, array $fields, int $adminId, ?string $ip): array
    {
        $d = $this->find($id);
        if (!$d || $d['status'] === 'pending') {
            throw new \RuntimeException('ไม่พบอุปกรณ์');
        }
        $name = $this->clip($fields['name'] ?? null, 100) ?? $d['name'];
        $userId = isset($fields['user_id']) ? (int) $fields['user_id'] : (int) $d['user_id'];
        if ($userId !== (int) $d['user_id']) {
            $this->assertSeller($userId);
        }
        $this->db->prepare('UPDATE pos_devices SET name = ?, user_id = ? WHERE id = ?')->execute([$name, $userId, $id]);
        $this->audit->log('pos_devices', $id, 'update', ['name' => $d['name'], 'user_id' => $d['user_id']], ['name' => $name, 'user_id' => $userId], $adminId, $ip);
        return $this->adminView($this->find($id));
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
        $stale = $device['last_seen_at'] === null || strtotime((string) $device['last_seen_at']) < time() - 60;
        if (!$stale && $device['pair_secret_hash'] === null && ($version === null || $version === $device['app_version'])
            && ($pending === null || $pending === (int) $device['pending_count'])) {
            return;
        }
        $this->db->prepare(
            'UPDATE pos_devices SET last_seen_at = NOW(), last_ip = ?, pair_secret_hash = NULL,
                app_version = COALESCE(?, app_version), pending_count = COALESCE(?, pending_count) WHERE id = ?'
        )->execute([$ip, $version, $pending, $device['id']]);
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
                    u.full_name AS user_name, u.role AS user_role, a.full_name AS paired_by_name,
                    (SELECT COUNT(*) FROM pos_device_sales s WHERE s.device_id = d.id AND s.status = 'failed') AS failed_count,
                    (SELECT COUNT(*) FROM pos_device_sales s WHERE s.device_id = d.id AND s.status = 'synced') AS synced_count
             FROM pos_devices d
             LEFT JOIN users u ON u.id = d.user_id
             LEFT JOIN users a ON a.id = d.paired_by
             WHERE d.status <> 'pending'
             ORDER BY d.status = 'revoked', d.name, d.id"
        )->fetchAll();
        foreach ($rows as &$r) {
            foreach (['id', 'user_id', 'pending_count', 'failed_count', 'synced_count'] as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
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
            'paired_at' => $d['paired_at'],
        ] : [];
    }

    /** A device record for the admin page: never the token or pairing secret hashes. */
    private function adminView(array $d): array
    {
        unset($d['token_hash'], $d['pair_secret_hash'], $d['pair_code']);
        return $d;
    }

    private function assertSeller(int $userId): void
    {
        if (!in_array($userId, array_column($this->sellers(), 'id'), true)) {
            throw new \RuntimeException('บัญชีนี้ใช้ขายสินค้าไม่ได้ (ต้องเป็นบัญชีที่เปิดใช้งานและมีสิทธิ์ขายสินค้า)');
        }
    }

    private function clip(mixed $v, int $len): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        return $v === '' ? null : mb_substr($v, 0, $len);
    }
}
