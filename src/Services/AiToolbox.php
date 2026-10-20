<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConsignorRepo;
use App\Repositories\ProductRepo;
use App\Repositories\UserRepo;
use App\Repositories\WithdrawalRepo;
use PDO;

/**
 * Tools the AI assistant can call. Every tool is tied to a role permission
 * key, so the assistant can only do what the logged-in user could do in the
 * UI. Tools marked 'confirm' change data and are only executed after the
 * user approves them in the chat window.
 */
class AiToolbox
{
    private const PAGES = [
        'dashboard' => 'dashboard.view', 'sales' => 'sales.create', 'receive' => 'receive.create',
        'ret' => 'return.create', 'count' => 'stockcount.create', 'consignors' => 'consignors.view',
        'passbook' => 'passbook.view', 'withdraw' => 'withdraw.create', 'reports' => 'reports.view',
        'settings' => 'settings.view', 'migrations' => 'migrations.manage', 'audit' => 'audit.view',
        'ai' => 'ai.manage', 'purchase' => 'purchase.create', 'aiusage' => 'ai.usage',
        'posimport' => 'sales.import',
    ];

    /** @var string[] */
    private array $permissions;

    /** Client-side effects (e.g. navigate) collected while running tools. */
    private array $clientActions = [];

    /** Tokens of the receipt photos in the latest user message; saved with a purchase. */
    private array $photos = [];

    public function __construct(private PDO $db, private array $user, private ?string $ip)
    {
        $repo = new UserRepo($db);
        $this->permissions = [];
        foreach ($repo->rolePermissions() as $rp) {
            if ($rp['role'] === $user['role'] && (bool) $rp['allowed']) {
                $this->permissions[] = $rp['permission_key'];
            }
        }
    }

    public function setPhotos(array $tokens): void
    {
        $this->photos = array_values($tokens);
    }

    public function permissions(): array
    {
        return $this->permissions;
    }

    public function takeClientActions(): array
    {
        $out = $this->clientActions;
        $this->clientActions = [];
        return $out;
    }

    private function definitions(): array
    {
        return [
            'navigate_to' => [
                'perm' => null,
                'confirm' => false,
                'description' => 'เปิดหน้าจอในระบบให้ผู้ใช้ (dashboard=แดชบอร์ด, sales=ขายสินค้า POS, purchase=ซื้อสินค้าเข้าร้าน, receive=รับฝากสินค้า, ret=คืนสินค้า, count=ตรวจนับสต๊อก, consignors=ทะเบียนผู้ฝากขาย, passbook=สมุดบัญชีผู้ฝาก, withdraw=เบิก/จ่ายเงิน, reports=รายงาน, settings=ตั้งค่า, audit=ประวัติการแก้ไข)',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'page' => ['type' => 'string', 'enum' => array_keys(self::PAGES)],
                        'consignor_id' => ['type' => 'integer', 'description' => 'ใช้กับหน้า passbook เพื่อเปิดสมุดของผู้ฝากรายนั้น'],
                    ],
                    'required' => ['page'],
                ],
            ],
            'get_dashboard_summary' => [
                'perm' => 'dashboard.view',
                'confirm' => false,
                'description' => 'ดูภาพรวมวันนี้: ยอดขาย ค่าฝากขาย ยอดค้างจ่ายผู้ฝาก สต๊อก และการแจ้งเตือน',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            'search_consignors' => [
                'perm' => 'consignors.view',
                'confirm' => false,
                'description' => 'ค้นหาผู้ฝากขายจากชื่อ รหัส หรือเบอร์โทร (เว้นว่างเพื่อดูรายการล่าสุด) พร้อมยอดคงเหลือ',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                ],
            ],
            'get_consignor_passbook' => [
                'perm' => 'passbook.view',
                'confirm' => false,
                'description' => 'ดูยอดคงเหลือและรายการเคลื่อนไหวล่าสุดในสมุดบัญชีของผู้ฝากขาย',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'consignor_id' => ['type' => 'integer'],
                        'limit' => ['type' => 'integer', 'description' => 'จำนวนรายการล่าสุด (ค่าเริ่มต้น 15)'],
                    ],
                    'required' => ['consignor_id'],
                ],
            ],
            'search_products' => [
                'perm' => 'consignors.view',
                'confirm' => false,
                'description' => 'ค้นหาสินค้าฝากขายจากชื่อหรือบาร์โค้ด ดูราคา จำนวนคงเหลือ และเจ้าของสินค้า',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'in_stock_only' => ['type' => 'boolean'],
                    ],
                ],
            ],
            'list_withdrawals' => [
                'perm' => 'withdraw.create',
                'confirm' => false,
                'description' => 'ดูรายการเบิกเงินล่าสุดและจำนวนที่รออนุมัติ',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['status' => ['type' => 'string', 'enum' => ['all', 'pending', 'paid']]],
                ],
            ],
            'get_report' => [
                'perm' => 'reports.view',
                'confirm' => false,
                'description' => 'ดึงรายงาน: sales=สรุปยอดขายตามผู้ฝาก, fee=รายได้ค่าฝากขาย, receive=บันทึกการรับฝาก, return=บันทึกการคืนสินค้า, detail=ยอดขายรายสินค้าของผู้ฝาก (ต้องระบุ consignor_id)',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['sales', 'fee', 'receive', 'return', 'detail']],
                        'range' => ['type' => 'string', 'enum' => ['today', '7d', 'month', 'term', 'custom']],
                        'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD ใช้เมื่อ range=custom'],
                        'to' => ['type' => 'string', 'description' => 'YYYY-MM-DD ใช้เมื่อ range=custom'],
                        'consignor_id' => ['type' => 'integer'],
                    ],
                    'required' => ['type'],
                ],
            ],
            'create_consignor' => [
                'perm' => 'consignors.create',
                'confirm' => true,
                'description' => 'ลงทะเบียนผู้ฝากขายรายใหม่ (ต้องให้ผู้ใช้ยืนยันก่อนบันทึก)',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'type' => ['type' => 'string', 'description' => 'ประเภท เช่น นักเรียน, ครู/บุคลากร, บุคคลภายนอก'],
                        'phone' => ['type' => 'string'],
                        'pay_channel' => ['type' => 'string', 'enum' => ['cash', 'promptpay', 'bank_transfer']],
                        'pay_account' => ['type' => 'string'],
                        'department' => ['type' => 'string'],
                        'advisor_teacher' => ['type' => 'string'],
                    ],
                    'required' => ['name', 'type', 'phone', 'pay_channel'],
                ],
            ],
            'create_withdrawal' => [
                'perm' => 'withdraw.create',
                'confirm' => true,
                'description' => 'สร้างรายการเบิกเงินให้ผู้ฝากขาย (ถ้าผู้ใช้มีสิทธิ์อนุมัติจะจ่ายทันที ไม่เช่นนั้นจะเป็นคำขอรออนุมัติ) ต้องให้ผู้ใช้ยืนยันก่อน',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'consignor_id' => ['type' => 'integer'],
                        'amount' => ['type' => 'number'],
                        'method' => ['type' => 'string', 'enum' => ['cash', 'promptpay', 'bank_transfer']],
                    ],
                    'required' => ['consignor_id', 'amount'],
                ],
            ],
            'create_purchase' => [
                'perm' => 'purchase.create',
                'confirm' => true,
                'description' => 'บันทึกใบซื้อสินค้าเข้าร้าน (สินค้าของร้านเอง ไม่ใช่ฝากขาย) จากใบเสร็จ ราคาในใบเสร็จคือราคาทุน ระบบจะเติมสต๊อกสินค้าเดิมที่ชื่อ/บาร์โค้ดตรงกัน หรือสร้างสินค้าใหม่ และคิดราคาขายให้ถ้าไม่ระบุ ต้องให้ผู้ใช้ยืนยันก่อน',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'supplier_name' => ['type' => 'string', 'description' => 'ชื่อร้าน/บริษัทที่ซื้อ'],
                        'supplier_tax_id' => ['type' => 'string'],
                        'receipt_no' => ['type' => 'string'],
                        'receipt_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD (ค.ศ.)'],
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'barcode' => ['type' => 'string', 'description' => 'บาร์โค้ดที่พิมพ์ในใบเสร็จ ถ้าไม่มีให้ใช้คอลัมน์ "รหัสสินค้า" ของร้านค้า (เช่น 104651) แทน ห้ามใช้ลำดับ จำนวน หรือรหัส ภ.พ./VAT'],
                                    'product_name' => ['type' => 'string', 'description' => 'ชื่อสำหรับขายแยกชิ้น (ตัดส่วนบอกจำนวนในแพ็คออก)'],
                                    'item_type' => ['type' => 'string', 'enum' => ['stock', 'supply'], 'description' => 'ปกติไม่ต้องใส่ (ถือว่าซื้อมาขาย) ใส่ "supply" เฉพาะรายการที่ผู้ใช้บอกเองว่าซื้อมาใช้ในกิจการ ห้ามเดาจากชื่อสินค้า'],
                                    'qty' => ['type' => 'integer', 'description' => 'จำนวนตามใบเสร็จ (เช่น จำนวนแพ็ค)'],
                                    'pack_size' => ['type' => 'integer', 'description' => 'จำนวนชิ้นย่อยใน 1 แพ็ค/ลัง สังเกตท้ายชื่อ เช่น "x 36" = 36, "X12" = 12, "36 กล่อง" = 36 (ไม่ใช่แพ็ค = 1) และให้ name คงชื่อเต็มตามใบเสร็จ ระบบจะเข้าสต๊อกและขายเป็นชิ้น'],
                                    'unit_cost' => ['type' => 'number', 'description' => 'ราคาทุนต่อหน่วยตามใบเสร็จ (ต่อแพ็ค) หลังหักส่วนลดรายการ'],
                                    'line_total' => ['type' => 'number'],
                                    'sale_price' => ['type' => 'number', 'description' => 'ราคาขายต่อชิ้น (ไม่บังคับ)'],
                                ],
                                'required' => ['name', 'qty', 'unit_cost'],
                            ],
                        ],
                        'discount' => ['type' => 'number', 'description' => 'ส่วนลดท้ายบิล'],
                        'vat' => ['type' => 'number'],
                        'total' => ['type' => 'number', 'description' => 'ยอดสุทธิที่พิมพ์ในใบเสร็จ'],
                        'note' => ['type' => 'string'],
                    ],
                    'required' => ['supplier_name', 'items'],
                ],
            ],
            'approve_withdrawal' => [
                'perm' => 'withdraw.approve',
                'confirm' => true,
                'description' => 'อนุมัติจ่ายคำขอเบิกเงินที่รออนุมัติ ต้องให้ผู้ใช้ยืนยันก่อน',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['withdrawal_id' => ['type' => 'integer']],
                    'required' => ['withdrawal_id'],
                ],
            ],
        ];
    }

    private function can(?string $perm): bool
    {
        return $perm === null || in_array($perm, $this->permissions, true);
    }

    /** Tool schemas (OpenAI format) limited to what this user may do. */
    public function schemas(): array
    {
        $out = [];
        foreach ($this->definitions() as $name => $def) {
            if (!$this->can($def['perm'])) {
                continue;
            }
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $def['description'],
                    'parameters' => $def['parameters'],
                ],
            ];
        }
        return $out;
    }

    /** True only for known tools that change data and that this user may run. */
    public function needsConfirmation(string $name): bool
    {
        $def = $this->definitions()[$name] ?? null;
        return $def !== null && $def['confirm'] && $this->can($def['perm']);
    }

    /** Tools whose draft the user can open in a full form instead of approving as-is. */
    public function editableInForm(string $name): bool
    {
        return $name === 'create_purchase';
    }

    /** Form-ready draft (same shape as ReceiptExtractor output) plus the photos. */
    public function formDraft(string $name, array $args): array
    {
        $draft = ReceiptExtractor::normalize([
            'supplier_name' => $args['supplier_name'] ?? '',
            'supplier_tax_id' => $args['supplier_tax_id'] ?? '',
            'receipt_no' => $args['receipt_no'] ?? '',
            'receipt_date' => $args['receipt_date'] ?? '',
            'items' => array_map(fn ($it) => [
                'name' => $it['name'] ?? '', 'product_name' => $it['product_name'] ?? '', 'barcode' => $it['barcode'] ?? '',
                'item_type' => $it['item_type'] ?? null,
                'qty' => $it['qty'] ?? 1, 'pack_size' => $it['pack_size'] ?? null,
                'unit_price' => $it['unit_cost'] ?? 0, 'line_total' => $it['line_total'] ?? 0,
                'sale_price' => $it['sale_price'] ?? null,
            ], array_filter((array) ($args['items'] ?? []), 'is_array')),
            'discount' => $args['discount'] ?? 0,
            'vat' => $args['vat'] ?? 0,
            'total' => $args['total'] ?? 0,
        ]);
        $draft['note'] = (string) ($args['note'] ?? '');
        $draft['attachments'] = $this->photos;
        return $draft;
    }

    /** Extra rows shown on the confirm card (e.g. purchase lines). */
    public function details(string $name, array $args): ?array
    {
        if ($name !== 'create_purchase') {
            return null;
        }
        $draft = $this->formDraft($name, $args);
        $preview = $this->purchaseService()->preview($draft['items']);
        $rows = [];
        foreach ($draft['items'] as $i => $it) {
            $match = $preview[$i]['match'] ?? null;
            $isSupply = ($preview[$i]['item_type'] ?? 'stock') === 'supply';
            $rows[] = [
                'item_type' => $isSupply ? 'supply' : 'stock',
                'name' => $it['product_name'],
                'receipt_name' => $it['name'],
                'qty' => $it['qty'],
                'pack_size' => $preview[$i]['pack_size'] ?? 1,
                'units' => $preview[$i]['units'] ?? $it['qty'],
                'unit_cost' => $it['unit_cost'],
                'piece_cost' => $preview[$i]['piece_cost'] ?? $it['unit_cost'],
                'line_total' => $it['line_total'],
                'sale_price' => $isSupply ? null : ($it['sale_price'] ?? ($match['sale_price'] ?? $preview[$i]['suggested_price'] ?? 0)),
                'status' => $isSupply ? 'ใช้ในกิจการ' : ($match ? 'เติมสต๊อก' : 'สินค้าใหม่'),
            ];
        }
        return [
            'type' => 'purchase',
            'rows' => $rows,
            'subtotal' => round(array_sum(array_column($draft['items'], 'line_total')), 2),
            'discount' => $draft['discount'],
            'total' => $draft['total'],
            'warnings' => $draft['warnings'],
            'photo_count' => count($this->photos),
        ];
    }

    /** Human-readable summary shown on the confirm card. */
    public function describe(string $name, array $args): string
    {
        $methodLabel = fn ($m) => match ($m) {
            'promptpay' => 'พร้อมเพย์', 'bank_transfer' => 'โอนธนาคาร', default => 'เงินสด',
        };
        switch ($name) {
            case 'create_consignor':
                return sprintf(
                    'ลงทะเบียนผู้ฝากขาย "%s" (%s) โทร %s รับเงินทาง%s',
                    $args['name'] ?? '-', $args['type'] ?? '-', $args['phone'] ?? '-', $methodLabel($args['pay_channel'] ?? 'cash')
                );
            case 'create_withdrawal':
                $c = (new ConsignorRepo($this->db, new AuditLogger($this->db)))->find((int) ($args['consignor_id'] ?? 0));
                $mode = $this->can('withdraw.approve') ? 'จ่ายทันที' : 'ส่งคำขอรออนุมัติ';
                return sprintf(
                    'เบิกเงิน ฿%s ให้ %s ทาง%s (%s)',
                    number_format((float) ($args['amount'] ?? 0), 2),
                    $c ? "{$c['code']} {$c['name']}" : 'ผู้ฝากรหัส ' . ($args['consignor_id'] ?? '?'),
                    $methodLabel($args['method'] ?? 'cash'),
                    $mode
                );
            case 'create_purchase':
                $items = array_filter((array) ($args['items'] ?? []), 'is_array');
                $sum = array_sum(array_map(fn ($it) => (float) ($it['line_total'] ?? 0) ?: (float) ($it['unit_cost'] ?? 0) * (int) ($it['qty'] ?? 1), $items));
                return sprintf(
                    'บันทึกใบซื้อจาก %s%s %d รายการ ยอด ฿%s',
                    $args['supplier_name'] ?? '-',
                    !empty($args['receipt_no']) ? ' เลขที่ ' . $args['receipt_no'] : '',
                    count($items),
                    number_format(!empty($args['total']) ? (float) $args['total'] : $sum, 2)
                );
            case 'approve_withdrawal':
                $stmt = $this->db->prepare('SELECT w.doc_no, w.amount, c.name FROM withdrawals w JOIN consignors c ON c.id = w.consignor_id WHERE w.id = ?');
                $stmt->execute([(int) ($args['withdrawal_id'] ?? 0)]);
                $w = $stmt->fetch();
                return $w
                    ? sprintf('อนุมัติจ่าย %s ฿%s ให้ %s', $w['doc_no'], number_format((float) $w['amount'], 2), $w['name'])
                    : 'อนุมัติคำขอเบิกเงิน #' . ($args['withdrawal_id'] ?? '?');
        }
        return $name;
    }

    /** Runs a tool and returns a JSON-serialisable result for the model. */
    public function execute(string $name, array $args): array
    {
        $defs = $this->definitions();
        if (!isset($defs[$name])) {
            return ['error' => "ไม่รู้จักเครื่องมือ {$name}"];
        }
        if (!$this->can($defs[$name]['perm'])) {
            return ['error' => 'ผู้ใช้ไม่มีสิทธิ์ดำเนินการนี้'];
        }
        try {
            return match ($name) {
                'navigate_to' => $this->navigateTo($args),
                'get_dashboard_summary' => $this->dashboardSummary(),
                'search_consignors' => $this->searchConsignors($args),
                'get_consignor_passbook' => $this->passbook($args),
                'search_products' => $this->searchProducts($args),
                'list_withdrawals' => $this->listWithdrawals($args),
                'get_report' => $this->report($args),
                'create_consignor' => $this->createConsignor($args),
                'create_withdrawal' => $this->createWithdrawal($args),
                'approve_withdrawal' => $this->approveWithdrawal($args),
                'create_purchase' => $this->createPurchase($args),
            };
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function navigateTo(array $args): array
    {
        $page = (string) ($args['page'] ?? '');
        if (!isset(self::PAGES[$page]) || !$this->can(self::PAGES[$page])) {
            return ['error' => 'ไม่มีหน้านี้ หรือผู้ใช้ไม่มีสิทธิ์เข้าหน้านี้'];
        }
        $params = [];
        if ($page === 'passbook' && !empty($args['consignor_id'])) {
            $params['id'] = (int) $args['consignor_id'];
        }
        $this->clientActions[] = ['type' => 'navigate', 'page' => $page, 'params' => $params ?: null];
        return ['ok' => true, 'opened' => $page];
    }

    private function dashboardSummary(): array
    {
        $svc = new DashboardService($this->db);
        return ['date' => date('Y-m-d'), 'kpis' => $svc->kpis(), 'alerts' => $svc->alerts(), 'top_sellers_7_days' => $svc->topSellers7Days()];
    }

    private function searchConsignors(array $args): array
    {
        $repo = new ConsignorRepo($this->db, new AuditLogger($this->db));
        $rows = array_slice($repo->search(trim((string) ($args['query'] ?? ''))), 0, 20);
        return ['results' => array_map(fn ($c) => [
            'id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'type' => $c['type'],
            'phone' => $c['phone'], 'status' => $c['status'], 'pay_channel' => $c['pay_channel'],
            'item_count' => (int) $c['item_count'], 'sold_total' => (float) $c['sold_total'], 'balance' => (float) $c['balance'],
        ], $rows)];
    }

    private function passbook(array $args): array
    {
        $id = (int) ($args['consignor_id'] ?? 0);
        $c = (new ConsignorRepo($this->db, new AuditLogger($this->db)))->find($id);
        if (!$c) {
            return ['error' => 'ไม่พบผู้ฝากขาย'];
        }
        $ledger = new LedgerService($this->db);
        $limit = max(1, min(50, (int) ($args['limit'] ?? 15)));
        $entries = array_slice($ledger->forConsignor($id), -$limit);
        return [
            'consignor' => ['id' => $id, 'code' => $c['code'], 'name' => $c['name']],
            'balance' => $ledger->balanceOf($id),
            'entries' => array_map(fn ($e) => [
                'date' => $e['entry_date'], 'description' => $e['description'], 'ref_no' => $e['ref_no'],
                'in' => (float) $e['amount_in'], 'out' => (float) $e['amount_out'], 'balance' => (float) $e['balance_after'],
            ], $entries),
        ];
    }

    private function searchProducts(array $args): array
    {
        $q = '%' . trim((string) ($args['query'] ?? '')) . '%';
        $sql = "SELECT p.id, p.name, p.barcode,
                       (SELECT GROUP_CONCAT(CONCAT(b.barcode, ' (แพ็ค ', b.pack_size, ')') ORDER BY b.pack_size SEPARATOR ', ')
                          FROM product_pack_barcodes b WHERE b.product_id = p.id) AS pack_barcodes,
                       p.sale_price, p.qty_stock, p.qty_sold, p.expiry_date,
                       c.id AS consignor_id, COALESCE(c.name, 'สินค้าของร้าน') AS consignor_name
                FROM products p LEFT JOIN consignors c ON c.id = p.consignor_id
                WHERE (p.name LIKE ? OR p.barcode LIKE ? OR EXISTS (SELECT 1 FROM product_pack_barcodes b WHERE b.product_id = p.id AND b.barcode LIKE ?))";
        if (!empty($args['in_stock_only'])) {
            $sql .= ' AND p.qty_stock > 0';
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY p.name ASC LIMIT 25');
        $stmt->execute([$q, $q, $q]);
        return ['results' => $stmt->fetchAll()];
    }

    private function listWithdrawals(array $args): array
    {
        $repo = $this->withdrawalRepo();
        $rows = $repo->recent(30);
        $status = $args['status'] ?? 'all';
        if ($status !== 'all') {
            $rows = array_values(array_filter($rows, fn ($w) => $w['status'] === $status));
        }
        return [
            'pending_count' => $repo->pendingCount(),
            'withdrawals' => array_map(fn ($w) => [
                'id' => (int) $w['id'], 'doc_no' => $w['doc_no'], 'consignor_id' => (int) $w['consignor_id'],
                'consignor_name' => $w['consignor_name'], 'amount' => (float) $w['amount'], 'method' => $w['method'],
                'status' => $w['status'], 'requested_at' => $w['requested_at'], 'paid_at' => $w['paid_at'],
            ], $rows),
        ];
    }

    private function report(array $args): array
    {
        $type = (string) ($args['type'] ?? 'sales');
        [$from, $to] = ReportService::resolveRange((string) ($args['range'] ?? 'today'), $args['from'] ?? null, $args['to'] ?? null);
        $svc = new ReportService($this->db);
        $rows = match ($type) {
            'receive' => $svc->receiveLog($from, $to),
            'return' => $svc->returnLog($from, $to),
            'sales' => $svc->salesSummaryByConsignor($from, $to),
            'detail' => !empty($args['consignor_id']) ? $svc->saleDetailForConsignor((int) $args['consignor_id'], $from, $to) : [],
            'fee' => $svc->feeRevenue($from, $to),
            default => null,
        };
        if ($rows === null) {
            return ['error' => 'ไม่รู้จักประเภทรายงานนี้'];
        }
        return ['type' => $type, 'from' => $from, 'to' => $to, 'row_count' => count($rows), 'rows' => array_slice($rows, 0, 60)];
    }

    private function createConsignor(array $args): array
    {
        foreach (['name', 'type', 'phone', 'pay_channel'] as $f) {
            if (empty($args[$f])) {
                return ['error' => "ขาดข้อมูล {$f}"];
            }
        }
        $data = array_intersect_key($args, array_flip(['name', 'type', 'phone', 'pay_channel', 'pay_account', 'department', 'advisor_teacher']));
        $repo = new ConsignorRepo($this->db, new AuditLogger($this->db));
        $id = $repo->create($data, (int) $this->user['id'], $this->ip);
        $c = $repo->find($id);
        return ['ok' => true, 'consignor' => ['id' => $id, 'code' => $c['code'], 'name' => $c['name'], 'status' => $c['status']]];
    }

    private function createWithdrawal(array $args): array
    {
        $consignorId = (int) ($args['consignor_id'] ?? 0);
        $amount = (float) ($args['amount'] ?? 0);
        $method = in_array($args['method'] ?? 'cash', ['cash', 'promptpay', 'bank_transfer'], true) ? $args['method'] ?? 'cash' : 'cash';
        if (!$consignorId || $amount <= 0) {
            return ['error' => 'ต้องระบุผู้ฝากขายและจำนวนเงิน'];
        }
        $repo = $this->withdrawalRepo();
        $id = $this->can('withdraw.approve')
            ? $repo->payNow($consignorId, $amount, $method, (int) $this->user['id'], $this->ip)
            : $repo->requestPending($consignorId, $amount, $method, (int) $this->user['id'], $this->ip);
        $w = $repo->find($id);
        return ['ok' => true, 'withdrawal' => ['id' => $id, 'doc_no' => $w['doc_no'], 'status' => $w['status'], 'amount' => (float) $w['amount']]];
    }

    private function approveWithdrawal(array $args): array
    {
        $id = (int) ($args['withdrawal_id'] ?? 0);
        $repo = $this->withdrawalRepo();
        $repo->approve($id, (int) $this->user['id'], $this->ip);
        $w = $repo->find($id);
        return ['ok' => true, 'withdrawal' => ['id' => $id, 'doc_no' => $w['doc_no'], 'status' => $w['status']]];
    }

    private function createPurchase(array $args): array
    {
        $draft = $this->formDraft('create_purchase', $args);
        $items = array_map(fn ($it) => array_intersect_key($it, array_flip(['name', 'product_name', 'item_type', 'barcode', 'qty', 'pack_size', 'unit_cost', 'line_total', 'sale_price'])), $draft['items']);
        // Photos already filed with an earlier purchase stay with that purchase.
        $photos = array_values(array_filter(
            (new AttachmentService($this->db))->ownedBy($this->photos, (int) $this->user['id']),
            fn ($a) => $a['purchase_document_id'] === null
        ));
        $result = $this->purchaseService()->create(
            array_intersect_key($draft, array_flip(['supplier_name', 'supplier_tax_id', 'receipt_no', 'receipt_date', 'discount', 'vat', 'total', 'note'])),
            $items,
            $photos,
            'ai',
            (int) $this->user['id'],
            $this->ip
        );
        $new = count(array_filter($result['items'], fn ($i) => $i['new']));
        $supplies = count(array_filter($result['items'], fn ($i) => $i['type'] === 'supply'));
        return [
            'ok' => true,
            'doc_no' => $result['doc_no'],
            'total' => $result['total'],
            'new_products' => $new,
            'restocked_products' => count($result['items']) - $new - $supplies,
            'business_supplies' => $supplies,
            'business_supplies_cost' => $result['supply_total'],
            'photos_saved' => count($photos),
        ];
    }

    private function purchaseService(): PurchaseService
    {
        $audit = new AuditLogger($this->db);
        return new PurchaseService(
            $this->db,
            new ProductRepo($this->db, $audit),
            new SettingsService($this->db),
            new DocumentNumberService($this->db),
            new AttachmentService($this->db),
            $audit
        );
    }

    private function withdrawalRepo(): WithdrawalRepo
    {
        return new WithdrawalRepo($this->db, new AuditLogger($this->db), new DocumentNumberService($this->db), new LedgerService($this->db));
    }
}
