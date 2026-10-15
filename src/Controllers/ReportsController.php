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

    private const TYPES = ['receive', 'return', 'sales', 'detail', 'fee', 'store_sales', 'store_stock', 'purchases'];

    public function show(Request $request, Response $response, array $args): Response
    {
        $type = $args['type'];
        if (!in_array($type, self::TYPES, true)) {
            return $this->error($response, 'ไม่รู้จักประเภทรายงานนี้', 404);
        }
        $q = $request->getQueryParams();
        [$from, $to] = ReportService::resolveRange($q['range'] ?? 'today', $q['from'] ?? null, $q['to'] ?? null);

        $service = new ReportService(Db::connection());
        $rows = $this->rows($service, $type, $from, $to, isset($q['consignor_id']) ? (int) $q['consignor_id'] : null);

        return $this->json($response, ['data' => $rows, 'from' => $from, 'to' => $to]);
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
        $rows = $this->rows($service, $type, $from, $to, isset($q['consignor_id']) ? (int) $q['consignor_id'] : null);

        $headers = $rows[0] ?? null ? array_keys($rows[0]) : [];
        $csvRows = array_map('array_values', $rows);
        $csv = $service->toCsv($csvRows, $headers);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"report-{$type}-{$from}_to_{$to}.csv\"");
    }

    private function rows(ReportService $service, string $type, string $from, string $to, ?int $consignorId): array
    {
        return match ($type) {
            'receive' => $service->receiveLog($from, $to),
            'return' => $service->returnLog($from, $to),
            'sales' => $service->salesSummaryByConsignor($from, $to),
            'detail' => $consignorId ? $service->saleDetailForConsignor($consignorId, $from, $to) : [],
            'fee' => $service->feeRevenue($from, $to),
            'store_sales' => $service->storeSales($from, $to),
            'store_stock' => $service->storeStock(),
            'purchases' => $service->purchases($from, $to),
        };
    }
}
