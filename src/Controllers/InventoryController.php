<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ConsignorRepo;
use App\Repositories\ProductRepo;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\LedgerService;
use App\Services\InventoryService;
use App\Services\StockCountService;
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

    /**
     * GET /inventory/lookup?code= — a product by its piece or pack barcode, for the phone
     * data mode (scanned_pack_size says which pack a pack barcode belongs to).
     */
    public function lookup(Request $request, Response $response): Response
    {
        $code = trim((string) ($request->getQueryParams()['code'] ?? ''));
        $found = $this->stockCounts()->lookup(0, $code);
        if (!$found) {
            return $this->error($response, "ไม่พบสินค้าที่มีบาร์โค้ด {$code}", 404);
        }
        unset($found['item']);
        return $this->json($response, ['data' => $found]);
    }

    /** GET /inventory/search?q= — products by name or barcode, for linking a new barcode on the phone. */
    public function search(Request $request, Response $response): Response
    {
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if ($q === '') {
            return $this->json($response, ['data' => []]);
        }
        return $this->json($response, ['data' => array_map(function ($p) {
            unset($p['item']);
            return $p;
        }, $this->stockCounts()->search(0, $q))]);
    }

    /**
     * POST /inventory/{id}/assign-barcode {barcode, pack_size} — gives a product a barcode
     * scanned on the phone: the piece barcode (replacing the old one), or a pack barcode.
     * A consignor's goods: admin only, as in the barcode editor.
     */
    public function assignBarcode(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $db = Db::connection();
        $repo = new ProductRepo($db, new AuditLogger($db));
        $id = (int) $args['id'];
        if (($repo->find($id)['consignor_id'] ?? null) !== null && ($user['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้นที่แก้บาร์โค้ดสินค้าฝากขายได้', 403);
        }
        $db->beginTransaction();
        try {
            $repo->assignBarcode($id, (string) ($body['barcode'] ?? ''), max(1, (int) ($body['pack_size'] ?? 1)),
                (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($e instanceof \RuntimeException) {
                return $this->error($response, $e->getMessage(), 422);
            }
            throw $e;
        }
        $p = $this->stockCounts()->productInfo(0, $id);
        unset($p['item']);
        return $this->json($response, ['data' => $p]);
    }

    private function stockCounts(): StockCountService
    {
        $db = Db::connection();
        return new StockCountService($db, new DocumentNumberService($db), new AuditLogger($db));
    }

    /** One product's details and every stock movement up to its current balance. */
    public function movements(Request $request, Response $response, array $args): Response
    {
        $data = (new InventoryService(Db::connection()))->movements((int) $args['id']);
        if ($data === null) {
            return $this->error($response, 'ไม่พบสินค้า', 404);
        }
        return $this->json($response, ['data' => $data]);
    }

    /** Admin only: store products whose stock may be off from documents keyed in late or out of order. */
    public function orderCheck(Request $request, Response $response): Response
    {
        if (($request->getAttribute('user')['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้น', 403);
        }
        return $this->json($response, ['data' => (new InventoryService(Db::connection()))->orderCheck()]);
    }

    /** Suppliers each selected product was bought from (?ids=1,2,3), for building purchase orders. */
    public function supplierOptions(Request $request, Response $response): Response
    {
        $ids = explode(',', (string) ($request->getQueryParams()['ids'] ?? ''));
        return $this->json($response, ['data' => (new InventoryService(Db::connection()))->supplierOptions(array_slice($ids, 0, 300))]);
    }

    /**
     * Creates one purchase order (waiting for delivery) per supplier from products picked in stock.
     * Body: {orders: [{supplier_name, supplier_tax_id?, funding?, items: [{product_id, name, receipt_name?, barcode?, qty, pack_size, unit_cost}]}]}
     */
    public function createOrders(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $service = PurchaseController::service();
        $made = [];
        foreach (array_slice((array) ($body['orders'] ?? []), 0, 50) as $o) {
            $o = (array) $o;
            $items = [];
            foreach ((array) ($o['items'] ?? []) as $it) {
                $it = (array) $it;
                $qty = (int) ($it['qty'] ?? 0);
                $cost = round((float) ($it['unit_cost'] ?? 0), 2);
                if ($qty <= 0) {
                    continue;
                }
                $items[] = [
                    'name' => (string) (($it['receipt_name'] ?? '') ?: ($it['name'] ?? '')), 'product_name' => (string) ($it['name'] ?? ''),
                    'product_id' => (int) ($it['product_id'] ?? 0) ?: null, 'item_type' => 'stock', 'barcode' => (string) ($it['barcode'] ?? ''),
                    'qty' => $qty, 'pack_size' => max(1, (int) ($it['pack_size'] ?? 1)), 'unit_cost' => $cost,
                    'line_total' => round($qty * $cost, 2), 'sale_price' => null,
                ];
            }
            if (!$items) {
                continue;
            }
            try {
                // Items go onto the supplier's draft order when there is one (more may still be added).
                $draftId = $service->draftFor((string) ($o['supplier_name'] ?? ''));
                if ($draftId !== null) {
                    $made[] = $service->appendToDraft($draftId, $items, (int) $user['id'], $ip) + ['supplier_name' => (string) $o['supplier_name']];
                    continue;
                }
                $made[] = $service->createOrder([
                    'supplier_name' => (string) ($o['supplier_name'] ?? ''), 'supplier_tax_id' => (string) ($o['supplier_tax_id'] ?? ''),
                    'receipt_date' => date('Y-m-d'), 'funding' => $o['funding'] ?? 'capital',
                    'note' => 'สร้างจากหน้าสต๊อกสินค้าของร้าน',
                ], $items, [], 'manual', (int) $user['id'], $ip, true) + ['supplier_name' => (string) ($o['supplier_name'] ?? '')];
            } catch (\RuntimeException $e) {
                $msg = $e instanceof \PDOException ? 'ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0041 และ 0042) ก่อน' : $e->getMessage();
                return $this->error($response, ($made ? 'สร้างแล้ว ' . implode(', ', array_column($made, 'doc_no')) . ' แต่' : '')
                    . 'สร้างใบสั่งซื้อของ ' . ($o['supplier_name'] ?? '') . ' ไม่ได้: ' . $msg, 422);
            }
        }
        if (!$made) {
            return $this->error($response, 'ไม่มีรายการที่จะสั่งซื้อ', 422);
        }
        return $this->json($response, ['data' => $made], 201);
    }

    /** The document behind one stock movement (?kind=purchase|receive|sale|pos|return|count&doc_no=...). */
    public function document(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $data = (new InventoryService(Db::connection()))->document((string) ($q['kind'] ?? ''), trim((string) ($q['doc_no'] ?? '')));
        if ($data === null) {
            return $this->error($response, 'ไม่พบเอกสาร', 404);
        }
        return $this->json($response, ['data' => $data]);
    }

    /** Admin / manager: store sales with no cost that a first purchase can now cost (preview). */
    public function zeroCost(Request $request, Response $response): Response
    {
        if (!in_array($request->getAttribute('user')['role'] ?? '', ['admin', 'manager'], true)) {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบหรือผู้จัดการร้าน', 403);
        }
        return $this->json($response, ['data' => (new InventoryService(Db::connection()))->zeroCostSales()]);
    }

    /** Admin / manager: give those sales the first purchase's piece cost. */
    public function fillZeroCost(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบหรือผู้จัดการร้าน', 403);
        }
        $result = (new InventoryService(Db::connection()))->fillZeroCost((int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        return $this->json($response, ['data' => $result]);
    }

    /**
     * Name, piece barcode (scanned at the POS) and the pack barcodes of one store product.
     * Admin only: the piece barcode of a consignment product (name and packs stay).
     */
    public function updateBarcodes(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $db = Db::connection();
        $repo = new ProductRepo($db, new AuditLogger($db));
        $id = (int) $args['id'];
        $consigned = ($repo->find($id)['consignor_id'] ?? null) !== null;
        if ($consigned && ($user['role'] ?? '') !== 'admin') {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้นที่แก้บาร์โค้ดสินค้าฝากขายได้', 403);
        }
        $db->beginTransaction();
        try {
            $repo->updateBarcodes(
                $id, $body['barcode'] ?? null, $consigned ? [] : (array) ($body['pack_barcodes'] ?? []), (int) $user['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? null, !$consigned && isset($body['name']) ? (string) $body['name'] : null, $consigned
            );
            // The piece sale price: admins and managers only, store goods only.
            if (isset($body['sale_price']) && $body['sale_price'] !== '' && !$consigned) {
                if (!in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
                    throw new \RuntimeException('เฉพาะผู้ดูแลระบบและผู้จัดการเท่านั้นที่กำหนดราคาขายได้');
                }
                $repo->setSalePrice($id, (float) $body['sale_price'], (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($e instanceof \RuntimeException) {
                return $this->error($response, $e->getMessage(), 422);
            }
            throw $e;
        }
        $p = $repo->find($id);
        return $this->json($response, ['data' => ['name' => $p['name'], 'barcode' => $p['barcode'], 'sale_price' => (float) $p['sale_price'], 'pack_barcodes' => $repo->packBarcodes($id)]]);
    }

    /**
     * Other names the same product goes by: receipt lines paired with it (or carrying
     * its barcodes) from every supplier, and POS item names mapped to it. Offered in
     * the edit form so a mistyped product name can be replaced with a printed one.
     */
    public function nameOptions(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $id = (int) $args['id'];
        $product = (new ProductRepo($db, new AuditLogger($db)))->find($id);
        if (!$product) {
            return $this->error($response, 'ไม่พบสินค้า', 404);
        }
        $stmt = $db->prepare(
            "SELECT pi.receipt_name AS name, pd.supplier_name AS source, COUNT(*) AS times, MAX(COALESCE(pd.receipt_date, DATE(pd.created_at))) AS last_date
             FROM purchase_items pi JOIN purchase_documents pd ON pd.id = pi.purchase_document_id
             WHERE COALESCE(pd.status, 'active') = 'active' AND (pi.product_id = ?
                OR (pi.barcode <> '' AND (pi.barcode = ? OR pi.barcode IN (SELECT b.barcode FROM product_pack_barcodes b WHERE b.product_id = ?))))
             GROUP BY pi.receipt_name, pd.supplier_name"
        );
        $stmt->execute([$id, (string) $product['barcode'], $id]);
        $rows = $stmt->fetchAll();
        $pos = $db->prepare("SELECT pos_name AS name, 'POS' AS source, GREATEST(times_used, 1) AS times, DATE(last_used_at) AS last_date FROM pos_item_mappings WHERE product_id = ? AND action = 'product'");
        $pos->execute([$id]);
        $rows = array_merge($rows, $pos->fetchAll());

        $key = fn (string $s) => mb_strtolower(preg_replace('/\s+/u', '', $s));
        $current = $key((string) $product['name']);
        $options = [];
        $add = function (string $name, array $row, bool $trimmed) use (&$options, $key, $current) {
            $name = trim(preg_replace('/\s+/u', ' ', $name));
            $k = $key($name);
            if ($name === '' || $k === $current) {
                return;
            }
            $o = $options[$k] ?? ['name' => $name, 'trimmed' => $trimmed, 'times' => 0, 'last_date' => null, 'sources' => []];
            $o['trimmed'] = $o['trimmed'] && $trimmed;
            $o['times'] += (int) $row['times'];
            $o['last_date'] = max($o['last_date'], $row['last_date']);
            $o['sources'][$row['source']] = ($o['sources'][$row['source']] ?? 0) + (int) $row['times'];
            $options[$k] = $o;
        };
        foreach ($rows as $r) {
            $add((string) $r['name'], $r, false);
            // The same name without the pack size / reader notes printed on receipts ("แพค 1x12", "(ชื่ออ่านไม่ชัด)").
            $clean = preg_replace(['/\s*\([^)]*(อ่าน|ไม่ชัด)[^)]*\)/u', '/\s*(แพ็?[คก]|ลัง|pack)?\s*\d+\s*[xX×*]\s*\d+\s*$/u'], '', (string) $r['name']);
            if ($clean !== $r['name']) {
                $add((string) $clean, $r, true);
            }
        }
        usort($options, fn ($a, $b) => [$a['trimmed'] ? 0 : 1, $b['times'], $b['last_date']] <=> [$b['trimmed'] ? 0 : 1, $a['times'], $a['last_date']]);
        return $this->json($response, ['data' => array_map(function ($o) {
            $o['sources'] = array_map(fn ($s, $n) => ['name' => $s, 'times' => $n], array_keys($o['sources']), $o['sources']);
            return $o;
        }, array_slice(array_values($options), 0, 15))]);
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
