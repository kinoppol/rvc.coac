<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ConsignorRepo;
use App\Repositories\ProductRepo;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\FeeCalculator;
use App\Services\LedgerService;
use App\Services\ListingFeeService;
use App\Services\ReceiveService;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReceiveController
{
    use JsonResponse;

    public function lookupBarcode(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $repo = new ProductRepo($db, new AuditLogger($db));
        $product = $repo->findByBarcode($args['code']);
        if (!$product) {
            return $this->error($response, 'ไม่พบสินค้าตามบาร์โค้ดนี้', 404);
        }
        return $this->json($response, ['data' => $product]);
    }

    public function quote(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $cost = (float) ($body['cost_price'] ?? 0);
        $db = Db::connection();
        $calc = new FeeCalculator(new SettingsService($db));
        return $this->json($response, ['data' => $calc->priceFor($cost)]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $consignorId = (int) ($body['consignor_id'] ?? 0);
        $items = $body['items'] ?? [];
        if (!$consignorId || !is_array($items) || empty($items)) {
            return $this->error($response, 'กรุณาเลือกผู้ฝากขายและเพิ่มรายการสินค้าอย่างน้อย 1 รายการ', 422);
        }

        $db = Db::connection();
        $audit = new AuditLogger($db);
        $settings = new SettingsService($db);
        $service = new ReceiveService(
            $db,
            new ProductRepo($db, $audit),
            new ConsignorRepo($db, $audit),
            new FeeCalculator($settings),
            new ListingFeeService($db, $settings),
            new LedgerService($db),
            new DocumentNumberService($db),
            $audit
        );

        $user = $request->getAttribute('user');
        try {
            $result = $service->receive($consignorId, $items, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }

        return $this->json($response, ['data' => $result], 201);
    }
}
