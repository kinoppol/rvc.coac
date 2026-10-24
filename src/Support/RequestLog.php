<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How long each request through PHP took, one tab-separated line per request in
 * storage/logs/requests-YYYY-MM-DD.log (kept 14 days). Static JS/CSS/images served
 * by the web server directly never reach PHP, so they are not here.
 *
 * analyze() works out what is normal for each route (its median over the day) and
 * flags requests far slower than that, or that waited for the session lock.
 */
class RequestLog
{
    private const KEEP_DAYS = 14;
    /** Shown in the list from this long; faster requests only count toward each route's normal time. */
    public const LIST_FROM_MS = 1000;

    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs';
    }

    private static function file(string $date): string
    {
        return self::dir() . "/requests-{$date}.log";
    }

    public static function write(string $method, string $path, float $ms, float $sessionMs, int $status, int $userId): void
    {
        $file = self::file(date('Y-m-d'));
        $new = !is_file($file);
        @file_put_contents($file, sprintf(
            "%s\t%d\t%d\t%d\t%s\t%s\t%d\n",
            date('H:i:s'), (int) round($ms), (int) round($sessionMs), $status, $method, mb_substr($path, 0, 200), $userId
        ), FILE_APPEND | LOCK_EX);
        if ($new) {
            foreach (glob(self::dir() . '/requests-*.log') ?: [] as $old) {
                if (filemtime($old) < time() - self::KEEP_DAYS * 86400) {
                    @unlink($old);
                }
            }
        }
    }

    /** Days that have a log, newest first. */
    public static function days(): array
    {
        $days = array_map(fn ($f) => substr(basename($f, '.log'), 9), glob(self::dir() . '/requests-*.log') ?: []);
        rsort($days);
        return $days;
    }

    /** /api/products/12/photo → /api/products/{id}/photo */
    public static function route(string $method, string $path): string
    {
        $path = preg_replace(['#^.*?(?=/api/)#', '#/[0-9a-f]{32}(?=/|$)#', '#/\d+(?=/|$)#'], ['', '/{token}', '/{id}'], $path) ?? $path;
        return $method . ' ' . $path;
    }

    /**
     * @return array{date:string,total:int,routes:array,slow:array}
     *   routes: per route count / median / p90 / max / slow count, slowest first;
     *   slow: requests from LIST_FROM_MS, newest first, each with why it stands out.
     */
    public static function analyze(string $date): array
    {
        $file = self::file($date);
        $rows = [];
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $p = explode("\t", $line);
                if (count($p) < 7) {
                    continue;
                }
                $rows[] = ['time' => $p[0], 'ms' => (int) $p[1], 'session_ms' => (int) $p[2], 'status' => (int) $p[3],
                    'method' => $p[4], 'path' => $p[5], 'user_id' => (int) $p[6], 'route' => self::route($p[4], $p[5])];
            }
        }
        $byRoute = [];
        foreach ($rows as $r) {
            $byRoute[$r['route']][] = $r['ms'];
        }
        $median = [];
        $routes = [];
        foreach ($byRoute as $route => $ms) {
            sort($ms);
            $n = count($ms);
            $median[$route] = $ms[intdiv($n, 2)];
            $routes[$route] = ['route' => $route, 'count' => $n, 'median' => $median[$route], 'p90' => $ms[(int) floor(($n - 1) * 0.9)], 'max' => $ms[$n - 1], 'slow' => 0];
        }

        $slow = [];
        foreach (array_reverse($rows) as $r) {
            if ($r['ms'] < self::LIST_FROM_MS) {
                continue;
            }
            $reasons = self::reasons($r, $median[$r['route']], count($byRoute[$r['route']]));
            if ($reasons) {
                $routes[$r['route']]['slow']++;
            }
            if (count($slow) < 500) {
                $slow[] = $r + ['normal_ms' => $median[$r['route']], 'reasons' => $reasons];
            }
        }
        usort($routes, fn ($a, $b) => [$b['slow'], $b['p90']] <=> [$a['slow'], $a['p90']]);
        return ['date' => $date, 'total' => count($rows), 'routes' => array_values($routes), 'slow' => $slow];
    }

    /** Why a request is slower than it should be (empty = slow, but normal for its route). */
    private static function reasons(array $r, int $median, int $samples): array
    {
        $out = [];
        if ($r['session_ms'] >= 500) {
            $out[] = 'รอคิว/เริ่มระบบ ' . number_format($r['session_ms'] / 1000, 1) . ' วิ';
        }
        if ($samples >= 5 && $r['ms'] >= 3 * max($median, 200)) {
            $out[] = 'ช้ากว่าปกติ ×' . number_format($r['ms'] / max($median, 1), 1);
        }
        // Routes that are slow by nature (AI calls) are judged against their own normal time only.
        if ($r['ms'] >= 10000 && $median < 5000) {
            $out[] = 'เกิน 10 วินาที';
        }
        if ($r['status'] >= 500) {
            $out[] = 'ผิดพลาด ' . $r['status'];
        }
        return $out;
    }
}
