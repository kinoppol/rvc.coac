<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Short questionnaires that give a member extra points once answered (migration 0088).
 * A member answers each survey once; the points go into their history as kind 'survey'.
 * Answered at the POS: the cashier names the buyer and hands them the screen.
 */
class SurveyService
{
    public const QUESTION_KINDS = ['choice' => 'เลือก 1 ข้อ', 'multi' => 'เลือกได้หลายข้อ', 'rating' => 'ให้คะแนน 1–5', 'text' => 'ตอบเป็นข้อความ'];

    public function __construct(private PDO $db, private ?AuditLogger $audit = null)
    {
    }

    private function assertReady(): void
    {
        if (!MemberService::ready($this->db)) {
            throw new \RuntimeException('ยังใช้แบบสอบถามไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0088) ก่อน');
        }
    }

    /** Every survey with its questions and how many members answered, newest first. */
    public function all(): array
    {
        if (!MemberService::ready($this->db)) {
            return [];
        }
        $rows = $this->db->query(
            'SELECT s.*, u.full_name AS creator, (SELECT COUNT(*) FROM survey_responses r WHERE r.survey_id = s.id) AS responses,
                    (SELECT COALESCE(SUM(r.points), 0) FROM survey_responses r WHERE r.survey_id = s.id) AS points_given
             FROM surveys s LEFT JOIN users u ON u.id = s.created_by ORDER BY s.active DESC, s.id DESC'
        )->fetchAll();
        $questions = $this->questions(array_map('intval', array_column($rows, 'id')));
        $now = date('Y-m-d H:i:s');
        return array_map(fn ($s) => $this->out($s, $questions[(int) $s['id']] ?? [], $now) + [
            'responses' => (int) $s['responses'], 'points_given' => (int) $s['points_given'], 'creator' => $s['creator'],
        ], $rows);
    }

    private function out(array $s, array $questions, string $now): array
    {
        return [
            'id' => (int) $s['id'], 'title' => $s['title'], 'description' => $s['description'], 'points' => (int) $s['points'],
            'starts_on' => $s['starts_on'], 'ends_on' => $s['ends_on'], 'active' => (bool) $s['active'],
            'status' => !$s['active'] ? 'off' : ($s['starts_on'] && $s['starts_on'] > $now ? 'upcoming' : ($s['ends_on'] && $s['ends_on'] < $now ? 'ended' : 'on')),
            'questions' => $questions,
        ];
    }

    /** @return array<int,array> survey id => its questions in order */
    private function questions(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT * FROM survey_questions WHERE survey_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY survey_id, position, id');
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $q) {
            $out[(int) $q['survey_id']][] = [
                'id' => (int) $q['id'], 'kind' => $q['kind'], 'label' => $q['label'],
                'options' => $q['options'] !== null ? (json_decode($q['options'], true) ?: []) : [], 'required' => (bool) $q['required'],
            ];
        }
        return $out;
    }

    /** POST /surveys or PUT /surveys/{id}: {title, description?, points, starts_on?, ends_on?, active?, questions: [{kind, label, options?, required?}]} */
    public function save(?int $id, array $in, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200);
        if ($title === '') {
            throw new \RuntimeException('กรุณาตั้งชื่อแบบสอบถาม');
        }
        $points = (int) ($in['points'] ?? -1);
        if ($points < 0 || $points > 100000) {
            throw new \RuntimeException('แต้มที่ได้รับต้องเป็น 0 ขึ้นไป');
        }
        $description = mb_substr(trim((string) ($in['description'] ?? '')), 0, 1000) ?: null;
        $date = function ($v, bool $end): ?string {
            $v = trim(str_replace('T', ' ', (string) ($v ?? '')));
            if ($v === '') {
                return null;
            }
            $ts = strtotime($v);
            if ($ts === false) {
                throw new \RuntimeException('วันเวลาของแบบสอบถามไม่ถูกต้อง');
            }
            return date($end && strlen($v) <= 16 ? 'Y-m-d H:i:59' : 'Y-m-d H:i:s', $end && strlen($v) === 10 ? $ts + 86399 : $ts);
        };
        $starts = $date($in['starts_on'] ?? null, false);
        $ends = $date($in['ends_on'] ?? null, true);
        if ($starts && $ends && $ends < $starts) {
            throw new \RuntimeException('วันเวลาสิ้นสุดต้องไม่ก่อนวันเวลาเริ่ม');
        }
        $active = !array_key_exists('active', $in) || !empty($in['active']);
        $questions = [];
        foreach ((array) ($in['questions'] ?? []) as $q) {
            $q = (array) $q;
            $label = mb_substr(trim((string) ($q['label'] ?? '')), 0, 500);
            if ($label === '') {
                continue;
            }
            $kind = (string) ($q['kind'] ?? 'choice');
            if (!isset(self::QUESTION_KINDS[$kind])) {
                throw new \RuntimeException('รูปแบบคำถามไม่ถูกต้อง');
            }
            $options = null;
            if ($kind === 'choice' || $kind === 'multi') {
                $opts = array_values(array_unique(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 200), (array) ($q['options'] ?? [])), fn ($o) => $o !== '')));
                if (count($opts) < 2) {
                    throw new \RuntimeException("คำถาม \"{$label}\" ต้องมีตัวเลือกอย่างน้อย 2 ข้อ");
                }
                $options = json_encode($opts, JSON_UNESCAPED_UNICODE);
            }
            $questions[] = ['kind' => $kind, 'label' => $label, 'options' => $options, 'required' => !array_key_exists('required', $q) || !empty($q['required'])];
        }
        if (!$questions) {
            throw new \RuntimeException('เพิ่มคำถามอย่างน้อย 1 ข้อ');
        }

        $this->db->beginTransaction();
        try {
            $before = null;
            if ($id === null) {
                $this->db->prepare('INSERT INTO surveys (title, description, points, starts_on, ends_on, active, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
                    ->execute([$title, $description, $points, $starts, $ends, (int) $active, $userId]);
                $id = (int) $this->db->lastInsertId();
            } else {
                $stmt = $this->db->prepare('SELECT * FROM surveys WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $before = $stmt->fetch();
                if (!$before) {
                    throw new \RuntimeException('ไม่พบแบบสอบถามนี้');
                }
                $answered = $this->db->prepare('SELECT COUNT(*) FROM survey_responses WHERE survey_id = ?');
                $answered->execute([$id]);
                $old = $this->questions([$id])[$id] ?? [];
                $same = count($old) === count($questions);
                foreach ($old as $i => $o) {
                    $same = $same && $o['kind'] === $questions[$i]['kind'] && $o['label'] === $questions[$i]['label'];
                }
                // Answers already given refer to the questions by position: their wording may be put right, not the set of questions.
                if ((int) $answered->fetchColumn() > 0 && count($old) !== count($questions)) {
                    throw new \RuntimeException('มีสมาชิกตอบแบบสอบถามนี้แล้ว เพิ่มหรือลบคำถามไม่ได้ (แก้ข้อความได้) — สร้างแบบสอบถามใหม่แทน');
                }
                $this->db->prepare('UPDATE surveys SET title = ?, description = ?, points = ?, starts_on = ?, ends_on = ?, active = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$title, $description, $points, $starts, $ends, (int) $active, $id]);
                $this->db->prepare('DELETE FROM survey_questions WHERE survey_id = ?')->execute([$id]);
            }
            $ins = $this->db->prepare('INSERT INTO survey_questions (survey_id, position, kind, label, options, required) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($questions as $pos => $q) {
                $ins->execute([$id, $pos, $q['kind'], $q['label'], $q['options'], (int) $q['required']]);
            }
            $this->audit?->log('surveys', $id, $before ? 'update' : 'create', $before, ['title' => $title, 'points' => $points, 'questions' => count($questions)], $userId, $ip);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['id' => $id];
    }

    public function setActive(int $id, bool $active, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $stmt = $this->db->prepare('UPDATE surveys SET active = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([(int) $active, $id]);
        $this->audit?->log('surveys', $id, 'update', null, ['active' => $active], $userId, $ip);
        return ['id' => $id, 'active' => $active];
    }

    /** Only a survey nobody answered can be deleted (the points given refer to it): close it instead. */
    public function delete(int $id, int $userId, ?string $ip): array
    {
        $this->assertReady();
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM survey_responses WHERE survey_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new \RuntimeException('มีสมาชิกตอบแบบสอบถามนี้แล้ว ลบไม่ได้ — กด "ปิด" เพื่อหยุดรับคำตอบแทน');
        }
        $this->db->prepare('DELETE FROM survey_questions WHERE survey_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM surveys WHERE id = ?')->execute([$id]);
        $this->audit?->log('surveys', $id, 'delete', null, null, $userId, $ip);
        return ['id' => $id];
    }

    /** Surveys running now that this member has not answered yet, with their questions. */
    public function openFor(int $memberId): array
    {
        if (!MemberService::ready($this->db)) {
            return [];
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'SELECT s.* FROM surveys s WHERE s.active = 1 AND (s.starts_on IS NULL OR s.starts_on <= ?) AND (s.ends_on IS NULL OR s.ends_on >= ?)
               AND NOT EXISTS (SELECT 1 FROM survey_responses r WHERE r.survey_id = s.id AND r.member_id = ?) ORDER BY s.id'
        );
        $stmt->execute([$now, $now, $memberId]);
        $rows = $stmt->fetchAll();
        $questions = $this->questions(array_map('intval', array_column($rows, 'id')));
        return array_map(fn ($s) => $this->out($s, $questions[(int) $s['id']] ?? [], $now), $rows);
    }

    /** A member's answers: kept once, and the survey's points added to their balance. */
    public function answer(int $surveyId, int $memberId, array $answers, int $userId): array
    {
        $this->assertReady();
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('SELECT * FROM surveys WHERE id = ?');
        $stmt->execute([$surveyId]);
        $s = $stmt->fetch();
        if (!$s || !$s['active'] || ($s['starts_on'] && $s['starts_on'] > $now) || ($s['ends_on'] && $s['ends_on'] < $now)) {
            throw new \RuntimeException('แบบสอบถามนี้ปิดรับคำตอบแล้ว');
        }
        $questions = $this->questions([$surveyId])[$surveyId] ?? [];
        $clean = [];
        foreach ($questions as $i => $q) {
            $a = $answers[$i] ?? $answers[(string) $q['id']] ?? null;
            $value = match ($q['kind']) {
                'choice' => is_scalar($a) && in_array((string) $a, $q['options'], true) ? (string) $a : null,
                'multi' => is_array($a) ? array_values(array_intersect($q['options'], array_map('strval', $a))) ?: null : null,
                'rating' => is_numeric($a) && (int) $a >= 1 && (int) $a <= 5 ? (int) $a : null,
                default => is_scalar($a) && trim((string) $a) !== '' ? mb_substr(trim((string) $a), 0, 2000) : null,
            };
            if ($value === null && $q['required']) {
                throw new \RuntimeException('กรุณาตอบคำถามข้อ ' . ($i + 1) . ': ' . $q['label']);
            }
            $clean[] = ['q' => $q['id'], 'a' => $value];
        }
        $members = new MemberService($this->db);
        $this->db->beginTransaction();
        try {
            try {
                $this->db->prepare('INSERT INTO survey_responses (survey_id, member_id, answers, points, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
                    ->execute([$surveyId, $memberId, json_encode($clean, JSON_UNESCAPED_UNICODE), (int) $s['points'], $userId]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new \RuntimeException('สมาชิกนี้ตอบแบบสอบถามนี้ไปแล้ว');
                }
                throw $e;
            }
            $balance = $members->credit($memberId, 'survey', (int) $s['points'], ['survey_id' => $surveyId, 'note' => $s['title']], $userId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['points' => (int) $s['points'], 'balance' => $balance, 'member' => $members->card($memberId)];
    }

    /** Admin: the answers summed per question (counts per option, average rating, the text answers). */
    public function results(int $surveyId): array
    {
        $this->assertReady();
        $stmt = $this->db->prepare('SELECT * FROM surveys WHERE id = ?');
        $stmt->execute([$surveyId]);
        $s = $stmt->fetch();
        if (!$s) {
            throw new \RuntimeException('ไม่พบแบบสอบถามนี้');
        }
        $questions = $this->questions([$surveyId])[$surveyId] ?? [];
        $stmt = $this->db->prepare(
            'SELECT r.answers, r.created_at, o.user_type, o.department FROM survey_responses r
             JOIN members m ON m.id = r.member_id LEFT JOIN oa_users o ON o.oa_user_id = m.oa_user_id
             WHERE r.survey_id = ? ORDER BY r.id'
        );
        $stmt->execute([$surveyId]);
        $rows = $stmt->fetchAll();
        $sum = array_map(fn ($q) => $q + ['counts' => array_fill_keys($q['options'], 0), 'ratings' => [], 'texts' => [], 'answered' => 0], $questions);
        $types = ['student' => 0, 'staff' => 0];
        foreach ($rows as $r) {
            $types[$r['user_type'] === 'student' ? 'student' : 'staff']++;
            foreach ((array) json_decode($r['answers'], true) as $i => $a) {
                if (!isset($sum[$i]) || ($a['a'] ?? null) === null) {
                    continue;
                }
                $sum[$i]['answered']++;
                $v = $a['a'];
                match ($sum[$i]['kind']) {
                    'choice' => isset($sum[$i]['counts'][$v]) ? $sum[$i]['counts'][$v]++ : null,
                    'multi' => array_map(function ($x) use (&$sum, $i) {
                        if (isset($sum[$i]['counts'][$x])) {
                            $sum[$i]['counts'][$x]++;
                        }
                    }, (array) $v),
                    'rating' => $sum[$i]['ratings'][] = (int) $v,
                    default => count($sum[$i]['texts']) < 500 ? $sum[$i]['texts'][] = ['text' => (string) $v, 'at' => $r['created_at']] : null,
                };
            }
        }
        foreach ($sum as &$q) {
            $q['average'] = $q['ratings'] ? round(array_sum($q['ratings']) / count($q['ratings']), 2) : null;
            $q['rating_counts'] = array_count_values($q['ratings']) + [];
            unset($q['ratings']);
        }
        unset($q);
        return array_merge($this->out($s, [], date('Y-m-d H:i:s')), ['responses' => count($rows), 'by_type' => $types, 'questions' => $sum]);
    }
}
