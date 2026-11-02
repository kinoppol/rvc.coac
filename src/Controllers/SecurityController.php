<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
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

    private function deny(Request $request, Response $response): ?Response
    {
        $user = $request->getAttribute('user');
        return ($user['role'] ?? '') === 'admin' ? null : $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้น', 403);
    }
}
