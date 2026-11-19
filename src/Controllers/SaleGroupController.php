<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\SaleGroupService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sale groups (migration 0084): look-alike store goods with no barcode sold on the POS as one item.
 * Admin / manager set them up from the stock page.
 */
class SaleGroupController
{
    use JsonResponse;

    private function service(): SaleGroupService
    {
        $db = Db::connection();
        return new SaleGroupService($db, new AuditLogger($db));
    }

    private function run(Request $request, Response $response, callable $fn): Response
    {
        if (!in_array($request->getAttribute('user')['role'] ?? '', ['admin', 'manager'], true)) {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบหรือผู้จัดการร้านเท่านั้น', 403);
        }
        try {
            return $this->json($response, ['data' => $fn()]);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
    }

    /** GET /sale-groups — every group with its members. */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => $this->service()->all(), 'ready' => SaleGroupService::ready(Db::connection())]);
    }

    /** POST /sale-groups {name, sale_price, product_ids} */
    public function create(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        return $this->run($request, $response, fn () => $this->service()->create($body, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null));
    }

    /** PUT /sale-groups/{id} {name?, sale_price?, add?, remove?} */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        return $this->run($request, $response, fn () => $this->service()->update((int) $args['id'], $body, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null));
    }

    /** POST /sale-groups/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        return $this->run($request, $response, fn () => $this->service()->delete((int) $args['id'], (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null));
    }
}
