<?php

declare(strict_types=1);

namespace App\Controllers;

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
}
