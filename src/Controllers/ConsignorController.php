<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ConsignorRepo;
use App\Repositories\ProductRepo;
use App\Services\AuditLogger;
use App\Services\LedgerService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ConsignorController
{
    use JsonResponse;

    public function index(Request $request, Response $response): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $db = Db::connection();
        $repo = new ConsignorRepo($db, new AuditLogger($db));
        return $this->json($response, ['data' => $repo->search($q)]);
    }

    public function options(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $repo = new ConsignorRepo($db, new AuditLogger($db));
        return $this->json($response, ['data' => $repo->options()]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        foreach (['name', 'type', 'phone', 'pay_channel'] as $field) {
            if (empty($body[$field])) {
                return $this->error($response, "กรุณากรอก $field", 422);
            }
        }

        $user = $request->getAttribute('user');
        $db = Db::connection();
        $repo = new ConsignorRepo($db, new AuditLogger($db));

        $id = $repo->create($body, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        return $this->json($response, ['data' => $repo->find($id)], 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $repo = new ConsignorRepo($db, new AuditLogger($db));
        $consignor = $repo->find((int) $args['id']);
        if (!$consignor) {
            return $this->error($response, 'ไม่พบผู้ฝากขาย', 404);
        }
        $productRepo = new ProductRepo($db, new AuditLogger($db));
        return $this->json($response, [
            'data' => $consignor,
            'products' => $productRepo->forConsignor((int) $args['id']),
        ]);
    }

    public function passbook(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $consignorRepo = new ConsignorRepo($db, new AuditLogger($db));
        $consignor = $consignorRepo->find((int) $args['id']);
        if (!$consignor) {
            return $this->error($response, 'ไม่พบผู้ฝากขาย', 404);
        }
        $ledger = new LedgerService($db);
        return $this->json($response, [
            'consignor' => $consignor,
            'entries' => $ledger->forConsignor((int) $args['id']),
            'balance' => $ledger->balanceOf((int) $args['id']),
        ]);
    }
}
