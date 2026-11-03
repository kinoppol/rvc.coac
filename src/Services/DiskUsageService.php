<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Disk space of the server (Settings > พื้นที่ดิสก์, admin): how big the disk is, how much is
 * used / free, how much of it is this system's data (uploaded files, logs, database), and one
 * reading a day to show the growth rate and when the disk would fill up.
 */
class DiskUsageService
{
    public function __construct(private PDO $db)
    {
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** The reading of right now (sizes of folders are walked, so this takes a moment). */
    public function measure(): array
    {
        $root = self::root();
        $total = (float) (@disk_total_space($root) ?: 0);
        $free = (float) (@disk_free_space($root) ?: 0);
        [$uploads, $uploadFiles] = self::folderSize($root . '/storage/uploads');
        [$logs] = self::folderSize($root . '/storage/logs');
        [$storage] = self::folderSize($root . '/storage');
        [$all] = self::folderSize($root, ['storage', '.git']);
        return [
            'disk_total' => (int) $total,
            'disk_free' => (int) $free,
            'uploads_bytes' => $uploads,
            'uploads_files' => $uploadFiles,
            'logs_bytes' => $logs,
            'other_storage_bytes' => max(0, $storage - $uploads - $logs),
            'db_bytes' => $this->databaseSize(),
            'app_bytes' => $all,
        ];
    }

    /** Saves today's reading (again, if asked a second time the same day). */
    public function record(): array
    {
        $m = $this->measure();
        $this->db->prepare(
            'INSERT INTO disk_usage_daily (day, disk_total, disk_free, uploads_bytes, uploads_files, logs_bytes, other_storage_bytes, db_bytes, app_bytes, recorded_at)
             VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE disk_total = VALUES(disk_total), disk_free = VALUES(disk_free), uploads_bytes = VALUES(uploads_bytes),
                uploads_files = VALUES(uploads_files), logs_bytes = VALUES(logs_bytes), other_storage_bytes = VALUES(other_storage_bytes),
                db_bytes = VALUES(db_bytes), app_bytes = VALUES(app_bytes), recorded_at = VALUES(recorded_at)'
        )->execute(array_values($m));
        @file_put_contents(self::stampFile(), date('Y-m-d'));
        return $m;
    }

    private static function stampFile(): string
    {
        return self::root() . '/storage/cache/disk-usage.day';
    }

    /**
     * Records today's reading once a day, after the response of the first request of the day.
     * Quiet on any failure (e.g. before migration 0060): it must never break a request.
     */
    public static function recordDaily(callable $db): void
    {
        if (@file_get_contents(self::stampFile()) === date('Y-m-d')) {
            return;
        }
        @file_put_contents(self::stampFile(), date('Y-m-d')); // one request does it, not every request at once
        register_shutdown_function(function () use ($db) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                (new self($db()))->record();
            } catch (\Throwable $e) {
                @unlink(self::stampFile()); // try again on a later request
            }
        });
    }

    /** Daily readings, newest first ([] before migration 0060). */
    public function history(int $days = 365): array
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM disk_usage_daily WHERE day >= CURDATE() - INTERVAL ? DAY ORDER BY day DESC');
            $stmt->execute([$days]);
            return array_map(function (array $r) {
                foreach ($r as $k => $v) {
                    if ($k !== 'day' && $k !== 'recorded_at') {
                        $r[$k] = (int) $v;
                    }
                }
                return $r;
            }, $stmt->fetchAll());
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Average growth a day over the last $days days of readings: of the whole disk's used space,
     * and of this system's own data; with how many days the free space would last.
     */
    public static function forecast(array $history, int $days = 30): array
    {
        $rows = array_values(array_filter($history, fn ($r) => strtotime($r['day']) >= strtotime("-{$days} days")));
        $out = ['days' => 0, 'used_per_day' => null, 'system_per_day' => null, 'days_left' => null];
        if (count($rows) < 2) {
            return $out;
        }
        $new = $rows[0];
        $old = $rows[count($rows) - 1];
        $span = max(1, (int) round((strtotime($new['day']) - strtotime($old['day'])) / 86400));
        $used = fn ($r) => $r['disk_total'] - $r['disk_free'];
        $system = fn ($r) => $r['uploads_bytes'] + $r['logs_bytes'] + $r['other_storage_bytes'] + $r['db_bytes'] + $r['app_bytes'];
        $out['days'] = $span;
        $out['used_per_day'] = (int) round(($used($new) - $used($old)) / $span);
        $out['system_per_day'] = (int) round(($system($new) - $system($old)) / $span);
        $grow = max($out['used_per_day'], $out['system_per_day']);
        $out['days_left'] = $grow > 0 ? (int) floor($new['disk_free'] / $grow) : null;
        return $out;
    }

    /** Tables and indexes of this system's database, in bytes. */
    private function databaseSize(): int
    {
        try {
            return (int) $this->db->query(
                'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()'
            )->fetchColumn();
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /** @return array{0:int,1:int} bytes and number of files under $dir (top-level names in $skip left out) */
    private static function folderSize(string $dir, array $skip = []): array
    {
        if (!is_dir($dir)) {
            return [0, 0];
        }
        $bytes = 0;
        $files = 0;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    fn (\SplFileInfo $f, $key, $iter) => !($skip && $iter instanceof \RecursiveDirectoryIterator
                        && $iter->getSubPath() === '' && in_array($f->getFilename(), $skip, true))
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($it as $f) {
                if ($f->isFile() && !$f->isLink()) {
                    $bytes += $f->getSize();
                    $files++;
                }
            }
        } catch (\Throwable $e) {
            // unreadable folder: count what was read
        }
        return [$bytes, $files];
    }
}
