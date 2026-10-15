<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Services\AiUsageService;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** AI usage log and estimated cost summaries (daily / weekly / monthly). */
class AiUsageController
{
    use JsonResponse;

    private const PERIODS = ['day', 'week', 'month'];

    private function service(): AiUsageService
    {
        $db = Db::connection();
        return new AiUsageService($db, new SettingsService($db));
    }

    /** Default look-back per period: 30 days, 12 weeks, 12 months. */
    private function filters(array $q): array
    {
        $period = in_array($q['period'] ?? '', self::PERIODS, true) ? $q['period'] : 'day';
        $today = new \DateTimeImmutable('today');
        $valid = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $defaultFrom = match ($period) {
            'week' => $today->modify('-' . ((int) $today->format('N') - 1) . ' days')->modify('-11 weeks'),
            'month' => $today->modify('first day of this month')->modify('-11 months'),
            default => $today->modify('-29 days'),
        };
        $from = $valid($q['from'] ?? null) ? $q['from'] : $defaultFrom->format('Y-m-d');
        $to = $valid($q['to'] ?? null) ? $q['to'] : $today->format('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'user_id' => (int) ($q['user_id'] ?? 0) ?: null,
            'category' => $q['category'] ?? null,
            'q' => trim((string) ($q['q'] ?? '')),
        ];
    }

    public function summary(Request $request, Response $response): Response
    {
        $f = $this->filters($request->getQueryParams());
        $s = $this->service();
        $user = $request->getAttribute('user');
        return $this->json($response, [
            'filters' => $f,
            'current' => $s->currentTotals(),
            'totals' => $s->totals($f),
            'summary' => $s->summary($f['period'], $f),
            'by_user' => $s->byUser($f),
            'users' => $s->users(),
            'rates' => $s->rates(),
            'category_labels' => AiUsageService::CATEGORY_LABEL,
            'can_edit_rates' => (new UserRepo(Db::connection()))->hasPermission($user['role'], 'ai.manage'),
        ]);
    }

    public function logs(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $f = $this->filters($q);
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = 50;
        $result = $this->service()->logs($f, $perPage, ($page - 1) * $perPage);
        return $this->json($response, $result + ['page' => $page, 'per_page' => $perPage]);
    }

    public function export(Request $request, Response $response): Response
    {
        $f = $this->filters($request->getQueryParams());
        $rows = $this->service()->logs($f, 100000, 0)['rows'];
        $labels = AiUsageService::CATEGORY_LABEL;
        $csvRows = array_map(fn ($r) => [
            $r['created_at'], $r['full_name'], $r['username'], $labels[$r['category']] ?? $r['category'],
            $r['cost'], $r['status'] === 'ok' ? 'สำเร็จ' : 'ผิดพลาด', $r['prompt'], $r['provider_name'], $r['model_name'],
            $r['image_count'], $r['response_chars'], $r['tool_calls'], $r['duration_ms'], $r['error_message'],
        ], $rows);
        $csv = (new ReportService(Db::connection()))->toCsv($csvRows, [
            'เวลา', 'ผู้ใช้', 'ชื่อผู้ใช้', 'ประเภท', 'ค่าใช้จ่าย (บาท)', 'สถานะ', 'คำสั่ง', 'ผู้ให้บริการ', 'โมเดล',
            'จำนวนรูป', 'ความยาวคำตอบ', 'เครื่องมือที่เรียก', 'เวลาที่ใช้ (ms)', 'ข้อผิดพลาด',
        ]);
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"ai-usage-{$f['from']}_to_{$f['to']}.csv\"");
    }

    /** Rates apply to requests logged from now on; past rows keep the cost they were logged with. */
    public function updateRates(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $values = [];
        foreach (AiUsageService::RATE_KEYS as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if (!is_numeric($body[$key]) || (float) $body[$key] < 0) {
                return $this->error($response, 'ค่าที่กรอกต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป', 422);
            }
            $values[$key] = str_contains($key, '_min_') ? (string) (int) $body[$key] : (string) round((float) $body[$key], 2);
        }
        $db = Db::connection();
        (new SettingsService($db))->update($values, (int) $request->getAttribute('user')['id']);
        return $this->json($response, ['rates' => $this->service()->rates()]);
    }
}
