<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\StockCountService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StockCountController
{
    use JsonResponse;

    private function service(): StockCountService
    {
        $db = Db::connection();
        return new StockCountService($db, new DocumentNumberService($db), new AuditLogger($db));
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => $this->service()->list()]);
    }

    public function start(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = $this->service()->start((int) $user['id']);
        return $this->json($response, ['data' => $this->service()->diffSummary($id) + ['id' => $id, 'stock_count' => $this->service()->get($id)]], 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $sc = $this->service()->get($id);
        if (!$sc) {
            return $this->error($response, 'ไม่พบรอบตรวจนับ', 404);
        }
        return $this->json($response, ['data' => $this->service()->diffSummary($id) + ['stock_count' => $sc]]);
    }

    public function saveCounts(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        $items = $body['items'] ?? [];
        if (!is_array($items)) {
            return $this->error($response, 'ข้อมูลไม่ถูกต้อง', 422);
        }
        $this->service()->saveCounts($id, $items);
        return $this->json($response, ['data' => $this->service()->diffSummary($id)]);
    }

    public function submit(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        try {
            $this->service()->submit($id);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $this->service()->get($id)]);
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];
        try {
            $this->service()->approve($id, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $this->service()->get($id)]);
    }
}
