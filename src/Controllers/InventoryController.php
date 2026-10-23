<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ConsignorRepo;
use App\Repositories\ProductRepo;
use App\Services\AuditLogger;
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

    /** Name, piece barcode (scanned at the POS) and the pack barcodes of one store product. */
    public function updateBarcodes(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $db = Db::connection();
        $repo = new ProductRepo($db, new AuditLogger($db));
        $id = (int) $args['id'];
        $db->beginTransaction();
        try {
            $repo->updateBarcodes($id, $body['barcode'] ?? null, (array) ($body['pack_barcodes'] ?? []), (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null, isset($body['name']) ? (string) $body['name'] : null);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($e instanceof \RuntimeException) {
                return $this->error($response, $e->getMessage(), 422);
            }
            throw $e;
        }
        $p = $repo->find($id);
        return $this->json($response, ['data' => ['name' => $p['name'], 'barcode' => $p['barcode'], 'pack_barcodes' => $repo->packBarcodes($id)]]);
    }

    /** Admin only: a store product that is really consignment goods moves to its consignor. */
    public function convertToConsignment(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (($user['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้นที่เปลี่ยนเป็นสินค้าฝากขายได้', 403);
        }
        $body = (array) $request->getParsedBody();
        $consignorId = (int) ($body['consignor_id'] ?? 0);
        if (!isset($body['cost_price']) || !is_numeric($body['cost_price'])) {
            return $this->error($response, 'กรุณาระบุราคาที่ผู้ฝากได้ต่อชิ้น', 422);
        }
        $db = Db::connection();
        $audit = new AuditLogger($db);
        if (!$consignorId || !(new ConsignorRepo($db, $audit))->find($consignorId)) {
            return $this->error($response, 'กรุณาเลือกผู้ฝากขาย', 422);
        }
        $db->beginTransaction();
        try {
            (new ProductRepo($db, $audit))->convertToConsignment((int) $args['id'], $consignorId, (float) $body['cost_price'], (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($e instanceof \RuntimeException) {
                return $this->error($response, $e->getMessage(), 422);
            }
            throw $e;
        }
        return $this->json($response, ['ok' => true]);
    }
}
