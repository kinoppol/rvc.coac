<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ConsignRequestRepo;
use App\Repositories\ConsignorRepo;
use App\Repositories\ProductRepo;
use App\Repositories\UserRepo;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\FeeCalculator;
use App\Services\LedgerService;
use App\Services\ListingFeeService;
use App\Services\ReceiveService;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ReceiveController
{
    use JsonResponse;

    public function lookupBarcode(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $repo = new ProductRepo($db, new AuditLogger($db));
        $product = $repo->findByBarcode($args['code']);
        if (!$product) {
            return $this->error($response, 'ไม่พบสินค้าตามบาร์โค้ดนี้', 404);
        }
        return $this->json($response, ['data' => $product]);
    }

    public function quote(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $cost = (float) ($body['cost_price'] ?? 0);
        $db = Db::connection();
        $calc = new FeeCalculator(new SettingsService($db));
        return $this->json($response, ['data' => $calc->priceFor($cost)]);
    }

    /**
     * Products this consignor has deposited before (one row per name, latest lot first),
     * so the receive form can refill the name / price / barcode of a repeat item.
     */
    public function history(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $consignorId = (int) ($params['consignor_id'] ?? 0);
        $q = trim((string) ($params['q'] ?? ''));
        if (!$consignorId) {
            return $this->json($response, ['data' => []]);
        }
        $db = Db::connection();
        $stmt = $db->prepare(
            "SELECT p.name, p.cost_price, p.sale_price, p.barcode, p.created_at AS last_at, h.lots
             FROM products p
             JOIN (
                SELECT MAX(id) AS id, COUNT(*) AS lots FROM products
                WHERE consignor_id = ? AND name LIKE ?
                GROUP BY name
             ) h ON h.id = p.id
             ORDER BY p.id DESC
             LIMIT 20"
        );
        $stmt->execute([$consignorId, '%' . addcslashes($q, '%_\\') . '%']);
        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    /** Consignors' requests waiting to be checked (or recent ones with ?status=all). */
    public function requests(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $repo = new ConsignRequestRepo($db, new AuditLogger($db));
        $repo->purgeExpired();
        $status = (string) ($request->getQueryParams()['status'] ?? 'pending');
        return $this->json($response, [
            'data' => $repo->listForStaff($status === 'all' ? null : 'pending'),
            'pending_count' => $repo->pendingCount(),
        ]);
    }

    /** The goods don't match / can't be taken: close the request with a reason the consignor sees. */
    public function rejectRequest(Request $request, Response $response, array $args): Response
    {
        $note = trim((string) (((array) $request->getParsedBody())['note'] ?? ''));
        if ($note === '') {
            return $this->error($response, 'กรุณาระบุเหตุผล', 422);
        }
        $db = Db::connection();
        $ok = (new ConsignRequestRepo($db, new AuditLogger($db)))->close(
            (int) $args['id'], 'rejected', null, $note,
            (int) $request->getAttribute('user')['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null
        );
        if (!$ok) {
            return $this->error($response, 'คำขอฝากนี้ถูกดำเนินการไปแล้ว', 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $consignorId = (int) ($body['consignor_id'] ?? 0);
        $items = $body['items'] ?? [];
        if (!$consignorId || !is_array($items) || empty($items)) {
            return $this->error($response, 'กรุณาเลือกผู้ฝากขายและเพิ่มรายการสินค้าอย่างน้อย 1 รายการ', 422);
        }

        $db = Db::connection();
        $audit = new AuditLogger($db);

        $requestId = !empty($body['request_id']) ? (int) $body['request_id'] : null;
        if ($requestId !== null) {
            $req = (new ConsignRequestRepo($db, $audit))->find($requestId);
            if (!$req || $req['status'] !== 'pending') {
                return $this->error($response, 'คำขอฝากนี้ถูกดำเนินการไปแล้วหรือไม่พบ กรุณารีเฟรชหน้า', 422);
            }
            if ((int) $req['consignor_id'] !== $consignorId) {
                return $this->error($response, 'ผู้ฝากขายไม่ตรงกับคำขอฝาก', 422);
            }
        }

        $user = $request->getAttribute('user');

        // Backdating (receive.backdate): admin / store manager only; today's date is a normal entry.
        $receiveDate = trim((string) ($body['receive_date'] ?? ''));
        if ($receiveDate === '' || $receiveDate === date('Y-m-d')) {
            $receiveDate = null;
        } else {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $receiveDate);
            if (!$d || $d->format('Y-m-d') !== $receiveDate) {
                return $this->error($response, 'วันที่รับฝากไม่ถูกต้อง', 422);
            }
            if ($receiveDate > date('Y-m-d')) {
                return $this->error($response, 'วันที่รับฝากต้องไม่เกินวันนี้', 422);
            }
            if (!(new UserRepo($db))->hasPermission($user['role'], 'receive.backdate')) {
                return $this->error($response, 'ไม่มีสิทธิ์บันทึกรับฝากย้อนหลัง (เฉพาะผู้ดูแลระบบและผู้จัดการร้าน)', 403);
            }
        }

        $settings = new SettingsService($db);
        $service = new ReceiveService(
            $db,
            new ProductRepo($db, $audit),
            new ConsignorRepo($db, $audit),
            new FeeCalculator($settings),
            new ListingFeeService($db, $settings),
            new LedgerService($db),
            new DocumentNumberService($db),
            $audit
        );

        try {
            $result = $service->receive($consignorId, $items, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null, $requestId, $receiveDate);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }

        return $this->json($response, ['data' => $result], 201);
    }
}
