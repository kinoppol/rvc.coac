<?php

declare(strict_types=1);

use App\Services\MemberService;

return new class {
    public function up(PDO $db): void
    {
        // People synced before 0088 have no national ID, student code or phone in oa_users: the
        // incremental sync only re-sends users changed at RVC-ONE. Fill the national ID from a login
        // that is one (RVC-ONE's default student login), and have the next sync pull everyone again.
        $rows = $db->query("SELECT oa_user_id, username FROM oa_users WHERE national_id IS NULL AND username REGEXP '^[0-9]{13}$'")->fetchAll(PDO::FETCH_ASSOC);
        $set = $db->prepare('UPDATE oa_users SET national_id = ? WHERE oa_user_id = ?');
        foreach ($rows as $r) {
            if (MemberService::isNationalId((string) $r['username'])) {
                $set->execute([$r['username'], $r['oa_user_id']]);
            }
        }
        $db->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('oa_identity_resync', '1')
                   ON DUPLICATE KEY UPDATE setting_value = '1'");
    }

    public function down(PDO $db): void
    {
        $db->exec("DELETE FROM settings WHERE setting_key = 'oa_identity_resync'");
    }
};
