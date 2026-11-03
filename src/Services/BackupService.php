<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * แบ็คอัพและกู้คืนข้อมูล (admin): three kinds, each for a period of the data or all of it.
 *  - images:   picture files in storage/uploads (product photos, receipt photos ...) → .zip
 *  - files:    the other files in storage/uploads (receipt text / PDF ...) → .zip
 *  - database: the system's tables → .sql.gz. "All" holds every table whole (a restore puts the
 *              database back exactly as it was); a period holds the rows created / changed in it
 *              (plus small tables with no dates), and a restore puts those rows back over what is
 *              there now without deleting anything else.
 * Files are picked by the time they were last written. Each backup sits in storage/backups/
 * beside a .json description, so the list survives a restore of the database itself.
 */
class BackupService
{
    public const DIR = 'storage/backups';
    public const KINDS = ['images' => 'รูปภาพ', 'database' => 'ฐานข้อมูล', 'files' => 'ไฟล์อื่น ๆ'];
    public const RANGES = [
        'all' => 'ทั้งหมด', 'today' => 'วันนี้', 'yesterday' => 'เมื่อวาน', 'this_week' => 'สัปดาห์นี้',
        'last_week' => 'สัปดาห์ก่อน', 'this_month' => 'เดือนนี้', 'last_month' => 'เดือนที่แล้ว',
    ];
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'];
    private const ROWS_PER_INSERT = 200;

    public function __construct(private PDO $db)
    {
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function dir(): string
    {
        $dir = self::root() . '/' . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('สร้างโฟลเดอร์ ' . self::DIR . ' ไม่ได้');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        return $dir;
    }

    private static function uploads(): string
    {
        return self::root() . '/storage/uploads';
    }

    /**
     * First and last moment of a period (weeks start on Monday); null for "all".
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}|null
     */
    public static function period(string $range): ?array
    {
        $today = new \DateTimeImmutable('today');
        [$from, $to] = match ($range) {
            'all' => [null, null],
            'today' => [$today, $today],
            'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day')],
            'this_week' => [$today->modify('monday this week'), $today],
            'last_week' => [$today->modify('monday last week'), $today->modify('sunday last week')],
            'this_month' => [$today->modify('first day of this month'), $today],
            'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
            default => throw new \RuntimeException('ช่วงเวลาไม่ถูกต้อง'),
        };
        return $from ? [$from, $to->setTime(23, 59, 59)] : null;
    }

    /** Backups on disk, newest first. */
    public function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $meta) {
            $m = json_decode((string) file_get_contents($meta), true);
            if (!is_array($m) || !is_file(self::dir() . '/' . ($m['file'] ?? ''))) {
                continue;
            }
            $m['size'] = (int) filesize(self::dir() . '/' . $m['file']);
            $out[] = $m;
        }
        usort($out, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $out;
    }

    public function find(string $id): array
    {
        if (!preg_match('/^[a-z0-9_-]{8,80}$/', $id) || !is_file(self::dir() . "/{$id}.json")) {
            throw new \RuntimeException('ไม่พบไฟล์แบ็คอัพ');
        }
        $m = json_decode((string) file_get_contents(self::dir() . "/{$id}.json"), true);
        if (!is_array($m) || !is_file(self::dir() . '/' . $m['file'])) {
            throw new \RuntimeException('ไฟล์แบ็คอัพหายไปหรือเสียหาย');
        }
        return $m;
    }

    public function path(array $m): string
    {
        return self::dir() . '/' . $m['file'];
    }

    /** Makes a backup of one kind for a period; returns its description. */
    public function create(string $kind, string $range, array $user, string $note = ''): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \RuntimeException('ประเภทแบ็คอัพไม่ถูกต้อง');
        }
        $period = self::period($range);
        @set_time_limit(0);
        $id = date('Ymd-His') . "-{$kind}-{$range}-" . bin2hex(random_bytes(3));
        $file = $id . ($kind === 'database' ? '.sql.gz' : '.zip');
        $path = self::dir() . '/' . $file;
        try {
            $stats = $kind === 'database' ? $this->dumpDatabase($path, $period) : $this->zipFiles($path, $kind === 'images', $period);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException('แบ็คอัพไม่สำเร็จ: ' . $e->getMessage());
        }
        $m = [
            'id' => $id, 'file' => $file, 'kind' => $kind, 'range' => $range,
            'from' => $period ? $period[0]->format('Y-m-d') : null, 'to' => $period ? $period[1]->format('Y-m-d') : null,
            'items' => $stats['items'], 'tables' => $stats['tables'] ?? null,
            'note' => mb_substr(trim($note), 0, 200),
            'created_at' => date('Y-m-d H:i:s'), 'created_by' => $user['name'] ?? $user['username'] ?? (string) ($user['id'] ?? ''),
        ];
        file_put_contents(self::dir() . "/{$id}.json", json_encode($m, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $m['size'] = (int) filesize($path);
        return $m;
    }

    public function delete(string $id): array
    {
        $m = $this->find($id);
        @unlink($this->path($m));
        @unlink(self::dir() . "/{$id}.json");
        return $m;
    }

    /**
     * Puts a backup back. Files: those missing now are written back; with $overwrite also the
     * ones that differ. Database: a full backup replaces every table it holds; a period backup
     * writes its rows over the current ones. A full database backup is made first.
     *
     * @return array{items:int, skipped?:int, safety?:array}
     */
    public function restore(string $id, bool $overwrite, array $user): array
    {
        $m = $this->find($id);
        @set_time_limit(0);
        if ($m['kind'] !== 'database') {
            return $this->unzipFiles($this->path($m), $overwrite);
        }
        $safety = $this->create('database', 'all', $user, 'สำรองอัตโนมัติ ก่อนกู้คืน ' . self::KINDS['database'] . ' ' . self::RANGES[$m['range']] . ' (' . $m['created_at'] . ')');
        $count = $this->runSql($this->path($m));
        return ['items' => $count, 'safety' => $safety];
    }

    // ---- files ----

    /** @return array{items:int} */
    private function zipFiles(string $path, bool $images, ?array $period): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('เซิร์ฟเวอร์ไม่มีส่วนขยาย zip ของ PHP');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('สร้างไฟล์แบ็คอัพไม่ได้');
        }
        $base = self::uploads();
        $n = 0;
        if (is_dir($base)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || $f->isLink() || $f->getFilename() === '.htaccess') {
                    continue;
                }
                $isImage = in_array(strtolower($f->getExtension()), self::IMAGE_EXT, true);
                if ($isImage !== $images) {
                    continue;
                }
                if ($period && ($f->getMTime() < $period[0]->getTimestamp() || $f->getMTime() > $period[1]->getTimestamp())) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
                $zip->addFile($f->getPathname(), $rel);
                // Pictures are compressed already: storing them saves the time of a second try.
                if ($images) {
                    $zip->setCompressionName($rel, \ZipArchive::CM_STORE);
                }
                $n++;
            }
        }
        $zip->setArchiveComment('rvc.coac backup: storage/uploads');
        if ($n === 0) {
            $zip->addFromString('.empty', '');
        }
        $zip->close();
        return ['items' => $n];
    }

    /** @return array{items:int, skipped:int} */
    private function unzipFiles(string $path, bool $overwrite): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('เปิดไฟล์แบ็คอัพไม่ได้ (ไฟล์อาจเสียหาย)');
        }
        $base = self::uploads();
        $written = 0;
        $skipped = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            // Only plain relative paths inside storage/uploads, never a PHP file.
            if ($name === '.empty' || str_ends_with($name, '/') || str_contains($name, '..') || str_contains($name, '\\')
                || str_starts_with($name, '/') || preg_match('/\.(php\d?|phtml|phar|htaccess|user\.ini)$/i', $name)
                || !preg_match('#^[A-Za-z0-9_./-]+$#', $name)) {
                $skipped++;
                continue;
            }
            $target = $base . '/' . $name;
            $stat = $zip->statIndex($i);
            if (is_file($target) && (!$overwrite || filesize($target) === (int) $stat['size'])) {
                $skipped++;
                continue;
            }
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0775, true);
            }
            $data = $zip->getFromIndex($i);
            if ($data === false || file_put_contents($target, $data) === false) {
                $zip->close();
                throw new \RuntimeException("เขียนไฟล์ {$name} ไม่ได้");
            }
            if (!empty($stat['mtime'])) {
                @touch($target, (int) $stat['mtime']);
            }
            $written++;
        }
        $zip->close();
        return ['items' => $written, 'skipped' => $skipped];
    }

    // ---- database ----

    /** @return array{items:int, tables:int} */
    private function dumpDatabase(string $path, ?array $period): array
    {
        $gz = gzopen($path, 'wb6');
        if (!$gz) {
            throw new \RuntimeException('สร้างไฟล์แบ็คอัพไม่ได้');
        }
        $full = $period === null;
        $w = fn (string $line) => gzwrite($gz, $line . "\n");
        $w('-- rvc.coac database backup ' . ($full ? 'FULL' : 'PERIOD ' . $period[0]->format('Y-m-d') . ' .. ' . $period[1]->format('Y-m-d')) . ' ' . date('c'));
        $w('-- one statement per line');
        $w('SET FOREIGN_KEY_CHECKS=0');
        $w("SET NAMES utf8mb4");
        $tables = $this->db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        $rows = 0;
        foreach ($tables as $table) {
            $q = '`' . str_replace('`', '``', (string) $table) . '`';
            $cols = $this->db->query("SHOW COLUMNS FROM $q")->fetchAll(PDO::FETCH_COLUMN);
            $where = '';
            $params = [];
            if ($full) {
                $create = (string) $this->db->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM)[1];
                $w("DROP TABLE IF EXISTS $q");
                $w(preg_replace('/\s*\R\s*/', ' ', $create));
            } else {
                // Rows created or changed in the period; tables without dates go whole (lookups, settings).
                $dated = array_values(array_intersect(['created_at', 'updated_at'], $cols));
                if ($dated) {
                    $where = ' WHERE ' . implode(' OR ', array_map(fn ($c) => "(`$c` BETWEEN ? AND ?)", $dated));
                    foreach ($dated as $_) {
                        $params[] = $period[0]->format('Y-m-d H:i:s');
                        $params[] = $period[1]->format('Y-m-d H:i:s');
                    }
                }
            }
            $colList = implode(', ', array_map(fn ($c) => '`' . str_replace('`', '``', (string) $c) . '`', $cols));
            $upsert = $full ? '' : ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn ($c) => "`$c` = VALUES(`$c`)", $cols));
            $stmt = $this->db->prepare("SELECT * FROM $q$where");
            $stmt->execute($params);
            $batch = [];
            while (($r = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                // PDO::quote escapes line breaks, so each statement stays on one line.
                $batch[] = '(' . implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $this->db->quote((string) $v), $r)) . ')';
                $rows++;
                if (count($batch) >= self::ROWS_PER_INSERT) {
                    $w("INSERT INTO $q ($colList) VALUES " . implode(', ', $batch) . $upsert);
                    $batch = [];
                }
            }
            if ($batch) {
                $w("INSERT INTO $q ($colList) VALUES " . implode(', ', $batch) . $upsert);
            }
        }
        $w('SET FOREIGN_KEY_CHECKS=1');
        $w('-- end');
        gzclose($gz);
        return ['items' => $rows, 'tables' => count($tables)];
    }

    /** Runs a backup made by dumpDatabase(); returns the number of statements run. */
    private function runSql(string $path): int
    {
        $gz = gzopen($path, 'rb');
        if (!$gz) {
            throw new \RuntimeException('เปิดไฟล์แบ็คอัพไม่ได้');
        }
        $first = (string) gzgets($gz);
        if (!str_starts_with($first, '-- rvc.coac database backup')) {
            gzclose($gz);
            throw new \RuntimeException('ไฟล์นี้ไม่ใช่แบ็คอัพฐานข้อมูลของระบบนี้');
        }
        $ended = false;
        $n = 0;
        try {
            while (($line = gzgets($gz)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '-- end') {
                    $ended = true;
                }
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                $this->db->exec($line);
                $n++;
            }
        } catch (\PDOException $e) {
            $this->db->exec('SET FOREIGN_KEY_CHECKS=1');
            throw new \RuntimeException('กู้คืนฐานข้อมูลไม่สำเร็จที่คำสั่งที่ ' . ($n + 1) . ': ' . $e->getMessage() . ' — ใช้แบ็คอัพอัตโนมัติก่อนกู้คืนเพื่อย้อนกลับได้');
        } finally {
            gzclose($gz);
        }
        if (!$ended) {
            throw new \RuntimeException('ไฟล์แบ็คอัพไม่ครบ (ถูกตัดกลางทาง) กู้คืนได้เพียงบางส่วน — ใช้แบ็คอัพอัตโนมัติก่อนกู้คืนเพื่อย้อนกลับได้');
        }
        return $n;
    }
}
