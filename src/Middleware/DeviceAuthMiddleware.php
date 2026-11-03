<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\UserRepo;
use App\Services\AuditLogger;
use App\Services\PosDeviceService;
use App\Support\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Android POS API (/api/device/v1/*): the paired device's token stands in for a sign-in.
 * The request then carries the seller working on the device in 'user' (so RoleGuard and the
 * web POS controllers work unchanged) and the device itself in 'device'. No cookies, so no CSRF.
 *
 * Token: "Authorization: Bearer <token>" or "X-Device-Token: <token>" (some PHP setups drop
 * the Authorization header). X-Device-User: the id of the seller profile working on the device
 * (one of its sellers; without it, the default profile). The app may also send X-App-Version
 * and X-Pending-Sales.
 */
class DeviceAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $token = trim($request->getHeaderLine('X-Device-Token'));
        if ($token === '' && preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $m)) {
            $token = $m[1];
        }
        if ($token === '') {
            return $this->deny(401, 'device_unpaired', 'อุปกรณ์นี้ยังไม่ได้จับคู่กับระบบ');
        }
        $db = Db::connection();
        $devices = new PosDeviceService($db, new AuditLogger($db));
        try {
            $device = $devices->byToken($token);
        } catch (\PDOException $e) {
            if (!PosDeviceService::notMigrated($e)) {
                throw $e;
            }
            return $this->deny(503, 'not_migrated', PosDeviceService::NOT_MIGRATED);
        }
        if (!$device) {
            return $this->deny(401, 'device_revoked', 'อุปกรณ์นี้ถูกยกเลิกการจับคู่ หรือรหัสอุปกรณ์ไม่ถูกต้อง กรุณาจับคู่ใหม่');
        }

        // The profile working on the device (X-Device-User), or its default one.
        $asked = ctype_digit($request->getHeaderLine('X-Device-User')) ? (int) $request->getHeaderLine('X-Device-User') : null;
        try {
            $seller = $devices->seller($device, $asked);
        } catch (\PDOException $e) {
            if (!PosDeviceService::notMigrated($e)) {
                throw $e;
            }
            return $this->deny(503, 'not_migrated', PosDeviceService::NOT_MIGRATED);
        }
        if (isset($seller['error'])) {
            return $this->deny(403, $seller['code'], $seller['error']);
        }
        $user = $seller['user'];

        $server = $request->getServerParams();
        $devices->touch($device, $server['REMOTE_ADDR'] ?? null, [
            'app_version' => $request->getHeaderLine('X-App-Version'),
            'pending' => $request->getHeaderLine('X-Pending-Sales'),
            'user_id' => $asked !== null ? (int) $user['id'] : null,
        ]);
        return $handler->handle($request->withAttribute('user', $user)->withAttribute('device', $device));
    }

    private function deny(int $status, string $code, string $message): Response
    {
        $response = (new ResponseFactory())->createResponse($status);
        $response->getBody()->write(json_encode(['error' => $message, 'code' => $code], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
