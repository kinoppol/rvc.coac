<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogger;
use App\Services\MemberReportService;
use App\Services\MemberService;
use App\Services\PointRedeemService;
use App\Services\SurveyService;
use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Store members and their points (migration 0088): the POS names the buyer and spends points
 * (sales.create, web and Android); the members menu (members.view) shows the register, histories
 * and statistics; admin / manager set the rates, the goods points come off, and the surveys.
 */
class MemberController
{
    use JsonResponse;

    private static function canManage(Request $request): bool
    {
        return in_array($request->getAttribute('user')['role'] ?? '', ['admin', 'manager'], true);
    }

    private static function uid(Request $request): int
    {
        return (int) $request->getAttribute('user')['id'];
    }

    private static function ip(Request $request): ?string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? null;
    }

    /** Runs $fn, turning a RuntimeException into 422; $manage = admin / manager only. */
    private function run(Request $request, Response $response, callable $fn, bool $manage = false): Response
    {
        if ($manage && !self::canManage($request)) {
            return $this->error($response, 'เฉพาะผู้ดูแลระบบหรือผู้จัดการร้านเท่านั้น', 403);
        }
        try {
            $db = Db::connection();
            return $this->json($response, ['data' => $fn($db)]);
        } catch (\RuntimeException $e) {
            return $this->error($response, $e->getMessage(), 422);
        }
    }

    private static function body(Request $request): array
    {
        return (array) $request->getParsedBody();
    }

    // ---------- POS ----------

    /** GET /sales/members?q= — people matching a national ID, student code, phone or name; a single exact match comes with its card. */
    public function posSearch(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function ($db) use ($request) {
            $members = new MemberService($db);
            $found = $members->search((string) ($request->getQueryParams()['q'] ?? ''));
            $card = count($found) === 1 && $found[0]['matched_by'] !== 'name' ? $members->cardFor($found[0]['oa_user_id']) : null;
            return ['people' => $found, 'card' => $card, 'hint' => $found ? null : $members->missingDataHint()];
        });
    }

    /** GET /sales/members/{oa} — the buyer picked from the list (their points account opened if new). */
    public function posPick(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn ($db) => (new MemberService($db))->cardFor((int) $args['oa']));
    }

    /** GET /sales/member-card/{id} — the buyer's points again (after a sale, on another till). */
    public function posCard(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn ($db) => (new MemberService($db))->card((int) $args['id']));
    }

    /** POST /sales/points/hold {member_id, product_id | group_id, qty, unit_price?, line_value?, points?} */
    public function hold(Request $request, Response $response): Response
    {
        $device = $request->getAttribute('device');
        return $this->run($request, $response, fn ($db) => (new MemberService($db))->hold(self::body($request), self::uid($request), $device ? (int) $device['id'] : null));
    }

    /** POST /sales/points/release {member_id, hold_ids} */
    public function release(Request $request, Response $response): Response
    {
        $b = self::body($request);
        return $this->run($request, $response, fn ($db) => (new MemberService($db))->release((int) ($b['member_id'] ?? 0), (array) ($b['hold_ids'] ?? [])));
    }

    /** GET /sales/redeems — goods points can come off now (the POS shows "ใช้แต้ม" on them); the Android POS also gets upcoming ones. */
    public function posRedeems(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $service = new PointRedeemService($db);
        if ($request->getAttribute('device')) {
            return $this->json($response, ['data' => $service->deviceRules(), 'ready' => MemberService::ready($db)]);
        }
        return $this->json($response, ['data' => $service->running(date('Y-m-d H:i:s')), 'ready' => MemberService::ready($db)]);
    }

    /** POST /sales/surveys/{id}/answer {member_id, answers} — the member answers at the counter. */
    public function answer(Request $request, Response $response, array $args): Response
    {
        $b = self::body($request);
        return $this->run($request, $response, fn ($db) => (new SurveyService($db))->answer((int) $args['id'], (int) ($b['member_id'] ?? 0), (array) ($b['answers'] ?? []), self::uid($request)));
    }

    // ---------- Members menu ----------

    public function index(Request $request, Response $response): Response
    {
        $db = Db::connection();
        if (!MemberService::ready($db)) {
            return $this->json($response, ['ready' => false, 'data' => []]);
        }
        return $this->json($response, (new MemberReportService($db))->list($request->getQueryParams()) + [
            'ready' => true, 'can_manage' => self::canManage($request), 'settings' => (new MemberService($db))->settings(),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn ($db) => (new MemberReportService($db))->detail((int) $args['oa'], self::canManage($request))
            + ['can_manage' => self::canManage($request)]);
    }

    public function points(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function ($db) use ($request, $args) {
            $id = (new MemberService($db))->ensure((int) $args['oa']);
            return (new MemberReportService($db))->ledger($id, (int) ($request->getQueryParams()['page'] ?? 1));
        });
    }

    public function purchases(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn ($db) => (new MemberReportService($db))->purchases((new MemberService($db))->ensure((int) $args['oa'])));
    }

    /** POST /members/{oa}/adjust {points, note} */
    public function adjust(Request $request, Response $response, array $args): Response
    {
        $b = self::body($request);
        return $this->run($request, $response, function ($db) use ($request, $args, $b) {
            $members = new MemberService($db, new AuditLogger($db));
            return $members->adjust($members->ensure((int) $args['oa']), (int) ($b['points'] ?? 0), (string) ($b['note'] ?? ''), self::uid($request), self::ip($request));
        }, true);
    }

    public function stats(Request $request, Response $response): Response
    {
        $p = $request->getQueryParams();
        $ok = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $to = $ok($p['to'] ?? null) ? $p['to'] : date('Y-m-d');
        $from = $ok($p['from'] ?? null) ? $p['from'] : date('Y-m-01');
        return $this->run($request, $response, fn ($db) => (new MemberReportService($db))->stats(min($from, $to), max($from, $to)));
    }

    public function settings(Request $request, Response $response): Response
    {
        return $this->run($request, $response, fn ($db) => (new MemberService($db))->settings() + ['can_manage' => self::canManage($request)]);
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        return $this->run($request, $response, fn ($db) => (new MemberService($db))->saveSettings(self::body($request), self::uid($request)), true);
    }

    // ---------- Using points (second tab of the promotions page) ----------

    public function redeems(Request $request, Response $response): Response
    {
        $db = Db::connection();
        return $this->json($response, ['data' => (new PointRedeemService($db))->all(), 'ready' => MemberService::ready($db), 'can_manage' => self::canManage($request)]);
    }

    public function redeemItems(Request $request, Response $response): Response
    {
        return $this->run($request, $response, fn ($db) => (new PointRedeemService($db))->searchItems((string) ($request->getQueryParams()['q'] ?? '')));
    }

    public function redeemSave(Request $request, Response $response, array $args): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        return $this->run($request, $response, fn ($db) => (new PointRedeemService($db, new AuditLogger($db)))->save($id, self::body($request), self::uid($request), self::ip($request)), true);
    }

    public function redeemActive(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn ($db) => (new PointRedeemService($db, new AuditLogger($db)))->setActive((int) $args['id'], !empty(self::body($request)['active']), self::uid($request), self::ip($request)), true);
    }

    public function redeemDelete(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn ($db) => (new PointRedeemService($db, new AuditLogger($db)))->delete((int) $args['id'], self::uid($request), self::ip($request)), true);
    }

    // ---------- Surveys ----------

    public function surveys(Request $request, Response $response): Response
    {
        $db = Db::connection();
        return $this->json($response, [
            'data' => (new SurveyService($db))->all(), 'ready' => MemberService::ready($db), 'can_manage' => self::canManage($request),
            'kinds' => SurveyService::QUESTION_KINDS,
        ]);
    }

    public function surveyResults(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn ($db) => (new SurveyService($db))->results((int) $args['id']));
    }

    public function surveySave(Request $request, Response $response, array $args): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        return $this->run($request, $response, fn ($db) => (new SurveyService($db, new AuditLogger($db)))->save($id, self::body($request), self::uid($request), self::ip($request)), true);
    }

    public function surveyActive(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn ($db) => (new SurveyService($db, new AuditLogger($db)))->setActive((int) $args['id'], !empty(self::body($request)['active']), self::uid($request), self::ip($request)), true);
    }

    public function surveyDelete(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn ($db) => (new SurveyService($db, new AuditLogger($db)))->delete((int) $args['id'], self::uid($request), self::ip($request)), true);
    }
}
