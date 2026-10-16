<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    use JsonResponse;

    public function login(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            return $this->error($response, 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน', 422);
        }

        $userRepo = new UserRepo(Db::connection());
        $user = $userRepo->findByUsername($username);

        if (!$user || !UserRepo::canSignIn($user) || !password_verify($password, $user['password_hash'])) {
            return $this->error($response, 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 401);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        return $this->json($response, [
            'user' => $this->publicUser($user),
            'permissions' => $this->permissionsFor($userRepo, $user['role']),
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $_SESSION = [];
        session_destroy();
        return $this->json($response, ['ok' => true]);
    }

    public function me(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userRepo = new UserRepo(Db::connection());
        return $this->json($response, [
            'user' => $this->publicUser($user),
            'permissions' => $this->permissionsFor($userRepo, $user['role']),
            'csrf_token' => $_SESSION['csrf_token'] ?? null,
        ]);
    }

    private function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
        ];
    }

    /** @return string[] */
    private function permissionsFor(UserRepo $userRepo, string $role): array
    {
        $keys = [];
        foreach ($userRepo->rolePermissions() as $rp) {
            if ($rp['role'] === $role && (bool) $rp['allowed']) {
                $keys[] = $rp['permission_key'];
            }
        }
        return $keys;
    }
}
