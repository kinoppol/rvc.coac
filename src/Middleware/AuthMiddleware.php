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
            $response = (new ResponseFactory())->createResponse(401);
            $response->getBody()->write(json_encode(['error' => 'กรุณาเข้าสู่ระบบ']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $userRepo = new UserRepo(Db::connection());
        $user = $userRepo->find((int) $_SESSION['user_id']);
        if (!$user || !UserRepo::canSignIn($user)) {
            $response = (new ResponseFactory())->createResponse(401);
            $response->getBody()->write(json_encode(['error' => 'บัญชีผู้ใช้ไม่พร้อมใช้งาน']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $request = $request->withAttribute('user', $user);

        if (!empty($_SESSION['impersonator_id'])) {
            $admin = $userRepo->find((int) $_SESSION['impersonator_id']);
            if (!$admin || !UserRepo::canSignIn($admin) || $admin['role'] !== 'admin') {
                $_SESSION = [];
                $response = (new ResponseFactory())->createResponse(401);
                $response->getBody()->write(json_encode(['error' => 'กรุณาเข้าสู่ระบบ']));
                return $response->withHeader('Content-Type', 'application/json');
            }
            $request = $request->withAttribute('impersonator', [
                'id' => (int) $admin['id'],
                'full_name' => $admin['full_name'],
            ]);
        }
        return $handler->handle($request);
    }
}
