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

/** Checks the logged-in user's role has the given permission key (see role_permissions table). */
class RoleGuard implements MiddlewareInterface
{
    public function __construct(private string $permissionKey)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        /** @var array $user */
        $user = $request->getAttribute('user');
        $userRepo = new UserRepo(Db::connection());

        if (!$user || !$userRepo->hasPermission($user['role'], $this->permissionKey)) {
            $response = (new ResponseFactory())->createResponse(403);
            $response->getBody()->write(json_encode(['error' => 'ไม่มีสิทธิ์เข้าถึงส่วนนี้']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
