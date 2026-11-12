<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Services\AuditLogger;
use App\Services\MobileDeviceService;
use App\Services\MobileSyncService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The mobile app for everyone who is not selling (docs/android-mobile-app.md).
 * App side, /api/mobile/v1: pair/start and pair/status are open; me, sync and unpair need the token.
 * Web side, /api/my/mobile-devices: the signed-in user pairs a device as their own and unpairs it.
 */
class MobileController
{
    use JsonResponse;

    public const API_VERSION = 1;
    public const ROLE_LABELS = [
        'admin' => 'ผู้ดูแลระบบ', 'manager' => 'ผู้จัดการร้าน', 'advisor' => 'ครูที่ปรึกษา', 'cashier' => 'พนักงานขาย',
        'closer' => 'ผู้สรุปบัญชีรายวัน', 'executive' => 'ผู้บริหาร', 'consignor' => 'ผู้ฝากขาย',
    ];
    /** The app syncs on its own no more often than this (the user may still pull to refresh). */
    public const SYNC_MINUTES = 30;

    public function pairStart(Request $request, Response $response): Response
    {
        try {
            $data = $this->devices()->start((array) $request->getParsedBody(), $this->ip($request));
        } catch (\DomainException $e) {
            return $this->error($response, $e->getMessage(), 429);
        } catch (\PDOException $e) {
            if (!MobileDeviceService::notMigrated($e)) {
                throw $e;
            }
            return $this->error($response, MobileDeviceService::NOT_MIGRATED, 503);
        }
        return $this->json($response, $data + ['server_name' => 'ระบบบริหารบัญชี ร้านค้าสหการ', 'api_version' => self::API_VERSION], 201);
    }

    public function pairStatus(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        try {
            $data = $this->devices()->status((int) ($body['pairing_id'] ?? 0), (string) ($body['pair_secret'] ?? ''));
        } catch (\PDOException $e) {
            if (!MobileDeviceService::notMigrated($e)) {
                throw $e;
            }
            return $this->error($response, MobileDeviceService::NOT_MIGRATED, 503);
        }
        return $this->json($response, $data);
    }

    /** GET /me — the owner, their roles (the active one first-class) and what it may see, and the device. */
    public function me(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $users = new UserRepo(Db::connection());
        return $this->json($response, [
            'user' => [
                'id' => (int) $user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'],
                'role' => $user['role'], 'roles' => array_map(fn ($r) => ['key' => $r, 'label' => self::ROLE_LABELS[$r] ?? $r], $user['roles']),
                'consignor' => in_array(UserRepo::CONSIGNOR, $user['roles'], true) ? $users->consignorOf((int) $user['id']) : null,
            ],
            'permissions' => $users->permissionsFor($user['role']),
            'read_only' => $user['role'] === 'executive',
            'device' => $this->devices()->view($request->getAttribute('device')),
            'store_name' => 'ร้านค้าสหการ',
            'timezone' => date_default_timezone_get(),
            'server_time' => date('c'),
            'sync_minutes' => self::SYNC_MINUTES,
            'api_version' => self::API_VERSION,
        ]);
    }

    /** GET /sync — everything the app keeps offline for the active role (MobileSyncService). */
    public function sync(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $user = $request->getAttribute('user');
        $permissions = (new UserRepo($db))->permissionsFor($user['role']);
        return $this->json($response, ['role' => $user['role']] + (new MobileSyncService($db))->bundle($user, $permissions));
    }

    /** POST /unpair — the app forgets itself; the token stops working at once. */
    public function unpair(Request $request, Response $response): Response
    {
        $this->devices()->forget($request->getAttribute('device'));
        return $this->json($response, ['ok' => true]);
    }

    /**
     * POST /api/auth/mobile-web (form: token, role, to, theme) — the app opens the full web system in its
     * own browser view, signed in as the device's owner without a password. The token travels in the
     * POST body (never the URL) to the same server it is sent to on every API call. The session
     * remembers the device: once the device is unpaired or the account closed, AuthMiddleware ends it.
     * Lives under /api/auth/ so public/index.php opens the session for writing.
     */
    public function openWeb(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $db = Db::connection();
        try {
            $device = $this->devices()->byToken(trim((string) ($body['token'] ?? '')));
        } catch (\PDOException $e) {
            if (!MobileDeviceService::notMigrated($e)) {
                throw $e;
            }
            return $this->webPage($response, 503, MobileDeviceService::NOT_MIGRATED);
        }
        if (!$device) {
            return $this->webPage($response, 401, 'อุปกรณ์นี้ถูกยกเลิกการจับคู่ กรุณาจับคู่ใหม่');
        }
        $users = new UserRepo($db);
        $user = $users->find((int) $device['user_id']);
        $roles = $user && UserRepo::canSignIn($user) ? $users->rolesOf($user) : [];
        if (!$roles) {
            return $this->webPage($response, 403, 'บัญชีผู้ใช้ของอุปกรณ์นี้ปิดอยู่หรือถูกระงับ กรุณาติดต่อผู้ดูแลระบบ');
        }
        $role = (string) ($body['role'] ?? '');

        session_regenerate_id(true);
        $_SESSION = [
            'user_id' => (int) $user['id'],
            'csrf_token' => bin2hex(random_bytes(32)),
            'active_role' => in_array($role, $roles, true) ? $role : $roles[0],
            'auth_via' => 'mobile-app',
            'mobile_device_id' => (int) $device['id'],
        ];
        $this->devices()->touch($device, $this->ip($request), $request->getHeaderLine('X-App-Version'));

        $to = (string) ($body['to'] ?? '');
        $to = preg_match('#^[a-z0-9-]{1,40}(\?[A-Za-z0-9=&_.%-]{0,200})?$#', $to) ? $to : 'dashboard';
        $theme = in_array($body['theme'] ?? '', ['light', 'dark', 'auto'], true) ? $body['theme'] : 'auto';
        $app = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/') . '/';
        return $response->withHeader('Location', $app . '?app_theme=' . $theme . '#/' . $to)
            ->withHeader('Cache-Control', 'no-store')->withStatus(303);
    }

    /** A plain page for the app's browser view when it cannot be signed in. */
    private function webPage(Response $response, int $status, string $message): Response
    {
        $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $response->getBody()->write(<<<HTML
            <!DOCTYPE html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <title>เข้าสู่ระบบไม่ได้</title></head>
            <body style="font-family:sans-serif;padding:32px 20px;text-align:center;color:#1e293b">
            <h2 style="color:#1d4ed8">เปิดระบบจัดการร้านไม่ได้</h2><p>{$m}</p></body></html>
            HTML);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store')->withStatus($status);
    }

    // ---- web: the signed-in user's own devices ----

    public function myDevices(Request $request, Response $response): Response
    {
        try {
            $data = $this->devices()->forUser((int) $request->getAttribute('user')['id']);
        } catch (\PDOException $e) {
            if (!MobileDeviceService::notMigrated($e)) {
                throw $e;
            }
            return $this->json($response, ['data' => [], 'not_migrated' => MobileDeviceService::NOT_MIGRATED]);
        }
        return $this->json($response, ['data' => $data, 'code_minutes' => MobileDeviceService::CODE_MINUTES]);
    }

    public function pair(Request $request, Response $response): Response
    {
        if ($request->getAttribute('impersonator')) {
            return $this->error($response, 'จับคู่แอปขณะสวมสิทธิ์ผู้ใช้อื่นไม่ได้ ให้เจ้าของบัญชีลงชื่อเข้าใช้และจับคู่เอง', 403);
        }
        $body = (array) $request->getParsedBody();
        try {
            $device = $this->devices()->approve((string) ($body['code'] ?? ''), isset($body['name']) ? (string) $body['name'] : null,
                (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\DomainException $e) {
            return $this->error($response, $e->getMessage(), 422);
        } catch (\PDOException $e) {
            if (!MobileDeviceService::notMigrated($e)) {
                throw $e;
            }
            return $this->error($response, MobileDeviceService::NOT_MIGRATED, 503);
        }
        return $this->json($response, ['data' => $device], 201);
    }

    public function revoke(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        try {
            $this->devices()->revoke((int) $args['id'], (int) $user['id'], $user['role'] === 'admin', $this->ip($request));
        } catch (\DomainException $e) {
            return $this->error($response, $e->getMessage(), 404);
        }
        return $this->json($response, ['ok' => true]);
    }

    private function devices(): MobileDeviceService
    {
        $db = Db::connection();
        return new MobileDeviceService($db, new AuditLogger($db));
    }

    private function ip(Request $request): ?string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? null;
    }
}
