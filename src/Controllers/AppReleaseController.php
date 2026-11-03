<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AppReleaseService;
use App\Services\AuditLogger;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The Android POS app file. Anyone may download it (a phone being set up is not signed in,
 * and the app does nothing until an admin pairs it); only devices.manage uploads a new one.
 */
class AppReleaseController
{
    use JsonResponse;

    /** GET /api/app/pos.json — the current version, for the download page and the app's update check. */
    public function info(Request $request, Response $response): Response
    {
        $r = (new AppReleaseService())->latest();
        return $this->json($response, ['data' => $r ? $this->publicInfo($r) : null]);
    }

    /** GET /api/app/pos.apk */
    public function download(Request $request, Response $response): Response
    {
        $service = new AppReleaseService();
        $r = $service->latest();
        if (!$r) {
            return $this->error($response, 'ยังไม่มีไฟล์ติดตั้งแอป ให้ผู้ดูแลระบบอัปโหลดที่หน้าอุปกรณ์ POS', 404);
        }
        $path = $service->path($r);
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return $this->error($response, 'เปิดไฟล์ติดตั้งแอปไม่ได้', 500);
        }
        $response = $response
            ->withHeader('Content-Type', 'application/vnd.android.package-archive')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', 'attachment; filename="rvc-coac-pos-' . preg_replace('/[^0-9A-Za-z.\-]/', '-', (string) $r['version']) . '.apk"')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('ETag', '"' . $r['sha256'] . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        $body = $response->getBody();
        while (!feof($fh)) {
            $body->write((string) fread($fh, 1 << 20));
        }
        fclose($fh);
        return $response;
    }

    /** POST /api/devices/app/chunk?id=<32 hex>&index=<n> — raw bytes of one 1 MB piece. */
    public function chunk(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        try {
            $size = (new AppReleaseService())->chunk((string) ($q['id'] ?? ''), (int) ($q['index'] ?? -1), (string) $request->getBody());
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        return $this->json($response, ['received' => $size]);
    }

    /** POST /api/devices/app/finish {upload_id, size, version, version_code?, note?} */
    public function finish(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');
        try {
            $r = (new AppReleaseService())->finish(
                (string) ($b['upload_id'] ?? ''), (int) ($b['size'] ?? 0), (string) ($b['version'] ?? ''),
                isset($b['version_code']) && is_numeric($b['version_code']) ? (int) $b['version_code'] : null,
                (string) ($b['note'] ?? ''), (string) $user['full_name']
            );
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
        (new AuditLogger(Db::connection()))->log('app_releases', 0, 'create', null, ['version' => $r['version'], 'file' => $r['file'], 'size' => $r['size'], 'sha256' => $r['sha256']],
            (int) $user['id'], $request->getServerParams()['REMOTE_ADDR'] ?? null);
        return $this->json($response, ['data' => $this->publicInfo($r)], 201);
    }

    private function publicInfo(array $r): array
    {
        return [
            'version' => $r['version'], 'version_code' => $r['version_code'] ?? null, 'size' => (int) $r['size'],
            'sha256' => $r['sha256'], 'note' => $r['note'] ?? '', 'uploaded_at' => $r['uploaded_at'],
            'uploaded_by' => $r['uploaded_by'] ?? '', 'url' => 'api/app/pos.apk',
        ];
    }
}
