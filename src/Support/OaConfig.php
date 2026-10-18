<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * Connection settings for RVC-ONE (SSO login + user-sync API), all in one place.
 *
 * Values come from the "oa" block of config/config.php; anything missing falls
 * back to the values registered for this app at RVC-ONE. The client secret is
 * read from the OA_CLIENT_SECRET environment variable first, then from
 * config/config.php (which is git-ignored), then from the encrypted value an
 * admin saved on the settings page — it never lives in the repo.
 */
class OaConfig
{
    private const DEFAULTS = [
        'authorize_url' => 'http://workspace.rvc.ac.th/oa/index.php',
        'verify_url' => 'http://workspace.rvc.ac.th/oa/api/verify_token.php',
        'users_url' => 'http://workspace.rvc.ac.th/oa/api/users.php',
        'client_id' => 'cl_8edfa15f2a',
        'client_secret' => '',
        'redirect_uri' => 'https://coac.rvc.ac.th/web/auth/callback',
        // Which RVC-ONE users to mirror: staff | student | all | '' (= everything the app is allowed).
        // The choice an admin saves on the settings page wins over this.
        'sync_type' => '',
        // When an RVC-ONE user with no linked account signs in: create one
        // (with default_role) or refuse until an admin grants access.
        'auto_provision' => false,
        'default_role' => 'cashier',
        'timeout' => 10,
    ];

    public static function get(string $key): mixed
    {
        if ($key === 'client_secret') {
            return self::secret()[0];
        }
        if ($key === 'sync_type') {
            return self::syncType();
        }
        return Config::get('oa.' . $key, self::DEFAULTS[$key] ?? null);
    }

    /** Where the client secret comes from: env | config | db | none. */
    public static function secretSource(): string
    {
        return self::secret()[1];
    }

    /** Saved on the settings page (settings.oa_sync_type) first, then config/config.php, then the default. */
    private static function syncType(): string
    {
        try {
            $stmt = Db::connection()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $stmt->execute([SettingsService::OA_SYNC_TYPE_KEY]);
            $saved = $stmt->fetchColumn();
            if ($saved !== false && in_array($saved, SettingsService::OA_SYNC_TYPES, true)) {
                return (string) $saved;
            }
        } catch (\Throwable) {
            // Not installed yet: fall back to the config file.
        }
        return (string) Config::get('oa.sync_type', self::DEFAULTS['sync_type']);
    }

    /** @return array{0:string,1:string} [secret, source] */
    private static function secret(): array
    {
        $env = getenv('OA_CLIENT_SECRET');
        if ($env !== false && $env !== '') {
            return [$env, 'env'];
        }
        $cfg = (string) Config::get('oa.client_secret', '');
        if ($cfg !== '') {
            return [$cfg, 'config'];
        }
        try {
            $db = (new SettingsService(Db::connection()))->oaClientSecret();
        } catch (\Throwable) {
            $db = '';
        }
        return $db !== '' ? [$db, 'db'] : ['', 'none'];
    }
}
