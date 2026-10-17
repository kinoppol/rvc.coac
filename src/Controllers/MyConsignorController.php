<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ConsignRequestRepo;
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

    public function requests(Request $request, Response $response): Response
    {
        $consignor = $this->consignorFor($request);
        if (!$consignor) {
            return $this->error($response, 'บัญชีนี้ยังไม่ได้ผูกกับผู้ฝากขาย', 404);
        }
        return $this->json($response, ['data' => $this->requestRepo()->forConsignor((int) $consignor['id'])]);
    }

    /** The consignor lists the goods and quantities they will bring for staff to check and receive. */
    public function storeRequest(Request $request, Response $response): Response
    {
        $consignor = $this->consignorFor($request);
        if (!$consignor) {
            return $this->error($response, 'บัญชีนี้ยังไม่ได้ผูกกับผู้ฝากขาย', 404);
        }
        $body = (array) $request->getParsedBody();
        try {
            $items = ConsignRequestRepo::cleanItems($body['items'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        $created = $this->requestRepo()->create(
            (int) $consignor['id'], $items, isset($body['note']) ? (string) $body['note'] : null,
            (int) $request->getAttribute('user')['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null
        );
        return $this->json($response, ['data' => $created], 201);
    }

    public function cancelRequest(Request $request, Response $response, array $args): Response
    {
        $consignor = $this->consignorFor($request);
        $repo = $this->requestRepo();
        $req = $repo->find((int) $args['id']);
        if (!$consignor || !$req || (int) $req['consignor_id'] !== (int) $consignor['id']) {
            return $this->error($response, 'ไม่พบคำขอฝาก', 404);
        }
        $ok = $repo->close((int) $req['id'], 'cancelled', null, null,
            (int) $request->getAttribute('user')['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        if (!$ok) {
            return $this->error($response, 'ร้านตรวจรับคำขอนี้ไปแล้ว ยกเลิกไม่ได้', 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    private function requestRepo(): ConsignRequestRepo
    {
        $db = Db::connection();
        return new ConsignRequestRepo($db, new AuditLogger($db));
    }

    private function consignorFor(Request $request): ?array
    {
        return (new UserRepo(Db::connection()))->consignorOf((int) $request->getAttribute('user')['id']);
    }
}
