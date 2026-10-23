<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Services\AuditLogger;
use App\Services\CashDrawerService;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Cash drawer: opening count before selling, closing count, and payment-method corrections. */
class DrawerController
{
    use JsonResponse;

    /**
     * The accounting day sales are booked on right now, and whether its drawer
     * must be counted first. Sales after closing time go to the next opening day;
     * nobody counts that drawer at night, so they are not held up.
     */
    public static function currentDay(SettingsService $settings): array
    {
        $day = $settings->businessDay(new \DateTimeImmutable());
        return ['date' => $day['date'], 'required' => !($day['after_hours'] && $day['date'] !== date('Y-m-d'))];
    }

    public function state(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $day = self::currentDay(new SettingsService($db));
        $user = $request->getAttribute('user');
        return $this->json($response, ['data' => $this->service($db)->state($day['date'], (int) $user['id'], $day['required'])]);
    }

    public function open(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!isset($body['amount']) || !is_numeric($body['amount'])) {
            return $this->error($response, 'กรุณาระบุจำนวนเงินในลิ้นชัก', 422);
        }
        $db = Db::connection();
        $day = self::currentDay(new SettingsService($db));
        $user = $request->getAttribute('user');
        try {
            $state = $this->service($db)->open($day['date'], (float) $body['amount'], (int) $user['id'], $body['note'] ?? null, $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $state]);
    }

    public function skip(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $day = self::currentDay(new SettingsService($db));
        $user = $request->getAttribute('user');
        return $this->json($response, ['data' => $this->service($db)->skip($day['date'], (int) $user['id'])]);
    }

    public function close(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!isset($body['amount']) || !is_numeric($body['amount'])) {
            return $this->error($response, 'กรุณาระบุจำนวนเงินที่นับได้', 422);
        }
        $db = Db::connection();
        $user = $request->getAttribute('user');
        $manage = $this->canManage($db, $user);
        $date = $this->dateParam($body['date'] ?? null) ?? self::currentDay(new SettingsService($db))['date'];
        if ($date !== self::currentDay(new SettingsService($db))['date'] && !$manage) {
            return $this->error($response, 'ปิดยอดย้อนหลังได้เฉพาะผู้ดูแลระบบหรือผู้จัดการร้าน', 403);
        }
        try {
            $this->service($db)->close($date, (float) $body['amount'], (int) $user['id'], $body['note'] ?? null, $manage, $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    public function adjustOpening(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $date = $this->dateParam($args['date'] ?? null);
        if (!$date || !isset($body['amount']) || !is_numeric($body['amount'])) {
            return $this->error($response, 'กรุณาระบุจำนวนเงิน', 422);
        }
        $db = Db::connection();
        $user = $request->getAttribute('user');
        try {
            $this->service($db)->adjustOpening($date, (float) $body['amount'], (int) $user['id'], trim((string) ($body['note'] ?? '')), $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    /** One day's drawer and totals per payment method; managers also get the sales and the staff log. */
    public function summary(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $user = $request->getAttribute('user');
        $manage = $this->canManage($db, $user);
        $today = self::currentDay(new SettingsService($db))['date'];
        $date = $this->dateParam($request->getQueryParams()['date'] ?? null) ?? $today;
        if ($date !== $today && !$manage) {
            $date = $today; // cashiers see the current day only
        }
        $data = $this->service($db)->summary($date, $manage);
        $data['today'] = $today;
        $data['can_manage'] = $manage;
        return $this->json($response, ['data' => $data]);
    }

    public function behavior(Request $request, Response $response): Response
    {
        $days = max(1, min(365, (int) ($request->getQueryParams()['days'] ?? 30)));
        return $this->json($response, ['data' => $this->service(Db::connection())->behavior($days), 'days' => $days]);
    }

    public function editPayments(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $db = Db::connection();
        $user = $request->getAttribute('user');
        try {
            $this->service($db)->editPayments((int) $args['id'], is_array($body['payments'] ?? null) ? $body['payments'] : [], (int) $user['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    private function service(\PDO $db): CashDrawerService
    {
        return new CashDrawerService($db, new AuditLogger($db));
    }

    private function canManage(\PDO $db, array $user): bool
    {
        return (new UserRepo($db))->hasPermission((string) $user['role'], 'drawer.manage');
    }

    private function dateParam(mixed $v): ?string
    {
        $v = trim((string) $v);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }

    private function ip(Request $request): ?string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? null;
    }
}
