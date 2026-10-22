<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Services\SettingsService;
use App\Support\Config;
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
            'timezones' => \DateTimeZone::listIdentifiers(),
            'timezone_default' => (string) Config::get('app.timezone', 'Asia/Bangkok'),
            'timezone_current' => date_default_timezone_get(),
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (isset($body['login_methods']) && !in_array($body['login_methods'], SettingsService::LOGIN_METHODS, true)) {
            return $this->error($response, 'วิธีการเข้าสู่ระบบไม่ถูกต้อง', 422);
        }
        if (isset($body['timezone']) && $body['timezone'] !== '' && !Db::isTimezone((string) $body['timezone'])) {
            return $this->error($response, 'Time zone ไม่ถูกต้อง', 422);
        }
        if (isset($body['pos_payment_methods'])) {
            $methods = array_values(array_intersect(array_keys(SettingsService::PAYMENT_METHODS), explode(',', (string) $body['pos_payment_methods'])));
            if (!$methods) {
                return $this->error($response, 'ต้องเปิดรับชำระอย่างน้อย 1 วิธี', 422);
            }
            $body['pos_payment_methods'] = implode(',', $methods);
        }
        if (isset($body['pos_payment_mixed'])) {
            $body['pos_payment_mixed'] = $body['pos_payment_mixed'] ? '1' : '0';
        }
        $user = $request->getAttribute('user');
        $db = Db::connection();
        $settings = new SettingsService($db);
        $settings->update($body, (int) $user['id']);
        return $this->json($response, ['settings' => $settings->all()]);
    }
}
