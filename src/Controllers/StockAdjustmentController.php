<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\StockAdjustmentService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Corrections of the "ปรับยอด" rows on a product's stock card (migration 0076). */
class StockAdjustmentController
{
    use JsonResponse;

    private function service(): StockAdjustmentService
    {
        $db = Db::connection();
        return new StockAdjustmentService($db, new DocumentNumberService($db), new AuditLogger($db));
    }

    /** POST /inventory/{id}/stock-adjustments {cause, count_doc_no?, original_qty, qty, note} */
    public function create(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        return $this->run($response, fn ($user, $ip) => $this->service()->create((int) $args['id'], $body, $user, $ip), $request, 201);
    }

    /** PUT /inventory/stock-adjustments/{id} {qty, note} */
    public function update(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        return $this->run($response, fn ($user, $ip) => $this->service()->update((int) $args['id'], $body, $user, $ip), $request);
    }

    /** POST /inventory/stock-adjustments/{id}/cancel {reason} */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        return $this->run($response, fn ($user, $ip) => $this->service()->cancel((int) $args['id'], (string) ($body['reason'] ?? ''), $user, $ip), $request);
    }

    private function run(Response $response, callable $fn, Request $request, int $status = 200): Response
    {
        $user = $request->getAttribute('user');
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        try {
            return $this->json($response, ['data' => $fn((int) $user['id'], $ip)], $status);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
    }
}
