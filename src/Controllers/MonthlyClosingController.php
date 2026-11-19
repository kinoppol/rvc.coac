<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\MonthlyClosingService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** สรุปยอดปิดบัญชีประจำเดือน (migration 0085): view with reports.view; admin / manager close a month. */
class MonthlyClosingController
{
    use JsonResponse;

    private function service(): MonthlyClosingService
    {
        return new MonthlyClosingService(Db::connection());
    }

    private static function canClose(Request $request): bool
    {
        return in_array($request->getAttribute('user')['role'] ?? '', ['admin', 'manager'], true);
    }

    /** GET /monthly-closings — the months, newest first, with their closing. */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => $this->service()->months(), 'ready' => MonthlyClosingService::ready(Db::connection()), 'can_close' => self::canClose($request)]);
    }

    /** GET /monthly-closings/{month} — the figures now, the snapshot and what changed since. */
    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $data = $this->service()->show((string) $args['month']);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $data + ['can_close' => self::canClose($request)]]);
    }

    /** POST /monthly-closings/{month}/close {note?} — keep the month's figures as they are now. */
    public function close(Request $request, Response $response, array $args): Response
    {
        if (!self::canClose($request)) {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบหรือผู้จัดการร้านเท่านั้น', 403);
        }
        $user = $request->getAttribute('user');
        $db = Db::connection();
        try {
            $data = $this->service()->close((string) $args['month'], (string) (((array) $request->getParsedBody())['note'] ?? ''),
                (int) $user['id'], new AuditLogger($db), $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $data + ['can_close' => true]]);
    }
}
