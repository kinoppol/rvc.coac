<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use PDO;

/** Consignment requests: what a consignor says they will bring, before staff receive it. */
class ConsignRequestRepo
{
    public const MAX_ITEMS = 50;

    public function __construct(private PDO $db, private AuditLogger $audit)
    {
    }

    /**
     * Validates and normalises request items; throws with a message for the user.
     * Also used for staff receive slips (with a higher $maxItems).
     * @return array<int,array{name:string,cost_price:float,qty:int,barcode:?string,mfg_date:string,expiry_date:?string}>
     */
    public static function cleanItems(mixed $items, int $maxItems = self::MAX_ITEMS): array
    {
        if (!is_array($items) || !$items) {
            throw new \RuntimeException('กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ');
        }
        if (count($items) > $maxItems) {
            throw new \RuntimeException('เพิ่มได้ไม่เกิน ' . $maxItems . ' รายการต่อครั้ง');
        }
        $today = date('Y-m-d');
        $out = [];
        foreach (array_values($items) as $i => $it) {
            $name = trim((string) ($it['name'] ?? ''));
            $cost = (float) ($it['cost_price'] ?? 0);
            $qty = (int) ($it['qty'] ?? 0);
            if ($name === '' || $cost <= 0 || $qty <= 0) {
                throw new \RuntimeException('รายการที่ ' . ($i + 1) . ': กรุณากรอกชื่อสินค้า ราคา และจำนวนให้ถูกต้อง');
            }
            $expiry = self::date($it['expiry_date'] ?? null);
            $mfg = self::date($it['mfg_date'] ?? null);
            if ($mfg === null) {
                throw new \RuntimeException('รายการที่ ' . ($i + 1) . ' (' . $name . '): กรุณาระบุวันที่ผลิต');
            }
            if ($mfg > $today) {
                throw new \RuntimeException('รายการที่ ' . ($i + 1) . ' (' . $name . '): วันที่ผลิตต้องไม่เกินวันนี้');
            }
            if ($expiry !== null && $expiry < $mfg) {
                throw new \RuntimeException('รายการที่ ' . ($i + 1) . ' (' . $name . '): วันหมดอายุต้องไม่ก่อนวันที่ผลิต');
            }
            $barcode = trim((string) ($it['barcode'] ?? ''));
            $out[] = [
                'name' => mb_substr($name, 0, 191),
                'cost_price' => round($cost, 2),
                'qty' => $qty,
                'barcode' => $barcode !== '' ? mb_substr($barcode, 0, 64) : null,
                'mfg_date' => $mfg,
                'expiry_date' => $expiry,
            ];
        }
        return $out;
    }

    /** A valid Y-m-d date string, or null. */
    private static function date(mixed $value): ?string
    {
        $v = trim((string) $value);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }

    public function create(int $consignorId, array $items, ?string $note, int $userId, ?string $ip): array
    {
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;
        $this->db->beginTransaction();
        try {
            $no = (new DocumentNumberService($this->db))->next('RQ', 'consign_requests', 'request_no');
            $this->db->prepare(
                "INSERT INTO consign_requests (request_no, consignor_id, created_by, status, note, created_at)
                 VALUES (?, ?, ?, 'pending', ?, NOW())"
            )->execute([$no, $consignorId, $userId, $note]);
            $id = (int) $this->db->lastInsertId();
            $ins = $this->db->prepare(
                'INSERT INTO consign_request_items (request_id, name, cost_price, qty, barcode, mfg_date, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $it) {
                $ins->execute([$id, $it['name'], $it['cost_price'], $it['qty'], $it['barcode'], $it['mfg_date'], $it['expiry_date']]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->audit->log('consign_requests', $id, 'create', null, ['request_no' => $no, 'items' => $items], $userId, $ip);
        return $this->find($id);
    }

    public function find(int $id): ?array
    {
        return $this->fetch('r.id = ?', [$id], 1)[0] ?? null;
    }

    public function forConsignor(int $consignorId): array
    {
        return $this->fetch('r.consignor_id = ?', [$consignorId], 50);
    }

    /** For staff: pending first, then the latest handled ones. */
    public function listForStaff(?string $status): array
    {
        return $status ? $this->fetch('r.status = ?', [$status], 100) : $this->fetch('1 = 1', [], 100);
    }

    public function pendingCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM consign_requests WHERE status = 'pending'")->fetchColumn();
    }

    /**
     * Moves a pending request to received/rejected/cancelled. Returns false if it
     * was no longer pending (someone else handled it first).
     */
    public function close(int $id, string $status, ?int $receiveDocId, ?string $reviewNote, int $userId, ?string $ip): bool
    {
        $reviewNote = $reviewNote !== null && trim($reviewNote) !== '' ? mb_substr(trim($reviewNote), 0, 500) : null;
        $stmt = $this->db->prepare(
            "UPDATE consign_requests SET status = ?, receive_document_id = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$status, $receiveDocId, $reviewNote, $userId, $id]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->audit->log('consign_requests', $id, 'update', ['status' => 'pending'], [
            'status' => $status, 'receive_document_id' => $receiveDocId, 'review_note' => $reviewNote,
        ], $userId, $ip);
        return true;
    }

    private function fetch(string $where, array $params, int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, c.code AS consignor_code, c.name AS consignor_name,
                    rd.doc_no AS receive_doc_no, u.full_name AS reviewed_by_name
             FROM consign_requests r
             JOIN consignors c ON c.id = r.consignor_id
             LEFT JOIN receive_documents rd ON rd.id = r.receive_document_id
             LEFT JOIN users u ON u.id = r.reviewed_by
             WHERE $where
             ORDER BY (r.status = 'pending') DESC, r.id DESC
             LIMIT $limit"
        );
        $stmt->execute($params);
        $requests = $stmt->fetchAll();
        if (!$requests) {
            return [];
        }
        $ids = array_column($requests, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $items = $this->db->prepare("SELECT * FROM consign_request_items WHERE request_id IN ($in) ORDER BY id ASC");
        $items->execute($ids);
        $byRequest = [];
        foreach ($items->fetchAll() as $it) {
            $byRequest[$it['request_id']][] = $it;
        }
        foreach ($requests as &$r) {
            $r['items'] = $byRequest[$r['id']] ?? [];
        }
        return $requests;
    }
}
