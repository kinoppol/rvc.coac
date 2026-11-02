<?php

declare(strict_types=1);

namespace App\Services;

/**
 * กักกันไฟล์ (Settings > ตรวจสอบความปลอดภัย): a suspicious file is moved out of its place into
 * storage/quarantine/ under a name without an extension, so it can no longer be run or served,
 * but nothing is lost — the admin can look inside it, put it back, or delete it for good.
 * index.json keeps where each file came from, its fingerprint and who moved it, and why.
 */
final class QuarantineService
{
    public const DIR = 'storage/quarantine';
    private const PREVIEW_BYTES = 200_000;
    private const IMAGE_BYTES = 4_000_000;
    // Never moved: the database settings, the quarantine itself and files the scan needs.
    private const LOCKED = ['#^config/config\.php$#', '#^storage/quarantine(/|$)#', '#^storage/sessions(/|$)#', '#^storage/security-baseline\.json$#', '#^storage/installed\.lock$#'];
    // Moving these takes the system (or part of it) down until they are restored.
    private const SYSTEM = ['#^src/#', '#^vendor/#', '#^migrations/#', '#^bin/#', '#^public/(index|install)\.php$#', '#^index\.php$#', '#^public/assets/#', '#^public/app\.html$#', '#(^|/)\.htaccess$#'];

    private string $root;
    private string $dir;

    public function __construct()
    {
        $this->root = str_replace('\\', '/', dirname(__DIR__, 2));
        $this->dir = $this->root . '/' . self::DIR;
    }

    public static function isSystemFile(string $rel): bool
    {
        foreach (self::SYSTEM as $re) {
            if (preg_match($re, $rel) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, array> newest first */
    public function list(): array
    {
        $rows = array_values($this->index());
        usort($rows, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        return array_map(fn ($r) => $r + ['present' => is_file($this->dir . '/' . $r['id'])], $rows);
    }

    /**
     * Moves each file into quarantine. Files that cannot be moved are reported, not fatal.
     *
     * @return array{moved: array<int, array>, failed: array<int, array{path: string, error: string}>}
     */
    public function quarantine(array $paths, string $reason, int $userId): array
    {
        $this->ensureDir();
        $index = $this->index();
        $moved = $failed = [];
        foreach (array_unique(array_map('strval', $paths)) as $rel) {
            try {
                $abs = $this->resolve($rel);
                $rel = $this->rel($abs);
                $id = date('YmdHis') . '-' . bin2hex(random_bytes(4));
                $meta = [
                    'id' => $id,
                    'path' => $rel,
                    'size' => (int) filesize($abs),
                    'sha256' => (string) hash_file('sha256', $abs),
                    'modified' => date('Y-m-d H:i:s', (int) filemtime($abs)),
                    'reason' => mb_substr(trim($reason), 0, 300),
                    'by' => $userId,
                    'at' => date('Y-m-d H:i:s'),
                    'system' => self::isSystemFile($rel),
                ];
                if (!@rename($abs, $this->dir . '/' . $id)) {
                    if (!@copy($abs, $this->dir . '/' . $id) || !@unlink($abs)) {
                        @unlink($this->dir . '/' . $id);
                        throw new \RuntimeException('ย้ายไฟล์ไม่ได้ (สิทธิ์การเขียนไฟล์)');
                    }
                }
                @chmod($this->dir . '/' . $id, 0600);
                $index[$id] = $meta;
                $moved[] = $meta;
            } catch (\RuntimeException $e) {
                $failed[] = ['path' => $rel, 'error' => $e->getMessage()];
            }
        }
        $this->saveIndex($index);
        return ['moved' => $moved, 'failed' => $failed];
    }

    /** Puts a file back where it came from (never over a file that is there now). */
    public function restore(string $id): array
    {
        $meta = $this->entry($id);
        $target = $this->root . '/' . $meta['path'];
        if (file_exists($target)) {
            throw new \RuntimeException('มีไฟล์ ' . $meta['path'] . ' อยู่ที่ตำแหน่งเดิมแล้ว — ย้ายหรือลบไฟล์นั้นก่อนคืนไฟล์');
        }
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true)) {
            throw new \RuntimeException('สร้างโฟลเดอร์ ' . dirname($meta['path']) . ' ไม่ได้');
        }
        if (!@rename($this->dir . '/' . $id, $target)) {
            throw new \RuntimeException('คืนไฟล์ไม่ได้ (สิทธิ์การเขียนไฟล์)');
        }
        @chmod($target, 0644);
        @touch($target, (int) strtotime($meta['modified']));
        $index = $this->index();
        unset($index[$id]);
        $this->saveIndex($index);
        return $meta;
    }

    /** Deletes a quarantined file for good. */
    public function delete(string $id): array
    {
        $meta = $this->entry($id);
        $file = $this->dir . '/' . $id;
        if (is_file($file) && !@unlink($file)) {
            throw new \RuntimeException('ลบไฟล์ไม่ได้ (สิทธิ์การเขียนไฟล์)');
        }
        $index = $this->index();
        unset($index[$id]);
        $this->saveIndex($index);
        return $meta;
    }

    /** What is inside a file still in place (before deciding to quarantine it). */
    public function inspectPath(string $rel): array
    {
        $abs = $this->resolve($rel);
        $rel = $this->rel($abs);
        return $this->describe($abs, $rel) + ['system' => self::isSystemFile($rel)];
    }

    public function inspectQuarantined(string $id): array
    {
        $meta = $this->entry($id);
        $file = $this->dir . '/' . $id;
        if (!is_file($file)) {
            throw new \RuntimeException('ไม่พบไฟล์ในพื้นที่กักกัน');
        }
        return $this->describe($file, $meta['path']) + ['quarantine' => $meta];
    }

    /**
     * Facts and a safe look inside: the patterns the scan matched, the text around any PHP tag
     * (bytes that are not text shown as ·), a picture when the file is a real image, and the
     * text of the file when it is text. Nothing in it is run.
     */
    private function describe(string $abs, string $rel): array
    {
        $size = (int) filesize($abs);
        $raw = (string) @file_get_contents($abs, false, null, 0, max(self::IMAGE_BYTES, self::PREVIEW_BYTES));
        $isPhp = preg_match('/\.(php\d?|phtml|phar|pht|phps|inc)$/i', $rel) === 1;
        $img = @getimagesizefromstring($raw);
        $binary = str_contains(substr($raw, 0, 8192), "\0") || ($img !== false);

        $snippets = [];
        if (preg_match_all(SecurityScanService::EMBEDDED_CODE, $raw, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach (array_slice($m[0], 0, 5) as [, $offset]) {
                $chunk = substr($raw, max(0, $offset - 40), 400);
                $snippets[] = ['offset' => $offset, 'text' => preg_replace('/[^\x20-\x7E\n\t]/', '·', $chunk)];
            }
        }
        $text = null;
        if (!$binary) {
            $text = substr($raw, 0, self::PREVIEW_BYTES);
            $text = mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        return [
            'path' => $rel,
            'size' => $size,
            'sha256' => (string) hash_file('sha256', $abs),
            'modified' => date('Y-m-d H:i:s', (int) filemtime($abs)),
            'mime' => $img['mime'] ?? (function_exists('mime_content_type') ? (string) @mime_content_type($abs) : ''),
            'image' => $img !== false && $size <= self::IMAGE_BYTES ? 'data:' . $img['mime'] . ';base64,' . base64_encode($raw) : null,
            'image_size' => $img !== false ? $img[0] . '×' . $img[1] : null,
            'findings' => $binary ? [] : SecurityScanService::findings($raw, $isPhp),
            'snippets' => $snippets,
            'text' => $text,
            'text_cut' => $text !== null && $size > self::PREVIEW_BYTES,
        ];
    }

    /** A path inside the project, a regular file, not one of the locked ones. */
    private function resolve(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || str_contains($rel, "\0")) {
            throw new \RuntimeException('ไม่ได้ระบุไฟล์');
        }
        $abs = realpath($this->root . '/' . $rel);
        if ($abs === false || !is_file($abs)) {
            throw new \RuntimeException('ไม่พบไฟล์ ' . $rel);
        }
        $abs = str_replace('\\', '/', $abs);
        if (!str_starts_with(strtolower($abs), strtolower($this->root) . '/')) {
            throw new \RuntimeException('ไฟล์อยู่นอกโฟลเดอร์ของระบบ');
        }
        foreach (self::LOCKED as $re) {
            if (preg_match($re, $this->rel($abs)) === 1) {
                throw new \RuntimeException('ไฟล์ ' . $this->rel($abs) . ' กักกันหรือเปิดดูจากหน้านี้ไม่ได้');
            }
        }
        return $abs;
    }

    private function rel(string $abs): string
    {
        return ltrim(substr(str_replace('\\', '/', $abs), strlen($this->root)), '/');
    }

    private function entry(string $id): array
    {
        $index = $this->index();
        if (!preg_match('/^[0-9]{14}-[0-9a-f]{8}$/', $id) || !isset($index[$id])) {
            throw new \RuntimeException('ไม่พบรายการกักกันนี้');
        }
        return $index[$id];
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true)) {
            throw new \RuntimeException('สร้างโฟลเดอร์ ' . self::DIR . ' ไม่ได้');
        }
        if (!is_file($this->dir . '/.htaccess')) {
            @file_put_contents($this->dir . '/.htaccess', "Require all denied\n");
        }
    }

    private function index(): array
    {
        $raw = @file_get_contents($this->dir . '/index.json');
        $data = $raw === false ? [] : json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function saveIndex(array $index): void
    {
        if (@file_put_contents($this->dir . '/index.json', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new \RuntimeException('บันทึกรายการกักกันไม่ได้');
        }
    }
}
