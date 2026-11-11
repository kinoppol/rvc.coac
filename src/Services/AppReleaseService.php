<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The Android POS app (.apk) the admin uploads on the อุปกรณ์ POS page, for staff to download
 * and install. Kept in storage/app-releases (closed to the web): the APK files and latest.json
 * describing the current one. The upload comes in chunks so PHP's upload size limit (often a
 * few MB) does not stop a 20–50 MB APK. The two releases before the current one are kept.
 * $kind 'mobile' is the mobile app for everyone who is not selling, kept apart in storage/app-releases/mobile.
 */
class AppReleaseService
{
    public const DIR = 'storage/app-releases';
    public const CHUNK = 1048576; // the page sends 1 MB at a time
    private const MAX_BYTES = 200 * 1048576;
    private const KEEP = 3;

    public const KINDS = ['pos', 'mobile'];

    public function __construct(private string $root = '', private string $kind = 'pos')
    {
        if (!in_array($this->kind, self::KINDS, true)) {
            throw new \RuntimeException('ชนิดแอปไม่ถูกต้อง');
        }
        $this->root = $root !== '' ? $root : dirname(__DIR__, 2);
    }

    /** @return array{version:string, version_code:?int, file:string, size:int, sha256:string, note:string, uploaded_at:string, uploaded_by:string}|null */
    public function latest(): ?array
    {
        $meta = @file_get_contents($this->dir() . '/latest.json');
        $m = $meta !== false ? json_decode($meta, true) : null;
        return is_array($m) && isset($m['file']) && is_file($this->dir() . '/' . basename((string) $m['file'])) ? $m : null;
    }

    public function path(array $release): string
    {
        return $this->dir() . '/' . basename((string) $release['file']);
    }

    /** Appends chunk $index (0, 1, 2 … in order) of upload $id. */
    public function chunk(string $id, int $index, string $data): int
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new \RuntimeException('รหัสการอัปโหลดไม่ถูกต้อง');
        }
        if ($data === '' || strlen($data) > self::CHUNK) {
            throw new \RuntimeException('ข้อมูลไฟล์ไม่ถูกต้อง');
        }
        $part = $this->dir() . "/upload-{$id}.part";
        $have = is_file($part) ? (int) filesize($part) : 0;
        if ($index === 0) {
            $this->clearStaleParts();
            $have = 0;
            @unlink($part);
        } elseif ($have !== $index * self::CHUNK) {
            throw new \RuntimeException('การอัปโหลดขาดตอน กรุณาเลือกไฟล์แล้วอัปโหลดใหม่');
        }
        if ($have + strlen($data) > self::MAX_BYTES) {
            @unlink($part);
            throw new \RuntimeException('ไฟล์ใหญ่เกิน 200 MB');
        }
        if (file_put_contents($part, $data, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('บันทึกไฟล์ไม่ได้ (ตรวจสิทธิ์เขียนโฟลเดอร์ ' . self::DIR . ')');
        }
        clearstatcache(true, $part);
        return (int) filesize($part);
    }

    /** Checks the uploaded file is an Android app and makes it the current release. */
    public function finish(string $id, int $size, string $version, ?int $versionCode, string $note, string $by): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new \RuntimeException('รหัสการอัปโหลดไม่ถูกต้อง');
        }
        $part = $this->dir() . "/upload-{$id}.part";
        if (!is_file($part) || (int) filesize($part) !== $size) {
            @unlink($part);
            throw new \RuntimeException('ได้รับไฟล์ไม่ครบ กรุณาอัปโหลดใหม่');
        }
        $version = trim($version);
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z.\-+_]{0,29}$/', $version)) {
            @unlink($part);
            throw new \RuntimeException('กรุณาระบุเวอร์ชันของแอป เช่น 1.0.0');
        }
        try {
            $this->assertApk($part);
        } catch (\RuntimeException $e) {
            @unlink($part);
            throw $e;
        }
        $file = 'rvc-coac-' . $this->kind . '-' . preg_replace('/[^0-9A-Za-z.\-]/', '-', $version) . '-' . date('YmdHis') . '.apk';
        if (!rename($part, $this->dir() . '/' . $file)) {
            @unlink($part);
            throw new \RuntimeException('บันทึกไฟล์ไม่ได้');
        }
        $release = [
            'version' => $version, 'version_code' => $versionCode, 'file' => $file, 'size' => $size,
            'sha256' => hash_file('sha256', $this->dir() . '/' . $file), 'note' => mb_substr(trim($note), 0, 500),
            'uploaded_at' => date('Y-m-d H:i:s'), 'uploaded_by' => $by,
        ];
        file_put_contents($this->dir() . '/latest.json', json_encode($release, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        $this->prune($file);
        return $release;
    }

    /** An APK is a ZIP holding AndroidManifest.xml and compiled code (classes.dex). */
    private function assertApk(string $path): void
    {
        $fh = fopen($path, 'rb');
        $magic = $fh ? (string) fread($fh, 4) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($magic !== "PK\x03\x04") {
            throw new \RuntimeException('ไฟล์นี้ไม่ใช่ไฟล์ติดตั้งแอป Android (.apk)');
        }
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new \RuntimeException('เปิดไฟล์ .apk ไม่ได้ ไฟล์อาจเสียหาย');
            }
            $ok = $zip->locateName('AndroidManifest.xml') !== false && $zip->locateName('classes.dex') !== false;
            $zip->close();
            if (!$ok) {
                throw new \RuntimeException('ไฟล์นี้ไม่ใช่ไฟล์ติดตั้งแอป Android (.apk) — ไม่พบ AndroidManifest.xml');
            }
        }
    }

    /** Keeps the newest KEEP APKs. */
    private function prune(string $current): void
    {
        $files = glob($this->dir() . '/rvc-coac-' . $this->kind . '-*.apk') ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, self::KEEP) as $old) {
            if (basename($old) !== $current) {
                @unlink($old);
            }
        }
    }

    /** Uploads left half-way more than a day ago. */
    private function clearStaleParts(): void
    {
        foreach (glob($this->dir() . '/upload-*.part') ?: [] as $p) {
            if (filemtime($p) < time() - 86400) {
                @unlink($p);
            }
        }
    }

    private function dir(): string
    {
        $dir = $this->root . '/' . self::DIR . ($this->kind === 'pos' ? '' : '/' . $this->kind);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        return $dir;
    }
}
