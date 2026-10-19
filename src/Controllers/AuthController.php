<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Services\AuditLogger;
use App\Services\SettingsService;
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

        $mode = (new SettingsService(Db::connection()))->loginMethods();
        // SSO-only still lets admins in with a password, in case RVC-ONE is down.
        if ($mode === 'sso' && $user['role'] !== 'admin') {
            return $this->error($response, 'ระบบเปิดให้เข้าสู่ระบบผ่าน RVC-ONE เท่านั้น (รหัสผ่านใช้ได้เฉพาะผู้ดูแลระบบ)', 403);
        }

        $roles = $userRepo->rolesOf($user);
        if (!$roles) {
            return $this->error($response, 'บัญชีนี้ยังไม่ได้ผูกกับผู้ฝากขาย กรุณาติดต่อผู้ดูแลระบบ', 403);
        }

        session_regenerate_id(true);
        unset($_SESSION['impersonator_id'], $_SESSION['active_role']);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $user['role'] = $roles[0];
        $user['roles'] = $roles;
        return $this->json($response, $this->sessionPayload($userRepo, $user, null));
    }

    /** Public: which sign-in options the login page should offer. */
    public function methods(Request $request, Response $response): Response
    {
        return $this->json($response, ['login_methods' => (new SettingsService(Db::connection()))->loginMethods()]);
    }

    public function logout(Request $request, Response $response): Response
    {
        // Signing out of an impersonated account drops back to the admin who started it.
        if (!empty($_SESSION['impersonator_id'])) {
            $adminId = (int) $_SESSION['impersonator_id'];
            $admin = (new UserRepo(Db::connection()))->find($adminId);
            if ($admin && UserRepo::canSignIn($admin) && $admin['role'] === 'admin') {
                $this->auditImpersonation($request, (int) $_SESSION['user_id'], $adminId, 'impersonate_stop');
                session_regenerate_id(true);
                unset($_SESSION['impersonator_id'], $_SESSION['active_role']);
                $_SESSION['user_id'] = $adminId;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                return $this->json($response, ['ok' => true, 'restored' => true]);
            }
        }

        $_SESSION = [];
        session_destroy();
        return $this->json($response, ['ok' => true]);
    }

    public function me(Request $request, Response $response): Response
    {
        return $this->json($response, $this->sessionPayload(
            new UserRepo(Db::connection()),
            $request->getAttribute('user'),
            $request->getAttribute('impersonator')
        ));
    }

    /** Switch the menus/permissions between the roles this user holds (e.g. manager ↔ consignor). */
    public function switchRole(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $role = (string) (((array) $request->getParsedBody())['role'] ?? '');
        if (!in_array($role, $user['roles'], true)) {
            return $this->error($response, 'คุณไม่มีบทบาทนี้', 422);
        }
        $_SESSION['active_role'] = $role;
        return $this->json($response, ['ok' => true]);
    }

    /** Admin only: act as another user until logout, which returns to the admin account. */
    public function impersonate(Request $request, Response $response, array $args): Response
    {
        $me = $request->getAttribute('user');
        if (!empty($_SESSION['impersonator_id']) || $me['role'] !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้นที่สวมสิทธิ์ผู้ใช้อื่นได้', 403);
        }

        $id = (int) $args['id'];
        $userRepo = new UserRepo(Db::connection());
        $target = $userRepo->find($id);
        if (!$target) {
            return $this->error($response, 'ไม่พบผู้ใช้', 404);
        }
        if ($id === (int) $me['id']) {
            return $this->error($response, 'ไม่สามารถสวมสิทธิ์ตัวเองได้', 422);
        }
        if (!UserRepo::canSignIn($target)) {
            return $this->error($response, 'บัญชีผู้ใช้นี้ปิดอยู่หรือถูกระงับ', 422);
        }
        if (!$userRepo->rolesOf($target)) {
            return $this->error($response, 'ผู้ใช้นี้ยังไม่ได้ผูกกับผู้ฝากขาย', 422);
        }

        $this->auditImpersonation($request, $id, (int) $me['id'], 'impersonate_start');
        session_regenerate_id(true);
        unset($_SESSION['active_role']);
        $_SESSION['impersonator_id'] = (int) $me['id'];
        $_SESSION['user_id'] = $id;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        return $this->json($response, ['ok' => true]);
    }

    private function auditImpersonation(Request $request, int $targetId, int $adminId, string $event): void
    {
        (new AuditLogger(Db::connection()))->log(
            'users', $targetId, 'update', null, ['event' => $event],
            $adminId,
            $request->getServerParams()['REMOTE_ADDR'] ?? null
        );
    }

    /** What the SPA needs about the signed-in session; $user['role'] is the active role. */
    private function sessionPayload(UserRepo $userRepo, array $user, ?array $impersonator): array
    {
        $consignor = in_array(UserRepo::CONSIGNOR, $user['roles'], true) ? $userRepo->consignorOf((int) $user['id']) : null;
        return [
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'roles' => $user['roles'],
                'consignor' => $consignor,
            ],
            'permissions' => $userRepo->permissionsFor($user['role']),
            'csrf_token' => $_SESSION['csrf_token'] ?? null,
            'impersonator' => $impersonator,
            'timezone' => date_default_timezone_get(),
        ];
    }
}
