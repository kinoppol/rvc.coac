<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * เงินลงทุน: capital put into the business and capital taken back out. Kept apart
 * from income and expenses, so it never shows up as profit.
 */
class CapitalService
{
    public const TYPES = ['invest' => 'ลงทุนเพิ่ม', 'withdraw' => 'ถอนทุนคืน'];
    public const METHODS = ['cash' => 'เงินสด', 'transfer' => 'โอนเงิน'];

    public function __construct(private PDO $db, private AuditLogger $audit, private DocumentNumberService $docs)
    {
    }

    /** @param array{entry_date:string, type:string, investor:string, amount:float, method:string, note?:?string} $d */
    public function add(array $d, int $userId, ?string $ip): array
    {
        $date = trim((string) ($d['entry_date'] ?? ''));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
            throw new \RuntimeException('วันที่ไม่ถูกต้อง (ต้องไม่เกินวันนี้)');
        }
        $type = (string) ($d['type'] ?? 'invest');
        $method = (string) ($d['method'] ?? 'cash');
        if (!isset(self::TYPES[$type]) || !isset(self::METHODS[$method])) {
            throw new \RuntimeException('ประเภทหรือวิธีรับ/จ่ายเงินไม่ถูกต้อง');
        }
        $investor = mb_substr(trim((string) ($d['investor'] ?? '')), 0, 191);
        if ($investor === '') {
            throw new \RuntimeException('กรุณาระบุผู้ลงทุน / แหล่งเงินทุน');
        }
        $amount = round((float) ($d['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \RuntimeException('กรุณาระบุจำนวนเงิน');
        }
        $note = trim((string) ($d['note'] ?? ''));
        $docNo = $this->docs->next('CP', 'capital_entries', 'doc_no');
        $this->db->prepare(
            'INSERT INTO capital_entries (doc_no, entry_date, type, investor, amount, method, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$docNo, $date, $type, $investor, $amount, $method, $note !== '' ? mb_substr($note, 0, 500) : null, $userId, date('Y-m-d H:i:s')]);
        $id = (int) $this->db->lastInsertId();
        $this->audit->log('capital_entries', $id, 'create', null, ['doc_no' => $docNo, 'type' => $type, 'investor' => $investor, 'amount' => $amount], $userId, $ip);
        return $this->find($id);
    }

    public function cancel(int $id, string $reason, int $userId, ?string $ip): void
    {
        $e = $this->find($id);
        if (!$e) {
            throw new \RuntimeException('ไม่พบรายการ');
        }
        if ($e['cancelled_at'] !== null) {
            throw new \RuntimeException('รายการนี้ถูกยกเลิกไปแล้ว');
        }
        $this->db->prepare('UPDATE capital_entries SET cancelled_at = ?, cancelled_by = ?, cancel_reason = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $userId, mb_substr($reason, 0, 500), $id]);
        $this->audit->log('capital_entries', $id, 'cancel', ['cancelled_at' => null], ['cancel_reason' => $reason], $userId, $ip);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare($this->select() . ' WHERE e.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Entries of a period (newest first) with totals, per investor, and the balance before and after. */
    public function list(string $from, string $to): array
    {
        $stmt = $this->db->prepare($this->select() . ' WHERE e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date DESC, e.id DESC');
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll();
        $stmt = $this->db->prepare(
            "SELECT investor,
                    SUM(CASE WHEN type = 'invest' THEN amount ELSE 0 END) AS invested,
                    SUM(CASE WHEN type = 'withdraw' THEN amount ELSE 0 END) AS withdrawn
             FROM capital_entries WHERE cancelled_at IS NULL AND entry_date <= ?
             GROUP BY investor ORDER BY SUM(CASE WHEN type = 'invest' THEN amount ELSE -amount END) DESC"
        );
        $stmt->execute([$to]);
        $byInvestor = array_map(fn ($r) => $r + ['balance' => round((float) $r['invested'] - (float) $r['withdrawn'], 2)], $stmt->fetchAll());
        $period = ['invested' => 0.0, 'withdrawn' => 0.0];
        foreach ($rows as $r) {
            if ($r['cancelled_at'] === null) {
                $period[$r['type'] === 'invest' ? 'invested' : 'withdrawn'] += (float) $r['amount'];
            }
        }
        $opening = $this->balance((new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'));
        return [
            'rows' => $rows,
            'by_investor' => $byInvestor,
            'opening' => $opening,
            'invested' => round($period['invested'], 2),
            'withdrawn' => round($period['withdrawn'], 2),
            'closing' => round($opening + $period['invested'] - $period['withdrawn'], 2),
            'investors' => $this->db->query('SELECT DISTINCT investor FROM capital_entries ORDER BY investor')->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    /** Capital in the business at the end of $date (invested − withdrawn, cancelled entries left out). */
    public function balance(string $date): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN type = 'invest' THEN amount ELSE -amount END), 0)
             FROM capital_entries WHERE cancelled_at IS NULL AND entry_date <= ?"
        );
        $stmt->execute([$date]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function select(): string
    {
        return 'SELECT e.*, cb.full_name AS created_by_name, xb.full_name AS cancelled_by_name
                FROM capital_entries e
                LEFT JOIN users cb ON cb.id = e.created_by
                LEFT JOIN users xb ON xb.id = e.cancelled_by';
    }
}
