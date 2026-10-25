<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Db;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A phone as a barcode scanner for a computer (scan_links, migration 0047).
 * The computer opens a link and shows its code as a QR-Code; any signed-in phone
 * that scans it sends the barcodes it reads, and the computer polls for them.
 */
class ScanLinkController
{
    use JsonResponse;

    private const HOURS = 4;

    /** POST /scan-links — the computer opens a new link (earlier ones of this user close). */
    public function open(Request $request, Response $response): Response
    {
        $db = Db::connection();
        $userId = (int) $request->getAttribute('user')['id'];
        $now = date('Y-m-d H:i:s');
        $db->prepare('UPDATE scan_links SET closed_at = ? WHERE user_id = ? AND closed_at IS NULL')->execute([$now, $userId]);
        // Old links and their codes are of no further use.
        $db->exec('DELETE FROM scan_links WHERE expires_at < NOW() - INTERVAL 1 DAY');
        $code = bin2hex(random_bytes(16));
        $db->prepare('INSERT INTO scan_links (code, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$code, $userId, $now, date('Y-m-d H:i:s', time() + self::HOURS * 3600)]);
        return $this->json($response, ['code' => $code, 'hours' => self::HOURS], 201);
    }

    /** GET /scan-links/{code}/codes?after=id — the computer picks up new barcodes and sees whether a phone is on. */
    public function codes(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $link = $this->link($db, (string) $args['code']);
        if (!$link || (int) $link['user_id'] !== (int) $request->getAttribute('user')['id']) {
            return $this->error($response, 'ไม่พบการเชื่อมต่อมือถือ', 404);
        }
        $stmt = $db->prepare('SELECT id, barcode, created_at FROM scan_link_codes WHERE link_id = ? AND id > ? ORDER BY id LIMIT 50');
        $stmt->execute([$link['id'], (int) ($request->getQueryParams()['after'] ?? 0)]);
        $phone = null;
        if ($link['phone_user_id'] !== null) {
            $name = $db->prepare('SELECT full_name FROM users WHERE id = ?');
            $name->execute([$link['phone_user_id']]);
            $phone = [
                'user_name' => $name->fetchColumn() ?: '',
                // The phone pings every few seconds while its scanner page is open.
                'online' => $link['phone_seen_at'] !== null && strtotime((string) $link['phone_seen_at']) > time() - 20,
            ];
        }
        return $this->json($response, [
            'codes' => $stmt->fetchAll(),
            'phone' => $phone,
            'active' => $this->usable($link),
        ]);
    }

    /** POST /scan-links/{code}/close — the computer stops listening. */
    public function close(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $db->prepare('UPDATE scan_links SET closed_at = ? WHERE code = ? AND user_id = ? AND closed_at IS NULL')
            ->execute([date('Y-m-d H:i:s'), (string) $args['code'], (int) $request->getAttribute('user')['id']]);
        return $this->json($response, ['ok' => true]);
    }

    /** GET /scan-links/{code} — the phone joins (and keeps pinging) the link. */
    public function join(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $link = $this->link($db, (string) $args['code']);
        if (!$link || !$this->usable($link)) {
            return $this->error($response, 'QR-Code นี้หมดอายุหรือถูกปิดแล้ว กรุณาสแกน QR-Code ใหม่จากหน้าจอคอมพิวเตอร์', 410);
        }
        $db->prepare('UPDATE scan_links SET phone_user_id = ?, phone_seen_at = ? WHERE id = ?')
            ->execute([(int) $request->getAttribute('user')['id'], date('Y-m-d H:i:s'), $link['id']]);
        $name = $db->prepare('SELECT full_name FROM users WHERE id = ?');
        $name->execute([$link['user_id']]);
        return $this->json($response, ['owner_name' => $name->fetchColumn() ?: '', 'expires_at' => $link['expires_at']]);
    }

    /** POST /scan-links/{code}/codes {barcode} — the phone sends one barcode it read. */
    public function send(Request $request, Response $response, array $args): Response
    {
        $db = Db::connection();
        $link = $this->link($db, (string) $args['code']);
        if (!$link || !$this->usable($link)) {
            return $this->error($response, 'คอมพิวเตอร์ปิดการเชื่อมต่อแล้ว กรุณาสแกน QR-Code ใหม่', 410);
        }
        $barcode = trim((string) (((array) $request->getParsedBody())['barcode'] ?? ''));
        if ($barcode === '' || mb_strlen($barcode) > 64) {
            return $this->error($response, 'บาร์โค้ดไม่ถูกต้อง', 422);
        }
        $now = date('Y-m-d H:i:s');
        $db->prepare('INSERT INTO scan_link_codes (link_id, barcode, created_at) VALUES (?, ?, ?)')->execute([$link['id'], $barcode, $now]);
        $db->prepare('UPDATE scan_links SET phone_user_id = ?, phone_seen_at = ? WHERE id = ?')
            ->execute([(int) $request->getAttribute('user')['id'], $now, $link['id']]);
        return $this->json($response, ['ok' => true], 201);
    }

    private function link(\PDO $db, string $code): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $code)) {
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM scan_links WHERE code = ?');
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    private function usable(array $link): bool
    {
        return $link['closed_at'] === null && strtotime((string) $link['expires_at']) > time();
    }
}
