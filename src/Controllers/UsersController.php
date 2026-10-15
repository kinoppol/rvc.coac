<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UsersController
{
    use JsonResponse;

    private const ROLES = ['admin', 'manager', 'advisor', 'cashier'];

    public function index(Request $request, Response $response): Response
    {
        $repo = new UserRepo(Db::connection());
        return $this->json($response, ['data' => $repo->all()]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $fullName = trim((string) ($body['full_name'] ?? ''));
        $role = (string) ($body['role'] ?? 'cashier');

        if ($username === '' || strlen($password) < 6 || $fullName === '' || !in_array($role, self::ROLES, true)) {
            return $this->error($response, 'ข้อมูลผู้ใช้ไม่ถูกต้อง (รหัสผ่านอย่างน้อย 6 ตัวอักษร)', 422);
        }

        $repo = new UserRepo(Db::connection());
        if ($repo->findByUsername($username)) {
            return $this->error($response, 'มีชื่อผู้ใช้นี้อยู่แล้ว', 422);
        }

        $id = $repo->create($username, $password, $fullName, $role);
        $user = $repo->find($id);
        unset($user['password_hash']);
        return $this->json($response, ['data' => $user], 201);
    }
}
