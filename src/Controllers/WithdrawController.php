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
        return $this->json($response, [
            'data' => $repo->recent(),
            'pending_count' => $repo->pendingCount(),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $consignorId = (int) ($body['consignor_id'] ?? 0);
        $amount = (float) ($body['amount'] ?? 0);
        $method = (string) ($body['method'] ?? 'cash');
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
                $id = $repo->payNow($consignorId, $amount, $method, (int) $user['id'], $ip);
            } else {
                $id = $repo->requestPending($consignorId, $amount, $method, (int) $user['id'], $ip);
            }
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }

        return $this->json($response, ['data' => $repo->find($id)], 201);
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
