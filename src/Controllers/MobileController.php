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
