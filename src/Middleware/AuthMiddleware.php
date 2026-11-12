<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\UserRepo;
use App\Support\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        // The mobile app's native screens: its device token signs the request in as the device's owner
        // (MobileAuthMiddleware), with the role it picks in X-App-Role. No cookie, so no CSRF to check.
        if (empty($_SESSION['user_id']) && trim($request->getHeaderLine('X-Device-Token')) !== '') {
            $resolved = MobileAuthMiddleware::resolve($request);
            if ($resolved instanceof Response) {
                return $resolved;
            }
            $user = $resolved->getAttribute('user');
            if ($user['role'] === UserRepo::CONSIGNOR && !preg_match('#/api/(auth|my)/#', $request->getUri()->getPath())) {
                return $this->deny(403, 'ไม่มีสิทธิ์เข้าถึงส่วนนี้');
            }
            return $handler->handle($resolved->withAttribute('via_device', true));
        }

        if (empty($_SESSION['user_id'])) {
            return $this->deny(401, 'กรุณาเข้าสู่ระบบ');
        }

        $userRepo = new UserRepo(Db::connection());
        $user = $userRepo->find((int) $_SESSION['user_id']);
        if (!$user || !UserRepo::canSignIn($user)) {
            return $this->deny(401, 'บัญชีผู้ใช้ไม่พร้อมใช้งาน');
        }

        // Opened from the mobile app (MobileController::openWeb): lasts only while that device stays paired to its owner
        // (the admin, while impersonating).
        if (!empty($_SESSION['mobile_device_id']) && !$this->deviceStillPaired((int) $_SESSION['mobile_device_id'], (int) ($_SESSION['impersonator_id'] ?? $user['id']))) {
            $this->endSession();
            return $this->deny(401, 'อุปกรณ์นี้ถูกยกเลิกการจับคู่ กรุณาจับคู่ใหม่');
        }

        // Users with a staff role and a consignor record switch between the two;
        // the chosen one becomes $user['role'] so RoleGuard checks it as usual.
        $roles = $userRepo->rolesOf($user);
        if (!$roles) {
            return $this->deny(401, 'บัญชีนี้ยังไม่ได้ผูกกับผู้ฝากขาย กรุณาติดต่อผู้ดูแลระบบ');
        }
        $active = in_array($_SESSION['active_role'] ?? null, $roles, true) ? $_SESSION['active_role'] : $roles[0];
        $user['role'] = $active;
        $user['roles'] = $roles;

        // The consignor view only reaches its own data; most staff routes have no RoleGuard.
        if ($active === UserRepo::CONSIGNOR && !preg_match('#/api/(auth|my)/#', $request->getUri()->getPath())) {
            return $this->deny(403, 'ไม่มีสิทธิ์เข้าถึงส่วนนี้');
        }

        $request = $request->withAttribute('user', $user);

        if (!empty($_SESSION['impersonator_id'])) {
            $admin = $userRepo->find((int) $_SESSION['impersonator_id']);
            if (!$admin || !UserRepo::canSignIn($admin) || $admin['role'] !== 'admin') {
                $this->endSession();
                return $this->deny(401, 'กรุณาเข้าสู่ระบบ');
            }
            $request = $request->withAttribute('impersonator', [
                'id' => (int) $admin['id'],
                'full_name' => $admin['full_name'],
            ]);
        }
        return $handler->handle($request);
    }

    private function deviceStillPaired(int $deviceId, int $userId): bool
    {
        try {
            $stmt = Db::connection()->prepare("SELECT 1 FROM mobile_devices WHERE id = ? AND user_id = ? AND status = 'active'");
            $stmt->execute([$deviceId, $userId]);
            return (bool) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            return false;
        }
    }

    /** Most requests read the session and release it (public/index.php): reopen it to clear it. */
    private function endSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        session_write_close();
    }

    private function deny(int $status, string $message): Response
    {
        $response = (new ResponseFactory())->createResponse($status);
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
