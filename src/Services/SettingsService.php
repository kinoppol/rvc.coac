<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Crypto;
use PDO;

class SettingsService
{
    private const DEFAULTS = [
        'fee_mode' => 'fixed',
        'fee_fixed' => '2',
        'fee_percent' => '10',
        'rounding_mode' => 'whole_baht',
        'rounding_beneficiary' => 'store',
        'listing_fee' => '5',
        'free_listings' => '5',
        'waive_qty' => '20',
        'purchase_markup_percent' => '20',
        'ai_cost_general' => '1',
        'ai_cost_markdown' => '3',
        'ai_cost_receipt' => '5',
        'ai_markdown_min_chars' => '1000',
        'ai_markdown_min_table_rows' => '10',
        'login_methods' => 'both',
        'timezone' => '', // '' = app.timezone from config/config.php
    ];

    /** Which RVC-ONE users the sync pulls: '' = staff and students (everything the app is allowed). */
    public const OA_SYNC_TYPE_KEY = 'oa_sync_type';
    public const OA_SYNC_TYPES = ['', 'staff', 'student'];

    /** both = password + RVC-ONE; sso = RVC-ONE only (admins keep a password fallback). */
    public const LOGIN_METHODS = ['both', 'password', 'sso'];

    public function loginMethods(): string
    {
        $v = $this->get('login_methods');
        return in_array($v, self::LOGIN_METHODS, true) ? $v : 'both';
    }

    /** RVC-ONE client_secret, stored encrypted; never returned by all() or the settings API. */
    private const OA_SECRET_KEY = 'oa_client_secret_enc';

    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        $rows = $this->db->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $out = self::DEFAULTS;
        foreach ($rows as $r) {
            if ($r['setting_key'] === self::OA_SECRET_KEY) {
                continue;
            }
            $out[$r['setting_key']] = $r['setting_value'];
        }
        return $out;
    }

    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    /**
     * Updates settings, recording each change into settings_audit_log.
     * @param array<string,string> $values
     */
    public function update(array $values, ?int $userId): void
    {
        $current = $this->all();
        $upsert = $this->db->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $audit = $this->db->prepare(
            'INSERT INTO settings_audit_log (setting_key, old_value, new_value, changed_by, changed_at)
             VALUES (?, ?, ?, ?, NOW())'
        );

        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $old = $current[$key] ?? null;
            $new = (string) $value;
            if ($old === $new) {
                continue;
            }
            $upsert->execute([$key, $new]);
            $audit->execute([$key, $old, $new, $userId]);
        }
    }

    public function oaClientSecret(): string
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([self::OA_SECRET_KEY]);
        $enc = $stmt->fetchColumn();
        return $enc === false ? '' : Crypto::decrypt((string) $enc);
    }

    /** Saves (or, with '', removes) the RVC-ONE client_secret; the audit log records only that it changed. */
    public function setOaClientSecret(string $secret, ?int $userId): void
    {
        $had = $this->oaClientSecret() !== '';
        if ($secret === '') {
            $this->db->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([self::OA_SECRET_KEY]);
        } else {
            $this->db->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            )->execute([self::OA_SECRET_KEY, Crypto::encrypt($secret)]);
        }
        $this->db->prepare(
            'INSERT INTO settings_audit_log (setting_key, old_value, new_value, changed_by, changed_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute(['oa_client_secret', $had ? '(ตั้งค่าไว้)' : '', $secret === '' ? '' : '(ตั้งค่าใหม่)', $userId]);
    }

    /** Always stored (even '') so it overrides oa.sync_type in config/config.php. */
    public function setOaSyncType(string $type, ?int $userId): void
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([self::OA_SYNC_TYPE_KEY]);
        $old = $stmt->fetchColumn();
        $this->db->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([self::OA_SYNC_TYPE_KEY, $type]);
        if ($old !== $type) {
            $this->db->prepare(
                'INSERT INTO settings_audit_log (setting_key, old_value, new_value, changed_by, changed_at)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([self::OA_SYNC_TYPE_KEY, $old === false ? null : $old, $type, $userId]);
        }
    }

    public function auditLog(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT sal.*, u.full_name AS user_name FROM settings_audit_log sal
             LEFT JOIN users u ON u.id = sal.changed_by
             ORDER BY sal.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
