<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class UserRepo
{
    public const CONSIGNOR = 'consignor';

    /** Fixed: the consignor view only ever sees the person's own data. */
    public const CONSIGNOR_PERMISSIONS = ['consignor.portal'];

    public function __construct(private PDO $db)
    {
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function countAdmins(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
    }

    public function create(string $username, string $password, string $fullName, string $role): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, password_hash, full_name, role, active, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, NOW(), NOW())'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role]);
        return (int) $this->db->lastInsertId();
    }

    public function findByOaUserId(int $oaUserId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE oa_user_id = ? LIMIT 1');
        $stmt->execute([$oaUserId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Local account for an RVC-ONE user. It signs in only through RVC-ONE, so
     * the password hash is of a random secret nobody knows.
     */
    public function createFromOa(int $oaUserId, array $oaUser, string $role, bool $active): int
    {
        $base = preg_replace('/[^A-Za-z0-9._@-]/', '', (string) ($oaUser['username'] ?? '')) ?: 'rvc' . $oaUserId;
        $username = mb_substr($base, 0, 60);
        if ($this->findByUsername($username)) {
            $username = mb_substr($base, 0, 48) . '-rvc' . $oaUserId;
        }
        $fullName = trim(($oaUser['first_name'] ?? '') . ' ' . ($oaUser['last_name'] ?? '')) ?: $username;

        $stmt = $this->db->prepare(
            "INSERT INTO users (oa_user_id, username, password_hash, full_name, role, active, oa_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())"
        );
        $stmt->execute([
            $oaUserId, $username, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            $fullName, $role, $active ? 1 : 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip(['role', 'active', 'oa_user_id', 'oa_status']));
        if (!$allowed) {
            return;
        }
        $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($allowed)));
        $stmt = $this->db->prepare("UPDATE users SET $sets WHERE id = ?");
        $stmt->execute([...array_values($allowed), $id]);
    }

    public function touchLogin(int $id): void
    {
        $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$id]);
    }

    /** Signed-in is allowed only for active accounts not suspended at RVC-ONE. */
    public static function canSignIn(array $user): bool
    {
        return (bool) $user['active'] && ($user['oa_status'] ?? null) !== 'suspended';
    }

    public function all(): array
    {
        $sql = 'SELECT u.id, u.username, u.full_name, u.role, u.active, u.oa_user_id, u.oa_status, u.last_login_at, u.created_at,
                    o.department AS oa_department, o.user_type AS oa_user_type, %s
             FROM users u
             LEFT JOIN oa_users o ON o.oa_user_id = u.oa_user_id %s
             ORDER BY u.id ASC';
        try {
            return $this->db->query(sprintf($sql, 'c.id AS consignor_id, c.code AS consignor_code',
                'LEFT JOIN consignors c ON c.user_id = u.id'))->fetchAll();
        } catch (\PDOException $e) {
            if ($e->getCode() !== '42S22') {
                throw $e;
            }
            // Migration 0019 not run yet.
            return $this->db->query(sprintf($sql, 'NULL AS consignor_id, NULL AS consignor_code', ''))->fetchAll();
        }
    }

    /** The consignor record this user owns, if any. */
    public function consignorOf(int $userId): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT id, code, name FROM consignors WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
        } catch (\PDOException $e) {
            // Before migration 0019 there is no consignors.user_id; sign-in must still work so it can be run.
            if ($e->getCode() === '42S22') {
                return null;
            }
            throw $e;
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Roles the user can switch between: their staff role (unless they are
     * consignor-only) plus 'consignor' when a consignor record is linked.
     *
     * @return string[]
     */
    public function rolesOf(array $user): array
    {
        $roles = $user['role'] !== self::CONSIGNOR ? [$user['role']] : [];
        if ($this->consignorOf((int) $user['id'])) {
            $roles[] = self::CONSIGNOR;
        }
        return $roles;
    }

    /** @return string[] */
    public function permissionsFor(string $role): array
    {
        if ($role === self::CONSIGNOR) {
            return self::CONSIGNOR_PERMISSIONS;
        }
        $keys = [];
        foreach ($this->rolePermissions() as $rp) {
            if ($rp['role'] === $role && (bool) $rp['allowed']) {
                $keys[] = $rp['permission_key'];
            }
        }
        return $keys;
    }

    public function rolePermissions(): array
    {
        return $this->db->query('SELECT role, permission_key, allowed FROM role_permissions')->fetchAll();
    }

    public function hasPermission(string $role, string $permissionKey): bool
    {
        if ($role === self::CONSIGNOR) {
            return in_array($permissionKey, self::CONSIGNOR_PERMISSIONS, true);
        }
        $stmt = $this->db->prepare(
            'SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ? LIMIT 1'
        );
        $stmt->execute([$role, $permissionKey]);
        $val = $stmt->fetchColumn();
        return $val !== false && (bool) $val;
    }
}
