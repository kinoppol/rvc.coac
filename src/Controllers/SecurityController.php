<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\QuarantineService;
use App\Services\SecurityScanService;
use App\Support\Db;
use App\Support\JsonResponse;
use App\Support\RepairUnlock;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** ตรวจสอบความปลอดภัย: admins only (see SecurityScanService). */
class SecurityController
{
    use JsonResponse;

    public function scan(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        return $this->json($response, ['data' => (new SecurityScanService(Db::connection()))->run($request->getServerParams())]);
    }

    public function baseline(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        $db = Db::connection();
        $user = $request->getAttribute('user');
        try {
            $saved = (new SecurityScanService($db))->saveBaseline((int) $user['id']);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 500);
        }
        (new AuditLogger($db))->log('security', 0, 'baseline', null, $saved, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        return $this->json($response, ['data' => $saved]);
    }

    /** Opens repair mode of install.php for RepairUnlock::MINUTES, or closes it. */
    public function repair(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        $open = !empty(((array) $request->getParsedBody())['open']);
        try {
            $until = $open ? RepairUnlock::open() : null;
            if (!$open) {
                RepairUnlock::close();
            }
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 500);
        }
        $user = $request->getAttribute('user');
        (new AuditLogger(Db::connection()))->log('security', 0, $open ? 'repair_open' : 'repair_close', null, ['until' => $until ? date('Y-m-d H:i:s', $until) : null], (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        return $this->json($response, ['data' => ['repair_until' => $until ? date('Y-m-d H:i:s', $until) : null]]);
    }

    /** A look inside a file still in place, before deciding to quarantine it. */
    public function inspect(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        try {
            return $this->json($response, ['data' => (new QuarantineService())->inspectPath((string) ($request->getQueryParams()['path'] ?? ''))]);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
    }

    public function quarantineList(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        return $this->json($response, ['data' => (new QuarantineService())->list()]);
    }

    public function quarantine(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        $body = (array) $request->getParsedBody();
        $paths = array_values(array_filter((array) ($body['paths'] ?? []), 'is_string'));
        if (!$paths) {
            return $this->error($response, 'ไม่ได้เลือกไฟล์', 422);
        }
        try {
            $result = (new QuarantineService())->quarantine($paths, (string) ($body['reason'] ?? ''), (int) $request->getAttribute('user')['id']);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 500);
        }
        foreach ($result['moved'] as $m) {
            $this->audit($request, 'quarantine', $m);
        }
        return $this->json($response, ['data' => $result]);
    }

    public function quarantined(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        try {
            return $this->json($response, ['data' => (new QuarantineService())->inspectQuarantined((string) $args['id'])]);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 404);
        }
    }

    public function restore(Request $request, Response $response, array $args): Response
    {
        return $this->act($request, $response, (string) $args['id'], 'restore');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        return $this->act($request, $response, (string) $args['id'], 'delete');
    }

    private function act(Request $request, Response $response, string $id, string $action): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        try {
            $q = new QuarantineService();
            $meta = $action === 'restore' ? $q->restore($id) : $q->delete($id);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        $this->audit($request, 'quarantine_' . $action, $meta);
        return $this->json($response, ['data' => $meta]);
    }

    private function audit(Request $request, string $action, array $meta): void
    {
        (new AuditLogger(Db::connection()))->log('security', 0, $action, null, $meta, (int) $request->getAttribute('user')['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
    }

    private function deny(Request $request, Response $response): ?Response
    {
        $user = $request->getAttribute('user');
        return ($user['role'] ?? '') === 'admin' ? null : $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้น', 403);
    }
}
