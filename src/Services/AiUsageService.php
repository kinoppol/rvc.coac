<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Records every AI request and estimates its cost with flat per-request
 * rates (settings): general, large markdown answer, receipt photo reading.
 */
class AiUsageService
{
    public const CATEGORY_LABEL = ['general' => 'ทั่วไป', 'markdown' => 'Markdown ขนาดใหญ่', 'receipt' => 'อ่านใบเสร็จจากรูป'];
    public const RATE_KEYS = ['ai_cost_general', 'ai_cost_markdown', 'ai_cost_receipt', 'ai_markdown_min_chars', 'ai_markdown_min_table_rows'];

    public function __construct(private PDO $db, private SettingsService $settings)
    {
    }

    public function rates(): array
    {
        $all = $this->settings->all();
        return [
            'general' => (float) $all['ai_cost_general'],
            'markdown' => (float) $all['ai_cost_markdown'],
            'receipt' => (float) $all['ai_cost_receipt'],
            'markdown_min_chars' => (int) $all['ai_markdown_min_chars'],
            'markdown_min_table_rows' => (int) $all['ai_markdown_min_table_rows'],
        ];
    }

    /** True when an answer is a "large markdown" one: long text or a big table. */
    public function isLargeMarkdown(string $text): bool
    {
        $rates = $this->rates();
        if ($rates['markdown_min_chars'] > 0 && mb_strlen($text) >= $rates['markdown_min_chars']) {
            return true;
        }
        if ($rates['markdown_min_table_rows'] > 0) {
            $rows = preg_match_all('/^\s*\|.*\|\s*$/m', $text);
            // Header and separator lines are not data rows.
            return $rows - 2 >= $rates['markdown_min_table_rows'];
        }
        return false;
    }

    /**
     * @param array{user_id:int, channel?:string, category:string, prompt?:string, model_row_id?:?int, model_name?:?string,
     *              provider_name?:?string, image_count?:int, response_chars?:int, tool_calls?:string[], status?:string,
     *              error_message?:?string, duration_ms?:int} $entry
     */
    public function log(array $entry): void
    {
        $category = array_key_exists($entry['category'], self::CATEGORY_LABEL) ? $entry['category'] : 'general';
        $status = ($entry['status'] ?? 'ok') === 'error' ? 'error' : 'ok';
        // Failed requests are recorded but not charged.
        $cost = $status === 'ok' ? $this->rates()[$category] : 0;
        $tools = array_values(array_unique($entry['tool_calls'] ?? []));

        $stmt = $this->db->prepare(
            'INSERT INTO ai_usage_logs
                (user_id, channel, category, cost, prompt, model_row_id, model_name, provider_name, image_count,
                 response_chars, tool_calls, status, error_message, duration_ms, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $entry['user_id'],
            in_array($entry['channel'] ?? 'chat', ['receipt_extract', 'pos_match'], true) ? $entry['channel'] : 'chat',
            $category,
            $cost,
            mb_substr((string) ($entry['prompt'] ?? ''), 0, 4000),
            $entry['model_row_id'] ?? null,
            isset($entry['model_name']) ? mb_substr((string) $entry['model_name'], 0, 191) : null,
            isset($entry['provider_name']) ? mb_substr((string) $entry['provider_name'], 0, 100) : null,
            (int) ($entry['image_count'] ?? 0),
            (int) ($entry['response_chars'] ?? 0),
            $tools ? mb_substr(implode(',', $tools), 0, 255) : null,
            $status,
            isset($entry['error_message']) ? mb_substr((string) $entry['error_message'], 0, 500) : null,
            (int) ($entry['duration_ms'] ?? 0),
            date('Y-m-d H:i:s'),
        ]);
    }

    /** Range filter shared by every query; dates are Y-m-d and inclusive. */
    private function where(array $f, array &$params): string
    {
        $where = ['l.created_at >= ?', 'l.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
        $params[] = $f['from'] . ' 00:00:00';
        $params[] = $f['to'];
        if (!empty($f['user_id'])) {
            $where[] = 'l.user_id = ?';
            $params[] = (int) $f['user_id'];
        }
        if (!empty($f['category']) && isset(self::CATEGORY_LABEL[$f['category']])) {
            $where[] = 'l.category = ?';
            $params[] = $f['category'];
        }
        if (!empty($f['q'])) {
            $where[] = 'l.prompt LIKE ?';
            $params[] = '%' . $f['q'] . '%';
        }
        return implode(' AND ', $where);
    }

    private const SUMS = "COUNT(*) AS requests,
        SUM(l.category = 'general') AS general_count,
        SUM(l.category = 'markdown') AS markdown_count,
        SUM(l.category = 'receipt') AS receipt_count,
        SUM(l.status = 'error') AS error_count,
        COALESCE(SUM(l.cost), 0) AS cost";

    /**
     * Totals grouped by day, ISO week (Mon–Sun) or month.
     * @return array<int,array{period_start:string, period_end:string, requests:int, cost:float, ...}>
     */
    public function summary(string $period, array $f): array
    {
        [$key, $start, $end] = match ($period) {
            'week' => [
                'YEARWEEK(l.created_at, 3)',
                'DATE_SUB(DATE(l.created_at), INTERVAL WEEKDAY(l.created_at) DAY)',
                'DATE_ADD(DATE_SUB(DATE(l.created_at), INTERVAL WEEKDAY(l.created_at) DAY), INTERVAL 6 DAY)',
            ],
            'month' => [
                "DATE_FORMAT(l.created_at, '%Y-%m')",
                "DATE_FORMAT(l.created_at, '%Y-%m-01')",
                'LAST_DAY(l.created_at)',
            ],
            default => ['DATE(l.created_at)', 'DATE(l.created_at)', 'DATE(l.created_at)'],
        };
        $params = [];
        $sql = "SELECT MIN($start) AS period_start, MAX($end) AS period_end, " . self::SUMS . "
                FROM ai_usage_logs l WHERE " . $this->where($f, $params) . "
                GROUP BY $key ORDER BY period_start DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'castRow'], $stmt->fetchAll());
    }

    public function byUser(array $f): array
    {
        $params = [];
        $stmt = $this->db->prepare(
            'SELECT l.user_id, u.full_name, u.username, u.role, ' . self::SUMS . '
             FROM ai_usage_logs l JOIN users u ON u.id = l.user_id
             WHERE ' . $this->where($f, $params) . '
             GROUP BY l.user_id, u.full_name, u.username, u.role ORDER BY cost DESC, requests DESC'
        );
        $stmt->execute($params);
        return array_map([$this, 'castRow'], $stmt->fetchAll());
    }

    public function totals(array $f): array
    {
        $params = [];
        $stmt = $this->db->prepare('SELECT ' . self::SUMS . ' FROM ai_usage_logs l WHERE ' . $this->where($f, $params));
        $stmt->execute($params);
        return $this->castRow($stmt->fetch() ?: []);
    }

    /** Today / this week (Mon–Sun) / this month, for the KPI tiles. */
    public function currentTotals(): array
    {
        $today = new \DateTimeImmutable('today');
        $weekStart = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
        return [
            'today' => $this->totals(['from' => $today->format('Y-m-d'), 'to' => $today->format('Y-m-d')]),
            'week' => $this->totals(['from' => $weekStart->format('Y-m-d'), 'to' => $today->format('Y-m-d')]) + [
                'from' => $weekStart->format('Y-m-d'), 'to' => $weekStart->modify('+6 days')->format('Y-m-d'),
            ],
            'month' => $this->totals(['from' => $today->format('Y-m-01'), 'to' => $today->format('Y-m-d')]),
        ];
    }

    public function logs(array $f, int $limit = 50, int $offset = 0): array
    {
        $params = [];
        $where = $this->where($f, $params);
        $count = $this->db->prepare("SELECT COUNT(*) FROM ai_usage_logs l WHERE $where");
        $count->execute($params);

        $stmt = $this->db->prepare(
            "SELECT l.*, u.full_name, u.username FROM ai_usage_logs l JOIN users u ON u.id = l.user_id
             WHERE $where ORDER BY l.id DESC LIMIT ? OFFSET ?"
        );
        foreach ($params as $i => $p) {
            $stmt->bindValue($i + 1, $p);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['total' => (int) $count->fetchColumn(), 'rows' => $stmt->fetchAll()];
    }

    public function users(): array
    {
        return $this->db->query(
            'SELECT DISTINCT u.id, u.full_name, u.username FROM ai_usage_logs l JOIN users u ON u.id = l.user_id ORDER BY u.full_name'
        )->fetchAll();
    }

    private function castRow(array $r): array
    {
        foreach (['requests', 'general_count', 'markdown_count', 'receipt_count', 'error_count'] as $k) {
            $r[$k] = (int) ($r[$k] ?? 0);
        }
        $r['cost'] = round((float) ($r['cost'] ?? 0), 2);
        return $r;
    }
}
