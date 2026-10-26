<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProductRepo;
use App\Services\AttachmentService;
use App\Services\AuditLogger;
use App\Services\CashDrawerService;
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
        $short = (new SettingsService($db))->sellShortStock($role, !empty($params['short']));
        $stock = $short ? '1 = 1' : 'p.qty_stock > 0';
        if (!empty($params['out']) && $q !== '') {
            // Matches the POS cannot sell (stock below 1), shown so the cashier knows why.
            $stock = 'p.qty_stock < 1';
            $all = false;
        }
        $stmt = $db->prepare(
            "SELECT p.id, p.name, p.barcode, p.sale_price, p.qty_stock, p.image_token, p.consignor_id,
                    COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name, COALESCE(s.sold, 0) AS sold_qty
             FROM products p
             LEFT JOIN consignors c ON c.id = p.consignor_id
             LEFT JOIN (
                SELECT si.product_id, SUM(si.qty) AS sold FROM sale_items si
                JOIN sales sa ON sa.id = si.sale_id
                WHERE sa.sale_datetime >= NOW() - INTERVAL 90 DAY
                GROUP BY si.product_id
             ) s ON s.product_id = p.id
             WHERE {$stock} AND (p.name LIKE ? OR p.barcode LIKE ?
                OR EXISTS (SELECT 1 FROM product_pack_barcodes b WHERE b.product_id = p.id AND b.barcode = ?))
             ORDER BY {$order} LIMIT " . ($all ? 500 : 20)
        );
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like, $q]);
        $rows = $stmt->fetchAll();
        // Photos still without a small copy: the POS makes one from the first load.
        $attachments = new AttachmentService($db);
        // Packs that can be sold whole (scanned by their pack barcode or picked in the cart).
        $packs = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            $stmt = $db->prepare('SELECT * FROM product_pack_barcodes WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY pack_size, id');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $b) {
                if (isset($b['sale_price']) && $b['sale_price'] !== null) {
                    $packs[(int) $b['product_id']][] = ['barcode' => $b['barcode'], 'pack_size' => (int) $b['pack_size'], 'sale_price' => (float) $b['sale_price']];
                }
            }
        }
        foreach ($rows as &$r) {
            $r['has_thumb'] = $r['image_token'] && $attachments->hasThumb($r['image_token']);
            $r['packs'] = $packs[(int) $r['id']] ?? [];
        }
        unset($r);
        return $this->json($response, ['data' => $rows]);
    }

    /** Payment methods the admin has turned on, and which POS switches this user's role may use. */
    public function options(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $settings = new SettingsService($db);
        $user = $request->getAttribute('user');
        $role = $user['role'] ?? null;
        $day = DrawerController::currentDay($settings);
        try {
            $drawer = (new CashDrawerService($db, new AuditLogger($db)))->state($day['date'], (int) $user['id'], $day['required']);
        } catch (\PDOException $e) {
            $drawer = null; // migration 0033 not run yet
        }
        $methods = array_map(fn ($m) => ['key' => $m, 'label' => SettingsService::PAYMENT_METHODS[$m]], $settings->paymentMethods());
        return $this->json($response, [
            'payment_methods' => $methods,
            'payment_mixed' => $settings->posRoleAllows('pos_mixed_roles', $role),
            'sell_out_of_stock' => $settings->posRoleAllows('pos_short_stock_roles', $role),
            'sell_out_of_stock_always' => $settings->get('pos_short_stock_always') === '1',
            'store_hours' => [
                'open' => $settings->get('store_open_time'),
                'close' => $settings->get('store_close_time'),
            ],
            'business_day' => $settings->businessDay(new \DateTimeImmutable()),
            'today' => date('Y-m-d'),
            'drawer' => $drawer,
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
        // The cash in the drawer is counted before selling (or skipped for a few minutes).
        $drawerDay = DrawerController::currentDay($settings);
        try {
            $maySell = !$drawerDay['required'] || (new CashDrawerService($db, $audit))->canSell($drawerDay['date'], (int) $user['id']);
        } catch (\PDOException $e) {
            $maySell = true; // migration 0033 not run yet
        }
        if (!$maySell) {
            return $this->error($response, 'กรุณาระบุจำนวนเงินในลิ้นชักก่อนทำการขาย', 428);
        }
        try {
            $payments = [
                'methods' => $settings->paymentMethods(),
                'mixed' => $settings->posRoleAllows('pos_mixed_roles', $user['role'] ?? null),
                'lines' => is_array($body['payments'] ?? null) ? $body['payments'] : [],
            ];
            $now = new \DateTimeImmutable();
            $day = $settings->businessDay($now);
            $result = $service->record($items, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null, [
                'sale_datetime' => $now->format('Y-m-d H:i:s'),
                'business_date' => $day['date'],
                'after_hours' => $day['after_hours'],
                'payments' => $payments,
                // The cashier's own switch, honoured only for roles the admin allows.
                'allow_short_stock' => $settings->sellShortStock($user['role'] ?? null, !empty($body['allow_short'])),
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }

        return $this->json($response, ['data' => $result], 201);
    }
}
