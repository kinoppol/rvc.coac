<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Stores uploaded images (receipt photos) and Markdown receipt files under
 * storage/uploads, outside the web root; they are only served through the
 * authenticated API.
 */
class AttachmentService
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_TEXT_BYTES = 1024 * 1024;
    public const TEXT_MIME = 'text/markdown';

    public function __construct(private PDO $db)
    {
    }

    private function baseDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads';
    }

    /** Saves a "data:image/...;base64,..." string and returns the attachment row. */
    public function saveDataUrl(string $dataUrl, int $userId, string $folder = 'receipts'): array
    {
        if (!preg_match('#^data:(image/[a-z]+);base64,(.+)$#s', $dataUrl, $m)) {
            throw new \RuntimeException('รูปแบบไฟล์รูปไม่ถูกต้อง');
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('อ่านไฟล์รูปไม่ได้');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException('ไฟล์รูปใหญ่เกิน 8 MB');
        }
        // Trust the bytes, not the declared type.
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? '';
        if (!isset(self::MIME_EXT[$mime])) {
            throw new \RuntimeException('รองรับเฉพาะรูป JPG, PNG หรือ WEBP');
        }

        $token = bin2hex(random_bytes(16));
        $rel = $folder . '/' . date('Ym') . '/' . $token . '.' . self::MIME_EXT[$mime];
        $abs = $this->baseDir() . '/' . $rel;
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0775, true) && !is_dir(dirname($abs))) {
            throw new \RuntimeException('สร้างโฟลเดอร์เก็บไฟล์ไม่ได้');
        }
        if (file_put_contents($abs, $bytes) === false) {
            throw new \RuntimeException('บันทึกไฟล์รูปไม่สำเร็จ');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO attachments (token, file_path, mime, size_bytes, width, height, uploaded_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$token, $rel, $mime, strlen($bytes), $info[0] ?? null, $info[1] ?? null, $userId]);
        return $this->findByToken($token);
    }

    /** Saves the text of a .md / .txt receipt file and returns the attachment row. */
    public function saveText(string $content, string $name, int $userId, string $folder = 'receipts'): array
    {
        $content = str_replace("\r\n", "\n", $content);
        if (trim($content) === '') {
            throw new \RuntimeException('ไฟล์ว่างเปล่า');
        }
        if (strlen($content) > self::MAX_TEXT_BYTES) {
            throw new \RuntimeException('ไฟล์ข้อความใหญ่เกิน 1 MB');
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new \RuntimeException('ไฟล์ต้องเป็นข้อความ UTF-8');
        }
        $name = preg_replace('/[^\p{L}\p{N} ._()-]/u', '', $name) ?: 'receipt.md';
        if (!preg_match('/\.(md|markdown|txt)$/i', $name)) {
            $name .= '.md';
        }

        $token = bin2hex(random_bytes(16));
        $rel = $folder . '/' . date('Ym') . '/' . $token . '.md';
        $abs = $this->baseDir() . '/' . $rel;
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0775, true) && !is_dir(dirname($abs))) {
            throw new \RuntimeException('สร้างโฟลเดอร์เก็บไฟล์ไม่ได้');
        }
        if (file_put_contents($abs, $content) === false) {
            throw new \RuntimeException('บันทึกไฟล์ไม่สำเร็จ');
        }
        try {
            $this->db->prepare(
                'INSERT INTO attachments (token, file_path, original_name, mime, size_bytes, uploaded_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            )->execute([$token, $rel, mb_substr($name, 0, 191), self::TEXT_MIME, strlen($content), $userId]);
        } catch (\PDOException $e) {
            @unlink($abs);
            if (($e->errorInfo[0] ?? '') === '42S22') {
                throw new \RuntimeException('ฐานข้อมูลยังไม่ได้อัปเดต — ผู้ดูแลระบบต้องรัน Migration ที่ค้างอยู่ (เมนู ระบบ > Migrations) ก่อนจึงจะแนบไฟล์ .md ได้');
            }
            throw $e;
        }
        return $this->findByToken($token);
    }

    public static function isImage(array $attachment): bool
    {
        return str_starts_with((string) $attachment['mime'], 'image/');
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM attachments WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Resolves tokens the given user uploaded, keeping the given order.
     * @param string[] $tokens
     */
    public function ownedBy(array $tokens, int $userId): array
    {
        $out = [];
        foreach (array_unique(array_filter(array_map('strval', $tokens))) as $token) {
            $row = $this->findByToken($token);
            if ($row && (int) $row['uploaded_by'] === $userId) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public function forPurchase(int $purchaseId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM attachments WHERE purchase_document_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$purchaseId]);
        return $stmt->fetchAll();
    }

    /** @param array[] $attachments rows from ownedBy() */
    public function linkToPurchase(array $attachments, int $purchaseId): void
    {
        $stmt = $this->db->prepare('UPDATE attachments SET purchase_document_id = ?, sort_order = ? WHERE id = ? AND purchase_document_id IS NULL');
        foreach (array_values($attachments) as $i => $a) {
            $stmt->execute([$purchaseId, $i, $a['id']]);
        }
    }

    public function absolutePath(array $attachment): string
    {
        $abs = realpath($this->baseDir() . '/' . $attachment['file_path']);
        $base = realpath($this->baseDir());
        if ($abs === false || $base === false || !str_starts_with($abs, $base)) {
            throw new \RuntimeException('ไม่พบไฟล์');
        }
        return $abs;
    }

    /** Small JPEG copy of an image, for lists such as the POS picture grid. */
    public const THUMB_SIZE = 320;
    private const THUMB_MAX_BYTES = 150 * 1024;

    public function thumbPath(string $token): string
    {
        return $this->baseDir() . '/thumbs/' . substr($token, 0, 2) . '/' . $token . '.jpg';
    }

    public function hasThumb(string $token): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $token) === 1 && is_file($this->thumbPath($token));
    }

    /** Builds the thumbnail with GD when the server has it; returns its path, or null. */
    public function makeThumb(array $attachment): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring((string) file_get_contents($this->absolutePath($attachment)));
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::THUMB_SIZE / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($tw, $th);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        ob_start();
        imagejpeg($dst, null, 72);
        $bytes = (string) ob_get_clean();
        return $this->writeThumb($attachment['token'], $bytes) ? $this->thumbPath($attachment['token']) : null;
    }

    /**
     * Stores a thumbnail made in the browser (when the photo is taken, or by the
     * first device that loads a photo the server could not shrink). Only a small
     * JPEG is accepted, and an existing thumbnail is never replaced.
     */
    public function saveThumbDataUrl(string $token, string $dataUrl): bool
    {
        if ($this->hasThumb($token) || !preg_match('#^data:image/jpeg;base64,(.+)$#s', $dataUrl, $m)) {
            return false;
        }
        $bytes = base64_decode($m[1], true);
        $info = $bytes !== false && strlen($bytes) <= self::THUMB_MAX_BYTES ? @getimagesizefromstring($bytes) : false;
        if (!$info || ($info['mime'] ?? '') !== 'image/jpeg' || max($info[0], $info[1]) > self::THUMB_SIZE) {
            return false;
        }
        return $this->writeThumb($token, $bytes);
    }

    private function writeThumb(string $token, string $bytes): bool
    {
        $path = $this->thumbPath($token);
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            return false;
        }
        return $bytes !== '' && file_put_contents($path, $bytes) !== false;
    }

    public function dataUrl(array $attachment): string
    {
        return 'data:' . $attachment['mime'] . ';base64,' . base64_encode((string) file_get_contents($this->absolutePath($attachment)));
    }

    public function publicInfo(array $a): array
    {
        return ['token' => $a['token'], 'mime' => $a['mime'], 'name' => $a['original_name'] ?? null, 'size' => (int) $a['size_bytes'], 'width' => $a['width'] ? (int) $a['width'] : null, 'height' => $a['height'] ? (int) $a['height'] : null];
    }
}
