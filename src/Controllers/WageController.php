<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\SettingsService;
use App\Services\WageService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** ค่าแรงพนักงาน: wages paid to staff, a business expense (admin / store manager). */
class WageController
{
    use JsonResponse;

    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $from = $this->date($q['from'] ?? null) ?? date('Y-m-01');
        $to = $this->date($q['to'] ?? null) ?? date('Y-m-d');
        $db = Db::connection();
        $service = $this->service($db);
        $drawerDay = DrawerController::currentDay(new SettingsService($db))['date'];
        $drawer = $db->prepare('SELECT closed_at FROM cash_drawers WHERE business_date = ?');
        $drawer->execute([$drawerDay]);
        $row = $drawer->fetch();
        return $this->json($response, ['data' => $service->list($from, $to, !empty($q['user_id']) ? (int) $q['user_id'] : null) + [
            'from' => $from,
            'to' => $to,
            'staff' => $service->staff(),
            'types' => WageService::TYPES,
            'methods' => WageService::METHODS,
            // Paying cash out of the drawer needs today's drawer counted and still open.
            'drawer_open' => $row !== false && $row['closed_at'] === null,
        ]]);
    }

    public function store(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $user = $request->getAttribute('user');
        $drawerDay = DrawerController::currentDay(new SettingsService($db))['date'];
        try {
            $wage = $this->service($db)->pay((array) $request->getParsedBody(), (int) $user['id'], $drawerDay, $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $wage], 201);
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        $reason = trim((string) (((array) $request->getParsedBody())['reason'] ?? ''));
        if ($reason === '') {
            return $this->error($response, 'กรุณาระบุเหตุผลที่ยกเลิก', 422);
        }
        $db = Db::connection();
        try {
            $this->service($db)->cancel((int) $args['id'], $reason, (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    private function service(\PDO $db): WageService
    {
        return new WageService($db, new AuditLogger($db), new DocumentNumberService($db));
    }

    private function date(mixed $v): ?string
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
