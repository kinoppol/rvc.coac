<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\LedgerService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Unauthenticated, tokenized read-only passbook view (the mockup's "สแกนดูยอดเอง" QR). */
class PortalController
{
    use JsonResponse;

    public function show(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $stmt = $db->prepare('SELECT * FROM consignors WHERE code = ? AND portal_token = ? LIMIT 1');
        $stmt->execute([$args['code'], $args['token']]);
        $consignor = $stmt->fetch();
        if (!$consignor) {
            return $this->error($response, 'ลิงก์ไม่ถูกต้องหรือหมดอายุ', 404);
        }

        $ledger = new LedgerService($db);
        return $this->json($response, [
            'consignor' => [
                'code' => $consignor['code'],
                'name' => $consignor['name'],
                'type' => $consignor['type'],
            ],
            'entries' => $ledger->forConsignor((int) $consignor['id']),
            'balance' => $ledger->balanceOf((int) $consignor['id']),
        ]);
    }
}
