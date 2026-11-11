<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\SupplierService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** รายชื่อร้าน/ผู้ขาย: the name list, merging names of one supplier, and its aliases. */
class SupplierController
{
    use JsonResponse;

    private function service(): SupplierService
    {
        $db = Db::connection();
        return new SupplierService($db, new AuditLogger($db));
    }

    /** Admin / manager change the list; others who buy only look it up. */
    private function canEdit(Request $request): bool
    {
        return in_array($request->getAttribute('user')['role'] ?? '', ['admin', 'manager'], true);
    }

    public function index(Request $request, Response $response): Response
    {
        $service = $this->service();
        return $this->json($response, [
            'data' => $service->all(),
            'ready' => $service->ready(),
            'can_edit' => $this->canEdit($request),
        ]);
    }

    /** POST {main, names: [...]} */
    public function merge(Request $request, Response $response): Response
    {
        return $this->edit($request, $response, function (SupplierService $s, array $b, int $uid, ?string $ip) {
            return $s->merge((string) ($b['main'] ?? ''), (array) ($b['names'] ?? []), $uid, $ip);
        });
    }

    /** POST {name, alias} */
    public function addAlias(Request $request, Response $response): Response
    {
        return $this->edit($request, $response, function (SupplierService $s, array $b, int $uid, ?string $ip) {
            $s->addAlias((string) ($b['name'] ?? ''), (string) ($b['alias'] ?? ''), $uid, $ip);
            return ['ok' => true];
        });
    }

    public function removeAlias(Request $request, Response $response, array $args): Response
    {
        return $this->edit($request, $response, function (SupplierService $s, array $b, int $uid, ?string $ip) use ($args) {
            $s->removeAlias((int) $args['id'], $uid, $ip);
            return ['ok' => true];
        });
    }

    /** POST {name, new_name, tax_id} */
    public function update(Request $request, Response $response): Response
    {
        return $this->edit($request, $response, function (SupplierService $s, array $b, int $uid, ?string $ip) {
            return ['name' => $s->update((string) ($b['name'] ?? ''), (string) ($b['new_name'] ?? ''), (string) ($b['tax_id'] ?? ''), $uid, $ip)];
        });
    }

    private function edit(Request $request, Response $response, callable $fn): Response
    {
        if (!$this->canEdit($request)) {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบและผู้จัดการร้านเท่านั้น', 403);
        }
        try {
            $data = $fn($this->service(), (array) $request->getParsedBody(), (int) $request->getAttribute('user')['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e instanceof \PDOException ? 'บันทึกไม่ได้: ' . $e->getMessage() : $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $data]);
    }
}
