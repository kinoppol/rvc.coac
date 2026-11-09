<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Services\AttachmentService;
use App\Services\AuditLogger;
use App\Services\CashDrawerService;
use App\Services\DeviceSaleSyncService;
use App\Services\PosDeviceService;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Android POS app API, /api/device/v1 (see docs/android-pos-app.md).
 * Pairing is open to anyone (it only gives out a code an admin must approve); everything
 * else needs the device token (DeviceAuthMiddleware). The rest of the POS — drawer, my
 * sales, sale edit requests — are the web POS routes, mounted under the same prefix.
 */
class DeviceController
{
    use JsonResponse;

    public const API_VERSION = 1;
    private const PAGE = 500;
    private const PAGE_MAX = 2000;

    /** POST /pair/start {device_name, model, platform, app_version} */
    public function pairStart(Request $request, Response $response): Response
    {
        $db = Db::connection();
        try {
            $data = $this->devices($db)->start((array) $request->getParsedBody(), $this->ip($request));
        } catch (\RuntimeException $e) {
            if (PosDeviceService::notMigrated($e)) {
                return $this->error($response, PosDeviceService::NOT_MIGRATED, 503);
            }
            return $this->error($response, $e->getMessage(), 429);
        }
        return $this->json($response, $data + ['server_name' => 'ระบบบริหารบัญชี ร้านค้าสหการ', 'api_version' => self::API_VERSION], 201);
    }

    /** POST /pair/status {pairing_id, pair_secret} — polled by the app every few seconds. */
    public function pairStatus(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        try {
            $data = $this->devices(Db::connection())->status((int) ($body['pairing_id'] ?? 0), (string) ($body['pair_secret'] ?? ''));
        } catch (\PDOException $e) {
            if (!PosDeviceService::notMigrated($e)) {
                throw $e;
            }
            return $this->error($response, PosDeviceService::NOT_MIGRATED, 503);
        }
        return $this->json($response, $data);
    }

    /**
     * GET /me — who the device is, its sellers (each profile with its permissions, PIN check
     * and drawer, so profiles switch offline), who is working now (X-Device-User), the sync
     * level, and every setting the POS needs to work offline (payment methods, switches,
     * store hours and days off for the accounting day).
     */
    public function me(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $settings = new SettingsService($db);
        $user = $request->getAttribute('user');
        $device = $request->getAttribute('device');
        $devices = $this->devices($db);
        $role = $user['role'];
        $day = DrawerController::currentDay($settings);
        $profiles = [];
        foreach ($devices->members((int) $device['id']) as $m) {
            $seller = $devices->seller($device, $m['id']);
            if (isset($seller['user'])) {
                $profiles[] = $this->profile($db, $settings, $seller['user'], $day) + [
                    'is_default' => $m['id'] === (int) $device['user_id'],
                    'pin' => $m['pin_hash'] ? ['salt' => $m['pin_salt'], 'hash' => $m['pin_hash']] : null,
                ];
            }
        }
        $current = $this->profile($db, $settings, $user, $day);
        $pos = [
            'payment_methods' => array_map(fn ($m) => ['key' => $m, 'label' => SettingsService::PAYMENT_METHODS[$m]], $settings->paymentMethods()),
            'payment_mixed' => $current['pos']['payment_mixed'],
            'promptpay' => $settings->promptPay(),
            'sell_out_of_stock' => $current['pos']['sell_out_of_stock'],
            'sell_out_of_stock_always' => $settings->get('pos_short_stock_always') === '1',
            'store_hours' => ['open' => (string) $settings->get('store_open_time'), 'close' => (string) $settings->get('store_close_time')],
            'store_closed_days' => array_values(array_map('intval', array_filter(explode(',', (string) $settings->get('store_closed_days')), 'strlen'))),
            'store_holidays' => array_map(fn ($d, $n) => ['date' => $d, 'name' => $n], array_keys($settings->holidays()), $settings->holidays()),
            'sale_edit' => ['enabled' => $settings->get('sale_edit_enabled') === '1', 'minutes' => (int) $settings->get('sale_edit_minutes')],
            'timezone' => date_default_timezone_get(),
        ];
        return $this->json($response, [
            'api_version' => self::API_VERSION,
            'server_time' => date(DATE_ATOM),
            'device' => $devices->publicView($devices->find((int) $device['id'])),
            'user' => array_diff_key($current, ['drawer' => 1, 'pos' => 1]),
            'users' => $profiles,
            'sync' => PosDeviceService::syncConfig($device),
            'sync_levels' => PosDeviceService::syncLevels(),
            'pos' => $pos,
            // Changes when any of the above does, so the app knows to redraw.
            'settings_hash' => sha1(json_encode([$pos, $user['id'], $role])),
            'config_hash' => $devices->configHash($device),
            'business_day' => $settings->businessDay(new \DateTimeImmutable()),
            'today' => date('Y-m-d'),
            'drawer' => $current['drawer'],
        ]);
    }

    /** PUT /settings {sync_level, changed_at?} — the level picked on the device (changed_at: when, if picked offline). */
    public function settings(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $db = Db::connection();
        try {
            $sync = $this->devices($db)->setSyncLevelFromApp($request->getAttribute('device'), (string) ($body['sync_level'] ?? ''),
                isset($body['changed_at']) ? (string) $body['changed_at'] : null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['sync' => $sync]);
    }

    /** A seller profile as the app keeps it: permissions and role-dependent POS switches. */
    private function profile(\PDO $db, SettingsService $settings, array $user, array $day): array
    {
        $role = $user['role'];
        try {
            $drawer = (new CashDrawerService($db, new AuditLogger($db)))->state($day['date'], (int) $user['id'], $day['required']);
        } catch (\PDOException $e) {
            $drawer = null; // migration 0033 not run yet
        }
        return [
            'id' => (int) $user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $role,
            'permissions' => (new UserRepo($db))->permissionsFor($role),
            'pos' => [
                'payment_mixed' => $settings->posRoleAllows('pos_mixed_roles', $role),
                'sell_out_of_stock' => $settings->posRoleAllows('pos_short_stock_roles', $role),
            ],
            'drawer' => $drawer,
        ];
    }

    /**
     * GET /catalog?since=<cursor>&limit=500 — every product (also those out of stock, so the
     * app shows the right figures), oldest change first, paged: pass each page's next as since
     * until has_more is false, and keep the last next for the following sync, which then
     * fetches only what changed. No since = everything. The last page of a request with since
     * also lists every product id still there (ids), so the app drops the ones that are gone.
     */
    public function catalog(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = max(1, min(self::PAGE_MAX, (int) ($params['limit'] ?? self::PAGE)));
        $since = $this->cursor((string) ($params['since'] ?? ''));
        $db = Db::connection();
        $now = time();
        // discontinued=1: the app keeps discontinued goods, flagged, to find them by barcode offline.
        // Apps too old to ask do not get them: leaving the ids below, they drop off that POS.
        $hasFlag = Db::hasColumn($db, 'products', 'discontinued_at');
        $flag = $hasFlag ? 'p.discontinued_at IS NOT NULL' : '0';
        $onSale = $hasFlag && empty($params['discontinued']) ? 'discontinued_at IS NULL' : '1 = 1';
        $where = str_replace('discontinued_at', 'p.discontinued_at', $onSale);
        $args = [];
        if ($since) {
            $where .= ' AND (p.updated_at > ? OR (p.updated_at = ? AND p.id > ?))';
            $args = [$since[0], $since[0], $since[1]];
        }
        $stmt = $db->prepare(
            "SELECT p.id, p.name, p.barcode, p.sale_price, p.qty_stock, p.image_token, p.consignor_id, p.updated_at,
                    COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name, {$flag} AS discontinued
             FROM products p LEFT JOIN consignors c ON c.id = p.consignor_id
             WHERE {$where} ORDER BY p.updated_at, p.id LIMIT " . ($limit + 1)
        );
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $packs = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            $p = $db->prepare('SELECT * FROM product_pack_barcodes WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY pack_size, id');
            $p->execute($ids);
            foreach ($p->fetchAll() as $b) {
                if (isset($b['sale_price']) && $b['sale_price'] !== null) {
                    $packs[(int) $b['product_id']][] = ['barcode' => $b['barcode'], 'pack_size' => (int) $b['pack_size'], 'sale_price' => (float) $b['sale_price']];
                }
            }
        }
        $attachments = new AttachmentService($db);
        $products = array_map(fn ($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'barcode' => $r['barcode'], 'sale_price' => (float) $r['sale_price'],
            'qty_stock' => (int) $r['qty_stock'], 'consignor_id' => $r['consignor_id'] !== null ? (int) $r['consignor_id'] : null,
            'consignor_name' => $r['consignor_name'], 'image_token' => $r['image_token'],
            'image_url' => $r['image_token'] ? "products/{$r['id']}/photo?size=thumb&v={$r['image_token']}" : null,
            'has_thumb' => $r['image_token'] && $attachments->hasThumb($r['image_token']),
            'packs' => $packs[(int) $r['id']] ?? [], 'updated_at' => $r['updated_at'],
            'discontinued' => !empty($r['discontinued']),
        ], $rows);

        $last = $rows ? end($rows) : null;
        if ($more) {
            // Mid-way: carry on right after the last row sent.
            $next = $this->makeCursor((string) $last['updated_at'], (int) $last['id']);
        } else {
            // Done: the next delta starts a little before now, so a change made in the same
            // second as the last row read is not missed (rows sent twice are just saved again).
            $mark = date('Y-m-d H:i:s', $now - 5);
            $from = $last && (string) $last['updated_at'] < $mark ? (string) $last['updated_at'] : $mark;
            $next = $this->makeCursor($since && !$last ? min($since[0], $mark) : $from, 0);
        }
        $data = [
            'products' => $products, 'has_more' => $more, 'next' => $next,
            'server_time' => date(DATE_ATOM),
        ];
        if (!$more && $since !== null) {
            $data['ids'] = array_map('intval', $db->query("SELECT id FROM products WHERE {$onSale} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        }
        return $this->json($response, $data);
    }

    /** GET /catalog/popular — pieces sold per product in the last 90 days, for the "ขายดี" order. */
    public function popular(Request $request, Response $response): Response
    {
        $rows = Db::connection()->query(
            'SELECT si.product_id, SUM(si.qty) AS sold FROM sale_items si JOIN sales sa ON sa.id = si.sale_id
             WHERE sa.sale_datetime >= NOW() - INTERVAL 90 DAY GROUP BY si.product_id'
        )->fetchAll();
        return $this->json($response, ['data' => array_map(fn ($r) => [(int) $r['product_id'], (int) $r['sold']], $rows)]);
    }

    /**
     * POST /sales {sales: [...]} — up to 50 sales, booked in the order given. Every sale gets
     * its own result; the app removes from its queue those that come back synced, duplicate
     * or failed (failed ones are kept on the server for an admin). See DeviceSaleSyncService.
     * Each sale names the seller who made it (user_id; without it, the X-Device-User profile),
     * so one batch may carry sales of several profiles.
     */
    public function syncSales(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $sales = is_array($body['sales'] ?? null) ? array_values($body['sales']) : [];
        if (!$sales) {
            return $this->error($response, 'ไม่มีรายการขาย', 422);
        }
        // Too many at once: book the first ones (results name them) and leave the rest queued,
        // rather than turn the whole queue away for ever.
        $remaining = max(0, count($sales) - DeviceSaleSyncService::MAX_BATCH);
        $sales = array_slice($sales, 0, DeviceSaleSyncService::MAX_BATCH);
        $db = Db::connection();
        $service = new DeviceSaleSyncService($db, new AuditLogger($db));
        $device = $request->getAttribute('device');
        $user = $request->getAttribute('user');
        $devices = $this->devices($db);
        $users = new UserRepo($db);
        $results = [];
        foreach ($sales as $sale) {
            $sale = is_array($sale) ? $sale : [];
            $sellerId = isset($sale['user_id']) && is_numeric($sale['user_id']) ? (int) $sale['user_id'] : (int) $user['id'];
            $notes = [];
            $seller = $sellerId === (int) $user['id'] ? ['user' => $user] : $devices->seller($device, $sellerId, !empty($sale['offline']));
            if (isset($seller['user']) && !$users->hasPermission($seller['user']['role'], 'sales.create')) {
                $seller = ['error' => "บัญชี {$seller['user']['full_name']} ไม่มีสิทธิ์ขายสินค้า"];
            }
            if (isset($seller['error'])) {
                $results[] = $service->fail($device, $sellerId, $sale, $seller['error']);
                continue;
            }
            if (!empty($seller['removed'])) {
                $notes[] = "ขายโดย {$seller['user']['full_name']} ซึ่งถูกนำออกจากอุปกรณ์นี้แล้ว";
            }
            $results[] = $service->process($device, $seller['user'], $sale, $this->ip($request), $notes);
        }
        return $this->json($response, ['results' => $results, 'remaining' => $remaining, 'server_time' => date(DATE_ATOM)]);
    }

    /**
     * GET /ping — cheap check that the server (and this device's pairing) is reachable. Also
     * the sync level (it may have been changed on the web page) and config_hash: when it
     * differs from the one of the last /me, fetch /me again (sellers or PINs changed).
     */
    public function ping(Request $request, Response $response): Response
    {
        $device = $request->getAttribute('device');
        return $this->json($response, [
            'ok' => true, 'server_time' => date(DATE_ATOM),
            'sync' => PosDeviceService::syncConfig($device),
            'config_hash' => $this->devices(Db::connection())->configHash($device),
        ]);
    }

    /** @return array{0:string,1:int}|null */
    private function cursor(string $raw): ?array
    {
        $raw = strtr($raw, '-_', '+/');
        $plain = base64_decode(str_pad($raw, (int) ceil(strlen($raw) / 4) * 4, '='), true);
        if ($plain === false || !preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $plain, $m)) {
            return null;
        }
        return [$m[1], (int) $m[2]];
    }

    private function makeCursor(string $at, int $id): string
    {
        return rtrim(strtr(base64_encode("{$at}|{$id}"), '+/', '-_'), '=');
    }

    private function devices(\PDO $db): PosDeviceService
    {
        return new PosDeviceService($db, new AuditLogger($db));
    }

    private function ip(Request $request): ?string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? null;
    }
}
