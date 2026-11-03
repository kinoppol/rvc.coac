<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\BackupService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** แบ็คอัพและกู้คืนข้อมูล: admins only (see BackupService). */
class BackupController
{
    use JsonResponse;

    /** GET /admin/backups — the backups on disk and the periods to choose from (with their dates). */
    public function index(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        $ranges = [];
        foreach (BackupService::RANGES as $key => $label) {
            $p = BackupService::period($key);
            $ranges[] = ['key' => $key, 'label' => $label, 'from' => $p ? $p[0]->format('Y-m-d') : null, 'to' => $p ? $p[1]->format('Y-m-d') : null];
        }
        $free = @disk_free_space(dirname(__DIR__, 2));
        return $this->json($response, ['data' => [
            'backups' => (new BackupService(Db::connection()))->list(),
            'kinds' => BackupService::KINDS,
            'ranges' => $ranges,
            'disk_free' => $free === false ? null : (int) $free,
        ]]);
    }

    /** POST /admin/backups {kind, range, note} */
    public function create(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        $body = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $db = Db::connection();
        try {
            $m = (new BackupService($db))->create((string) ($body['kind'] ?? ''), (string) ($body['range'] ?? ''), $user, (string) ($body['note'] ?? ''));
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        (new AuditLogger($db))->log('backups', 0, 'create', null, $m, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        return $this->json($response, ['data' => $m]);
    }

    /** POST /admin/backups/{id}/restore {overwrite, confirm: "กู้คืน"} */
    public function restore(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        $body = (array) $request->getParsedBody();
        if (trim((string) ($body['confirm'] ?? '')) !== 'กู้คืน') {
            return $this->error($response, 'พิมพ์คำว่า "กู้คืน" เพื่อยืนยัน', 422);
        }
        $user = $request->getAttribute('user');
        $service = new BackupService(Db::connection());
        try {
            $m = $service->find((string) $args['id']);
            $result = $service->restore($m['id'], !empty($body['overwrite']), $user);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        // After a database restore the audit table is the restored one: the entry goes in there.
        (new AuditLogger(Db::connection()))->log('backups', 0, 'restore', $m, $result, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        return $this->json($response, ['data' => $result]);
    }

    /** POST /admin/backups/{id}/delete — the backup file is removed for good. */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        $user = $request->getAttribute('user');
        $db = Db::connection();
        try {
            $m = (new BackupService($db))->delete((string) $args['id']);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        (new AuditLogger($db))->log('backups', 0, 'delete', $m, null, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        return $this->json($response, ['ok' => true]);
    }

    /** GET /admin/backups/{id}/download — the backup file, to keep somewhere off this server. */
    public function download(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        $service = new BackupService(Db::connection());
        try {
            $m = $service->find((string) $args['id']);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 404);
        }
        $path = $service->path($m);
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return $this->error($response, 'เปิดไฟล์แบ็คอัพไม่ได้', 500);
        }
        $response = $response
            ->withHeader('Content-Type', $m['kind'] === 'database' ? 'application/gzip' : 'application/zip')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', 'attachment; filename="rvc-coac-' . $m['file'] . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        $body = $response->getBody();
        while (!feof($fh)) {
            $body->write((string) fread($fh, 1 << 20));
        }
        fclose($fh);
        return $response;
    }

    private function deny(Request $request, Response $response): ?Response
    {
        $user = $request->getAttribute('user');
        return ($user['role'] ?? '') === 'admin' ? null : $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้น', 403);
    }
}
