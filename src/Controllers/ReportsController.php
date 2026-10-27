<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ReportService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReportsController
{
    use JsonResponse;

    private const TYPES = [
        'statement', 'profit', 'payments', 'daily_payments', 'cashiers', 'drawers', 'wages', 'after_hours', 'pos_imports',
        'receive', 'return', 'sales', 'sales_items', 'detail', 'fee', 'withdrawals', 'balances', 'consign_stock',
        'store_sales', 'store_stock', 'purchases', 'stock_counts', 'capital',
    ];

    public function show(Request $request, Response $response, array $args): Response
    {
        $type = $args['type'];
        if (!in_array($type, self::TYPES, true)) {
            return $this->error($response, 'ไม่รู้จักประเภทรายงานนี้', 404);
        }
        $q = $request->getQueryParams();
        [$from, $to] = ReportService::resolveRange($q['range'] ?? 'today', $q['from'] ?? null, $q['to'] ?? null);

        $service = new ReportService(Db::connection());
        $rows = $this->rows($service, $type, $from, $to, $q);

        return $this->json($response, [
            'data' => $rows, 'from' => $from, 'to' => $to, 'labels' => ReportService::LABELS,
            // columns whose figures open the records behind them (rows carry _from/_to)
            'drill' => $type === 'capital' ? ReportService::CAPITAL_DRILL : [],
        ]);
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        $type = $args['type'];
        if (!in_array($type, self::TYPES, true)) {
            return $this->error($response, 'ไม่รู้จักประเภทรายงานนี้', 404);
        }
        $q = $request->getQueryParams();
        [$from, $to] = ReportService::resolveRange($q['range'] ?? 'today', $q['from'] ?? null, $q['to'] ?? null);

        $service = new ReportService(Db::connection());
        $rows = $this->rows($service, $type, $from, $to, $q);

        $rows = array_map(function ($r) {
            unset($r['key']); // internal: which statement line can be opened
            // internal: _from/_to are the days a row's figures can be opened for
            return array_filter($r, fn ($k) => !str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY);
        }, $rows);
        $headers = array_map(fn ($k) => ReportService::LABELS[$k] ?? $k, $rows ? array_keys($rows[0]) : []);
        $csvRows = array_map('array_values', $rows);
        $csv = $service->toCsv($csvRows, $headers);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"report-{$type}-{$from}_to_{$to}.csv\"");
    }

    /** The records behind one figure of รายงานงบลงทุน (?column=capital_purchases&from=…&to=…). */
    public function capitalSource(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : null;
        $from = $date($q['from'] ?? null);
        $to = $date($q['to'] ?? null);
        if (!$from || !$to || !in_array($q['column'] ?? '', ReportService::CAPITAL_DRILL, true)) {
            return $this->error($response, 'ไม่พบที่มาของตัวเลขนี้', 404);
        }
        return $this->json($response, ['data' => (new ReportService(Db::connection()))->capitalSource((string) $q['column'], $from, $to)]);
    }

    /** The records behind one ผลประกอบการ line (?line=consign_fee&range=…). */
    public function statementSource(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        [$from, $to] = ReportService::resolveRange($q['range'] ?? 'today', $q['from'] ?? null, $q['to'] ?? null);
        try {
            $data = (new ReportService(Db::connection()))->statementSource((string) ($q['line'] ?? ''), $from, $to);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 404);
        }
        return $this->json($response, ['data' => $data]);
    }

    private function rows(ReportService $service, string $type, string $from, string $to, array $q): array
    {
        $consignorId = isset($q['consignor_id']) ? (int) $q['consignor_id'] : null;
        // Profit and payment reports: one row per day, week or month.
        $group = in_array($q['group'] ?? '', ['day', 'week', 'month'], true) ? $q['group'] : 'day';
        return match ($type) {
            'statement' => $service->statement($from, $to),
            'capital' => $service->capital($from, $to),
            'profit' => $service->profit($from, $to, $group),
            'payments' => $service->payments($from, $to, $group),
            'daily_payments' => $service->dailyCashTransfer($from, $to),
            'cashiers' => $service->salesByCashier($from, $to),
            'drawers' => $service->drawers($from, $to),
            'pos_imports' => $service->posImports($from, $to),
            'withdrawals' => $service->withdrawals($from, $to),
            'balances' => $service->balances($from, $to),
            'consign_stock' => $service->consignStock(),
            'stock_counts' => $service->stockCounts($from, $to),
            'receive' => $service->receiveLog($from, $to),
            'return' => $service->returnLog($from, $to),
            'sales' => $service->salesSummaryByConsignor($from, $to),
            'sales_items' => $service->salesByProduct($from, $to),
            'detail' => $consignorId ? $service->saleDetailForConsignor($consignorId, $from, $to) : [],
            'fee' => $service->feeRevenue($from, $to),
            'store_sales' => $service->storeSales($from, $to),
            'store_stock' => $service->storeStock(),
            'purchases' => $service->purchases($from, $to),
            'wages' => $service->wages($from, $to),
            'after_hours' => $service->afterHoursSales($from, $to),
        };
    }
}
