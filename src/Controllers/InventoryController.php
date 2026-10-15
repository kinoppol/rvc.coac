<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\InventoryService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class InventoryController
{
    use JsonResponse;

    public function index(Request $request, Response $response): Response
    {
        $service = new InventoryService(Db::connection());
        $search = trim((string) ($request->getQueryParams()['q'] ?? ''));
        return $this->json($response, [
            'summary' => $service->summary(),
            'data' => $service->products($search),
        ]);
    }
}
