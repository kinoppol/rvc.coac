<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\UserRepo;
use App\Services\AuditLogger;
use App\Services\MobileDeviceService;
use App\Support\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Mobile app API (/api/mobile/v1/*): the device's token stands in for its owner's sign-in.
 * Token: "X-Device-Token: <token>" or "Authorization: Bearer <token>". X-App-Role picks which of
 * the owner's roles the app shows (as switching role on the web); without it, the first one.
 * The request then carries 'user' (with role and roles, like AuthMiddleware) and 'device'.
 */
class MobileAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $r = self::resolve($request, str_ends_with($request->getUri()->getPath(), '/sync'));
        return $r instanceof Response ? $r : $handler->handle($r);
    }

    /**
     * The device's owner for this request (or the error to answer with): the request comes back with
     * 'user' (role and roles, as AuthMiddleware) and 'device'. Also used by AuthMiddleware, so the app
     * reaches every web endpoint with its token (the native management screens).
     */
    public static function resolve(Request $request, bool $synced = false): Request|Response
    {
        $token = trim($request->getHeaderLine('X-Device-Token'));
        if ($token === '' && preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $m)) {
            $token = $m[1];
        }
        if ($token === '') {
            return self::deny(401, 'device_unpaired', 'อุปกรณ์นี้ยังไม่ได้จับคู่กับระบบ');
        }
        $db = Db::connection();
        $devices = new MobileDeviceService($db, new AuditLogger($db));
        try {
            $device = $devices->byToken($token);
        } catch (\PDOException $e) {
            if (!MobileDeviceService::notMigrated($e)) {
                throw $e;
            }
            return self::deny(503, 'not_migrated', MobileDeviceService::NOT_MIGRATED);
        }
        if (!$device) {
            return self::deny(401, 'device_revoked', 'อุปกรณ์นี้ถูกยกเลิกการจับคู่ กรุณาจับคู่ใหม่');
        }

        $users = new UserRepo($db);
        $user = $users->find((int) $device['user_id']);
        if (!$user || !UserRepo::canSignIn($user)) {
            return self::deny(403, 'user_inactive', 'บัญชีผู้ใช้ของอุปกรณ์นี้ปิดอยู่หรือถูกระงับ กรุณาติดต่อผู้ดูแลระบบ');
        }
        $roles = $users->rolesOf($user);
        if (!$roles) {
            return self::deny(403, 'user_inactive', 'บัญชีนี้ยังไม่ได้ผูกกับผู้ฝากขาย กรุณาติดต่อผู้ดูแลระบบ');
        }
        $asked = trim($request->getHeaderLine('X-App-Role'));
        $user['role'] = in_array($asked, $roles, true) ? $asked : $roles[0];
        $user['roles'] = $roles;

        $devices->touch($device, $request->getServerParams()['REMOTE_ADDR'] ?? null, $request->getHeaderLine('X-App-Version'), $synced);
        return $request->withAttribute('user', $user)->withAttribute('device', $device);
    }

    private static function deny(int $status, string $code, string $message): Response
    {
        $response = (new ResponseFactory())->createResponse($status);
        $response->getBody()->write(json_encode(['error' => $message, 'code' => $code], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
