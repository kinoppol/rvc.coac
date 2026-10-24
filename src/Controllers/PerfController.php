<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AttachmentService;
use App\Support\Db;
use App\Support\JsonResponse;
use App\Support\RequestLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Settings > ประสิทธิภาพ (admin): request timings and product thumbnails. */
class PerfController
{
    use JsonResponse;

    private const THUMB_BATCH = 20;

    /** GET /admin/perf?date=YYYY-MM-DD — the day's request timings, slow ones flagged. */
    public function requests(Request $request, Response $response): Response
    {
        $days = RequestLog::days();
        $date = (string) ($request->getQueryParams()['date'] ?? '');
        if (!in_array($date, $days, true)) {
            $date = $days[0] ?? date('Y-m-d');
        }
        $data = RequestLog::analyze($date);
        $ids = array_values(array_unique(array_filter(array_column($data['slow'], 'user_id'))));
        $names = [];
        if ($ids) {
            $stmt = Db::connection()->prepare('SELECT id, full_name FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            $names = array_column($stmt->fetchAll(), 'full_name', 'id');
        }
        foreach ($data['slow'] as &$r) {
            $r['user'] = $names[$r['user_id']] ?? null;
        }
        unset($r);
        return $this->json($response, ['data' => $data, 'days' => $days, 'list_from_ms' => RequestLog::LIST_FROM_MS]);
    }

    /**
     * POST /admin/thumbnails {after_id} — checks the next batch of product photos:
     * the original file exists, and a thumbnail exists (made with GD when missing).
     * The client calls again with next_id until it is null.
     */
    public function thumbnails(Request $request, Response $response): Response
    {
        set_time_limit(120);
        $after = (int) (((array) $request->getParsedBody())['after_id'] ?? 0);
        $db = Db::connection();
        $total = (int) $db->query("SELECT COUNT(*) FROM products WHERE image_token IS NOT NULL AND image_token <> ''")->fetchColumn();
        $stmt = $db->prepare("SELECT id, name, image_token FROM products WHERE image_token IS NOT NULL AND image_token <> '' AND id > ? ORDER BY id LIMIT " . self::THUMB_BATCH);
        $stmt->execute([$after]);
        $service = new AttachmentService($db);
        $out = ['checked' => 0, 'had' => 0, 'made' => 0, 'missing' => [], 'failed' => []];
        $last = null;
        foreach ($stmt->fetchAll() as $p) {
            $last = (int) $p['id'];
            $out['checked']++;
            $row = $service->findByToken((string) $p['image_token']);
            try {
                if (!$row) {
                    throw new \RuntimeException('ไม่พบไฟล์');
                }
                $service->absolutePath($row);
            } catch (\RuntimeException $e) {
                $out['missing'][] = $p['name'];
                continue;
            }
            if ($service->hasThumb($row['token'])) {
                $out['had']++;
            } elseif ($service->makeThumb($row)) {
                $out['made']++;
            } else {
                $out['failed'][] = $p['name'];
            }
        }
        return $this->json($response, ['data' => $out + [
            'total' => $total,
            'next_id' => $out['checked'] === self::THUMB_BATCH ? $last : null,
            'gd' => function_exists('imagecreatefromstring'),
        ]]);
    }
}
