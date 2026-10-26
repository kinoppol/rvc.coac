<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProductRepo;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\ReturnService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReturnController
{
    use JsonResponse;

    public function stockFor(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $repo = new ProductRepo($db, new AuditLogger($db));
        return $this->json($response, ['data' => $repo->inStockForConsignor((int) $args['id'])]);
    }

    private static function service(\PDO $db): ReturnService
    {
        $audit = new AuditLogger($db);
        return new ReturnService($db, new ProductRepo($db, $audit), new DocumentNumberService($db), $audit);
    }

    /** Return documents already saved (?q= doc no., consignor, recipient or product name). */
    public function documents(Request $request, Response $response): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        return $this->json($response, ['data' => self::service(Db::connection())->documents($q)]);
    }

    public function document(Request $request, Response $response, array $args): Response
    {
        $doc = self::service(Db::connection())->document((int) $args['id']);
        return $doc ? $this->json($response, ['data' => $doc]) : $this->error($response, 'ไม่พบใบคืนสินค้า', 404);
    }

    /** Admin only: cancel a return with a reason (its goods go back into stock). */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (($user['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้นที่ยกเลิกการคืนสินค้าได้', 403);
        }
        $reason = (string) (((array) $request->getParsedBody())['reason'] ?? '');
        try {
            $doc = self::service(Db::connection())->cancel((int) $args['id'], $reason, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $doc]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $consignorId = (int) ($body['consignor_id'] ?? 0);
        $items = $body['items'] ?? [];
        if (!$consignorId || !is_array($items) || empty($items)) {
            return $this->error($response, 'กรุณาเลือกผู้ฝากขายและระบุจำนวนที่คืนอย่างน้อย 1 รายการ', 422);
        }

        $db = Db::connection();
        $audit = new AuditLogger($db);
        $service = new ReturnService($db, new ProductRepo($db, $audit), new DocumentNumberService($db), $audit);

        $user = $request->getAttribute('user');
        try {
            $result = $service->returnGoods(
                $consignorId,
                $items,
                $body['recipient_name'] ?? null,
                (int) $user['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? null
            );
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }

        return $this->json($response, ['data' => $result], 201);
    }
}
