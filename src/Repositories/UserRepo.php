<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class UserRepo
{
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
        return (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
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

    public function all(): array
    {
        return $this->db->query('SELECT id, username, full_name, role, active, created_at FROM users ORDER BY id ASC')->fetchAll();
    }

    public function rolePermissions(): array
    {
        return $this->db->query('SELECT role, permission_key, allowed FROM role_permissions')->fetchAll();
    }

    public function hasPermission(string $role, string $permissionKey): bool
    {
        $stmt = $this->db->prepare(
            'SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = ? LIMIT 1'
        );
        $stmt->execute([$role, $permissionKey]);
        $val = $stmt->fetchColumn();
        return $val !== false && (bool) $val;
    }
}
