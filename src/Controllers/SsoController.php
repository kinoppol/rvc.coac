<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\OaUserRepo;
use App\Repositories\UserRepo;
use App\Services\OaApiException;
use App\Services\OaClient;
use App\Services\SettingsService;
use App\Support\Db;
use App\Support\OaConfig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sign-in through RVC-ONE.
 *
 *   GET  /auth/login     → redirect to RVC-ONE with a random state
 *   POST /auth/callback  ← token_id, token_key, state (verified server-side)
 *   GET  /auth/callback  ← ?error=access_denied when the user declines
 *
 * The callback is a cross-site POST from RVC-ONE, so the SameSite=Lax session
 * cookie is not sent with it. The state therefore also travels in its own
 * short-lived cookie (SameSite=None on HTTPS) and is checked against that.
 */
class SsoController
{
    private const STATE_COOKIE = 'oa_sso_state';
    private const STATE_TTL = 600;

    public function start(Request $request, Response $response): Response
    {
        if ($this->ssoDisabled()) {
            return $this->disabledPage($response);
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['oa_sso_state'] = $state;
        $this->setStateCookie($state, time() + self::STATE_TTL);

        $url = OaConfig::get('authorize_url') . '?' . http_build_query([
            'client_id' => OaConfig::get('client_id'),
            'redirect_uri' => OaConfig::get('redirect_uri'),
            'state' => $state,
        ]);
        return $response->withHeader('Location', $url)->withHeader('Cache-Control', 'no-store')->withStatus(302);
    }

    /** A GET on the callback only happens when the user declined (or opened it by hand). */
    public function cancelled(Request $request, Response $response): Response
    {
        $this->clearState();
        if (($request->getQueryParams()['error'] ?? '') === 'access_denied') {
            return $this->page($response, 200, 'ยกเลิกการเข้าสู่ระบบ',
                'คุณไม่ได้อนุญาตให้ระบบฝากขาย ร้านค้าสหการ เข้าถึงบัญชี RVC-ONE จึงยังไม่ได้เข้าสู่ระบบ');
        }
        return $this->page($response, 400, 'ไม่สามารถเข้าสู่ระบบได้',
            'ลิงก์เข้าสู่ระบบไม่ถูกต้อง กรุณาเริ่มเข้าสู่ระบบผ่าน RVC-ONE ใหม่อีกครั้ง');
    }

    public function callback(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $tokenId = is_string($body['token_id'] ?? null) ? trim($body['token_id']) : '';
        $tokenKey = is_string($body['token_key'] ?? null) ? trim($body['token_key']) : '';
        $postedState = is_string($body['state'] ?? null) ? $body['state'] : '';

        $stored = $request->getCookieParams()[self::STATE_COOKIE] ?? $_SESSION['oa_sso_state'] ?? null;
        $stored = is_string($stored) && $stored !== '' ? $stored : null;
        $this->clearState(); // one-time use, whatever happens next

        if ($this->ssoDisabled()) {
            return $this->disabledPage($response);
        }

        // CSRF: a state we issued must come back unchanged. No stored state and
        // an empty posted state = login started from the RVC-ONE Workspace tile.
        if ($stored !== null ? !hash_equals($stored, $postedState) : $postedState !== '') {
            return $this->page($response, 400, 'ไม่สามารถเข้าสู่ระบบได้',
                'คำขอเข้าสู่ระบบหมดอายุหรือไม่ถูกต้อง กรุณากดเข้าสู่ระบบผ่าน RVC-ONE ใหม่อีกครั้ง');
        }

        if (!preg_match('/^\d+_\d+$/', $tokenId) || !preg_match('/^[0-9a-f]{64}$/i', $tokenKey)) {
            return $this->page($response, 401, 'ไม่สามารถเข้าสู่ระบบได้',
                'ข้อมูลยืนยันตัวตนจาก RVC-ONE ไม่ครบหรือไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง');
        }

        try {
            $verified = (new OaClient())->verifyToken($tokenId, $tokenKey);
        } catch (OaApiException $e) {
            error_log('[oa-sso] verify_token failed: ' . $e->getMessage());
            if ($e->apiError === 'invalid_client') {
                return $this->page($response, 401, 'ไม่สามารถเข้าสู่ระบบได้',
                    'RVC-ONE ไม่ยอมรับการเชื่อมต่อของแอปนี้ (client_id / client_secret ไม่ถูกต้อง) '
                    . 'กรุณาแจ้งผู้ดูแลระบบให้ตรวจ Client Secret ในหน้าตั้งค่า → RVC-ONE');
            }
            return $this->page($response, 401, 'ไม่สามารถเข้าสู่ระบบได้',
                'ติดต่อระบบ RVC-ONE ไม่ได้ในขณะนี้ กรุณาลองใหม่อีกครั้งในอีกสักครู่');
        }
        if ($verified === null) {
            return $this->page($response, 401, 'ไม่สามารถเข้าสู่ระบบได้',
                'การยืนยันตัวตนหมดอายุหรือไม่ถูกต้อง (มีอายุ 5 นาที) กรุณาเข้าสู่ระบบผ่าน RVC-ONE ใหม่อีกครั้ง');
        }

        $oaUser = $verified['user'];
        $oaId = (int) ($oaUser['id'] ?? 0);
        if ($oaId <= 0) {
            return $this->page($response, 401, 'ไม่สามารถเข้าสู่ระบบได้', 'RVC-ONE ไม่ได้ส่งรหัสผู้ใช้กลับมา');
        }

        $db = Db::connection();
        // Refresh name/department. verify_token carries no status, so a
        // suspension recorded by the user sync stays in force.
        unset($oaUser['status']);
        (new OaUserRepo($db))->upsert($oaUser);

        $users = new UserRepo($db);
        $user = $users->findByOaUserId($oaId);
        if ($user === null && OaConfig::get('auto_provision')) {
            $user = $users->find($users->createFromOa($oaId, $oaUser, (string) OaConfig::get('default_role'), true));
        }
        if ($user === null) {
            return $this->page($response, 403, 'ยังไม่มีสิทธิ์ใช้งาน',
                'บัญชี RVC-ONE ของคุณยังไม่ได้รับสิทธิ์ใช้งานระบบฝากขาย ร้านค้าสหการ '
                . 'กรุณาแจ้งผู้ดูแลระบบให้เพิ่มสิทธิ์ (RVC-ONE ID: ' . $oaId . ')');
        }
        if (!UserRepo::canSignIn($user)) {
            return $this->page($response, 403, 'บัญชีถูกปิดการใช้งาน',
                'บัญชีของคุณในระบบฝากขาย ร้านค้าสหการ ถูกปิดการใช้งาน กรุณาติดต่อผู้ดูแลระบบ');
        }
        if (!$users->rolesOf($user)) {
            return $this->page($response, 403, 'ยังไม่มีสิทธิ์ใช้งาน',
                'บัญชีของคุณยังไม่ได้ผูกกับผู้ฝากขาย กรุณาติดต่อผู้ดูแลระบบ');
        }

        session_regenerate_id(true);
        $_SESSION = [
            'user_id' => (int) $user['id'],
            'csrf_token' => bin2hex(random_bytes(32)),
            'auth_via' => 'rvc-one',
        ];
        $users->touchLogin((int) $user['id']);

        return $response->withHeader('Location', $this->appUrl())->withHeader('Cache-Control', 'no-store')->withStatus(303);
    }

    private function ssoDisabled(): bool
    {
        return (new SettingsService(Db::connection()))->loginMethods() === 'password';
    }

    private function disabledPage(Response $response): Response
    {
        return $this->page($response, 403, 'ปิดการเข้าสู่ระบบผ่าน RVC-ONE',
            'ผู้ดูแลระบบตั้งค่าให้เข้าสู่ระบบด้วยชื่อผู้ใช้และรหัสผ่านเท่านั้น', false);
    }

    /** The SPA lives next to index.php (…/public/), whichever URL reached us. */
    private function appUrl(): string
    {
        return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/') . '/';
    }

    private function setStateCookie(string $value, int $expires): void
    {
        $secure = parse_url((string) OaConfig::get('redirect_uri'), PHP_URL_SCHEME) === 'https';
        setcookie(self::STATE_COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            // RVC-ONE posts back cross-site; a Lax cookie would not come along.
            'samesite' => $secure ? 'None' : 'Lax',
        ]);
    }

    private function clearState(): void
    {
        unset($_SESSION['oa_sso_state']);
        $this->setStateCookie('', time() - 3600);
    }

    private function page(Response $response, int $status, string $title, string $message, bool $retry = true): Response
    {
        $h = fn (string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $app = $h($this->appUrl());
        $retryLink = $retry
            ? "<a class=\"btn\" style=\"text-align:center;text-decoration:none\" href=\"{$app}auth/login\">ลงชื่อเข้าใช้ผ่านระบบ RVC-ONE อีกครั้ง</a>"
            : '';
        $title = $h($title);
        $message = $h($message);
        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="th">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title} - ระบบฝากขาย ร้านค้าสหการ</title>
            <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="{$app}assets/css/app.css">
            </head>
            <body>
            <div class="auth-wrap"><div class="auth-card">
              <h1>{$title}</h1>
              <p class="sub">ระบบฝากขาย ร้านค้าสหการ</p>
              <p style="margin:0 0 20px;line-height:1.6">{$message}</p>
              <div style="display:flex;flex-direction:column;gap:10px">
                {$retryLink}
                <a class="btn secondary" style="text-align:center;text-decoration:none" href="{$app}">กลับหน้าเข้าสู่ระบบ</a>
              </div>
            </div></div>
            </body>
            </html>
            HTML;
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus($status);
    }
}
