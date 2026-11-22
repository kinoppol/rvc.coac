<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * The members menu (migration 0088): the register of members with their points and spending,
 * one member's points history and buying habits, and the use of points across the store.
 */
class MemberReportService
{
    private const SORTS = [
        'points' => 'points DESC, o.first_name', 'spend' => 'spend DESC, o.first_name', 'recent' => 'last_purchase_at IS NULL, last_purchase_at DESC',
        'bills' => 'bills DESC, o.first_name', 'name' => 'o.first_name, o.last_name',
    ];

    public function __construct(private PDO $db)
    {
    }

    /**
     * GET /members: q (name, national ID, student code, phone), type (student | staff), only (buyers | points),
     * sort (points | spend | recent | bills | name), page (50 a page).
     */
    public function list(array $f): array
    {
        $where = ["o.status = 'active'"];
        $args = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $digits = preg_replace('/[\s-]/', '', $q);
            if (preg_match('/^\d{4,15}$/', $digits)) {
                $where[] = '(o.national_id = ? OR o.student_code = ? OR o.student_code LIKE ? OR EXISTS (SELECT 1 FROM oa_user_phones ph WHERE ph.oa_user_id = o.oa_user_id AND ph.phone = ?))';
                array_push($args, $digits, $q, $q . '%', $digits);
            } else {
                $where[] = "(CONCAT(o.first_name, ' ', o.last_name) LIKE ? OR o.department LIKE ? OR o.student_code = ?)";
                array_push($args, '%' . $q . '%', '%' . $q . '%', $q);
            }
        }
        $type = (string) ($f['type'] ?? '');
        if ($type === 'student') {
            $where[] = "o.user_type = 'student'";
        } elseif ($type === 'staff') {
            $where[] = "(o.user_type IS NULL OR o.user_type <> 'student')";
        }
        $only = (string) ($f['only'] ?? '');
        if ($only === 'buyers') {
            $where[] = 'm.first_purchase_at IS NOT NULL';
        } elseif ($only === 'points') {
            $where[] = 'm.points > 0';
        }
        $order = self::SORTS[$f['sort'] ?? 'recent'] ?? self::SORTS['recent'];
        $page = max(1, (int) ($f['page'] ?? 1));
        $per = 50;
        $from = 'FROM oa_users o
                 LEFT JOIN members m ON m.oa_user_id = o.oa_user_id OR (o.national_id IS NOT NULL AND m.national_id = o.national_id)
                 LEFT JOIN (SELECT member_id, COUNT(*) AS bills, SUM(total_value) AS spend FROM sales WHERE member_id IS NOT NULL GROUP BY member_id) s ON s.member_id = m.id
                 WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) {$from}");
        $stmt->execute($args);
        $total = (int) $stmt->fetchColumn();
        $stmt = $this->db->prepare(
            "SELECT o.*, m.id AS member_id, COALESCE(m.points, 0) AS points, m.first_purchase_at, m.last_purchase_at,
                    COALESCE(s.bills, 0) AS bills, COALESCE(s.spend, 0) AS spend
             {$from} ORDER BY {$order} LIMIT {$per} OFFSET " . (($page - 1) * $per)
        );
        $stmt->execute($args);
        $rows = array_map(fn ($r) => MemberService::personOut($r) + [
            'member_id' => $r['member_id'] !== null ? (int) $r['member_id'] : null, 'points' => (int) $r['points'],
            'bills' => (int) $r['bills'], 'spend' => round((float) $r['spend'], 2), 'last_purchase_at' => $r['last_purchase_at'],
        ], $stmt->fetchAll());
        $summary = $this->db->query(
            "SELECT (SELECT COUNT(*) FROM oa_users WHERE status = 'active') AS people,
                    (SELECT COUNT(*) FROM oa_users WHERE status = 'active' AND user_type = 'student') AS students,
                    (SELECT COUNT(*) FROM members WHERE first_purchase_at IS NOT NULL) AS buyers,
                    (SELECT COALESCE(SUM(points), 0) FROM members) AS points,
                    (SELECT COUNT(*) FROM oa_users WHERE status = 'active' AND national_id IS NOT NULL) AS with_id,
                    (SELECT COUNT(DISTINCT oa_user_id) FROM oa_user_phones) AS with_phone"
        )->fetch();
        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per, 'summary' => array_map('intval', $summary)];
    }

    /** One person of the register, with their points account (opened if new) and spending summary. */
    public function detail(int $oaUserId, bool $full): array
    {
        $stmt = $this->db->prepare('SELECT * FROM oa_users WHERE oa_user_id = ?');
        $stmt->execute([$oaUserId]);
        $o = $stmt->fetch();
        if (!$o) {
            throw new \RuntimeException('ไม่พบสมาชิกในทะเบียน RVC-ONE');
        }
        $members = new MemberService($this->db);
        $memberId = $members->ensure($oaUserId);
        $card = $members->card($memberId);
        $phones = $this->db->prepare('SELECT phone FROM oa_user_phones WHERE oa_user_id = ? ORDER BY phone');
        $phones->execute([$oaUserId]);
        $phones = $phones->fetchAll(PDO::FETCH_COLUMN);
        $m = $members->member($memberId);
        $sum = $this->db->prepare(
            'SELECT COUNT(*) AS bills, COALESCE(SUM(total_value), 0) AS spend, COALESCE(SUM(total_qty), 0) AS pieces,
                    COALESCE(SUM(points_discount), 0) AS points_discount, COALESCE(SUM(promo_discount), 0) AS promo_discount
             FROM sales WHERE member_id = ?'
        );
        $sum->execute([$memberId]);
        $s = $sum->fetch();
        $kinds = $this->db->prepare('SELECT kind, SUM(points) AS points FROM member_points WHERE member_id = ? GROUP BY kind');
        $kinds->execute([$memberId]);
        $byKind = [];
        foreach ($kinds->fetchAll() as $k) {
            $byKind[$k['kind']] = (int) $k['points'];
        }
        return $card + [
            'national_id' => $full ? $o['national_id'] : MemberService::maskNationalId($o['national_id']),
            'phones' => $full ? $phones : array_map([MemberService::class, 'maskPhone'], $phones),
            'username' => $o['username'], 'email' => $full ? $o['email'] : null,
            'first_purchase_at' => $m['first_purchase_at'], 'last_purchase_at' => $m['last_purchase_at'], 'member_since' => $m['created_at'],
            'bills' => (int) $s['bills'], 'spend' => round((float) $s['spend'], 2), 'pieces' => (int) $s['pieces'],
            'avg_bill' => (int) $s['bills'] ? round((float) $s['spend'] / (int) $s['bills'], 2) : 0,
            'points_discount' => round((float) $s['points_discount'], 2), 'promo_discount' => round((float) $s['promo_discount'], 2),
            'points_by_kind' => $byKind,
        ];
    }

    /** A member's points history, newest first (50 a page). */
    public function ledger(int $memberId, int $page = 1): array
    {
        $per = 50;
        $page = max(1, $page);
        $stmt = $this->db->prepare(
            'SELECT p.*, s.doc_no, sv.title AS survey_title, u.full_name AS by_name FROM member_points p
             LEFT JOIN sales s ON s.id = p.sale_id LEFT JOIN surveys sv ON sv.id = p.survey_id LEFT JOIN users u ON u.id = p.created_by
             WHERE p.member_id = ? ORDER BY p.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)
        );
        $stmt->execute([$memberId]);
        $count = $this->db->prepare('SELECT COUNT(*) FROM member_points WHERE member_id = ?');
        $count->execute([$memberId]);
        return [
            'data' => array_map(fn ($r) => [
                'id' => (int) $r['id'], 'kind' => $r['kind'], 'kind_label' => MemberService::KINDS[$r['kind']] ?? $r['kind'],
                'points' => (int) $r['points'], 'balance_after' => (int) $r['balance_after'], 'sale_id' => $r['sale_id'] !== null ? (int) $r['sale_id'] : null,
                'doc_no' => $r['doc_no'], 'survey_title' => $r['survey_title'], 'note' => $r['note'], 'by' => $r['by_name'], 'at' => $r['created_at'],
            ], $stmt->fetchAll()),
            'total' => (int) $count->fetchColumn(), 'page' => $page, 'per_page' => $per,
        ];
    }

    /**
     * How a member buys, to study what customers do: spending by month, by weekday and hour,
     * their favourite goods, how they pay, and their latest bills with what was in them.
     */
    public function purchases(int $memberId): array
    {
        $q = function (string $sql) use ($memberId) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$memberId]);
            return $stmt->fetchAll();
        };
        $months = $q("SELECT DATE_FORMAT(sale_datetime, '%Y-%m') AS month, COUNT(*) AS bills, SUM(total_value) AS spend
                      FROM sales WHERE member_id = ? AND sale_datetime >= DATE_FORMAT(NOW() - INTERVAL 11 MONTH, '%Y-%m-01') GROUP BY month ORDER BY month");
        $slots = $q('SELECT (DAYOFWEEK(sale_datetime) + 5) % 7 AS weekday, HOUR(sale_datetime) AS hour, COUNT(*) AS bills
                     FROM sales WHERE member_id = ? GROUP BY weekday, hour');
        $top = $q("SELECT COALESCE(CONCAT('g', g.id), CONCAT('p', p.id)) AS k, MAX(COALESCE(g.name, p.name)) AS name, SUM(si.qty) AS qty,
                          SUM(si.qty * si.unit_price) AS spend, COUNT(DISTINCT si.sale_id) AS bills, MAX(s.sale_datetime) AS last_at
                   FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN products p ON p.id = si.product_id
                   LEFT JOIN sale_groups g ON g.id = p.sale_group_id
                   WHERE s.member_id = ? GROUP BY k ORDER BY qty DESC, spend DESC LIMIT 15");
        $pay = $q('SELECT sp.method, COUNT(DISTINCT sp.sale_id) AS bills, SUM(sp.amount) AS amount FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
                   WHERE s.member_id = ? GROUP BY sp.method ORDER BY amount DESC');
        $recent = $q('SELECT id, doc_no, sale_datetime, total_qty, total_value, promo_discount, points_discount, points_earned, points_used
                      FROM sales WHERE member_id = ? ORDER BY sale_datetime DESC LIMIT 20');
        $items = [];
        if ($recent) {
            $ids = array_column($recent, 'id');
            $stmt = $this->db->prepare('SELECT si.sale_id, p.name, si.qty, si.unit_price, si.pack_size FROM sale_items si JOIN products p ON p.id = si.product_id
                                        WHERE si.sale_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY si.id');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $it) {
                $items[(int) $it['sale_id']][] = ['name' => $it['name'], 'qty' => (int) $it['qty'], 'unit_price' => (float) $it['unit_price']];
            }
        }
        // Days between visits: how often they come back.
        $days = $q('SELECT DISTINCT DATE(sale_datetime) AS d FROM sales WHERE member_id = ? ORDER BY d');
        $gaps = [];
        for ($i = 1; $i < count($days); $i++) {
            $gaps[] = (strtotime($days[$i]['d']) - strtotime($days[$i - 1]['d'])) / 86400;
        }
        return [
            'months' => array_map(fn ($r) => ['month' => $r['month'], 'bills' => (int) $r['bills'], 'spend' => round((float) $r['spend'], 2)], $months),
            'slots' => array_map(fn ($r) => ['weekday' => (int) $r['weekday'], 'hour' => (int) $r['hour'], 'bills' => (int) $r['bills']], $slots),
            'top' => array_map(fn ($r) => ['name' => $r['name'], 'qty' => (int) $r['qty'], 'spend' => round((float) $r['spend'], 2), 'bills' => (int) $r['bills'], 'last_at' => $r['last_at']], $top),
            'payments' => array_map(fn ($r) => ['method' => $r['method'], 'label' => SettingsService::PAYMENT_METHODS[$r['method']] ?? $r['method'], 'bills' => (int) $r['bills'], 'amount' => round((float) $r['amount'], 2)], $pay),
            'recent' => array_map(fn ($r) => [
                'id' => (int) $r['id'], 'doc_no' => $r['doc_no'], 'at' => $r['sale_datetime'], 'qty' => (int) $r['total_qty'], 'total' => (float) $r['total_value'],
                'promo_discount' => (float) $r['promo_discount'], 'points_discount' => (float) $r['points_discount'],
                'points_earned' => (int) $r['points_earned'], 'points_used' => (int) $r['points_used'], 'items' => $items[(int) $r['id']] ?? [],
            ], $recent),
            'visit_days' => count($days), 'avg_days_between' => $gaps ? round(array_sum($gaps) / count($gaps), 1) : null,
        ];
    }

    /** GET /members/stats?from=&to=: points earned and spent, members' share of sales, and what points were spent on. */
    public function stats(string $from, string $to): array
    {
        $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $q = function (string $sql, array $args = []) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($args);
            return $stmt->fetchAll();
        };
        $kinds = [];
        foreach ($q('SELECT kind, COUNT(*) AS n, SUM(points) AS points, COUNT(DISTINCT member_id) AS members FROM member_points WHERE created_at BETWEEN ? AND ? GROUP BY kind', $range) as $r) {
            $kinds[$r['kind']] = ['label' => MemberService::KINDS[$r['kind']] ?? $r['kind'], 'count' => (int) $r['n'], 'points' => (int) $r['points'], 'members' => (int) $r['members']];
        }
        $sales = $q('SELECT COUNT(*) AS bills, COALESCE(SUM(total_value), 0) AS value, COUNT(member_id) AS member_bills,
                            COALESCE(SUM(IF(member_id IS NULL, 0, total_value)), 0) AS member_value, COUNT(DISTINCT member_id) AS buyers,
                            COALESCE(SUM(points_discount), 0) AS points_discount, SUM(points_used > 0) AS redeem_bills
                     FROM sales WHERE sale_datetime BETWEEN ? AND ?', $range)[0];
        $daily = $q("SELECT DATE(created_at) AS day, SUM(IF(points > 0, points, 0)) AS given, -SUM(IF(points < 0 AND kind = 'redeem', points, 0)) AS used
                     FROM member_points WHERE created_at BETWEEN ? AND ? GROUP BY day ORDER BY day", $range);
        $redeemed = $q("SELECT COALESCE(g.name, p.name, '(ไม่พบสินค้า)') AS name, COUNT(*) AS times, SUM(h.qty) AS qty, SUM(h.points) AS points, SUM(h.discount) AS discount
                        FROM member_point_holds h LEFT JOIN products p ON p.id = h.product_id LEFT JOIN sale_groups g ON g.id = h.sale_group_id
                        WHERE h.status = 'used' AND h.closed_at BETWEEN ? AND ? GROUP BY name ORDER BY points DESC LIMIT 20", $range);
        $topMembers = $q("SELECT m.id, o.oa_user_id, o.first_name, o.last_name, o.user_type, o.department, o.student_code, o.national_id,
                                 COUNT(*) AS bills, SUM(s.total_value) AS spend, SUM(s.points_earned) AS earned, SUM(s.points_used) AS used
                          FROM sales s JOIN members m ON m.id = s.member_id LEFT JOIN oa_users o ON o.oa_user_id = m.oa_user_id
                          WHERE s.sale_datetime BETWEEN ? AND ? GROUP BY m.id ORDER BY spend DESC LIMIT 20", $range);
        $byType = $q("SELECT IF(o.user_type = 'student', 'student', 'staff') AS type, COUNT(*) AS bills, SUM(s.total_value) AS spend, COUNT(DISTINCT s.member_id) AS buyers
                      FROM sales s JOIN members m ON m.id = s.member_id LEFT JOIN oa_users o ON o.oa_user_id = m.oa_user_id
                      WHERE s.sale_datetime BETWEEN ? AND ? GROUP BY type", $range);
        $held = $q("SELECT COUNT(*) AS n, COALESCE(SUM(points), 0) AS points FROM member_point_holds WHERE status = 'held' AND expires_at >= NOW()")[0];
        $outstanding = $q('SELECT COALESCE(SUM(points), 0) AS points, SUM(points > 0) AS members FROM members')[0];
        $surveys = $q('SELECT s.id, s.title, COUNT(r.id) AS responses, COALESCE(SUM(r.points), 0) AS points FROM survey_responses r JOIN surveys s ON s.id = r.survey_id
                       WHERE r.created_at BETWEEN ? AND ? GROUP BY s.id, s.title ORDER BY responses DESC', $range);
        return [
            'from' => $from, 'to' => $to, 'kinds' => $kinds,
            'sales' => [
                'bills' => (int) $sales['bills'], 'value' => round((float) $sales['value'], 2), 'member_bills' => (int) $sales['member_bills'],
                'member_value' => round((float) $sales['member_value'], 2), 'buyers' => (int) $sales['buyers'],
                'points_discount' => round((float) $sales['points_discount'], 2), 'redeem_bills' => (int) $sales['redeem_bills'],
            ],
            'daily' => array_map(fn ($r) => ['day' => $r['day'], 'given' => (int) $r['given'], 'used' => (int) $r['used']], $daily),
            'redeemed' => array_map(fn ($r) => ['name' => $r['name'], 'times' => (int) $r['times'], 'qty' => (int) $r['qty'], 'points' => (int) $r['points'], 'discount' => round((float) $r['discount'], 2)], $redeemed),
            'top_members' => array_map(fn ($r) => MemberService::personOut($r) + ['bills' => (int) $r['bills'], 'spend' => round((float) $r['spend'], 2), 'earned' => (int) $r['earned'], 'used' => (int) $r['used']], $topMembers),
            'by_type' => array_map(fn ($r) => ['type' => $r['type'], 'bills' => (int) $r['bills'], 'spend' => round((float) $r['spend'], 2), 'buyers' => (int) $r['buyers']], $byType),
            'held' => ['count' => (int) $held['n'], 'points' => (int) $held['points']],
            'outstanding' => ['points' => (int) $outstanding['points'], 'members' => (int) $outstanding['members']],
            'surveys' => array_map(fn ($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'responses' => (int) $r['responses'], 'points' => (int) $r['points']], $surveys),
        ];
    }
}
