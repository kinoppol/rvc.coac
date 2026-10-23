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
        if (isset($body['qr_login'])) {
            $body['qr_login'] = in_array($body['qr_login'], ['1', 1, true], true) ? '1' : '0';
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
        foreach (['store_open_time', 'store_close_time'] as $key) {
            if (isset($body[$key]) && $body[$key] !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $body[$key])) {
                return $this->error($response, 'เวลาเปิด-ปิดร้านไม่ถูกต้อง', 422);
            }
        }
        if (isset($body['store_open_time'], $body['store_close_time'])) {
            $open = (string) $body['store_open_time'];
            $close = (string) $body['store_close_time'];
            if (($open === '') !== ($close === '')) {
                return $this->error($response, 'กรุณาระบุทั้งเวลาเปิดและเวลาปิดร้าน หรือเว้นว่างทั้งคู่', 422);
            }
            if ($open !== '' && $close <= $open) {
                return $this->error($response, 'เวลาปิดร้านต้องหลังเวลาเปิดร้าน', 422);
            }
        }
        if (isset($body['store_closed_days'])) {
            $body['store_closed_days'] = implode(',', array_intersect(['0', '1', '2', '3', '4', '5', '6'], explode(',', (string) $body['store_closed_days'])));
        }
        foreach (['pos_mixed_roles', 'pos_short_stock_roles'] as $key) {
            if (isset($body[$key])) {
                $body[$key] = implode(',', array_intersect(SettingsService::POS_ROLES, explode(',', (string) $body[$key])));
            }
        }
        $user = $request->getAttribute('user');
        $db = Db::connection();
        $settings = new SettingsService($db);
        $settings->update($body, (int) $user['id']);
        return $this->json($response, ['settings' => $settings->all()]);
    }
}
