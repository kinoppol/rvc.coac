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
        'statement', 'profit', 'payments', 'cashiers', 'drawers', 'wages', 'after_hours', 'pos_imports',
        'receive', 'return', 'sales', 'detail', 'fee', 'withdrawals', 'balances', 'consign_stock',
        'store_sales', 'store_stock', 'purchases', 'stock_counts',
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

        return $this->json($response, ['data' => $rows, 'from' => $from, 'to' => $to, 'labels' => ReportService::LABELS]);
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

        $headers = array_map(fn ($k) => ReportService::LABELS[$k] ?? $k, $rows ? array_keys($rows[0]) : []);
        $csvRows = array_map('array_values', $rows);
        $csv = $service->toCsv($csvRows, $headers);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"report-{$type}-{$from}_to_{$to}.csv\"");
    }

    private function rows(ReportService $service, string $type, string $from, string $to, array $q): array
    {
        $consignorId = isset($q['consignor_id']) ? (int) $q['consignor_id'] : null;
        // Profit and payment reports: one row per day, week or month.
        $group = in_array($q['group'] ?? '', ['day', 'week', 'month'], true) ? $q['group'] : 'day';
        return match ($type) {
            'statement' => $service->statement($from, $to),
            'profit' => $service->profit($from, $to, $group),
            'payments' => $service->payments($from, $to, $group),
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
