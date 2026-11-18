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
            'create_kinds' => BackupService::CREATE_KINDS,
            'chunk_size' => BackupService::CHUNK,
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

    /** POST /admin/backups/{id}/restore {overwrite, confirm: "กู้คืน", parts?: ["database","files"] (a bundle)} */
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
            $parts = is_array($body['parts'] ?? null) ? array_map('strval', $body['parts']) : null;
            $result = $service->restore($m['id'], !empty($body['overwrite']), $user, $parts);
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

    /** POST /admin/backups/import/chunk?id=<32 hex>&index=<n> — one piece of a backup file being brought back. */
    public function importChunk(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        $q = $request->getQueryParams();
        try {
            $size = (new BackupService(Db::connection()))->importChunk((string) ($q['id'] ?? ''), (int) ($q['index'] ?? -1), (string) $request->getBody());
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['received' => $size]);
    }

    /** POST /admin/backups/import/finish {upload_id, size, name} — checks the file and lists it as a backup. */
    public function importFinish(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) {
            return $denied;
        }
        $b = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        $db = Db::connection();
        @set_time_limit(0);
        try {
            $m = (new BackupService($db))->importFinish((string) ($b['upload_id'] ?? ''), (int) ($b['size'] ?? 0), (string) ($b['name'] ?? ''), $user);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        (new AuditLogger($db))->log('backups', 0, 'create', null, $m, (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        return $this->json($response, ['data' => $m], 201);
    }

    /**
     * GET /admin/backups/{id}/download — the backup file, to keep somewhere off this server.
     * ?part=database|files: only that part of a bundle (database.sql or files.zip).
     */
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
        $name = 'rvc-coac-' . $m['file'];
        $tmp = null;
        $part = (string) ($request->getQueryParams()['part'] ?? '');
        if ($part !== '') {
            @set_time_limit(0);
            try {
                [$path, $tmp] = $service->extractPart($m, $part);
            } catch (\RuntimeException $e) {
                return $this->error($response, $e->getMessage(), 422);
            }
            $name = 'rvc-coac-' . $m['id'] . '-' . BackupService::PARTS[$part];
        }
        $fh = fopen($path, 'rb');
        if (!$fh) {
            if ($tmp) {
                $service->removeDir($tmp);
            }
            return $this->error($response, 'เปิดไฟล์แบ็คอัพไม่ได้', 500);
        }
        $type = match (true) {
            str_ends_with($path, '.sql.gz') => 'application/gzip',
            str_ends_with($path, '.sql') => 'application/sql; charset=utf-8',
            default => 'application/zip',
        };
        $response = $response
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        $body = $response->getBody();
        while (!feof($fh)) {
            $body->write((string) fread($fh, 1 << 20));
        }
        fclose($fh);
        if ($tmp) {
            $service->removeDir($tmp);
        }
        return $response;
    }

    private function deny(Request $request, Response $response): ?Response
    {
        $user = $request->getAttribute('user');
        return ($user['role'] ?? '') === 'admin' ? null : $this->error($response, 'เฉพาะผู้ดูแลระบบเท่านั้น', 403);
    }
}
