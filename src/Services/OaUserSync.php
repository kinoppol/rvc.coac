<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OaUserRepo;
use App\Support\OaConfig;
use PDO;

/**
 * Pulls the RVC-ONE directory through the user-sync API into oa_users.
 *
 * Incremental runs ask for users changed since the server_time of the last
 * successful run. server_time is only stored once every page has been
 * upserted, so a run that dies half-way is simply redone from the same since.
 */
class OaUserSync
{
    private const PAGE_LIMIT = 500;
    private const MAX_PAGES = 2000;

    public function __construct(
        private PDO $db,
        private OaClient $client = new OaClient(),
    ) {
    }

    /**
     * @return array{run_id:int, mode:string, since:?string, server_time:?string, pages:int, fetched:int, added:int, updated:int, suspended:int}
     * @throws OaApiException|\RuntimeException
     */
    public function run(bool $full = false, ?int $triggeredBy = null): array
    {
        $got = $this->db->query("SELECT GET_LOCK('rvc_coac_oa_user_sync', 0)")->fetchColumn();
        if ((int) $got !== 1) {
            throw new \RuntimeException('มีการซิงค์ผู้ใช้จาก RVC-ONE กำลังทำงานอยู่ กรุณารอสักครู่');
        }

        try {
            $since = $full ? null : $this->lastServerTime();
            $mode = $since === null ? 'full' : 'incremental';
            $type = (string) OaConfig::get('sync_type');

            $this->db->prepare(
                "INSERT INTO oa_sync_runs (mode, user_type, since_value, status, triggered_by, started_at)
                 VALUES (?, ?, ?, 'running', ?, NOW())"
            )->execute([$mode, $type ?: null, $since, $triggeredBy]);
            $runId = (int) $this->db->lastInsertId();

            $stats = ['pages' => 0, 'fetched' => 0, 'added' => 0, 'updated' => 0, 'suspended' => 0];
            try {
                $serverTime = $this->pullAll($type, $since, $stats);
            } catch (\Throwable $e) {
                $this->finish($runId, 'failed', null, $stats, mb_substr($e->getMessage(), 0, 500));
                error_log(sprintf('[oa-sync] run %d failed: %s', $runId, $e->getMessage()));
                throw $e;
            }

            $this->finish($runId, 'ok', $serverTime, $stats, null);
            error_log(sprintf(
                '[oa-sync] run %d %s ok: fetched=%d added=%d updated=%d suspended=%d',
                $runId, $mode, $stats['fetched'], $stats['added'], $stats['updated'], $stats['suspended']
            ));

            return ['run_id' => $runId, 'mode' => $mode, 'since' => $since, 'server_time' => $serverTime] + $stats;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('rvc_coac_oa_user_sync')");
        }
    }

    /** server_time of the latest successful run = since of the next one. */
    public function lastServerTime(): ?string
    {
        $v = $this->db->query(
            "SELECT server_time FROM oa_sync_runs WHERE status = 'ok' AND server_time IS NOT NULL ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function recentRuns(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, u.full_name AS triggered_by_name FROM oa_sync_runs r
             LEFT JOIN users u ON u.id = r.triggered_by ORDER BY r.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function pullAll(string $type, ?string $since, array &$stats): ?string
    {
        $repo = new OaUserRepo($this->db);
        $first = ['type' => $type, 'since' => $since, 'limit' => self::PAGE_LIMIT];
        $params = $first;
        $serverTime = null;
        $restarted = false;

        while (true) {
            try {
                $page = $this->client->fetchUsers($params);
            } catch (OaApiException $e) {
                // An expired/invalid cursor means: start this round again from page one.
                if ($e->apiError === 'bad_cursor' && !$restarted) {
                    $restarted = true;
                    $params = $first;
                    $serverTime = null;
                    continue;
                }
                throw $e;
            }

            // Every page of one round carries the same server_time; keep the first.
            $serverTime ??= is_string($page['server_time'] ?? null) ? $page['server_time'] : null;
            $stats['pages']++;

            $this->db->beginTransaction();
            try {
                foreach ((array) ($page['users'] ?? []) as $u) {
                    if (!is_array($u) || (int) ($u['id'] ?? 0) <= 0) {
                        continue;
                    }
                    $stats['fetched']++;
                    $result = $repo->upsert($u);
                    if ($result === 'added' || $result === 'updated') {
                        $stats[$result]++;
                        if (($u['status'] ?? '') === 'suspended') {
                            $stats['suspended']++;
                        }
                    }
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $next = $page['next_cursor'] ?? null;
            if (empty($page['has_more']) || !is_string($next) || $next === '') {
                return $serverTime;
            }
            if ($stats['pages'] >= self::MAX_PAGES) {
                throw new \RuntimeException('จำนวนหน้าเกินขีดจำกัด (' . self::MAX_PAGES . ') หยุดซิงค์เพื่อความปลอดภัย');
            }
            $params = ['cursor' => $next, 'limit' => self::PAGE_LIMIT];
        }
    }

    private function finish(int $runId, string $status, ?string $serverTime, array $stats, ?string $error): void
    {
        $this->db->prepare(
            'UPDATE oa_sync_runs SET status = ?, server_time = ?, pages = ?, fetched = ?, added = ?, updated = ?,
                    suspended = ?, error = ?, finished_at = NOW() WHERE id = ?'
        )->execute([
            $status, $serverTime, $stats['pages'], $stats['fetched'], $stats['added'], $stats['updated'],
            $stats['suspended'], $error, $runId,
        ]);
    }
}
