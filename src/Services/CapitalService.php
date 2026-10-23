<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * งบลงทุน: the money spent buying goods into the business (unless bought with its own
 * profit), plus other money invested, less money withdrawn from the profit. Kept apart
 * from income and expenses, so it never shows up as profit. This records the entries
 * that are not purchases: other investments and withdrawals from profit.
 */
class CapitalService
{
    public const TYPES = ['withdraw' => 'เบิกเงินออกจากกำไร', 'invest' => 'ลงทุนเพิ่ม (นอกจากการซื้อของ)'];
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
            throw new \RuntimeException('กรุณาระบุผู้รับเงิน / ผู้ลงทุน');
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

    /**
     * Entries of a period (newest first) and the monthly summary (รายงานงบลงทุน): goods bought
     * with invested money, other money invested, withdrawals from profit, งบลงทุน left,
     * and each month's net profit.
     */
    public function list(string $from, string $to): array
    {
        $stmt = $this->db->prepare($this->select() . ' WHERE e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date DESC, e.id DESC');
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll();
        $months = (new ReportService($this->db))->capital($from, $to);
        $sum = fn (string $k) => round(array_sum(array_map(fn ($m) => (float) ($m[$k] ?? 0), array_slice($months, 1))), 2);
        $last = end($months);
        return [
            'rows' => $rows,
            'months' => $months,
            'opening' => (float) $months[0]['capital_balance'],
            'capital_purchases' => $sum('capital_purchases'),
            'other_invest' => $sum('other_invest'),
            'profit_withdrawn' => $sum('profit_withdrawn'),
            'net_profit' => $sum('net_profit'),
            'closing' => (float) $last['capital_balance'],
            'retained_profit' => (float) $last['retained_profit'],
            'investors' => $this->db->query('SELECT DISTINCT investor FROM capital_entries ORDER BY investor')->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    /**
     * งบลงทุน at the end of $date: every purchase bought with invested money + other money
     * invested − money withdrawn from the profit (cancelled entries and purchases left out).
     */
    public function balance(string $date): float
    {
        $report = new ReportService($this->db);
        $first = min($report->firstDay(), $date);
        $total = 0.0;
        foreach ($report->capitalFlows($first, $date) as $f) {
            $total += ($f['cap_purchase'] ?? 0) + ($f['invest'] ?? 0) - ($f['withdraw'] ?? 0);
        }
        return round($total, 2);
    }

    private function select(): string
    {
        return 'SELECT e.*, cb.full_name AS created_by_name, xb.full_name AS cancelled_by_name
                FROM capital_entries e
                LEFT JOIN users cb ON cb.id = e.created_by
                LEFT JOIN users xb ON xb.id = e.cancelled_by';
    }
}
