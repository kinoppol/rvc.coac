<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Services\AuditLogger;
use App\Services\ClosingService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** สรุปบัญชีรายวัน: the duty roster, each day's figures and the cash handed to the college. */
class ClosingController
{
    use JsonResponse;

    /** The last ?days= days (default 14), the roster and the viewer's own duty weekdays. */
    public function index(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $user = $request->getAttribute('user');
        $service = $this->service($db);
        $days = max(1, min(62, (int) ($request->getQueryParams()['days'] ?? 14)));
        $today = date('Y-m-d');
        $from = (new \DateTimeImmutable($today))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $manage = $this->canManage($db, $user);
        return $this->json($response, ['data' => [
            'today' => $today,
            'can_manage' => $manage,
            'weekdays' => ClosingService::WEEKDAYS,
            'my_duties' => $service->dutiesOf((int) $user['id']),
            'roster' => $service->roster(),
            'candidates' => $manage ? $service->candidates() : [],
            'days' => $service->days($from, $today),
        ]]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $date = $this->dateParam($args['date']);
        if ($date === null) {
            return $this->error($response, 'วันที่ไม่ถูกต้อง', 422);
        }
        $service = $this->service($db);
        $user = $request->getAttribute('user');
        return $this->json($response, ['data' => $service->report($date) + [
            'closing' => $service->find($date),
            'on_duty' => $service->roster()[$service->weekdayOf($date)],
            'can_submit' => $date <= date('Y-m-d') && ($this->canManage($db, $user) || $service->isOnDuty((int) $user['id'], $date)),
        ]]);
    }

    public function submit(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $date = $this->dateParam($args['date']);
        if ($date === null) {
            return $this->error($response, 'วันที่ไม่ถูกต้อง', 422);
        }
        $user = $request->getAttribute('user');
        try {
            $row = $this->service($db)->submit($date, (array) $request->getParsedBody(), (int) $user['id'], $this->canManage($db, $user), date('Y-m-d'), $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $row]);
    }

    /** Leave a step of the day's report for later, or take that back (body: item, on, note). */
    public function skip(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $date = $this->dateParam($args['date']);
        $user = $request->getAttribute('user');
        $service = $this->service($db);
        if ($date === null) {
            return $this->error($response, 'วันที่ไม่ถูกต้อง', 422);
        }
        if (!$this->canManage($db, $user) && !$service->isOnDuty((int) $user['id'], $date)) {
            return $this->error($response, 'ข้ามรายการได้เฉพาะผู้มีเวรสรุปบัญชีวันนี้ ผู้จัดการ หรือผู้ดูแลระบบ', 403);
        }
        $body = (array) $request->getParsedBody();
        try {
            $service->skip($date, (string) ($body['item'] ?? ''), !empty($body['on']), (int) $user['id'], isset($body['note']) ? (string) $body['note'] : null, $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $service->report($date)['skips']]);
    }

    public function verify(Request $request, Response $response, array $args): Response
    {
        $date = $this->dateParam($args['date']);
        if ($date === null) {
            return $this->error($response, 'วันที่ไม่ถูกต้อง', 422);
        }
        try {
            $row = $this->service(Db::connection())->verify($date, (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $row]);
    }

    public function setRoster(Request $request, Response $response): Response
    {
        $roster = ((array) $request->getParsedBody())['roster'] ?? [];
        $data = $this->service(Db::connection())->setRoster(is_array($roster) ? $roster : [], (int) $request->getAttribute('user')['id'], $this->ip($request));
        return $this->json($response, ['data' => $data]);
    }

    private function service(\PDO $db): ClosingService
    {
        return new ClosingService($db, new AuditLogger($db));
    }

    private function canManage(\PDO $db, array $user): bool
    {
        return (new UserRepo($db))->hasPermission((string) $user['role'], 'closing.manage');
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
