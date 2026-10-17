<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AuditLogger;
use PDO;

class ConsignorRepo
{
    public function __construct(private PDO $db, private AuditLogger $audit)
    {
    }

    public function search(string $q = ''): array
    {
        $sql = 'SELECT c.*,
                    (SELECT COUNT(*) FROM products p WHERE p.consignor_id = c.id) AS item_count,
                    (SELECT COALESCE(SUM(si.qty * si.unit_price), 0) FROM sale_items si WHERE si.consignor_id = c.id) AS sold_total,
                    (SELECT COALESCE(balance_after, 0) FROM ledger_entries le WHERE le.consignor_id = c.id ORDER BY le.id DESC LIMIT 1) AS balance
                FROM consignors c';
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE c.name LIKE ? OR c.code LIKE ? OR c.phone LIKE ?';
            $like = '%' . $q . '%';
            $params = [$like, $like, $like];
        }
        $sql .= ' ORDER BY c.created_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM consignors WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM consignors WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Link (or unlink with null) the user account that owns this consignor record. */
    public function linkUser(int $id, ?int $linkUserId, ?int $userId, ?string $ip): void
    {
        $before = $this->find($id);
        $this->db->prepare('UPDATE consignors SET user_id = ? WHERE id = ?')->execute([$linkUserId, $id]);
        $this->audit->log('consignors', $id, 'update', $before, $this->find($id), $userId, $ip);
    }

    public function options(): array
    {
        $stmt = $this->db->query("SELECT id, code, name FROM consignors WHERE status = 'active' ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    public function create(array $data, ?int $userId, ?string $ip): int
    {
        $code = $this->nextCode();
        $token = bin2hex(random_bytes(16));

        $stmt = $this->db->prepare(
            'INSERT INTO consignors
                (code, name, type, department, phone, id_card, pay_channel, pay_account, advisor_teacher, status, portal_token, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $code,
            $data['name'],
            $data['type'],
            $data['department'] ?? null,
            $data['phone'],
            $data['id_card'] ?? null,
            $data['pay_channel'],
            $data['pay_account'] ?? null,
            $data['advisor_teacher'] ?? null,
            $data['status'] ?? 'active',
            $token,
        ]);
        $id = (int) $this->db->lastInsertId();

        $this->audit->log('consignors', $id, 'create', null, $this->find($id), $userId, $ip);

        // Seed the passbook with an opening balance entry so the ledger always starts from 0.
        $ins = $this->db->prepare(
            "INSERT INTO ledger_entries (consignor_id, entry_date, description, ref_no, type, amount_in, amount_out, balance_after, created_by, created_at)
             VALUES (?, CURDATE(), 'ยอดยกมา', ?, 'open', 0, 0, 0, ?, NOW())"
        );
        $ins->execute([$id, 'OPEN-' . $code, $userId]);

        return $id;
    }

    private function nextCode(): string
    {
        $last = $this->db->query("SELECT code FROM consignors ORDER BY id DESC LIMIT 1")->fetchColumn();
        $seq = 1;
        if ($last && preg_match('/(\d+)$/', (string) $last, $m)) {
            $seq = (int) $m[1] + 1;
        }
        return 'C-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
