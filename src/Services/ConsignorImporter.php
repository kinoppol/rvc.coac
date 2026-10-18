<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConsignorRepo;
use App\Repositories\OaUserRepo;
use App\Repositories\UserRepo;
use PDO;

/**
 * Turns people from the RVC-ONE directory (staff and students) into
 * consignors. Each one gets a consignor-only account (or keeps the account
 * they already have) so they can sign in through RVC-ONE and see their own
 * passbook, products and consign requests.
 */
class ConsignorImporter
{
    public function __construct(private PDO $db)
    {
    }

    /** Directory search with each person's local account and consignor record, if any. */
    public function directory(string $q, string $type): array
    {
        $rows = (new OaUserRepo($this->db))->search($q, $type, 100);
        $users = new UserRepo($this->db);
        foreach ($rows as &$r) {
            $c = $r['local_user_id'] ? $users->consignorOf((int) $r['local_user_id']) : null;
            $r['consignor_id'] = $c['id'] ?? null;
            $r['consignor_code'] = $c['code'] ?? null;
        }
        return $rows;
    }

    /**
     * @return array{oa_user_id:int, status:'created'|'exists'|'error', consignor_code?:string, name?:string, error?:string}
     */
    public function import(int $oaUserId, int $meId, ?string $ip): array
    {
        $oaUser = (new OaUserRepo($this->db))->find($oaUserId);
        if (!$oaUser) {
            return ['oa_user_id' => $oaUserId, 'status' => 'error', 'error' => 'ไม่พบในรายชื่อ RVC-ONE'];
        }
        $name = trim($oaUser['first_name'] . ' ' . $oaUser['last_name']);
        if ($oaUser['status'] === 'suspended') {
            return ['oa_user_id' => $oaUserId, 'status' => 'error', 'name' => $name, 'error' => 'บัญชี RVC-ONE ถูกระงับ'];
        }

        $audit = new AuditLogger($this->db);
        $users = new UserRepo($this->db);
        $consignors = new ConsignorRepo($this->db, $audit);

        $this->db->beginTransaction();
        try {
            $user = $users->findByOaUserId($oaUserId);
            if ($user) {
                $existing = $users->consignorOf((int) $user['id']);
                if ($existing) {
                    $this->db->commit();
                    return ['oa_user_id' => $oaUserId, 'status' => 'exists', 'name' => $name, 'consignor_code' => $existing['code']];
                }
            } else {
                $userId = $users->createFromOa($oaUserId, $oaUser, UserRepo::CONSIGNOR, true);
                $user = $users->find($userId);
                $logged = $user;
                unset($logged['password_hash']);
                $audit->log('users', $userId, 'create', null, $logged, $meId, $ip);
            }

            $consignorId = $consignors->create(
                ConsignorRepo::profileFrom((string) $user['full_name'], $oaUser),
                $meId,
                $ip
            );
            $consignors->linkUser($consignorId, (int) $user['id'], $meId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'oa_user_id' => $oaUserId,
            'status' => 'created',
            'name' => $name,
            'consignor_code' => (string) $consignors->find($consignorId)['code'],
        ];
    }
}
