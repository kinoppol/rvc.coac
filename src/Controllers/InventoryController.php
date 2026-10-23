<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ConsignorRepo;
use App\Repositories\ProductRepo;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\LedgerService;
use App\Services\InventoryService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class InventoryController
{
    use JsonResponse;

    public function index(Request $request, Response $response): Response
    {
        $service = new InventoryService(Db::connection());
        $params = $request->getQueryParams();
        $search = trim((string) ($params['q'] ?? ''));
        return $this->json($response, [
            'summary' => $service->summary(),
            'data' => $service->products($search, !empty($params['consigned'])),
        ]);
    }

    /** Name, piece barcode (scanned at the POS) and the pack barcodes of one store product. */
    public function updateBarcodes(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $db = Db::connection();
        $repo = new ProductRepo($db, new AuditLogger($db));
        $id = (int) $args['id'];
        $db->beginTransaction();
        try {
            $repo->updateBarcodes($id, $body['barcode'] ?? null, (array) ($body['pack_barcodes'] ?? []), (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null, isset($body['name']) ? (string) $body['name'] : null);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($e instanceof \RuntimeException) {
                return $this->error($response, $e->getMessage(), 422);
            }
            throw $e;
        }
        $p = $repo->find($id);
        return $this->json($response, ['data' => ['name' => $p['name'], 'barcode' => $p['barcode'], 'pack_barcodes' => $repo->packBarcodes($id)]]);
    }

    /** Admin only: a store product that is really consignment goods moves to its consignor. */
    public function convertToConsignment(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (($user['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้นที่เปลี่ยนเป็นสินค้าฝากขายได้', 403);
        }
        $body = (array) $request->getParsedBody();
        $consignorId = (int) ($body['consignor_id'] ?? 0);
        if (!isset($body['cost_price']) || !is_numeric($body['cost_price'])) {
            return $this->error($response, 'กรุณาระบุราคาที่ผู้ฝากได้ต่อชิ้น', 422);
        }
        $db = Db::connection();
        $audit = new AuditLogger($db);
        if (!$consignorId || !(new ConsignorRepo($db, $audit))->find($consignorId)) {
            return $this->error($response, 'กรุณาเลือกผู้ฝากขาย', 422);
        }
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $repo = new ProductRepo($db, $audit);
        $claimed = null;
        $db->beginTransaction();
        try {
            $repo->convertToConsignment((int) $args['id'], $consignorId, (float) $body['cost_price'], (int) $user['id'], $ip);
            if (!empty($body['include_past_sales'])) {
                $claimed = $repo->claimStoreSales((int) $args['id'], new LedgerService($db), new DocumentNumberService($db), (int) $user['id'], $ip);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($e instanceof \RuntimeException) {
                return $this->error($response, $e->getMessage(), 422);
            }
            throw $e;
        }
        return $this->json($response, ['ok' => true, 'claimed' => $claimed]);
    }

    /**
     * Admin only: this consignor's products whose sales are still recorded as the store's
     * (sold before the product was changed to consignment), with what the consignor would get.
     */
    public function unclaimedSales(Request $request, Response $response, array $args): Response
    {
        if (($request->getAttribute('user')['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้น', 403);
        }
        return $this->json($response, ['data' => $this->unclaimedFor(Db::connection(), (int) $args['id'])]);
    }

    /** Admin only: move every unclaimed sale of this consignor's products into their ledger. */
    public function claimConsignorSales(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (($user['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้น', 403);
        }
        $db = Db::connection();
        $repo = new ProductRepo($db, new AuditLogger($db));
        $ledger = new LedgerService($db);
        $docs = new DocumentNumberService($db);
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $total = ['products' => 0, 'qty' => 0, 'sale' => 0.0, 'fee' => 0.0];
        $db->beginTransaction();
        try {
            foreach ($this->unclaimedFor($db, (int) $args['id']) as $p) {
                $r = $repo->claimStoreSales((int) $p['id'], $ledger, $docs, (int) $user['id'], $ip);
                $total['products']++;
                $total['qty'] += $r['qty'];
                $total['sale'] = round($total['sale'] + $r['sale'], 2);
                $total['fee'] = round($total['fee'] + $r['fee'], 2);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($e instanceof \RuntimeException) {
                return $this->error($response, $e->getMessage(), 422);
            }
            throw $e;
        }
        return $this->json($response, ['data' => $total]);
    }

    private function unclaimedFor(\PDO $db, int $consignorId): array
    {
        $stmt = $db->prepare(
            "SELECT p.id, p.name, SUM(si.qty) AS qty, ROUND(SUM(si.qty * si.unit_price), 2) AS sale,
                    ROUND(SUM(si.qty) * (p.fee_per_unit + IF(p.rounding_beneficiary = 'store', p.round_per_unit, 0)), 2) AS fee,
                    MIN(s.sale_datetime) AS first_sale, MAX(s.sale_datetime) AS last_sale
             FROM sale_items si
             JOIN products p ON p.id = si.product_id
             JOIN sales s ON s.id = si.sale_id
             WHERE p.consignor_id = ? AND si.consignor_id IS NULL
             GROUP BY p.id, p.name, p.fee_per_unit, p.rounding_beneficiary, p.round_per_unit
             ORDER BY p.name"
        );
        $stmt->execute([$consignorId]);
        return $stmt->fetchAll();
    }

    /** Admin only: credit the consignor with sales made before the product became theirs. */
    public function claimStoreSales(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (($user['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้น', 403);
        }
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $claimed = (new ProductRepo($db, new AuditLogger($db)))->claimStoreSales(
                (int) $args['id'], new LedgerService($db), new DocumentNumberService($db),
                (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($e instanceof \RuntimeException) {
                return $this->error($response, $e->getMessage(), 422);
            }
            throw $e;
        }
        return $this->json($response, ['data' => $claimed]);
    }
}
