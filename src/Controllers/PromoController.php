<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\PromoService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Bundle promotions (migration 0086): goods bought together sell for less on the web POS.
 * Anyone with reports.view sees them; admin / manager set them up.
 */
class PromoController
{
    use JsonResponse;

    private function service(): PromoService
    {
        $db = Db::connection();
        return new PromoService($db, new AuditLogger($db));
    }

    private static function canManage(Request $request): bool
    {
        return in_array($request->getAttribute('user')['role'] ?? '', ['admin', 'manager'], true);
    }

    private function run(Request $request, Response $response, callable $fn): Response
    {
        if (!self::canManage($request)) {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบหรือผู้จัดการร้านเท่านั้น', 403);
        }
        try {
            return $this->json($response, ['data' => $fn((int) $request->getAttribute('user')['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null)]);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
    }

    /** GET /promos — every promotion with its slots, and whether this user may change them. */
    public function index(Request $request, Response $response): Response
    {
        $db = Db::connection();
        return $this->json($response, [
            'data' => $this->service()->all(),
            'ready' => PromoService::ready($db),
            'can_manage' => self::canManage($request),
            'kinds' => PromoService::KINDS,
        ]);
    }

    /** GET /promos/items?q= — products and sale groups a slot can hold. */
    public function items(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => $this->service()->searchItems((string) ($request->getQueryParams()['q'] ?? ''))]);
    }

    /** POST /promos {name, kind, value, starts_on?, ends_on?, active?, note?, slots} */
    public function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        return $this->run($request, $response, fn ($uid, $ip) => $this->service()->save(null, $body, $uid, $ip));
    }

    /** PUT /promos/{id} — same body as create. */
    public function update(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        return $this->run($request, $response, fn ($uid, $ip) => $this->service()->save((int) $args['id'], $body, $uid, $ip));
    }

    /** POST /promos/{id}/active {active} */
    public function setActive(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        return $this->run($request, $response, fn ($uid, $ip) => $this->service()->setActive((int) $args['id'], !empty($body['active']), $uid, $ip));
    }

    /** POST /promos/{id}/delete */
    public function delete(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn ($uid, $ip) => $this->service()->delete((int) $args['id'], $uid, $ip));
    }

    /** POST /sales/promo-quote {items} — the bundle discounts of a POS cart (same lines as POST /sales). */
    public function quote(Request $request, Response $response): Response
    {
        $items = ((array) $request->getParsedBody())['items'] ?? [];
        if (!is_array($items)) {
            return $this->error($response, 'รายการไม่ถูกต้อง', 422);
        }
        return $this->json($response, ['data' => (new PromoService(Db::connection()))->quote(array_values($items))]);
    }
}
