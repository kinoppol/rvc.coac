<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Generic accounting-data audit trail. Every repository write that touches
 * an accounting-relevant table calls log() so changes can be inspected later.
 */
class AuditLogger
{
    public function __construct(private PDO $db)
    {
    }

    public function log(
        string $table,
        int $recordId,
        string $action,
        ?array $old,
        ?array $new,
        ?int $userId,
        ?string $ip
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_logs (table_name, record_id, action, old_data, new_data, user_id, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $table,
            $recordId,
            $action,
            $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            $userId,
            $ip,
        ]);
    }

    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['table_name'])) {
            $where[] = 'table_name = ?';
            $params[] = $filters['table_name'];
        }
        if (!empty($filters['record_id'])) {
            $where[] = 'record_id = ?';
            $params[] = (int) $filters['record_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }
        $sql = 'SELECT al.*, u.full_name AS user_name FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY al.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $p) {
            $stmt->bindValue($i + 1, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
