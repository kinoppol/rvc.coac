<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Repositories\WithdrawalRepo;
use App\Services\AuditLogger;
use App\Services\ClosingService;
use App\Services\ConsignorPayoutService;
use App\Services\DocumentNumberService;
use App\Services\LedgerService;
use App\Services\SettingsService;
use App\Services\WageService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** สรุปบัญชีรายวัน: the duty roster, each day's figures and the cash handed to the college. */
class ClosingController
{
    use JsonResponse;

    /** The last ?days= days (default 14), the roster and the viewer's own duty weekdays. */
    public function index(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $user = $request->getAttribute('user');
        $service = $this->service($db);
        $days = max(1, min(62, (int) ($request->getQueryParams()['days'] ?? 14)));
        $today = date('Y-m-d');
        $from = (new \DateTimeImmutable($today))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $manage = $this->canManage($db, $user);
        return $this->json($response, ['data' => [
            'today' => $today,
            'can_manage' => $manage,
            'weekdays' => ClosingService::WEEKDAYS,
            'my_duties' => $service->dutiesOf((int) $user['id']),
            'roster' => $service->roster(),
            'candidates' => $manage ? $service->candidates() : [],
            'days' => $service->days($from, $today),
        ]]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $date = $this->dateParam($args['date']);
        if ($date === null) {
            return $this->error($response, 'วันที่ไม่ถูกต้อง', 422);
        }
        $service = $this->service($db);
        $user = $request->getAttribute('user');
        $settings = new SettingsService($db);
        return $this->json($response, ['data' => $service->report($date) + [
            'closing' => $service->find($date),
            'on_duty' => $service->roster()[$service->weekdayOf($date)],
            'can_submit' => $date <= date('Y-m-d') && ($this->canManage($db, $user) || $service->isOnDuty((int) $user['id'], $date)),
            'close_time_reached' => $settings->closeTimeReached($date, new \DateTimeImmutable()),
            'close_time_message' => $settings->closeTimeMessage('สรุปบัญชี'),
            'defaults' => [
                'sent_to' => (string) (new \App\Services\SettingsService($db))->get('closing_sent_to'),
                'float' => $service->defaultFloat(),
            ],
        ]]);
    }

    public function submit(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $date = $this->dateParam($args['date']);
        if ($date === null) {
            return $this->error($response, 'วันที่ไม่ถูกต้อง', 422);
        }
        $user = $request->getAttribute('user');
        // Nobody sums up the day before closing time: sales can still come in until then.
        $settings = new SettingsService($db);
        if (!$settings->closeTimeReached($date, new \DateTimeImmutable())) {
            return $this->error($response, $settings->closeTimeMessage('สรุปบัญชี'), 422);
        }
        try {
            $row = $this->service($db)->submit($date, (array) $request->getParsedBody(), (int) $user['id'], $this->canManage($db, $user), date('Y-m-d'), $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $row]);
    }

    /** Leave a step of the day's report for later, or take that back (body: item, on, note). */
    public function skip(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $date = $this->dateParam($args['date']);
        $user = $request->getAttribute('user');
        $service = $this->service($db);
        if ($date === null) {
            return $this->error($response, 'วันที่ไม่ถูกต้อง', 422);
        }
        if (!$this->canManage($db, $user) && !$service->isOnDuty((int) $user['id'], $date)) {
            return $this->error($response, 'ข้ามรายการได้เฉพาะผู้มีเวรสรุปบัญชีวันนี้ ผู้จัดการ หรือผู้ดูแลระบบ', 403);
        }
        $body = (array) $request->getParsedBody();
        try {
            $service->skip($date, (string) ($body['item'] ?? ''), !empty($body['on']), (int) $user['id'], isset($body['note']) ? (string) $body['note'] : null, $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $service->report($date)['skips']]);
    }

    /** Corrects the day's opening cash (body: amount, reason), or takes the correction back (amount null). */
    public function opening(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        [$date, $err] = $this->actDate($db, $request, $args);
        if ($err) {
            return $this->error($response, $err[0], $err[1]);
        }
        $body = (array) $request->getParsedBody();
        $amount = isset($body['amount']) && $body['amount'] !== '' && $body['amount'] !== null ? (float) $body['amount'] : null;
        $service = $this->service($db);
        try {
            $service->adjustOpening($date, $amount, (string) ($body['reason'] ?? ''), (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $service->report($date)]);
    }

    /** Corrects the cash counted in the drawer at closing (body: amount, reason), or takes the correction back (amount null). */
    public function counted(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        [$date, $err] = $this->actDate($db, $request, $args);
        if ($err) {
            return $this->error($response, $err[0], $err[1]);
        }
        $body = (array) $request->getParsedBody();
        $amount = isset($body['amount']) && $body['amount'] !== '' && $body['amount'] !== null ? (float) $body['amount'] : null;
        $service = $this->service($db);
        try {
            $service->adjustCounted($date, $amount, (string) ($body['reason'] ?? ''), (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $service->report($date)]);
    }

    /** Records what the bank says reached a receiving account that day (body: method, amount; amount null takes it back). */
    public function account(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        [$date, $err] = $this->actDate($db, $request, $args);
        if ($err) {
            return $this->error($response, $err[0], $err[1]);
        }
        $body = (array) $request->getParsedBody();
        $raw = $body['amount'] ?? null;
        if ($raw !== null && $raw !== '' && !is_numeric($raw)) {
            return $this->error($response, 'จำนวนเงินไม่ถูกต้อง', 422);
        }
        $service = $this->service($db);
        try {
            $closing = $service->find($date);
            if ($closing && $closing['verified_at'] !== null && !$this->canManage($db, $request->getAttribute('user'))) {
                throw new \RuntimeException('สรุปบัญชีวันนี้ได้รับการยืนยันแล้ว แก้ไขได้เฉพาะผู้ดูแลระบบหรือผู้จัดการร้าน');
            }
            $service->setAccountReport($date, (string) ($body['method'] ?? ''), $raw === null || $raw === '' ? null : (float) $raw,
                (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $service->report($date)]);
    }

    /** Whether a purchase of the day was paid from the drawer (body: from_drawer). */
    public function purchaseDrawer(Request $request, Response $response, array $args): Response
    {
        return $this->drawerFlag($request, $response, $args, 'setPurchaseDrawer');
    }

    /** Whether a consignor paid in cash that day was paid from the drawer (body: from_drawer). */
    public function payoutDrawer(Request $request, Response $response, array $args): Response
    {
        return $this->drawerFlag($request, $response, $args, 'setPayoutDrawer');
    }

    /** Whether a wage paid in cash that day came out of the drawer (body: from_drawer). */
    public function wageDrawer(Request $request, Response $response, array $args): Response
    {
        return $this->drawerFlag($request, $response, $args, 'setWageDrawer');
    }

    private function drawerFlag(Request $request, Response $response, array $args, string $setter): Response
    {
        $db = Db::connection();
        $date = $this->dateParam($args['date']);
        $user = $request->getAttribute('user');
        $service = $this->service($db);
        if ($date === null) {
            return $this->error($response, 'วันที่ไม่ถูกต้อง', 422);
        }
        if (!$this->canManage($db, $user) && !$service->isOnDuty((int) $user['id'], $date)) {
            return $this->error($response, 'ระบุได้เฉพาะผู้มีเวรสรุปบัญชีวันนี้ ผู้จัดการ หรือผู้ดูแลระบบ', 403);
        }
        try {
            $service->$setter($date, (int) $args['id'], !empty(((array) $request->getParsedBody())['from_drawer']), (int) $user['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $service->report($date)]);
    }

    /**
     * What the report's own pay forms need (items 3 and 4): staff, wage types and methods,
     * consignors with their balances and the withdrawals waiting to be paid.
     */
    public function payOptions(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        [$date, $err] = $this->actDate($db, $request, $args);
        if ($err) {
            return $this->error($response, $err[0], $err[1]);
        }
        $drawer = $db->prepare('SELECT 1 FROM cash_drawers WHERE business_date = ?');
        $drawer->execute([$date]);
        $wages = $this->wageService($db);
        return $this->json($response, ['data' => [
            'staff' => $wages->staff(),
            'types' => WageService::TYPES,
            'methods' => WageService::METHODS,
            'drawer' => $drawer->fetchColumn() !== false,
            // A consignor payout is dated when it is made, so only today's report can pay one.
            'can_payout' => $date === date('Y-m-d'),
            'can_hold' => $this->holds($db)->ready(),
            'default_wage' => (string) (new \App\Services\SettingsService($db))->get('closing_default_wage'),
            'payout_method' => (string) (new \App\Services\SettingsService($db))->get('closing_payout_method'),
            'can_overdraw' => (new UserRepo($db))->hasPermission((string) $request->getAttribute('user')['role'], 'withdraw.approve'),
            'consignors' => $db->query(
                "SELECT c.id, c.code, c.name, COALESCE((SELECT l.balance_after FROM ledger_entries l WHERE l.consignor_id = c.id ORDER BY l.id DESC LIMIT 1), 0) AS balance
                 FROM consignors c WHERE c.status = 'active' ORDER BY c.code"
            )->fetchAll(),
            'pending' => $db->query(
                "SELECT w.id, w.doc_no, w.amount, w.method, w.requested_at, c.code AS consignor_code, c.name AS consignor_name
                 FROM withdrawals w JOIN consignors c ON c.id = w.consignor_id WHERE w.status = 'pending' ORDER BY w.id"
            )->fetchAll(),
        ]]);
    }

    /** Pays a wage dated the report's day; cash may come out of that day's drawer (item 3). */
    public function payWage(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        [$date, $err] = $this->actDate($db, $request, $args);
        if ($err) {
            return $this->error($response, $err[0], $err[1]);
        }
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $fromDrawer = !empty($body['from_drawer']) && ($body['method'] ?? 'cash') === 'cash';
        $service = $this->service($db);
        try {
            if ($fromDrawer) {
                $drawer = $db->prepare('SELECT 1 FROM cash_drawers WHERE business_date = ?');
                $drawer->execute([$date]);
                if (!$drawer->fetchColumn()) {
                    throw new \RuntimeException('วันนี้ไม่ได้ระบุเงินในลิ้นชัก จึงจ่ายจากลิ้นชักไม่ได้');
                }
            }
            $wage = $this->wageService($db)->pay(['pay_date' => $date, 'from_drawer' => false] + $body, (int) $user['id'], null, $this->ip($request));
            if ($fromDrawer) {
                $service->setWageDrawer($date, (int) $wage['id'], true, (int) $user['id'], $this->ip($request));
                $wage = $this->wageService($db)->find((int) $wage['id']);
            }
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $service->report($date), 'wage' => $wage], 201);
    }

    /** Pays a consignor now from today's report (item 4); cash is taken from today's drawer unless said otherwise. */
    public function payPayout(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        [$date, $err] = $this->actDate($db, $request, $args, true);
        if ($err) {
            return $this->error($response, $err[0], $err[1]);
        }
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();
        $consignorId = (int) ($body['consignor_id'] ?? 0);
        $amount = round((float) ($body['amount'] ?? 0), 2);
        $method = (string) ($body['method'] ?? 'cash');
        if (!in_array($method, ['cash', 'promptpay', 'bank_transfer', 'hold'], true)) {
            return $this->error($response, 'ช่องทางจ่ายเงินไม่ถูกต้อง', 422);
        }
        if ($method === 'hold' && !$this->holds($db)->ready()) {
            return $this->error($response, 'ยังพักเงินไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0069) ก่อน', 422);
        }
        if (!$consignorId || $amount <= 0) {
            return $this->error($response, 'กรุณาเลือกผู้ฝากขายและระบุจำนวนเงิน', 422);
        }
        // Handing goods back with the payout is a return: it needs the return permission.
        $returns = (array) ($body['returns'] ?? []);
        if ($returns && !(new \App\Repositories\UserRepo($db))->hasPermission($user['role'], 'return.create')) {
            return $this->error($response, 'ไม่มีสิทธิ์คืนสินค้าให้ผู้ฝากขาย', 403);
        }
        $service = $this->service($db);
        try {
            // For the sales not paid out yet in a period; a different amount needs a reason
            // (over the balance too: the store answers for lost goods, the account goes negative).
            $from = $this->dateParam($body['from'] ?? '') ?? $date;
            $to = $this->dateParam($body['to'] ?? '') ?? $date;
            if ($from > $to || $to > $date) {
                throw new \RuntimeException('ช่วงวันที่ของรายการขายไม่ถูกต้อง');
            }
            $id = $this->payouts($db)->pay([
                'consignor_id' => $consignorId, 'from' => $from, 'to' => $to, 'amount' => $amount, 'method' => $method,
                'product_ids' => (array) ($body['product_ids'] ?? []), 'reason' => (string) ($body['reason'] ?? ''),
                'stock' => (array) ($body['stock'] ?? []), 'returns' => $returns,
            ], (int) $user['id'], $this->ip($request));
            if ($method === 'cash' && isset($body['from_drawer']) && empty($body['from_drawer'])) {
                $service->setPayoutDrawer($date, $id, false, (int) $user['id'], $this->ip($request));
            }
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $service->report($date), 'withdrawal' => $this->withdrawals($db)->find($id)], 201);
    }

    /** A consignor's sales not paid out yet in a period (?consignor_id=&from=&to=), for the payout form (item 4). */
    public function payoutSales(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        [$date, $err] = $this->actDate($db, $request, $args, true);
        if ($err) {
            return $this->error($response, $err[0], $err[1]);
        }
        $q = $request->getQueryParams();
        $from = $this->dateParam($q['from'] ?? '') ?? $date;
        $to = $this->dateParam($q['to'] ?? '') ?? $date;
        if ($from > $to || $to > $date) {
            return $this->error($response, 'ช่วงวันที่ไม่ถูกต้อง', 422);
        }
        return $this->json($response, ['data' => $this->payouts($db)->unpaid((int) ($q['consignor_id'] ?? 0), $from, $to)]);
    }

    /**
     * Every consignor with sales of the day not paid out yet, and their share: what
     * "pay every consignor" would pay (item 3.2).
     */
    public function payoutAllPreview(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        [$date, $err] = $this->actDate($db, $request, $args, true);
        if ($err) {
            return $this->error($response, $err[0], $err[1]);
        }
        try {
            return $this->json($response, ['data' => $this->unpaidByConsignor($db, $date)]);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
    }

    /**
     * Pays every consignor their share of the day's sales not paid out yet (body: items [{consignor_id, method}]).
     * The amounts are worked out again here; each consignor is paid on its own, so one that fails
     * does not stop the others. Cash comes out of today's drawer.
     */
    public function payoutAll(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        [$date, $err] = $this->actDate($db, $request, $args, true);
        if ($err) {
            return $this->error($response, $err[0], $err[1]);
        }
        $user = $request->getAttribute('user');
        $holdReady = $this->holds($db)->ready();
        $methods = [];
        foreach ((array) (((array) $request->getParsedBody())['items'] ?? []) as $it) {
            $m = (string) ($it['method'] ?? '');
            if (!in_array($m, ['cash', 'promptpay', 'bank_transfer', 'hold'], true) || ($m === 'hold' && !$holdReady)) {
                return $this->error($response, $m === 'hold' ? 'ยังพักเงินไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0069) ก่อน' : 'ช่องทางจ่ายเงินไม่ถูกต้อง', 422);
            }
            $methods[(int) ($it['consignor_id'] ?? 0)] = $m;
        }
        if (!$methods) {
            return $this->error($response, 'ไม่มีผู้ฝากขายที่เลือกจ่าย', 422);
        }
        $paid = [];
        $failed = [];
        try {
            $due = $this->unpaidByConsignor($db, $date);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        foreach ($due as $c) {
            $method = $methods[$c['consignor_id']] ?? null;
            if ($method === null) {
                continue;
            }
            try {
                $id = $this->payouts($db)->pay([
                    'consignor_id' => $c['consignor_id'], 'from' => $date, 'to' => $date, 'amount' => $c['amount'], 'method' => $method,
                    'product_ids' => $c['product_ids'], 'reason' => '',
                ], (int) $user['id'], $this->ip($request));
                $paid[] = ['consignor' => "{$c['code']} - {$c['name']}", 'amount' => $c['amount'], 'method' => $method, 'doc_no' => $this->withdrawals($db)->find($id)['doc_no'] ?? ''];
            } catch (\RuntimeException $e) {
                $failed[] = ['consignor' => "{$c['code']} - {$c['name']}", 'amount' => $c['amount'], 'error' => $e->getMessage()];
            }
        }
        return $this->json($response, ['data' => $this->service($db)->report($date), 'paid' => $paid, 'failed' => $failed], 201);
    }

    /** Consignors' shares of one day's sales not paid out yet, with the products behind them. */
    private function unpaidByConsignor(\PDO $db, string $date): array
    {
        if (!$this->payouts($db)->ready()) {
            throw new \RuntimeException('ยังจ่ายตามรายการขายไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0057) ก่อน');
        }
        $stmt = $db->prepare(
            "SELECT si.consignor_id, c.code, c.name, ROUND(SUM(si.cost_amount), 2) AS amount, COUNT(*) AS line_count,
                    GROUP_CONCAT(DISTINCT si.product_id) AS product_ids
             FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN consignors c ON c.id = si.consignor_id
             WHERE si.withdrawal_id IS NULL AND COALESCE(s.business_date, DATE(s.sale_datetime)) = ?
             GROUP BY si.consignor_id, c.code, c.name HAVING amount > 0 ORDER BY c.code"
        );
        $stmt->execute([$date]);
        return array_map(fn ($r) => [
            'consignor_id' => (int) $r['consignor_id'], 'code' => $r['code'], 'name' => $r['name'], 'amount' => (float) $r['amount'],
            'lines' => (int) $r['line_count'], 'product_ids' => array_map('intval', explode(',', (string) $r['product_ids'])),
        ], $stmt->fetchAll());
    }

    private function holds(\PDO $db): \App\Services\ConsignorHoldService
    {
        return new \App\Services\ConsignorHoldService($db, new AuditLogger($db), new DocumentNumberService($db));
    }

    private function payouts(\PDO $db): ConsignorPayoutService
    {
        return new ConsignorPayoutService($db, new AuditLogger($db), new DocumentNumberService($db));
    }

    /** Pays a withdrawal that was waiting for approval, from today's report (item 4). */
    public function approvePayout(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        [$date, $err] = $this->actDate($db, $request, $args, true);
        if ($err) {
            return $this->error($response, $err[0], $err[1]);
        }
        try {
            $this->withdrawals($db)->approve((int) $args['id'], (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $this->service($db)->report($date)]);
    }

    /**
     * The report's date when the user may pay from it (on duty that day, or closing.manage),
     * else [null, [message, status]]. $todayOnly: payments that are dated when made.
     */
    private function actDate(\PDO $db, Request $request, array $args, bool $todayOnly = false): array
    {
        $date = $this->dateParam($args['date']);
        if ($date === null || $date > date('Y-m-d')) {
            return [null, ['วันที่ไม่ถูกต้อง', 422]];
        }
        $user = $request->getAttribute('user');
        if (!$this->canManage($db, $user) && !$this->service($db)->isOnDuty((int) $user['id'], $date)) {
            return [null, ['ทำรายการได้เฉพาะผู้มีเวรสรุปบัญชีวันนี้ ผู้จัดการ หรือผู้ดูแลระบบ', 403]];
        }
        if ($todayOnly && $date !== date('Y-m-d')) {
            return [null, ['จ่ายเงินผู้ฝากขายจากรายงานได้เฉพาะวันนี้ (รายการจะลงวันที่ที่จ่ายจริง)', 422]];
        }
        return [$date, null];
    }

    private function wageService(\PDO $db): WageService
    {
        return new WageService($db, new AuditLogger($db), new DocumentNumberService($db));
    }

    private function withdrawals(\PDO $db): WithdrawalRepo
    {
        return new WithdrawalRepo($db, new AuditLogger($db), new DocumentNumberService($db), new LedgerService($db));
    }

    public function verify(Request $request, Response $response, array $args): Response
    {
        $date = $this->dateParam($args['date']);
        if ($date === null) {
            return $this->error($response, 'วันที่ไม่ถูกต้อง', 422);
        }
        try {
            $row = $this->service(Db::connection())->verify($date, (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $row]);
    }

    public function setRoster(Request $request, Response $response): Response
    {
        $roster = ((array) $request->getParsedBody())['roster'] ?? [];
        $data = $this->service(Db::connection())->setRoster(is_array($roster) ? $roster : [], (int) $request->getAttribute('user')['id'], $this->ip($request));
        return $this->json($response, ['data' => $data]);
    }

    private function service(\PDO $db): ClosingService
    {
        return new ClosingService($db, new AuditLogger($db));
    }

    private function canManage(\PDO $db, array $user): bool
    {
        return (new UserRepo($db))->hasPermission((string) $user['role'], 'closing.manage');
    }

    private function dateParam(mixed $v): ?string
    {
        $v = trim((string) $v);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }

    private function ip(Request $request): ?string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? null;
    }
}
