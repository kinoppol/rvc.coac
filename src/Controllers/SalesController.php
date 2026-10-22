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
     * in the last 90 days, used for "popular" ordering. short=1 also lists
     * products with stock below 1, for roles allowed to sell them.
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
        $role = $request->getAttribute('user')['role'] ?? null;
        $short = !empty($params['short']) && (new SettingsService($db))->posRoleAllows('pos_short_stock_roles', $role);
        $stock = $short ? '1 = 1' : 'p.qty_stock > 0';
        if (!empty($params['out']) && $q !== '') {
            // Matches the POS cannot sell (stock below 1), shown so the cashier knows why.
            $stock = 'p.qty_stock < 1';
            $all = false;
        }
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
             WHERE {$stock} AND (p.name LIKE ? OR p.barcode LIKE ?)
             ORDER BY {$order} LIMIT " . ($all ? 500 : 20)
        );
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    /** Payment methods the admin has turned on, and which POS switches this user's role may use. */
    public function options(Request $request, Response $response): Response
    {
        $settings = new SettingsService(Db::connection());
        $role = $request->getAttribute('user')['role'] ?? null;
        $methods = array_map(fn ($m) => ['key' => $m, 'label' => SettingsService::PAYMENT_METHODS[$m]], $settings->paymentMethods());
        return $this->json($response, [
            'payment_methods' => $methods,
            'payment_mixed' => $settings->posRoleAllows('pos_mixed_roles', $role),
            'sell_out_of_stock' => $settings->posRoleAllows('pos_short_stock_roles', $role),
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
                'mixed' => $settings->posRoleAllows('pos_mixed_roles', $user['role'] ?? null),
                'lines' => is_array($body['payments'] ?? null) ? $body['payments'] : [],
            ];
            $result = $service->record($items, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null, [
                'payments' => $payments,
                // The cashier's own switch, honoured only for roles the admin allows.
                'allow_short_stock' => !empty($body['allow_short']) && $settings->posRoleAllows('pos_short_stock_roles', $user['role'] ?? null),
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }

        return $this->json($response, ['data' => $result], 201);
    }
}
