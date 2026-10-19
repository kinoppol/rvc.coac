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
        return $this->json($response, ['data' => $service->recent(), 'markup_percent' => $service->markupPercent()]);
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

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $images = (new AttachmentService(Db::connection()))->ownedBy((array) ($body['attachments'] ?? []), (int) $user['id']);
        try {
            $result = self::service()->create(
                (array) ($body['header'] ?? []),
                (array) ($body['items'] ?? []),
                $images,
                ($body['source'] ?? '') === 'ai' ? 'ai' : 'manual',
                (int) $user['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? null
            );
        } catch (\RuntimeException $e) {
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
                $request->getServerParams()['REMOTE_ADDR'] ?? null
            );
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
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
        $images = $attachments->ownedBy((array) ($body['attachments'] ?? []), (int) $user['id']);
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
