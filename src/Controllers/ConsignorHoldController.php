<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\ConsignorHoldService;
use App\Services\DocumentNumberService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** บัญชีเพื่อผู้ฝากขาย: what it should hold, paying held money out, and checks against the bank. */
class ConsignorHoldController
{
    use JsonResponse;

    private function service(): ConsignorHoldService
    {
        $db = Db::connection();
        return new ConsignorHoldService($db, new AuditLogger($db), new DocumentNumberService($db));
    }

    private function ip(Request $request): ?string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? null;
    }

    private function dateParam(mixed $v): ?string
    {
        $v = trim((string) $v);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v && $v <= date('Y-m-d') ? $v : null;
    }

    public function index(Request $request, Response $response): Response
    {
        $s = $this->service();
        if (!$s->ready()) {
            return $this->error($response, 'ยังใช้บัญชีเพื่อผู้ฝากขายไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0069) ก่อน', 422);
        }
        return $this->json($response, ['data' => [
            'today' => date('Y-m-d'),
            'expected' => $s->expectedAt(date('Y-m-d')),
            'consignors' => $s->consignors(),
            'movements' => $s->movements(),
            'checks' => $s->checks(),
            'methods' => ConsignorHoldService::METHODS,
        ]]);
    }

    /** What the account should have held at the end of a day (?date=), for a statement of that day. */
    public function expected(Request $request, Response $response): Response
    {
        $date = $this->dateParam($request->getQueryParams()['date'] ?? '');
        if ($date === null) {
            return $this->error($response, 'วันที่ไม่ถูกต้อง', 422);
        }
        return $this->json($response, ['data' => ['date' => $date, 'expected' => $this->service()->expectedAt($date)]]);
    }

    public function pay(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        try {
            $id = $this->service()->pay(
                (int) ($body['consignor_id'] ?? 0),
                (float) ($body['amount'] ?? 0),
                (string) ($body['method'] ?? ''),
                (string) ($body['note'] ?? ''),
                (int) $user['id'],
                $this->ip($request)
            );
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => ['id' => $id]], 201);
    }

    /** Admin only, like cancelling any other payment to a consignor. */
    public function cancelPayout(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (($user['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้นที่ยกเลิกรายการจ่ายเงินได้', 403);
        }
        try {
            $this->service()->cancelPayout((int) $args['id'], (string) (((array) $request->getParsedBody())['reason'] ?? ''), (int) $user['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    public function check(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $date = $this->dateParam($body['check_date'] ?? '');
        if ($date === null) {
            return $this->error($response, 'วันที่ไม่ถูกต้อง', 422);
        }
        if (!is_numeric($body['actual_amount'] ?? null)) {
            return $this->error($response, 'กรุณากรอกยอดเงินในบัญชี', 422);
        }
        try {
            $id = $this->service()->recordCheck($date, (float) $body['actual_amount'], (string) ($body['note'] ?? ''), (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => ['id' => $id]], 201);
    }
}
