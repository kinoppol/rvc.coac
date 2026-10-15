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
}
