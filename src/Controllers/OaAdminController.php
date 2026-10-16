<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\OaUserRepo;
use App\Services\OaApiException;
use App\Services\OaUserSync;
use App\Support\Db;
use App\Support\JsonResponse;
use App\Support\OaConfig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Admin side of RVC-ONE: directory search, sync status and manual sync. */
class OaAdminController
{
    use JsonResponse;

    public function status(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $sync = new OaUserSync($db);
        $redirect = (string) OaConfig::get('redirect_uri');
        return $this->json($response, [
            'directory_count' => (new OaUserRepo($db))->count(),
            'since' => $sync->lastServerTime(),
            'runs' => $sync->recentRuns(10),
            'sync_type' => OaConfig::get('sync_type'),
            'secret_configured' => (string) OaConfig::get('client_secret') !== '',
            'auto_provision' => (bool) OaConfig::get('auto_provision'),
            // The "URL เริ่ม SSO ของแอป" to register at RVC-ONE: same folder as the callback.
            'sso_start_url' => preg_replace('#/callback$#', '/login', $redirect),
        ]);
    }

    public function search(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $rows = (new OaUserRepo(Db::connection()))->search(
            trim((string) ($q['q'] ?? '')),
            (string) ($q['type'] ?? '')
        );
        return $this->json($response, ['data' => $rows]);
    }

    public function sync(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $full = !empty($body['full']);
        @set_time_limit(300);
        try {
            $result = (new OaUserSync(Db::connection()))->run($full, (int) $request->getAttribute('user')['id']);
        } catch (OaApiException $e) {
            $msg = match ($e->apiError) {
                'sync_disabled' => 'ผู้ดูแล RVC-ONE ยังไม่ได้เปิดสิทธิ์ «API ซิงค์ข้อมูลผู้ใช้» ให้แอปนี้',
                'invalid_client' => 'client_id / client_secret ไม่ถูกต้อง หรือแอปถูกปิดใช้งานที่ RVC-ONE',
                'type_not_allowed' => 'แอปนี้ไม่ได้รับอนุญาตให้ดึงผู้ใช้ประเภท ' . OaConfig::get('sync_type'),
                default => 'ซิงค์ไม่สำเร็จ: ' . $e->getMessage(),
            };
            return $this->error($response, $msg, 502);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 409);
        }
        return $this->json($response, ['data' => $result]);
    }
}
