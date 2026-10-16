<?php

declare(strict_types=1);

namespace App\Services;

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
    ];

    /** both = password + RVC-ONE; sso = RVC-ONE only (admins keep a password fallback). */
    public const LOGIN_METHODS = ['both', 'password', 'sso'];

    public function loginMethods(): string
    {
        $v = $this->get('login_methods');
        return in_array($v, self::LOGIN_METHODS, true) ? $v : 'both';
    }

    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        $rows = $this->db->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $out = self::DEFAULTS;
        foreach ($rows as $r) {
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
