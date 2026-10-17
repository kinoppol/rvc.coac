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
        if (empty($_SESSION['user_id'])) {
            return $this->deny(401, 'กรุณาเข้าสู่ระบบ');
        }

        $userRepo = new UserRepo(Db::connection());
        $user = $userRepo->find((int) $_SESSION['user_id']);
        if (!$user || !UserRepo::canSignIn($user)) {
            return $this->deny(401, 'บัญชีผู้ใช้ไม่พร้อมใช้งาน');
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
                $_SESSION = [];
                return $this->deny(401, 'กรุณาเข้าสู่ระบบ');
            }
            $request = $request->withAttribute('impersonator', [
                'id' => (int) $admin['id'],
                'full_name' => $admin['full_name'],
            ]);
        }
        return $handler->handle($request);
    }

    private function deny(int $status, string $message): Response
    {
        $response = (new ResponseFactory())->createResponse($status);
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
