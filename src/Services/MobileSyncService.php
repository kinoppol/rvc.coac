<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConsignRequestRepo;
use App\Repositories\UserRepo;
use App\Repositories\WithdrawalRepo;
use PDO;

/**
 * What the mobile app keeps on the device (GET /api/mobile/v1/sync): one section per thing the
 * user's active role may see, with the same permission keys as the web's menus. The app stores
 * the sections and works from them offline until the next sync, so each one is compact and
 * typed (numbers are numbers). A section the role may not see is left out, not sent empty.
 */
class MobileSyncService
{
    public const DAYS = 90;

    public function __construct(private PDO $db)
    {
    }

    /** @param string[] $permissions */
    public function bundle(array $user, array $permissions): array
    {
        $can = fn (string $p) => in_array($p, $permissions, true);
        $today = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-' . (self::DAYS - 1) . ' days'));
        $sections = [];
        if ($user['role'] === UserRepo::CONSIGNOR && $can('consignor.portal')) {
            $consignor = (new UserRepo($this->db))->consignorOf((int) $user['id']);
            if ($consignor) {
                $sections['consignor'] = $this->consignor((int) $consignor['id']);
            }
        }
        if ($can('dashboard.view')) {
            $sections['sales_daily'] = $this->salesDaily($from, $today);
        }
        if ($can('consignors.view')) {
            $sections['consignors'] = $this->consignors();
        }
        if ($can('passbook.view')) {
            $sections['consignor_entries'] = $this->entriesSince($from);
        }
        // Those who count stock need the products too (the web's ตรวจนับ page looks them up the same way).
        if ($can('reports.view') || $can('stockcount.create')) {
            $sections['inventory'] = $this->inventory();
        }
        if ($can('withdraw.create')) {
            $sections['withdrawals'] = $this->withdrawals($from, $today);
        }
        return ['generated_at' => date('Y-m-d H:i:s'), 'today' => $today, 'from' => $from, 'days' => self::DAYS, 'sections' => $sections];
    }

    /** The consignor's own passbook, goods, what sold and their deposit requests. */
    private function consignor(int $id): array
    {
        $stmt = $this->db->prepare('SELECT id, code, name, type, department, phone, pay_channel, pay_account, status FROM consignors WHERE id = ?');
        $stmt->execute([$id]);
        $info = $stmt->fetch() ?: [];
        $ledger = new LedgerService($this->db);
        try {
            $held = (new ConsignorHoldService($this->db, new AuditLogger($this->db), new DocumentNumberService($this->db)))->heldOf($id);
        } catch (\PDOException $e) {
            $held = 0.0; // before migration 0069 nothing can be held
        }
        $stmt = $this->db->prepare('SELECT * FROM products WHERE consignor_id = ? ORDER BY name ASC');
        $stmt->execute([$id]);
        $products = array_map(fn ($p) => $this->product($p), $stmt->fetchAll());
        try {
            $requests = array_map(fn ($r) => [
                'id' => (int) $r['id'], 'request_no' => $r['request_no'], 'status' => $r['status'],
                'deposit_date' => $r['deposit_date'] ?? null, 'note' => $r['note'], 'review_note' => $r['review_note'],
                'receive_doc_no' => $r['receive_doc_no'], 'created_at' => $r['created_at'],
                'items' => array_map(fn ($i) => [
                    'name' => $i['name'], 'qty' => (int) $i['qty'], 'cost_price' => (float) $i['cost_price'], 'barcode' => $i['barcode'],
                ], $r['items']),
            ], (new ConsignRequestRepo($this->db, new AuditLogger($this->db)))->forConsignor($id));
        } catch (\PDOException $e) {
            $requests = [];
        }
        return [
            'info' => $info ? ['id' => (int) $info['id']] + $info : null,
            'balance' => $ledger->balanceOf($id),
            'held' => (float) $held,
            'entries' => array_map(fn ($e) => $this->entry($e), $ledger->forConsignor($id)),
            'products' => $products,
            'sales' => array_map(fn ($s) => [
                'date' => $s['sale_date'], 'product_id' => (int) $s['product_id'], 'name' => $s['name'],
                'qty' => (int) $s['qty'], 'value' => (float) $s['value'], 'fee' => (float) $s['fee'],
            ], $ledger->salesByProduct($id)),
            'requests' => $requests,
        ];
    }

    /** Sales per accounting day, every day of the span (days with no sales too). */
    private function salesDaily(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.business_date AS d, COUNT(*) AS bills, SUM(s.total_qty) AS qty, SUM(s.total_value) AS value
             FROM sales s WHERE s.business_date BETWEEN ? AND ? GROUP BY s.business_date'
        );
        $stmt->execute([$from, $to]);
        $byDay = [];
        foreach ($stmt->fetchAll() as $r) {
            $byDay[$r['d']] = $r;
        }
        $fees = $this->db->prepare(
            'SELECT s.business_date AS d, SUM(si.fee_amount) AS fee FROM sale_items si JOIN sales s ON s.id = si.sale_id
             WHERE s.business_date BETWEEN ? AND ? GROUP BY s.business_date'
        );
        $fees->execute([$from, $to]);
        $feeByDay = array_column($fees->fetchAll(), 'fee', 'd');
        $out = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $r = $byDay[$d] ?? null;
            $out[] = [
                'date' => $d, 'bills' => (int) ($r['bills'] ?? 0), 'qty' => (int) ($r['qty'] ?? 0),
                'value' => round((float) ($r['value'] ?? 0), 2), 'fee' => round((float) ($feeByDay[$d] ?? 0), 2),
            ];
        }
        return $out;
    }

    private function consignors(): array
    {
        $rows = $this->db->query(
            'SELECT c.id, c.code, c.name, c.type, c.department, c.phone, c.status,
                    (SELECT le.balance_after FROM ledger_entries le WHERE le.consignor_id = c.id ORDER BY le.id DESC LIMIT 1) AS balance,
                    (SELECT COALESCE(SUM(p.qty_stock), 0) FROM products p WHERE p.consignor_id = c.id AND p.qty_stock > 0) AS stock_qty,
                    (SELECT COALESCE(SUM(p.qty_stock * p.sale_price), 0) FROM products p WHERE p.consignor_id = c.id AND p.qty_stock > 0) AS stock_value
             FROM consignors c ORDER BY c.code'
        )->fetchAll();
        return array_map(fn ($c) => [
            'id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'type' => $c['type'], 'department' => $c['department'],
            'phone' => $c['phone'], 'status' => $c['status'], 'balance' => (float) ($c['balance'] ?? 0),
            'stock_qty' => (int) $c['stock_qty'], 'stock_value' => round((float) $c['stock_value'], 2),
        ], $rows);
    }

    /** Every consignor's passbook lines of the span; the balance before it is each consignor's opening line. */
    private function entriesSince(string $from): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ledger_entries WHERE entry_date >= ? ORDER BY id ASC');
        $stmt->execute([$from]);
        return array_map(fn ($e) => ['consignor_id' => (int) $e['consignor_id']] + $this->entry($e), $stmt->fetchAll());
    }

    private function inventory(): array
    {
        $rows = $this->db->query(
            'SELECT p.*, c.code AS consignor_code, c.name AS consignor_name FROM products p
             LEFT JOIN consignors c ON c.id = p.consignor_id
             WHERE p.qty_stock <> 0 ORDER BY p.name'
        )->fetchAll();
        return array_map(fn ($p) => $this->product($p) + [
            'consignor_id' => $p['consignor_id'] !== null ? (int) $p['consignor_id'] : null,
            'owner' => $p['consignor_name'] ?? 'สินค้าของร้าน', 'owner_code' => $p['consignor_code'] ?? null,
        ], $rows);
    }

    private function withdrawals(string $from, string $to): array
    {
        $repo = new WithdrawalRepo($this->db, new AuditLogger($this->db), new DocumentNumberService($this->db), new LedgerService($this->db));
        return array_map(fn ($w) => [
            'id' => (int) $w['id'], 'doc_no' => $w['doc_no'], 'consignor_id' => (int) $w['consignor_id'], 'consignor_name' => $w['consignor_name'],
            'amount' => (float) $w['amount'], 'method' => $w['method'], 'status' => $w['status'],
            'requested_at' => $w['requested_at'], 'paid_at' => $w['paid_at'], 'cancel_reason' => $w['cancel_reason'] ?? null,
        ], $repo->recent(2000, $from, $to));
    }

    private function entry(array $e): array
    {
        return [
            'id' => (int) $e['id'], 'date' => $e['entry_date'], 'type' => $e['type'], 'description' => $e['description'],
            'ref_no' => $e['ref_no'], 'in' => (float) $e['amount_in'], 'out' => (float) $e['amount_out'],
            'balance' => (float) $e['balance_after'], 'created_at' => $e['created_at'],
        ];
    }

    private function product(array $p): array
    {
        return [
            'id' => (int) $p['id'], 'name' => $p['name'], 'barcode' => $p['barcode'], 'sale_price' => (float) $p['sale_price'],
            'qty_received' => (int) $p['qty_received'], 'qty_sold' => (int) $p['qty_sold'], 'qty_returned' => (int) $p['qty_returned'],
            'qty_stock' => (int) $p['qty_stock'], 'expiry_date' => $p['expiry_date'] ?? null,
            'discontinued' => !empty($p['discontinued_at']),
        ];
    }
}
