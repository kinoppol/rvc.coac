<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Repositories\WithdrawalRepo;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\LedgerService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WithdrawController
{
    use JsonResponse;

    private function repo(): WithdrawalRepo
    {
        $db = Db::connection();
        $audit = new AuditLogger($db);
        return new WithdrawalRepo($db, $audit, new DocumentNumberService($db), new LedgerService($db));
    }

    public function index(Request $request, Response $response): Response
    {
        $repo = $this->repo();
        $params = $request->getQueryParams();
        $period = [];
        foreach (['from', 'to'] as $key) {
            $value = trim((string) ($params[$key] ?? ''));
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($value !== '' && (!$date || $date->format('Y-m-d') !== $value)) {
                return $this->error($response, 'วันที่ไม่ถูกต้อง', 422);
            }
            $period[$key] = $value !== '' ? $value : null;
        }
        // Without a period (the menu's badge) this stays the latest few; the page says if a period reaches the limit.
        $limit = $period['from'] !== null && $period['to'] !== null ? 1000 : null;
        return $this->json($response, [
            'data' => $limit !== null ? $repo->recent($limit, $period['from'], $period['to']) : $repo->recent(),
            'pending_count' => $repo->pendingCount(),
            'can_hold' => (new \App\Services\ConsignorHoldService(Db::connection(), new AuditLogger(Db::connection()), new DocumentNumberService(Db::connection())))->ready(),
        ] + ($limit !== null ? ['limit' => $limit] : []));
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $consignorId = (int) ($body['consignor_id'] ?? 0);
        $amount = (float) ($body['amount'] ?? 0);
        $method = (string) ($body['method'] ?? 'cash');
        // Holding is done from the daily report, where the drawer it takes from is summed up.
        if (!in_array($method, ['cash', 'promptpay', 'bank_transfer'], true)) {
            return $this->error($response, 'ช่องทางจ่ายเงินไม่ถูกต้อง', 422);
        }
        if (!$consignorId || $amount <= 0) {
            return $this->error($response, 'กรุณาเลือกผู้ฝากขายและระบุจำนวนเงิน', 422);
        }

        $user = $request->getAttribute('user');
        $db = Db::connection();
        $userRepo = new UserRepo($db);
        $canApprove = $userRepo->hasPermission($user['role'], 'withdraw.approve');
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        $repo = $this->repo();
        try {
            if ($canApprove) {
                $id = $repo->payNow($consignorId, $amount, $method, (int) $user['id'], $ip, (string) ($body['overdraw_reason'] ?? ''));
            } else {
                $id = $repo->requestPending($consignorId, $amount, $method, (int) $user['id'], $ip);
            }
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }

        return $this->json($response, ['data' => $repo->find($id)], 201);
    }

    /** Admin only: correct the amount or method of a withdrawal (pending or paid) with a reason. */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (($user['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้นที่แก้ไขรายการเบิกได้', 403);
        }
        $body = (array) $request->getParsedBody();
        $repo = $this->repo();
        try {
            $repo->edit(
                (int) $args['id'],
                (float) ($body['amount'] ?? 0),
                (string) ($body['method'] ?? ''),
                (string) ($body['reason'] ?? ''),
                (int) $user['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? null
            );
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $repo->find((int) $args['id'])]);
    }

    /** Admin only: cancel a withdrawal (pending or paid) with a reason. */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (($user['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้นที่ยกเลิกรายการเบิกได้', 403);
        }
        $reason = (string) (((array) $request->getParsedBody())['reason'] ?? '');
        $repo = $this->repo();
        try {
            $repo->cancel((int) $args['id'], $reason, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $repo->find((int) $args['id'])]);
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $repo = $this->repo();
        try {
            $repo->approve((int) $args['id'], (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $repo->find((int) $args['id'])]);
    }
}
