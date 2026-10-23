<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProductRepo;
use App\Services\AuditLogger;
use App\Services\DashboardService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
{
    use JsonResponse;

    public function index(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $service = new DashboardService($db);
        return $this->json($response, [
            'kpis' => $service->kpis(),
            'alerts' => $service->alerts(),
            'recent_sales' => $service->recentSales(),
            'top_sellers' => $service->topSellers7Days(),
        ]);
    }

    /** Tables behind one KPI card, for the dashboard's detail dialog. */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (!in_array($args['key'], DashboardService::DETAILS, true)) {
            return $this->error($response, 'ไม่พบรายละเอียดนี้', 404);
        }
        return $this->json($response, ['data' => (new DashboardService(Db::connection()))->detail($args['key'])]);
    }

    /** ?period=week|month|year&date=Y-m-d (any day inside the period; default today). */
    public function salesChart(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $date = (string) ($q['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $service = new DashboardService(Db::connection());
        return $this->json($response, ['data' => $service->salesSeries((string) ($q['period'] ?? 'week'), $date)]);
    }
}
