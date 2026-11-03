<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\DeviceSaleSyncService;
use App\Services\PosDeviceService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** อุปกรณ์ POS (Android): pair devices by their code, choose who they sell as, revoke them, and settle sales they could not book. */
class DeviceAdminController
{
    use JsonResponse;

    /** GET /devices — devices, accounts a device may sell as, and the sales that failed to book. */
    public function index(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $devices = $this->devices($db);
        try {
            return $this->json($response, [
                'data' => $devices->all(),
                'sellers' => $devices->sellers(),
                'failed' => $this->sync($db)->list('failed', null),
            ]);
        } catch (\PDOException $e) {
            if (!PosDeviceService::notMigrated($e)) {
                throw $e;
            }
            return $this->error($response, PosDeviceService::NOT_MIGRATED, 503);
        }
    }

    /** POST /devices/pair {code, name, user_id} */
    public function pair(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $db = Db::connection();
        try {
            $device = $this->devices($db)->approve((string) ($body['code'] ?? ''), (string) ($body['name'] ?? ''), (int) ($body['user_id'] ?? 0),
                (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $device], 201);
    }

    /** PUT /devices/{id} {name?, user_id?} */
    public function update(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        try {
            $device = $this->devices($db)->update((int) $args['id'], (array) $request->getParsedBody(), (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['data' => $device]);
    }

    public function revoke(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        try {
            $this->devices($db)->revoke((int) $args['id'], (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    /** GET /devices/{id}/sales?status=synced|failed|resolved — what a device sent. */
    public function sales(Request $request, Response $response, array $args): Response
    {
        $status = (string) ($request->getQueryParams()['status'] ?? 'synced');
        if (!in_array($status, ['synced', 'failed', 'resolved'], true)) {
            $status = 'synced';
        }
        return $this->json($response, ['data' => $this->sync(Db::connection())->list($status, (int) $args['id'], 100)]);
    }

    public function retry(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        try {
            $result = $this->sync($db)->retry((int) $args['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        if ($result['status'] === 'failed') {
            return $this->error($response, 'ยังบันทึกไม่ได้: ' . ($result['error'] ?? ''), 422);
        }
        return $this->json($response, ['data' => $result]);
    }

    public function resolve(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        try {
            $this->sync($db)->resolve((int) $args['id'], (string) (((array) $request->getParsedBody())['note'] ?? ''), (int) $request->getAttribute('user')['id'], $this->ip($request));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    private function devices(\PDO $db): PosDeviceService
    {
        return new PosDeviceService($db, new AuditLogger($db));
    }

    private function sync(\PDO $db): DeviceSaleSyncService
    {
        return new DeviceSaleSyncService($db, new AuditLogger($db));
    }

    private function ip(Request $request): ?string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? null;
    }
}
