<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProductRepo;
use App\Services\AttachmentService;
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

    /** GET /stockcounts/{id}/lookup?code= — product of a scanned or typed barcode. */
    public function lookup(Request $request, Response $response, array $args): Response
    {
        $code = (string) ($request->getQueryParams()['code'] ?? '');
        $found = $this->service()->lookup((int) $args['id'], $code);
        if (!$found) {
            return $this->error($response, "ไม่พบสินค้าที่มีบาร์โค้ด {$code}", 404);
        }
        return $this->json($response, ['data' => $found]);
    }

    public function search(Request $request, Response $response, array $args): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if (mb_strlen($q) < 1) {
            return $this->json($response, ['data' => []]);
        }
        return $this->json($response, ['data' => $this->service()->search((int) $args['id'], $q)]);
    }

    /** POST /stockcounts/{id}/count {product_id, counted_qty} — one product counted on the phone. */
    public function countOne(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        try {
            $data = $this->service()->setCount((int) $args['id'], (int) ($body['product_id'] ?? 0), (int) ($body['counted_qty'] ?? -1));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $data]);
    }

    /** POST /stockcounts/{id}/barcode {product_id, barcode, pack_size} */
    public function assignBarcode(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $productId = (int) ($body['product_id'] ?? 0);
        $db = Db::connection();
        $db->beginTransaction();
        try {
            (new ProductRepo($db, new AuditLogger($db)))->assignBarcode(
                $productId, (string) ($body['barcode'] ?? ''), max(1, (int) ($body['pack_size'] ?? 1)),
                (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($e instanceof \RuntimeException) {
                return $this->error($response, $e->getMessage(), 422);
            }
            throw $e;
        }
        return $this->json($response, ['data' => $this->service()->productInfo((int) $args['id'], $productId)]);
    }

    /** POST /products/{id}/photo {data_url} */
    public function savePhoto(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $db = Db::connection();
        $repo = new ProductRepo($db, new AuditLogger($db));
        $id = (int) $args['id'];
        if (!$repo->find($id)) {
            return $this->error($response, 'ไม่พบสินค้า', 404);
        }
        try {
            $row = (new AttachmentService($db))->saveDataUrl((string) ($body['data_url'] ?? ''), (int) $user['id'], 'products');
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        $repo->setImage($id, $row['token'], (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        return $this->json($response, ['data' => ['image_token' => $row['token']]]);
    }

    /** GET /products/{id}/photo — any signed-in user may see product photos. */
    public function photo(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $product = (new ProductRepo($db, new AuditLogger($db)))->find((int) $args['id']);
        $service = new AttachmentService($db);
        $row = $product && $product['image_token'] ? $service->findByToken($product['image_token']) : null;
        if (!$row) {
            return $this->error($response, 'ไม่พบรูปสินค้า', 404);
        }
        try {
            $path = $service->absolutePath($row);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 404);
        }
        $response->getBody()->write((string) file_get_contents($path));
        return $response
            ->withHeader('Content-Type', $row['mime'])
            ->withHeader('Cache-Control', 'private, max-age=86400')
            ->withHeader('X-Content-Type-Options', 'nosniff');
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
