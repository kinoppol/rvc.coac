<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\OaUserRepo;
use App\Services\OaApiException;
use App\Services\OaUserSync;
use App\Services\SettingsService;
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
            'secret_source' => OaConfig::secretSource(),
            'client_id' => OaConfig::get('client_id'),
            'auto_provision' => (bool) OaConfig::get('auto_provision'),
            // The "URL เริ่ม SSO ของแอป" to register at RVC-ONE: same folder as the callback.
            'sso_start_url' => preg_replace('#/callback$#', '/login', $redirect),
        ]);
    }

    /** Saves the client_secret copied from RVC-ONE (blank + clear = remove it). */
    public function saveSecret(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $secret = trim((string) ($body['client_secret'] ?? ''));
        if ($secret === '' && empty($body['clear'])) {
            return $this->error($response, 'กรุณากรอก Client Secret', 422);
        }
        if (strlen($secret) > 255 || preg_match('/\s/', $secret)) {
            return $this->error($response, 'Client Secret ไม่ถูกต้อง', 422);
        }
        try {
            (new SettingsService(Db::connection()))
                ->setOaClientSecret($secret, (int) $request->getAttribute('user')['id']);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 500);
        }
        return $this->json($response, ['ok' => true, 'secret_source' => OaConfig::secretSource()]);
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
            return $this->error($response, $msg, 422);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 409);
        }
        return $this->json($response, ['data' => $result]);
    }
}
