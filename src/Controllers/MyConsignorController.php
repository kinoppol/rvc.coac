<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProductRepo;
use App\Repositories\UserRepo;
use App\Services\AuditLogger;
use App\Services\LedgerService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** The consignor view: a signed-in user's own passbook and products, nobody else's. */
class MyConsignorController
{
    use JsonResponse;

    public function passbook(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $consignor = $this->consignorFor($request);
        if (!$consignor) {
            return $this->error($response, 'บัญชีนี้ยังไม่ได้ผูกกับผู้ฝากขาย', 404);
        }
        $stmt = $db->prepare('SELECT * FROM consignors WHERE id = ?');
        $stmt->execute([$consignor['id']]);
        $ledger = new LedgerService($db);
        return $this->json($response, [
            'consignor' => $stmt->fetch(),
            'entries' => $ledger->forConsignor((int) $consignor['id']),
            'balance' => $ledger->balanceOf((int) $consignor['id']),
        ]);
    }

    public function products(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $consignor = $this->consignorFor($request);
        if (!$consignor) {
            return $this->error($response, 'บัญชีนี้ยังไม่ได้ผูกกับผู้ฝากขาย', 404);
        }
        return $this->json($response, [
            'consignor' => $consignor,
            'data' => (new ProductRepo($db, new AuditLogger($db)))->forConsignor((int) $consignor['id']),
        ]);
    }

    private function consignorFor(Request $request): ?array
    {
        return (new UserRepo(Db::connection()))->consignorOf((int) $request->getAttribute('user')['id']);
    }
}
