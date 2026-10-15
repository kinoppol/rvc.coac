<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SettingsController
{
    use JsonResponse;

    public function show(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $settings = new SettingsService($db);
        $userRepo = new UserRepo($db);
        return $this->json($response, [
            'settings' => $settings->all(),
            'role_permissions' => $userRepo->rolePermissions(),
            'audit_log' => $settings->auditLog(),
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $db = Db::connection();
        $settings = new SettingsService($db);
        $settings->update($body, (int) $user['id']);
        return $this->json($response, ['settings' => $settings->all()]);
    }
}
