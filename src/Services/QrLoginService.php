<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Sign-in by QR-Code: a signed-out screen shows a one-time code; a phone that is
 * already signed in scans it and approves; the screen then signs in as that user.
 * Codes last 5 minutes, work once, and are tied to the browser session that showed
 * them, so a photo of the QR on another computer cannot be used to get in.
 */
class QrLoginService
{
    public const TTL_SECONDS = 300;

    public function __construct(private PDO $db)
    {
    }

    /** A new code for the screen; returns the raw code (only its hash is stored) and its id. */
    public function create(?string $ip, ?string $agent): array
    {
        // Old codes are only history; keep a day of them.
        $this->db->prepare('DELETE FROM qr_logins WHERE expires_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
        $code = bin2hex(random_bytes(24));
        $this->db->prepare(
            'INSERT INTO qr_logins (code_hash, created_at, expires_at, requester_ip, requester_agent) VALUES (?, ?, ?, ?, ?)'
        )->execute([hash('sha256', $code), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + self::TTL_SECONDS), $ip, $agent !== null ? mb_substr($agent, 0, 255) : null]);
        return ['id' => (int) $this->db->lastInsertId(), 'code' => $code, 'expires_in' => self::TTL_SECONDS];
    }

    /** The code a phone scanned, while it can still be approved; null when unknown, used or expired. */
    public function pending(string $code): ?array
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $code)) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM qr_logins WHERE code_hash = ? AND used_at IS NULL AND approved_at IS NULL AND expires_at > ?'
        );
        $stmt->execute([hash('sha256', $code), date('Y-m-d H:i:s')]);
        return $stmt->fetch() ?: null;
    }

    public function approve(string $code, int $userId, ?string $ip): bool
    {
        $row = $this->pending($code);
        if (!$row) {
            return false;
        }
        $stmt = $this->db->prepare('UPDATE qr_logins SET approved_by = ?, approved_at = ?, approver_ip = ? WHERE id = ? AND approved_at IS NULL');
        $stmt->execute([$userId, date('Y-m-d H:i:s'), $ip, $row['id']]);
        return $stmt->rowCount() === 1;
    }

    /**
     * The screen asks about its code: 'pending', 'expired', or the approving user's id
     * once (the code is then used up).
     * @return array{status:string, user_id?:int}
     */
    public function claim(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM qr_logins WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || $row['used_at'] !== null) {
            return ['status' => 'expired'];
        }
        if ($row['approved_at'] !== null) {
            $use = $this->db->prepare('UPDATE qr_logins SET used_at = ? WHERE id = ? AND used_at IS NULL');
            $use->execute([date('Y-m-d H:i:s'), $id]);
            return $use->rowCount() === 1 ? ['status' => 'approved', 'user_id' => (int) $row['approved_by']] : ['status' => 'expired'];
        }
        return ['status' => $row['expires_at'] > date('Y-m-d H:i:s') ? 'pending' : 'expired'];
    }
}
