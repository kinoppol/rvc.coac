<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProductRepo;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\LedgerService;
use App\Services\ListingFeeService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SalesController
{
    use JsonResponse;

    public function searchProducts(Request $request, Response $response): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $db = Db::connection();
        $stmt = $db->prepare(
            "SELECT p.id, p.name, p.barcode, p.sale_price, p.qty_stock, COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name
             FROM products p LEFT JOIN consignors c ON c.id = p.consignor_id
             WHERE p.qty_stock > 0 AND (p.name LIKE ? OR p.barcode LIKE ?)
             ORDER BY p.name ASC LIMIT 20"
        );
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $items = $body['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            return $this->error($response, 'ไม่มีรายการขาย', 422);
        }

        $db = Db::connection();
        $audit = new AuditLogger($db);
        $settings = new SettingsService($db);
        $service = new SaleService(
            $db,
            new ProductRepo($db, $audit),
            new LedgerService($db),
            new DocumentNumberService($db),
            new ListingFeeService($db, $settings),
            $audit
        );

        $user = $request->getAttribute('user');
        try {
            $result = $service->record($items, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }

        return $this->json($response, ['data' => $result], 201);
    }
}
