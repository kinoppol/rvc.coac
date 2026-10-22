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

    private const SORTS = [
        'name' => 'p.name ASC',
        'popular' => 'sold_qty DESC, p.name ASC',
        'price_asc' => 'p.sale_price ASC, p.name ASC',
        'price_desc' => 'p.sale_price DESC, p.name ASC',
        'stock_desc' => 'p.qty_stock DESC, p.name ASC',
        'stock_asc' => 'p.qty_stock ASC, p.name ASC',
    ];

    /**
     * Products in stock for the POS. q matches name or barcode; an empty q
     * with all=1 lists everything (the picture grid). sold_qty = pieces sold
     * in the last 90 days, used for "popular" ordering.
     */
    public function searchProducts(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $q = trim((string) ($params['q'] ?? ''));
        $all = !empty($params['all']);
        $order = self::SORTS[$params['sort'] ?? 'name'] ?? self::SORTS['name'];
        if ($q === '' && !$all) {
            return $this->json($response, ['data' => []]);
        }
        $db = Db::connection();
        $stmt = $db->prepare(
            "SELECT p.id, p.name, p.barcode, p.sale_price, p.qty_stock, p.image_token,
                    COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name, COALESCE(s.sold, 0) AS sold_qty
             FROM products p
             LEFT JOIN consignors c ON c.id = p.consignor_id
             LEFT JOIN (
                SELECT si.product_id, SUM(si.qty) AS sold FROM sale_items si
                JOIN sales sa ON sa.id = si.sale_id
                WHERE sa.sale_datetime >= NOW() - INTERVAL 90 DAY
                GROUP BY si.product_id
             ) s ON s.product_id = p.id
             WHERE p.qty_stock > 0 AND (p.name LIKE ? OR p.barcode LIKE ?)
             ORDER BY {$order} LIMIT " . ($all ? 500 : 20)
        );
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    /** Payment methods the admin has turned on for the POS. */
    public function options(Request $request, Response $response): Response
    {
        $settings = new SettingsService(Db::connection());
        $methods = array_map(fn ($m) => ['key' => $m, 'label' => SettingsService::PAYMENT_METHODS[$m]], $settings->paymentMethods());
        return $this->json($response, [
            'payment_methods' => $methods,
            'payment_mixed' => $settings->get('pos_payment_mixed') === '1',
        ]);
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
            $payments = [
                'methods' => $settings->paymentMethods(),
                'mixed' => $settings->get('pos_payment_mixed') === '1',
                'lines' => is_array($body['payments'] ?? null) ? $body['payments'] : [],
            ];
            $result = $service->record($items, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null, ['payments' => $payments]);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }

        return $this->json($response, ['data' => $result], 201);
    }
}
