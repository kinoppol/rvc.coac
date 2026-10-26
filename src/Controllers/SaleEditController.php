<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepo;
use App\Services\AuditLogger;
use App\Services\SaleEditService;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** ประวัติการขายของฉัน / ขอแก้ไขการขาย, and their approval by a manager or admin. */
class SaleEditController
{
    use JsonResponse;

    /**
     * The signed-in cashier's own POS sales, by accounting day. Admins and managers may
     * look at another cashier's (?cashier_id=<id>) or everyone's (?cashier_id=all).
     */
    public function mine(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $from = $this->dateParam($params['from'] ?? '') ?? date('Y-m-d');
        $to = $this->dateParam($params['to'] ?? '') ?? $from;
        $user = $request->getAttribute('user');
        $db = Db::connection();
        $service = $this->service($db);
        $settings = new SettingsService($db);
        $oversee = in_array($user['role'] ?? '', ['admin', 'manager'], true);
        $pick = (string) ($params['cashier_id'] ?? '');
        $cashier = $oversee && $pick === 'all' ? null : ($oversee && (int) $pick > 0 ? (int) $pick : (int) $user['id']);
        $rows = $service->mine($cashier, $from, $to);
        return $this->json($response, [
            'from' => $from, 'to' => $to, 'data' => $rows,
            'cashier_id' => $cashier ?? 'all',
            'cashiers' => $oversee ? $service->cashiers($from, $to) : null,
            'total_value' => round(array_sum(array_column($rows, 'total_value')), 2),
            'edit_enabled' => $service->enabled(),
            'edit_minutes' => $service->minutes(),
            'payment_methods' => array_map(fn ($m) => ['key' => $m, 'label' => SettingsService::PAYMENT_METHODS[$m]], $settings->paymentMethods()),
            'payment_mixed' => $settings->posRoleAllows('pos_mixed_roles', $user['role'] ?? null),
            'now' => date('Y-m-d H:i:s'),
        ]);
    }

    /** One sale: its cashier, or anyone who approves corrections. */
    public function show(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $sale = $this->service($db)->detail((int) $args['id']);
        $user = $request->getAttribute('user');
        if (!$sale || ((int) $sale['cashier_id'] !== (int) $user['id'] && !$this->canApprove($db, $user))) {
            return $this->error($response, 'ไม่พบรายการขาย', 404);
        }
        return $this->json($response, ['data' => $sale, 'now' => date('Y-m-d H:i:s')]);
    }

    public function store(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        try {
            $result = $this->service(Db::connection())->request(
                (int) $args['id'],
                is_array($body['items'] ?? null) ? $body['items'] : [],
                is_array($body['payments'] ?? null) ? $body['payments'] : [],
                (string) ($body['reason'] ?? ''),
                $request->getAttribute('user'),
                $this->ip($request)
            );
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $result], 201);
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        try {
            $this->service(Db::connection())->cancel((int) $args['id'], (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    /** Requests for approvers: pending first; status=done lists the decided ones. */
    public function index(Request $request, Response $response): Response
    {
        $status = (string) ($request->getQueryParams()['status'] ?? 'pending');
        $service = $this->service(Db::connection());
        $rows = $status === 'done'
            ? array_values(array_filter($service->requests([], 300), fn ($r) => $r['status'] !== 'pending'))
            : $service->requests(['status' => 'pending']);
        return $this->json($response, ['data' => $rows, 'pending_count' => $service->pendingCount()]);
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        try {
            $result = $this->service(Db::connection())->approve((int) $args['id'], (string) ($body['note'] ?? ''), $request->getAttribute('user'), $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $result]);
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        try {
            $this->service(Db::connection())->reject((int) $args['id'], (string) ($body['note'] ?? ''), (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    private function service(\PDO $db): SaleEditService
    {
        return new SaleEditService($db, new SettingsService($db), new AuditLogger($db));
    }

    private function canApprove(\PDO $db, array $user): bool
    {
        return (new UserRepo($db))->hasPermission((string) $user['role'], 'sales.edit.approve');
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
