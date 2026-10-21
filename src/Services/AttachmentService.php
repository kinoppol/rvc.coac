<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Stores uploaded images (receipt photos) under storage/uploads, outside the
 * web root; they are only served through the authenticated API.
 */
class AttachmentService
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

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

    public function dataUrl(array $attachment): string
    {
        return 'data:' . $attachment['mime'] . ';base64,' . base64_encode((string) file_get_contents($this->absolutePath($attachment)));
    }

    public function publicInfo(array $a): array
    {
        return ['token' => $a['token'], 'mime' => $a['mime'], 'width' => $a['width'] ? (int) $a['width'] : null, 'height' => $a['height'] ? (int) $a['height'] : null];
    }
}
