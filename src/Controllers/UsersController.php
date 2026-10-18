<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ConsignorRepo;
use App\Repositories\OaUserRepo;
use App\Repositories\UserRepo;
use App\Services\AuditLogger;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UsersController
{
    use JsonResponse;

    private const ROLES = ['admin', 'manager', 'advisor', 'cashier', 'consignor'];

    public function index(Request $request, Response $response): Response
    {
        $repo = new UserRepo(Db::connection());
        return $this->json($response, ['data' => $repo->all()]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $fullName = trim((string) ($body['full_name'] ?? ''));
        $role = (string) ($body['role'] ?? 'cashier');

        if ($username === '' || strlen($password) < 6 || $fullName === '' || !in_array($role, self::ROLES, true)) {
            return $this->error($response, 'ข้อมูลผู้ใช้ไม่ถูกต้อง (รหัสผ่านอย่างน้อย 6 ตัวอักษร)', 422);
        }

        $repo = new UserRepo(Db::connection());
        if ($repo->findByUsername($username)) {
            return $this->error($response, 'มีชื่อผู้ใช้นี้อยู่แล้ว', 422);
        }

        $id = $repo->create($username, $password, $fullName, $role);
        $user = $repo->find($id);
        unset($user['password_hash']);
        return $this->json($response, ['data' => $user], 201);
    }

    /** Grant access to someone from the RVC-ONE directory (they sign in via SSO). */
    public function storeFromOa(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $oaId = (int) ($body['oa_user_id'] ?? 0);
        $role = (string) ($body['role'] ?? 'cashier');
        if ($oaId <= 0 || !in_array($role, self::ROLES, true)) {
            return $this->error($response, 'ข้อมูลไม่ถูกต้อง', 422);
        }

        $db = Db::connection();
        $oaUser = (new OaUserRepo($db))->find($oaId);
        if (!$oaUser) {
            return $this->error($response, 'ไม่พบผู้ใช้นี้ในรายชื่อ RVC-ONE กรุณาซิงค์รายชื่อก่อน', 404);
        }
        if ($oaUser['status'] === 'suspended') {
            return $this->error($response, 'บัญชี RVC-ONE นี้ถูกระงับ', 422);
        }
        $repo = new UserRepo($db);
        if ($repo->findByOaUserId($oaId)) {
            return $this->error($response, 'ผู้ใช้นี้มีสิทธิ์ใช้งานอยู่แล้ว', 422);
        }

        $id = $repo->createFromOa($oaId, $oaUser, $role, true);
        $user = $repo->find($id);
        unset($user['password_hash']);
        $this->audit($request, $id, 'create', null, $user);
        if ($role === UserRepo::CONSIGNOR) {
            $this->linkConsignor($request, $user, null);
        }
        return $this->json($response, ['data' => $user], 201);
    }

    /** Change role / active flag, or link an existing account to an RVC-ONE ID. */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $body = (array) $request->getParsedBody();
        $me = $request->getAttribute('user');
        $db = Db::connection();
        $repo = new UserRepo($db);

        $user = $repo->find($id);
        if (!$user) {
            return $this->error($response, 'ไม่พบผู้ใช้', 404);
        }

        $fields = [];
        if (array_key_exists('role', $body)) {
            if (!in_array($body['role'], self::ROLES, true)) {
                return $this->error($response, 'บทบาทไม่ถูกต้อง', 422);
            }
            $fields['role'] = $body['role'];
        }
        if (array_key_exists('active', $body)) {
            $fields['active'] = $body['active'] ? 1 : 0;
        }
        if (array_key_exists('oa_user_id', $body)) {
            $oaId = $body['oa_user_id'] === null || $body['oa_user_id'] === '' ? null : (int) $body['oa_user_id'];
            if ($oaId !== null) {
                $oaUser = (new OaUserRepo($db))->find($oaId);
                if ($oaId <= 0 || !$oaUser) {
                    return $this->error($response, 'ไม่พบ RVC-ONE ID นี้ในรายชื่อที่ซิงค์ไว้', 422);
                }
                $other = $repo->findByOaUserId($oaId);
                if ($other && (int) $other['id'] !== $id) {
                    return $this->error($response, 'RVC-ONE ID นี้ผูกกับผู้ใช้ ' . $other['username'] . ' อยู่แล้ว', 422);
                }
                $fields['oa_status'] = $oaUser['status'];
            } else {
                $fields['oa_status'] = null;
            }
            $fields['oa_user_id'] = $oaId;
        }

        $linkedConsignor = $repo->consignorOf($id);
        $consignorChange = array_key_exists('consignor', $body);
        $consignorCode = $consignorChange && $body['consignor'] !== null ? trim((string) $body['consignor']) : null;
        if ($consignorChange && $consignorCode === null) {
            if (($fields['role'] ?? $user['role']) === UserRepo::CONSIGNOR) {
                return $this->error($response, 'ผู้ใช้นี้มีบทบาทผู้ฝากขายอย่างเดียว เปลี่ยนบทบาทก่อนจึงยกเลิกการเป็นผู้ฝากขายได้', 422);
            }
        } elseif ($consignorCode !== null && $consignorCode !== '' && $consignorCode !== 'new') {
            $target = $this->consignors()->findByCode($consignorCode);
            if (!$target) {
                return $this->error($response, 'ไม่พบรหัสผู้ฝากขาย ' . $consignorCode, 422);
            }
            if ($target['user_id'] && (int) $target['user_id'] !== $id) {
                return $this->error($response, 'ผู้ฝากขาย ' . $consignorCode . ' ผูกกับผู้ใช้อื่นอยู่แล้ว', 422);
            }
        }

        if ((int) $me['id'] === $id && ((isset($fields['active']) && !$fields['active'])
            || (isset($fields['role']) && $fields['role'] !== $user['role']))) {
            return $this->error($response, 'ไม่สามารถปิดบัญชีหรือเปลี่ยนบทบาทของตัวเองได้', 422);
        }
        $losesAdmin = $user['role'] === 'admin' && $user['active']
            && ((isset($fields['role']) && $fields['role'] !== 'admin') || (isset($fields['active']) && !$fields['active']));
        if ($losesAdmin && $repo->countAdmins() <= 1) {
            return $this->error($response, 'ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน', 422);
        }

        $repo->update($id, $fields);
        if ($consignorChange) {
            if ($linkedConsignor && ($consignorCode === null || $consignorCode !== $linkedConsignor['code'])) {
                $this->consignors()->linkUser((int) $linkedConsignor['id'], null, (int) $me['id'], $this->ip($request));
                $linkedConsignor = null;
            }
            if ($consignorCode !== null && !$linkedConsignor) {
                $this->linkConsignor($request, $user, in_array($consignorCode, ['', 'new'], true) ? null : $consignorCode);
            }
        } elseif (($fields['role'] ?? null) === UserRepo::CONSIGNOR && !$linkedConsignor) {
            // Consignor-only needs a consignor record; make one from the user's details.
            $this->linkConsignor($request, $user, null);
        }
        $after = $repo->find($id);
        unset($user['password_hash'], $after['password_hash']);
        $this->audit($request, $id, 'update', $user, $after);
        return $this->json($response, ['data' => $after]);
    }

    private function consignors(): ConsignorRepo
    {
        $db = Db::connection();
        return new ConsignorRepo($db, new AuditLogger($db));
    }

    private function ip(Request $request): ?string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? null;
    }

    /** Make the user the owner of consignor $code, or of a new consignor record built from their profile. */
    private function linkConsignor(Request $request, array $user, ?string $code): void
    {
        $repo = $this->consignors();
        $meId = (int) $request->getAttribute('user')['id'];
        if ($code !== null) {
            $consignorId = (int) $repo->findByCode($code)['id'];
        } else {
            $oaUser = $user['oa_user_id'] ? (new OaUserRepo(Db::connection()))->find((int) $user['oa_user_id']) : null;
            $consignorId = $repo->create(
                ConsignorRepo::profileFrom((string) $user['full_name'], $oaUser),
                $meId,
                $this->ip($request)
            );
        }
        $repo->linkUser($consignorId, (int) $user['id'], $meId, $this->ip($request));
    }

    private function audit(Request $request, int $id, string $action, ?array $old, ?array $new): void
    {
        $db = Db::connection();
        (new AuditLogger($db))->log(
            'users', $id, $action, $old, $new,
            (int) $request->getAttribute('user')['id'],
            $request->getServerParams()['REMOTE_ADDR'] ?? null
        );
    }
}
