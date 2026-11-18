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
 *  - bundle:   the whole set as one .zip holding two files: database.sql (the database as SQL
 *              text, the same as the database kind) and files.zip (every file in storage/uploads:
 *              pictures and the other files), plus manifest.json. A restore puts back both parts or
 *              either one; each part can also be downloaded on its own.
 * Files are picked by the time they were last written. Each backup sits in storage/backups/
 * beside a .json description, so the list survives a restore of the database itself.
 * A backup file kept elsewhere comes back by upload (import): a whole bundle .zip, or one part of
 * it (database.sql / a .sql.gz database backup, or a .zip of uploaded files); it is then listed
 * here and put back like any other.
 */
class BackupService
{
    public const DIR = 'storage/backups';
    public const KINDS = [
        'bundle' => 'ชุดสำรองครบ (ข้อมูล + รูปภาพ/ไฟล์)', 'images' => 'รูปภาพ', 'database' => 'ฐานข้อมูล', 'files' => 'ไฟล์อื่น ๆ',
        'uploads' => 'รูปภาพและไฟล์แนบ',
    ];
    /** Kinds made on this page ('uploads' only comes from an imported .zip of files). */
    public const CREATE_KINDS = ['bundle', 'database', 'images', 'files'];
    /** The two parts of a bundle. */
    public const PARTS = ['database' => 'database.sql', 'files' => 'files.zip'];
    /** Imports arrive in pieces of this size (under the usual PHP post limit). */
    public const CHUNK = 1048576;
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
        if (!in_array($kind, self::CREATE_KINDS, true)) {
            throw new \RuntimeException('ประเภทแบ็คอัพไม่ถูกต้อง');
        }
        $period = self::period($range);
        @set_time_limit(0);
        $id = date('Ymd-His') . "-{$kind}-{$range}-" . bin2hex(random_bytes(3));
        $file = $id . ($kind === 'database' ? '.sql.gz' : '.zip');
        $path = self::dir() . '/' . $file;
        try {
            $stats = match ($kind) {
                'database' => $this->dumpDatabase($path, $period),
                'bundle' => $this->makeBundle($path, $period, $range),
                default => $this->zipFiles($path, $kind === 'images', $period),
            };
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException('แบ็คอัพไม่สำเร็จ: ' . $e->getMessage());
        }
        $m = [
            'id' => $id, 'file' => $file, 'kind' => $kind, 'range' => $range,
            'from' => $period ? $period[0]->format('Y-m-d') : null, 'to' => $period ? $period[1]->format('Y-m-d') : null,
            'items' => $stats['items'], 'tables' => $stats['tables'] ?? null, 'parts' => $stats['parts'] ?? null,
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
    public function restore(string $id, bool $overwrite, array $user, ?array $parts = null): array
    {
        $m = $this->find($id);
        @set_time_limit(0);
        if ($m['kind'] === 'bundle') {
            return $this->restoreBundle($m, $overwrite, $user, $parts ?: array_keys(self::PARTS));
        }
        if ($m['kind'] !== 'database') {
            return $this->unzipFiles($this->path($m), $overwrite);
        }
        $safety = $this->safetyBackup($m, $user);
        $count = $this->runSql($this->path($m));
        return ['items' => $count, 'safety' => $safety];
    }

    /** A full database backup made right before a restore, so it can be undone. */
    private function safetyBackup(array $m, array $user): array
    {
        $what = (self::KINDS[$m['kind']] ?? $m['kind']) . ' ' . (self::RANGES[$m['range']] ?? ($m['from'] ? "{$m['from']} – {$m['to']}" : ''));
        return $this->create('database', 'all', $user, 'สำรองอัตโนมัติ ก่อนกู้คืน ' . trim($what) . ' (' . $m['created_at'] . ')');
    }

    // ---- bundle: database.sql + files.zip in one .zip ----

    /** @return array{items:int, tables:int, parts:array} */
    private function makeBundle(string $path, ?array $period, string $range): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('เซิร์ฟเวอร์ไม่มีส่วนขยาย zip ของ PHP');
        }
        $tmp = $this->tmpDir();
        try {
            $db = $this->dumpDatabase("{$tmp}/" . self::PARTS['database'], $period, true);
            $files = $this->zipFiles("{$tmp}/" . self::PARTS['files'], null, $period);
            $parts = [
                'database' => ['file' => self::PARTS['database'], 'rows' => $db['items'], 'tables' => $db['tables'], 'size' => (int) filesize("{$tmp}/" . self::PARTS['database'])],
                'files' => ['file' => self::PARTS['files'], 'items' => $files['items'], 'size' => (int) filesize("{$tmp}/" . self::PARTS['files'])],
            ];
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('สร้างไฟล์แบ็คอัพไม่ได้');
            }
            $zip->addFromString('manifest.json', json_encode([
                'app' => 'rvc.coac', 'type' => 'bundle', 'created_at' => date('c'), 'range' => $range,
                'from' => $period ? $period[0]->format('Y-m-d') : null, 'to' => $period ? $period[1]->format('Y-m-d') : null,
                'parts' => $parts,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $zip->addFile("{$tmp}/" . self::PARTS['database'], self::PARTS['database']);
            $zip->addFile("{$tmp}/" . self::PARTS['files'], self::PARTS['files']);
            // files.zip is compressed already.
            $zip->setCompressionName(self::PARTS['files'], \ZipArchive::CM_STORE);
            $zip->setArchiveComment('rvc.coac backup: bundle (database.sql + files.zip)');
            if (!$zip->close()) {
                throw new \RuntimeException('บันทึกไฟล์แบ็คอัพไม่สำเร็จ (พื้นที่ดิสก์อาจไม่พอ)');
            }
        } finally {
            $this->removeDir($tmp);
        }
        return ['items' => $db['items'] + $files['items'], 'tables' => $db['tables'], 'parts' => $parts];
    }

    /** Which parts a bundle .zip holds. @return array<int,string> */
    private function bundleParts(\ZipArchive $zip): array
    {
        return array_values(array_filter(array_keys(self::PARTS), fn ($k) => $zip->locateName(self::PARTS[$k]) !== false));
    }

    /**
     * Takes one part out of a bundle into a new temporary folder.
     * @return array{0:string,1:string} [the part's path, the temporary folder to remove afterwards]
     */
    public function extractPart(array $m, string $part): array
    {
        if ($m['kind'] !== 'bundle' || !isset(self::PARTS[$part])) {
            throw new \RuntimeException('ไม่มีส่วนนี้ในแบ็คอัพ');
        }
        $zip = new \ZipArchive();
        if ($zip->open($this->path($m)) !== true) {
            throw new \RuntimeException('เปิดไฟล์แบ็คอัพไม่ได้ (ไฟล์อาจเสียหาย)');
        }
        if ($zip->locateName(self::PARTS[$part]) === false) {
            $zip->close();
            throw new \RuntimeException('แบ็คอัพชุดนี้ไม่มี' . ($part === 'database' ? 'ไฟล์ข้อมูล (database.sql)' : 'ไฟล์รูปภาพ/ไฟล์แนบ (files.zip)'));
        }
        $tmp = $this->tmpDir();
        $ok = $zip->extractTo($tmp, self::PARTS[$part]);
        $zip->close();
        if (!$ok || !is_file("{$tmp}/" . self::PARTS[$part])) {
            $this->removeDir($tmp);
            throw new \RuntimeException('แตกไฟล์แบ็คอัพไม่ได้ (พื้นที่ดิสก์อาจไม่พอ)');
        }
        return ["{$tmp}/" . self::PARTS[$part], $tmp];
    }

    public function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private function tmpDir(): string
    {
        $dir = self::dir() . '/tmp-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0775, true)) {
            throw new \RuntimeException('สร้างโฟลเดอร์ชั่วคราวไม่ได้');
        }
        return $dir;
    }

    /** @param array<int,string> $parts 'database' and/or 'files' */
    private function restoreBundle(array $m, bool $overwrite, array $user, array $parts): array
    {
        $parts = array_values(array_intersect(array_keys(self::PARTS), $parts));
        if (!$parts) {
            throw new \RuntimeException('เลือกส่วนที่จะกู้คืนอย่างน้อย 1 ส่วน');
        }
        $out = ['items' => 0, 'parts' => []];
        // The database first: if it fails, the files stay as they are.
        foreach ($parts as $part) {
            [$file, $tmp] = $this->extractPart($m, $part);
            try {
                if ($part === 'database') {
                    $out['safety'] = $this->safetyBackup($m, $user);
                    $n = $this->runSql($file);
                    $out['parts']['database'] = ['items' => $n];
                } else {
                    $r = $this->unzipFiles($file, $overwrite);
                    $out['parts']['files'] = $r;
                    $out['skipped'] = $r['skipped'];
                    $n = $r['items'];
                }
                $out['items'] += $n;
            } finally {
                $this->removeDir($tmp);
            }
        }
        return $out;
    }

    // ---- import: a backup file brought back by upload ----

    /** Stores one piece of an upload; returns the bytes received so far. */
    public function importChunk(string $uploadId, int $index, string $data): int
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw new \RuntimeException('รหัสการอัปโหลดไม่ถูกต้อง');
        }
        if ($data === '' || strlen($data) > self::CHUNK) {
            throw new \RuntimeException('ข้อมูลไฟล์ไม่ถูกต้อง');
        }
        $part = self::dir() . "/upload-{$uploadId}.part";
        $have = is_file($part) ? (int) filesize($part) : 0;
        if ($index === 0) {
            $this->clearStaleUploads();
            $have = 0;
            @unlink($part);
        } elseif ($have !== $index * self::CHUNK) {
            throw new \RuntimeException('การอัปโหลดขาดตอน กรุณาเลือกไฟล์แล้วอัปโหลดใหม่');
        }
        $free = @disk_free_space(self::dir());
        if ($free !== false && $free < strlen($data) + 50 * 1048576) {
            @unlink($part);
            throw new \RuntimeException('พื้นที่ดิสก์บนเซิร์ฟเวอร์ไม่พอ');
        }
        if (file_put_contents($part, $data, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('บันทึกไฟล์ไม่ได้ (ตรวจสิทธิ์เขียนโฟลเดอร์ ' . self::DIR . ')');
        }
        clearstatcache(true, $part);
        return (int) filesize($part);
    }

    /**
     * Checks an uploaded file and lists it as a backup: a bundle .zip (database.sql and/or
     * files.zip), a database backup (.sql text or .sql.gz) or a .zip of uploaded files.
     */
    public function importFinish(string $uploadId, int $size, string $name, array $user): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw new \RuntimeException('รหัสการอัปโหลดไม่ถูกต้อง');
        }
        $part = self::dir() . "/upload-{$uploadId}.part";
        if (!is_file($part) || filesize($part) !== $size || $size <= 0) {
            @unlink($part);
            throw new \RuntimeException('ได้รับไฟล์ไม่ครบ กรุณาอัปโหลดใหม่');
        }
        try {
            $info = $this->identify($part);
        } catch (\Throwable $e) {
            @unlink($part);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException('ตรวจไฟล์ไม่สำเร็จ: ' . $e->getMessage());
        }
        $id = date('Ymd-His') . "-import-{$info['kind']}-" . bin2hex(random_bytes(3));
        $file = $id . $info['ext'];
        if (!@rename($part, self::dir() . '/' . $file)) {
            @unlink($part);
            throw new \RuntimeException('บันทึกไฟล์ที่นำเข้าไม่ได้');
        }
        $m = [
            'id' => $id, 'file' => $file, 'kind' => $info['kind'], 'range' => $info['range'],
            'from' => $info['from'], 'to' => $info['to'], 'items' => $info['items'], 'tables' => $info['tables'] ?? null,
            'parts' => $info['parts'] ?? null, 'imported' => true, 'source_name' => mb_substr(basename($name), 0, 150),
            'note' => 'นำเข้าจากไฟล์ ' . mb_substr(basename($name), 0, 150),
            'created_at' => date('Y-m-d H:i:s'), 'created_by' => $user['name'] ?? $user['full_name'] ?? $user['username'] ?? (string) ($user['id'] ?? ''),
        ];
        file_put_contents(self::dir() . "/{$id}.json", json_encode($m, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $m['size'] = $size;
        return $m;
    }

    /** What an uploaded file is. @return array{kind:string, ext:string, range:string, from:?string, to:?string, items:?int} */
    private function identify(string $path): array
    {
        $fh = fopen($path, 'rb');
        $magic = (string) fread($fh, 4);
        fclose($fh);
        if (str_starts_with($magic, "\x1f\x8b") || str_starts_with($magic, '--')) {
            $gz = gzopen($path, 'rb'); // reads plain text too
            $head = (string) gzgets($gz);
            gzclose($gz);
            return ['kind' => 'database', 'ext' => str_starts_with($magic, '--') ? '.sql' : '.sql.gz'] + $this->sqlRange($head);
        }
        if (!str_starts_with($magic, "PK\x03\x04") && !str_starts_with($magic, "PK\x05\x06")) {
            throw new \RuntimeException('ไฟล์นี้ไม่ใช่แบ็คอัพของระบบ (รับ .zip, .sql หรือ .sql.gz)');
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('เซิร์ฟเวอร์ไม่มีส่วนขยาย zip ของ PHP');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('เปิดไฟล์ .zip ไม่ได้ (ไฟล์อาจเสียหาย)');
        }
        try {
            $parts = $this->bundleParts($zip);
            if (!$parts) {
                // A .zip of uploaded files (the files part on its own, or an images / files backup).
                return ['kind' => 'uploads', 'ext' => '.zip', 'range' => 'all', 'from' => null, 'to' => null,
                    'items' => $zip->locateName('.empty') !== false ? $zip->numFiles - 1 : $zip->numFiles];
            }
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true) ?: [];
            $range = ['range' => 'all', 'from' => null, 'to' => null];
            $info = ['parts' => []];
            if (in_array('database', $parts, true)) {
                $stream = $zip->getStream(self::PARTS['database']);
                $head = (string) fgets($stream);
                fclose($stream);
                $range = $this->sqlRange($head);
                $info['parts']['database'] = $manifest['parts']['database'] ?? ['file' => self::PARTS['database']];
            }
            if (in_array('files', $parts, true)) {
                $info['parts']['files'] = $manifest['parts']['files'] ?? ['file' => self::PARTS['files']];
            }
            if (!in_array('database', $parts, true) && isset($manifest['range']) && isset(self::RANGES[$manifest['range']])) {
                $range = ['range' => $manifest['range'], 'from' => $manifest['from'] ?? null, 'to' => $manifest['to'] ?? null];
            }
            $items = (int) ($info['parts']['database']['rows'] ?? 0) + (int) ($info['parts']['files']['items'] ?? 0);
            return ['kind' => 'bundle', 'ext' => '.zip', 'items' => $items ?: null,
                'tables' => $info['parts']['database']['tables'] ?? null, 'parts' => $info['parts']] + $range;
        } finally {
            $zip->close();
        }
    }

    /** The period a database backup holds, from its first line. */
    private function sqlRange(string $head): array
    {
        if (!str_starts_with($head, '-- rvc.coac database backup')) {
            throw new \RuntimeException('ไฟล์ข้อมูลนี้ไม่ใช่แบ็คอัพฐานข้อมูลของระบบนี้');
        }
        if (preg_match('/PERIOD (\d{4}-\d{2}-\d{2}) \.\. (\d{4}-\d{2}-\d{2})/', $head, $mm)) {
            return ['range' => 'custom', 'from' => $mm[1], 'to' => $mm[2], 'items' => null];
        }
        return ['range' => 'all', 'from' => null, 'to' => null, 'items' => null];
    }

    /** Uploads left half-way more than a day ago. */
    private function clearStaleUploads(): void
    {
        foreach (glob(self::dir() . '/upload-*.part') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        foreach (glob(self::dir() . '/tmp-*', GLOB_ONLYDIR) ?: [] as $d) {
            if (filemtime($d) < time() - 86400) {
                $this->removeDir($d);
            }
        }
    }

    // ---- files ----

    /** @return array{items:int} */
    private function zipFiles(string $path, ?bool $images, ?array $period): array
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
                if ($images !== null && $isImage !== $images) {
                    continue;
                }
                if ($period && ($f->getMTime() < $period[0]->getTimestamp() || $f->getMTime() > $period[1]->getTimestamp())) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
                $zip->addFile($f->getPathname(), $rel);
                // Pictures are compressed already: storing them saves the time of a second try.
                if ($isImage) {
                    $zip->setCompressionName($rel, \ZipArchive::CM_STORE);
                }
                $n++;
            }
        }
        $zip->setArchiveComment('rvc.coac backup: storage/uploads');
        if ($n === 0) {
            $zip->addFromString('.empty', '');
        }
        if (!$zip->close()) {
            throw new \RuntimeException('บันทึกไฟล์แบ็คอัพไม่สำเร็จ (พื้นที่ดิสก์อาจไม่พอ)');
        }
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

    /**
     * $plain: a text file (database.sql of a bundle) instead of .sql.gz.
     * @return array{items:int, tables:int}
     */
    private function dumpDatabase(string $path, ?array $period, bool $plain = false): array
    {
        $gz = $plain ? fopen($path, 'wb') : gzopen($path, 'wb6');
        if (!$gz) {
            throw new \RuntimeException('สร้างไฟล์แบ็คอัพไม่ได้');
        }
        $full = $period === null;
        $w = $plain
            ? function (string $line) use ($gz) {
                if (fwrite($gz, $line . "\n") === false) {
                    throw new \RuntimeException('เขียนไฟล์แบ็คอัพไม่ได้ (พื้นที่ดิสก์อาจไม่พอ)');
                }
            }
            : fn (string $line) => gzwrite($gz, $line . "\n");
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
        $plain ? fclose($gz) : gzclose($gz);
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
