<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * The RVC-ONE directory mirror (oa_users) and its link to local `users`.
 */
class OaUserRepo
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Idempotent upsert of one RVC-ONE user (from user-sync or verify_token),
     * and refresh of the linked local account if there is one.
     *
     * @return 'added'|'updated'|'unchanged'
     */
    public function upsert(array $u): string
    {
        $id = (int) ($u['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('RVC-ONE user without id');
        }
        $existing = $this->find($id);
        // No status in the payload (verify_token) = keep what the sync recorded.
        $status = isset($u['status'])
            ? ($u['status'] === 'suspended' ? 'suspended' : 'active')
            : ($existing['status'] ?? 'active');
        $first = self::str($u['first_name'] ?? null) ?? '';
        $last = self::str($u['last_name'] ?? null) ?? '';

        $row = [
            'first_name' => $first,
            'last_name' => $last,
            'department' => self::str($u['department'] ?? null),
            'user_type' => self::str($u['user_type'] ?? null),
            'status' => $status,
            'username' => self::str($u['username'] ?? null),
            'email' => self::str($u['email'] ?? null),
            'google_email' => self::str($u['google_email'] ?? null),
            'remote_updated_at' => self::datetime($u['updated_at'] ?? null),
        ];

        if ($existing === null) {
            $result = 'added';
            $stmt = $this->db->prepare(
                'INSERT INTO oa_users (oa_user_id, ' . implode(', ', array_keys($row)) . ', synced_at)
                 VALUES (?, ' . implode(', ', array_fill(0, count($row), '?')) . ', NOW())'
            );
            $stmt->execute([$id, ...array_values($row)]);
        } else {
            // Optional fields only overwrite when RVC-ONE actually sent them, so
            // a narrower field grant (or a verify_token payload) never blanks data.
            foreach (['user_type', 'username', 'email', 'google_email', 'remote_updated_at'] as $optional) {
                $row[$optional] ??= $existing[$optional];
            }
            $changed = false;
            foreach ($row as $k => $v) {
                if ((string) $v !== (string) $existing[$k]) {
                    $changed = true;
                    break;
                }
            }
            $result = $changed ? 'updated' : 'unchanged';
            if ($changed) {
                $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($row)));
                $stmt = $this->db->prepare("UPDATE oa_users SET $sets, synced_at = NOW() WHERE oa_user_id = ?");
                $stmt->execute([...array_values($row), $id]);
            }
        }

        $fullName = trim($first . ' ' . $last);
        $link = $this->db->prepare(
            'UPDATE users SET oa_status = ?, full_name = IF(? = \'\', full_name, ?) WHERE oa_user_id = ?'
        );
        $link->execute([$status, $fullName, $fullName, $id]);

        return $result;
    }

    public function find(int $oaUserId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM oa_users WHERE oa_user_id = ?');
        $stmt->execute([$oaUserId]);
        return $stmt->fetch() ?: null;
    }

    /** Directory search for the "grant access" picker. */
    public function search(string $q, string $type = '', int $limit = 30): array
    {
        $where = ["o.status = 'active'"];
        $params = [];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(CONCAT(o.first_name, \' \', o.last_name) LIKE ? OR o.username LIKE ? OR o.email LIKE ?
                         OR o.department LIKE ? OR o.oa_user_id = ?)';
            array_push($params, $like, $like, $like, $like, ctype_digit($q) ? (int) $q : -1);
        }
        if ($type !== '') {
            $where[] = 'o.user_type = ?';
            $params[] = $type;
        }
        $sql = 'SELECT o.oa_user_id, o.first_name, o.last_name, o.department, o.user_type, o.username, o.email,
                       u.id AS local_user_id, u.role AS local_role
                FROM oa_users o LEFT JOIN users u ON u.oa_user_id = o.oa_user_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY o.first_name, o.last_name LIMIT ' . max(1, min(100, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM oa_users')->fetchColumn();
    }

    private static function str(mixed $v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, 191);
    }

    /** RVC-ONE sends ISO 8601 with offset; store as local (Asia/Bangkok) time. */
    private static function datetime(mixed $v): ?string
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($v))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
