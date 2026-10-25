<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AiProviderRepo;
use App\Repositories\ProductRepo;
use App\Services\AiClient;
use App\Services\AiUsageService;
use App\Services\AttachmentService;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\PurchaseService;
use App\Services\ReceiptExtractor;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PurchaseController
{
    use JsonResponse;

    public static function service(): PurchaseService
    {
        $db = Db::connection();
        $audit = new AuditLogger($db);
        return new PurchaseService(
            $db,
            new ProductRepo($db, $audit),
            new SettingsService($db),
            new DocumentNumberService($db),
            new AttachmentService($db),
            $audit
        );
    }

    public function index(Request $request, Response $response): Response
    {
        $service = self::service();
        // ?q= &status= &range=all|today|week|month|year|custom (&from= &to=) &page= &per=
        $q = $request->getQueryParams();
        $range = (string) ($q['range'] ?? 'all');
        [$from, $to] = $range === 'all' ? [null, null] : \App\Services\ReportService::resolveRange($range, $q['from'] ?? null, $q['to'] ?? null);
        $list = $service->search([
            'q' => (string) ($q['q'] ?? ''), 'status' => (string) ($q['status'] ?? ''), 'from' => $from, 'to' => $to,
            'page' => (int) ($q['page'] ?? 1), 'per' => (int) ($q['per'] ?? 10),
        ]);
        return $this->json($response, $list + ['from' => $from, 'to' => $to, 'markup_percent' => $service->markupPercent(), 'buyers' => $service->buyers()]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $doc = self::service()->find((int) $args['id']);
        if (!$doc) {
            return $this->error($response, 'ไม่พบใบซื้อ', 404);
        }
        return $this->json($response, ['data' => $doc]);
    }

    public function preview(Request $request, Response $response): Response
    {
        $items = (array) (((array) $request->getParsedBody())['items'] ?? []);
        return $this->json($response, ['data' => self::service()->preview(array_slice($items, 0, 300))]);
    }

    /**
     * Store products to pair a receipt line with (?q= name or barcode): goods first
     * recorded by a sale or a POS import, before any purchase, so the receipt restocks them.
     */
    public function products(Request $request, Response $response): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $stmt = Db::connection()->prepare(
            "SELECT p.id, p.name, p.barcode, p.qty_stock, p.cost_price, p.sale_price,
                    (SELECT GROUP_CONCAT(CONCAT(b.barcode, '×', b.pack_size) SEPARATOR ', ') FROM product_pack_barcodes b WHERE b.product_id = p.id) AS packs
             FROM products p
             WHERE p.consignor_id IS NULL AND (? = '' OR p.name LIKE ? OR p.barcode = ?
                   OR EXISTS (SELECT 1 FROM product_pack_barcodes b WHERE b.product_id = p.id AND b.barcode = ?))
             ORDER BY p.qty_stock < 0 DESC, p.name LIMIT 40"
        );
        // Thai names have no spaces and spellings vary (สตรอเบอร์รี่ / สตรอว์เบอร์รี่), so when the
        // whole text finds nothing, retry with a shorter start of it (down to 3 letters).
        $term = $q;
        while (true) {
            $stmt->execute([$term, '%' . addcslashes($term, '%_\\') . '%', $term, $term]);
            $rows = $stmt->fetchAll();
            $len = mb_strlen($term);
            if ($rows || $len <= 3) {
                break;
            }
            $term = mb_substr($term, 0, max(3, $len - max(2, intdiv($len, 4))));
        }
        // 'matched' tells the picker it is showing results for a shorter word.
        return $this->json($response, ['data' => $rows, 'matched' => $rows && $term !== $q ? $term : null]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $images = (new AttachmentService(Db::connection()))->ownedBy((array) ($body['attachments'] ?? []), (int) $user['id']);
        try {
            // order: goods ordered for delivery later, saved without touching stock.
            $result = !empty($body['order']) ? self::service()->createOrder(
                (array) ($body['header'] ?? []),
                (array) ($body['items'] ?? []),
                $images,
                ($body['source'] ?? '') === 'ai' ? 'ai' : 'manual',
                (int) $user['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? null,
                !empty($body['draft']) // a draft: not sent to the supplier yet
            ) : self::service()->create(
                (array) ($body['header'] ?? []),
                (array) ($body['items'] ?? []),
                $images,
                ($body['source'] ?? '') === 'ai' ? 'ai' : 'manual',
                (int) $user['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? null
            );
        } catch (\RuntimeException $e) {
            if (!empty($body['order']) && $e instanceof \PDOException) {
                return $this->error($response, 'ยังบันทึกใบสั่งซื้อไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0041 และ 0042) ก่อน', 422);
            }
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $result], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];
        $attachments = new AttachmentService(Db::connection());
        $tokens = array_map('strval', (array) ($body['attachments'] ?? []));
        $current = array_column($attachments->forPurchase($id), 'token');
        try {
            $result = self::service()->update(
                $id,
                (array) ($body['header'] ?? []),
                (array) ($body['items'] ?? []),
                array_values(array_intersect($tokens, $current)),
                $attachments->ownedBy(array_diff($tokens, $current), (int) $user['id']),
                (int) $user['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? null,
                !empty($body['receive']) // an order: confirm the delivery into stock
            );
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $result]);
    }

    /** A draft order was sent to the supplier: it now waits for delivery. */
    public function place(Request $request, Response $response, array $args): Response
    {
        try {
            $result = self::service()->placeOrder((int) $args['id'], (int) $request->getAttribute('user')['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $result]);
    }

    /** Replaces the receipt evidence (photos / .md files) of a saved purchase without touching its lines. */
    public function attachments(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];
        $attachments = new AttachmentService(Db::connection());
        $tokens = array_map('strval', (array) ($body['attachments'] ?? []));
        $current = array_column($attachments->forPurchase($id), 'token');
        try {
            self::service()->setAttachments(
                $id,
                $tokens,
                $attachments->ownedBy(array_diff($tokens, $current), (int) $user['id']),
                (int) $user['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? null
            );
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => self::service()->find($id)]);
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        try {
            $result = self::service()->cancel(
                (int) $args['id'],
                (string) ($body['reason'] ?? ''),
                (int) $request->getAttribute('user')['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? null
            );
        } catch (\RuntimeException $e) {
            $missingColumn = $e instanceof \PDOException && ($e->errorInfo[0] ?? '') === '42S22';
            return $this->error($response, $missingColumn
                ? 'ฐานข้อมูลยังไม่ได้อัปเดต — ผู้ดูแลระบบต้องรัน Migration ที่ค้างอยู่ (เมนู ระบบ > Migrations) ก่อนจึงจะยกเลิกใบซื้อได้'
                : $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $result]);
    }

    /** Reads receipt photos with a vision model and returns a draft for the form. */
    public function extract(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $db = Db::connection();
        $repo = new AiProviderRepo($db);

        $resolved = $repo->resolveEnabledModel((int) ($body['model'] ?? 0), $user['role']);
        if (!$resolved) {
            return $this->error($response, 'ไม่พบโมเดลที่เลือก หรือโมเดลถูกปิดใช้งาน', 422);
        }
        [$provider, $model] = $resolved;
        if (!$model['supports_vision']) {
            return $this->error($response, 'โมเดลนี้ยังไม่ได้ตั้งค่าว่าอ่านรูปได้ (ตั้งค่าได้ที่หน้า ตั้งค่าผู้ช่วย AI)', 422);
        }

        $attachments = new AttachmentService($db);
        $images = array_values(array_filter(
            $attachments->ownedBy((array) ($body['attachments'] ?? []), (int) $user['id']),
            [AttachmentService::class, 'isImage']
        ));
        if (!$images) {
            return $this->error($response, 'กรุณาแนบรูปใบเสร็จอย่างน้อย 1 รูป', 422);
        }
        if (count($images) > 10) {
            return $this->error($response, 'แนบรูปได้ไม่เกิน 10 รูปต่อใบเสร็จ', 422);
        }

        set_time_limit(300);
        $client = new AiClient($provider['base_url'], $repo->apiKey($provider), $repo->extraHeaders($provider), 240);
        $hint = trim((string) ($body['hint'] ?? ''));
        $usage = [
            'user_id' => (int) $user['id'],
            'channel' => 'receipt_extract',
            'category' => 'receipt',
            'prompt' => 'อ่านใบเสร็จจากรูป ' . count($images) . ' รูป (หน้าซื้อสินค้าเข้าร้าน)' . ($hint !== '' ? ": {$hint}" : ''),
            'model_row_id' => (int) $model['id'],
            'model_name' => $model['model_id'],
            'provider_name' => $provider['name'],
            'image_count' => count($images),
        ];
        $usageService = new AiUsageService($db, new SettingsService($db));
        $started = microtime(true);
        try {
            $draft = (new ReceiptExtractor($client, $model['model_id']))
                ->extract(array_map([$attachments, 'dataUrl'], $images), $hint);
        } catch (\RuntimeException $e) {
            $usageService->log($usage + ['status' => 'error', 'error_message' => $e->getMessage(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
            return $this->error($response, $e->getMessage(), 422);
        }
        $usageService->log($usage + [
            'response_chars' => mb_strlen(json_encode($draft, JSON_UNESCAPED_UNICODE)),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        $service = self::service();
        return $this->json($response, ['data' => $draft, 'preview' => $service->preview($draft['items'])]);
    }
}
