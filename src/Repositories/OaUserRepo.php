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

        $this->saveIdentity($id, $u);

        $fullName = trim($first . ' ' . $last);
        $link = $this->db->prepare(
            'UPDATE users SET oa_status = ?, full_name = IF(? = \'\', full_name, ?) WHERE oa_user_id = ?'
        );
        $link->execute([$status, $fullName, $fullName, $id]);

        return $result;
    }

    /**
     * Store members (migration 0088): the national ID, student code and phone numbers RVC-ONE holds
     * from RMS, sent when this app is granted them. A field left out of the payload is kept as it is.
     * phone / phones: one number or a list ("0812345678", "081-234-5678, 043-511-xxx", or an array).
     */
    private function saveIdentity(int $id, array $u): void
    {
        if (!\App\Support\Db::hasColumn($this->db, 'oa_users', 'national_id')) {
            return;
        }
        $sets = [];
        $args = [];
        if (array_key_exists('national_id', $u)) {
            $nid = preg_replace('/\D/', '', (string) $u['national_id']);
            $sets[] = 'national_id = ?';
            $args[] = strlen($nid) === 13 ? $nid : null;
        }
        if (array_key_exists('student_code', $u)) {
            $sets[] = 'student_code = ?';
            $args[] = self::str($u['student_code']);
        }
        if ($sets) {
            $this->db->prepare('UPDATE oa_users SET ' . implode(', ', $sets) . ' WHERE oa_user_id = ?')->execute([...$args, $id]);
        }
        if (!array_key_exists('phone', $u) && !array_key_exists('phones', $u)) {
            return;
        }
        $raw = [];
        foreach (['phone', 'phones'] as $k) {
            $v = $u[$k] ?? null;
            foreach (is_array($v) ? $v : preg_split('/[,;\/]+/', (string) $v) as $one) {
                $raw[] = (string) $one;
            }
        }
        $phones = [];
        foreach ($raw as $one) {
            $d = preg_replace('/\D/', '', $one);
            if (str_starts_with($d, '66') && strlen($d) === 11) {
                $d = '0' . substr($d, 2);
            }
            if (strlen($d) >= 9 && strlen($d) <= 15) {
                $phones[$d] = true;
            }
        }
        $this->db->prepare('DELETE FROM oa_user_phones WHERE oa_user_id = ?')->execute([$id]);
        $ins = $this->db->prepare('INSERT IGNORE INTO oa_user_phones (phone, oa_user_id) VALUES (?, ?)');
        foreach (array_keys($phones) as $p) {
            $ins->execute([(string) $p, $id]);
        }
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

    /** @return array<string,int> user_type => number of people */
    public function countByType(): array
    {
        $rows = $this->db->query(
            "SELECT COALESCE(user_type, '') AS t, COUNT(*) AS n FROM oa_users GROUP BY user_type"
        )->fetchAll();
        return array_column(array_map(fn ($r) => ['t' => $r['t'], 'n' => (int) $r['n']], $rows), 'n', 't');
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
