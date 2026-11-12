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

    /** Whether a round is open: the phone counts single items on the spot only when none is. */
    public function openRound(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => ['open' => $this->service()->hasOpenRound()]]);
    }

    /**
     * POST /products/{id}/quick-count {counted_qty, photo?, place?, method?, note?, reason?, reason_detail?}
     * Count one product with no round open (migration 0077). A difference from the system needs a
     * reason (StockCountService::DIFF_REASONS) and reason_detail, else 422 with need_reason. Up to
     * ITEM_APPROVAL_OVER pieces off the stock is set at once; more waits for a manager (pending).
     */
    public function quickCount(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        if (!isset($body['counted_qty']) || !is_numeric($body['counted_qty']) || (int) $body['counted_qty'] != $body['counted_qty']) {
            return $this->error($response, 'กรุณาระบุจำนวนที่นับเป็นจำนวนเต็ม', 422);
        }
        $detail = [
            'place' => $body['place'] ?? null,
            'method' => $body['method'] ?? $body['photo_note'] ?? null,
            'note' => $body['note'] ?? null,
            'reason' => $body['reason'] ?? null,
            'reason_detail' => $body['reason_detail'] ?? null,
        ];
        try {
            $result = $this->service()->itemCount((int) $args['id'], (int) $body['counted_qty'], $detail, (int) $user['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? null, isset($body['photo']) ? (string) $body['photo'] : null);
        } catch (\RuntimeException $e) {
            $db = Db::connection();
            $product = (new ProductRepo($db, new AuditLogger($db)))->find((int) $args['id']);
            // need_reason: a difference with no (or too short an) explanation; system_qty is what it is compared with.
            $response->getBody()->write((string) json_encode([
                'error' => $e->getMessage(), 'need_reason' => $e->getCode() === 1,
                'system_qty' => $product ? (int) $product['qty_stock'] : null,
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }
        $db = Db::connection();
        $product = (new ProductRepo($db, new AuditLogger($db)))->find((int) $args['id']);
        return $this->json($response, ['data' => $result + ['qty_stock' => (int) $product['qty_stock']]]);
    }

    /**
     * GET /stockcounts/items[?product_id=] — item counts waiting for a manager and the latest ones,
     * with all that was recorded about them, plus the reasons to choose from and the threshold.
     */
    public function itemCounts(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $pid = (int) ($request->getQueryParams()['product_id'] ?? 0);
        $db = Db::connection();
        $data = $this->service()->itemCounts($pid > 0 ? $pid : null);
        $data['reasons'] = StockCountService::DIFF_REASONS;
        $data['approval_over'] = StockCountService::ITEM_APPROVAL_OVER;
        $data['detail_min'] = StockCountService::DIFF_DETAIL_MIN;
        $data['open_round'] = $this->service()->hasOpenRound();
        $data['can_approve'] = ($user['role'] ?? '') !== \App\Middleware\ReadOnlyGuard::ROLE
            && (new UserRepo($db))->hasPermission($user['role'], 'stockcount.approve');
        $data['user_id'] = (int) $user['id'];
        return $this->json($response, ['data' => $data]);
    }

    /** POST /stockcounts/items/{id}/approve {note?} — a manager certifies an item count that differs by more than the threshold. */
    public function approveItem(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        try {
            $result = $this->service()->approveItem((int) $args['id'], (int) $user['id'], (string) ($user['role'] ?? ''),
                isset($body['note']) ? (string) $body['note'] : null, $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $result]);
    }

    /** POST /stockcounts/items/{id}/reject {reason} — a manager turns it down, or whoever counted it withdraws it. */
    public function rejectItem(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $approver = ($user['role'] ?? '') !== \App\Middleware\ReadOnlyGuard::ROLE
            && (new UserRepo(Db::connection()))->hasPermission($user['role'], 'stockcount.approve');
        try {
            $result = $this->service()->rejectItem((int) $args['id'], (int) $user['id'], $approver,
                isset($body['reason']) ? (string) $body['reason'] : null, $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $result]);
    }

    public function start(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        try {
            $id = $this->service()->start((int) $user['id']);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
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
        $this->service()->addConsignedLines($id);
        $this->service()->refreshSystemQty($id);
        return $this->json($response, ['data' => $this->service()->diffSummary($id) + ['stock_count' => $sc]]);
    }

    /** Products marked as discontinued. */
    public function discontinued(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => $this->service()->discontinued()]);
    }

    /** Admin only: marks a product as discontinued, or back on sale (body: discontinued). */
    public function setDiscontinued(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (($user['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้น', 403);
        }
        try {
            $this->service()->setDiscontinued((int) $args['id'], !empty(((array) $request->getParsedBody())['discontinued']), (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['ok' => true]);
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
                (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null, !empty($body['replace'])
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($e instanceof \App\Support\NeedsConfirmation) {
                return $this->error($response, $e->getMessage(), 409);
            }
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
        $repo = new ProductRepo($db, new AuditLogger($db));
        $product = $repo->find((int) $args['id']);
        $service = new AttachmentService($db);
        // ?token= one of the product's backup photos (kept at a merge / when a new photo was taken).
        $token = (string) ($request->getQueryParams()['token'] ?? '');
        if ($product && $token !== '' && $token !== $product['image_token']) {
            $row = $repo->isPhotoBackup((int) $product['id'], $token) ? $service->findByToken($token) : null;
        } else {
            $row = $product && $product['image_token'] ? $service->findByToken($product['image_token']) : null;
        }
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

    /** POST /stockcounts/{id}/reopen — approver sends a submitted round back to draft to keep counting. */
    public function reopen(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];
        try {
            $this->service()->reopen($id, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
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
