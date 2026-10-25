<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProductRepo;
use App\Repositories\UserRepo;
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
        $this->service()->purgeOldPhotos(); // counting photos are kept PHOTO_DAYS days
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
        $this->service()->purgeOldPhotos();
        $this->service()->refreshSystemQty($id);
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
    /**
     * POST /stockcounts/{id}/count {product_id, counted_qty, photo, photo_note?} — one product
     * counted on the phone. A photo of what was counted is required (kept PHOTO_DAYS days).
     */
    public function countOne(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $photo = (string) ($body['photo'] ?? '');
        if ($photo === '') {
            return $this->error($response, 'กรุณาถ่ายรูปสินค้าที่นับไว้เป็นหลักฐานก่อนบันทึก', 422);
        }
        $service = $this->service();
        try {
            $service->addPhoto((int) $args['id'], (int) ($body['product_id'] ?? 0), $photo,
                isset($body['photo_note']) ? (string) $body['photo_note'] : null, (int) $request->getAttribute('user')['id']);
            $data = $service->setCount((int) $args['id'], (int) ($body['product_id'] ?? 0), (int) ($body['counted_qty'] ?? -1));
        } catch (\PDOException $e) {
            return $this->error($response, 'ยังบันทึกรูปประกอบการนับไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0044) ก่อน', 422);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $data]);
    }

    /** POST /stockcounts/{id}/photo {product_id, data_url} — a counting photo added from the count table. */
    public function addPhoto(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        try {
            $token = $this->service()->addPhoto((int) $args['id'], (int) ($body['product_id'] ?? 0), (string) ($body['data_url'] ?? ''),
                null, (int) $request->getAttribute('user')['id']);
        } catch (\PDOException $e) {
            return $this->error($response, 'ยังบันทึกรูปประกอบการนับไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0044) ก่อน', 422);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => ['token' => $token]], 201);
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
        $service = new AttachmentService($db);
        if (!$service->saveThumbDataUrl($row['token'], (string) ($body['thumb_data_url'] ?? ''))) {
            $service->makeThumb($row);
        }
        return $this->json($response, ['data' => ['image_token' => $row['token']]]);
    }

    /**
     * GET /products/{id}/photo — any signed-in user may see product photos.
     * size=thumb returns the small copy (made with GD here when possible, else
     * the original until a browser uploads one). The URL carries the photo's
     * token, so a real thumbnail or original can be cached for good.
     */
    public function photo(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $product = (new ProductRepo($db, new AuditLogger($db)))->find((int) $args['id']);
        $service = new AttachmentService($db);
        $row = $product && $product['image_token'] ? $service->findByToken($product['image_token']) : null;
        if (!$row) {
            return $this->error($response, 'ไม่พบรูปสินค้า', 404);
        }
        $thumb = ($request->getQueryParams()['size'] ?? '') === 'thumb';
        $path = null;
        if ($thumb) {
            $path = $service->hasThumb($row['token']) ? $service->thumbPath($row['token']) : $service->makeThumb($row);
        }
        $mime = $path ? 'image/jpeg' : $row['mime'];
        // An original sent in place of a missing thumbnail is cached only briefly,
        // so the thumbnail is picked up once it exists.
        $cache = $thumb && !$path ? 'private, max-age=300' : 'private, max-age=31536000, immutable';
        if ($path === null) {
            try {
                $path = $service->absolutePath($row);
            } catch (\RuntimeException $e) {
                return $this->error($response, $e->getMessage(), 404);
            }
        }
        $etag = '"' . $row['token'] . ($thumb ? '-t' . (int) filesize($path) : '') . '"';
        $response = $response
            ->withHeader('Cache-Control', $cache)
            ->withHeader('ETag', $etag)
            ->withHeader('X-Content-Type-Options', 'nosniff');
        if (trim($request->getHeaderLine('If-None-Match')) === $etag) {
            return $response->withStatus(304);
        }
        $response->getBody()->write((string) file_get_contents($path));
        return $response->withHeader('Content-Type', $mime);
    }

    /** POST /products/{id}/photo/thumb — a browser-made thumbnail for a photo that has none yet. */
    public function saveThumb(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $product = (new ProductRepo($db, new AuditLogger($db)))->find((int) $args['id']);
        if (!$product || !$product['image_token']) {
            return $this->error($response, 'ไม่พบรูปสินค้า', 404);
        }
        $saved = (new AttachmentService($db))->saveThumbDataUrl($product['image_token'], (string) (((array) $request->getParsedBody())['data_url'] ?? ''));
        return $this->json($response, ['data' => ['saved' => $saved]]);
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

    /** POST /stockcounts/{id}/cancel — a submitted round may only be cancelled by an approver. */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];
        $sc = $this->service()->get($id);
        if (!$sc) {
            return $this->error($response, 'ไม่พบรอบตรวจนับ', 404);
        }
        if ($sc['status'] === 'submitted' && !(new UserRepo(Db::connection()))->hasPermission($user['role'], 'stockcount.approve')) {
            return $this->error($response, 'รอบตรวจนับนี้ส่งรายงานแล้ว ต้องให้ผู้อนุมัติเป็นผู้ยกเลิก', 403);
        }
        try {
            $this->service()->cancel($id, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $this->service()->get($id)]);
    }
}
