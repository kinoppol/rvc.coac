<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\OaConfig;

/**
 * Server-side HTTP client for RVC-ONE. Never logs token_id / token_key /
 * client_secret — error messages carry only the HTTP status and API error code.
 */
class OaClient
{
    /**
     * Verifies an SSO token pair. Returns the "user" (and "org" when shared)
     * on success, or null when RVC-ONE says the token is invalid/expired.
     *
     * @return array{user: array, org: ?array}|null
     * @throws OaApiException when RVC-ONE cannot be reached or answers garbage
     */
    public function verifyToken(string $tokenId, string $tokenKey): ?array
    {
        [$status, $data] = $this->send('POST', (string) OaConfig::get('verify_url'), [
            CURLOPT_POSTFIELDS => http_build_query(['token_id' => $tokenId, 'token_key' => $tokenKey]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);

        if ($status === 200 && ($data['valid'] ?? false) === true && is_array($data['user'] ?? null)) {
            return ['user' => $data['user'], 'org' => is_array($data['org'] ?? null) ? $data['org'] : null];
        }
        if (in_array($status, [400, 401, 403], true) || ($data['valid'] ?? null) === false) {
            return null;
        }
        throw new OaApiException('verify_token ตอบกลับไม่ถูกต้อง (HTTP ' . $status . ')', $status);
    }

    /**
     * One page of the user-sync API. Retries network errors and 5xx a few
     * times with backoff; 4xx are returned to the caller as OaApiException
     * with the API error code (sync_disabled, bad_cursor, ...).
     *
     * @param array{type?: string, since?: string, limit?: int, cursor?: string} $params
     */
    public function fetchUsers(array $params, int $attempts = 3): array
    {
        $url = (string) OaConfig::get('users_url') . '?' . http_build_query(array_filter(
            $params,
            fn ($v) => $v !== null && $v !== ''
        ));
        $auth = OaConfig::get('client_id') . ':' . OaConfig::get('client_secret');

        $last = null;
        for ($i = 1; $i <= $attempts; $i++) {
            if ($i > 1) {
                usleep(500000 * ($i - 1));
            }
            try {
                [$status, $data] = $this->send('GET', $url, [
                    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                    CURLOPT_USERPWD => $auth,
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                ]);
            } catch (OaApiException $e) {
                $last = $e; // network error / timeout: retry
                continue;
            }
            if ($status === 200 && ($data['ok'] ?? false) === true) {
                return $data;
            }
            $code = is_string($data['error'] ?? null) ? $data['error'] : 'http_' . $status;
            $message = is_string($data['message'] ?? null) ? ' — ' . $data['message'] : '';
            $last = new OaApiException('users API: ' . $code . $message . ' (HTTP ' . $status . ')', $status, $code);
            if ($status < 500) {
                throw $last; // client errors will not fix themselves
            }
        }
        throw $last;
    }

    /** @return array{0: int, 1: ?array} */
    private function send(string $method, string $url, array $opts): array
    {
        $timeout = (int) OaConfig::get('timeout');
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new OaApiException('เชื่อมต่อ RVC-ONE ไม่สำเร็จ (curl error ' . $errno . ')', 0, 'network');
        }
        $data = json_decode((string) $raw, true);
        return [$status, is_array($data) ? $data : null];
    }
}
