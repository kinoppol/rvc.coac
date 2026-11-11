<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepo;
use PDO;

/**
 * The mobile app for everyone who is not selling (mobile_devices, migration 0075), paired like the POS:
 *  1. the app asks for a code (start) and shows it, keeping a secret only it knows;
 *  2. a signed-in user types the code on the web (or scans the QR-Code with a phone signed in),
 *     and the device becomes theirs (approve) — there is no choosing of users;
 *  3. the app, polling with its secret, receives its token (status).
 * Only the token's SHA-256 is kept. The device works as its owner until the owner or an admin
 * unpairs it, or the owner's account is closed.
 */
class MobileDeviceService
{
    public const CODE_MINUTES = 10;
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const MAX_PENDING_PER_IP = 10;
    public const NOT_MIGRATED = 'ยังใช้งานแอปบนมือถือไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0075) ที่หน้าจัดการฐานข้อมูลก่อน';

    public function __construct(private PDO $db, private AuditLogger $audit)
    {
    }

    public static function notMigrated(\Throwable $e): bool
    {
        return $e instanceof \PDOException && in_array($e->getCode(), ['42S02', '42S22'], true);
    }

    /** @return array{pairing_id:int, pair_code:string, pair_secret:string, expires_at:string, expires_in:int, poll_seconds:int} */
    public function start(array $info, ?string $ip): array
    {
        $this->db->exec("DELETE FROM mobile_devices WHERE status = 'pending' AND pair_expires_at < NOW() - INTERVAL 1 DAY");
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM mobile_devices WHERE status = 'pending' AND created_ip <=> ? AND pair_expires_at > NOW()");
        $stmt->execute([$ip]);
        if ((int) $stmt->fetchColumn() >= self::MAX_PENDING_PER_IP) {
            throw new \DomainException('ขอรหัสจับคู่บ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่');
        }
        $secret = bin2hex(random_bytes(32));
        $check = $this->db->prepare("SELECT 1 FROM mobile_devices WHERE pair_code = ? AND status = 'pending' AND pair_expires_at > NOW()");
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $check->execute([$code]);
        } while ($check->fetchColumn());
        $this->db->prepare(
            // The database's clock decides expiry everywhere (start, approve, status), so PHP's time zone cannot disagree with it.
            "INSERT INTO mobile_devices (name, model, platform, app_version, status, pair_code, pair_secret_hash, pair_expires_at, created_ip, created_at)
             VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW() + INTERVAL " . self::CODE_MINUTES . " MINUTE, ?, NOW())"
        )->execute([
            self::clip($info['device_name'] ?? null, 100) ?? 'Android', self::clip($info['model'] ?? null, 100),
            self::clip($info['platform'] ?? null, 50), self::clip($info['app_version'] ?? null, 30),
            $code, hash('sha256', $secret), $ip,
        ]);
        $id = (int) $this->db->lastInsertId();
        return [
            'pairing_id' => $id, 'pair_code' => $code, 'pair_secret' => $secret,
            'expires_at' => $this->find($id)['pair_expires_at'] ?? null, 'expires_in' => self::CODE_MINUTES * 60, 'poll_seconds' => 3,
        ];
    }

    /**
     * Polled by the app with its secret: pending | active (with a token) | expired | revoked.
     * Until the app first uses its token, polling again issues a fresh one (a reply may be lost).
     */
    public function status(int $pairingId, string $secret): array
    {
        $stmt = $this->db->prepare('SELECT *, pair_expires_at > NOW() AS code_alive FROM mobile_devices WHERE id = ?');
        $stmt->execute([$pairingId]);
        $d = $stmt->fetch();
        if (!$d || $d['pair_secret_hash'] === null || !hash_equals((string) $d['pair_secret_hash'], hash('sha256', $secret))) {
            return ['status' => 'expired'];
        }
        if ($d['status'] === 'revoked') {
            return ['status' => 'revoked'];
        }
        if ($d['status'] === 'pending') {
            return ['status' => $d['code_alive'] ? 'pending' : 'expired'];
        }
        $token = 'md' . $d['id'] . '_' . bin2hex(random_bytes(24));
        $this->db->prepare('UPDATE mobile_devices SET token_hash = ? WHERE id = ?')->execute([hash('sha256', $token), $d['id']]);
        return ['status' => 'active', 'token' => $token];
    }

    /** The signed-in user takes the device showing $code as their own. */
    public function approve(string $code, ?string $name, int $userId, ?string $ip): array
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        $stmt = $this->db->prepare("SELECT * FROM mobile_devices WHERE pair_code = ? AND status = 'pending' AND pair_expires_at > NOW() ORDER BY id DESC LIMIT 1");
        $stmt->execute([$code]);
        $d = $stmt->fetch();
        if (!$d) {
            throw new \DomainException('ไม่พบรหัสจับคู่นี้ หรือรหัสหมดอายุแล้ว ให้กดขอรหัสใหม่ในแอป');
        }
        $name = self::clip($name, 100) ?? $d['name'];
        $this->db->prepare(
            "UPDATE mobile_devices SET status = 'active', user_id = ?, name = ?, pair_code = NULL, paired_at = NOW() WHERE id = ? AND status = 'pending'"
        )->execute([$userId, $name, $d['id']]);
        $this->audit->log('mobile_devices', (int) $d['id'], 'update', ['status' => 'pending'],
            ['status' => 'active', 'user_id' => $userId, 'name' => $name, 'model' => $d['model']], $userId, $ip);
        return $this->view($this->find((int) $d['id']));
    }

    /** The owner, or an admin, unpairs a device; the app finds out on its next request. */
    public function revoke(int $id, int $userId, bool $isAdmin, ?string $ip): void
    {
        $d = $this->find($id);
        if (!$d || $d['status'] !== 'active' || (!$isAdmin && (int) $d['user_id'] !== $userId)) {
            throw new \DomainException('ไม่พบอุปกรณ์นี้');
        }
        $this->db->prepare(
            "UPDATE mobile_devices SET status = 'revoked', token_hash = NULL, pair_secret_hash = NULL, revoked_at = NOW(), revoked_by = ? WHERE id = ?"
        )->execute([$userId, $id]);
        $this->audit->log('mobile_devices', $id, 'update', ['status' => 'active'], ['status' => 'revoked'], $userId, $ip);
    }

    /** The app unpairs itself (its token is then useless). */
    public function forget(array $device): void
    {
        $this->db->prepare(
            "UPDATE mobile_devices SET status = 'revoked', token_hash = NULL, pair_secret_hash = NULL, revoked_at = NOW(), revoked_by = user_id WHERE id = ?"
        )->execute([$device['id']]);
    }

    public function byToken(string $token): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM mobile_devices WHERE token_hash = ? AND status = 'active'");
        $stmt->execute([hash('sha256', $token)]);
        return $stmt->fetch() ?: null;
    }

    /** Every request: when it was last seen; the first use of the token retires the pairing secret. */
    public function touch(array $device, ?string $ip, ?string $appVersion, bool $synced = false): void
    {
        $this->db->prepare(
            'UPDATE mobile_devices SET last_seen_at = NOW(), last_ip = ?, pair_secret_hash = NULL,
                app_version = COALESCE(NULLIF(?, \'\'), app_version)' . ($synced ? ', last_sync_at = NOW()' : '') . ' WHERE id = ?'
        )->execute([$ip, self::clip($appVersion, 30) ?? '', $device['id']]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mobile_devices WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** The user's own paired devices, newest first. */
    public function forUser(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM mobile_devices WHERE user_id = ? AND status = 'active' ORDER BY id DESC");
        $stmt->execute([$userId]);
        return array_map(fn ($d) => $this->view($d), $stmt->fetchAll());
    }

    public function view(?array $d): array
    {
        if (!$d) {
            return [];
        }
        return [
            'id' => (int) $d['id'], 'name' => $d['name'], 'model' => $d['model'], 'platform' => $d['platform'],
            'app_version' => $d['app_version'], 'status' => $d['status'], 'paired_at' => $d['paired_at'],
            'last_seen_at' => $d['last_seen_at'], 'last_sync_at' => $d['last_sync_at'],
        ];
    }

    private static function clip(mixed $v, int $len): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $len);
    }
}
